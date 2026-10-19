<?php

use Domain\Billing\Application\BudgetDecision;
use Domain\Billing\Domain\Messages\MessageType;
use Domain\Billing\Domain\Ports\PaymentGateway;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;

beforeEach(function () {
    Mail::fake();
    config([
        'billing.payments.driver' => 'mercadopago',
        'billing.mercadopago.access_token' => 'TEST-token',
        'billing.mercadopago.webhook_secret' => null,
    ]);

    Http::fake([
        'api.mercadopago.com/checkout/preferences' => Http::response([
            'id' => 'pref-123',
            'init_point' => 'https://www.mercadopago.com.br/checkout?pref=pref-123',
            'sandbox_init_point' => 'https://sandbox.mercadopago.com.br/checkout?pref=pref-123',
        ], 201),
        'api.mercadopago.com/v1/payments/555/refunds' => Http::response(['id' => 1, 'status' => 'approved'], 201),
        'api.mercadopago.com/v1/payments/555' => fn () => Http::response([
            'id' => 555,
            'status' => $this->mpStatus ?? 'approved',
            'status_detail' => 'accredited',
            'external_reference' => store()->paymentsOf(42)[0]->getId(),
        ]),
        'api.mercadopago.com/v1/payments/999' => Http::response(['message' => 'boom'], 500),
        'api.mercadopago.com/v1/payments/777' => Http::response(['id' => 777, 'status' => 'approved', 'external_reference' => 'outra']),
    ]);

    dispatchCommand(budgetCommand());
    $budget = store()->budgetsOf(42)[0];
    app(BudgetDecision::class)->approve($budget->getId());
    dispatchCommand(command(MessageType::RequestPayment, 42, ['budget_id' => $budget->getId(), 'amount' => 300.0]));
    $this->payment = store()->paymentsOf(42)[0];
});

function mpSignature(string $dataId, string $requestId, string $secret, ?int $ts = null): string
{
    $ts ??= time() * 1000;
    $v1 = hash_hmac('sha256', "id:{$dataId};request-id:{$requestId};ts:{$ts};", $secret);

    return "ts={$ts},v1={$v1}";
}

it('cria a preferência do Checkout Pro com a referência da cobrança', function () {
    expect($this->payment->getCheckoutUrl())->toBe('https://sandbox.mercadopago.com.br/checkout?pref=pref-123');

    Http::assertSent(function (Request $request) {
        return $request->url() === 'https://api.mercadopago.com/checkout/preferences'
            && $request->hasHeader('Authorization', 'Bearer TEST-token')
            && $request->hasHeader('X-Idempotency-Key', $this->payment->getId())
            && $request['external_reference'] === $this->payment->getId()
            && $request['items'][0]['unit_price'] === 300.0
            && $request['payer']['email'] === 'cliente@test.com'
            && str_ends_with($request['notification_url'], '/billing/api/webhooks/mercadopago');
    });
});

it('confirma o pagamento pelo webhook consultando a API', function () {
    $this->postJson('/billing/api/webhooks/mercadopago', ['type' => 'payment', 'data' => ['id' => '555']])
        ->assertOk()
        ->assertJsonPath('result', 'approved');

    expect(lastEvent(MessageType::PaymentConfirmed)->payload['gateway_payment_id'])->toBe('555');
});

it('recusa o pagamento quando o MP informa rejected', function () {
    $this->mpStatus = 'rejected';

    $this->postJson('/billing/api/webhooks/mercadopago?type=payment&data.id=555')
        ->assertOk()
        ->assertJsonPath('result', 'rejected');

    expect(lastEvent(MessageType::PaymentFailed)->payload['reason'])->toBe('accredited');
});

it('mantém a cobrança pendente enquanto o MP processa', function () {
    $this->mpStatus = 'in_process';

    $this->postJson('/billing/api/webhooks/mercadopago', ['type' => 'payment', 'data' => ['id' => '555']])
        ->assertJsonPath('result', 'pending');
});

it('valida o x-signature quando há chave secreta', function () {
    config(['billing.mercadopago.webhook_secret' => 's3cr3t']);
    $body = ['type' => 'payment', 'data' => ['id' => '555']];

    $this->postJson('/billing/api/webhooks/mercadopago?data.id=555', $body, [
        'x-signature' => mpSignature('555', 'rq-1', 'outra-chave'),
        'x-request-id' => 'rq-1',
    ])->assertStatus(401);

    $this->postJson('/billing/api/webhooks/mercadopago?data.id=555', $body, [
        'x-signature' => mpSignature('555', 'rq-1', 's3cr3t'),
        'x-request-id' => 'rq-1',
    ])->assertOk()->assertJsonPath('result', 'approved');
});

it('ignora notificações que não são de pagamento ou de cobrança desconhecida', function () {
    $this->postJson('/billing/api/webhooks/mercadopago', ['type' => 'merchant_order', 'data' => ['id' => '1']])
        ->assertJsonPath('result', 'ignored');
    $this->postJson('/billing/api/webhooks/mercadopago', ['type' => 'payment', 'data' => ['id' => '777']])
        ->assertJsonPath('result', 'unknown_payment');
});

it('devolve 502 quando a API do MP falha, para o MP reenviar', function () {
    $this->postJson('/billing/api/webhooks/mercadopago', ['type' => 'payment', 'data' => ['id' => '999']])
        ->assertStatus(502);
});

it('estorna no MP com chave de idempotência', function () {
    $this->postJson('/billing/api/webhooks/mercadopago', ['type' => 'payment', 'data' => ['id' => '555']]);
    $refund = dispatchCommand(command(MessageType::RefundPayment, 42, ['payment_id' => $this->payment->getId()]));

    Http::assertSent(fn (Request $r) => $r->url() === 'https://api.mercadopago.com/v1/payments/555/refunds'
        && $r->hasHeader('X-Idempotency-Key', $refund->id));
    expect(lastEvent(MessageType::PaymentRefunded)->payload['refunded'])->toBeTrue();
});

it('usa o init_point de produção fora do sandbox', function () {
    config(['billing.mercadopago.sandbox' => false]);
    $checkout = app(PaymentGateway::class)->createCheckout($this->payment, store()->budgetsOf(42)[0]);

    expect($checkout->url)->toBe('https://www.mercadopago.com.br/checkout?pref=pref-123')
        ->and($checkout->reference)->toBe('pref-123');
});

it('expõe o contador de webhooks e o outbox pendente no /metrics', function () {
    $this->postJson('/billing/api/webhooks/mercadopago', ['type' => 'payment', 'data' => ['id' => '555']]);

    $this->get('/metrics')
        ->assertOk()
        ->assertSee('oficina_billing_mercadopago_webhooks_total{result="approved"} 1', false)
        ->assertSee('oficina_billing_outbox_pending_messages 3', false);
});
