<?php

namespace Tests\Unit;

use App\Models\InventoryItem;
use App\Services\CostingService;
use App\Services\PurchasePricingService;
use PHPUnit\Framework\TestCase;

/**
 * Purchasing records whole packs; costing consumes grams. These pin down the
 * bridge between the two — and specifically that `quantity` never moves the rate.
 */
class PurchasePricingServiceTest extends TestCase
{
    private PurchasePricingService $pricing;

    protected function setUp(): void
    {
        parent::setUp();
        $this->pricing = new PurchasePricingService(new CostingService);
    }

    /** An ingredient defined once, on the Items tab. */
    private function item(string $baseUnit, ?float $packSize, string $packUnit = 'bottle'): InventoryItem
    {
        return new InventoryItem([
            'name' => 'Chipotle Base',
            'unit' => $baseUnit,
            'base_unit' => $baseUnit,
            'pack_unit' => $packUnit,
            'pack_size' => $packSize,
        ]);
    }

    public function test_pack_unit_divides_the_rate_by_the_predefined_pack_size(): void
    {
        // Rs 1200 a bottle, 1 bottle = 950 g → Rs 1.2632 a gram.
        $item = $this->item('g', 950);

        $this->assertSame(1.2632, $this->pricing->costPerBaseUnit(1200, 'bottle', $item));
    }

    public function test_quantity_does_not_move_the_rate(): void
    {
        $item = $this->item('g', 950);
        $one = $this->pricing->costPerBaseUnit(1200, 'bottle', $item);

        // Buying 2 bottles changes the bill, not the cost of a gram — there is
        // no quantity argument at all, which is the point.
        $this->assertSame($one, $this->pricing->costPerBaseUnit(1200, 'bottle', $item));
        $this->assertSame(1900.0, $this->pricing->baseQty(2, 'bottle', $item));
    }

    public function test_measurement_unit_converts_globally_and_ignores_pack_size(): void
    {
        // Rs 80 a kg on an item packed 25 kg → still Rs 0.08 a gram.
        $item = $this->item('g', 25000, 'bag');

        $this->assertSame(0.08, $this->pricing->costPerBaseUnit(80, 'kg', $item));
        $this->assertSame(5000.0, $this->pricing->baseQty(5, 'kg', $item));
    }

    public function test_spelled_out_units_are_normalized(): void
    {
        $item = $this->item('g', 950);

        $this->assertSame(2.0, $this->pricing->costPerBaseUnit(2, 'gram', $item));
        $this->assertSame(0.002, $this->pricing->costPerBaseUnit(2, 'kgs', $item));
    }

    public function test_piece_items_bought_in_pcs_cost_per_piece(): void
    {
        // Burger bun: base unit pc, Rs 35 each.
        $item = $this->item('pc', 1, 'pcs');

        $this->assertSame(35.0, $this->pricing->costPerBaseUnit(35, 'pcs', $item));
    }

    public function test_pcs_on_a_gram_item_falls_back_to_the_pack_size(): void
    {
        // "pcs" can't convert to grams, so it is treated as a pack unit.
        $item = $this->item('g', 500, 'packet');

        $this->assertSame(0.96, $this->pricing->costPerBaseUnit(480, 'pcs', $item));
    }

    public function test_pack_unit_without_a_pack_size_is_unresolvable(): void
    {
        // Rather than silently cost Rs 1200 a gram, the caller gets null and the
        // controller rejects the purchase.
        $item = $this->item('g', null);

        $this->assertNull($this->pricing->costPerBaseUnit(1200, 'bottle', $item));
        $this->assertNull($this->pricing->baseQty(2, 'bottle', $item));
    }
}
