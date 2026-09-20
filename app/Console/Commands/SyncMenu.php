<?php

namespace App\Console\Commands;

use App\Services\MenuImporter;
use Illuminate\Console\Command;
use Throwable;

/**
 * Pulls the canonical menu from genz-admin's public feed into the RMS mirror.
 * The RMS no longer authors the menu (genz-admin is the source of truth); this
 * keeps a local read-only copy so recipes/costing/inventory keep referencing
 * menu_items by id. Run manually after editing the menu in genz-admin.
 */
class SyncMenu extends Command
{
    protected $signature = 'menu:sync {--url= : Override the genz-admin menu feed URL}';

    protected $description = 'Sync the menu from the genz-admin public feed into the RMS menu mirror.';

    public function handle(MenuImporter $importer): int
    {
        try {
            $result = $importer->importFromFeed($this->option('url') ?: null);
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info("Synced from {$result['url']}: {$result['categories']} categories, {$result['items']} items.");

        return self::SUCCESS;
    }
}
