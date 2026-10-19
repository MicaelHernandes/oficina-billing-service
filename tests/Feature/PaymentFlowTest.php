<?php

use Domain\Billing\Application\BudgetDecision;
use Domain\Billing\Application\PaymentNotificationHandler;
use Domain\Billing\Domain\Messages\MessageType;
use Domain\Billing\Domain\Ports\GatewayPayment;
use Domain\Billing\Infrastructure\Mail\PaymentRequestedMail;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;

beforeEach(function () {
    Mail::fake();
    dispatchCommand(budgetCommand());
    $this->budget = store()->budgetsOf(42)[0];
    app(BudgetDecision::class)->approve($this->budget->getId());
});

function requestPayment(?string $budgetId): void
{
    dispatchCommand(command(MessageType::RequestPayment, 42, ['budget_id' => $budgetId, 'amount' => 300.0]));
}

it('cria a cobrança e envia o link de pagamento', function () {
    requestPayment($this->budget->getId());

    $payment = store()->paymentsOf(42)[0];
    expect($payment->getStatus()->value)->toBe('pending')
        ->and($payment->getAmount())->toBe(300.0)
        ->and($payment->getCheckoutUrl())->toContain("/billing/api/public/payments/{$payment->getId()}/fake-checkout");

    Mail::assertSent(PaymentRequestedMail::class, fn ($mail) => $mail->hasTo('cliente@test.com')
        && str_contains($mail->render(), 'Pagar agora'));
});

it('reaproveita a cobrança em aberto do mesmo orçamento', function () {
    requestPayment($this->budget->getId());
    requestPayment(null);

    expect(store()->paymentsOf(42))->toHaveCount(1);
});

it('responde billing.payment.failed quando não há orçamento aprovado', function () {
    requestPayment('nao-existe');
    dispatchCommand(command(MessageType::RequestPayment, 99));

    expect(lastEvent(MessageType::PaymentFailed)->payload['reason'])->toBe('orçamento aprovado não encontrado')
        ->and(lastEvent(MessageType::PaymentFailed)->osId)->toBe(99);
});

it('confirma o pagamento pelo checkout simulado', function () {
    requestPayment($this->budget->getId());
    $payment = store()->paymentsOf(42)[0];

    $page = $this->getJson($payment->getCheckoutUrl())->assertOk()->json();
    expect($page['data']['status'])->toBe('pending');

    $this->postJson($page['pay_url'], ['status' => 'approved'])
        ->assertOk()
        ->assertJsonPath('data.status', 'approved');

    $event = lastEvent(MessageType::PaymentConfirmed);
    expect($event->payload['payment_id'])->toBe($payment->getId())
        ->and($event->payload['amount'])->toBe(300.0)
        ->and($event->sagaId)->toBe('saga-42');

    // Notificação repetida não muda a cobrança já decidida.
    $this->postJson($page['pay_url'], ['status' => 'rejected'])->assertJsonPath('data.status', 'approved');
});

it('recusa o pagamento pelo checkout simulado', function () {
    requestPayment($this->budget->getId());
    $page = $this->getJson(store()->paymentsOf(42)[0]->getCheckoutUrl())->json();

    $this->postJson($page['pay_url'], ['status' => 'talvez'])->assertStatus(422);
    $this->postJson($page['pay_url'], ['status' => 'rejected'])->assertJsonPath('data.status', 'rejected');

    expect(lastEvent(MessageType::PaymentFailed)->payload['reason'])->toBe('cc_rejected_other_reason');
});

it('o checkout simulado devolve 404 para cobrança inexistente', function () {
    $url = URL::temporarySignedRoute('billing.fake-checkout', now()->addHour(), ['payment' => 'x']);
    $pay = URL::temporarySignedRoute('billing.fake-checkout.pay', now()->addHour(), ['payment' => 'x']);

    $this->getJson($url)->assertNotFound();
    $this->postJson($pay, ['status' => 'approved'])->assertNotFound();
});

it('estorna o pagamento aprovado na compensação', function () {
    requestPayment($this->budget->getId());
    $payment = store()->paymentsOf(42)[0];
    app(PaymentNotificationHandler::class)->apply(
        new GatewayPayment('mp-1', 'approved', $payment->getId())
    );

    dispatchCommand(command(MessageType::RefundPayment, 42, ['payment_id' => $payment->getId(), 'reason' => 'sem estoque']));

    expect(store()->findPayment($payment->getId())->getStatus()->value)->toBe('refunded')
        ->and(store()->findBudget($this->budget->getId())->getStatus()->value)->toBe('cancelled')
        ->and(lastEvent(MessageType::PaymentRefunded)->payload)->toBe([
            'payment_id' => $payment->getId(), 'refunded' => true, 'amount' => 300.0,
        ]);
});

it('responde ao estorno mesmo sem nada a estornar', function () {
    dispatchCommand(command(MessageType::RefundPayment, 42));

    expect(lastEvent(MessageType::PaymentRefunded)->payload)->toBe(['payment_id' => null, 'refunded' => false, 'amount' => 0.0]);
});

it('cancela cobrança pendente e orçamento', function () {
    requestPayment($this->budget->getId());
    dispatchCommand(command(MessageType::CancelPayment, 42));

    expect(store()->paymentsOf(42)[0]->getStatus()->value)->toBe('cancelled')
        ->and(store()->findBudget($this->budget->getId())->getStatus()->value)->toBe('cancelled');
});

it('cancela o orçamento', function () {
    dispatchCommand(command(MessageType::CancelBudget, 42));

    expect(store()->findBudget($this->budget->getId())->getStatus()->value)->toBe('cancelled');
});

it('expõe orçamentos e cobranças na rota interna', function () {
    requestPayment($this->budget->getId());

    $this->getJson('/billing/api/order-services/42')->assertUnauthorized();
    $this->withToken('errado')->getJson('/billing/api/order-services/42')->assertUnauthorized();
    $this->withToken('test-internal-token')->getJson('/billing/api/order-services/42')
        ->assertOk()
        ->assertJsonCount(1, 'data.budgets')
        ->assertJsonCount(1, 'data.payments')
        ->assertJsonPath('data.budgets.0.status', 'approved');
});
