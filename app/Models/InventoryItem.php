<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class InventoryItem extends Model
{
    protected $fillable = [
        'name', 'supplier', 'unit', 'current_stock', 'min_stock', 'cost_per_unit', 'is_active',
        'pack_size', 'pack_unit', 'pack_price', 'base_unit', 'cost_per_base_unit',
        'track_stock', 'menu_item_id', 'variant', 'is_deal_default',
    ];

    protected $casts = [
        'is_active'          => 'boolean',
        'pack_size'          => 'float',
        'pack_price'         => 'float',
        'cost_per_base_unit' => 'float',
        'track_stock'        => 'boolean',
        'is_deal_default'    => 'boolean',
    ];

    public function movements(): HasMany
    {
        return $this->hasMany(StockMovement::class);
    }

    /** The menu SKU this item is sold as — see the `track_stock` migration. */
    public function menuItem(): BelongsTo
    {
        return $this->belongsTo(MenuItem::class);
    }

    /** Items under stock control. Raw recipe ingredients are deliberately not. */
    public function scopeTracked(Builder $query): Builder
    {
        return $query->where('track_stock', true);
    }

    /**
     * Everything this shelf took in / let out, by movement type.
     *
     * Reported beside the balance because a bare "50 left" invites exactly the
     * question this answers — how much was bought, and how much went out.
     */
    public function movementTotal(string $type): float
    {
        return (float) $this->movements()->where('type', $type)->sum('quantity');
    }

    /**
     * Cost per base unit (per gram / ml / piece). Prefer the precise pack-derived
     * value; fall back to the legacy integer cost_per_unit so older rows still work.
     */
    public function getUnitCostAttribute(): float
    {
        if ($this->pack_size && $this->pack_price !== null && $this->pack_size > 0) {
            return round($this->pack_price / $this->pack_size, 4);
        }
        if ($this->cost_per_base_unit !== null) {
            return (float) $this->cost_per_base_unit;
        }
        return (float) $this->cost_per_unit;
    }

    /**
     * An ingredient nobody counts has no stock level to be wrong about.
     *
     * Reporting the other ~90 rows as "Out" because their balance sits at its
     * default zero is what made the whole module unreadable: a real shortage was
     * indistinguishable from an item that was never meant to carry stock.
     */
    public function getStatusAttribute(): string
    {
        if (! $this->track_stock) return 'Untracked';
        if ($this->current_stock <= 0) return 'Out';
        if ($this->current_stock < $this->min_stock * 0.5) return 'Critical';
        if ($this->current_stock < $this->min_stock) return 'Low';
        return 'OK';
    }
}
