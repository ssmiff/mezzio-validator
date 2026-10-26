<?php

declare(strict_types=1);

namespace Ssmiff\MezzioValidator;

use Laminas\ServiceManager\Factory\AbstractFactoryInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;
use Ssmiff\MezzioContainerHelpers\Factory\AttributeFactory;

final readonly class ConfigProvider
{
    /**
     * @return array{dependencies: array{
     *     factories: array<string, class-string<FactoryInterface>>,
     *     abstract_factories: list<class-string<AbstractFactoryInterface>>,
     * }}
     */
    public function __invoke(): array
    {
        return ['dependencies' => $this->dependencies()];
    }

    /**
     * @return array{
     *     factories: array<string, class-string<FactoryInterface>>,
     *     abstract_factories: list<class-string<AbstractFactoryInterface>>,
     * }
     */
    public function dependencies(): array
    {
        return [
            'factories'          => [Http\ValidationFailedMiddleware::class => AttributeFactory::class],
            'abstract_factories' => [Factory\RequestValidatorAbstractFactory::class],
        ];
    }
}
