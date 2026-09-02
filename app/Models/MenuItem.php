<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MenuItem extends Model
{
    protected $fillable = [
        'category_id', 'name', 'slug', 'description', 'price_type',
        'price', 'prices', 'pizza_selection', 'deal_extras', 'default_size',
        'tag', 'is_special', 'is_signature', 'is_active', 'sort_order',
    ];

    protected $casts = [
        'prices'          => 'array',
        'pizza_selection' => 'array',
        'deal_extras'     => 'array',
        'is_special'      => 'boolean',
        'is_signature'    => 'boolean',
        'is_active'       => 'boolean',
    ];

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }
}
