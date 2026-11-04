<?php

namespace Domain\Billing\Infrastructure\Mail;

use Domain\Billing\Domain\Entities\Budget;
use Domain\Billing\Domain\Entities\Payment;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class PaymentRequestedMail extends Mailable
{
    public function __construct(
        public readonly Budget $budget,
        public readonly Payment $payment,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: "Pagamento da sua OS #{$this->budget->getOsId()}");
    }

    public function content(): Content
    {
        return new Content(view: 'mail.payment-requested');
    }
}
