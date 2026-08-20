<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class CommunicationMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly string $subjectLine,
        public readonly string $bodyText,
        public readonly ?string $attachmentDisk = null,
        public readonly ?string $attachmentPath = null,
        public readonly ?string $attachmentName = null,
        public readonly ?string $attachmentBytes = null,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->subjectLine);
    }

    public function content(): Content
    {
        return new Content(
            text: 'mail.communication',
            with: ['body' => $this->bodyText],
        );
    }

    /** @return list<Attachment> */
    public function attachments(): array
    {
        if ($this->attachmentBytes !== null) {
            return [Attachment::fromData(fn (): string => $this->attachmentBytes, $this->attachmentName ?? 'document.pdf')
                ->withMime('application/pdf')];
        }

        if ($this->attachmentDisk === null || $this->attachmentPath === null) {
            return [];
        }

        return [Attachment::fromStorageDisk($this->attachmentDisk, $this->attachmentPath)
            ->as($this->attachmentName ?? 'document.pdf')->withMime('application/pdf')];
    }
}
