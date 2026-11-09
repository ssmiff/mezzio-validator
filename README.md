# ssmiff/mezzio-validator

Request validation for [Mezzio](https://docs.mezzio.dev/), built on [laminas-inputfilter](https://docs.laminas.dev/laminas-inputfilter/).

You write one small class per request. It declares the **rules** for each field and **maps** the clean values onto an object of
your own, usually a readonly DTO. Your handler asks for that validator, calls `validate($request)`, and gets back either a fully
typed object or a `ValidationFailed` exception that a middleware turns into a `422` JSON response.

```php
$note = $this->validator->validate($request); // a CreateNote, or a 422 for the client
```

If you have used Laravel, it is the same idea as a `FormRequest`: `rules()` are the rules, `data()` is `validationData()`, and
`map()` is what you would do with `validated()`. See [Coming from Laravel](#coming-from-laravel).

Requires PHP 8.5. Licensed under the [MIT licence](LICENSE).

- [Install](#install)
- [Quick start](#quick-start)
- [How a request is validated](#how-a-request-is-validated)
- [Writing rules](#writing-rules)
- [Mapping values with `map()`](#mapping-values-with-map)
- [Where the data comes from: `data()`](#where-the-data-comes-from-data)
- [Injecting a validator](#injecting-a-validator)
- [Error responses](#error-responses)
- [Validators with dependencies](#validators-with-dependencies)
- [Testing a validator](#testing-a-validator)
- [Coming from Laravel](#coming-from-laravel)
- [Limitations](#limitations)
- [Reference](#reference)
- [Development](#development)

## Install

```
composer require ssmiff/mezzio-validator
```

`laminas-component-installer` registers `Ssmiff\MezzioValidator\ConfigProvider` for you, along with the providers of the packages it
depends on: `laminas-filter`, `laminas-validator` and `laminas-inputfilter`.

This package also depends on [ssmiff/mezzio-container-helpers](https://github.com/ssmiff/mezzio-container-helpers), which provides
`#[UseValidator]`'s plumbing (`AttributeFactory`). Its `ConfigProvider` must be registered too; the component installer does that as well.
Without the installer, add all of them to `config/config.php` yourself.

## Quick start

Three pieces: a DTO, a validator, and a handler that uses it.

**1. The DTO** is whatever you want your handler to receive. Nothing in it is specific to this package.

```php
final readonly class CreateUser
{
    public function __construct(
        public string $email,
        public string $name,
        public ?int $age,
    ) {}
}
```

**2. The validator** extends `AbstractRequestValidator`, names the DTO in `@extends`, and implements two methods:

```php
use Laminas\Filter\StringTrim;
use Laminas\Validator\Digits;
use Laminas\Validator\EmailAddress;
use Laminas\Validator\StringLength;
use Ssmiff\MezzioValidator\AbstractRequestValidator;

/** @extends AbstractRequestValidator<CreateUser> */
final class CreateUserValidator extends AbstractRequestValidator
{
    protected function rules(): array
    {
        return [
            'email' => [
                'required'   => true,
                'filters'    => [['name' => StringTrim::class]],
                'validators' => [['name' => EmailAddress::class]],
            ],
            'name'  => [
                'required'   => true,
                'filters'    => [['name' => StringTrim::class]],
                'validators' => [['name' => StringLength::class, 'options' => ['min' => 2, 'max' => 100]]],
            ],
            'age'   => [
                'required'   => false,
                'validators' => [['name' => Digits::class]],   // '42' passes, 'abc' and '4.2' fail
            ],
        ];
    }

    protected function map(array $values): CreateUser
    {
        return new CreateUser(
            email: (string) $values['email'],
            name:  (string) $values['name'],
            age:   $values['age'] === null || $values['age'] === '' ? null : (int) $values['age'],
        );
    }
}
```

There is nothing to register: every concrete validator is built automatically (see [Validators with dependencies](#validators-with-dependencies)).

**3. The handler** asks for the validator with `#[UseValidator]` and is built by `AttributeFactory`:

```php
use Ssmiff\MezzioValidator\Attribute\UseValidator;
use Ssmiff\MezzioValidator\RequestValidator;

final readonly class CreateUserHandler implements RequestHandlerInterface
{
    /** @param RequestValidator<CreateUser> $validator */
    public function __construct(
        #[UseValidator(CreateUserValidator::class)] private RequestValidator $validator,
        private UserRepository $users,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $user = $this->validator->validate($request); // CreateUser, fully typed

        $this->users->add($user);

        return new JsonResponse(['email' => $user->email], 201);
    }
}
```

```php
// config/autoload/dependencies.global.php
'dependencies' => ['factories' => [CreateUserHandler::class => AttributeFactory::class]],
```

**4. Turn failures into `422` responses** by piping the middleware once, in `config/pipeline.php`:

```php
$app->pipe(ServerUrlMiddleware::class);
$app->pipe(\Ssmiff\MezzioValidator\Http\ValidationFailedMiddleware::class);
```

A bad request now gets:

```http
HTTP/1.1 422 Unprocessable Entity
Content-Type: application/json

{"errors":{"email":{"emailAddressInvalidFormat":"The input is not a valid email address. Use the basic format local-part@hostname"}}}
```

## How a request is validated

`validate(ServerRequestInterface $request)` does the same five steps every time:

1. **Build a fresh input filter.** A new `Laminas\InputFilter\InputFilter` comes from the `InputFilterPluginManager` on every call, so
   one validator instance can be shared safely and nothing leaks from one request to the next.
2. **Add your rules.** Each entry of `rules()` is added as an input named after its key.
3. **Collect the data** by calling `data($request)`. By default this is the query string merged with the parsed body
   (see [`data()`](#where-the-data-comes-from-data)).
4. **Filter and validate.** laminas-inputfilter runs each field's filters, then its validators. Fields without a rule are ignored.
5. **Fail or map.**
   - If any field failed, it throws `ValidationFailed` with the messages of **every** failing field, not just the first.
   - Otherwise it passes the filtered values to `map()` and returns whatever `map()` returns.

`validate()` is `final`. You customise it through three `protected` methods:

| Method | Required? | What you use it for |
| --- | --- | --- |
| `rules(): array` | yes | Which fields exist, how they are cleaned and what makes them valid. |
| `map(array $values): object` | yes | Turning the clean values into your own object. Also the place for checks across fields. |
| `data(ServerRequestInterface $request): array` | no | Choosing where the values come from: query string, body, route parameters, uploaded files, headers... |

## Writing rules

`rules()` returns an array keyed by field name. Each value is a
[laminas-inputfilter input specification](https://docs.laminas.dev/laminas-inputfilter/specs/) without the `name` key; the key of the
array is used as the name.

```php
protected function rules(): array
{
    return [
        'field' => [
            'required'    => true,              // must be present and non-empty (default: true)
            'allow_empty' => false,             // let '' through for a required field
            'fallback_value' => null,           // value used when an optional field is missing or empty
            'filters'     => [/* run first, in order */],
            'validators'  => [/* run on the filtered value, in order */],
        ],
    ];
}
```

### The keys you will use

| Key | Default | Meaning |
| --- | --- | --- |
| `required` | `true` | The field must be present and not empty. A missing or empty required field fails with `__inputEmptyValueFailure`. |
| `allow_empty` | `false` | Lets a required field be empty: `''` passes as `''`, and `null` or a missing field pass as `null`. In practice, this makes the field optional. |
| `continue_if_empty` | `false` | Runs the validators even when the value is empty, for validators that decide for themselves what empty means. |
| `fallback_value` | none | For an optional field: the value `map()` receives when the field is missing, `null` or `''`. |
| `filters` | `[]` | A list of `['name' => FilterClass::class, 'options' => [...]]`. They transform the value before it is validated. |
| `validators` | `[]` | A list of `['name' => ValidatorClass::class, 'options' => [...]]`. All of them run, and every failure is reported. |
| `type` | `Input` | `Laminas\InputFilter\FileInput` for an uploaded file (see [Uploaded files](#uploaded-files)). |

Filters come from [laminas-filter](https://docs.laminas.dev/laminas-filter/) and validators from
[laminas-validator](https://docs.laminas.dev/laminas-validator/). Any filter or validator registered in their plugin managers can
be used by class name, including your own.

### Filters run before validators

This is the part that surprises people. Validators see the value **after** the filters, so a converting filter can hide bad input
from them. `ToInt` turns `'abc'` into `0`, and `Digits` then happily accepts `0`:

```php
// Accepts 'abc' as an age of 0.
'age' => ['filters' => [['name' => ToInt::class]], 'validators' => [['name' => Digits::class]]],

// Rejects 'abc'; map() does the conversion.
'age' => ['validators' => [['name' => Digits::class]]],
```

Use filters to **clean** input (`StringTrim`, `StringToLower`, `StripTags`, ...) and validators to **check** it, and convert to `int`,
`float`, `bool` or dates in `map()` once the raw value has been checked.

### Stopping a chain early

By default every validator of a field runs and every failure is reported. To stop at the first failure, for example so a non-numeric
value does not also get a "too short" message, set `break_chain_on_failure` on that validator:

```php
'code' => [
    'validators' => [
        ['name' => Digits::class, 'break_chain_on_failure' => true],
        ['name' => StringLength::class, 'options' => ['min' => 5]],
    ],
],
```

### Custom messages

Every laminas validator accepts a `messages` option, keyed by its failure code. `%min%`, `%max%` and the like are replaced with the
validator's options:

```php
'title' => [
    'validators' => [
        [
            'name'                   => NotEmpty::class,
            'break_chain_on_failure' => true,
            'options'                => ['messages' => [NotEmpty::IS_EMPTY => 'Give it a title.']],
        ],
        [
            'name'    => StringLength::class,
            'options' => ['min' => 3, 'messages' => [StringLength::TOO_SHORT => 'At least %min% characters.']],
        ],
    ],
],
```

Putting `NotEmpty` first is also how you replace the generic `__inputEmptyValueFailure` message of a required field with your own.

## Mapping values with `map()`

`map()` is where the untyped request becomes your typed object. It runs only after **every** rule has passed.

```php
/**
 * @param array<array-key, mixed> $values the filtered values of every field that has a rule
 * @return T
 */
abstract protected function map(array $values): object;
```

### What `$values` contains

`$values` has **exactly one key per entry in `rules()`**, every time, whether or not the client sent that field. Fields the client
sent that have no rule are not in it, so a client can never smuggle extra fields into your object.

| The client... | `$values['field']` is |
| --- | --- |
| sent a value | the value **after** the field's filters |
| sent `''` to an optional field | `''` (after filters), or the `fallback_value` if it has one |
| sent `null` to an optional field | `null`, or the `fallback_value` |
| did not send an optional field | `null`, or the `fallback_value` |
| sent a field that has no rule | not present at all |

A required field is never missing, `null` or empty in `map()`, because validation would already have failed. The exception is
`allow_empty`, which lets it through as `''` or `null`.

### Typing values

The values are `mixed` as far as PHP and Psalm know: the request is untrusted, and only your rules say what is in it. Cast or narrow
each one as you build your object. Your rules have already guaranteed the cast is safe:

```php
protected function map(array $values): CreateUser
{
    return new CreateUser(
        email: (string) $values['email'],                                   // required: always a string
        name:  (string) $values['name'],
        age:   $values['age'] === null || $values['age'] === '' ? null : (int) $values['age'], // optional
    );
}
```

**Optional fields can always be `null`, or `''` when the client sends an empty value.** Passing `$values['age']` straight into an `int`
parameter works in your tests and then throws a `TypeError` on the first request without `age`. Either handle both in `map()` and
accept `?int` in your DTO, or give the field a `fallback_value`, which replaces both `null` and `''`.

Narrow the return type to your DTO (`map(array $values): CreateUser`) and put it in the class's `@extends` tag, so callers of
`validate()` get `CreateUser` and not `object`.

### Checks across fields

`rules()` checks one field at a time. Anything that needs two or more fields, such as "the end date is after the start date" or
"the passwords match", belongs in `map()`. Throw `ValidationFailed` yourself, keyed by field and failure code like any other error,
and the client gets the same `422` response:

```php
protected function map(array $values): ChangePassword
{
    if ($values['password'] !== $values['password_confirmation']) {
        throw new ValidationFailed([
            'password_confirmation' => ['notSame' => 'The passwords do not match.'],
        ]);
    }

    return new ChangePassword((string) $values['password']);
}
```

```json
{"errors":{"password_confirmation":{"notSame":"The passwords do not match."}}}
```

Any other exception thrown from `map()` passes through untouched, so a bug in `map()` is still a `500`, not a `422`.

### Building more than a DTO

`map()` can return any object: a command for a bus, a value object, an entity you build from scratch... Keep it free of side effects,
though: no saving, no sending. It is about turning input into an object, and the handler decides what to do with that object.

## Where the data comes from: `data()`

### The default

`data()` returns the **query string merged with the parsed body**, and **the body wins** when both have the same key:

```php
protected function data(ServerRequestInterface $request): array
{
    $body = $request->getParsedBody();

    return array_replace($request->getQueryParams(), is_array($body) ? $body : []);
}
```

| Request | Data |
| --- | --- |
| `GET /users?page=2` | `['page' => '2']` |
| `POST /users` with a form or JSON body | the body |
| `POST /users?draft=1` with body `{"name": "Ann"}` | `['draft' => '1', 'name' => 'Ann']` |
| `POST /users?name=Query` with body `{"name": "Body"}` | `['name' => 'Body']` |

This works for every method without configuration. Mezzio gives a `GET` request an empty array as its parsed body (Diactoros copies
`$_POST`), so the query string fills it. A JSON body is parsed only once
[`BodyParamsMiddleware`](https://docs.mezzio.dev/mezzio/v3/features/helpers/body-parse/) is in your pipeline; without it, a JSON
request's parsed body is empty and only the query string is validated. A parsed body that is an object rather than an array is ignored.

The merge is shallow: a key in the body replaces the same key from the query string completely, even when both are arrays.

### Overriding it

`data()` is `protected` and not `final`. Override it in any validator that needs different data. Start from `parent::data($request)`
when you want to keep the default and add to it.

**Route parameters.** Mezzio's router stores route parameters as request attributes, so `PATCH /users/{id}` can validate `id` like any
other field. Put it **last**, so a body field of the same name cannot override the URL:

```php
protected function data(ServerRequestInterface $request): array
{
    return [...parent::data($request), 'id' => $request->getAttribute('id')];
}
```

Ask for attributes by name. `getAttributes()` also returns everything your middleware stored on the request, such as the
`RouteResult`, the authenticated user or the tenant.

**Query string only**, so a body can never affect a search:

```php
protected function data(ServerRequestInterface $request): array
{
    return $request->getQueryParams();
}
```

**Body only**, ignoring the query string (the behaviour of many APIs for `POST`, `PUT` and `PATCH`):

```php
protected function data(ServerRequestInterface $request): array
{
    $body = $request->getParsedBody();

    return is_array($body) ? $body : [];
}
```

**Headers or other request details**, as plain fields:

```php
protected function data(ServerRequestInterface $request): array
{
    return [...parent::data($request), 'idempotency_key' => $request->getHeaderLine('Idempotency-Key')];
}
```

**Different sources per method**, if a single validator serves several routes:

```php
protected function data(ServerRequestInterface $request): array
{
    return match ($request->getMethod()) {
        'GET', 'HEAD', 'DELETE' => $request->getQueryParams(),
        default                 => parent::data($request),
    };
}
```

Whatever `data()` returns is only the input: it still goes through `rules()`, and only fields with a rule reach `map()`. Adding a source
to `data()` therefore never lets unexpected fields through.

### Uploaded files

PSR-7 keeps uploaded files apart from the body, in `getUploadedFiles()`. Merge them in and give the field a `FileInput`; `map()` then
receives the `Psr\Http\Message\UploadedFileInterface`:

```php
use Laminas\InputFilter\FileInput;
use Laminas\Validator\File\UploadFile;

protected function rules(): array
{
    return [
        'name'   => ['required' => true],
        'avatar' => ['type' => FileInput::class, 'required' => true, 'validators' => [['name' => UploadFile::class]]],
    ];
}

protected function data(ServerRequestInterface $request): array
{
    return [...parent::data($request), ...$request->getUploadedFiles()];
}

protected function map(array $values): UploadAvatar
{
    assert($values['avatar'] instanceof UploadedFileInterface);

    return new UploadAvatar((string) $values['name'], $values['avatar']);
}
```

`UploadFile` fails when the upload itself failed: too large, partial, missing... laminas-validator's other `File\` validators (`Size`,
`MimeType`, `Extension`, `IsImage`, ...) add the usual checks.

## Injecting a validator

### With `#[UseValidator]`

`#[UseValidator(SomeValidator::class)]` on a constructor parameter tells `AttributeFactory` (from mezzio-container-helpers) to inject
that validator. Type the parameter as the `RequestValidator` interface, and add the DTO in a `@param` tag so that `validate()` is typed:

```php
/** @param RequestValidator<CreateUser> $validator */
public function __construct(
    #[UseValidator(CreateUserValidator::class)] private RequestValidator $validator,
) {}
```

The class being built (usually a handler) must be built by `AttributeFactory`:

```php
'dependencies' => ['factories' => [CreateUserHandler::class => AttributeFactory::class]],
```

Typing against `RequestValidator` rather than `CreateUserValidator` keeps the handler easy to test: any `RequestValidator<CreateUser>`
will do, including a stub.

### Without the attribute

Every validator is an ordinary container service, so you can also type-hint the concrete class, or fetch it from the container
in your own factory:

```php
public function __construct(private CreateUserValidator $validator) {}
```

```php
$validator = $container->get(CreateUserValidator::class);
```

## Error responses

### `ValidationFailed`

`validate()` throws `Ssmiff\MezzioValidator\Exception\ValidationFailed`, a `RuntimeException`. `getMessages()` returns the messages of
every failing field, keyed by field, then by the validator's failure code. Fields that passed are not included:

```php
[
    'email' => ['emailAddressInvalidFormat' => 'The input is not a valid email address. ...'],
    'name'  => ['stringLengthTooShort' => 'The input is less than 2 characters long'],
]
```

The failure codes are stable and safe for a client to switch on. The texts can be changed (see [Custom messages](#custom-messages)).

You can catch it in a handler when a failure should do something other than a `422`, such as re-rendering a form with the messages:

```php
try {
    $user = $this->validator->validate($request);
} catch (ValidationFailed $e) {
    return new HtmlResponse($this->templates->render('user::create', ['errors' => $e->getMessages()]), 422);
}
```

### `ValidationFailedMiddleware`

For an API you rarely need to catch it. Pipe `Ssmiff\MezzioValidator\Http\ValidationFailedMiddleware` once, before routing, and any
`ValidationFailed` thrown by a later middleware or handler becomes:

- status `422 Unprocessable Entity`
- header `Content-Type: application/json`
- body `{"errors": <getMessages()>}`

Every other response and every other exception passes through untouched.

```php
// config/pipeline.php
$app->pipe(ErrorHandler::class);
$app->pipe(ServerUrlMiddleware::class);
$app->pipe(ValidationFailedMiddleware::class);
// ... routing, dispatch
```

It needs a PSR-17 `Psr\Http\Message\ResponseFactoryInterface` in the container, which `laminas-diactoros` provides. To change the
format, for example to [RFC 9457 problem details](https://www.rfc-editor.org/rfc/rfc9457), write your own middleware that catches
`ValidationFailed` and pipe it instead.

## Validators with dependencies

Every concrete class implementing `RequestValidator` that has no factory of its own is built by `RequestValidatorAbstractFactory`,
which uses `AttributeFactory`: **constructor parameters are injected by type** from the container, and `#[Use...]` attributes on them
work as usual. A validator can therefore depend on other services without any registration, as long as it passes the
`InputFilterPluginManager` on to the parent:

```php
/** @extends AbstractRequestValidator<RegisterUser> */
final class RegisterUserValidator extends AbstractRequestValidator
{
    public function __construct(InputFilterPluginManager $inputFilters, private UserRepository $users)
    {
        parent::__construct($inputFilters);
    }

    protected function rules(): array
    {
        return ['email' => ['required' => true, 'validators' => [['name' => EmailAddress::class]]]];
    }

    protected function map(array $values): RegisterUser
    {
        $email = (string) $values['email'];

        if ($this->users->emailExists($email)) {
            throw new ValidationFailed(['email' => ['emailTaken' => 'That email address is already registered.']]);
        }

        return new RegisterUser($email);
    }
}
```

Register a factory of your own only when a dependency cannot be found by type, such as a scalar setting from config. Validators are
shared services like any other; that is safe because `validate()` builds a fresh input filter each time and keeps no state.

## Testing a validator

A validator only needs an `InputFilterPluginManager` and a PSR-7 request, so it can be tested without the application:

```php
final class CreateUserValidatorTest extends TestCase
{
    private CreateUserValidator $validator;

    protected function setUp(): void
    {
        $dependencies = [];
        foreach ([
            new \Laminas\Filter\ConfigProvider(),
            new \Laminas\Validator\ConfigProvider(),
            new \Laminas\InputFilter\ConfigProvider(),
        ] as $provider) {
            $dependencies = array_replace_recursive($dependencies, $provider()['dependencies']);
        }

        $container       = new ServiceManager($dependencies);
        $this->validator = new CreateUserValidator($container->get(InputFilterPluginManager::class));
    }

    public function testMapsAValidBody(): void
    {
        $user = $this->validator->validate(
            new ServerRequest()->withParsedBody(['email' => 'ann@example.com', 'name' => 'Ann']),
        );

        self::assertEquals(new CreateUser('ann@example.com', 'Ann', null), $user);
    }

    public function testRejectsABadEmail(): void
    {
        try {
            $this->validator->validate(new ServerRequest()->withParsedBody(['email' => 'nope', 'name' => 'Ann']));
            self::fail('Expected ValidationFailed.');
        } catch (ValidationFailed $e) {
            self::assertArrayHasKey('emailAddressInvalidFormat', $e->getMessages()['email']);
        }
    }
}
```

To test a GET validator the way it runs in an app, give the request an empty parsed body:
`new ServerRequest(method: 'GET')->withParsedBody([])->withQueryParams([...])`.

In a handler's tests, replace the validator with a stub `RequestValidator` that returns a DTO or throws `ValidationFailed`.

## Coming from Laravel

| Laravel `FormRequest` | This package |
| --- | --- |
| `rules()` | `rules()`, with laminas specs instead of rule strings |
| `validationData()`: `all()`, the body merged over the query string | `data()`: the parsed body merged over the query string |
| `prepareForValidation()` + `$this->merge([...])` | override `data()` and add the values |
| `$this->route('id')` | `$request->getAttribute('id')` in `data()` |
| `validated()` | the `$values` given to `map()` |
| `after()` / `withValidator()` for checks across fields | throw `ValidationFailed` from `map()` |
| `messages()` | the `messages` option of each validator |
| `authorize()` | not part of validation: use an authorization middleware |
| automatic `422` with `{"errors": ...}` | `ValidationFailedMiddleware` |
| type-hint the request in a controller | `#[UseValidator]` on a handler constructor parameter |

Two differences matter in practice:

- Laravel's errors are lists of texts per field. Here they are keyed by failure code: `{"email": {"emailAddressInvalidFormat": "..."}}`.
- Laravel's `required` rejects empty strings, and so does `'required' => true` here. Laravel's `nullable` is closest to `'required' => false`.
- Laravel's `integer` rule checks without converting. Here, check with a validator such as `Digits` and convert in `map()` (see
  [Filters run before validators](#filters-run-before-validators)).

## Limitations

- **Fields are flat and scalar.** Nested input filters (`'type' => InputFilter::class`) and `ArrayInput` do not work through `rules()`
  yet. For a list or nested object, give the field no validators of its own (`'required' => false` or `true`), then check its
  contents in `map()` and throw `ValidationFailed` with keys such as `'items.0.sku'`.
- **A JSON body needs `BodyParamsMiddleware`.** Without it, the parsed body is empty and only the query string is used.

## Reference

| Class | What it is |
| --- | --- |
| `Ssmiff\MezzioValidator\RequestValidator<T>` | The interface: `validate(ServerRequestInterface): T`, throws `ValidationFailed`. Type-hint this. |
| `Ssmiff\MezzioValidator\AbstractRequestValidator<T>` | The base class: implement `rules()` and `map()`, optionally override `data()`. |
| `Ssmiff\MezzioValidator\Attribute\UseValidator` | Constructor parameter attribute: `#[UseValidator(SomeValidator::class)]`. Needs `AttributeFactory`. |
| `Ssmiff\MezzioValidator\Exception\ValidationFailed` | Thrown on failure. `getMessages()`: `array<field, array<code, message>>`. |
| `Ssmiff\MezzioValidator\Http\ValidationFailedMiddleware` | Turns `ValidationFailed` into a `422` JSON response. |
| `Ssmiff\MezzioValidator\Factory\RequestValidatorAbstractFactory` | Builds every concrete `RequestValidator` without its own factory, wiring the constructor by type. |
| `Ssmiff\MezzioValidator\ConfigProvider` | Registers the middleware and the abstract factory. |

## Development

```
composer install
composer check   # php-cs-fixer, phpcs, Psalm and PHPUnit
```

Tagged versions are published to the internal Composer repository by the Forgejo release workflow.
