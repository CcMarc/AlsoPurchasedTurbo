# Also Purchased Turbo (APT) — v1.0.0

Replaces Zen Cart's stock **"Customers who bought this product also
purchased"** engine with a precomputed product-pair table, eliminating the
expensive `orders_products` self-join that runs on every product page view.
On large stores (hundreds of thousands of orders, millions of
`orders_products` rows) that stock query is routinely the most expensive
statement in the slow-query log. With APT the read becomes an indexed
primary-key range scan that takes a fraction of a millisecond at any store
size.

As a side benefit, recommendations improve: instead of a random window over a
date-sorted list, APT ranks by **purchase affinity** — the products most often
actually bought together — with Recency and Random modes available.

- **Requires:** Zen Cart 2.x. Installed via Plugin Manager (encapsulated
  plugin).
- **No core file changes. No template changes.** Your store's existing
  also-purchased presentation is preserved; only the data engine underneath
  is replaced.
- **Admin:** Configuration > Also Purchased Turbo (enable, ranking, fallback,
  debug log) and Tools > Also Purchased Turbo (status, seeding, maintenance,
  display settings).

## How it works

1. **A pair table.** `products_also_purchased` stores
   `(products_id, also_products_id, times_purchased, last_purchased)` with a
   composite primary key. It is the only new schema.

2. **A checkout observer.** APT hooks
   `NOTIFY_ORDER_DURING_CREATE_ADDED_PRODUCT_LINE_ITEM`, which fires inside
   `order::create()` for every inserted line item — so storefront checkout
   *and* any admin/plugin flow that creates orders through the order class
   keeps the table current. Each line item is paired (in both directions)
   with the items already written for the same order via tiny primary-key
   upserts; a 5-item order adds roughly 20 sub-millisecond upserts in total.
   There is no meaningful lock exposure at checkout.

3. **A module shim.** Zen Cart's own override mechanism
   (`zen_get_module_directory()`) checks
   `includes/modules/<your_template>/also_purchased_products.php` before the
   stock module. The installer places a 9-line shim there that routes to the
   plugin's engine when the plugin is installed and enabled, and falls back
   to the stock module automatically if the plugin is ever disabled or
   removed while the shim remains. Every wrapper template loads its data
   through this resolution path, which is why custom template presentations
   keep working untouched.

   If a *customized* module file already occupies that location, the
   installer leaves it completely alone and tells you — see
   "If your template has a customized also_purchased_products.php" below.

4. **The engine.** The plugin's replacement module reads the pair table with
   one indexed query, joins `products` to enforce `products_status = 1` at
   read time, honors the standard display settings
   (`MIN_DISPLAY_ALSO_PURCHASED`, `MAX_DISPLAY_ALSO_PURCHASED`, columns per
   row), and populates the same `$list_box_contents` /
   `$zc_show_also_purchased` output contract as the stock module.

## Installation

1. Copy the `zc_plugins/AlsoPurchasedTurbo/` directory into your store's
   `zc_plugins/` directory.
2. Admin > Modules > Plugin Manager > **Also Purchased Turbo** > Install.
3. Go to **Tools > Also Purchased Turbo** and click
   **"Seed / resume from order history"**. Seeding processes your existing
   orders in chunks and continues automatically until complete; it is safe to
   leave the page mid-run and resume later. Until seeding completes, product
   pages transparently use the stock query as a fallback, so the storefront
   never regresses.

Note: placing the shim requires `includes/modules/<your_template>/` to be
writable by the web-server user. If it isn't, the install still succeeds —
the Tools page shows the template as MISSING with the exact path to fix, and
"Repair template shims" completes the job afterward.

## If your template has a customized also_purchased_products.php

Some templates (and many long-customized stores) ship their own
`includes/modules/<your_template>/also_purchased_products.php` with custom
product-card markup, extra buttons, or analytics fixes. The installer
**never replaces a customized module** — it reports the template as
CUSTOMIZED on the Tools page and APT stays inactive for that template until
you choose one of two options:

**Option A — replace it (switch to APT's stock-style rendering).** On
Tools > Also Purchased Turbo, click **"Back up & take over"** next to the
template. Your module is backed up as
`also_purchased_products.pre-APT.php.bak` (restored automatically on
uninstall) and APT's engine takes over. Choose this if your customization was
minor or you don't need it anymore.

**Option B — integrate it (keep YOUR rendering, recommended).** Keep your
module and swap only its data query to read APT's pair table. Your markup,
buttons, and fixes are untouched; only the expensive self-join is replaced.

1. In your module, find the block that builds and executes the also-purchased
   SQL (the `orders_products` self-join) and replace it following this
   pattern — adjust the SELECT columns to whatever your rendering loop uses:

```php
// APT-DATA-CONSUMER
$also_purchased_products = false;
$apt_active = (defined('APT_ENABLED') && APT_ENABLED === 'true' && defined('TABLE_PRODUCTS_ALSO_PURCHASED'));
if ($apt_active) {
    switch (defined('APT_RANKING') ? APT_RANKING : 'Affinity') {
        case 'Recency':  $apt_order_by = 'ap.last_purchased DESC, ap.times_purchased DESC, ap.also_products_id'; break;
        case 'Random':   $apt_order_by = 'RAND()'; break;
        default:         $apt_order_by = 'ap.times_purchased DESC, ap.last_purchased DESC, ap.also_products_id'; break;
    }
    $also_purchased_products = $db->Execute(
        "SELECT p.products_id, p.products_image, pd.products_name, ap.last_purchased AS date_purchased
           /* add any other p.* columns your rendering loop reads */
           FROM " . TABLE_PRODUCTS_ALSO_PURCHASED . " ap
                INNER JOIN " . TABLE_PRODUCTS . " p
                    ON p.products_id = ap.also_products_id AND p.products_status = 1
                INNER JOIN " . TABLE_PRODUCTS_DESCRIPTION . " pd
                    ON pd.products_id = p.products_id AND pd.language_id = " . (int)$_SESSION['languages_id'] . "
          WHERE ap.products_id = " . (int)$_GET['products_id'] . "
          ORDER BY " . $apt_order_by . "
          LIMIT " . (int)MAX_DISPLAY_ALSO_PURCHASED
    );
}
if ($also_purchased_products === false || ($also_purchased_products->RecordCount() === 0 && (!$apt_active || (defined('APT_FALLBACK_STOCK') && APT_FALLBACK_STOCK === 'true')))) {
    // ... your ORIGINAL query goes here, unchanged, as the fallback ...
}
```

2. Keep the literal marker comment `APT-DATA-CONSUMER` in the file. The
   installer, uninstaller, and Tools page recognize it: your module is left
   untouched and the template is reported as INTEGRATED.
3. Your rendering loop below the query needs no changes as long as the SELECT
   returns the columns it reads.

Because every APT reference is wrapped in `defined()` checks with your
original query as the fallback, the integrated file stands alone — if the
plugin is later disabled or uninstalled, your module reverts to its original
behavior automatically.

## Uninstall

Plugin Manager > Uninstall removes the configuration group and keys, both
admin pages, and every APT shim (restoring any backed-up customized module).
The pair table is deliberately preserved so a reinstall doesn't require a
reseed. To remove it as well:

```sql
DROP TABLE products_also_purchased;  -- add your DB_PREFIX if you use one
```

## FAQ

**Does it change how the box looks?** No. Presentation is untouched; only the
data source changes. With the default Affinity ranking, the *selection* of
products will typically improve.

**How do I know APT is serving the box?** The Tools page shows each
template as OK or INTEGRATED when APT supplies the data. For proof, enable
the debug log and view a product page: each render logs one JSON line with
`"source":"pair_table"` (or `stock_fallback` / `disabled`).

**What about orders created outside checkout?** Anything that goes through
the order class (including typical admin order-creation tooling) is captured
live. Anything that writes `orders_products` with raw SQL is not — run
**Truncate and rebuild** occasionally if you use such tooling, or don't worry
about it: recommendation drift of a few orders is invisible to shoppers.

**I switched templates and the Tools page shows the shim as MISSING.** Click
**Repair template shims**. Until then that template simply uses stock
behavior — degraded, never broken.

**Deleted products?** They can never display (the read query joins the live
products table), but their pair rows linger; **Purge pairs for deleted
products** reclaims them.

## License

GNU General Public License v2.0 — https://www.zen-cart.com/license/2_0.txt
