<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Generic queueable mailable for transactional system emails:
 *   - emails.welcome-user      (admin creates a new sub-user)
 *   - emails.password-reset    (self-service forgot-password)
 *
 * Mirrors the NuNotificationMail pattern: a thin wrapper that ships
 * primitive payload to a Blade template. We DO NOT serialize Eloquent
 * models — under Octane + Redis queue with multiple tenants, a model
 * deserialized at job time would re-resolve against whichever tenant
 * connection the worker happens to have bound.
 */
class SystemMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public string $blade;
    public string $subjectLine;
    public array $payload;

    public function __construct(string $blade, string $subjectLine, array $payload)
    {
        // Own queue, worked by ONE worker only (hms-queue): IONOS limits SMTP
        // sessions — parallel workers must never send mail concurrently.
        $this->onQueue('mail');
        $this->blade        = $blade;
        $this->subjectLine  = $subjectLine;
        $this->payload      = $payload;
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->subjectLine);
    }

    public function content(): Content
    {
        return new Content(
            view: $this->blade,
            with: $this->payload,
        );
    }
}
