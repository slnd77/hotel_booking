<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * E-mail OTP: the one-time code for signing in or turning e-mail codes on.
 */
class TwoFactorCodeMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public string $code, public int $minutes) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Your sign-in code: '.$this->code);
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.two-factor-code',
            with: [
                'appName' => config('app.name'),
                'code' => $this->code,
                'minutes' => $this->minutes,
            ],
        );
    }
}
