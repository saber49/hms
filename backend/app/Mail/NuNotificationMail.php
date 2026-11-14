<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Generic queueable mailable for all NU (subcontractor) notifications:
 *   - emails.nu-request           (new shift requested)
 *   - emails.nu-shift-cancelled   (shift removed)
 *   - emails.nu-time-change       (start/end time changed)
 *   - emails.nu-worker-removal    (NU worker removed by HMS)
 *
 * Why one class instead of four: payload is already POPO arrays in the
 * controllers, every template renders the same way, and Sanctum/Octane
 * benefits from a smaller serialized job. Keeping this fully generic also
 * means the dispatcher controller doesn't need to know about queue mechanics.
 *
 * IMPORTANT for multi-tenant safety: all $data passed in MUST be primitive
 * (strings/ints/arrays) — never Eloquent models or DB query results that
 * would be re-resolved from the wrong tenant DB by SerializesModels at
 * job-execution time.
 */
class NuNotificationMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    /**
     * Note: $view and $viewData are inherited (untyped) from Mailable, so we
     * cannot redeclare them with PHP types. We use distinctly named, typed
     * fields here instead and wire them up in content() below.
     */
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
