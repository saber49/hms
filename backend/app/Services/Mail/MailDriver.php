<?php

namespace App\Services\Mail;

/**
 * One connected mailbox. Implementations talk to the provider live —
 * nothing is persisted except refreshed OAuth tokens.
 *
 * Folder:     'inbox' | 'sent'  (IMAP: UID is per-folder; Graph: id is global)
 * Cursor:     opaque paging token returned as `next` (IMAP: page nr,
 *             Graph: @odata.nextLink)
 * List item:  id, from_name, from_email, to_name, to_email, subject,
 *             date (ISO 8601), seen, has_attachments, preview
 * Message:    id, subject, from{name,email}, to[], cc[], date, html, text,
 *             attachments[{id,name,mime,size}]
 * Send input: to[], cc[], bcc[], subject, text, files[{name,mime,content}],
 *             reply_to, forward_of, src_folder (folder of reply_to/forward_of)
 */
interface MailDriver
{
    /** @return array{items: array, next: ?string, unread: ?int} */
    public function listing(string $folder, ?string $search, ?string $cursor, int $perPage): array;

    public function message(string $id, string $folder = 'inbox'): array;

    /** @return array{name: string, mime: string, content: string} */
    public function attachment(string $id, string $attachmentId, string $folder = 'inbox'): array;

    public function send(array $mail): void;

    /** Moves the message to the provider's trash folder. */
    public function delete(string $id, string $folder = 'inbox'): void;

    public function setSeen(string $id, bool $seen, string $folder = 'inbox'): void;

    /** Throws MailException when the mailbox cannot be reached/authenticated. */
    public function verify(): void;
}
