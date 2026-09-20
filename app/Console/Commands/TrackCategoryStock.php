<?php

namespace App\Console\Commands;

use App\Services\CategoryStockLinker;
use App\Services\StockService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Puts a menu category (cold drinks by default) under stock control, then
 * derives its balances. The pairing rules live in CategoryStockLinker, which
 * `GET /api/run-stock-setup` calls too — this host has no shell, so the HTTP
 * route is the one that actually gets used in production.
 */
class TrackCategoryStock extends Command
{
    protected $signature = 'stock:track
        {--category=cold-drinks : Menu category slug to put under stock control}
        {--deal-default=drink-next-cola : Menu item whose bottles deals are served from}
        {--dry-run : Show the pairing without writing anything}
        {--no-rebuild : Skip deriving balances afterwards}';

    protected $description = 'Put a menu category (cold drinks by default) under stock control and derive its balances.';

    public function handle(CategoryStockLinker $linker, StockService $stock): int
    {
        $category = (string) $this->option('category');
        $dryRun = (bool) $this->option('dry-run');

        try {
            $links = $linker->link($category, (string) $this->option('deal-default'), $dryRun);
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->table(
            ['Ingredient', 'Sold as', 'Action', ''],
            array_map(fn ($r) => [
                $r['ingredient'], $r['sold_as'], $r['action'], $r['deal_default'] ? 'deal default' : '',
            ], $links),
        );

        if ($dryRun) {
            $this->warn('Dry run — nothing was written. Drop --dry-run to apply.');

            return self::SUCCESS;
        }

        if ($this->option('no-rebuild')) {
            $this->info('Linked. Run `php artisan stock:rebuild` to derive the balances.');

            return self::SUCCESS;
        }

        $this->newLine();
        $this->info('Deriving balances from '.$stock->openingDate()->toDateString().' onwards …');
        $result = $stock->rebuild();
        $this->info("Replayed {$result['purchases']} deliveries and {$result['orders']} bills.");
        $this->newLine();

        $this->table(
            ['Sold as', 'Ingredient', 'Bought', 'Sold', 'On hand', 'Status'],
            array_map(fn ($r) => [
                $r['sold_as'], $r['ingredient'],
                $this->qty($r['bought']), $this->qty($r['sold']), $this->qty($r['on_hand']), $r['status'],
            ], $linker->balances($category)),
        );

        return self::SUCCESS;
    }

    private function qty(float $n): string
    {
        return rtrim(rtrim(number_format($n, 2, '.', ''), '0'), '.') ?: '0';
    }
}
