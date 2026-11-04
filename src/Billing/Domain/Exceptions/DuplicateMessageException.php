<?php

namespace Domain\Billing\Domain\Exceptions;

/** A mensagem já foi processada (inbox); o consumidor só confirma. */
class DuplicateMessageException extends DomainException {}
