<?php

use Domain\Billing\Application\BudgetDecision;
use Domain\Billing\Domain\Messages\Envelope;
use Domain\Billing\Domain\Messages\MessageType;
use Domain\Billing\Domain\Ports\MessagePublisher;
use Domain\Billing\Domain\Ports\MessageSource;
use Domain\Billing\Domain\Ports\PaymentGateway;
use Domain\Billing\Domain\Ports\ReceivedMessage;
use Illuminate\Support\Facades\Mail;

class FakeSource implements MessageSource
{
    public array $acked = [];

    public function __construct(public array $queue = []) {}

    public function receive(): array
    {
        [$batch, $this->queue] = [$this->queue, []];

        return $batch;
    }

    public function ack(ReceivedMessage $message): void
    {
        $this->acked[] = $message->receipt;
    }
}

class FakePublisher implements MessagePublisher
{
    public array $published = [];

    public function __construct(public bool $fail = false) {}

    public function publish(Envelope $envelope): void
    {
        if ($this->fail) {
            throw new RuntimeException('SNS indisponível');
        }
        $this->published[] = $envelope->type;
    }
}

beforeEach(fn () => Mail::fake());

it('billing:consume processa comandos, descarta malformados e mantém os que falham', function () {
    // Pedido de pagamento com o gateway fora do ar: não confirma, volta para a fila.
    dispatchCommand(budgetCommand(osId: 50));
    app(BudgetDecision::class)->approve(store()->budgetsOf(50)[0]->getId());
    $this->mock(PaymentGateway::class)->shouldReceive('createCheckout')->andThrow(new RuntimeException('MP fora'));

    $source = new FakeSource([
        new ReceivedMessage(budgetCommand(osId: 51)->toJson(), 'r-ok'),
        new ReceivedMessage('lixo', 'r-malformed'),
        new ReceivedMessage(command(MessageType::RequestPayment, 50)->toJson(), 'r-fail'),
    ]);
    app()->instance(MessageSource::class, $source);

    $this->artisan('billing:consume', ['--once' => true])
        ->expectsOutput('processadas: 1 | com falha: 1')
        ->assertSuccessful();

    expect($source->acked)->toBe(['r-ok', 'r-malformed'])
        ->and(store()->budgetsOf(51))->toHaveCount(1)
        ->and(store()->paymentsOf(50))->toBe([]);
});

it('billing:relay publica o outbox e para no primeiro erro', function () {
    dispatchCommand(budgetCommand(osId: 60));
    dispatchCommand(budgetCommand(osId: 61));

    app()->instance(MessagePublisher::class, $failing = new FakePublisher(fail: true));
    $this->artisan('billing:relay', ['--once' => true])->assertSuccessful();
    expect(outbox())->toHaveCount(2);

    app()->instance(MessagePublisher::class, $publisher = new FakePublisher);
    $this->artisan('billing:relay', ['--once' => true])
        ->expectsOutput('publicadas: 2')
        ->assertSuccessful();

    expect($publisher->published)->toBe(['billing.budget.generated', 'billing.budget.generated'])
        ->and(outbox())->toBe([]);
});

it('billing:dynamodb-table não falha se a tabela já existe', function () {
    $this->artisan('billing:dynamodb-table')->expectsOutputToContain('já existe')->assertSuccessful();
});
