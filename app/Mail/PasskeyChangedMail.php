<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Tells a user a passkey was added to or removed from their account, so a change they didn't
 * make stands out.
 */
class PasskeyChangedMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    /**
     * @param  string  $change  "added" or "removed"
     */
    public function __construct(public string $userName, public string $passkeyName, public string $change, public string $changedAt, public ?string $ipAddress, public ?string $device) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: config('app.name').": a passkey was {$this->change}");
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.passkey-changed',
            with: [
                'appName' => config('app.name'),
                'userName' => $this->userName,
                'passkeyName' => $this->passkeyName,
                'change' => $this->change,
                'changedAt' => $this->changedAt,
                'ipAddress' => $this->ipAddress,
                'device' => $this->device,
            ],
        );
    }
}
