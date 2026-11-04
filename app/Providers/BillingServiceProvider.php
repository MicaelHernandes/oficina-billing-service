<?php

namespace App\Providers;

use Aws\DynamoDb\DynamoDbClient;
use Aws\Sns\SnsClient;
use Aws\Sqs\SqsClient;
use Domain\Billing\Domain\Ports\CustomerNotifier;
use Domain\Billing\Domain\Ports\MessagePublisher;
use Domain\Billing\Domain\Ports\MessageSource;
use Domain\Billing\Domain\Ports\PaymentGateway;
use Domain\Billing\Domain\Repositories\BillingStoreInterface;
use Domain\Billing\Infrastructure\Mail\MailCustomerNotifier;
use Domain\Billing\Infrastructure\Messaging\LogMessagePublisher;
use Domain\Billing\Infrastructure\Messaging\NullMessageSource;
use Domain\Billing\Infrastructure\Messaging\SnsMessagePublisher;
use Domain\Billing\Infrastructure\Messaging\SqsMessageSource;
use Domain\Billing\Infrastructure\Payments\FakePaymentGateway;
use Domain\Billing\Infrastructure\Payments\MercadoPagoGateway;
use Domain\Billing\Infrastructure\Persistence\DynamoDbBillingStore;
use Domain\Billing\Presentation\Commands\ConsumeMessagesCommand;
use Domain\Billing\Presentation\Commands\CreateTableCommand;
use Domain\Billing\Presentation\Commands\RelayOutboxCommand;
use Illuminate\Support\ServiceProvider;

class BillingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Credenciais AWS pela cadeia padrão do SDK (IRSA no EKS). Com endpoint
        // local (DynamoDB Local), credenciais fictícias.
        $this->app->singleton(DynamoDbClient::class, function () {
            $endpoint = config('billing.dynamodb.endpoint');

            return new DynamoDbClient(array_filter([
                'region' => config('billing.dynamodb.region'),
                'version' => '2012-08-10',
                'endpoint' => $endpoint,
                'credentials' => $endpoint ? ['key' => 'local', 'secret' => 'local'] : null,
            ]));
        });

        $this->app->bind(BillingStoreInterface::class, fn ($app) => new DynamoDbBillingStore(
            $app->make(DynamoDbClient::class),
            (string) config('billing.dynamodb.table'),
        ));

        $this->app->bind(PaymentGateway::class, fn () => match (config('billing.payments.driver')) {
            'mercadopago' => new MercadoPagoGateway(
                (string) config('billing.mercadopago.base_url'),
                (string) config('billing.mercadopago.access_token'),
                (bool) config('billing.mercadopago.sandbox'),
                (int) config('billing.mercadopago.timeout'),
            ),
            default => new FakePaymentGateway,
        });

        $this->app->bind(CustomerNotifier::class, MailCustomerNotifier::class);

        $this->app->bind(MessagePublisher::class, fn () => match (config('messaging.publisher')) {
            'sns' => new SnsMessagePublisher(
                new SnsClient(['region' => config('messaging.region'), 'version' => '2010-03-31']),
                (string) config('messaging.sns.topic_arn'),
            ),
            default => new LogMessagePublisher,
        });

        $this->app->bind(MessageSource::class, fn () => match (config('messaging.consumer')) {
            'sqs' => new SqsMessageSource(
                new SqsClient(['region' => config('messaging.region'), 'version' => '2012-11-05']),
                (string) config('messaging.sqs.queue_url'),
                (int) config('messaging.sqs.wait_seconds'),
                (int) config('messaging.sqs.max_messages'),
            ),
            default => new NullMessageSource,
        });
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                ConsumeMessagesCommand::class,
                RelayOutboxCommand::class,
                CreateTableCommand::class,
            ]);
        }
    }
}
