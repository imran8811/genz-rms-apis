<?php

return [
    // Canonical menu feed — source of truth is genz-admin (genz-admin-apis).
    // The RMS is now a read-only consumer: `php artisan menu:sync` pulls this
    // feed into the local categories + menu_items mirror so recipes/costing keep
    // referencing menu_items by id.
    'admin_menu_url' => env('ADMIN_MENU_URL', 'https://api.admin.genzfoods.pk/api/public/menu'),

    // Shared secret that genz-web-apis presents (X-Integration-Secret header)
    // when forwarding online orders into the RMS. Must match RMS_INTEGRATION_SECRET
    // on the web-apis side.
    'integration_secret' => env('WEB_INTEGRATION_SECRET'),
];
