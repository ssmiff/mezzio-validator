<?php

declare(strict_types=1);

namespace Ssmiff\MezzioValidator\Test\Fixture;

final readonly class CreateUser
{
    public function __construct(
        public string $email,
        public string $name,
        public int $age,
    ) {}
}
