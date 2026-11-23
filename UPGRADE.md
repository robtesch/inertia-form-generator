# Upgrade Guide

## Upgrading to 4.0 from 3.x

4.0 changes the shape of the generated TypeScript file and fixes many cases where the generator emitted wrong types, wrong initial values, or TypeScript that did not compile. Your PHP code and config file do not need to change. Your front-end code does: every import of a generated form has to be updated.

The steps below are ordered by how likely they are to affect you.

1. [Regenerate the file](#1-regenerate-the-file)
2. [Replace shared form instances with the new factories](#2-replace-shared-form-instances-with-the-new-factories) (**required**)
3. [Review changed field types and initial values](#3-review-changed-field-types-and-initial-values)
4. [Check enum fields](#4-check-enum-fields)
5. [Check the command output for skipped requests](#5-check-the-command-output-for-skipped-requests)
6. [Check paths and discovery](#6-check-paths-and-discovery)

### 1. Regenerate the file

```bash
composer require --dev robtesch/inertia-form-generator:^4.0
php artisan inertia-form-generator:generate
```

Then run your TypeScript checker (`vue-tsc --noEmit`, `tsc --noEmit`, `svelte-check`). It will point at every usage that needs updating in step 2.

You do not need to republish the config file. No keys were added, renamed or removed; only the comments changed.

### 2. Replace shared form instances with the new factories

**Likelihood of impact: high. Every project is affected.**

3.x exported one form instance per request, created when the module was first imported:

```ts
// 3.x
export const storeUserRequestForm = useForm({
  name: '' as string | undefined,
  age: null as number | null | undefined,
});
```

That had two problems:

- **React:** `useForm` from `@inertiajs/react` is a hook. Calling it at module level throws an "Invalid hook call" error as soon as the file is imported, so the 3.x output could not be used with React at all.
- **Vue and Svelte:** every component that imported the form shared the same instance, so its values, errors and `processing` state leaked between components and pages. With SSR, they also leaked between requests from different users.

4.0 exports a type and a factory function for each request instead:

```ts
// 4.0
export type StoreUserRequestFormData = {
  name: string;
  age: number | null;
};

export function useStoreUserRequestForm(overrides: Partial<StoreUserRequestFormData> = {}) {
  const defaults: StoreUserRequestFormData = {
    name: '',
    age: null,
  };

  return useForm<StoreUserRequestFormData>({ ...defaults, ...overrides });
}
```

#### Names

| Request class | 3.x export | 4.0 factory | 4.0 type |
| --- | --- | --- | --- |
| `App\Http\Requests\StoreUserRequest` | `storeUserRequestForm` | `useStoreUserRequestForm` | `StoreUserRequestFormData` |
| `App\Http\Requests\Admin\UpdatePostRequest` | `adminUpdatePostRequestForm` | `useAdminUpdatePostRequestForm` | `AdminUpdatePostRequestFormData` |

Requests outside `App\Http\Requests` keep their full namespace in the name, as before. For example `Modules\Blog\Http\Requests\StorePostRequest` becomes `useModulesBlogHttpRequestsStorePostRequestForm`.

#### Vue

```vue
<!-- 3.x -->
<script setup lang="ts">
import { storeUserRequestForm } from '@/formRequests';

storeUserRequestForm.defaults({ name: user.name });
</script>

<template>
  <input v-model="storeUserRequestForm.name" />
</template>
```

```vue
<!-- 4.0 -->
<script setup lang="ts">
import { useStoreUserRequestForm } from '@/formRequests';

const form = useStoreUserRequestForm({ name: user.name });
</script>

<template>
  <input v-model="form.name" />
</template>
```

- Call the factory inside `<script setup>` (or `setup()`), not at the top level of a shared module.
- Initial values you used to set with `.defaults({...})` go in the factory's argument instead. `.defaults()` still works on the returned form if you need to change the defaults later, for example after a successful save.

#### React

```tsx
// 4.0
import { useStoreUserRequestForm } from '@/formRequests';

export default function CreateUser({ user }: Props) {
  const form = useStoreUserRequestForm({ name: user.name });

  return <input value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} />;
}
```

The factory is a hook, so the usual rules of hooks apply: call it at the top level of a component or another hook.

#### Svelte

```svelte
<!-- 4.0 -->
<script lang="ts">
  import { useStoreUserRequestForm } from '@/formRequests';

  const form = useStoreUserRequestForm({ name: user.name });
</script>

<input bind:value={$form.name} />
```

#### If you relied on the shared instance

If two components deliberately shared one form (for example a multi-step wizard), create the form once in the parent and pass it down as a prop, or with `provide` / `inject` in Vue or context in React.

#### Typing your own code

The generated `...FormData` types can be imported directly, for example to type a prop or a helper:

```ts
import type { StoreUserRequestFormData } from '@/formRequests';
```

### 3. Review changed field types and initial values

**Likelihood of impact: medium.** Most of these are bug fixes, but they change what the generated file contains, so your type checker may report new errors where the old output was too loose. Fix them at the call site; they usually point at code that was already wrong.

#### Types are no longer `| undefined` everywhere

3.x added `| undefined` to every field, including `required` ones. 4.0 only does that for `sometimes` fields.

| Rules | 3.x type | 4.0 type |
| --- | --- | --- |
| `required\|string` | `string \| undefined` | `string` |
| `nullable\|integer` | `number \| null \| undefined` | `number \| null` |
| `sometimes\|integer` | `number \| undefined` | `number \| undefined` |

#### Initial values

| Rules | 3.x initial value | 4.0 initial value | Why |
| --- | --- | --- | --- |
| `sometimes\|integer` | `0` | `undefined` | An untouched optional field was submitted as `0` and could overwrite stored data. `undefined` values are left out of JSON requests (see [Multipart forms](#multipart-forms) below). |
| `nullable\|in:a,b` | `'a'` | `null` | `nullable` fields now always start as `null`. |
| `nullable\|integer\|in:1,2` | `0` | `null` | Same as above. |
| `array` with no `.*` rule | `''` | `[]` | The field was typed and initialised as a string. |
| `nullable\|array` | `''` | `null` | Same as above. |
| `'status' => Rule::in(['a', 'b'])` (not wrapped in an array) | `'"a"'` | `"a"` | The value included literal double quotes, so Laravel rejected it. |
| `integer\|in:3,4` | `0` typed `number` | `3` typed `3 \| 4` | Now a literal union, starting at the first allowed value. |
| `alpha_num` | `''` typed `string \| number` | `''` typed `string` | Form input is always a string. |
| `decimal:2` | `''` | `''` typed `string` | `decimal` checks the written form (`"1.00"`), which a number would lose. |

#### Multipart forms

When a form is sent as multipart (for example because it contains a file), Inertia serializes `undefined` as an empty string, so an untouched `sometimes` field is sent as `""`, which Laravel turns into `null`. If a form has both a file and `sometimes` fields, remove the untouched ones, at every level of nesting, before submitting:

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

#### Arrays and nested fields

Dotted rules are now typed all the way down, and the parent's own rules (such as `nullable`) are kept no matter which order the rules are declared in:

```php
'address' => 'nullable|array',
'address.street' => 'required|string',
'items' => 'required|array',
'items.*.name' => 'required|string',
'items.*.qty' => 'required|integer',
```

```ts
// 3.x: the parent's nullable was lost and items had no element type (never[] under strict mode)
address: { street: '' as string | undefined },
items: [],

// 4.0
address: { street: string } | null;   // initial value: null
items: Array<{ name: string; qty: number }>;  // initial value: []
```

#### Custom mappings

- A field whose mapped type is anything other than `string`, `number` or `boolean` now starts as `null`, and `| null` is added to its type. For example `'binary' => 'Blob'` now gives `Blob | null`.
- `nullable` is now honoured for mapped fields.
- Mapped types containing `: ` (for example `'{ lat: number; lng: number }'`) are no longer cut off.
- A string rule mapping now also matches the rule when it has parameters. For example `'mimes' => 'File'` matches `mimes:pdf,jpg`.

#### Rules that cannot be mapped

Fields whose rules the generator does not understand are now typed `any` and start as `''`. In 3.x they had no type annotation, so TypeScript inferred `string`. Add a `custom_mappings` entry to give them a real type.

#### Other output changes

- Fields are emitted in the order your `rules()` declares them, not alphabetically.
- A keyed array with a wildcard rule (`'labels' => 'array:en,fr'`, `'labels.*' => 'string'`) is typed `Record<string, string>` and starts as `{}`. When named children exist (`'translations.en'`), it is typed as an object with those keys.
- Literal values are emitted with double quotes (`"active"`) and are properly escaped, so values such as `O'Brien` no longer break the file.
- Keys that are not valid identifiers are quoted (`"2fa_code"`, `"first-name"`). Field names containing `_nullable` or `_array_of` are no longer renamed.
- Fluent rule objects are now understood whether or not they are wrapped in an array: `Rule::numeric()`, `Rule::date()`, `Rule::in()`, `Rule::array()`, `File::image()`, `Rule::email()`, `Password::min()`, and so on.
- More rules map to TypeScript types: `uuid`, `ulid`, `ip`, `json`, `timezone`, `digits`, `digits_between` and `decimal` become `string` (they check the written form, where leading or trailing zeros matter); `mimes` becomes `File | null`; `list` becomes an array.
- `Rule::when()` is expanded using the branch that applies to an empty request.
- Laravel 13 fluent rules that describe themselves as parameter lists (`Rule::requiredArrayKeys()`, `Rule::confirmed()`, `Rule::url()`, `Rule::uuid()`, ...) are understood.
- A `confirmed` field now also gets its `<field>_confirmation` sibling (for example `password_confirmation`), which 3.x left out, so the form could not pass validation. With a custom field (`confirmed:password_repeat`), that field is added at the path given.
- `required_array_keys:home,away` makes the field an object with those keys instead of a list.
- Class-keyed custom mappings also match subclasses, so a mapping for `Illuminate\Validation\Rules\File` applies to `File::image()`.

### 4. Check enum fields

**Likelihood of impact: low.**

Enum fields still reference the enum's TypeScript type by its dotted PHP class name (`App.Enums.Status`). The generated file does not declare that type, so you still need [spatie/typescript-transformer](https://github.com/spatie/typescript-transformer) (or your own declarations) to provide it.

What changed:

- The initial value is the first case the rule allows, so `Rule::enum(Status::class)->only([Status::Archived])` now starts as `'archived'` instead of the enum's first case.
- Non-backed (unit) enums no longer crash the generator. Their fields start as `null`.
- Backed values containing quotes are escaped.

### 5. Check the command output for skipped requests

**Likelihood of impact: medium if any of your requests read the route or user in `rules()`.**

In 3.x, one FormRequest that could not be loaded stopped the whole command, and no file was written. 4.0 skips that request, prints a warning naming it, and generates everything else:

```
Skipped App\Http\Requests\UpdatePostRequest: Attempt to read property "id" on null
File exported to /var/www/resources/js/formRequests.ts
```

Requests are skipped when:

- `rules()` throws, typically because it reads a route parameter (`$this->route('post')->id`) that does not exist outside a real request;
- the request cannot be constructed;
- its generated name clashes with another request's (for example `Admin\UserRequest` and `AdminUserRequest`).

If every request is skipped, the command fails and leaves the existing output file untouched instead of overwriting it with an empty one.

After upgrading, read the command's output. Add requests you do not want generated to `exclude` in the config, which also silences the warning.

Also new:

- Abstract FormRequest base classes are skipped instead of crashing the command.
- `rules()` is now called through the container, so method injection such as `rules(Repository $config)` works.

### 6. Check paths and discovery

**Likelihood of impact: low.**

- `output-file-path` is now resolved from the project root rather than the directory you run `php artisan` from. Missing directories are created, and the command fails with an error if the file cannot be written.
- `additional_paths` entries are now resolved from the project root, and the class name is read from each file rather than guessed from Composer's PSR-4 map. Directories that matched nothing in 3.x, such as relative paths and packages under `vendor/`, now work. Classes found there must still be loadable by Composer's autoloader.
- Discovery under `app/` no longer assumes the `App\` namespace, and it no longer executes `.php` files that do not declare a class (such as helper files).
- The `InertiaFormGenerator` facade alias registered in `composer.json` has been removed. It pointed at a class that never existed, so nothing could have been using it.
