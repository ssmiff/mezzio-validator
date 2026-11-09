<?php

declare(strict_types=1);

namespace Ssmiff\MezzioValidator\Test\Fixture;

use Laminas\Filter\ToInt;
use Override;
use Psr\Http\Message\ServerRequestInterface;
use Ssmiff\MezzioValidator\AbstractRequestValidator;

/**
 * Validates a route parameter alongside the body, as for PATCH /users/{id}.
 *
 * @extends AbstractRequestValidator<RenameUser>
 */
final class RenameUserValidator extends AbstractRequestValidator
{
    #[Override]
    protected function rules(): array
    {
        return [
            'id'   => ['name' => 'id', 'required' => true, 'filters' => [['name' => ToInt::class]]],
            'name' => ['name' => 'name', 'required' => true],
        ];
    }

    #[Override]
    protected function map(array $values): RenameUser
    {
        return new RenameUser((int) $values['id'], (string) $values['name']);
    }

    #[Override]
    protected function data(ServerRequestInterface $request): array
    {
        return [...parent::data($request), 'id' => $request->getAttribute('id')];
    }
}
