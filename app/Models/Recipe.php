<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Recipe extends Model
{
    protected $fillable = [
        'kind', 'name', 'category', 'menu_item_id', 'variant',
        'sell_price', 'yield_qty', 'yield_unit', 'notes', 'is_active',
    ];

    protected $casts = [
        'sell_price' => 'float',
        'yield_qty'  => 'float',
        'is_active'  => 'boolean',
    ];

    public function lines(): HasMany
    {
        return $this->hasMany(RecipeLine::class)->orderBy('sort_order')->orderBy('id');
    }

    public function menuItem(): BelongsTo
    {
        return $this->belongsTo(MenuItem::class);
    }
}
