<?php

namespace App\Services\Mail;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Log;

/**
 * Microsoft 365 / Outlook.com via Microsoft Graph (delegated
 * Mail.ReadWrite + Mail.Send). Graph instead of SMTP because many M365
 * tenants disable SMTP AUTH by default.
 */
class GraphMailDriver implements MailDriver
{
    private const BASE = 'https://graph.microsoft.com/v1.0';
    private const INLINE_MAX = 2 * 1024 * 1024;
    /** Graph rejects request bodies over 4 MB — bigger files go through an upload session. */
    private const DIRECT_ATTACH_MAX = 3 * 1024 * 1024;
    private const CHUNK = 320 * 1024 * 10;

    public function __construct(private object $konto, private array $cred) {}

    public function verify(): void
    {
        $this->call('GET', '/me/mailFolders/inbox', ['query' => ['$select' => 'id']]);
    }

    public function listing(string $folder, ?string $search, ?string $cursor, int $perPage): array
    {
        if ($cursor !== null) {
            // Opaque continuation — must be a Graph nextLink we issued before.
            if (!str_starts_with($cursor, self::BASE . '/')) {
                throw new MailException('Ungültiger Seitenzeiger.', 422);
            }
            $r = $this->call('GET', $cursor)->json();
        } else {
            $query = [
                '$top'    => $perPage,
                '$select' => 'id,subject,from,toRecipients,receivedDateTime,isRead,hasAttachments,bodyPreview',
            ];
            $opts = ['query' => $query];
            if ($search !== null) {
                // KQL term search (subject/body/from); no $orderby/$skip with $search.
                $query['$search'] = '"' . str_replace('"', '', $search) . '"';
                $opts['headers'] = ['ConsistencyLevel' => 'eventual'];
            } else {
                $query['$orderby'] = 'receivedDateTime desc';
            }
            $folderPath = $folder === 'sent' ? 'sentitems' : 'inbox';
            $r = $this->call('GET', "/me/mailFolders/{$folderPath}/messages", $opts)->json();
        }

        $items = array_map(fn ($m) => [
            'id'              => (string) $m['id'],
            'from_name'       => (string) ($m['from']['emailAddress']['name'] ?? ''),
            'from_email'      => (string) ($m['from']['emailAddress']['address'] ?? ''),
            'to_name'         => (string) ($m['toRecipients'][0]['emailAddress']['name'] ?? ''),
            'to_email'        => (string) ($m['toRecipients'][0]['emailAddress']['address'] ?? ''),
            'subject'         => (string) ($m['subject'] ?? ''),
            'date'            => $m['receivedDateTime'] ?? null,
            'seen'            => (bool) ($m['isRead'] ?? false),
            'has_attachments' => (bool) ($m['hasAttachments'] ?? false),
            'preview'         => mb_substr((string) ($m['bodyPreview'] ?? ''), 0, 160),
        ], $r['value'] ?? []);

        return [
            'items'  => $items,
            'next'   => $r['@odata.nextLink'] ?? null,
            'unread' => $this->unread(),
        ];
    }

    public function message(string $id, string $folder = 'inbox'): array
    {
        $path = '/me/messages/' . $this->enc($id);
        $m = $this->call('GET', $path, [
            'query'   => ['$select' => 'id,subject,from,toRecipients,ccRecipients,receivedDateTime,body,hasAttachments,isRead'],
            'headers' => ['Prefer' => 'outlook.body-content-type="html"'],
        ])->json();

        $isHtml = strtolower($m['body']['contentType'] ?? '') === 'html';
        $html = $isHtml ? (string) ($m['body']['content'] ?? '') : null;
        $text = $isHtml ? null : (string) ($m['body']['content'] ?? '');

        $attachments = [];
        if (!empty($m['hasAttachments']) || ($html && str_contains($html, 'cid:'))) {
            $list = $this->call('GET', $path . '/attachments', ['query' => ['$select' => 'id,name,contentType,size,isInline']])->json('value') ?? [];
            foreach ($list as $a) {
                if (($a['@odata.type'] ?? '') !== '#microsoft.graph.fileAttachment') continue;
                if (!empty($a['isInline']) && $html && str_contains($html, 'cid:') && (int) ($a['size'] ?? 0) <= self::INLINE_MAX) {
                    $full = $this->call('GET', $path . '/attachments/' . $this->enc($a['id']))->json();
                    $cid = trim((string) ($full['contentId'] ?? ''), '<>');
                    if ($cid !== '' && str_contains($html, 'cid:' . $cid)) {
                        $html = str_replace('cid:' . $cid, 'data:' . ($full['contentType'] ?? 'image/png') . ';base64,' . ($full['contentBytes'] ?? ''), $html);
                        continue;
                    }
                }
                $attachments[] = [
                    'id'   => (string) $a['id'],
                    'name' => (string) ($a['name'] ?? 'anhang'),
                    'mime' => (string) ($a['contentType'] ?? 'application/octet-stream'),
                    'size' => (int) ($a['size'] ?? 0),
                ];
            }
        }

        if (empty($m['isRead'])) {
            try { $this->call('PATCH', $path, ['json' => ['isRead' => true]]); } catch (\Throwable) {}
        }

        return [
            'id'          => $id,
            'subject'     => (string) ($m['subject'] ?? ''),
            'from'        => $this->addr($m['from'] ?? null),
            'to'          => array_map(fn ($r) => $this->addr($r), $m['toRecipients'] ?? []),
            'cc'          => array_map(fn ($r) => $this->addr($r), $m['ccRecipients'] ?? []),
            'date'        => $m['receivedDateTime'] ?? null,
            'html'        => $html,
            'text'        => $text,
            'attachments' => $attachments,
        ];
    }

    public function attachment(string $id, string $attachmentId, string $folder = 'inbox'): array
    {
        $path = '/me/messages/' . $this->enc($id) . '/attachments/' . $this->enc($attachmentId);
        $meta = $this->call('GET', $path, ['query' => ['$select' => 'name,contentType']])->json();
        return [
            'name'    => (string) ($meta['name'] ?? 'anhang'),
            'mime'    => (string) ($meta['contentType'] ?? 'application/octet-stream'),
            'content' => $this->call('GET', $path . '/$value')->body(),
        ];
    }

    public function send(array $mail): void
    {
        $recipients = [
            'toRecipients'  => array_map(fn ($a) => ['emailAddress' => ['address' => $a]], $mail['to']),
            'ccRecipients'  => array_map(fn ($a) => ['emailAddress' => ['address' => $a]], $mail['cc'] ?? []),
            'bccRecipients' => array_map(fn ($a) => ['emailAddress' => ['address' => $a]], $mail['bcc'] ?? []),
        ];
        $textHtml = '<div style="white-space:pre-wrap">' . nl2br(htmlspecialchars((string) $mail['text'], ENT_QUOTES, 'UTF-8')) . '</div>';

        if (!empty($mail['reply_to']) || !empty($mail['forward_of'])) {
            // createReply/createForward keeps Outlook's threading, quotes the
            // original body and (forward) carries its attachments; our text
            // goes in front.
            $action = !empty($mail['forward_of']) ? 'createForward' : 'createReply';
            $src = !empty($mail['forward_of']) ? $mail['forward_of'] : $mail['reply_to'];
            $draft = $this->call('POST', '/me/messages/' . $this->enc($src) . '/' . $action, ['json' => (object) []])->json();
            $quoted = (string) ($draft['body']['content'] ?? '');
            $body = preg_match('/<body[^>]*>/i', $quoted)
                ? preg_replace('/(<body[^>]*>)/i', '$1' . str_replace(['\\', '$'], ['\\\\', '\\$'], $textHtml) . '<br>', $quoted, 1)
                : $textHtml . '<br>' . $quoted;
            $this->call('PATCH', '/me/messages/' . $this->enc($draft['id']), ['json' => $recipients + [
                'subject' => (string) $mail['subject'],
                'body'    => ['contentType' => 'HTML', 'content' => $body],
            ]]);
        } else {
            $draft = $this->call('POST', '/me/messages', ['json' => $recipients + [
                'subject' => (string) $mail['subject'],
                'body'    => ['contentType' => 'HTML', 'content' => $textHtml],
            ]])->json();
        }

        $draftPath = '/me/messages/' . $this->enc($draft['id']);
        try {
            foreach ($mail['files'] as $f) $this->attach($draftPath, $f);
            $this->call('POST', $draftPath . '/send');
        } catch (\Throwable $e) {
            try { $this->call('DELETE', $draftPath); } catch (\Throwable) {}
            throw $e;
        }
    }

    public function delete(string $id, string $folder = 'inbox'): void
    {
        // Graph DELETE moves the message to "Deleted Items".
        $this->call('DELETE', '/me/messages/' . $this->enc($id));
    }

    public function setSeen(string $id, bool $seen, string $folder = 'inbox'): void
    {
        $this->call('PATCH', '/me/messages/' . $this->enc($id), ['json' => ['isRead' => $seen]]);
    }

    private function attach(string $draftPath, array $f): void
    {
        $len = strlen($f['content']);
        if ($len <= self::DIRECT_ATTACH_MAX) {
            $this->call('POST', $draftPath . '/attachments', ['json' => [
                '@odata.type'  => '#microsoft.graph.fileAttachment',
                'name'         => $f['name'],
                'contentType'  => $f['mime'],
                'contentBytes' => base64_encode($f['content']),
            ]]);
            return;
        }
        $session = $this->call('POST', $draftPath . '/attachments/createUploadSession', ['json' => [
            'AttachmentItem' => ['attachmentType' => 'file', 'name' => $f['name'], 'size' => $len, 'contentType' => $f['mime']],
        ]])->json();
        $url = (string) ($session['uploadUrl'] ?? '');
        if (!str_starts_with($url, 'https://')) throw new MailException('Anhang konnte nicht hochgeladen werden.', 502);
        for ($off = 0; $off < $len; $off += self::CHUNK) {
            $part = substr($f['content'], $off, self::CHUNK);
            $end = $off + strlen($part) - 1;
            $res = MailOAuth::http()->timeout(60)->withHeaders(['Content-Range' => "bytes $off-$end/$len"])
                ->withBody($part, 'application/octet-stream')->put($url);
            if ($res->failed()) throw new MailException('Anhang „' . $f['name'] . '“ konnte nicht hochgeladen werden.', 502);
        }
    }

    private function unread(): ?int
    {
        $f = $this->call('GET', '/me/mailFolders/inbox', ['query' => ['$select' => 'unreadItemCount']])->json();
        return isset($f['unreadItemCount']) ? (int) $f['unreadItemCount'] : null;
    }

    private function call(string $method, string $path, array $options = []): Response
    {
        $headers = $options['headers'] ?? [];
        unset($options['headers']);
        $url = str_starts_with($path, 'https://') ? $path : self::BASE . $path;
        try {
            $res = MailOAuth::http()->withToken(MailService::accessToken($this->konto, $this->cred))
                ->withHeaders($headers)->acceptJson()->timeout(25)->connectTimeout(5)
                ->send($method, $url, $options);
        } catch (MailException $e) {
            throw $e;
        } catch (\Throwable) {
            throw new MailException('Microsoft 365 ist nicht erreichbar. Bitte später erneut versuchen.', 504);
        }
        if ($res->successful()) return $res;

        $code = (string) $res->json('error.code');
        Log::warning('mail.graph', ['konto' => $this->konto->id ?? null, 'status' => $res->status(), 'code' => $code, 'op' => $method . ' ' . preg_replace('#/[A-Za-z0-9_=%-]{40,}#', '/{id}', $path)]);
        throw match (true) {
            $res->status() === 401                           => new MailException('Die Verbindung zum Postfach ist abgelaufen. Bitte neu verbinden.', 409, true),
            $res->status() === 404                           => new MailException('Nachricht nicht gefunden.', 404),
            $code === 'ErrorAccessDenied', $res->status() === 403 => new MailException('Microsoft verweigert den Zugriff auf dieses Postfach. Bitte neu verbinden und die Berechtigungen bestätigen.', 409, true),
            $code === 'ErrorMessageSizeExceeded'             => new MailException('Die Nachricht ist zu groß für Microsoft 365.', 413),
            $res->status() === 429                           => new MailException('Microsoft 365 drosselt gerade die Anfragen. Bitte kurz warten.', 429),
            default                                          => new MailException('Microsoft 365 hat die Anfrage abgelehnt.', 502),
        };
    }

    private function enc(string $id): string
    {
        return rawurlencode($id);
    }

    private function addr(?array $r): array
    {
        return ['name' => (string) ($r['emailAddress']['name'] ?? ''), 'email' => (string) ($r['emailAddress']['address'] ?? '')];
    }
}
