<?php

namespace RobTesch\InertiaFormGenerator\Tests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Config;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\File;
use Illuminate\Validation\Rules\Password;
use InvalidArgumentException;
use RobTesch\InertiaFormGenerator\InertiaFormGenerator;
use RobTesch\InertiaFormGenerator\Tests\Fixtures\Enums\Status;
use RobTesch\InertiaFormGenerator\Tests\Fixtures\Enums\Version;

/**
 * @param  array<string, mixed>  $rules
 * @return array{name: string, type: string, initial: string}
 */
function generate(array $rules): array
{
    $request = new class extends FormRequest
    {
        /** @var array<string, mixed> */
        public array $testRules = [];

        public function rules(): array
        {
            return $this->testRules;
        }
    };
    $request->testRules = $rules;

    $generator = new InertiaFormGenerator;
    $transformed = $generator->transformRequests([$request]);

    expect($generator->warnings())->toBe([]);
    expect($transformed)->toHaveCount(1);

    return $transformed[0];
}

/**
 * Build the expected "{ ... }" block from one line per property.
 *
 * @param  list<string>  $lines
 */
function block(array $lines): string
{
    return '{'.PHP_EOL.implode(PHP_EOL, array_map(fn (string $line) => '  '.$line, $lines)).PHP_EOL.'}';
}

it('maps basic validation rules to types and initial values', function () {
    $form = generate([
        'name' => 'string|required',
        'age' => 'integer|nullable',
        'agree' => 'accepted',
        'avatar' => 'nullable|image',
        'anything' => 'required',
    ]);

    expect($form['type'])->toBe(block([
        'name: string;',
        'age: number | null;',
        'agree: boolean;',
        'avatar: File | null;',
        'anything: any;',
    ]));
    expect($form['initial'])->toBe(block([
        "name: '',",
        'age: null,',
        'agree: false,',
        'avatar: null,',
        "anything: '',",
    ]));
});

it('types arrays from their element rules regardless of declaration order', function () {
    $form = generate([
        'tags.*' => 'string',
        'tags' => 'array',
        'plain' => 'array',
        'maybe' => 'nullable|array',
        'maybe.*' => 'integer',
        'first' => 'required|array',
        'first.*' => 'string',
        'first.0' => 'required|string',
    ]);

    expect($form['type'])->toBe(block([
        'tags: Array<string>;',
        'plain: any[];',
        'maybe: Array<number> | null;',
        'first: Array<string>;',
    ]));
    expect($form['initial'])->toBe(block([
        'tags: [],',
        'plain: [],',
        'maybe: null,',
        'first: [],',
    ]));
});

it('types nested objects and arrays of objects', function () {
    $form = generate([
        'address' => 'required|array',
        'address.street' => 'required|string',
        'address.zip' => 'nullable|string',
        'items' => 'required|array',
        'items.*.name' => 'required|string',
        'items.*.qty' => 'required|integer',
    ]);

    expect($form['type'])->toBe(block([
        'address: {',
        '  street: string;',
        '  zip: string | null;',
        '};',
        'items: Array<{',
        '  name: string;',
        '  qty: number;',
        '}>;',
    ]));
    expect($form['initial'])->toBe(block([
        'address: {',
        "  street: '',",
        '  zip: null,',
        '},',
        'items: [],',
    ]));
});

it('keeps field names that look like internal markers and quotes unsafe keys', function () {
    $form = generate([
        'is_nullable' => 'boolean',
        'show_array_of_x' => 'string',
        '2fa_code' => 'string',
        'first-name' => 'string',
        'say "hi"' => 'string',
        'file\.name' => 'string',
    ]);

    expect($form['type'])->toBe(block([
        'is_nullable: boolean;',
        'show_array_of_x: string;',
        '"2fa_code": string;',
        '"first-name": string;',
        '"say \"hi\"": string;',
        '"file.name": string;',
    ]));
});

it('maps in: rules to literal unions and picks the first value as the default', function () {
    $form = generate([
        'status' => 'in:active,inactive',
        'quoted' => "in:o'brien,\"a,b\"",
        'object' => Rule::in(['draft', 'say "hi"']),
        'maybe' => 'nullable|in:a,b',
        'count' => 'integer|in:3,4',
        'many' => ['array', Rule::in(['x', 'y'])],
    ]);

    expect($form['type'])->toBe(block([
        'status: "active" | "inactive";',
        'quoted: "o\'brien" | "a,b";',
        'object: "draft" | "say \"hi\"";',
        'maybe: "a" | "b" | null;',
        'count: 3 | 4;',
        'many: Array<"x" | "y">;',
    ]));
    expect($form['initial'])->toBe(block([
        'status: "active",',
        'quoted: "o\'brien",',
        'object: "draft",',
        'maybe: null,',
        'count: 3,',
        'many: [],',
    ]));
});

it('maps Enum rules to a referenced enum type with the first allowed case as default', function () {
    $form = generate([
        'status' => Rule::enum(Status::class),
        'only' => Rule::enum(Status::class)->only([Status::inactive]),
        'version' => ['required', Rule::unique('versions'), Rule::enum(Version::class)],
    ]);

    $status = 'RobTesch.InertiaFormGenerator.Tests.Fixtures.Enums.Status';
    $version = 'RobTesch.InertiaFormGenerator.Tests.Fixtures.Enums.Version';

    expect($form['type'])->toBe(block([
        "status: {$status};",
        "only: {$status};",
        "version: {$version};",
    ]));
    expect($form['initial'])->toBe(block([
        "status: \"active\" as {$status},",
        "only: \"inactive\" as {$status},",
        "version: 1 as {$version},",
    ]));
});

it('accepts bare rule objects, closures and fluent rules', function () {
    $form = generate([
        'closure' => fn ($attribute, $value, $fail) => null,
        'when' => Rule::requiredIf(true),
        'amount' => Rule::numeric()->min(1),
        'upload' => File::image(),
        'day' => ['date', Rule::in(['2025-01-01'])],
        'email' => ['nullable', Rule::email()],
        'password' => ['required', Password::min(8)],
        'code' => 'alpha_num',
        'zip' => 'string|digits:5',
        'pin' => 'digits:4',
        'conditional' => Rule::when(true, ['required', 'integer'], ['nullable', 'string']),
        'otherwise' => Rule::when(false, 'required|integer', 'nullable|string'),
        'pattern' => ['string', Rule::regex('/^(foo|int|nullable)$/')],
        'loose' => Rule::regex('/foo|int|bar/'),
        'terms' => 'accepted_if:role,admin',
        'empty' => [Rule::in([])],
        'bag' => 'array:',
        'game' => ['required', 'array', 'required_array_keys:home,away'],
        'game.*' => 'required|string',
        'score' => ['array', Rule::requiredArrayKeys('home')],
        'huge' => 'numeric|in:1e309,1',
        'secret' => 'required|string|confirmed',
        'contact' => ['required', 'email', Rule::confirmed()->customField('contact_repeat')],
        'account.password' => 'required|string|confirmed:password_repeat',
        'agree' => 'required|boolean|confirmed',
        'price' => ['decimal:2', Rule::in(['1.00', '0.99'])],
        'amount2' => 'required|decimal:2',
        'postcode' => 'required|numeric|digits:5',
        'total' => Rule::numeric()->decimal(2),
        'year' => 'integer|digits:4',
        'phone' => 'required|numeric|min_digits:10',
        'big' => 'integer|in:9007199254740993',
        'secret2' => 'required|confirmed',
    ]);

    expect($form['type'])->toBe(block([
        'closure: any;',
        'when: any;',
        'amount: number;',
        'upload: File | null;',
        'day: "2025-01-01";',
        'email: string | null;',
        'password: string;',
        'code: string;',
        'zip: string;',
        'pin: string;',
        'conditional: number;',
        'otherwise: string | null;',
        'pattern: string;',
        'loose: string;',
        'terms: boolean;',
        'empty: string;',
        'bag: any[];',
        'game: {',
        '  home: string;',
        '  away: string;',
        '};',
        'score: {',
        '  home: any;',
        '};',
        'huge: "1e309" | "1";',
        'secret: string;',
        'secret_confirmation: string;',
        'contact: string;',
        'contact_repeat: string;',
        'account: {',
        '  password: string;',
        '};',
        'password_repeat: string;',
        'agree: boolean;',
        'agree_confirmation: boolean;',
        'price: "1.00" | "0.99";',
        'amount2: string;',
        'postcode: string;',
        'total: string;',
        'year: number;',
        'phone: string;',
        'big: "9007199254740993";',
        'secret2: string;',
        'secret2_confirmation: string;',
    ]));
});

it('adds null and undefined only at the top level of a union', function () {
    $form = generate([
        'profile' => 'nullable|array',
        'profile.nickname' => 'sometimes|nullable|string',
        'choice' => ['sometimes', Rule::in(['a | undefined | b'])],
    ]);

    expect($form['type'])->toBe(block([
        'profile: {',
        '  nickname: string | null | undefined;',
        '} | null;',
        'choice: "a | undefined | b" | undefined;',
    ]));
    expect($form['initial'])->toBe(block([
        'profile: null,',
        'choice: undefined,',
    ]));
});

it('types keyed arrays as objects even when they also have a wildcard rule', function () {
    $form = generate([
        'translations' => ['array:en,fr', 'required_array_keys:en'],
        'translations.*' => 'string',
        'translations.en' => 'required|string',
        'labels' => 'array:en,fr',
        'labels.*' => 'string',
        'names' => 'array_keys:en,fr',
        'names.*' => 'string',
    ]);

    expect($form['type'])->toBe(block([
        'translations: {',
        '  en: string;',
        '};',
        'labels: Record<string, string>;',
        'names: Record<string, string>;',
    ]));
    expect($form['initial'])->toBe(block([
        'translations: {',
        "  en: '',",
        '},',
        'labels: {},',
        'names: {},',
    ]));
});

it('keeps rule parameters exactly as Laravel parses them', function () {
    $form = generate([
        'status' => ['in:draft '],
    ]);

    expect($form['type'])->toBe(block(['status: "draft ";']));
});

it('leaves sometimes fields out of the initial payload', function () {
    $form = generate([
        'count' => 'sometimes|integer',
    ]);

    expect($form['type'])->toBe(block(['count: number | undefined;']));
    expect($form['initial'])->toBe(block(['count: undefined,']));
});

it('respects custom mappings from configuration', function () {
    Config::set('inertia-form-generator.custom_mappings', [
        'string' => 'CustomStringType',
        'point' => '{ lat: number; lng: number }',
        'binary' => 'Blob',
        'callback' => '() => void',
        '\\'.File::class => 'UploadedImage',
    ]);

    $form = generate([
        'title' => 'string',
        'coords' => 'point',
        'coords.lat' => 'numeric',
        'location' => 'point',
        'avatar' => 'nullable|binary',
        'mixed' => [Rule::unique('users'), 'string'],
        'onDone' => 'callback',
        'photo' => File::image(),
    ]);

    expect($form['type'])->toBe(block([
        'title: CustomStringType | null;',
        'coords: { lat: number; lng: number } | null;',
        'location: { lat: number; lng: number } | null;',
        'avatar: Blob | null;',
        'mixed: CustomStringType | null;',
        'onDone: (() => void) | null;',
        'photo: UploadedImage | null;',
    ]));
});

it('reports requests whose rules cannot be evaluated and carries on', function () {
    $broken = new class extends FormRequest
    {
        public function rules(): array
        {
            return ['title' => 'unique:posts,title,'.$this->route('post')->id];
        }
    };

    $generator = new InertiaFormGenerator;
    $transformed = $generator->transformRequests([$broken]);

    expect($transformed)->toBe([]);
    expect($generator->warnings())->toHaveCount(1);
    expect($generator->warnings()[0])->toContain('Skipped Illuminate\Foundation\Http\FormRequest@anonymous');
});

it('returns correct useForm import for each supported provider and errors on unsupported', function () {
    $generator = new InertiaFormGenerator;

    expect($generator->getUseFormImportForProvider('vue'))->toBe("import { useForm } from '@inertiajs/vue3';");
    expect($generator->getUseFormImportForProvider('react'))->toBe("import { useForm } from '@inertiajs/react';");
    expect($generator->getUseFormImportForProvider('svelte4'))->toBe("import { useForm } from '@inertiajs/svelte';");
    expect($generator->getUseFormImportForProvider('svelte5'))->toBe("import { useForm } from '@inertiajs/svelte';");

    expect(fn () => $generator->getUseFormImportForProvider('angular'))->toThrow(InvalidArgumentException::class);
});
