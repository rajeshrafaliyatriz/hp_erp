<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class NewsletterMail extends Mailable
{
    use Queueable, SerializesModels;

    public $body;
    public $ctaLink;
    public $ctaText;
    public $subject;

    /**
     * Create a new message instance.
     */
    public function __construct($body, $ctaLink, $ctaText, $subject)
    {
        $this->body = $body;
        $this->ctaLink = $ctaLink;
        $this->ctaText = $ctaText;
        $this->subject = $subject;
    }

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        return new Envelope(
            subject: $this->subject,
        );
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        return new Content(
            view: 'emails.newsletter',
            with: [
                'body' => $this->body,
                'ctaLink' => $this->ctaLink,
                'ctaText' => $this->ctaText,
            ],
        );
    }
}