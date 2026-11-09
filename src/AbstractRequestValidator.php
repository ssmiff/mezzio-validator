<?php

declare(strict_types=1);

namespace Ssmiff\MezzioValidator;

use Laminas\InputFilter\Factory;
use Override;
use Psr\Http\Message\ServerRequestInterface;
use Ssmiff\MezzioValidator\Exception\ValidationFailed;

use function array_filter;
use function array_map;
use function array_replace;
use function is_array;

/**
 * @template-covariant T of object
 * @implements RequestValidator<T>
 */
abstract class AbstractRequestValidator implements RequestValidator
{
    public function __construct(private readonly Factory $factory) {}

    /**
     * The input filter specification, exactly as laminas-inputfilter's Factory::createInputFilter() takes it.
     *
     * @return array<array-key, mixed>
     */
    abstract protected function rules(): array;

    /**
     * @param array<array-key, mixed> $values the filtered values of every input in rules()
     * @return T
     */
    abstract protected function map(array $values): object;

    #[Override]
    final public function validate(ServerRequestInterface $request): object
    {
        $result = $this->factory->createInputFilter($this->rules())->validate($this->data($request));

        if (! $result->valid()) {
            throw new ValidationFailed(self::failuresOnly($result->getMessages()->toArray()));
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

    /**
     * laminas lists every input in its messages, with [] for those that passed, nested ones included.
     * Keep only the failures; collection items keep their index.
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
}
