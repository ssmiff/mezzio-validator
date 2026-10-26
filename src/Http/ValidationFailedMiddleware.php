<?php

declare(strict_types=1);

namespace Ssmiff\MezzioValidator\Http;

use Override;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Ssmiff\MezzioValidator\Exception\ValidationFailed;

use function json_encode;

use const JSON_THROW_ON_ERROR;

/** Turns a ValidationFailed thrown by a handler into a 422 JSON response, so handlers need not catch it. */
final readonly class ValidationFailedMiddleware implements MiddlewareInterface
{
    public function __construct(private ResponseFactoryInterface $responses) {}

    #[Override]
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        try {
            return $handler->handle($request);
        } catch (ValidationFailed $e) {
            $response = $this->responses->createResponse(422)->withHeader('Content-Type', 'application/json');
            $response->getBody()->write(json_encode(['errors' => $e->getMessages()], JSON_THROW_ON_ERROR));

            return $response;
        }
    }
}
