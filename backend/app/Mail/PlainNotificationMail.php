<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Queueable replacement for the synchronous Mail::raw() calls in
 * NotificationDispatcher. Carries either a prebuilt HTML body or a plain
 * text body (rendered as escaped HTML with line breaks). Payload is
 * primitives only — safe to serialize across the multi-tenant queue.
 */
class PlainNotificationMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $subjectLine,
        public string $bodyText,
        public ?string $htmlBody = null,
    ) {
        // Own queue, worked by ONE worker only (hms-queue): IONOS limits SMTP
        // sessions — parallel workers must never send mail concurrently.
        $this->onQueue('mail');
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->subjectLine);
    }

    public function content(): Content
    {
        return new Content(
            htmlString: $this->htmlBody ?? nl2br(e($this->bodyText)),
        );
    }
}
