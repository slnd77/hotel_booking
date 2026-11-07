<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Tells a user an API key was made or revoked (or a device signed out) on their account, so a
 * change they didn't make stands out.
 */
class ApiAccessChangedMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(public string $userName, public string $change) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: config('app.name').': API access changed');
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.api-access-changed',
            with: ['appName' => config('app.name'), 'userName' => $this->userName, 'change' => $this->change],
        );
    }
}
