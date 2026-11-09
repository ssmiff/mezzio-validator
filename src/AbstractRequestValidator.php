<?php

declare(strict_types=1);

namespace Ssmiff\MezzioValidator;

use Laminas\InputFilter\InputFilter;
use Laminas\InputFilter\InputFilterPluginManager;
use Override;
use Psr\Http\Message\ServerRequestInterface;
use Ssmiff\MezzioValidator\Exception\ValidationFailed;

use function array_filter;
use function array_replace;
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

        $result = $filter->validate($this->data($request));

        if (! $result->valid()) {
            // The result lists every field, with no messages for those that passed.
            throw new ValidationFailed(
                array_filter($result->getMessages()->toArray(), static fn($messages): bool => $messages !== []),
            );
        }

        return $this->map($result->value());
    }

    /**
     * The data the rules run against: the query string merged with the parsed body, the body winning when both
     * have the same key. A parsed body that is not an array (null, or an object) contributes nothing.
     *
     * Override it to read other sources, such as route parameters (request attributes) or uploaded files.
     *
     * @return array<array-key, mixed>
     */
    protected function data(ServerRequestInterface $request): array
    {
        $body = $request->getParsedBody();

        return array_replace($request->getQueryParams(), is_array($body) ? $body : []);
    }
}
