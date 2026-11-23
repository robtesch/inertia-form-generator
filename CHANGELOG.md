# Changelog

All notable changes to `inertia-form-generator` will be documented in this file.

## v4.0.0 - 2026-11-23

### BREAKING

- Each FormRequest now generates a `<Name>FormData` type and a `use<Name>Form(overrides)` factory to call inside your component, instead of a module-level `useForm` call. The old output crashed under React and made Vue/Svelte components share one form. See [UPGRADE.md](https://github.com/robtesch/inertia-form-generator/blob/main/UPGRADE.md) for how to move from 3.x.
- Many generated types and defaults change: `nullable` fields default to `null`, `sometimes` fields to `undefined`, plain `array` to `any[]`/`[]`, and files to `File | null`.
- Removed the facade alias from `composer.json`; it pointed at a class that doesn't exist.

### Added

- Nested objects, arrays of objects, wildcards and escaped dots are typed from dotted rule paths.
- Rule objects (`Rule::in`, `Rule::enum`, `Rule::when`, `File`, `Email`, `Password` and parameterized rules) are supported alongside string rules.
- `confirmed` adds its `_confirmation` field, and `required_array_keys` produces an object with those keys.
- Requests that can't be evaluated are skipped with a warning naming them. If every request fails, the command exits non-zero and leaves the existing file untouched.

### Fixed

- Discovery reads class names without executing files, no longer assumes the `App\` namespace, and `additional_paths` now works.
- Abstract requests are skipped, and constructor and `rules()` dependencies are resolved through the container.
- Unsafe keys and string literals (e.g. `O'Brien`) are quoted correctly; enums no longer crash on unit or empty enums.
- The output path may be relative to the project root, and missing directories are created.

**Full Changelog**: https://github.com/robtesch/inertia-form-generator/compare/v3.0.0...v4.0.0

## v3.0.0 - 2026-11-23

### BREAKING

- Drops support for Laravel 11. The package now requires Laravel 12 or 13.

### Added

- Laravel 13 support.

### Changed

- Updated dev dependencies: Pest 5 / testbench 11 (for Laravel 13), with Pest 4 / testbench 10 still supported for Laravel 12.
- CI now tests against Laravel 12 and 13.

**Full Changelog**: https://github.com/robtesch/inertia-form-generator/compare/v2.2.0...v3.0.0

## Change output style - 2025-09-08

### BREAKING

- Changes the output style to just having forms rather than separate forms and types.

**Full Changelog**: https://github.com/robtesch/inertia-form-generator/compare/v1.0.1...v2.0.0

## v1.0.1 - 2025-09-05

Updated github test runners

**Full Changelog**: https://github.com/robtesch/inertia-form-generator/compare/v1.0.0...v1.0.1

## v1.0.0 - 2025-09-05

Initial release

### What's Changed

* Bump actions/checkout from 4 to 5 by @dependabot[bot] in https://github.com/robtesch/inertia-form-generator/pull/1

### New Contributors

* @dependabot[bot] made their first contribution in https://github.com/robtesch/inertia-form-generator/pull/1

**Full Changelog**: https://github.com/robtesch/inertia-form-generator/commits/v1.0.0

## 1.0.0 - 2025-09-05

- initial release
