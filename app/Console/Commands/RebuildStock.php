<?php

namespace App\Console\Commands;

use App\Models\InventoryItem;
use App\Services\StockService;
use Illuminate\Console\Command;

/**
 * Re-derives every tracked balance from the purchases and bills on file.
 *
 * Stock is a replay of the ledger rather than a running total (see StockService),
 * so this is safe to run at any time and always lands on the same answer. Run it
 * after switching an item's tracking on, after linking one to a menu SKU, or
 * whenever a balance is doubted — re-deriving costs nothing and settles it.
 */
class RebuildStock extends Command
{
    protected $signature = 'stock:rebuild';

    protected $description = 'Re-derive every tracked stock balance from purchases and sales.';

    public function handle(StockService $stock): int
    {
        $tracked = InventoryItem::tracked()->where('is_active', true)->count();

        if ($tracked === 0) {
            $this->warn('No items are under stock control yet.');
            $this->line('Switch "Track stock" on for the items you actually count — cold drinks and');
            $this->line('other resale goods — and link each one to its menu item and size first.');

            return self::SUCCESS;
        }

        $this->info('Rebuilding stock from '.$stock->openingDate()->toDateString().' onwards …');

        $result = $stock->rebuild();

        $this->info(sprintf(
            'Replayed %d deliveries and %d bills across %d items.',
            $result['purchases'],
            $result['orders'],
            $result['items'],
        ));

        $rows = InventoryItem::tracked()->where('is_active', true)
            ->orderBy('name')
            ->get(['name', 'current_stock', 'min_stock', 'unit'])
            ->map(fn (InventoryItem $i) => [
                $i->name,
                rtrim(rtrim(number_format((float) $i->current_stock, 2), '0'), '.').' '.$i->unit,
                $i->status,
            ]);

        $this->table(['Item', 'Stock', 'Status'], $rows);

        return self::SUCCESS;
    }
}
