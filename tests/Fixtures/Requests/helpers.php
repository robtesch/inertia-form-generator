<?php

// A non-class file: discovery must never execute it. It mentions "class" (and
// Foo::class below) so that discovery has to tokenize it rather than skip it.
$GLOBALS['inertia_form_generator_helpers_loaded'] = true;

if (! function_exists('inertia_form_generator_fixture_helper')) {
    function inertia_form_generator_fixture_helper(): string
    {
        return stdClass::class;
    }
}
