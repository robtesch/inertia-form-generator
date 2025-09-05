<?php

namespace RobTesch\InertiaFormGenerator\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @see \RobTesch\InertiaFormGenerator\InertiaFormGenerator
 */
class InertiaFormGenerator extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \RobTesch\InertiaFormGenerator\InertiaFormGenerator::class;
    }
}
