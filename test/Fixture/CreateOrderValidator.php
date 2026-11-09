<?php

declare(strict_types=1);

namespace Ssmiff\MezzioValidator\Test\Fixture;

use Laminas\Filter\StringTrim;
use Laminas\InputFilter\CollectionInputFilter;
use Laminas\InputFilter\InputFilter;
use Laminas\InputFilter\OptionalInputFilter;
use Laminas\Validator\Digits;
use Override;
use Ssmiff\MezzioValidator\AbstractRequestValidator;

/** @extends AbstractRequestValidator<CreateOrder> */
final class CreateOrderValidator extends AbstractRequestValidator
{
    #[Override]
    protected function rules(): array
    {
        return [
            'address' => [
                'type'   => InputFilter::class,
                'street' => ['required' => true, 'filters' => [['name' => StringTrim::class]]],
                'zip'    => ['required' => true, 'validators' => [['name' => Digits::class]]],
                'geo'    => ['type' => InputFilter::class, 'lat' => ['required' => true]],
            ],
            'billing' => [
                'type' => OptionalInputFilter::class,
                'zip'  => ['required' => true, 'validators' => [['name' => Digits::class]]],
            ],
            'items'   => [
                'type'         => CollectionInputFilter::class,
                'required'     => true,
                'input_filter' => [
                    'sku' => ['required' => true],
                    'qty' => ['required' => true, 'validators' => [['name' => Digits::class]]],
                ],
            ],
        ];
    }

    #[Override]
    protected function map(array $values): CreateOrder
    {
        return new CreateOrder(
            (array) $values['address'],
            (array) $values['billing'],
            (array) $values['items'],
        );
    }
}
