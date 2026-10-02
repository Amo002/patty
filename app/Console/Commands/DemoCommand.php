<?php

namespace App\Console\Commands;

use App\Exceptions\Domain\DemoNotEmpty;
use App\Support\DemoTools;
use Illuminate\Console\Command;

/**
 * Shared guard for the three patty:demo:* commands (D-037, S13): confirm unless --force,
 * and refuse outside local unless --force-env, because clearing drops every table.
 */
abstract class DemoCommand extends Command
{
    /** What the confirmation asks, in plain words. */
    abstract protected function warning(): string;

    /**
     * The work itself.
     *
     * @return array<string, int>|null the counts to print, or null
     */
    abstract protected function perform(): ?array;

    public function handle(): int
    {
        if (! DemoTools::enabled() && ! $this->option('force-env')) {
            $this->components->error('Refused: the demo tools run only when APP_ENV=local. Pass --force-env if you really mean it.');

            return self::FAILURE;
        }

        if (! $this->option('force') && ! $this->confirm($this->warning())) {
            $this->components->warn('Cancelled. Nothing was changed.');

            return self::FAILURE;
        }

        try {
            $counts = $this->perform();
        } catch (DemoNotEmpty $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        if ($counts !== null) {
            $this->table(['Table', 'Rows'], collect($counts)->map(fn ($n, $name) => [$name, $n])->values()->all());
        }

        $this->components->info('Done.');

        return self::SUCCESS;
    }
}
