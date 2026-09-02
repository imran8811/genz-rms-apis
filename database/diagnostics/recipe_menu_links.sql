-- Which recipe is pinned to which menu item, and what its lines actually are.
--
-- The Costing grid draws its cards from the LIVE genz-admin feed but matches
-- recipes onto them via recipes.menu_item_id -> menu_items.slug (the RMS's
-- synced mirror). If a deal slug was repurposed between menu revisions, an old
-- recipe stays pinned to that slug and surfaces under the new card.
--
-- Run in phpMyAdmin against genz_rms_apis.

-- 1) Every deal recipe with the menu item it is pinned to.
SELECT  r.id            AS recipe_id,
        r.name          AS recipe_name,
        r.category      AS recipe_category,
        r.variant,
        m.slug          AS linked_slug,
        m.name          AS linked_menu_name,
        c.name          AS linked_menu_category
FROM    recipes r
LEFT JOIN menu_items m ON m.id = r.menu_item_id
LEFT JOIN categories c ON c.id = m.category_id
WHERE   r.is_active = 1
  AND  (r.category LIKE '%deal%' OR c.name LIKE '%Deal%')
ORDER BY c.name, m.slug;

-- 2) Mismatches: the recipe's own category disagrees with the category of the
--    menu item it is linked to. These are the rows that render under the wrong card.
SELECT  r.id AS recipe_id, r.name AS recipe_name, r.category AS recipe_category,
        m.slug AS linked_slug, m.name AS linked_menu_name, c.name AS linked_menu_category
FROM    recipes r
JOIN    menu_items m ON m.id = r.menu_item_id
JOIN    categories c ON c.id = m.category_id
WHERE   r.is_active = 1
  AND   LOWER(c.name) NOT LIKE CONCAT('%', LOWER(r.category), '%')
ORDER BY c.name;

-- 3) What Deal 10 is actually costing right now — the lines behind the modal.
SELECT  r.id AS recipe_id, r.name AS recipe_name,
        m.slug, m.name AS menu_name,
        rl.component_type, rl.label, rl.qty, rl.unit,
        i.name AS ingredient, sr.name AS sub_recipe
FROM    recipes r
JOIN    menu_items m      ON m.id = r.menu_item_id
LEFT JOIN recipe_lines rl ON rl.recipe_id = r.id
LEFT JOIN inventory_items i ON i.id = rl.inventory_item_id
LEFT JOIN recipes sr        ON sr.id = rl.sub_recipe_id
WHERE   m.slug = 'deal-10'
ORDER BY rl.id;
