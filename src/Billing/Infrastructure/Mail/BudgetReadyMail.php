<?php

namespace Domain\Billing\Infrastructure\Mail;

use Domain\Billing\Domain\Entities\Budget;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class BudgetReadyMail extends Mailable
{
    public function __construct(
        public readonly Budget $budget,
        public readonly string $approveUrl,
        public readonly string $rejectUrl,
        public readonly string $cancelUrl,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: "Orçamento da sua OS #{$this->budget->getOsId()}");
    }

    public function content(): Content
    {
        return new Content(view: 'mail.budget-ready');
    }
}
