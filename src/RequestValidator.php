<?php

declare(strict_types=1);

namespace Ssmiff\MezzioValidator;

use Psr\Http\Message\ServerRequestInterface;
use Ssmiff\MezzioValidator\Exception\ValidationFailed;

/** @template-covariant T of object */
interface RequestValidator
{
    /**
     * @return T built from the filtered values of every field that has a rule
     * @throws ValidationFailed
     */
    public function validate(ServerRequestInterface $request): object;
}
