# Also Purchased Turbo for Zen Cart

![AlsoPurchasedTurbo](https://socialify.git.ci/CcMarc/AlsoPurchasedTurbo/image?custom_description=Fast+%22also+purchased%22+recommendations+for+Zen+Cart+from+a+precomputed+pair+table&description=1&font=Inter&forks=1&issues=1&language=1&name=1&owner=0&pattern=Signal&pulls=1&stargazers=1&theme=Auto)

Replaces Zen Cart's stock **"Customers who bought this product also
purchased"** engine with a precomputed product-pair table, eliminating the
expensive `orders_products` self-join that runs on every product page view.
On large stores that query is routinely the most expensive statement in the
slow-query log; with APT the read becomes an indexed primary-key lookup that
takes a fraction of a millisecond at any store size.

As a side benefit, recommendations improve: instead of a random window over a
date-sorted list, APT ranks by **purchase affinity** — the products most
often actually bought together — with Recency and Random modes available.

**No core file changes. No template changes.** Your store's existing
also-purchased presentation is preserved; only the data engine underneath is
replaced. Installs via Plugin Manager (encapsulated plugin).

## Benchmarks

Measured with MariaDB `ANALYZE`, warm cache, on the store's most expensive
(most-purchased) products.

**Small store** — ~13,000 `orders_products` rows (author's store):

| Engine | Query time | Row work |
| ------ | ---------- | -------- |
| Stock self-join | 96.4 ms | ~13,000 joined rows, temp table + filesort, ~30,000 index lookups |
| **APT pair table** | **0.37 ms** | 750 indexed rows (8 InnoDB pages) + 12 PK lookups |

**~260x faster.**

**Large store** — 167,000 orders / 2.7 million `orders_products` rows
(community testing by **balihr** — thanks!):

| Test | Engine | Query time | Query cost | Rows examined |
| ---- | ------ | ---------- | ---------- | ------------- |
| Random product | Stock | 0.268 s | 1,313,034 | 2,562,922 |
| Random product | **APT** | **0.014 s** | **171** | **234** |
| Most popular product (46k purchases) | Stock | 0.785 s | 1,314,237 | 2,562,922 |
| Most popular product | **APT** | **0.191 s** | **5,149** | **17,278**¹ |

Roughly **8,000x more efficient** on the random-product case — a ~99.6% drop
in query cost — with no temporary tables and no filesort.

¹ Pre-pruning. v1.1.0's pair pruning caps this read at the configured pair
limit (default 50 rows).

## Admin

Everything lives on two admin pages:

- **Configuration > Also Purchased Turbo** — enable switch, ranking mode,
  stock-query fallback, debug log, pair-prune limit
- **Tools > Also Purchased Turbo** — engine status, template integration
  health, seeding, pruning, maintenance, and the shared display settings

## How it works

1. **A pair table.** `products_also_purchased` stores
   `(products_id, also_products_id, times_purchased, last_purchased)` with a
   composite primary key. It is the only new schema. A configurable pruning
   limit (default 50 pairs per product) keeps only each product's strongest
   pairs — the storefront only ever displays a handful, so pruning shrinks
   the table dramatically with no visible change. Pruning runs automatically
   after seeding and on demand; set the limit to 0 to keep everything.

2. **A checkout observer.** APT hooks
   `NOTIFY_ORDER_DURING_CREATE_ADDED_PRODUCT_LINE_ITEM`, which fires inside
   `order::create()` for every inserted line item — so storefront checkout
   *and* any admin/plugin flow that creates orders through the order class
   keeps the table current. Each line item is paired (in both directions)
   with the items already written for the same order via tiny primary-key
   upserts; a 5-item order adds roughly 20 sub-millisecond upserts in total.
   No meaningful lock exposure at checkout.

3. **A module shim.** Zen Cart's own override mechanism
   (`zen_get_module_directory()`) checks
   `includes/modules/<your_template>/also_purchased_products.php` before the
   stock module. The installer places a 9-line shim there that routes to the
   plugin's engine when the plugin is installed and enabled, and falls back
   to the stock module automatically if the plugin is ever disabled or
   removed. Every wrapper template loads its data through this resolution
   path, which is why custom template presentations keep working untouched.
   A *customized* module file already at that location is left completely
   alone — see "Customized templates" below.

4. **The engine.** Reads the pair table with one indexed query, joins
   `products` to enforce `products_status = 1` at read time, honors the
   standard display settings, and populates the same `$list_box_contents` /
   `$zc_show_also_purchased` output contract as the stock module.

## Installation

1. Copy the `zc_plugins/AlsoPurchasedTurbo/` directory into your store's
   `zc_plugins/` directory.
2. Admin > Modules > Plugin Manager > **Also Purchased Turbo** > Install.
3. Go to **Tools > Also Purchased Turbo** and click
   **"Seed / resume from order history"**. Seeding processes existing orders
   in chunks and continues automatically until complete (then prunes
   automatically); it is safe to leave mid-run and resume later. Until
   seeding completes, product pages transparently use the stock query as a
   fallback, so the storefront never regresses.

> [!NOTE]
> Placing the shim requires `includes/modules/<your_template>/` to be
> writable by the web-server user. If it isn't, the install still succeeds —
> the Tools page shows the template as MISSING with the exact path to fix,
> and "Repair template shims" completes the job afterward.

### Upgrading

Upload the new release's version folder alongside the old one inside
`zc_plugins/AlsoPurchasedTurbo/`, then use Plugin Manager's **Upgrade**
button. Upgrades are idempotent: schema and settings are brought current,
admin-edited values are never touched, and the pair table is never rebuilt
or dropped.

## Settings

| Setting | Default | Purpose |
| ------- | ------- | ------- |
| `APT_ENABLED` | `true` | Master switch; `false` = stock behavior exactly |
| `APT_RANKING` | `Affinity` | `Affinity` (most bought together), `Recency` (closest to stock), or `Random` |
| `APT_FALLBACK_STOCK` | `true` | Run the stock query for products with no pair data (e.g. mid-seed) |
| `APT_DEBUG_LOG` | `false` | JSON diagnostics to `logs/also_purchased_turbo_debug.log` |
| `APT_MAX_PAIRS_PER_PRODUCT` | `50` | Pairs kept per product by the prune tool; `0` = unlimited |

Display counts and column layout continue to honor the stock Zen Cart
settings (`MIN_DISPLAY_ALSO_PURCHASED`, `MAX_DISPLAY_ALSO_PURCHASED`,
columns) — editable in their usual configuration groups or on the Tools page.

## Customized templates

Some templates (and many long-customized stores) ship their own
`includes/modules/<template>/also_purchased_products.php` with custom
product-card markup, extra buttons, or analytics fixes. The installer
**never replaces a customized module** — it reports the template as
CUSTOMIZED on the Tools page and APT stays inactive there until you choose:

**Option A — replace it.** Click **"Back up & take over"** on the Tools
page. Your module is backed up as `also_purchased_products.pre-APT.php.bak`
(restored automatically on uninstall) and APT's stock-style rendering takes
over.

**Option B — integrate it (recommended).** Keep your rendering and swap only
the data query to read APT's pair table:

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

Keep the literal `APT-DATA-CONSUMER` marker comment: the installer,
uninstaller, and Tools page recognize it, leave your module untouched, and
report the template as INTEGRATED. Because every APT reference is wrapped in
`defined()` checks with your original query as the fallback, the integrated
file stands alone — if the plugin is disabled or uninstalled, your module
reverts to its original behavior automatically.

## Compatibility

| Zen Cart | Status |
| -------- | ------ |
| 2.2.2 | ✅ Tested |
| 2.2.0 / 2.2.1 | 📋 Should work (all required APIs present; not yet field-tested) |
| 2.1.0 | 📋 Should work (uses the `Product` class and installer helpers introduced in 2.1.0) |
| 2.0.x and earlier | ❌ Not supported (required installer helpers and `Product` class do not exist) |

- ✅ = tested &nbsp; 📋 = should work, report your results &nbsp; ❌ = not supported

## Uninstall

Plugin Manager > Uninstall removes the configuration group and keys, both
admin pages, and every APT shim (restoring any backed-up customized module).
The pair table is deliberately preserved so a reinstall doesn't require a
reseed. To remove it as well:

```sql
DROP TABLE products_also_purchased;  -- add your DB_PREFIX if you use one
```

## FAQ

**Does it change how the box looks?** No. Presentation is untouched; only
the data source changes. With the default Affinity ranking, the *selection*
of products will typically improve.

**How do I know APT is serving the box?** The Tools page shows each template
as OK or INTEGRATED when APT supplies the data. For proof, enable the debug
log and view a product page: each render logs one JSON line with
`"source":"pair_table"` (or `stock_fallback` / `disabled`).

**How big does the pair table get?** Unpruned, it stores every co-purchase
pair in your order history — on a very large store with big baskets that can
reach millions of rows. With the default prune limit of 50 pairs per product
the table stays bounded at (products × 50) rows worst case, and the
per-product read shrinks accordingly. Pruned pairs that recur in future
orders simply re-enter through the checkout observer. Pruning survivors
follow your configured ranking, so what the storefront displays never
changes.

**What about orders created outside checkout?** Anything that goes through
the order class (including typical admin order-creation tooling) is captured
live. Anything that writes `orders_products` with raw SQL is not — run
**Truncate and rebuild** occasionally if you use such tooling, or don't
worry about it: recommendation drift of a few orders is invisible to
shoppers.

**I switched templates and the Tools page shows the shim as MISSING.** Click
**Repair template shims**. Until then that template simply uses stock
behavior — degraded, never broken.

**Deleted products?** They can never display (the read query joins the live
products table), but their pair rows linger; **Purge pairs for deleted
products** reclaims them.

## Links

- [Zen Cart Plugin Library listing](https://www.zen-cart.com/downloads.php?do=file&id=2443)
- [Support thread on the Zen Cart forums](https://www.zen-cart.com) <!-- TODO: replace with the support thread URL -->
- [Releases](https://github.com/CcMarc/AlsoPurchasedTurbo/releases) — download the latest zip here
- [Changelog](https://github.com/CcMarc/AlsoPurchasedTurbo/blob/main/CHANGELOG.md)

## Credits

- **balihr** — the original optimization idea on the Zen Cart forums, and
  the large-store benchmarks above
- The **Zen Cart** team — the encapsulated-plugin framework this builds on

## License

GNU General Public License v2.0 — https://www.zen-cart.com/license/2_0.txt
