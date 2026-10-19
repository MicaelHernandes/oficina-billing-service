<?php

namespace Domain\Billing\Infrastructure\Messaging;

use Aws\Sqs\SqsClient;
use Domain\Billing\Domain\Ports\MessageSource;
use Domain\Billing\Domain\Ports\ReceivedMessage;

/** Fila SQS do Billing Service (long polling). */
class SqsMessageSource implements MessageSource
{
    public function __construct(
        private readonly SqsClient $client,
        private readonly string $queueUrl,
        private readonly int $waitSeconds = 20,
        private readonly int $maxMessages = 10,
    ) {}

    public function receive(): array
    {
        $result = $this->client->receiveMessage([
            'QueueUrl' => $this->queueUrl,
            'MaxNumberOfMessages' => $this->maxMessages,
            'WaitTimeSeconds' => $this->waitSeconds,
        ]);

        return array_map(
            fn (array $m) => new ReceivedMessage(body: $m['Body'], receipt: $m['ReceiptHandle']),
            $result->get('Messages') ?? [],
        );
    }

    public function ack(ReceivedMessage $message): void
    {
        $this->client->deleteMessage([
            'QueueUrl' => $this->queueUrl,
            'ReceiptHandle' => $message->receipt,
        ]);
    }
}
