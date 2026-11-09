# ssmiff/mezzio-validator

Request validation for [Mezzio](https://docs.mezzio.dev/), as a thin layer over
[laminas-inputfilter](https://docs.laminas.dev/laminas-inputfilter/).

You write one class per request: its `rules()` are a laminas input filter specification, and its `map()` turns the filtered values
into your own object. A handler gets the validator injected with `#[UseValidator]`, calls `validate($request)`, and receives that
object or a `ValidationFailed` exception, which a middleware turns into a `422` JSON response.

All filtering and validation is laminas-inputfilter's. This package only connects it to PSR-7 requests, your DTOs and the container.

Requires PHP 8.5. Licensed under the [MIT licence](LICENSE).

## Install

```
composer require ssmiff/mezzio-validator
```

`laminas-component-installer` registers `Ssmiff\MezzioValidator\ConfigProvider` and the providers it depends on: `laminas-filter`,
`laminas-validator`, `laminas-inputfilter` and [ssmiff/mezzio-container-helpers](https://github.com/ssmiff/mezzio-container-helpers).
Without the installer, add them to `config/config.php` yourself.

## Quick start

**A validator** extends `AbstractRequestValidator`, names the object it returns in `@extends`, and implements `rules()` and `map()`:

```php
use Laminas\Filter\StringTrim;
use Laminas\Validator\Digits;
use Laminas\Validator\EmailAddress;
use Ssmiff\MezzioValidator\AbstractRequestValidator;

/** @extends AbstractRequestValidator<CreateUser> */
final class CreateUserValidator extends AbstractRequestValidator
{
    protected function rules(): array
    {
        return [
            'email' => [
                'name'       => 'email',
                'required'   => true,
                'filters'    => [['name' => StringTrim::class]],
                'validators' => [['name' => EmailAddress::class]],
            ],
            'age'   => [
                'name'       => 'age',
                'required'   => false,
                'validators' => [['name' => Digits::class]],
            ],
        ];
    }

    protected function map(array $values): CreateUser
    {
        return new CreateUser(
            (string) $values['email'],
            $values['age'] === null || $values['age'] === '' ? null : (int) $values['age'],
        );
    }
}
```

**A handler** asks for it by class and is built by `AttributeFactory`:

```php
use Ssmiff\MezzioValidator\Attribute\UseValidator;
use Ssmiff\MezzioValidator\RequestValidator;

final readonly class CreateUserHandler implements RequestHandlerInterface
{
    /** @param RequestValidator<CreateUser> $validator */
    public function __construct(
        #[UseValidator(CreateUserValidator::class)] private RequestValidator $validator,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $user = $this->validator->validate($request); // a CreateUser

        // ...
    }
}
```

```php
'dependencies' => ['factories' => [CreateUserHandler::class => AttributeFactory::class]],
```

**The middleware** turns failures into `422` responses. Pipe it once, before routing, in `config/pipeline.php`:

```php
$app->pipe(\Ssmiff\MezzioValidator\Http\ValidationFailedMiddleware::class);
```

Validators need no registration: every concrete `RequestValidator` is built automatically.

## `rules()`

Return an input filter specification exactly as laminas-inputfilter's `Factory::createInputFilter()` accepts it. Nothing is added or
translated, so everything in the [laminas-inputfilter documentation](https://docs.laminas.dev/laminas-inputfilter/) applies as written:
input specs and their keys, filters and validators, nested `InputFilter` and `OptionalInputFilter`, `CollectionInputFilter`,
`FileInput`, and custom messages.

As laminas requires, every input spec has a `name`, nested ones included. A nested input filter is keyed by its field name:

```php
'address' => [
    'type'   => InputFilter::class,
    'street' => ['name' => 'street', 'required' => true],
    'zip'    => ['name' => 'zip', 'required' => true, 'validators' => [['name' => Digits::class]]],
],
```

A new input filter is built from `rules()` on every call, so one validator instance can be shared and keeps no state between requests.

## `map()`

`map()` runs only after every rule has passed. It receives laminas' filtered values: **one key per input in `rules()`**, at every
level, and nothing else, so fields the client sent without a rule never reach it.

Values are `mixed`; cast or narrow them as you build your object. An optional input the client did not send is `null` (or its
`fallback_value`), and an empty one is `''`, so handle both before casting:

```php
$values['age'] === null || $values['age'] === '' ? null : (int) $values['age']
```

Checks that need more than one field belong here. Throw `ValidationFailed` yourself, keyed by field and failure code, and the client
gets the same `422` as for a rule:

```php
if ($values['password'] !== $values['password_confirmation']) {
    throw new ValidationFailed(['password_confirmation' => ['notSame' => 'The passwords do not match.']]);
}
```

Any other exception passes through untouched.

## `data()`

`data()` returns the array the rules run against. By default it is the **query string merged with the parsed body**, the body
winning when both have the same key, like Laravel's `validationData()`. A parsed body that is not an array contributes nothing.

| Request | Data |
| --- | --- |
| `GET /users?page=2` | `['page' => '2']` |
| `POST /users` with a form or JSON body | the body |
| `POST /users?draft=1` with body `{"name": "Ann"}` | `['draft' => '1', 'name' => 'Ann']` |
| `POST /users?name=Query` with body `{"name": "Body"}` | `['name' => 'Body']` |

A JSON body is parsed only when [`BodyParamsMiddleware`](https://docs.mezzio.dev/mezzio/v3/features/helpers/body-parse/) is in
your pipeline.

Override `data()` to use other parts of the request. Build on `parent::data($request)` to keep the default:

```php
// Route parameters are request attributes. Add them last so the body cannot override the URL.
protected function data(ServerRequestInterface $request): array
{
    return [...parent::data($request), 'id' => $request->getAttribute('id')];
}
```

```php
// Uploaded files, for FileInput inputs. map() receives the UploadedFileInterface.
protected function data(ServerRequestInterface $request): array
{
    return [...parent::data($request), ...$request->getUploadedFiles()];
}
```

```php
// Only the query string, so a body can never affect it.
protected function data(ServerRequestInterface $request): array
{
    return $request->getQueryParams();
}
```

Whatever `data()` returns still goes through `rules()`, so adding a source never lets unexpected fields through to `map()`.

## Errors

`validate()` throws `Ssmiff\MezzioValidator\Exception\ValidationFailed`. `getMessages()` returns laminas' messages for the inputs
that failed, keyed by field and then by failure code, nested like the data. Inputs that passed are left out, and collection items
keep their index:

```php
[
    'email'   => ['emailAddressInvalidFormat' => 'The input is not a valid email address. ...'],
    'address' => ['zip' => ['notDigits' => 'The input must contain only digits']],
]
```

`Ssmiff\MezzioValidator\Http\ValidationFailedMiddleware` catches it from any later middleware or handler and responds with `422`,
`Content-Type: application/json` and `{"errors": <getMessages()>}`. Everything else passes through. It needs a PSR-17
`ResponseFactoryInterface` in the container, which `laminas-diactoros` provides. For a different format, catch `ValidationFailed` in a
middleware of your own instead.

## Validators with dependencies

Validators are built by `RequestValidatorAbstractFactory`, which wires constructor parameters by type, so they can depend on other
services without registration. Pass laminas-inputfilter's `Factory` on to the parent:

```php
public function __construct(Factory $factory, private UserRepository $users)
{
    parent::__construct($factory);
}
```

Register a factory of your own only for a dependency that cannot be found by type.

## Testing a validator

A validator needs only laminas-inputfilter's `Factory`, from a container with the laminas providers:

```php
$dependencies = [];
foreach ([new \Laminas\Filter\ConfigProvider(), new \Laminas\Validator\ConfigProvider(), new \Laminas\InputFilter\ConfigProvider()] as $provider) {
    $dependencies = array_replace_recursive($dependencies, $provider()['dependencies']);
}

$validator = new CreateUserValidator(new ServiceManager($dependencies)->get(\Laminas\InputFilter\Factory::class));
$user      = $validator->validate(new ServerRequest()->withParsedBody(['email' => 'ann@example.com']));
```

A GET request in a real app has an empty parsed body rather than `null`. To test one the same way, use
`new ServerRequest(method: 'GET')->withParsedBody([])->withQueryParams([...])`.

## Coming from Laravel

| Laravel `FormRequest` | This package |
| --- | --- |
| `rules()` | `rules()`, as a laminas input filter specification |
| `validationData()` | `data()` |
| `prepareForValidation()` with `merge()` | override `data()` |
| `validated()` | the `$values` passed to `map()` |
| `after()` | throw `ValidationFailed` from `map()` |
| automatic `422` | `ValidationFailedMiddleware` |

## Upgrading from 0.1

- `rules()` is now a laminas specification passed through unchanged: add `'name' => '<field>'` to every input spec, nested ones
  included.
- `AbstractRequestValidator`'s constructor takes `Laminas\InputFilter\Factory` instead of `InputFilterPluginManager`. Only validators
  with their own constructor need changing.

## Development

```
composer install
composer check   # php-cs-fixer, phpcs, Psalm and PHPUnit
```

Tagged versions are published to the internal Composer repository by the Forgejo release workflow.
