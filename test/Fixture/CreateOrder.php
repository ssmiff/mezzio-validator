<?php

declare(strict_types=1);

namespace Ssmiff\MezzioValidator\Test\Fixture;

/** @psalm-suppress PossiblyUnusedProperty the fixture's properties are read through the validator mapping under test */
final readonly class CreateOrder
{
    /**
     * @param array<array-key, mixed> $address
     * @param array<array-key, mixed> $billing
     * @param array<array-key, mixed> $items
     */
    public function __construct(
        public array $address,
        public array $billing,
        public array $items,
    ) {}
}
