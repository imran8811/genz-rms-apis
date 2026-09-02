<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RecipeLine extends Model
{
    protected $fillable = [
        'recipe_id', 'component_type', 'inventory_item_id', 'sub_recipe_id',
        'overhead_key', 'label', 'qty', 'unit', 'flat_cost', 'waste_pct', 'sort_order',
    ];

    protected $casts = [
        'qty'        => 'float',
        'flat_cost'  => 'float',
        'waste_pct'  => 'float',
        'sort_order' => 'integer',
    ];

    public function recipe(): BelongsTo
    {
        return $this->belongsTo(Recipe::class);
    }

    public function inventoryItem(): BelongsTo
    {
        return $this->belongsTo(InventoryItem::class);
    }

    public function subRecipe(): BelongsTo
    {
        return $this->belongsTo(Recipe::class, 'sub_recipe_id');
    }
}
