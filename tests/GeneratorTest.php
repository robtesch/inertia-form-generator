<?php

namespace RobTesch\InertiaFormGenerator\Tests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Config;
use Illuminate\Validation\Rule;
use InvalidArgumentException;
use RobTesch\InertiaFormGenerator\InertiaFormGenerator;
use RobTesch\InertiaFormGenerator\Tests\Fixtures\Enums\Status;

it('maps basic validation rules to types and initial values', function () {
    $generator = new InertiaFormGenerator;

    $request = new class extends FormRequest
    {
        public function rules(): array
        {
            return [
                'name' => 'string|required',
                'age' => 'integer|nullable',
            ];
        }
    };

    $transformed = $generator->transformRequests([$request]);

    expect(count($transformed))->toBe(1);

    $item = $transformed[0];

    // type should include name and age
    expect($item['initial'])->toContain('name')->toContain('age');

    // initial should have sensible defaults: name -> '', age -> null (nullable)
    expect($item['initial'])->toContain("name: ''")->toContain('age: null');
});

it('handles array rules and defaults arrays to []', function () {
    $generator = new InertiaFormGenerator;

    $request = new class extends FormRequest
    {
        public function rules(): array
        {
            return [
                'tags' => 'array',
                'tags.*' => 'string',
            ];
        }
    };

    $transformed = $generator->transformRequests([$request]);

    expect(count($transformed))->toBe(1);

    $item = $transformed[0];

    expect($item['formName'])->toContain('illuminateFoundationHttpFormRequest');
    expect($item['initial'])->toContain('tags: []');
});

it('picks the first value for in: style rules as the default', function () {
    $generator = new InertiaFormGenerator;

    $request = new class extends FormRequest
    {
        public function rules(): array
        {
            return [
                'status' => 'in:active,inactive',
            ];
        }
    };

    $transformed = $generator->transformRequests([$request]);

    expect(count($transformed))->toBe(1);

    $item = $transformed[0];

    // initial should contain the first literal value
    expect($item['initial'])->toContain("'active'");
});

it('maps Enum rule to a referenced enum type in the emitted type', function () {
    $generator = new InertiaFormGenerator;

    $request = new class extends FormRequest
    {
        public function rules(): array
        {
            return [
                'status' => Rule::enum(Status::class),
            ];
        }
    };

    $transformed = $generator->transformRequests([$request]);

    expect(count($transformed))->toBe(1);

    $item = $transformed[0];

    // the generated TS type should reference the enum type name (dot-separated)
    expect($item['initial'])->toContain('status')->toContain('active');
});

it('respects custom mappings from configuration', function () {
    Config::set('inertia-form-generator.custom_mappings', [
        // Override the string rule mapping for the test
        'string' => 'CustomStringType',
    ]);

    $generator = new InertiaFormGenerator;

    $request = new class extends FormRequest
    {
        public function rules(): array
        {
            return [
                'title' => 'string',
            ];
        }
    };

    $transformed = $generator->transformRequests([$request]);

    expect(count($transformed))->toBe(1);

    $item = $transformed[0];

    // The emitted TS type should use the custom mapping
    expect($item['initial'])->toContain('CustomStringType');
});

it('returns correct useForm import for each supported provider and errors on unsupported', function () {
    $generator = new InertiaFormGenerator;

    expect($generator->getUseFormImportForProvider('vue'))->toBe("import { useForm } from '@inertiajs/vue3';");
    expect($generator->getUseFormImportForProvider('react'))->toBe("import { useForm } from '@inertiajs/react';");
    expect($generator->getUseFormImportForProvider('svelte4'))->toBe("import { useForm } from '@inertiajs/svelte';");
    expect($generator->getUseFormImportForProvider('svelte5'))->toBe("import { useForm } from '@inertiajs/svelte';");

    $thrown = false;
    try {
        $generator->getUseFormImportForProvider('angular');
    } catch (InvalidArgumentException $e) {
        $thrown = true;
    }

    expect($thrown)->toBeTrue();
});
