<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Queue\SerializesModels;

class OfferLetterMail extends Mailable
{
    use Queueable, SerializesModels;

    public $offer;
    public $attachmentPath;

    /**
     * Everything the template needs beyond the offer itself.
     *
     * Public properties rather than a with() array, matching how $offer was
     * already exposed - Laravel passes public mailable properties to the view,
     * and mixing the two conventions in one class is how a variable ends up
     * undefined in production but fine in a preview.
     */
    public $candidateName;
    public $organisation;
    public $responseUrl;
    public $expiresAt;

    public function __construct(
        $offer,
        $attachmentPath = null,
        ?string $candidateName = null,
        ?string $organisation = null,
        ?string $responseUrl = null,
        $expiresAt = null
    ) {
        $this->offer = $offer;
        $this->attachmentPath = $attachmentPath;
        $this->candidateName = $candidateName;
        $this->organisation = $organisation;
        $this->responseUrl = $responseUrl;
        $this->expiresAt = $expiresAt;
    }

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        /*
         * The subject names the role. "Talent Offer Letter" is internal
         * vocabulary - a candidate reading their inbox sees an email about a job
         * they applied for, not about a module of our software.
         */
        return new Envelope(
            subject: $this->offer->position
                ? 'Your offer: ' . $this->offer->position
                : 'Your job offer',
        );
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        return new Content(
            view: 'emails.offer_letter',
        );
    }

    /**
     * Get the attachments for the message.
     *
     * @return array<int, \Illuminate\Mail\Mailables\Attachment>
     */
    public function attachments(): array
    {
        $attachments = [];
        if ($this->attachmentPath) {
            // Named for the candidate's downloads folder, not for our storage.
            $attachments[] = Attachment::fromPath($this->attachmentPath)
                ->as('Offer Letter' . ($this->offer->position ? ' - ' . $this->offer->position : '') . '.pdf');
        }
        return $attachments;
    }
}
