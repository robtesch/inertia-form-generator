<?php

namespace RobTesch\InertiaFormGenerator;

use Illuminate\Contracts\Validation\Rule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\ArrayRule;
use Illuminate\Validation\Rules\Dimensions;
use Illuminate\Validation\Rules\Enum;
use Illuminate\Validation\Rules\In;
use Illuminate\Validation\Rules\RequiredIf;
use Illuminate\Validation\Rules\Unique;
use InvalidArgumentException;
use ReflectionClass;
use ReflectionException;

class InertiaFormGenerator
{
    /**
     * @var class-string[]
     */
    private array $enumMap = [];

    public function getUseFormImportForProvider(string $provider): string
    {
        return match ($provider) {
            'vue' => "import { useForm } from '@inertiajs/vue3';",
            'react' => "import { useForm } from '@inertiajs/react';",
            'svelte4', 'svelte5' => "import { useForm } from '@inertiajs/svelte';",
            default => throw new InvalidArgumentException('Invalid provider: '.$provider),
        };
    }

    /**
     * @return FormRequest[]
     *
     * @throws ReflectionException
     */
    public function getFormRequests(): array
    {
        $files = File::allFiles(app_path('Http/Requests'));

        $excluded = Config::get('inertia-form-generator.exclude', []);

        $formRequests = [];

        foreach ($files as $file) {
            $classPath = str_replace('/', '\\', $file->getRelativePathname());
            $classPath = str_replace('.php', '', $classPath);

            /** @var class-string $class */
            $class = 'App\\Http\\Requests\\'.$classPath;

            if (in_array($class, $excluded, true)) {
                continue;
            }

            $reflection = new ReflectionClass($class);

            if ($reflection->isSubclassOf(FormRequest::class)) {
                $instance = $this->createFormRequestInstance($reflection);

                $formRequests[] = $instance;
            }
        }

        return $formRequests;
    }

    /**
     * @param  ReflectionClass<FormRequest>  $class
     *
     * @throws ReflectionException
     */
    private function createFormRequestInstance(ReflectionClass $class): FormRequest
    {
        /** @var FormRequest $formRequest */
        $formRequest = $class->newInstance();

        $request = new Request;

        $formRequest->setContainer(app())
            ->setRedirector(app('redirect'));
        $formRequest->initialize(
            $request->query->all(),
            $request->request->all(),
            $request->attributes->all(),
            $request->cookies->all(),
            $request->files->all(),
            $request->server->all(),
            $request->getContent(),
        );

        /** @var class-string<Model>|null $userClass */
        $userClass = Config::get('inertia-form-generator.user-model');
        if ($userClass && method_exists($userClass, 'factory')) {
            $formRequest->setUserResolver(fn () => $userClass::factory()->make());
        } else {
            $formRequest->setUserResolver($request->getUserResolver());
        }

        return $formRequest;
    }

    /**
     * @param  FormRequest[]  $formRequests
     * @return list<array{formName: string, initial: string}>
     *
     * @throws ReflectionException
     */
    public function transformRequests(array $formRequests): array
    {
        $transformed = [];

        foreach ($formRequests as $instance) {
            $rules = method_exists($instance, 'rules') ? $instance->rules() : [];

            $properties = $this->transformRulesToTypeScriptProperties($rules);

            $keyedProperties = [];
            foreach ($properties as $property) {
                // split at ": " to get the property name and value
                $split = explode(': ', $property);
                $keyedProperties[$split[0]] = $split[1];
            }

            $undot = Arr::undot($keyedProperties);

            $cleaned = $this->cleanArray($undot);

            if (empty($cleaned)) {
                continue;
            }

            // sort the keys alphabetically
            ksort($cleaned);

            $class = get_class($instance);
            $typeName = str_replace('App\\Http\\Requests\\', '', $class);
            $typeName = str_replace('\\', '', $typeName);

            $formName = Str::of($typeName)->camel()->append('Form');

            $initial = $this->buildInitialFromCleaned($cleaned);

            $transformed[] = [
                'formName' => $formName->toString(),
                'initial' => $initial,
            ];
        }

        return $transformed;
    }

    /**
     * @param  array<string, mixed>  $rules
     * @return array<string>
     *
     * @throws ReflectionException
     */
    protected function transformRulesToTypeScriptProperties(array $rules): array
    {
        $properties = [];

        foreach ($rules as $field => $ruleSet) {
            $type = $this->mapValidationRulesToType($ruleSet);
            $properties[] = sprintf('%s: %s;', $field, $type);
        }

        return $properties;
    }

    /**
     * @param  string|array<int, mixed>  $rules
     *
     * @throws ReflectionException
     *
     * @phpstan-ignore parameter.deprecatedInterface
     */
    protected function mapValidationRulesToType(string|array|Rule $rules): string
    {
        $rules = Arr::wrap(is_string($rules) ? explode('|', $rules) : $rules);

        $type = 'unknown';

        if ($this->hasOverridenRule($rules)) {
            return $this->getOverridenRule($rules);
        }

        if ($this->hasClassBasedRule($rules)) {
            $type = $this->getTypeFromClassBasedRule($rules);
        }

        if ($type === 'unknown') {
            if ($this->hasStringStyleInRule($rules)) {
                foreach ($rules as $rule) {
                    if (is_string($rule) && Str::startsWith($rule, 'in:')) {
                        // Only return the list of values if it's not "numeric"
                        if (in_array('numeric', $rules, true) || in_array('integer', $rules, true)) {
                            return 'number';
                        }

                        return Str::of($rule)->after('in:')->explode(',')->map(fn ($val): string => "'".trim((string) $val)."'")->implode(' | ');
                    }
                }
            }
            if ($this->hasRule($rules, ['alpha_num'])) {
                $type = 'string | number';
            } elseif ($this->hasRule($rules, ['integer', 'numeric', 'int', 'float'])) {
                $type = 'number';
            } elseif ($this->hasRule($rules, ['string', 'email', 'url', 'date_format', 'date'])) {
                $type = 'string';
            } elseif ($this->hasRule($rules, ['boolean', 'bool', 'accepted', 'declined'])) {
                $type = 'boolean';
            } elseif ($this->hasRule($rules, ['file', 'image'])) {
                $type = 'File | null';
            } elseif ($this->hasRule($rules, ['array'])) {
                $type = 'unknown[]';
            }
        }

        if ($this->hasRule($rules, ['nullable'])) {
            $type .= ' | null';
        }

        if (Str::contains($type, 'unknown')) {
            return 'unknown';
        }

        return str_replace('null | null', 'null', $type);
    }

    /**
     * @param  array<int, mixed>  $rules
     * @param  array<int, string>  $ruleNames
     */
    protected function hasRule(array $rules, array $ruleNames): bool
    {
        return array_any($rules,
            fn ($rule) => is_string($rule) && (in_array($rule, $ruleNames, true) || Str::startsWith($rule, array_map(fn ($name): string => $name.':', $ruleNames))));
    }

    /**
     * @param  array<int, mixed>  $rules
     */
    protected function hasClassBasedRule(array $rules): bool
    {
        return array_any($rules, fn ($rule) => is_object($rule));
    }

    /**
     * @param  array<int, mixed>  $rules
     */
    protected function hasOverridenRule(array $rules): bool
    {
        $overrides = array_keys(Config::get('inertia-form-generator.custom_mappings'));

        return array_any($rules, function ($rule) use ($overrides) {
            if (is_object($rule)) {
                return in_array(get_class($rule), $overrides, true);
            }

            return is_string($rule) && in_array($rule, $overrides, true);
        });
    }

    /**
     * @param  array<int, mixed>  $rules
     */
    protected function getOverridenRule(array $rules): string
    {
        $overrides = Config::get('inertia-form-generator.custom_mappings');

        foreach ($rules as $rule) {
            if (is_object($rule)) {
                return $overrides[get_class($rule)];
            }
            if (in_array((string) $rule, array_keys($overrides), true)) {
                return $overrides[(string) $rule];
            }
        }

        return 'unknown';
    }

    /**
     * @param  array<int, mixed>  $rules
     *
     * @throws ReflectionException
     */
    protected function getTypeFromClassBasedRule(array $rules): string
    {
        $result = 'unknown';
        foreach ($rules as $rule) {
            switch ($rule) {
                case $rule instanceof In:
                    // Only return the list of values if it's not "numeric"
                    if (in_array('numeric', $rules, true) || in_array('integer', $rules, true)) {
                        return 'number';
                    }

                    $val = Str::of($rule->__toString())->after('in:')->explode(',')->implode(' | ');
                    if ($val === '') {
                        return 'string';
                    }

                    return $val;
                case $rule instanceof Enum:
                    $reflection = new ReflectionClass($rule);
                    $enumClass = $reflection->getProperty('type')->getValue($rule);
                    if (! is_string($enumClass) || ! class_exists($enumClass)) {
                        return 'string';
                    }
                    // Convert to typescript format
                    $typeName = str_replace('\\', '.', $enumClass);
                    // Add to enum map for later use.
                    $this->enumMap[(string) $typeName] = $enumClass;

                    return $typeName;
                case $rule instanceof RequiredIf:
                case $rule instanceof Unique:
                case $rule instanceof Dimensions:
                case is_object($rule):
                case is_callable($rule):
                    return 'unknown';
                case $rule instanceof ArrayRule:
                    $keys = Str::of($rule->__toString())->after('array:')->explode(',')->map(fn ($key): string => trim((string) $key).': unknown')->implode(';');

                    return '{'.$keys.'}';
                case is_string($rule):
                    // do nothing
                    break;
                default:
                    // do nothing
            }
        }

        return $result;
    }

    /**
     * @param  array<int, mixed>  $rules
     */
    protected function hasStringStyleInRule(array $rules): bool
    {
        return array_any($rules, fn ($rule) => is_string($rule) && Str::startsWith($rule, 'in:'));
    }

    /**
     * @param  string|array<int, mixed>  $rules
     *
     * @phpstan-ignore parameter.deprecatedInterface
     */
    protected function isRequired(string|array|Rule $rules): bool
    {
        $rules = is_string($rules) ? explode('|', $rules) : (array) $rules;

        return $this->hasRule($rules, ['required']);
    }

    /**
     * @param  array<string, mixed>  $array
     * @return array<string, mixed>
     */
    protected function cleanArray(array $array): array
    {
        $result = [];
        foreach ($array as $key => $val) {
            $isNullable = is_string($val) && str_contains($val, '| null') ? '_nullable' : '';

            if (is_array($val)) {
                if (array_key_exists('*', $val)) {
                    $subVal = $val['*'];
                    if (is_array($subVal)) {
                        $result[$key.'_array_of'.$isNullable] = $this->cleanArray($subVal);
                    } else {
                        $result[$key.'_array_of'.$isNullable] = $subVal;
                    }
                } else {
                    $result[$key.$isNullable] = $this->cleanArray($val);
                }
            } else {
                $result[$key.$isNullable] = $val;
            }
        }

        return $result;
    }

    /**
     * @param  array<string, mixed>  $cleaned
     */
    private function buildInitialFromCleaned(array $cleaned): string
    {
        $lines = $this->initialObjectLines($cleaned, '  ');

        return '{'.PHP_EOL.implode('', $lines).'}';
    }

    /**
     * @param  array<string, mixed>  $node
     * @return list<string>
     */
    private function initialObjectLines(array $node, string $indent): array
    {
        $out = [];

        foreach ($node as $rawKey => $val) {
            $isArrayOf = str_contains($rawKey, '_array_of');
            $isNullable = str_contains($rawKey, '_nullable');

            $key = str_replace(['_array_of', '_nullable'], '', $rawKey);

            // quote unsafe keys
            if (str_contains($key, ' ') || str_contains($key, '-')) {
                $key = '"'.$key.'"';
            }

            if (is_array($val)) {
                if ($isArrayOf) {
                    // arrays default to [] (nullable -> null)
                    $out[] = $indent.$key.': '.($isNullable ? 'null' : '[]').','.PHP_EOL;
                } else {
                    // nested object
                    $children = $this->initialObjectLines($val, $indent.'  ');
                    $body = '{'.PHP_EOL.implode('', $children).$indent.'}';
                    $out[] = $indent.$key.': '.($isNullable ? 'null' : $body).','.PHP_EOL;
                }
            } else {
                // leaf: $val is a TS type string like "string", "number", "boolean", "File", "unknown[]", unions, etc.
                $ts = (string) $val;

                $asType = rtrim($ts, ';');
                if ($asType === 'unknown') {
                    $asString = '';
                } else {
                    $asType .= ' | undefined';
                    if ($isArrayOf) {
                        $asType = 'Array<'.$asType.'>';
                    }

                    $asString = ' as '.$asType;
                }

                $default = $this->defaultForTsType($ts, $isNullable, $isArrayOf).$asString;

                $out[] = $indent.$key.': '.$default.','.PHP_EOL;
            }
        }

        return $out;
    }

    /**
     * Heuristics for initial values from TS type strings.
     */
    private function defaultForTsType(string $ts, bool $nullable, bool $arrayOf): string
    {

        // if we have a set of literal string values, pick the first one
        if (! $arrayOf && str_contains($ts, '|')) {
            $parts = explode('|', $ts);
            $first = trim($parts[0]);
            if ((str_contains($first, '"') || str_contains($first, "'")) && ! str_contains($first, '[]')) {
                return $first;
            }
        }

        if (! $arrayOf && str_contains($ts, "'")) {
            return trim($ts, ';');
        }

        // Arrays
        if (! $nullable && ($arrayOf || preg_match('/\bArray<.*>\[]?|\bunknown\[]/', $ts))) {
            return '[]';
        }

        if ($nullable) {
            return 'null';
        }

        // Literal unions like: `'a' | 'b' | 'c'`
        if (preg_match("/^'[^']*'(?:\s*\|\s*'[^']*')+$/", trim($ts))) {
            return "''"; // neutral empty; avoids picking a value accidentally
        }

        // string-ish
        if (str_contains($ts, 'string')) {
            return "''";
        }

        // number-ish
        if (str_contains($ts, 'number')) {
            return '0';
        }

        // boolean
        if (str_contains($ts, 'boolean')) {
            return 'false';
        }

        // File / Uploadables
        if (preg_match('/\bFile\b/i', $ts)) {
            return 'null';
        }

        // object-ish unknown
        if ($ts === 'unknown;') {
            return "''";
        }

        // enums like App.Enums.Status -> attempt to get the first value
        if (preg_match('/^[A-Za-z0-9_.]+$/', trim($ts, ';'))) {
            $trimmed = trim($ts, ';');
            if (array_key_exists($trimmed, $this->enumMap)) {
                $cases = $this->enumMap[$trimmed]::cases();
                if (count($cases) === 0) {
                    return 'null';
                }

                $firstValue = $cases[0]->value;
                // replace any \ with \\
                $firstValue = str_replace('\\', '\\\\', $firstValue);

                return "'".$firstValue."'";
            }
        }

        // fallback
        return 'null';
    }
}
