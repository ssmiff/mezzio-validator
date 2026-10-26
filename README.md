# ssmiff/mezzio-validator

Request validation for Mezzio, built on `laminas-inputfilter`. A validator declares its rules, `validate()` returns the filtered values or throws.

Requires PHP 8.5. Licensed under the [MIT licence](LICENSE).

## Install

```
composer require ssmiff/mezzio-validator
```

`laminas-component-installer` registers `Ssmiff\MezzioValidator\ConfigProvider`, along with the `laminas-filter`, `laminas-validator` and `laminas-inputfilter` providers it depends on.
This package also depends on [ssmiff/mezzio-container-helpers](https://github.com/ssmiff/mezzio-container-helpers), whose provider must be registered too.

## Write a validator

Extend `AbstractRequestValidator`, return laminas input specs keyed by field from `rules()`, and build your own DTO from the filtered values in `map()`:

```php
/** @extends AbstractRequestValidator<CreateUser> */
final class CreateUserValidator extends AbstractRequestValidator
{
    protected function rules(): array
    {
        return [
            'email' => ['required' => true, 'validators' => [['name' => EmailAddress::class]]],
            'name'  => ['required' => true, 'validators' => [['name' => StringLength::class, 'options' => ['min' => 2]]]],
            'age'   => ['required' => false, 'filters' => [['name' => ToInt::class]]],
        ];
    }

    protected function map(array $values): CreateUser
    {
        return new CreateUser($values['email'], $values['name'], $values['age']);
    }
}
```

Every concrete `RequestValidator` is built by an abstract factory with its constructor wired by type, so there is nothing to register. Register your own factory
if a validator needs extra dependencies; its constructor must pass the `InputFilterPluginManager` to `parent::__construct()`.

## Use it

```php
/** @param RequestValidator<CreateUser> $validator */
public function __construct(
    #[UseValidator(CreateUserValidator::class)] private RequestValidator $validator,
) {}

public function handle(ServerRequestInterface $request): ResponseInterface
{
    $user = $this->validator->validate($request); // a CreateUser
}
```

`#[UseValidator]` is a `ParameterSource`, so register the handler with `AttributeFactory`:

```php
'dependencies' => ['factories' => [CreateUserHandler::class => AttributeFactory::class]],
```

`validate()` reads the parsed body, or the query string when there is no parsed body. Override `data(ServerRequestInterface): array` to change that.
Fields without a rule are not passed to `map()`.

On failure it throws `ValidationFailed`; `getMessages()` returns the laminas messages keyed by field.

## Error responses

Pipe `Ssmiff\MezzioValidator\Http\ValidationFailedMiddleware` ahead of your handlers, for example right after `ServerUrlMiddleware` in `config/pipeline.php`, and handlers need not catch the exception.
It answers `422` with `{"errors": {"field": {"validatorCode": "message"}}}` and passes every other response through. It needs a PSR-17 `ResponseFactoryInterface` in the container, which `laminas-diactoros` provides.

## Development

```
composer install
composer check
```
