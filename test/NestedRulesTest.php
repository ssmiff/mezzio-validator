<?php

declare(strict_types=1);

namespace Ssmiff\MezzioValidator\Test;

use Laminas\Diactoros\ServerRequest;
use Laminas\InputFilter\InputFilterPluginManager;
use Laminas\ServiceManager\ServiceManager;
use Override;
use PHPUnit\Framework\TestCase;
use Ssmiff\MezzioValidator\Exception\ValidationFailed;
use Ssmiff\MezzioValidator\Test\Fixture\CreateOrder;
use Ssmiff\MezzioValidator\Test\Fixture\CreateOrderValidator;

use function array_keys;
use function array_replace_recursive;

final class NestedRulesTest extends TestCase
{
    private CreateOrderValidator $validator;

    #[Override]
    protected function setUp(): void
    {
        /** @var array<string, mixed> $dependencies */
        $dependencies = [];
        /** @psalm-suppress InternalClass laminas-inputfilter offers no other way to register its services in a test */
        foreach ([
            new \Laminas\Filter\ConfigProvider(),
            new \Laminas\Validator\ConfigProvider(),
            new \Laminas\InputFilter\ConfigProvider(),
        ] as $provider) {
            /** @var array{dependencies: array<string, mixed>} $config */
            $config       = $provider();
            $dependencies = array_replace_recursive($dependencies, $config['dependencies']);
        }

        /** @psalm-suppress MixedArgumentTypeCoercion the merged provider config is a plain dependencies array */
        $container = new ServiceManager($dependencies);

        $this->validator = new CreateOrderValidator($container->get(InputFilterPluginManager::class));
    }

    public function testMapsNestedFiltersAndCollectionsDroppingUnknownKeysAtEveryLevel(): void
    {
        $order = $this->validator->validate(new ServerRequest()->withParsedBody([
            'address' => ['street' => ' 1 Main St ', 'zip' => '12345', 'geo' => ['lat' => '51.5', 'x' => 1], 'y' => 2],
            'billing' => ['zip' => '54321'],
            'items'   => [['sku' => 'A', 'qty' => '2', 'z' => 3], ['sku' => 'B', 'qty' => '1']],
        ]));

        self::assertEquals(
            new CreateOrder(
                ['street' => '1 Main St', 'zip' => '12345', 'geo' => ['lat' => '51.5']],
                ['zip' => '54321'],
                [['sku' => 'A', 'qty' => '2'], ['sku' => 'B', 'qty' => '1']],
            ),
            $order,
        );
    }

    public function testAnOptionalInputFilterThatIsNotSentIsAnEmptyArray(): void
    {
        $order = $this->validator->validate(new ServerRequest()->withParsedBody([
            'address' => ['street' => 'S', 'zip' => '1', 'geo' => ['lat' => '0']],
            'items'   => [['sku' => 'A', 'qty' => '1']],
        ]));

        self::assertSame([], $order->billing);
    }

    public function testReportsOnlyTheFailingFieldsAtEveryLevel(): void
    {
        $messages = $this->failures([
            'address' => ['street' => 'S', 'zip' => 'nope', 'geo' => []],
            'billing' => ['zip' => 'x'],
            'items'   => [['sku' => 'A', 'qty' => '1']],
        ]);

        self::assertSame(['address', 'billing'], array_keys($messages));
        self::assertSame(['zip', 'geo'], array_keys((array) $messages['address']));
        self::assertSame(['lat'], array_keys((array) ((array) $messages['address'])['geo']));
        self::assertSame(['zip'], array_keys((array) $messages['billing']));
    }

    public function testCollectionFailuresAreKeyedByTheIndexOfTheFailingItem(): void
    {
        $messages = $this->failures([
            'address' => ['street' => 'S', 'zip' => '1', 'geo' => ['lat' => '0']],
            'items'   => [['sku' => 'A', 'qty' => '1'], ['sku' => 'B', 'qty' => '1'], ['qty' => 'x']],
        ]);

        self::assertSame(['items'], array_keys($messages));
        self::assertSame([2], array_keys((array) $messages['items']));
        self::assertSame(['sku', 'qty'], array_keys((array) ((array) $messages['items'])[2]));
    }

    /**
     * @param array<string, mixed> $body
     * @return array<array-key, mixed>
     */
    private function failures(array $body): array
    {
        try {
            $this->validator->validate(new ServerRequest()->withParsedBody($body));
        } catch (ValidationFailed $e) {
            return $e->getMessages();
        }

        self::fail('Expected ValidationFailed.');
    }
}
