<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class InventoryItem extends Model
{
    protected $fillable = [
        'name', 'supplier', 'unit', 'current_stock', 'min_stock', 'cost_per_unit', 'is_active',
        'pack_size', 'pack_unit', 'pack_price', 'base_unit', 'cost_per_base_unit',
    ];

    protected $casts = [
        'is_active'          => 'boolean',
        'pack_size'          => 'float',
        'pack_price'         => 'float',
        'cost_per_base_unit' => 'float',
    ];

    public function movements(): HasMany
    {
        return $this->hasMany(StockMovement::class);
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

    public function getStatusAttribute(): string
    {
        if ($this->current_stock <= 0) return 'Out';
        if ($this->current_stock < $this->min_stock * 0.5) return 'Critical';
        if ($this->current_stock < $this->min_stock) return 'Low';
        return 'OK';
    }
}
