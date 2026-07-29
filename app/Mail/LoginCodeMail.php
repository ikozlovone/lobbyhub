<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class LoginCodeMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public readonly string $code) {}

    public function envelope(): Envelope
    {
        // The code is in the subject too: most people read it from the inbox
        // list and never open the message.
        return new Envelope(subject: "{$this->code} is your LobbyHub sign-in code");
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.login-code',
            with: ['minutes' => (int) ceil(config('auth.codes.ttl') / 60)],
        );
    }
}
