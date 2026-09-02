<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Category;
use Illuminate\Http\JsonResponse;

/**
 * Read-only category feed for the POS billing screen. The menu is authored in
 * genz-admin and synced here via `php artisan menu:sync` — this controller only
 * exposes the local read-only mirror (no CRUD; menu management was removed).
 */
class CategoryController extends Controller
{
    public function index(): JsonResponse
    {
        $categories = Category::with(['menuItems' => fn ($q) => $q->where('is_active', true)->orderBy('sort_order')])
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->get();

        return response()->json($categories->map(fn ($cat) => [
            'id'         => $cat->slug,
            'name'       => $cat->name,
            'type'       => $cat->type,
            'sizes'      => $cat->sizes,
            'comingSoon' => $cat->is_coming_soon,
            'items'      => $cat->menuItems->map(fn ($item) => array_filter([
                'id'             => $item->slug,
                'name'           => $item->name,
                'description'    => $item->description,
                'price'          => $item->price,
                'prices'         => $item->prices,
                'tag'            => $item->tag,
                'special'        => $item->is_special ?: null,
                'signature'      => $item->is_signature ?: null,
                'pizzaSelection' => $item->pizza_selection,
                'dealExtras'     => $item->deal_extras,
                'defaultSize'    => $item->default_size,
            ], fn ($v) => $v !== null)),
        ]));
    }

    public function show(Category $category): JsonResponse
    {
        return response()->json($category->load('menuItems'));
    }
}
