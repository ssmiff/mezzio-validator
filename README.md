# ssmiff/mezzio-validator

Inject [laminas-inputfilter](https://docs.laminas.dev/laminas-inputfilter/) validation into Mezzio handlers, and get your own
object back.

```
composer require ssmiff/mezzio-validator
```

## Write a validator

`rules()` is a laminas input filter specification, passed through unchanged. `map()` builds your object from the filtered values.

```php
/** @extends AbstractRequestValidator<CreateUser> */
final class CreateUserValidator extends AbstractRequestValidator
{
    protected function rules(): array
    {
        return [
            'email' => ['name' => 'email', 'required' => true, 'validators' => [['name' => EmailAddress::class]]],
        ];
    }

    protected function map(array $values): CreateUser
    {
        return new CreateUser((string) $values['email']);
    }
}
```

`map()` gets one key per input and nothing else. To check fields against each other, throw
`new ValidationFailed(['field' => ['code' => 'message']])` from it.

## Use it

```php
/** @param RequestValidator<CreateUser> $validator */
public function __construct(
    #[UseValidator(CreateUserValidator::class)] private RequestValidator $validator,
) {}

public function handle(ServerRequestInterface $request): ResponseInterface
{
    $user = $this->validator->validate($request); // CreateUser, or throws ValidationFailed
}
```

Build the handler with `AttributeFactory`. Validators need no registration.

Pipe `Ssmiff\MezzioValidator\Http\ValidationFailedMiddleware` before routing to turn `ValidationFailed` into a `422` with
`{"errors": {"field": {"code": "message"}}}`.

## Where the data comes from

By default, the query string merged with the parsed body (the body wins on a clash). Override `data()` to change it, for example
to add a route parameter:

```php
protected function data(ServerRequestInterface $request): array
{
    return [...parent::data($request), 'id' => $request->getAttribute('id')];
}
```

## Upgrading from 0.1

Add `'name'` to every input spec, and pass `Laminas\InputFilter\Factory` (not `InputFilterPluginManager`) to `parent::__construct()`.
