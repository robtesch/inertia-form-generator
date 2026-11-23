# Inertia Form Generator

A small Laravel package that transforms your Laravel FormRequest validation rules into type-safe TypeScript definitions and ready-to-use Inertia useForm factories.

The package finds the FormRequest classes under app/ (and any additional paths you configure), maps their validation rules to TypeScript types (with support for nested fields, arrays, class-based validation rules, enums and custom mappings), and exports a single TypeScript file containing a type and a useForm factory for each request.

### Features

- Generates TypeScript type definitions from Laravel FormRequest rules.
- Generates Inertia useForm factories for Vue, React or Svelte.
- Supports PHP 8.x enums used with the Enum validation rule.
- Allows custom type mappings via configuration.
- Command-line generator: php artisan inertia-form-generator:generate

### Requirements

- PHP: ^8.4 (as declared in composer.json)
- Laravel 12 or 13 (package depends on illuminate/* components via composer)

### Installation

1. Install with Composer:

```bash
composer require robtesch/inertia-form-generator --dev
```

2. Publish the configuration file (so you can change output path, front-end provider, custom mappings, etc.):

```bash
php artisan vendor:publish --tag="inertia-form-generator-config"
```

### Configuration

The published config file config/inertia-form-generator.php contains the key options:

- output-file-path: string — Path where the generated TypeScript file will be written, relative to the project root. Missing directories are created. Default: resources/js/formRequests.ts
- front-end-provider: string — One of: 'vue', 'react', 'svelte4', 'svelte5' (controls which useForm import is used). Default: 'vue'
- user-model: string|null — Fully qualified user model used to create a fake user when FormRequests rely on an authenticated user. Default: 'App\\Models\\User'
- custom_mappings: array — Map validation rule class names or string rule names to custom TypeScript type strings. Example: ['App\\Rules\\YourCustomRule' => 'string | null']
- exclude: array — FormRequest classes to skip.
- additional_paths: array — Directories outside app/ to search for FormRequests (relative to the project root). Classes found there must be loadable by Composer's autoloader.

See config/inertia-form-generator.php for the exact defaults shipped with the package.

### Usage

1. Create or ensure you have FormRequest classes under app/ (typically app/Http/Requests) with rules() defined as usual.

2. Run the generator command to produce the TypeScript file:

```bash
php artisan inertia-form-generator:generate
```

By default the command writes to the path configured in config/inertia-form-generator.php (resources/js/formRequests.ts by default) and prints the resulting path.

#### What gets generated

For each FormRequest found, the package exports a `<Name>FormData` type and a `use<Name>Form()` factory. The name comes from the class name, with the `App\Http\Requests\` prefix removed. Given this request:

```php
class ExampleRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'name' => 'required|string',
            'age' => 'nullable|integer',
            'tags' => 'array',
            'tags.*' => 'string',
        ];
    }
}
```

the generator writes:

```ts
import { useForm } from '@inertiajs/vue3';

export type ExampleRequestFormData = {
  name: string;
  age: number | null;
  tags: Array<string>;
};

export function useExampleRequestForm(overrides: Partial<ExampleRequestFormData> = {}) {
  const defaults: ExampleRequestFormData = {
    name: '',
    age: null,
    tags: [],
  };

  return useForm<ExampleRequestFormData>({ ...defaults, ...overrides });
}
```

Call the factory inside your component, so each component gets its own form (and so React's rules of hooks are respected). Pass any initial values as overrides:

```vue
// ExampleComponent.vue
<script setup lang="ts">
import { useExampleRequestForm } from '@/formRequests';
import { usePage } from '@inertiajs/vue3';

const page = usePage();

const form = useExampleRequestForm({
  name: page.props.auth.user.name,
});
</script>

<template>
  <form @submit.prevent="form.post('/example-request')">
    <input v-model="form.name" type="text" />
    <input v-model="form.age" type="number" />
    <button type="submit">Submit</button>
  </form>
</template>
```

You will benefit from type-safety and all of Inertia's amazing useForm features.

### Notes and behavior

- The generator maps common validation rules (string, integer, numeric, boolean, array, file, in, etc.) and Laravel's fluent rule objects to TypeScript types. Dotted fields (`address.street`, `items.*.name`) become nested object and array types. Rules it cannot map are typed as `any`; map them yourself with custom_mappings.
- `nullable` fields start as `null`, and `sometimes` fields start as `undefined`, so an untouched field is left out of the submitted payload.
- A field with the `confirmed` rule also gets its `<field>_confirmation` sibling (or the custom field name), so the form can satisfy the rule. `required_array_keys:a,b` makes the field an object with those keys.
- `Rule::when()` is resolved against an empty request: a boolean condition works as expected, but a closure that inspects the input sees no data.
- Inertia sends `undefined` as an empty string when a form is submitted as multipart (for example because it contains a file). If a form has both a file and `sometimes` fields, drop the untouched ones before submitting; see [Multipart forms](#multipart-forms) below.
- Field names containing a literal dot (`'file\.name'` in your rules) are typed correctly, but Inertia's Vue adapter treats dots in form keys as paths when it submits, so it will send them nested.
- Enum validation rules are emitted as a reference to the enum's TypeScript type, using the PHP class name with dots (`App.Enums.Status`). The generated file does not declare these types; generate them with a tool such as [spatie/typescript-transformer](https://github.com/spatie/typescript-transformer). The initial value is the first case allowed by the rule (respecting `only()` / `except()`).
- If a FormRequest cannot be instantiated or its rules() throws (for example because it reads a route parameter), the command prints a warning naming the request, skips it, and carries on. Add such requests to `exclude` to silence the warning. If every request is skipped, the command fails and leaves the existing output file untouched.
- If a FormRequest needs an authenticated user, set the user-model config to a model that has a factory so the generator can instantiate a fake user during parsing. If you don't need this behavior, set user-model to null.
- The package uses auto-discovery (service provider is registered via composer extra) and registers a single Artisan command: inertia-form-generator:generate.

### Multipart forms

When a form is sent as multipart (for example because it contains a file), Inertia serializes `undefined` as an empty string, which Laravel turns into `null`. That means untouched `sometimes` fields are sent after all. Remove them, at every level of nesting, before submitting:

```ts
// Removes undefined values at any depth, leaving files, dates and arrays intact.
const withoutUndefined = (value: any): any =>
  Array.isArray(value)
    ? value.map(withoutUndefined)
    : value !== null && typeof value === 'object' && !(value instanceof Blob) && !(value instanceof Date)
      ? Object.fromEntries(
          Object.entries(value)
            .filter(([, item]) => item !== undefined)
            .map(([key, item]) => [key, withoutUndefined(item)]),
        )
      : value;

form.transform(withoutUndefined);
```

### Extending and Custom Mappings

Add mappings for custom rule classes or rule names in config/inertia-form-generator.php under the custom_mappings key. The key should be the validation rule string or the full class name of the rule, and the value should be the TypeScript type string you want to emit. A class key also matches subclasses of that rule. Fields mapped to `string`, `number` or `boolean` start as `''`, `0` or `false`; fields mapped to any other type start as `null`, and `| null` is added to their type.

```php
'custom_mappings' => [
    App\Rules\GeoPoint::class => 'CustomPointInterface | null',
    'binary' => 'Blob',
],
```

### Excluding FormRequests

You can exclude FormRequests from being parsed by adding them to the exclude array in the config. This can be useful in cases where the validation is particularly complex and this generator is not able to parse it correctly.

```php
'exclude' => [
    \App\Http\Requests\ExampleRequest::class,
],
```

### Upgrading

4.0 replaces the shared form instances with a type and a factory per request, and changes several generated types and initial values. See [UPGRADE.md](UPGRADE.md) for the full guide.

### Testing

Run the package tests with:

```bash
composer test
```

### Development notes

- Primary PHP entrypoint: src/InertiaFormGenerator.php
- Artisan command implementation: src/Commands/InertiaFormGeneratorCommand.php
- Package service provider: src/InertiaFormGeneratorServiceProvider.php
- Default config: config/inertia-form-generator.php

### License

This package is open-source under the MIT License — see LICENSE.md for details.

### Contributing

This package has primarily been developed and tested with my specific use case in mind. Fixes and improvements are welcome. Please open issues or pull requests on the project repository and follow the coding standards and test suites included in the package.

### Credits

- Author: Robert Teschmacher
