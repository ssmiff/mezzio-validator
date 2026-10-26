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

        return new ServiceManager($dependencies);
    }
}
