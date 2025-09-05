<?php

namespace RobTesch\InertiaFormGenerator\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\File;
use RobTesch\InertiaFormGenerator\InertiaFormGenerator;

class InertiaFormGeneratorCommand extends Command
{
    private InertiaFormGenerator $inertiaFormGenerator;

    public function __construct()
    {
        parent::__construct();
        $this->inertiaFormGenerator = new InertiaFormGenerator;
    }

    public $signature = 'inertia-form-generator:generate';

    public $description = 'Generate Inertia form requests from your Laravel FormRequests';

    public function handle(): int
    {
        $formRequests = $this->inertiaFormGenerator->getFormRequests();

        $transformed = $this->inertiaFormGenerator->transformRequests($formRequests);

        $this->exportToFile($transformed);

        $this->info('File exported to '.Config::get('inertia-form-generator.output-file-path'));

        return self::SUCCESS;
    }

    /**
     * @param  list<array{formName: string, typeName: string, type: string, initial: string}>  $transformed
     */
    private function exportToFile(array $transformed): void
    {
        $outFile = Config::get('inertia-form-generator.output-file-path');

        $frontEndProvider = Config::get('inertia-form-generator.front-end-provider', 'vue');

        $fileContents = $this->inertiaFormGenerator->getUseFormImportForProvider($frontEndProvider).PHP_EOL.PHP_EOL;

        foreach ($transformed as $transformedRequest) {
            $fileContents .= 'export type '.$transformedRequest['typeName'].' = '.$transformedRequest['type'].PHP_EOL;
            $fileContents .= 'export const '.$transformedRequest['formName'].' = useForm('.$transformedRequest['initial'].' satisfies '.$transformedRequest['typeName'].');'.PHP_EOL.PHP_EOL;
        }

        File::put($outFile, $fileContents);
    }
}
