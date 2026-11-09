<?php

declare(strict_types=1);

namespace Ssmiff\MezzioValidator\Test;

use Laminas\Diactoros\ServerRequest;
use Laminas\ServiceManager\ServiceManager;
use PHPUnit\Framework\TestCase;
use Ssmiff\MezzioContainerHelpers\ConfigProvider as HelpersConfigProvider;
use Ssmiff\MezzioContainerHelpers\Factory\AttributeFactory;
use Ssmiff\MezzioContainerHelpers\Services;
use Ssmiff\MezzioValidator\ConfigProvider;
use Ssmiff\MezzioValidator\Exception\ValidationFailed;
use Ssmiff\MezzioValidator\RequestValidator;
use Ssmiff\MezzioValidator\Test\Fixture\CreateUser;
use Ssmiff\MezzioValidator\Test\Fixture\CreateUserHandler;
use Ssmiff\MezzioValidator\Test\Fixture\CreateUserValidator;
use Ssmiff\MezzioValidator\Test\Fixture\RenameUser;
use Ssmiff\MezzioValidator\Test\Fixture\RenameUserValidator;

use function array_replace_recursive;

final class RequestValidatorTest extends TestCase
{
    public function testReturnsTheFilteredValuesOfAValidBody(): void
    {
        $user = $this->validator()->validate(
            $this->request(['email' => 'a@example.com', 'name' => 'Ann', 'age' => '42', 'extra' => 'dropped']),
        );

        self::assertEquals(new CreateUser('a@example.com', 'Ann', 42), $user);
    }

    public function testThrowsWithTheMessagesOfEveryFailingField(): void
    {
        try {
            $this->validator()->validate($this->request(['email' => 'nope', 'name' => 'A']));
            self::fail('Expected ValidationFailed.');
        } catch (ValidationFailed $e) {
            self::assertSame(['email', 'name'], array_keys($e->getMessages()));
        }
    }

    public function testAMissingRequiredFieldFails(): void
    {
        $this->expectException(ValidationFailed::class);

        $this->validator()->validate($this->request(['name' => 'Ann']));
    }

    public function testReadsTheQueryStringWhenThereIsNoParsedBody(): void
    {
        $request = new ServerRequest()->withQueryParams(['email' => 'a@example.com', 'name' => 'Ann']);

        self::assertSame('Ann', $this->validator()->validate($request)->name);
    }

    public function testReadsTheQueryStringWhenTheParsedBodyIsEmpty(): void
    {
        // A real GET request: Diactoros fills the parsed body from $_POST, so it is [] rather than null.
        $request = new ServerRequest(method: 'GET')
            ->withParsedBody([])
            ->withQueryParams(['email' => 'a@example.com', 'name' => 'Ann']);

        self::assertSame('Ann', $this->validator()->validate($request)->name);
    }

    public function testMergesTheQueryStringAndTheParsedBody(): void
    {
        $request = new ServerRequest()
            ->withQueryParams(['email' => 'a@example.com', 'age' => '7'])
            ->withParsedBody(['name' => 'Ann']);

        self::assertEquals(new CreateUser('a@example.com', 'Ann', 7), $this->validator()->validate($request));
    }

    public function testTheParsedBodyWinsOverTheQueryString(): void
    {
        $request = new ServerRequest()
            ->withQueryParams(['email' => 'query@example.com', 'name' => 'Query'])
            ->withParsedBody(['email' => 'body@example.com', 'name' => 'Body']);

        $user = $this->validator()->validate($request);

        self::assertSame('body@example.com', $user->email);
        self::assertSame('Body', $user->name);
    }

    public function testAnObjectParsedBodyContributesNothing(): void
    {
        $request = new ServerRequest()
            ->withQueryParams(['email' => 'a@example.com', 'name' => 'Ann'])
            ->withParsedBody((object) ['name' => 'Ignored']);

        self::assertSame('Ann', $this->validator()->validate($request)->name);
    }

    public function testDataCanBeOverriddenToAddRouteParameters(): void
    {
        $validator = Services::get($this->container(), RenameUserValidator::class);

        $request = new ServerRequest()->withAttribute('id', '12')->withParsedBody(['name' => 'Ann', 'id' => '99']);

        self::assertEquals(new RenameUser(12, 'Ann'), $validator->validate($request));
    }

    public function testEachValidationStartsFromAFreshFilter(): void
    {
        $validator = $this->validator();

        try {
            $validator->validate($this->request(['email' => 'nope', 'name' => 'Ann']));
        } catch (ValidationFailed) {
        }

        self::assertSame(
            'Bob',
            $validator->validate($this->request(['email' => 'b@example.com', 'name' => 'Bob']))->name,
        );
    }

    public function testUseValidatorInjectsTheNamedValidatorThroughAttributeFactory(): void
    {
        $handler = new AttributeFactory()($this->container(), CreateUserHandler::class);

        self::assertInstanceOf(CreateUserHandler::class, $handler);
        self::assertInstanceOf(CreateUserValidator::class, $handler->validator);
    }

    /** @return RequestValidator<CreateUser> */
    private function validator(): RequestValidator
    {
        return Services::get($this->container(), CreateUserValidator::class);
    }

    /** @param array<string, mixed> $body */
    private function request(array $body): ServerRequest
    {
        return new ServerRequest()->withParsedBody($body);
    }

    private function container(): ServiceManager
    {
        /** @var array<string, mixed> $dependencies */
        $dependencies = [];
        /** @psalm-suppress InternalClass laminas-inputfilter offers no other way to register its services in a test */
        foreach ([
            new HelpersConfigProvider(),
            new \Laminas\Filter\ConfigProvider(),
            new \Laminas\Validator\ConfigProvider(),
            new \Laminas\InputFilter\ConfigProvider(),
            new ConfigProvider(),
        ] as $provider) {
            /** @var array{dependencies: array<string, mixed>} $config */
            $config       = $provider();
            $dependencies = array_replace_recursive($dependencies, $config['dependencies']);
        }

        /** @psalm-suppress MixedArgumentTypeCoercion the merged provider config is a plain dependencies array */
        return new ServiceManager($dependencies);
    }
}
