<?php

declare(strict_types=1);

namespace Ssmiff\MezzioValidator\Exception;

use RuntimeException;

final class ValidationFailed extends RuntimeException
{
    /** @param array<array-key, mixed> $messages error messages keyed by field, then by validator failure code */
    public function __construct(private readonly array $messages)
    {
        parent::__construct('The request failed validation.');
    }

    /** @return array<array-key, mixed> */
    public function getMessages(): array
    {
        return $this->messages;
    }
}
