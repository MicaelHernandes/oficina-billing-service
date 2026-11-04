<?php

use Domain\Billing\Domain\Exceptions\DomainException;
use Domain\Billing\Domain\Messages\Envelope;
use Domain\Billing\Domain\Messages\MessageType;
use Domain\Billing\Infrastructure\Mail\BudgetReadyMail;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;

beforeEach(fn () => Mail::fake());

function signedBudgetUrl(string $route, string $budgetId, array $extra = []): string
{
    return URL::temporarySignedRoute($route, now()->addHour(), ['budget' => $budgetId] + $extra);
}

it('gera o orçamento, avisa a saga e envia os links ao cliente', function () {
    dispatchCommand(budgetCommand());

    $budget = store()->budgetsOf(42)[0];
    $event = lastEvent(MessageType::BudgetGenerated);

    expect($budget->getStatus()->value)->toBe('pending')
        ->and($budget->getTotalAmount())->toBe(300.0)
        ->and($budget->getSagaId())->toBe('saga-42')
        ->and($event->payload)->toBe(['budget_id' => $budget->getId(), 'revision' => 1, 'total_amount' => 300.0])
        ->and($event->correlationId)->toBe('req-42')
        ->and($event->osId)->toBe(42)
        ->and($event->source)->toBe('oficina-billing-service');

    Mail::assertSent(BudgetReadyMail::class, function (BudgetReadyMail $mail) use ($budget) {
        return $mail->hasTo('cliente@test.com')
            && str_contains($mail->approveUrl, "/billing/api/public/budgets/{$budget->getId()}/approve?")
            && str_contains($mail->cancelUrl, 'final=1')
            && str_contains($mail->render(), 'R$ 300,00');
    });
});

it('processa o mesmo comando uma única vez', function () {
    $command = budgetCommand();
    dispatchCommand($command);
    dispatchCommand($command);

    expect(store()->budgetsOf(42))->toHaveCount(1)
        ->and(outboxTypes())->toBe(['billing.budget.generated']);
});

it('substitui a proposta pendente na renegociação', function () {
    dispatchCommand(budgetCommand(revision: 1));
    $first = store()->budgetsOf(42)[0];

    dispatchCommand(budgetCommand(revision: 2, total: 250.0));
    [$latest, $previous] = store()->budgetsOf(42);

    expect($latest->getRevision())->toBe(2)
        ->and($latest->getStatus()->value)->toBe('pending')
        ->and($previous->getId())->toBe($first->getId())
        ->and($previous->getStatus()->value)->toBe('superseded');

    // O link da revisão antiga não vale mais.
    $this->getJson(signedBudgetUrl('billing.public.budgets.approve', $first->getId()))
        ->assertStatus(422)
        ->assertJsonPath('message', "O orçamento já está 'Substituído por nova proposta' e não pode mais ser alterado.");
});

it('aprova pelo link assinado e publica billing.budget.approved', function () {
    dispatchCommand(budgetCommand());
    $budget = store()->budgetsOf(42)[0];

    $this->getJson(signedBudgetUrl('billing.public.budgets.show', $budget->getId()))
        ->assertOk()
        ->assertJsonPath('data.status', 'pending');

    $this->getJson(signedBudgetUrl('billing.public.budgets.approve', $budget->getId()))
        ->assertOk()
        ->assertJsonPath('data.status', 'approved');

    expect(lastEvent(MessageType::BudgetApproved)->payload)->toBe(['budget_id' => $budget->getId(), 'revision' => 1]);

    $this->getJson(signedBudgetUrl('billing.public.budgets.approve', $budget->getId()))->assertStatus(422);
});

it('recusa com ou sem cancelamento da OS', function (bool $final) {
    dispatchCommand(budgetCommand());
    $budget = store()->budgetsOf(42)[0];

    $this->getJson(signedBudgetUrl('billing.public.budgets.reject', $budget->getId(), $final ? ['final' => 1] : []))
        ->assertOk()
        ->assertJsonPath('data.status', 'rejected');

    expect(lastEvent(MessageType::BudgetRejected)->payload['final'])->toBe($final);
})->with([true, false]);

it('exige assinatura válida e orçamento existente', function () {
    dispatchCommand(budgetCommand());
    $budget = store()->budgetsOf(42)[0];

    $this->getJson("/billing/api/public/budgets/{$budget->getId()}/approve")->assertForbidden();
    $this->getJson(signedBudgetUrl('billing.public.budgets.approve', 'nao-existe'))->assertNotFound();
    $this->getJson(signedBudgetUrl('billing.public.budgets.show', 'nao-existe'))->assertNotFound();
});

it('recusa orçamento sem valor e ignora mensagens sem OS ou de tipo desconhecido', function () {
    expect(fn () => dispatchCommand(budgetCommand(total: 0)))->toThrow(DomainException::class);

    dispatchCommand(Envelope::fromArray(['id' => 'm1', 'type' => 'os.order.opened', 'source' => 'os', 'os_id' => 42]));
    dispatchCommand(Envelope::fromArray(['id' => 'm2', 'type' => 'billing.budget.generate', 'source' => 'os']));
    dispatchCommand(command(MessageType::BudgetApproved));

    expect(outbox())->toBe([]);
});
