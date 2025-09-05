<?php

namespace RobTesch\InertiaFormGenerator\Commands;

use Illuminate\Console\Command;

class InertiaFormGeneratorCommand extends Command
{
    public $signature = 'inertia-form-generator';

    public $description = 'My command';

    public function handle(): int
    {
        $this->comment('All done');

        return self::SUCCESS;
    }
}
