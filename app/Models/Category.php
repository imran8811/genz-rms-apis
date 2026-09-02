<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Category extends Model
{
    protected $fillable = ['name', 'slug', 'type', 'sizes', 'sort_order', 'is_active', 'is_coming_soon'];

    protected $casts = [
        'sizes'         => 'array',
        'is_active'     => 'boolean',
        'is_coming_soon'=> 'boolean',
    ];

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    public function menuItems(): HasMany
    {
        return $this->hasMany(MenuItem::class);
    }
}
