<?php

declare(strict_types=1);

namespace Ssmiff\MezzioValidator\Test\Fixture;

use Ssmiff\MezzioValidator\Attribute\UseValidator;
use Ssmiff\MezzioValidator\RequestValidator;

final readonly class CreateUserHandler
{
    /** @param RequestValidator<CreateUser> $validator */
    public function __construct(
        #[UseValidator(CreateUserValidator::class)]
        public RequestValidator $validator,
    ) {}
}
