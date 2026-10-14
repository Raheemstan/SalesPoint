<?php

namespace App\Mail;

use App\Models\Backup;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * "Last night's backup worked" - or, more usefully, "it did not".
 *
 * A notification, not a transport. The backup itself goes to object storage;
 * this is so that somebody finds out on the morning it stopped working rather
 * than on the morning they needed it. The subject line says which, because a
 * report nobody reads until they open it is a report nobody reads.
 *
 * Queueable rather than ShouldQueue: BackupService decides. A report carrying
 * an attachment is sent inline because the attached file is a temporary one the
 * run deletes on its way out, and a worker picking the job up later would find
 * nothing there.
 *
 * @param  array<string, mixed>  $facts
 */
class BackupReportMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public Backup $backup,
        public array $facts,
        public ?string $attachmentPath = null,
        public ?string $attachmentNote = null,
    ) {
    }

    public function envelope(): Envelope
    {
        $shop = $this->facts['shop'] ?? config('app.name');
        $outcome = $this->backup->succeeded() ? 'Backup OK' : 'BACKUP FAILED';

        // A failure that needs a person is worth saying in the subject too: the
        // local backup worked, so "Backup OK" on its own would be misleading.
        if ($this->backup->succeeded() && $this->backup->offsite_status === Backup::OFFSITE_FAILED) {
            $outcome = 'Backup OK, offsite copy FAILED';
        }

        return new Envelope(
            subject: $shop . ' - ' . $outcome . ' - ' . ($this->backup->created_at ?? now())->format('d M Y'),
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'mail.backup-report',
            with: [
                'backup' => $this->backup,
                'facts' => $this->facts,
                'attachmentNote' => $this->attachmentNote,
                'attached' => $this->attachmentPath !== null,
            ],
        );
    }

    /**
     * @return array<int, Attachment>
     */
    public function attachments(): array
    {
        if ($this->attachmentPath === null || !is_file($this->attachmentPath)) {
            return [];
        }

        /*
         * Only ever the compressed, encrypted artifact - BackupService refuses
         * to pass a plaintext dump here, and the name it is sent under keeps
         * the .gz.enc so the person receiving it can see what it is.
         */
        return [
            Attachment::fromPath($this->attachmentPath)
                ->as(basename($this->attachmentPath))
                ->withMime('application/octet-stream'),
        ];
    }
}
