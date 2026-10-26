<?php

declare(strict_types=1);

namespace Ssmiff\MezzioValidator\Test\Fixture;

use Laminas\Filter\ToInt;
use Laminas\Validator\EmailAddress;
use Laminas\Validator\StringLength;
use Override;
use Ssmiff\MezzioValidator\AbstractRequestValidator;

/** @extends AbstractRequestValidator<CreateUser> */
final class CreateUserValidator extends AbstractRequestValidator
{
    #[Override]
    protected function rules(): array
    {
        return [
            'email' => ['required' => true, 'validators' => [['name' => EmailAddress::class]]],
            'name'  => [
                'required'   => true,
                'validators' => [['name' => StringLength::class, 'options' => ['min' => 2]]],
            ],
            'age'   => ['required' => false, 'filters' => [['name' => ToInt::class]]],
        ];
    }

    #[Override]
    protected function map(array $values): CreateUser
    {
        return new CreateUser((string) $values['email'], (string) $values['name'], (int) $values['age']);
    }
}
