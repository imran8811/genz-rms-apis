<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StockMovement extends Model
{
    protected $fillable = [
        'inventory_item_id', 'type', 'quantity', 'stock_after', 'reference', 'note',
        'source_type', 'source_id', 'occurred_at',
    ];

    protected $casts = ['occurred_at' => 'datetime'];

    public function inventoryItem(): BelongsTo
    {
        return $this->belongsTo(InventoryItem::class);
    }
}
