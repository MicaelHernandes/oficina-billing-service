<?php

namespace Domain\Billing\Domain\Messages;

use Domain\Billing\Domain\Exceptions\DomainException;
use Illuminate\Support\Str;

/**
 * Envelope comum a todas as mensagens entre microsserviços.
 *
 * - `id`: único por mensagem; o consumidor usa para idempotência (inbox).
 * - `saga_id`: instância da saga da OS.
 * - `correlation_id`: X-Request-Id da requisição que iniciou a saga; segue em
 *   todas as mensagens e logs para rastrear o fluxo distribuído no Loki.
 */
final class Envelope
{
    /** @param array<string, mixed> $payload */
    public function __construct(
        public readonly string $id,
        public readonly string $type,
        public readonly ?string $sagaId,
        public readonly ?string $correlationId,
        public readonly ?int $osId,
        public readonly string $source,
        public readonly \DateTimeImmutable $occurredAt,
        public readonly array $payload = [],
    ) {}

    /** @param array<string, mixed> $payload */
    public static function new(
        MessageType $type,
        ?string $sagaId,
        ?string $correlationId,
        ?int $osId,
        string $source,
        array $payload = [],
    ): self {
        return new self(
            id: (string) Str::uuid(),
            type: $type->value,
            sagaId: $sagaId,
            correlationId: $correlationId,
            osId: $osId,
            source: $source,
            occurredAt: new \DateTimeImmutable,
            payload: $payload,
        );
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        foreach (['id', 'type', 'source'] as $field) {
            if (! isset($data[$field]) || ! is_string($data[$field]) || $data[$field] === '') {
                throw DomainException::because("Mensagem inválida: campo '{$field}' ausente.");
            }
        }

        return new self(
            id: $data['id'],
            type: $data['type'],
            sagaId: $data['saga_id'] ?? null,
            correlationId: $data['correlation_id'] ?? null,
            osId: isset($data['os_id']) ? (int) $data['os_id'] : null,
            source: $data['source'],
            occurredAt: isset($data['occurred_at'])
                ? new \DateTimeImmutable($data['occurred_at'])
                : new \DateTimeImmutable,
            payload: is_array($data['payload'] ?? null) ? $data['payload'] : [],
        );
    }

    /**
     * Aceita o corpo cru (raw message delivery) ou a notificação SNS que
     * embrulha o envelope no campo `Message`.
     */
    public static function fromJson(string $json): self
    {
        $data = json_decode($json, true);

        if (! is_array($data)) {
            throw DomainException::because('Mensagem inválida: corpo não é JSON.');
        }

        if (($data['Type'] ?? null) === 'Notification' && isset($data['Message'])) {
            return self::fromJson((string) $data['Message']);
        }

        return self::fromArray($data);
    }

    public function messageType(): ?MessageType
    {
        return MessageType::tryFrom($this->type);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'saga_id' => $this->sagaId,
            'correlation_id' => $this->correlationId,
            'os_id' => $this->osId,
            'source' => $this->source,
            'occurred_at' => $this->occurredAt->format(DATE_ATOM),
            'payload' => $this->payload,
        ];
    }

    public function toJson(): string
    {
        return json_encode($this->toArray(), JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION | JSON_UNESCAPED_UNICODE);
    }

    /** @return array<string, string> */
    public function logContext(): array
    {
        return array_filter([
            'message_id' => $this->id,
            'message_type' => $this->type,
            'saga_id' => $this->sagaId,
            'correlation_id' => $this->correlationId,
            'os_id' => $this->osId !== null ? (string) $this->osId : null,
        ], fn ($v) => $v !== null);
    }
}
