<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * An e-mailed report (Reports → Edit: subject, heading and description) with its Excel / PDF
 * files attached. Sent from the SendReportEmail job (already queued).
 */
class ReportMail extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * @param  list<Attachment>  $files
     */
    public function __construct(public string $propertyName, public string $subjectLine, public string $heading, public string $description, public string $period, public array $files) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: "{$this->subjectLine} — {$this->propertyName} — {$this->period}");
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.report',
            with: [
                'propertyName' => $this->propertyName,
                'heading' => $this->heading,
                'description' => $this->description,
                'period' => $this->period,
            ],
        );
    }

    /**
     * @return list<Attachment>
     */
    public function attachments(): array
    {
        return $this->files;
    }
}
