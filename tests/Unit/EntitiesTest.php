<?php

use Domain\Billing\Domain\Entities\Budget;
use Domain\Billing\Domain\Entities\Payment;
use Domain\Billing\Domain\Enums\BudgetStatus;
use Domain\Billing\Domain\Enums\PaymentStatus;
use Domain\Billing\Domain\Exceptions\DomainException;
use Domain\Billing\Domain\Messages\Envelope;
use Domain\Billing\Domain\Messages\MessageType;

function aBudget(): Budget
{
    return Budget::fromCommand(1, 's', 'c', ['total_amount' => 100, 'revision' => 2, 'customer' => ['email' => 'a@b.c']]);
}

it('serializa e restaura o orçamento', function () {
    $budget = aBudget();
    $budget->approve();
    $copy = Budget::fromArray($budget->toArray());

    expect($copy->toArray())->toBe($budget->toArray())
        ->and($copy->getDecidedAt())->not->toBeNull()
        ->and($copy->getCreatedAt())->toBeInstanceOf(DateTimeImmutable::class)
        ->and($copy->getCustomerEmail())->toBe('a@b.c');
});

it('só decide orçamento pendente e cancela pendente ou aprovado', function () {
    $budget = aBudget();
    $budget->reject();
    expect(fn () => $budget->approve())->toThrow(DomainException::class);

    $budget->cancel();
    expect($budget->getStatus())->toBe(BudgetStatus::Rejected);

    $approved = aBudget();
    $approved->approve();
    $approved->cancel();
    expect($approved->getStatus())->toBe(BudgetStatus::Cancelled);
});

it('rótulos de status do orçamento', function () {
    expect(array_map(fn ($s) => $s->label(), BudgetStatus::cases()))->toHaveCount(5)
        ->and(PaymentStatus::Pending->isFinal())->toBeFalse()
        ->and(PaymentStatus::Refunded->isFinal())->toBeTrue();
});

it('serializa e restaura a cobrança', function () {
    $payment = Payment::forBudget(aBudget(), 0);
    $payment->attachCheckout('pref', 'https://pay');
    $payment->reject('cc_rejected', 'mp-9');
    $copy = Payment::fromArray($payment->toArray());

    expect($copy->toArray())->toBe($payment->toArray())
        ->and($copy->getAmount())->toBe(100.0)
        ->and($copy->getFailureReason())->toBe('cc_rejected')
        ->and($copy->getGatewayPaymentId())->toBe('mp-9');
});

it('envelope: SNS, campos obrigatórios e contexto de log', function () {
    $envelope = Envelope::new(MessageType::PaymentConfirmed, 's', 'c', 3, 'billing', ['x' => 1]);
    $wrapped = json_encode(['Type' => 'Notification', 'Message' => $envelope->toJson()]);

    expect(Envelope::fromJson($wrapped)->toArray())->toBe($envelope->toArray())
        ->and($envelope->logContext()['os_id'])->toBe('3')
        ->and(fn () => Envelope::fromJson('x'))->toThrow(DomainException::class)
        ->and(fn () => Envelope::fromArray(['id' => 'a', 'type' => 'b']))->toThrow(DomainException::class);
});
