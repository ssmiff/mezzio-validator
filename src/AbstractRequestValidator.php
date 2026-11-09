<?php

declare(strict_types=1);

namespace Ssmiff\MezzioValidator;

use Laminas\InputFilter\CollectionInputFilter;
use Laminas\InputFilter\InputFilter;
use Laminas\InputFilter\InputFilterInterface;
use Laminas\InputFilter\InputFilterPluginManager;
use Override;
use Psr\Http\Message\ServerRequestInterface;
use Ssmiff\MezzioValidator\Exception\ValidationFailed;

use function array_combine;
use function array_filter;
use function array_keys;
use function array_map;
use function array_replace;
use function is_a;
use function is_array;
use function is_string;

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
            // The name is an argument, not a 'name' key: every key of a nested input filter's spec is a field.
            $filter->add(self::nameChildren($spec), $field);
        }

        $result = $filter->validate($this->data($request));

        if (! $result->valid()) {
            throw new ValidationFailed(self::failuresOnly($result->getMessages()->toArray()));
        }

        return $this->map($result->value());
    }

    /**
     * The result lists every field, nested ones included, with no messages for those that passed: drop them, at
     * every level. Collection items keep their index, so a client can tell which item failed.
     *
     * @param array<array-key, mixed> $messages
     * @return array<array-key, mixed>
     */
    private static function failuresOnly(array $messages): array
    {
        $pruned = array_map(
            static fn(mixed $message): mixed => is_array($message) ? self::failuresOnly($message) : $message,
            $messages,
        );

        return array_filter($pruned, static fn(mixed $message): bool => $message !== []);
    }

    /**
     * Gives every input inside a nested input filter spec its key as its name.
     *
     * laminas reads the name of an input inside a nested input filter from its 'name' key only, so without this
     * 'address' => ['type' => InputFilter::class, 'zip' => [...]] fails. Inputs keep a 'name' they already have.
     *
     * @param array<array-key, mixed> $spec
     * @return array<array-key, mixed>
     */
    private static function nameChildren(array $spec): array
    {
        if (! self::isInputFilterSpec($spec)) {
            return $spec;
        }

        if (! self::isA($spec, CollectionInputFilter::class)) {
            return self::nameInputs($spec);
        }

        if (isset($spec['input_filter']) && is_array($spec['input_filter'])) {
            $spec['input_filter'] = self::nameInputs($spec['input_filter']);
        }

        return $spec;
    }

    /**
     * @param array<array-key, mixed> $spec an input filter spec: its keys are fields, apart from 'type'
     * @return array<array-key, mixed>
     */
    private static function nameInputs(array $spec): array
    {
        $keys = array_keys($spec);

        return array_combine($keys, array_map(
            static fn(int|string $key, mixed $child): mixed => match (true) {
                $key === 'type', ! is_array($child) => $child,
                self::isInputFilterSpec($child)     => self::nameChildren($child),
                default                             => ['name' => $key, ...$child],
            },
            $keys,
            $spec,
        ));
    }

    /** @param array<array-key, mixed> $spec */
    private static function isInputFilterSpec(array $spec): bool
    {
        return self::isA($spec, InputFilterInterface::class);
    }

    /**
     * @param array<array-key, mixed> $spec
     * @param class-string $class
     */
    private static function isA(array $spec, string $class): bool
    {
        return isset($spec['type']) && is_string($spec['type']) && is_a($spec['type'], $class, true);
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
