<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Path for Output TypeScript Definitions File
    |--------------------------------------------------------------------------
    |
    | Defines the file path where the Inertia forms will be saved.
    |
    */
    'output-file-path' => 'resources/js/formRequests.ts',

    /*
    |--------------------------------------------------------------------------
    | Front-end provider
    |--------------------------------------------------------------------------
    |
    | Choose which front-end provider you use.
    | Options: 'vue', 'react', 'svelte4', 'svelte5'
    |
    */
    'front-end-provider' => 'vue',

    /*
    |--------------------------------------------------------------------------
    | User Model
    |--------------------------------------------------------------------------
    |
    | Many form requests require access to the authenticated user.
    | Please ensure you have a user model and user model factory.
    | Set to null if none of your requests need this.
    |
    */
    'user-model' => 'App\Models\User',

    /*
    |--------------------------------------------------------------------------
    | Override or Add New Type Mappings
    |--------------------------------------------------------------------------
    |
    | Custom mappings allow you to add support for types that are considered
    | unknown or override existing mappings. You can also add mappings for your
    | custom rules.
    |
    | Example:
    | 'App\Rules\YourCustomRule' => 'string | null',
    | 'binary' => 'Blob',
    | 'bool' => 'boolean',
    | 'point' => 'CustomPointInterface',
    | 'year' => 'string',
    */
    'custom_mappings' => [],

    /*
    |--------------------------------------------------------------------------
    | Excluded Form Request Classes
    |--------------------------------------------------------------------------
    |
    | There may be some form requests that you do not want to generate
    | TypeScript definitions for. You can add them to this array to exclude
    | them from the output.
    */
    'exclude' => [],
];
