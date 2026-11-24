<?php

namespace App\Console\Commands;

use App\Services\DuplicateLinkRepair;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

#[Signature('links:reconcile-duplicates')]
#[Description('Give Duplicate issues missing their duplicates link the one their activity log records')]
class ReconcileDuplicateLinks extends Command
{
    public function handle(): int
    {
        [$repaired, $skipped] = DuplicateLinkRepair::run();
        $line = "Duplicate links: {$repaired} repaired, {$skipped} skipped (original not recorded, purged, missing, in another workspace or removed on purpose).";
        $this->info($line);
        Log::info($line);

        return self::SUCCESS;
    }
}
