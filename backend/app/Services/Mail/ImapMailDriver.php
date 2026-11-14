<?php

namespace App\Services\Mail;

use Symfony\Component\Mailer\Transport\Smtp\Auth\XOAuth2Authenticator;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Webklex\PHPIMAP\Client;
use Webklex\PHPIMAP\ClientManager;
use Webklex\PHPIMAP\Folder;
use Webklex\PHPIMAP\Message;

/**
 * Generic IMAP (read) + SMTP (send). Also serves Gmail — via XOAUTH2 when
 * connected with Google sign-in, or with an app password.
 */
class ImapMailDriver implements MailDriver
{
    private const INLINE_MAX = 2 * 1024 * 1024;
    private const FWD_ATTACH_MAX = 15 * 1024 * 1024;
    private const SENT_NAMES = [
        'sent', 'gesendet', 'gesendete objekte', 'gesendete elemente',
        'gesendete nachrichten', 'sent items', 'sent messages', 'sent mail',
    ];
    private const TRASH_NAMES = [
        'trash', 'papierkorb', 'deleted', 'deleted items', 'deleted messages',
        'gelöschte elemente', 'gelöscht', 'bin', 'inbox.trash',
    ];

    private ?Client $client = null;

    public function __construct(private object $konto, private array $cred) {}

    public function __destruct()
    {
        try { $this->client?->disconnect(); } catch (\Throwable) {}
    }

    public function verify(): void
    {
        $this->inboxFolder()->status();
        $smtp = $this->smtp();
        try {
            $smtp->start();
            $smtp->stop();
        } catch (\Throwable $e) {
            throw MailException::fromThrowable($e);
        }
    }

    public function listing(string $folderName, ?string $search, ?string $cursor, int $perPage): array
    {
        $page = max(1, (int) ($cursor ?: 1));
        $query = $this->folder($folderName)->query()->all()
            ->setFetchOrderDesc()->setFetchBody(false)->leaveUnread();
        if ($search !== null) {
            try {
                $query->whereText($search);
            } catch (\Throwable) {
                $query->whereSubject($search);
            }
        }
        try {
            $messages = $query->limit($perPage + 1, $page)->get();
        } catch (\Throwable $e) {
            throw MailException::fromThrowable($e);
        }

        $items = [];
        foreach ($messages->take($perPage) as $m) {
            $from = $this->address($m->getFrom());
            $to = $this->address($m->getTo());
            $items[] = [
                'id'              => (string) $m->getUid(),
                'from_name'       => $from['name'],
                'from_email'      => $from['email'],
                'to_name'         => $to['name'],
                'to_email'        => $to['email'],
                'subject'         => (string) $m->getSubject(),
                'date'            => $this->date($m),
                'seen'            => $m->getFlags()->has('seen'),
                'has_attachments' => stripos((string) $m->getHeader()?->get('content_type'), 'multipart/mixed') !== false,
                'preview'         => null,
            ];
        }
        return [
            'items'  => $items,
            'next'   => count($messages) > $perPage ? (string) ($page + 1) : null,
            'unread' => $this->unread(),
        ];
    }

    public function message(string $id, string $folder = 'inbox'): array
    {
        $m = $this->fetch($id, $folder);
        $html = $m->hasHTMLBody() ? (string) $m->getHTMLBody() : null;
        $text = $m->hasTextBody() ? (string) $m->getTextBody() : null;

        $attachments = [];
        $i = 0;
        foreach ($m->getAttachments() as $a) {
            $idx = (string) $i++;
            $cid = (string) $a->id;
            $mime = (string) ($a->content_type ?: $a->getMimeType() ?: 'application/octet-stream');
            if ($html && $cid !== '' && str_contains($html, 'cid:' . $cid) && str_starts_with($mime, 'image/') && (int) $a->size <= self::INLINE_MAX) {
                $html = str_replace('cid:' . $cid, 'data:' . $mime . ';base64,' . base64_encode((string) $a->getContent()), $html);
                continue;
            }
            $attachments[] = [
                'id'   => $idx,
                'name' => (string) ($a->name ?: $a->filename ?: 'Anhang-' . ($idx + 1)),
                'mime' => $mime,
                'size' => (int) $a->size,
            ];
        }

        if (!$m->getFlags()->has('seen')) {
            try { $m->setFlag('Seen'); } catch (\Throwable) {}
        }

        $from = $this->address($m->getFrom());
        return [
            'id'          => $id,
            'subject'     => (string) $m->getSubject(),
            'from'        => $from,
            'to'          => $this->addresses($m->getTo()),
            'cc'          => $this->addresses($m->getCc()),
            'date'        => $this->date($m),
            'html'        => $html,
            'text'        => $text,
            'attachments' => $attachments,
        ];
    }

    public function attachment(string $id, string $attachmentId, string $folder = 'inbox'): array
    {
        $m = $this->fetch($id, $folder);
        $i = 0;
        foreach ($m->getAttachments() as $a) {
            if ((string) $i++ === $attachmentId) {
                return [
                    'name'    => (string) ($a->name ?: $a->filename ?: 'anhang'),
                    'mime'    => (string) ($a->content_type ?: $a->getMimeType() ?: 'application/octet-stream'),
                    'content' => (string) $a->getContent(),
                ];
            }
        }
        throw new MailException('Anhang nicht gefunden.', 404);
    }

    public function send(array $mail): void
    {
        $email = (new Email())
            ->from(new Address($this->konto->email, (string) $this->konto->anzeige_name))
            ->subject((string) $mail['subject']);
        foreach ($mail['to'] as $a) $email->addTo($a);
        foreach ($mail['cc'] ?? [] as $a) $email->addCc($a);
        foreach ($mail['bcc'] ?? [] as $a) $email->addBcc($a);

        $text = (string) $mail['text'];
        $srcFolder = $mail['src_folder'] ?? 'inbox';
        if (!empty($mail['reply_to'])) {
            $orig = $this->fetch((string) $mail['reply_to'], $srcFolder);
            $mid = trim((string) $orig->getMessageId(), " <>\t\r\n");
            if ($mid !== '') {
                preg_match_all('/<([^>]+)>/', (string) $orig->getReferences(), $refs);
                $email->getHeaders()->addIdHeader('In-Reply-To', $mid);
                $email->getHeaders()->addIdHeader('References', array_values(array_unique([...$refs[1], $mid])));
            }
            $text .= "\n\n" . $this->quoteHead($orig) . "\n" . preg_replace('/^/m', '> ', $this->bodyText($orig));
        } elseif (!empty($mail['forward_of'])) {
            $orig = $this->fetch((string) $mail['forward_of'], $srcFolder);
            $fmt = fn ($a) => $a['name'] ? "{$a['name']} <{$a['email']}>" : $a['email'];
            $d = $this->date($orig);
            $text .= "\n\n---------- Weitergeleitete Nachricht ----------\n"
                . 'Von: ' . $fmt($this->address($orig->getFrom())) . "\n"
                . 'Datum: ' . ($d ? date('d.m.Y H:i', strtotime($d)) : '') . "\n"
                . 'Betreff: ' . (string) $orig->getSubject() . "\n"
                . 'An: ' . implode(', ', array_map($fmt, $this->addresses($orig->getTo()))) . "\n\n"
                . $this->bodyText($orig);
            $files = $mail['files'];
            foreach ($this->forwardedAttachments($orig) as $f) $files[] = $f;
            $mail['files'] = $files;
        }
        $email->text($text);
        foreach ($mail['files'] as $f) $email->attach($f['content'], $f['name'], $f['mime']);

        try {
            $sent = $this->smtp()->send($email);
        } catch (\Throwable $e) {
            throw MailException::fromThrowable($e);
        }

        // SMTP does not keep a copy — file it under "Gesendet" like a mail
        // client would (Gmail does this server-side already). Best-effort.
        if ($sent && !str_ends_with((string) $this->konto->smtp_host, 'gmail.com')) {
            try { $this->namedFolder(self::SENT_NAMES, '\\Sent')?->appendMessage($sent->toString(), ['\\Seen']); } catch (\Throwable) {}
        }
    }

    public function delete(string $id, string $folder = 'inbox'): void
    {
        $m = $this->fetch($id, $folder);
        try {
            $trash = $this->namedFolder(self::TRASH_NAMES, '\\Trash');
            if ($trash) {
                $m->move($trash->path, true);
                return;
            }
            $m->delete();
        } catch (\Throwable $e) {
            throw MailException::fromThrowable($e);
        }
    }

    public function setSeen(string $id, bool $seen, string $folder = 'inbox'): void
    {
        $m = $this->fetch($id, $folder);
        try {
            $seen ? $m->setFlag('Seen') : $m->unsetFlag('Seen');
        } catch (\Throwable $e) {
            throw MailException::fromThrowable($e);
        }
    }

    private function client(): Client
    {
        if ($this->client) return $this->client;
        if (!MailDiscovery::hostAllowed($this->konto->imap_host)) {
            throw new MailException('Die Adresse des Mailservers ist ungültig.', 422);
        }
        $client = (new ClientManager(['options' => ['soft_fail' => true]]))->make([
            'host'           => $this->konto->imap_host,
            'port'           => (int) $this->konto->imap_port,
            'encryption'     => $this->konto->imap_encryption === 'starttls' ? 'starttls' : 'ssl',
            'validate_cert'  => true,
            'username'       => $this->konto->username ?: $this->konto->email,
            'password'       => $this->secret(),
            'protocol'       => 'imap',
            'authentication' => $this->isOAuth() ? 'oauth' : null,
            'timeout'        => 15,
        ]);
        try {
            $client->connect();
        } catch (\Throwable $e) {
            throw MailException::fromThrowable($e);
        }
        return $this->client = $client;
    }

    private function inboxFolder(): Folder
    {
        try {
            $f = $this->client()->getFolderByPath('INBOX');
        } catch (MailException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw MailException::fromThrowable($e);
        }
        if (!$f) throw new MailException('Der Posteingang wurde nicht gefunden.', 502);
        return $f;
    }

    private function folder(string $name): Folder
    {
        if ($name === 'sent') {
            $f = $this->namedFolder(self::SENT_NAMES, '\\Sent');
            if (!$f) throw new MailException('Der Ordner „Gesendet“ wurde nicht gefunden.', 404);
            return $f;
        }
        return $this->inboxFolder();
    }

    private function namedFolder(array $names, string $attribute): ?Folder
    {
        try {
            foreach ($this->client()->getFolders(false) as $f) {
                if (in_array($attribute, (array) ($f->attributes ?? []), true)) return $f;
                if (in_array(mb_strtolower((string) $f->name), $names, true)) return $f;
            }
        } catch (MailException $e) {
            throw $e;
        } catch (\Throwable) {}
        return null;
    }

    private function unread(): ?int
    {
        try {
            $s = $this->inboxFolder()->status();
            return isset($s['unseen']) ? (int) $s['unseen'] : null;
        } catch (MailException $e) {
            throw $e;
        } catch (\Throwable) {
            return null;
        }
    }

    private function fetch(string $uid, string $folder = 'inbox'): Message
    {
        if (!ctype_digit($uid)) throw new MailException('Nachricht nicht gefunden.', 404);
        try {
            $m = $this->folder($folder)->query()->getMessageByUid((int) $uid);
        } catch (MailException $e) {
            throw $e;
        } catch (\Throwable) {
            $m = null;
        }
        if (!$m) throw new MailException('Nachricht nicht gefunden.', 404);
        return $m;
    }

    private function smtp(): EsmtpTransport
    {
        if (!MailDiscovery::hostAllowed($this->konto->smtp_host)) {
            throw new MailException('Die Adresse des Postausgangsservers ist ungültig.', 422);
        }
        $t = new EsmtpTransport((string) $this->konto->smtp_host, (int) $this->konto->smtp_port, $this->konto->smtp_encryption === 'ssl');
        if ($this->konto->smtp_encryption === 'starttls') $t->setRequireTls(true);
        $t->setUsername($this->konto->username ?: $this->konto->email);
        $t->setPassword($this->secret());
        if ($this->isOAuth()) $t->setAuthenticators([new XOAuth2Authenticator()]);
        $t->getStream()->setTimeout(20);
        return $t;
    }

    private function isOAuth(): bool
    {
        return ($this->cred['type'] ?? 'password') === 'oauth';
    }

    private function secret(): string
    {
        return $this->isOAuth()
            ? MailService::accessToken($this->konto, $this->cred)
            : (string) ($this->cred['password'] ?? '');
    }

    private function bodyText(Message $m): string
    {
        $body = $m->hasTextBody()
            ? (string) $m->getTextBody()
            : html_entity_decode(strip_tags(preg_replace('/<(br|\/p|\/div)[^>]*>/i', "\n", (string) $m->getHTMLBody())), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        return mb_substr(trim($body), 0, 20000);
    }

    private function quoteHead(Message $orig): string
    {
        $from = $this->address($orig->getFrom());
        $date = $this->date($orig);
        return 'Am ' . ($date ? date('d.m.Y H:i', strtotime($date)) : '') . ' schrieb ' . ($from['name'] ?: $from['email']) . ':';
    }

    private function forwardedAttachments(Message $orig): array
    {
        $html = $orig->hasHTMLBody() ? (string) $orig->getHTMLBody() : '';
        $files = [];
        $total = 0;
        foreach ($orig->getAttachments() as $a) {
            $mime = (string) ($a->content_type ?: $a->getMimeType() ?: 'application/octet-stream');
            $cid = (string) $a->id;
            if ($html && $cid !== '' && str_contains($html, 'cid:' . $cid) && str_starts_with($mime, 'image/')) continue;
            $content = (string) $a->getContent();
            $total += strlen($content);
            if ($total > self::FWD_ATTACH_MAX) break;
            $files[] = [
                'name'    => (string) ($a->name ?: $a->filename ?: 'Anhang'),
                'mime'    => $mime,
                'content' => $content,
            ];
        }
        return $files;
    }

    private function address($attr): array
    {
        $a = $attr?->first();
        return ['name' => $a ? trim((string) $a->personal) : '', 'email' => $a ? (string) $a->mail : ''];
    }

    private function addresses($attr): array
    {
        $out = [];
        foreach ($attr ? $attr->all() : [] as $a) {
            $out[] = ['name' => trim((string) $a->personal), 'email' => (string) $a->mail];
        }
        return $out;
    }

    private function date(Message $m): ?string
    {
        try {
            return $m->getDate()->toDate()->toIso8601String();
        } catch (\Throwable) {
            return null;
        }
    }
}
