<?php

namespace App\Mail;

use App\Models\SupportRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class SupportRequestMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(public readonly SupportRequest $request) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Питання з сайту: '.$this->request->name,
            // Відповідати зручно прямо з листа — адреса того, хто питає.
            replyTo: [new Address($this->request->email, $this->request->name)],
        );
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.support-request');
    }
}
