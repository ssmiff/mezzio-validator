<?php

declare(strict_types=1);

namespace Ssmiff\MezzioValidator\Factory;

use Laminas\ServiceManager\Factory\AbstractFactoryInterface;
use Override;
use Psr\Container\ContainerInterface;
use ReflectionClass;
use Ssmiff\MezzioContainerHelpers\Factory\AttributeFactory;
use Ssmiff\MezzioValidator\RequestValidator;

use function is_subclass_of;

/** Builds every concrete RequestValidator that has no factory of its own, wiring its constructor by type. */
final readonly class RequestValidatorAbstractFactory implements AbstractFactoryInterface
{
    #[Override]
    public function canCreate(ContainerInterface $container, $requestedName): bool
    {
        return is_subclass_of($requestedName, RequestValidator::class)
            && new ReflectionClass($requestedName)->isInstantiable();
    }

    #[Override]
    public function __invoke(ContainerInterface $container, $requestedName, ?array $options = null): object
    {
        return new AttributeFactory()($container, $requestedName);
    }
}
