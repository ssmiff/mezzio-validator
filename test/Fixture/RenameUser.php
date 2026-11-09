<?php

declare(strict_types=1);

namespace Ssmiff\MezzioValidator\Test\Fixture;

/** @psalm-suppress PossiblyUnusedProperty the fixture's properties are read through the validator mapping under test */
final readonly class RenameUser
{
    public function __construct(
        public int $id,
        public string $name,
    ) {}
}
