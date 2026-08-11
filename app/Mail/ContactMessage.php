<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * A message written into the contact form on the site.
 *
 * `replyTo` is set to the visitor's address rather than `from`: the SPF and
 * DKIM alignment of the sending server would refuse an outbound with a
 * different `from`, and the mailbox on the other side would classify it as
 * spam. Setting reply-to instead means clicking Reply in whatever mail client
 * receives this lands at the visitor without any address-book fiddling.
 *
 * Subject carries a fixed prefix so support inbox filters can route it and a
 * human skimming the list can spot it — the visitor's own subject is preserved
 * after the prefix.
 */
class ContactMessage extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly string $fromEmail,
        public readonly ?string $fromName,
        public readonly string $subject,
        public readonly string $body,
        public readonly string $ip,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: '[LobbyHub contact] '.$this->subject,
            replyTo: [new Address($this->fromEmail, $this->fromName ?? '')],
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.contact-message',
            with: [
                'fromEmail' => $this->fromEmail,
                'fromName' => $this->fromName,
                'subject' => $this->subject,
                'body' => $this->body,
                'ip' => $this->ip,
            ],
        );
    }
}
