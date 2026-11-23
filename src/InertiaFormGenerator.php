<?php

namespace RobTesch\InertiaFormGenerator;

use Closure;
use Illuminate\Contracts\Validation\ParameterizedRule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Illuminate\Validation\ConditionalRules;
use Illuminate\Validation\Rules\ArrayRule;
use Illuminate\Validation\Rules\Date;
use Illuminate\Validation\Rules\Email;
use Illuminate\Validation\Rules\Enum;
use Illuminate\Validation\Rules\File as FileRule;
use Illuminate\Validation\Rules\In;
use Illuminate\Validation\Rules\Numeric;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\Rules\StringRule;
use InvalidArgumentException;
use PhpToken;
use ReflectionClass;
use Stringable;
use Throwable;
use UnitEnum;

/**
 * @phpstan-type Node array{rules: list<mixed>|null, children: array<string, mixed>}
 * @phpstan-type Field array{type: string, default: string}
 * @phpstan-type TransformedRequest array{name: string, type: string, initial: string}
 */
class InertiaFormGenerator
{
    private const string INDENT = '  ';

    private const array NUMBER_RULES = ['integer', 'int', 'numeric', 'float'];

    private const array BOOLEAN_RULES = ['boolean', 'bool', 'accepted', 'accepted_if', 'declined', 'declined_if'];

    private const array FILE_RULES = ['file', 'image', 'mimes', 'mimetypes', 'extensions', 'dimensions'];

    private const array STRING_RULES = [
        'string', 'alpha_num', 'email', 'url', 'active_url', 'date', 'date_format', 'date_equals', 'after', 'after_or_equal',
        'before', 'before_or_equal', 'uuid', 'ulid', 'alpha', 'alpha_dash', 'ip', 'ipv4', 'ipv6', 'mac_address',
        'json', 'timezone', 'hex_color', 'lowercase', 'uppercase', 'regex', 'not_regex', 'starts_with', 'ends_with',
        'doesnt_start_with', 'doesnt_end_with', 'current_password',
        // Digits rules usually guard zip codes, PINs and phone numbers, where leading zeros matter, and
        // "decimal:2" checks the written form ("1.00"), which a number would lose.
        'digits', 'digits_between', 'min_digits', 'max_digits', 'decimal',
    ];

    private const array ARRAY_RULES = ['array', 'list'];

    /**
     * Problems found while discovering or transforming requests. Each entry names the request it affected.
     *
     * @var list<string>
     */
    private array $warnings = [];

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
     * @return list<string>
     */
    public function warnings(): array
    {
        return $this->warnings;
    }

    /**
     * Find every concrete FormRequest declared under app/ and the configured additional paths.
     *
     * @return list<FormRequest>
     */
    public function getFormRequests(): array
    {
        $excluded = array_map(
            fn (string $class): string => ltrim($class, '\\'),
            Config::get('inertia-form-generator.exclude') ?? [],
        );

        $paths = [app_path(), ...Config::get('inertia-form-generator.additional_paths') ?? []];

        $classes = [];

        foreach ($paths as $path) {
            $path = $this->absolutePath($path);

            if (! is_dir($path)) {
                $this->warnings[] = "Skipped path {$path}: directory not found.";

                continue;
            }

            foreach (File::allFiles($path) as $file) {
                if ($file->getExtension() !== 'php') {
                    continue;
                }

                foreach ($this->classesInFile($file->getPathname()) as $class) {
                    if (! in_array($class, $excluded, true)) {
                        $classes[$class] = true;
                    }
                }
            }
        }

        ksort($classes);

        $formRequests = [];

        foreach (array_keys($classes) as $class) {
            try {
                if (! class_exists($class)) {
                    continue;
                }

                $reflection = new ReflectionClass($class);

                if (! $reflection->isSubclassOf(FormRequest::class) || ! $reflection->isInstantiable()) {
                    continue;
                }

                $formRequests[] = $this->createFormRequestInstance($reflection);
            } catch (Throwable $e) {
                $this->warnings[] = "Skipped {$class}: {$e->getMessage()}";
            }
        }

        return $formRequests;
    }

    /**
     * Resolve a configured path against the application root unless it is already absolute.
     */
    public function absolutePath(string $path): string
    {
        if (str_starts_with($path, DIRECTORY_SEPARATOR) || preg_match('/^[A-Za-z]:[\\\\\/]/', $path)) {
            return $path;
        }

        return base_path($path);
    }

    /**
     * Read the fully qualified names of the classes declared in a file without loading it,
     * so that discovery never executes scripts or helper files.
     *
     * @return list<string>
     */
    private function classesInFile(string $path): array
    {
        $contents = file_get_contents($path);

        if ($contents === false || stripos($contents, 'class') === false) {
            return [];
        }

        $tokens = array_values(array_filter(
            PhpToken::tokenize($contents),
            fn (PhpToken $token): bool => ! $token->is([T_WHITESPACE, T_COMMENT, T_DOC_COMMENT]),
        ));

        $namespace = '';
        $classes = [];

        foreach ($tokens as $i => $token) {
            if ($token->is(T_NAMESPACE) && isset($tokens[$i + 1])) {
                // "namespace { ... }" declares the global namespace.
                $namespace = $tokens[$i + 1]->is([T_NAME_QUALIFIED, T_STRING]) ? $tokens[$i + 1]->text.'\\' : '';
            }

            if (! $token->is(T_CLASS)) {
                continue;
            }

            // Skip `Foo::class` and `new class`.
            if (isset($tokens[$i - 1]) && $tokens[$i - 1]->is([T_DOUBLE_COLON, T_NEW])) {
                continue;
            }

            if (isset($tokens[$i + 1]) && $tokens[$i + 1]->is(T_STRING)) {
                $classes[] = $namespace.$tokens[$i + 1]->text;
            }
        }

        return $classes;
    }

    /**
     * @param  ReflectionClass<FormRequest>  $class
     */
    private function createFormRequestInstance(ReflectionClass $class): FormRequest
    {
        // build() resolves constructor dependencies without firing the container's
        // resolving callbacks, which would otherwise validate the empty request.
        /** @var FormRequest $formRequest */
        $formRequest = app()->build($class->getName());

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
            $user = null;
            $formRequest->setUserResolver(function () use (&$user, $userClass) {
                return $user ??= $userClass::factory()->make();
            });
        } else {
            $formRequest->setUserResolver($request->getUserResolver());
        }

        return $formRequest;
    }

    /**
     * @param  FormRequest[]  $formRequests
     * @return list<TransformedRequest>
     */
    public function transformRequests(array $formRequests): array
    {
        $transformed = [];
        $names = [];

        foreach ($formRequests as $instance) {
            $class = get_class($instance);

            try {
                $rules = method_exists($instance, 'rules') ? app()->call([$instance, 'rules']) : [];
                $tree = $this->buildTree(is_array($rules) ? $rules : []);

                if ($tree === []) {
                    continue;
                }

                $name = $this->formNameFor($class);

                if (isset($names[$name])) {
                    $this->warnings[] = "Skipped {$class}: its generated name {$name} is already used by {$names[$name]}.";

                    continue;
                }

                $form = $this->renderObject($tree, '');
                $transformed[] = ['name' => $name, 'type' => $form['type'], 'initial' => $form['default']];
                $names[$name] = $class;
            } catch (Throwable $e) {
                $this->warnings[] = "Skipped {$class}: {$e->getMessage()}";
            }
        }

        return $transformed;
    }

    private function formNameFor(string $class): string
    {
        // Anonymous classes are named "Parent@anonymous<NUL>/path/to/file.php:line$0".
        $class = Str::before($class, '@anonymous');

        $prefix = app()->getNamespace().'Http\\Requests\\';
        if (str_starts_with($class, $prefix)) {
            $class = substr($class, strlen($prefix));
        }

        return collect(explode('\\', $class))
            ->map(fn (string $segment): string => Str::studly($segment))
            ->implode('');
    }

    /**
     * Turn Laravel's dotted field paths into a tree, so a parent keeps its own rules alongside its children
     * regardless of the order the rules are declared in.
     *
     * @param  array<array-key, mixed>  $rules
     * @return array<string, Node>
     */
    private function buildTree(array $rules): array
    {
        $tree = [];

        foreach ($rules as $field => $ruleSet) {
            $segments = $this->splitPath((string) $field);

            $normalized = $this->normalizeRules($ruleSet);
            $tree = $this->insertNode($tree, $segments, $normalized);

            // "confirmed" validates against another field the form also has to send: "<field>_confirmation"
            // by default, or the full path given as the rule's parameter.
            $confirmation = $this->ruleParameters($normalized, 'confirmed');
            if ($confirmation !== null) {
                if (isset($confirmation[0]) && $confirmation[0] !== '') {
                    $segments = $this->splitPath($confirmation[0]);
                } else {
                    $segments[] = array_pop($segments).'_confirmation';
                }

                if (! $this->hasNode($tree, $segments)) {
                    // The confirmation must match, so it gets the same rules (siblings are only made for declared fields).
                    $tree = $this->insertNode($tree, $segments, $normalized);
                }
            }
        }

        return $tree;
    }

    /**
     * Split a Laravel field path on its dots. Laravel escapes literal dots in field names as "\.".
     *
     * @return list<string>
     */
    private function splitPath(string $field): array
    {
        return array_map(
            fn (string $segment): string => str_replace('\\.', '.', $segment),
            preg_split('/(?<!\\\\)\./', $field) ?: [$field],
        );
    }

    /**
     * @param  array<string, Node>  $children
     * @param  list<string>  $segments
     */
    private function hasNode(array $children, array $segments): bool
    {
        $segment = array_shift($segments);

        if ($segment === null || ! array_key_exists($segment, $children)) {
            return false;
        }

        /** @var array<string, Node> $grandchildren */
        $grandchildren = $children[$segment]['children'];

        return $segments === [] || $this->hasNode($grandchildren, $segments);
    }

    /**
     * @param  array<string, Node>  $children
     * @param  list<string>  $segments
     * @param  list<mixed>  $rules
     * @return array<string, Node>
     */
    private function insertNode(array $children, array $segments, array $rules): array
    {
        $segment = array_shift($segments);

        if ($segment === null) {
            return $children;
        }

        $node = $children[$segment] ?? ['rules' => null, 'children' => []];

        if ($segments === []) {
            $node['rules'] = $rules;
        } else {
            /** @var array<string, Node> $grandchildren */
            $grandchildren = $node['children'];
            $node['children'] = $this->insertNode($grandchildren, $segments, $rules);
        }

        $children[$segment] = $node;

        return $children;
    }

    /**
     * Flatten a rule set into a list of string rules plus the rule objects that need special handling.
     * Laravel's fluent rule objects (Rule::numeric(), Rule::date(), Rule::requiredIf(), ...) are expanded
     * into their string form; the object itself is kept so custom mappings can still match its class.
     *
     * @return list<mixed>
     */
    private function normalizeRules(mixed $ruleSet): array
    {
        $rules = match (true) {
            is_string($ruleSet) => explode('|', $ruleSet),
            is_array($ruleSet) => array_values($ruleSet),
            default => [$ruleSet],
        };

        $normalized = [];

        foreach ($rules as $rule) {
            if (is_string($rule)) {
                // Laravel trims the rule name but not its parameters, so neither do we.
                if (trim($rule) !== '') {
                    $normalized[] = ltrim($rule);
                }

                continue;
            }

            if (! is_object($rule)) {
                continue;
            }

            // Rule::when(): use whichever branch applies to an empty request.
            if ($rule instanceof ConditionalRules) {
                try {
                    $branch = $rule->passes() ? $rule->rules() : $rule->defaultRules();
                } catch (Throwable) {
                    continue;
                }

                array_push($normalized, ...$this->normalizeRules($branch));

                continue;
            }

            $normalized[] = $rule;

            // Laravel 13's fluent rules (Rule::requiredArrayKeys(), Rule::confirmed(), ...) describe
            // themselves as [name, ...parameters] instead of a string.
            if ($rule instanceof ParameterizedRule) {
                [$name, $parameters] = [(string) ($rule->toArray()[0] ?? ''), array_slice($rule->toArray(), 1)];

                $normalized[] = $parameters === [] ? $name : $name.':'.implode(',', array_map(
                    fn (mixed $parameter): string => '"'.str_replace('"', '""', (string) $parameter).'"',
                    $parameters,
                ));

                continue;
            }

            if ($rule instanceof Enum || $rule instanceof Closure) {
                continue;
            }

            // Fluent rules that cannot be cast to a rule string.
            $equivalent = match (true) {
                $rule instanceof FileRule => 'file',
                $rule instanceof Email, $rule instanceof Password => 'string',
                default => null,
            };

            if ($equivalent !== null) {
                $normalized[] = $equivalent;

                continue;
            }

            if ($rule instanceof Stringable) {
                try {
                    $string = (string) $rule;
                } catch (Throwable) {
                    continue;
                }

                // Only these fluent rules join several rules with "|". Any other rule's string can
                // legitimately contain "|" (a regex alternation, an "in" value, a where clause).
                $compound = $rule instanceof ArrayRule || $rule instanceof Date
                    || $rule instanceof Numeric || $rule instanceof StringRule;
                $pieces = $compound ? explode('|', $string) : [$string];

                foreach ($pieces as $piece) {
                    if (trim($piece) !== '') {
                        $normalized[] = ltrim($piece);
                    }
                }
            }
        }

        return $normalized;
    }

    /**
     * Render an object's type and initial value in one pass, so each node is visited once.
     *
     * @param  array<string, Node>  $children
     * @return Field
     */
    private function renderObject(array $children, string $indent): array
    {
        $types = [];
        $defaults = [];

        foreach ($children as $key => $node) {
            $field = $this->nodeField($node, $indent.self::INDENT);
            $prefix = $indent.self::INDENT.$this->propertyKey($key).': ';
            $types[] = $prefix.$field['type'].';';
            $defaults[] = $prefix.$field['default'].',';
        }

        $close = PHP_EOL.$indent.'}';

        return [
            'type' => '{'.PHP_EOL.implode(PHP_EOL, $types).$close,
            'default' => '{'.PHP_EOL.implode(PHP_EOL, $defaults).$close,
        ];
    }

    /**
     * @param  Node  $node
     * @return Field
     */
    private function nodeField(array $node, string $indent, bool $element = false): array
    {
        $rules = $node['rules'] ?? [];
        /** @var array<string, Node> $children */
        $children = $node['children'];

        // "required_array_keys:home,away" makes the value an object with (at least) those keys,
        // each validated by the wildcard rule when there is one.
        foreach ($this->ruleParameters($rules, 'required_array_keys') ?? [] as $key) {
            $children[$key] ??= $children['*'] ?? ['rules' => [], 'children' => []];
        }
        // Index rules ("tags.0") alongside a wildcard refine a list rather than describe an object.
        $named = array_filter(
            array_diff_key($children, ['*' => true]),
            fn (string|int $key): bool => ! (array_key_exists('*', $children) && ctype_digit((string) $key)),
            ARRAY_FILTER_USE_KEY,
        );
        $override = $this->overrideFor($rules);

        if ($children === [] || $override !== null) {
            $field = $this->scalarField($rules);
        } elseif ($named !== []) {
            // Named children ("translations.en") describe an object, even alongside a wildcard.
            $field = $this->renderObject($named, $indent);
        } else {
            $elementType = $this->nodeField($children['*'], $indent, true)['type'];
            $keys = $this->allowedArrayKeys($rules);

            // "array:en,fr" with "translations.*" validates each value of a keyed array.
            $field = $keys !== null
                ? ['type' => 'Record<string, '.$elementType.'>', 'default' => '{}']
                : ['type' => 'Array<'.$elementType.'>', 'default' => '[]'];
        }

        $nullable = $this->hasRule($rules, ['nullable']);

        if ($nullable) {
            $field['default'] = 'null';
        }

        if ($nullable || (! $element && $field['default'] === 'null')) {
            $field['type'] = $this->withMember($field['type'], 'null');
        }

        if ($element) {
            return $field;
        }

        // An untouched "sometimes" field should be left out of the submitted payload.
        if ($this->hasRule($rules, ['sometimes'])) {
            $field = ['type' => $this->withMember($field['type'], 'undefined'), 'default' => 'undefined'];
        }

        return $field;
    }

    /**
     * @param  list<mixed>  $rules
     * @return Field
     */
    private function scalarField(array $rules): array
    {
        $override = $this->overrideFor($rules);
        if ($override !== null) {
            // Function and conditional types bind looser than "|", so wrap them before members are added.
            $type = str_contains($override, '=>') || str_contains($override, ' extends ') ? '('.$override.')' : $override;

            return ['type' => $type, 'default' => match (trim($override)) {
                'string' => "''",
                'number' => '0',
                'boolean' => 'false',
                default => 'null',
            }];
        }

        foreach ($rules as $rule) {
            if ($rule instanceof Enum) {
                return $this->enumField($rule);
            }
        }

        $in = $this->ruleParameters($rules, 'in');
        if ($in !== null) {
            return $this->inField($rules, $in);
        }

        $arrayKeys = $this->allowedArrayKeys($rules);

        return match (true) {
            // "numeric|digits:5" and "numeric|decimal:2" check the written form ("01234", "10.50"), which a
            // number would lose. "integer" rejects leading zeros anyway, so it stays a number.
            $this->hasRule($rules, ['digits', 'digits_between', 'min_digits', 'max_digits', 'decimal']) && ! $this->hasRule($rules, ['integer', 'int']) => ['type' => 'string', 'default' => "''"],
            $this->hasRule($rules, self::NUMBER_RULES) => ['type' => 'number', 'default' => '0'],
            $this->hasRule($rules, self::STRING_RULES) => ['type' => 'string', 'default' => "''"],
            $this->hasRule($rules, self::BOOLEAN_RULES) => ['type' => 'boolean', 'default' => 'false'],
            $this->hasRule($rules, self::FILE_RULES) => ['type' => 'File | null', 'default' => 'null'],
            // "array:a,b" restricts the keys, so the value is an object rather than a list.
            $arrayKeys !== null => ['type' => 'Record<string, any>', 'default' => '{}'],
            $this->hasRule($rules, self::ARRAY_RULES) => ['type' => 'any[]', 'default' => '[]'],
            // A confirmed field with no other type rule is almost always a password or an email.
            $this->hasRule($rules, ['confirmed']) => ['type' => 'string', 'default' => "''"],
            default => ['type' => 'any', 'default' => "''"],
        };
    }

    /**
     * @return Field
     */
    private function enumField(Enum $rule): array
    {
        /** @var class-string<UnitEnum>|mixed $enumClass */
        $enumClass = (fn () => $this->type)->call($rule);

        if (! is_string($enumClass) || ! enum_exists($enumClass)) {
            return ['type' => 'string', 'default' => "''"];
        }

        $type = str_replace('\\', '.', ltrim($enumClass, '\\'));

        $allowed = array_values(array_filter(
            $enumClass::cases(),
            fn (UnitEnum $case): bool => (bool) (fn () => $this->isDesirable($case))->call($rule),
        ));

        if ($allowed === [] || ! property_exists($allowed[0], 'value')) {
            return ['type' => $type, 'default' => 'null'];
        }

        return ['type' => $type, 'default' => $this->literal($allowed[0]->value).' as '.$type];
    }

    /**
     * @param  list<mixed>  $rules
     * @param  list<string>  $values
     * @return Field
     */
    private function inField(array $rules, array $values): array
    {
        if ($values === []) {
            return ['type' => 'string', 'default' => "''"];
        }

        $isArray = $this->hasRule($rules, self::ARRAY_RULES);

        // Only values whose written form survives a round trip through a number ("3", "-1.5", not "1.00").
        $numeric = array_filter(
            $values,
            fn (string $value): bool => is_numeric($value) && is_finite((float) $value) && (string) ($value + 0) === $value
                // JavaScript cannot represent integers beyond 2^53 exactly.
                && abs((float) $value) <= 9007199254740991,
        );

        if ($this->hasRule($rules, self::NUMBER_RULES) && count($numeric) === count($values)) {
            $literals = array_map(fn (string $value): string => is_numeric($value) ? (string) ($value + 0) : '0', $values);
            $type = implode(' | ', array_unique($literals));
            $default = $literals[0];
        } else {
            // Strings, or numbers Laravel only accepts in their written form ("1.00", "007").
            $literals = array_map(fn (string $value): string => $this->literal($value), $values);
            $type = implode(' | ', array_unique($literals));
            $default = $literals[0];
        }

        return $isArray
            ? ['type' => 'Array<'.$type.'>', 'default' => '[]']
            : ['type' => $type, 'default' => $default];
    }

    /**
     * @param  list<mixed>  $rules
     */
    private function overrideFor(array $rules): ?string
    {
        $overrides = [];
        foreach (Config::get('inertia-form-generator.custom_mappings') ?? [] as $key => $type) {
            $overrides[ltrim((string) $key, '\\')] = (string) $type;
        }

        foreach ($rules as $rule) {
            if (! is_object($rule)) {
                continue;
            }

            if (array_key_exists(get_class($rule), $overrides)) {
                return $overrides[get_class($rule)];
            }

            // Also match subclasses, e.g. a mapping for Rules\File applies to File::image().
            foreach ($overrides as $class => $type) {
                if (is_a($rule, $class)) {
                    return $type;
                }
            }
        }

        foreach ($rules as $rule) {
            if (! is_string($rule)) {
                continue;
            }

            if (array_key_exists($rule, $overrides)) {
                return $overrides[$rule];
            }

            if (array_key_exists($this->ruleName($rule), $overrides)) {
                return $overrides[$this->ruleName($rule)];
            }
        }

        return null;
    }

    /**
     * @param  list<mixed>  $rules
     * @param  array<int, string>  $names
     */
    private function hasRule(array $rules, array $names): bool
    {
        return array_any($rules, fn ($rule): bool => is_string($rule) && in_array($this->ruleName($rule), $names, true));
    }

    /**
     * The parameters of the first string rule called $name, parsed the way Laravel parses them,
     * or null when there is no such rule.
     *
     * @param  list<mixed>  $rules
     * @return list<string>|null
     */
    private function ruleParameters(array $rules, string $name): ?array
    {
        foreach ($rules as $rule) {
            if (! is_string($rule) || $this->ruleName($rule) !== $name) {
                continue;
            }

            if (! str_contains($rule, ':') || Str::after($rule, ':') === '') {
                return [];
            }

            return array_map(
                fn (?string $value): string => (string) $value,
                str_getcsv(Str::after($rule, ':'), escape: '\\'),
            );
        }

        return null;
    }

    /**
     * The keys an array may contain, from "array:a,b" or Laravel 13's "array_keys:a,b", or null when unrestricted.
     *
     * @param  list<mixed>  $rules
     * @return list<string>|null
     */
    private function allowedArrayKeys(array $rules): ?array
    {
        $keys = $this->ruleParameters($rules, 'array') ?: $this->ruleParameters($rules, 'array_keys');

        return $keys === [] ? null : $keys;
    }

    private function ruleName(string $rule): string
    {
        return Str::snake(trim(Str::before($rule, ':')));
    }

    /**
     * Add a member to a union type. Only the end of the type is checked: a nested type
     * ("{ a: string | null }", "Array<T | null>") or a literal ("'a | null'") ends in a bracket or quote,
     * so a trailing "| null" can only be a top-level member.
     */
    private function withMember(string $type, string $member): string
    {
        $type = rtrim($type);

        if ($type === $member || preg_match('/\|\s*'.preg_quote($member, '/').'$/', $type)) {
            return $type;
        }

        return $type.' | '.$member;
    }

    private function propertyKey(string $key): string
    {
        return preg_match('/^[A-Za-z_$][A-Za-z0-9_$]*$/', $key) ? $key : $this->literal($key);
    }

    private function literal(string|int|float $value): string
    {
        if (! is_string($value)) {
            return (string) $value;
        }

        return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }
}
