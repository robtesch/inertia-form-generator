<?php

namespace RobTesch\InertiaFormGenerator;

use RobTesch\InertiaFormGenerator\Commands\InertiaFormGeneratorCommand;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

class InertiaFormGeneratorServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        /*
         * This class is a Package Service Provider
         *
         * More info: https://github.com/spatie/laravel-package-tools
         */
        $package
            ->name('inertia-form-generator')
            ->hasConfigFile()
            ->hasViews()
            ->hasMigration('create_inertia_form_generator_table')
            ->hasCommand(InertiaFormGeneratorCommand::class);
    }
}
