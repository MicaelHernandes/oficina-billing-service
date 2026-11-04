<?php

namespace Domain\Billing\Domain\Exceptions;

class NotFoundException extends DomainException
{
    public static function forEntity(string $entity, string|int $id): static
    {
        return new static("{$entity} '{$id}' não encontrado.");
    }
}
