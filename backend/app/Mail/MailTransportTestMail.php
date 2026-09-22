<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class MailTransportTestMail extends Mailable
{
    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'BluePOS mail transport test',
        );
    }

    public function content(): Content
    {
        return new Content(
            htmlString: '<p>BluePOS mail transport is working. This message does not contain a security code.</p>',
        );
    }
}
