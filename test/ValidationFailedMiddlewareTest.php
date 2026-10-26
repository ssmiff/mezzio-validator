<?php

declare(strict_types=1);

namespace Ssmiff\MezzioValidator\Test;

use Laminas\Diactoros\Response\EmptyResponse;
use Laminas\Diactoros\ResponseFactory;
use Laminas\Diactoros\ServerRequest;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Ssmiff\MezzioValidator\Exception\ValidationFailed;
use Ssmiff\MezzioValidator\Http\ValidationFailedMiddleware;

use function json_decode;

use const JSON_THROW_ON_ERROR;

final class ValidationFailedMiddlewareTest extends TestCase
{
    public function testAValidationFailureBecomesA422JsonResponse(): void
    {
        $handler = new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                throw new ValidationFailed(['title' => ['isEmpty' => 'required']]);
            }
        };

        $response = new ValidationFailedMiddleware(new ResponseFactory())->process(new ServerRequest(), $handler);

        self::assertSame(422, $response->getStatusCode());
        self::assertSame('application/json', $response->getHeaderLine('Content-Type'));
        self::assertSame(
            ['errors' => ['title' => ['isEmpty' => 'required']]],
            json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR),
        );
    }

    public function testOtherResponsesPassThrough(): void
    {
        $handler = new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return new EmptyResponse(204);
            }
        };

        $response = new ValidationFailedMiddleware(new ResponseFactory())->process(new ServerRequest(), $handler);

        self::assertSame(204, $response->getStatusCode());
    }
}
