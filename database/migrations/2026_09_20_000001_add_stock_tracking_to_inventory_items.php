<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tells stock control which ingredients are actually countable, and what selling
 * one of them means.
 *
 * Most rows in `inventory_items` are raw ingredients (flour, chicken, mayo). They
 * are consumed through recipes, in grams, and nobody counts them off a shelf — so
 * they carry no stock and must not be reported as "Out". Resale goods are the
 * opposite: a bottle of Coke arrives whole and leaves whole, so its balance is
 * simply what was bought minus what was sold.
 *
 * `track_stock` is that distinction, and it is **opt-in**: a new ingredient stays
 * out of stock control until someone deliberately switches it on, so the module
 * never fills up with items showing a balance nobody maintains.
 *
 * `menu_item_id` + `variant` are how a sale finds the item. A sized drink is one
 * physical SKU per size — a 350ml Coke and a 1.5Ltr Coke are different things on
 * different shelves — so the link is to (menu item, size), not to the menu item
 * alone. `variant` is null for single-price items. Names are deliberately *not*
 * the link: the ingredient master calls a bottle "coke 345ml" where the menu
 * calls it "Coca Cola / 350ml", and matching on that would silently never fire.
 *
 * `is_deal_default` covers the drinks that go out inside deals. A deal records
 * "2x Drink 1.5Ltr" and never which flavour, because the deal picker has nowhere
 * to choose one — so those come off whichever item is flagged the default for
 * that size. The bottle count stays right even though the flavour split can't.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory_items', function (Blueprint $table) {
            $table->boolean('track_stock')->default(false)->after('is_active');
            $table->foreignId('menu_item_id')->nullable()->after('track_stock')->constrained()->nullOnDelete();
            $table->string('variant', 50)->nullable()->after('menu_item_id');
            $table->boolean('is_deal_default')->default(false)->after('variant');

            $table->index('track_stock');
            // One stock item per sellable SKU. Rows with no link keep a null
            // menu_item_id, and nulls stay distinct in a unique index, so every
            // untracked ingredient still coexists happily.
            $table->unique(['menu_item_id', 'variant'], 'inventory_items_menu_sku_unique');
        });
    }

    public function down(): void
    {
        Schema::table('inventory_items', function (Blueprint $table) {
            $table->dropUnique('inventory_items_menu_sku_unique');
            $table->dropIndex(['track_stock']);
            $table->dropConstrainedForeignId('menu_item_id');
            $table->dropColumn(['track_stock', 'variant', 'is_deal_default']);
        });
    }
};
