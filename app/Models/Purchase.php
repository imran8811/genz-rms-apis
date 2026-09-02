<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Purchase extends Model
{
    protected $fillable = [
        'date', 'item_name', 'inventory_item_id', 'vendor_name', 'rate', 'quantity', 'unit',
        'base_qty', 'base_unit', 'cost_per_base_unit', 'total_amount',
    ];

    protected $casts = [
        'date' => 'date:Y-m-d',
        'rate' => 'decimal:2',
        'quantity' => 'decimal:3',
        'base_qty' => 'decimal:3',
        'cost_per_base_unit' => 'decimal:4',
        'total_amount' => 'decimal:2',
    ];

    /** The ingredient this purchase stocks — the source of the pack size. */
    public function inventoryItem(): BelongsTo
    {
        return $this->belongsTo(InventoryItem::class);
    }
}
