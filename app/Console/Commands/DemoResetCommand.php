<?php

namespace App\Console\Commands;

use App\Support\DemoTools;

class DemoResetCommand extends DemoCommand
{
    protected $signature = 'patty:demo:reset {--force : Skip the confirmation} {--force-env : Run outside the local environment}';

    protected $description = 'Clear everything, then load the demo data';

    protected function warning(): string
    {
        return 'This deletes ALL data and loads the demo restaurant again. Continue?';
    }

    protected function perform(): ?array
    {
        return DemoTools::reset();
    }
}
