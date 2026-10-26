<?php

declare(strict_types=1);

namespace Ssmiff\MezzioValidator;

use Laminas\InputFilter\InputFilter;
use Laminas\InputFilter\InputFilterPluginManager;
use Override;
use Psr\Http\Message\ServerRequestInterface;
use Ssmiff\MezzioValidator\Exception\ValidationFailed;

use function is_array;

/**
 * @template-covariant T of object
 * @implements RequestValidator<T>
 */
abstract class AbstractRequestValidator implements RequestValidator
{
    public function __construct(private readonly InputFilterPluginManager $inputFilters) {}

    /** @return array<string, array> laminas input specs, keyed by field */
    abstract protected function rules(): array;

    /**
     * @param array<array-key, mixed> $values the filtered values of every field that has a rule
     * @return T
     */
    abstract protected function map(array $values): object;

    #[Override]
    final public function validate(ServerRequestInterface $request): object
    {
        $filter = $this->inputFilters->build(InputFilter::class);

        foreach ($this->rules() as $field => $spec) {
            /** @psalm-suppress MixedArgumentTypeCoercion specs are validated by the laminas input factory */
            $filter->add(['name' => $field, ...$spec]);
        }

        $filter->setData($this->data($request));

        if (! $filter->isValid()) {
            throw new ValidationFailed($filter->getMessages());
        }

        /** @var array<array-key, mixed> $values */
        $values = $filter->getValues();

        return $this->map($values);
    }

    /**
     * The parsed body, or the query string when the request has none.
     *
     * @return array<array-key, mixed>
     */
    protected function data(ServerRequestInterface $request): array
    {
        $body = $request->getParsedBody();

        return is_array($body) ? $body : $request->getQueryParams();
    }
}
