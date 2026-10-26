<?php

declare(strict_types=1);

namespace Ssmiff\MezzioValidator\Attribute;

use Attribute;
use Override;
use Psr\Container\ContainerInterface;
use Ssmiff\MezzioContainerHelpers\Attribute\ParameterSource;
use Ssmiff\MezzioContainerHelpers\Services;
use Ssmiff\MezzioValidator\RequestValidator;

#[Attribute(Attribute::TARGET_PARAMETER)]
final readonly class UseValidator implements ParameterSource
{
    /** @param class-string<RequestValidator<object>> $class */
    public function __construct(private string $class) {}

    /** @return RequestValidator<object> */
    #[Override]
    public function resolve(ContainerInterface $container): RequestValidator
    {
        return Services::get($container, $this->class);
    }
}
