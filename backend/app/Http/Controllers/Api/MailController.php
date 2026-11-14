<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Mail\ImapMailDriver;
use App\Services\Mail\MailDiscovery;
use App\Services\Mail\MailException;
use App\Services\Mail\MailOAuth;
use App\Services\Mail\MailService;
use App\Services\TenantService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\HeaderUtils;

/**
 * E-Mail section — shared company mailboxes, read live from the provider.
 *
 * view   : accounts, inbox, message, attachment
 * send   : send (multipart, attachments ≤ 10 MB total)
 * manage : discover, connect (IMAP), oauth start/finish, destroy
 *
 * OAuth callback is public (browser redirect from Microsoft/Google). It only
 * swaps the code for tokens and parks them in the cache under a one-time
 * key; the authenticated `finish` call then stores them in the tenant DB
 * after re-checking tenant + user — so the public endpoint never touches a
 * tenant database.
 */
class MailController extends Controller
{
    private const PER_PAGE = 30;
    private const MAX_TOTAL_BYTES = 10 * 1024 * 1024;
    private const OAUTH_TTL = 600;
    private const SMTP_PORTS = [465, 587];
    private const IMAP_PORTS = [993, 143];

    public function accounts()
    {
        return response()->json([
            'accounts' => MailService::all()->map(fn ($k) => MailService::publicRow($k))->values(),
            'oauth'    => ['microsoft' => MailOAuth::configured('microsoft'), 'google' => MailOAuth::configured('google')],
        ]);
    }

    public function inbox(Request $request, int $id)
    {
        $k = $this->konto($id);
        $q = mb_substr(trim((string) $request->query('q', '')), 0, 190);
        $cursor = $request->query('cursor');
        $cursor = is_string($cursor) && strlen($cursor) <= 4096 && $cursor !== '' ? $cursor : null;
        return $this->run($k, fn ($d) => $d->listing($this->folder($request), $q !== '' ? $q : null, $cursor, self::PER_PAGE));
    }

    public function message(Request $request, int $id)
    {
        $k = $this->konto($id);
        $mid = $this->messageId($request->query('mid'));
        $folder = $this->folder($request);
        return $this->run($k, fn ($d) => $d->message($mid, $folder));
    }

    public function destroyMessage(Request $request, int $id)
    {
        $k = $this->konto($id);
        $mid = $this->messageId($request->query('mid'));
        $folder = $this->folder($request);
        return $this->run($k, function ($d) use ($mid, $folder, $k, $request) {
            $d->delete($mid, $folder);
            Log::info('mail.message.deleted', ['konto' => $k->id, 'user' => $request->user()->id, 'folder' => $folder]);
            \App\Helpers\Realtime::changed('mail', ['konto' => (int) $k->id]);
            return ['success' => true];
        });
    }

    public function seen(Request $request, int $id)
    {
        $k = $this->konto($id);
        $v = $request->validate([
            'mid'    => 'required|string|max:1024',
            'seen'   => 'required|boolean',
            'folder' => 'nullable|in:inbox,sent',
        ]);
        $mid = $this->messageId($v['mid']);
        $folder = ($v['folder'] ?? 'inbox') === 'sent' ? 'sent' : 'inbox';
        return $this->run($k, function ($d) use ($mid, $folder, $v, $k) {
            $d->setSeen($mid, (bool) $v['seen'], $folder);
            \App\Helpers\Realtime::changed('mail', ['konto' => (int) $k->id]);
            return ['success' => true];
        });
    }

    public function attachment(Request $request, int $id)
    {
        $k = $this->konto($id);
        $mid = $this->messageId($request->query('mid'));
        $folder = $this->folder($request);
        $aid = (string) $request->query('aid', '');
        if ($aid === '' || strlen($aid) > 1024) abort(response()->json(['error' => 'Anhang nicht gefunden.'], 404));

        try {
            $a = MailService::driver($k)->attachment($mid, $aid, $folder);
        } catch (\Throwable $e) {
            return $this->error($k, $e);
        }
        $name = str_replace(["\r", "\n", '"', '/', '\\'], '_', $a['name']) ?: 'anhang';
        $fallback = preg_replace('/[^\x20-\x7E]/', '_', $name);
        $mime = preg_match('#^[a-z0-9.+-]+/[a-z0-9.+-]+$#i', $a['mime']) ? $a['mime'] : 'application/octet-stream';
        return response($a['content'], 200, [
            'Content-Type'           => $mime,
            'Content-Disposition'    => HeaderUtils::makeDisposition('attachment', $name, $fallback),
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control'          => 'no-store',
        ]);
    }

    public function send(Request $request, int $id)
    {
        $k = $this->konto($id);
        $v = $request->validate([
            'to'         => 'required|string|max:5000',
            'cc'         => 'nullable|string|max:5000',
            'bcc'        => 'nullable|string|max:5000',
            'subject'    => 'nullable|string|max:900',
            'text'       => 'nullable|string|max:200000',
            'reply_to'   => 'nullable|string|max:1024',
            'forward_of' => 'nullable|string|max:1024',
            'src_folder' => 'nullable|in:inbox,sent',
            'files'      => 'nullable|array|max:10',
            'files.*'    => 'file|max:10240',
        ]);

        [$to, $badTo] = $this->recipients($v['to']);
        [$cc, $badCc] = $this->recipients($v['cc'] ?? '');
        [$bcc, $badBcc] = $this->recipients($v['bcc'] ?? '');
        if ($badTo || $badCc || $badBcc) {
            return response()->json(['error' => 'Ungültige E-Mail-Adresse: ' . implode(', ', [...$badTo, ...$badCc, ...$badBcc])], 422);
        }
        if (!$to) return response()->json(['error' => 'Bitte mindestens einen Empfänger angeben.'], 422);
        if (count($to) + count($cc) + count($bcc) > 50) return response()->json(['error' => 'Maximal 50 Empfänger pro Nachricht.'], 422);

        $files = [];
        $total = 0;
        foreach ($request->file('files', []) as $f) {
            $total += (int) $f->getSize();
            $files[] = [
                'name'    => str_replace(["\r", "\n"], '', $f->getClientOriginalName()) ?: 'anhang',
                'mime'    => $f->getMimeType() ?: 'application/octet-stream',
                'content' => (string) file_get_contents($f->getRealPath()),
            ];
        }
        if ($total > self::MAX_TOTAL_BYTES) {
            return response()->json(['error' => 'Anhänge dürfen zusammen höchstens 10 MB groß sein.'], 413);
        }

        $mail = [
            'to'         => $to,
            'cc'         => $cc,
            'bcc'        => $bcc,
            'subject'    => trim(str_replace(["\r", "\n"], ' ', (string) ($v['subject'] ?? ''))),
            'text'       => (string) ($v['text'] ?? ''),
            'reply_to'   => !empty($v['reply_to']) ? $this->messageId($v['reply_to']) : null,
            'forward_of' => !empty($v['forward_of']) ? $this->messageId($v['forward_of']) : null,
            'src_folder' => ($v['src_folder'] ?? 'inbox') === 'sent' ? 'sent' : 'inbox',
            'files'      => $files,
        ];
        return $this->run($k, function ($d) use ($mail, $k, $request) {
            $d->send($mail);
            Log::info('mail.sent', ['konto' => $k->id, 'user' => $request->user()->id, 'rcpt' => count($mail['to']) + count($mail['cc']) + count($mail['bcc']), 'files' => count($mail['files'])]);
            \App\Helpers\Realtime::changed('mail', ['konto' => (int) $k->id]);
            return ['success' => true];
        });
    }

    public function discover(Request $request)
    {
        $v = $request->validate(['email' => 'required|email:rfc|max:190']);
        return response()->json((new MailDiscovery())->discover($v['email']));
    }

    public function connect(Request $request)
    {
        $v = $request->validate([
            'email'                  => 'required|email:rfc|max:190',
            'password'               => 'required|string|max:500',
            'anzeige_name'           => 'nullable|string|max:190',
            'manual'                 => 'nullable|array',
            'manual.imap_host'       => 'required_with:manual|string|max:190',
            'manual.imap_port'       => 'required_with:manual|integer|in:' . implode(',', self::IMAP_PORTS),
            'manual.imap_encryption' => 'required_with:manual|in:ssl,starttls',
            'manual.smtp_host'       => 'required_with:manual|string|max:190',
            'manual.smtp_port'       => 'required_with:manual|integer|in:' . implode(',', self::SMTP_PORTS),
            'manual.smtp_encryption' => 'required_with:manual|in:ssl,starttls',
            'manual.username'        => 'nullable|string|max:190',
        ]);
        $email = strtolower(trim($v['email']));

        $found = (new MailDiscovery())->discover($email);
        if ($found['provider'] === 'microsoft') {
            return response()->json(['error' => 'Dieses Postfach wird über „Mit Microsoft anmelden“ verbunden.'], 422);
        }

        $m = $v['manual'] ?? null;
        $imap = $m ? ['host' => strtolower($m['imap_host']), 'port' => (int) $m['imap_port'], 'encryption' => $m['imap_encryption'], 'username' => $m['username'] ?? null] : $found['imap'];
        $smtp = $m ? ['host' => strtolower($m['smtp_host']), 'port' => (int) $m['smtp_port'], 'encryption' => $m['smtp_encryption']] : $found['smtp'];
        if (!$imap || !$smtp) {
            return response()->json([
                'error'       => 'Die Servereinstellungen konnten nicht automatisch ermittelt werden. Bitte die Angaben Ihres Anbieters eintragen.',
                'need_manual' => true,
            ], 422);
        }
        if (!MailDiscovery::hostAllowed($imap['host']) || !MailDiscovery::hostAllowed($smtp['host'])) {
            return response()->json(['error' => 'Die Adresse des Mailservers ist ungültig.', 'need_manual' => true], 422);
        }

        $row = [
            'email'           => $email,
            'anzeige_name'    => $v['anzeige_name'] ?? null,
            'provider'        => $found['provider'] === 'google' ? 'google' : 'imap',
            'imap_host'       => $imap['host'],
            'imap_port'       => $imap['port'],
            'imap_encryption' => $imap['encryption'],
            'smtp_host'       => $smtp['host'],
            'smtp_port'       => $smtp['port'],
            'smtp_encryption' => $smtp['encryption'],
            'username'        => ($imap['username'] ?? null) ?: $email,
        ];
        $cred = ['type' => 'password', 'password' => $v['password']];

        try {
            (new ImapMailDriver((object) ($row + ['id' => 0]), $cred))->verify();
        } catch (\Throwable $e) {
            $err = MailException::fromThrowable($e, $found['hint']);
            return response()->json(['error' => $err->getMessage(), 'hint' => $found['hint']], $err->status === 504 ? 504 : 422);
        }

        return response()->json(['account' => $this->store($row, $cred, $request->user()->id)]);
    }

    public function oauthStart(Request $request)
    {
        $v = $request->validate([
            'provider' => 'required|in:' . implode(',', MailOAuth::providers()),
            'email'    => 'nullable|email:rfc|max:190',
        ]);
        if (!MailOAuth::configured($v['provider'])) {
            return response()->json(['error' => 'Diese Anmeldeart ist noch nicht eingerichtet.'], 422);
        }
        [$verifier, $challenge] = MailOAuth::pkce();
        $state = Str::random(48);
        Cache::put('mail:oauth:state:' . $state, [
            'provider'  => $v['provider'],
            'tenant_id' => TenantService::assertActive()->id,
            'user_id'   => $request->user()->id,
            'verifier'  => $verifier,
        ], self::OAUTH_TTL);

        return response()->json(['url' => MailOAuth::authUrl($v['provider'], $state, $challenge, $v['email'] ?? null)]);
    }

    public function oauthCallback(Request $request, string $provider)
    {
        $state = (string) $request->query('state', '');
        $st = strlen($state) === 48 ? Cache::pull('mail:oauth:state:' . $state) : null;
        if (!$st || $st['provider'] !== $provider) {
            return $this->oauthPage(false, 'Die Anmeldung ist abgelaufen. Bitte erneut auf „Verbinden“ klicken.');
        }
        if ($request->query('error')) {
            $denied = in_array($request->query('error'), ['access_denied', 'consent_required'], true);
            Log::info('mail.oauth.denied', ['provider' => $provider, 'error' => (string) $request->query('error'), 'desc' => mb_substr((string) $request->query('error_description'), 0, 300)]);
            return $this->oauthPage(false, $denied
                ? 'Der Zugriff wurde nicht erlaubt. Ohne Zustimmung kann das Postfach nicht verbunden werden.'
                : 'Der E-Mail-Anbieter hat die Anmeldung abgelehnt.');
        }
        $code = (string) $request->query('code', '');
        if ($code === '') return $this->oauthPage(false, 'Die Anmeldung wurde abgebrochen.');

        try {
            $tokens = MailOAuth::exchange($provider, $code, $st['verifier']);
            $profile = MailOAuth::profile($provider, $tokens['access_token']);
        } catch (MailException $e) {
            return $this->oauthPage(false, $e->getMessage());
        }

        $key = Str::random(48);
        Cache::put('mail:oauth:result:' . $key, [
            'tenant_id' => $st['tenant_id'],
            'user_id'   => $st['user_id'],
            'provider'  => $provider,
            'email'     => $profile['email'],
            'name'      => $profile['name'],
            'tokens'    => Crypt::encryptString(json_encode($tokens)),
        ], self::OAUTH_TTL);

        return $this->oauthPage(true, null, $key);
    }

    public function oauthFinish(Request $request)
    {
        $v = $request->validate(['key' => 'required|string|size:48']);
        $res = Cache::pull('mail:oauth:result:' . $v['key']);
        if (!$res || (int) $res['tenant_id'] !== (int) TenantService::assertActive()->id || (int) $res['user_id'] !== (int) $request->user()->id) {
            return response()->json(['error' => 'Die Anmeldung ist abgelaufen. Bitte erneut verbinden.'], 410);
        }
        $tokens = json_decode(Crypt::decryptString($res['tokens']), true);

        $row = [
            'email'        => $res['email'],
            'anzeige_name' => $res['name'] ?: null,
            'provider'     => $res['provider'],
        ];
        if ($res['provider'] === 'google') {
            $row += [
                'imap_host' => 'imap.gmail.com', 'imap_port' => 993, 'imap_encryption' => 'ssl',
                'smtp_host' => 'smtp.gmail.com', 'smtp_port' => 465, 'smtp_encryption' => 'ssl',
                'username'  => $res['email'],
            ];
        } else {
            $row += ['imap_host' => null, 'imap_port' => null, 'imap_encryption' => null,
                     'smtp_host' => null, 'smtp_port' => null, 'smtp_encryption' => null, 'username' => null];
        }

        return response()->json(['account' => $this->store($row, $tokens, $request->user()->id, keepName: true)]);
    }

    public function destroy(int $id)
    {
        $k = $this->konto($id);
        DB::connection('tenant')->table('mail_konten')->where('id', $k->id)->delete();
        Log::info('mail.account.removed', ['konto' => $k->id, 'provider' => $k->provider]);
        \App\Helpers\Realtime::changed('mail', ['konto' => (int) $k->id]);
        return response()->json(['success' => true]);
    }

    private function store(array $row, array $cred, int $userId, bool $keepName = false): array
    {
        $table = DB::connection('tenant')->table('mail_konten');
        $existing = $table->where('email', $row['email'])->first();
        $row += ['credentials' => MailService::encrypt($cred), 'aktiv' => true, 'last_error' => null, 'updated_at' => now()];

        if ($existing) {
            if ($keepName && $existing->anzeige_name) unset($row['anzeige_name']);
            DB::connection('tenant')->table('mail_konten')->where('id', $existing->id)->update($row);
            $id = (int) $existing->id;
        } else {
            $id = (int) DB::connection('tenant')->table('mail_konten')->insertGetId($row + ['created_by' => $userId, 'created_at' => now()]);
        }
        Log::info('mail.account.connected', ['konto' => $id, 'provider' => $row['provider'], 'user' => $userId]);
        \App\Helpers\Realtime::changed('mail', ['konto' => $id]);
        return MailService::publicRow(MailService::find($id));
    }

    private function konto(int $id): object
    {
        $k = MailService::find($id);
        if (!$k) abort(response()->json(['error' => 'Postfach nicht gefunden.'], 404));
        return $k;
    }

    private function folder(Request $request): string
    {
        return $request->query('folder') === 'sent' ? 'sent' : 'inbox';
    }

    private function messageId($mid): string
    {
        $mid = (string) $mid;
        if ($mid === '' || strlen($mid) > 1024 || !preg_match('/^[A-Za-z0-9_=+\/.-]+$/', $mid)) {
            abort(response()->json(['error' => 'Nachricht nicht gefunden.'], 404));
        }
        return $mid;
    }

    /** @return array{0: string[], 1: string[]} valid, invalid */
    private function recipients(string $raw): array
    {
        $ok = $bad = [];
        foreach (preg_split('/[,;\s]+/', $raw, -1, PREG_SPLIT_NO_EMPTY) as $part) {
            if (preg_match('/<([^>]+)>/', $part, $m)) $part = $m[1];
            $part = trim($part, " <>\"'");
            if ($part === '') continue;
            filter_var($part, FILTER_VALIDATE_EMAIL) ? $ok[] = strtolower($part) : $bad[] = $part;
        }
        return [array_values(array_unique($ok)), $bad];
    }

    private function run(object $k, \Closure $fn)
    {
        try {
            $result = $fn(MailService::driver($k));
            if ($k->last_error) MailService::markError((int) $k->id, null);
            return response()->json($result);
        } catch (\Throwable $e) {
            return $this->error($k, $e);
        }
    }

    private function error(object $k, \Throwable $e)
    {
        if (!$e instanceof MailException) {
            Log::warning('mail.driver', ['konto' => $k->id, 'provider' => $k->provider, 'err' => class_basename($e) . ': ' . mb_substr($e->getMessage(), 0, 300)]);
        }
        $m = MailException::fromThrowable($e);
        if ($m->reconnect || $m->status === 422) MailService::markError((int) $k->id, $m->getMessage());
        return response()->json(['error' => $m->getMessage(), 'reconnect' => $m->reconnect], $m->status);
    }

    private function oauthPage(bool $ok, ?string $error, ?string $key = null)
    {
        $flags = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE;
        $payload = json_encode(['source' => 'clood-mail-oauth', 'ok' => $ok, 'key' => $key, 'error' => $error], $flags);
        $fallback = json_encode(rtrim((string) config('app.url'), '/') . '/index.php?'
            . http_build_query($ok ? ['mail_oauth' => $key] : ['mail_oauth_error' => $error]) . '#mail', $flags);
        $msg = e($ok ? 'Postfach verbunden — dieses Fenster schließt sich automatisch.' : $error);

        $html = <<<HTML
<!doctype html><html lang="de"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Clood-X · E-Mail</title>
<style>body{font-family:system-ui,-apple-system,"Segoe UI",Roboto,sans-serif;display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0;background:#f1f5f9;color:#1f2937}p{max-width:420px;text-align:center;padding:24px;line-height:1.5}</style>
</head><body><p>{$msg}</p>
<script>(function(){var d={$payload};try{if(window.opener&&!window.opener.closed){window.opener.postMessage(d,'*');setTimeout(function(){window.close();},400);return;}}catch(e){}location.replace({$fallback});})();</script>
</body></html>
HTML;
        return response($html, 200, [
            'Content-Type'    => 'text/html; charset=utf-8',
            'Cache-Control'   => 'no-store',
            'Referrer-Policy' => 'no-referrer',
        ]);
    }
}
