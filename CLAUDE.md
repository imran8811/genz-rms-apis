# genz-rms-apis — RMS Backend APIs

Backend REST APIs for the Restaurant Management System (POS). Consumed by
[`genz-rms-fe`](../genz-rms-fe) (the RMS/POS frontend).

- **Stack:** Laravel 13, PHP 8.2+, Laravel Sanctum (token auth), Tinker.
- **Frontend assets:** Vite (`vite.config.js`), `npm` for build tooling.
- **Auth:** Sanctum tokens. The frontend stores the token under `rms_token`.
- **Public API base:** `https://api.rms.genzfoods.pk/api`
- **DB:** MySQL `genz_rms_apis`. Local dev: run on a **separate port** from the
  storefront API, e.g. `php artisan serve --port=8001`.

## Menu = read-only mirror (source of truth is genz-admin)
The menu is **no longer authored here**. The source of truth is **`genz-admin-apis`**. This app
keeps a **read-only mirror** in `categories` + `menu_items` (so recipes/costing/inventory keep
referencing `menu_items` by id) that is refreshed from the admin feed:
- `php artisan menu:sync` (`App\Console\Commands\SyncMenu` + `App\Services\MenuImporter`) pulls
  `ADMIN_MENU_URL` (default `http://localhost:8002/api/public/menu`) and **upserts by slug**
  (stable ids; missing items are deactivated, never deleted — so `recipes.menu_item_id` stays valid).
- **Menu management was removed**: `MenuItemController` + the `menu-items` routes are gone, the old
  `public/menu` publisher feed was removed, and the RMS POS `/menu` page was deleted. The only menu
  endpoint left is **`GET /categories`** (`CategoryController` index/show) — a read feed the POS
  billing screen (`lib/menuStore.ts` → `useMenu`) uses to build orders. Edit the menu in `genz-admin`,
  then run `menu:sync`.
- The `Category` + `MenuItem` models and `menu_items`/`categories` tables remain (the synced mirror);
  recipes/costing/inventory still link to `menu_items` by `menu_item_id` exactly as before.

## Roles: `admin` / `user` / `kitchen`
`users.role` is a plain string (no enum, no migration needed to add one). `admin` unlocks the
admin-only bits (dashboard, costing, deleting staff food logs); `user` is the counter.
**`kitchen` is the terminal in the back and is confined to the orders board** by
`App\Http\Middleware\RestrictKitchenUser`, which wraps the *entire* `auth:sanctum` group in
`routes/api.php`:
- **Deny-by-default allowlist.** A kitchen login may call only `me`, `logout`, `orders/kitchen`,
  `orders/{order}/kitchen-status`, `orders/{order}/eta`, `complaints/kitchen`,
  `complaints/{complaint}/seen` and `complaints` (the register, **read-only** — see below)
  — everything else `403`s with
  *"This account only has access to the kitchen orders board."* A route added to the group later is
  closed to the kitchen until someone puts it on that list, which is the point: the failure mode is
  a locked door, not a leaked one.
- `orders/{order}/eta-request` is **not** on the list — asking "how much longer?" is the front
  desk's job; the kitchen only answers.
- Every other role passes straight through; the middleware only ever narrows `kitchen`.
- Seeded account: `kitchen@genzfoods.pk` / `kitchen1234` (`AuthUsersSeeder`). Covered by
  `tests/Feature/KitchenUserAccessTest.php`.

## The front desk is the only source of kitchen orders
**Only orders rung up at the till reach the kitchen board** — `Order::COUNTER_SOURCES`
(`pos`, `foodpanda`). Every channel the restaurant takes orders on (walk-in, phone, WhatsApp, Food
Panda, the website) is entered at the front desk, and it is *that entry* — not the original channel
— that puts food on the board. `foodpanda` counts as a counter source because it is read off the
Food Panda device and typed into the POS like any other bill.
- An order forwarded in from `genz-web-apis` (`source = web`) **never appears on the kitchen board**
  until the front desk releases it. It waits on the counter's own board while the staff **phone the
  customer to verify it**; only when they send it through does that **same row** appear on the
  kitchen board as a `new` slip and ring like any other. The verification call is why nothing is
  cooked on the strength of a web form alone.
- **It is released, never retyped.** `orders.released_at` (null = waiting, timestamp = sent through)
  is the whole mechanism. Retyping it as a POS bill would give one sale two rows and count the money
  twice; releasing keeps one row, so Sales is right and `source` still shows the online channel.
- `COUNTER_SOURCES` is an **allowlist**, so a source added later (a phone app) waits for a release
  rather than reaching the board because nobody remembered to exclude it — the failure mode is food
  waiting on a button someone can see, not the kitchen cooking an order no one has looked at.
- The split is enforced **in the feed, not the UI**: a kitchen login can call `orders/kitchen`
  directly, so hiding slips in the frontend would not actually keep them off its screen. The rule
  itself lives in `Order::scopeVisibleToKitchen()` / `scopeAwaitingFrontDesk()` — exact complements,
  written once, so the board and the inbox cannot drift into overlapping or into losing an order
  between them.
- `POST /api/orders/{order}/release` — front desk sends an online order to the kitchen. Idempotent
  (two terminals pressing at once must not move the time the kitchen is working to). `422` for a
  counter order or a cancelled one. **`created_at` is left alone** — the customer has been waiting
  since they placed it, and the slip's age should say so rather than restarting at the release.
- `POST /api/orders/{order}/reject` — front desk refuses one (duplicate, hoax, out of stock): sets
  `status = cancelled`, which is what takes it out of Sales (`completed` only). **Only before
  release** — once the kitchen has it, cancelling is a decision about food already cooking and
  belongs on the board. Between them, every online order ends up explicitly sent or refused.
- Both are **off** `RestrictKitchenUser`'s allowlist, so the kitchen can neither see nor release.

## Kitchen orders board
`orders.kitchen_status` (`new` → `received` → `ready`, plus `received_at` / `ready_at`) tracks the
kitchen workflow and is **separate from `orders.status`** (completed/cancelled), which sales
reporting uses. Every new order — POS or Food Panda — starts as `new`.
- `GET  /api/orders/kitchen[?date=YYYY-MM-DD]` — today's non-cancelled **counter-source** orders with
  items, **oldest first**, for the board (`genz-rms-fe` `/orders` polls it every 5s). The front desk
  polls the same feed to raise a desktop notification when an order turns `ready` — no extra
  endpoint or state.
- `GET  /api/orders/counter[?date=YYYY-MM-DD]` — **the counter's board, what `kitchen` is to the
  kitchen**: today's orders that arrived through an integration, in *every* state (waiting on the
  verification call, sent through, cancelled). Same envelope as the kitchen feed. Unlike it, this one
  **keeps cancelled rows** — the counter needs to see what it cancelled — so consumers filter for
  "waiting" themselves with `released_at === null && status !== 'cancelled'`
  (`Order::isAwaitingFrontDesk()` server-side).
- `POST /api/orders/{order}/kitchen-status` `{ kitchen_status }` — kitchen presses OK (`received`)
  or marks it done (`ready`). Idempotent; `ready` backfills `received_at`. It also moves **backwards**
  — the board's **↩ Undo** sends `received` to put a slip marked ready by mistake back on the active
  board (not `new`, which clears every timestamp and would set the new-order alarm off again).

## Preparation time (front desk asks → kitchen answers)
A customer at the counter asks "how much longer?". The front desk raises the question on the slip;
the kitchen answers in minutes. Three columns on `orders`, all on the same kitchen feed:
`eta_requested_at` (question outstanding — null means nothing pending), `eta_minutes` (the answer)
and `eta_set_at` (when it was given, so the front desk counts it *down* instead of re-quoting a
stale number).
- `POST /api/orders/{order}/eta-request` — front desk asks. Stamps `eta_requested_at` and **clears
  the previous answer** (they're asking because the old number stopped helping). `422` if the order
  is already `ready`.
- `POST /api/orders/{order}/eta` `{ minutes }` (0–240) — kitchen answers: sets `eta_minutes` +
  `eta_set_at`, clears `eta_requested_at` (the alarm stops on every board). Answering also moves a
  still-`new` order to `received` — the kitchen is plainly looking at the slip, so leaving the
  new-order alarm ringing behind the answer makes no sense.
- `kitchen-status` keeps them honest: `ready` drops an outstanding question, and stepping back to
  `new` clears all three columns.

## Customer complaints (counter takes them, kitchen is alarmed, counter closes them)
`order_complaints` hangs off an order (`cascadeOnDelete` — a deleted bill has no complaint to
answer) and carries the customer's words verbatim in `body`, plus `logged_by` **and**
`logged_by_name` (snapshotted, so a complaint stays attributable after that login is deleted).
Three states, and the middle one is the point:
- **`new`** — nobody in the back has looked at it. **This is the only state that alarms the kitchen
  board**, where it plays a sound of its own (see `genz-rms-fe` → "Complaints").
- **`seen`** — the kitchen pressed OK. *"We know"*, not *"it's fixed"*: the complaint is still open,
  it has just stopped making noise. Deliberately separate from resolution, because the alarm has to
  stop in seconds while putting a complaint right can take a shift.
- **`resolved`** — the front desk closed it off (refund, remake, phone call), optionally with a note
  in `resolution`. `seen_at` is **backfilled** when the counter resolves one the kitchen never
  acknowledged, so no complaint reads as never-seen-by-anyone.

Endpoints:
- `POST /api/orders/{order}/complaints` `{ body }` — front desk logs one. Nothing is validated about
  *which* order: a complaint about a cancelled or a week-old bill is still a complaint, and refusing
  it would only mean the counter writing it somewhere the kitchen never sees.
- `GET  /api/complaints/kitchen[?date=]` — the board's ⚠ Complaints tab: one day, **every state**,
  newest first. Acknowledged rows stay on it — the tab is also the kitchen's record of what came
  back today, and a complaint that vanished on OK could never be re-read.
- `GET  /api/complaints` — the front desk's register (paginated, newest first, each row carrying its
  order and items). Filters: `status` (`new` / `seen` / `resolved`, or **`open`** for new+seen),
  `order_id`, `date`, `from`/`to`.
- `POST /api/complaints/{complaint}/seen` — kitchen acknowledges. Idempotent (two terminals must not
  move `seen_at`) and it **never moves a complaint backwards** — pressing OK on a resolved one can't
  reopen it.
- `POST /api/complaints/{complaint}/resolve` `{ resolution? }` / `.../reopen` — front desk closes or
  reopens. Reopening returns it to **`seen`, never `new`**: the kitchen has already been told, and
  restarting the alarm over a complaint it acknowledged an hour ago is noise, not news.
- `DELETE /api/complaints/{complaint}` — **admins only** (`403` otherwise) and a **hard delete**:
  `order_complaints` has no soft-delete column, so the row is gone. This is not how a complaint
  normally ends — `resolve` is. It is for the ones that should never have been on the register at
  all (a test entry, a duplicate, one logged against the wrong bill); resolving those instead would
  leave the register overstating how often customers actually complain. Any state may go, `new`
  included — a mistaken complaint is exactly the one the counter wants gone before the kitchen board
  alarms over it.
- **Kitchen allowlist:** `complaints/kitchen`, `complaints/{complaint}/seen` **and `GET
  /complaints`**. The register is on the list because the board's tab is one day deep and *"has this
  dish come back before?"* is a kitchen question — but reading is all it buys. Logging, resolving,
  reopening and deleting all still 403 for a kitchen login: it is *told* about complaints and may
  look them up, it does not administer them. (`GET /complaints` is the read feed; there is no
  separate kitchen-scoped version of it, so a kitchen login sees the same rows the front desk does.)
- `GET /orders` now carries **`complaints_count`** per row (`withCount`), so the Sales list can show
  that a bill came back without loading the complaints themselves.

## Staff: inactive ≠ deleted
Two deliberately different actions — `DELETE /staff/{id}` used to just flip `is_active`, and no
longer does:
- **Inactive** (`is_active = false`) — the *temporary* one. Off the working roster (POS, attendance,
  payroll, new leave/advance/food entries) but still on the staff list, editable, reactivatable,
  history untouched. This is what "removing" someone normally means.
- **Deleted** — `$staff->delete()`, and it is **permanent and cascading**: `staff_attendance`,
  `staff_leaves`, `staff_advances` and `staff_food_logs` are all `cascadeOnDelete`, so that person's
  records disappear from past attendance summaries, food reports and payroll too. Reserved for
  someone gone for good whose records aren't wanted anywhere. No undo, no soft-delete column.

`GET /staff` returns active **+** inactive; `GET /staff?active=1` is the working roster.

## Late fines (charged by marking attendance)
A check-in more than the grace period past the staff member's shift start costs a fine, written to
`staff_fines` **as a side-effect of `POST /staff-attendance/bulk`** — there is nothing to run
afterwards and no separate "apply fines" step. `App\Services\LateFineService` holds the whole rule;
defaults are **Rs 200**, **30 minutes' grace**, fallback shift start **14:00**, so a 2PM shift is
fined from **14:31**. All are `settings` rows (`late_fine_amount`, `late_fine_grace_minutes`,
`late_fine_shift_start`, `restaurant_timezone`) writable through `PUT /settings`.
- **Marking someone in *is* the check-in.** The counter marks each person as they arrive, so a
  record saved with no `check_in_time` is **stamped with the moment of marking** and judged on that
  — nobody types times in normal use. A typed time always wins (that's how a wrong stamp is fixed),
  `absent` rows are never stamped, and **past dates are never stamped** (back-filling last week must
  not borrow today's clock), so retrospective fines need the time typed in.
- **The stamp marks a transition, not a save.** Only someone *not already marked in* is stamped:
  a row already on the sheet as present/late/half_day keeps its time (even a null one, as every row
  saved before this feature has), so pressing Save again at 17:45 can't re-stamp the roster and fine
  everybody. That regression is pinned by `test_re_saving_does_not_stamp_someone_already_marked_in`.
  Flipping someone from `absent` to present later *is* a transition, and stamps that moment.
  A stored time also survives a save that doesn't echo it back, so a stale tab can't wipe it.
- **⚠ The app runs on UTC** (`config/app.php`) while the restaurant is UTC+5. Never compare a raw
  `now()` against a shift time — 2:31pm Karachi is 09:31 UTC, which reads as *early* and silently
  fines nobody. `LateFineService::localNow()` is the only correct clock; it applies the
  `restaurant_timezone` setting (default `Asia/Karachi`).
- **The shift start is per staff member:** parsed off `staff.shift` (`2PM–2AM` → 14:00, `5PM–2AM` →
  17:00) so a 5PM starter isn't fined against a 2PM clock. Shifts with no time in them ("Full Day",
  "Morning") and staff with no shift fall back to the setting.
- **`sync()` owns the `auto_late` row for a (staff, date) pair.** Re-saving the same day re-prices
  the fine instead of stacking a second one; correcting the check-in time back inside the grace
  period, clearing it, or marking the person `absent` **deletes** the fine. `source = manual` rows
  are never touched by the sync.
- `POST /staff-attendance/bulk` now answers `{ records, fines }` — `fines` is what that save just
  charged, so the frontend can show it. (It used to return the bare records array.)
- CRUD: `GET/POST /staff-fines`, `PUT|DELETE /staff-fines/{id}`, plus `GET /staff-fines/rule`
  (amount + grace + fallback shift start) so the UI quotes the same numbers the sync charges.
  `GET` filters: `month=YYYY-MM` (on `fine_date`), `staff_id`, `source`, `from`/`to`.
- **Payroll:** fines are deducted in the month their `fine_date` falls in (no carry-forward as with
  advances) — `fine_deduct` on every `GET /staff-payroll` row, inside `total_deductions`.
- Editing an `auto_late` fine by hand is allowed but not durable: the next save of that day's
  attendance overwrites it. The real fix is correcting the check-in time.

**Staff food entries are admin-delete-only:** anyone logged in may add a `staff_food_logs` entry
(the counter logs meals as they happen), but `DELETE /staff-food/{id}` `403`s for non-admins —
`users.role !== 'admin'` can't take a meal back off the record. The RMS frontend hides the food
table's Actions column entirely for non-admins; the controller check is the actual gate.

## Layout

- `routes/api.php` — API endpoints (primary surface).
- `app/` — controllers, models, services (PSR-4 `App\`).
- `database/` — migrations, factories, seeders.
- `config/`, `.env` — configuration. Copy `.env.example` → `.env` for setup.
- `tests/` — PHPUnit tests (PSR-4 `Tests\`).

## Common commands

PHP is installed at `C:\php83` (use it if `php` is not on PATH).

```bash
composer run setup       # install, copy .env, key:generate, migrate, npm build
composer run dev         # serve + queue listener + pail logs + vite (concurrently)
php artisan serve        # API server only
php artisan migrate      # run migrations
composer run test        # config:clear + artisan test (PHPUnit)
./vendor/bin/pint        # code style (Laravel Pint)
```

## Conventions

- Standard Laravel structure and conventions.
- Format with **Laravel Pint** before committing.
- Keep API routes in `routes/api.php`; protect them with Sanctum middleware.
