<?php

namespace App\Console\Commands;

use App\Services\MenuImporter;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
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
        $url = $this->option('url') ?: config('genz.admin_menu_url');

        if (! $url) {
            $this->error('No menu feed URL configured. Set ADMIN_MENU_URL in .env or pass --url=');

            return self::FAILURE;
        }

        $this->info("Fetching menu from {$url} …");

        try {
            $response = Http::timeout(20)->acceptJson()->get($url);
        } catch (Throwable $e) {
            $this->error('Request to genz-admin failed: '.$e->getMessage());

            return self::FAILURE;
        }

        if (! $response->successful()) {
            $this->error('Feed returned HTTP '.$response->status());

            return self::FAILURE;
        }

        $menu = $response->json();
        if (! is_array($menu) || ! isset($menu['categories']) || ! is_array($menu['categories'])) {
            $this->error('Unexpected feed format: missing "categories".');

            return self::FAILURE;
        }

        $result = $importer->import($menu);

        $this->info("Synced: {$result['categories']} categories, {$result['items']} items.");

        return self::SUCCESS;
    }
}
