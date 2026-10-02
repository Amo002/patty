<?php

namespace App\Console\Commands;

use App\Support\DemoTools;

class DemoSeedCommand extends DemoCommand
{
    protected $signature = 'patty:demo:seed {--force : Skip the confirmation} {--force-env : Run outside the local environment}';

    protected $description = 'Load the realistic demo data into an empty system';

    protected function warning(): string
    {
        return 'Load the demo restaurant into the empty system?';
    }

    protected function perform(): ?array
    {
        return DemoTools::seed();
    }
}
