<?php

namespace App\Mail;

use App\Models\GoalPartnerInvite;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class PartnerInviteMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public GoalPartnerInvite $invite,
        public string $inviteUrl,
    ) {
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: sprintf('%s invited you to partner on "%s"', $this->invite->inviter->name, $this->invite->goal->title),
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.partner-invite',
            with: [
                'invite' => $this->invite,
                'inviteUrl' => $this->inviteUrl,
            ],
        );
    }
}
