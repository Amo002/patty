<?php

namespace App\Console\Commands;

use App\Support\DemoTools;

class DemoClearCommand extends DemoCommand
{
    protected $signature = 'patty:demo:clear {--force : Skip the confirmation} {--force-env : Run outside the local environment}';

    protected $description = 'Empty everything (migrate:fresh, no seed)';

    protected function warning(): string
    {
        return 'This deletes ALL data, including the stock history. Continue?';
    }

    protected function perform(): ?array
    {
        DemoTools::clear();

        return null;
    }
}
