# Also Purchased Turbo — Changelog

File headers (`@version` / `@updated`) record the release that LAST MODIFIED
each file, not the current release — same convention as Zen Cart core. Files
unchanged since v1.0.0 intentionally keep their v1.0.0 stamp.

## v1.2.0 (07-26-2026)

Deploy pattern: **Plugin Manager upgrade required** (adds a status-tracking
configuration key).

- NEW: "Optimize table (reclaim disk space)" maintenance action. InnoDB
  keeps deleted rows' space inside the tablespace after pruning; this
  rebuilds the table and shrinks the file on disk, reporting the size and
  space reclaimed. Shows current size and internally reclaimable space in
  the button's help text.
- After a prune that removes 100k+ rows, a message now points at the
  optimize action.
- NEW: "Last optimize" shown in the Tools page status panel — when it ran,
  resulting table size on disk, and space reclaimed.

## v1.1.0 (07-22-2026)

Deploy pattern: **Plugin Manager upgrade required** (adds a configuration
setting). File-swap alone will not seed the new setting.

- NEW: Pair pruning. `APT_MAX_PAIRS_PER_PRODUCT` setting (default 50,
  0 = unlimited) with a chunked, auto-continuing "Prune pair table" tool.
  Keeps only each product's strongest pairs, per the configured ranking.
  On large stores this shrinks the pair table by orders of magnitude and
  reduces the per-product read to the kept set. Motivated by a 167k-order
  production store whose unpruned table reached 40M rows / 4.5 GB.
- NEW: Pruning runs automatically after seeding completes (when a limit
  is set).
- NEW: "Last prune" shown in the Tools page status panel — when it ran and
  the before/after row counts (rows removed, limit used).
- Tools page: prune button with progress, "Max pairs per product" shown in
  the Plugin settings panel.
- Installer: idempotent install/upgrade building blocks (`ensurePairTable`,
  `insertConfigurationKeys`) shared by both paths per Zen Cart
  encapsulated-plugin guidance; `APT_CURRENT_VERSION` constant added.

## v1.0.0 (07-13-2026)

Initial release.

- Precomputed `products_also_purchased` pair table replacing the stock
  `orders_products` self-join on product pages.
- Checkout observer (order-class line-item hook) keeps the table current,
  including admin-created orders.
- Chunked, resumable, auto-continuing historical seeding.
- Affinity / Recency / Random ranking modes.
- Template shim via Zen Cart's module-override mechanism; customized
  modules detected and never overwritten (explicit takeover or
  APT-DATA-CONSUMER integration).
- Admin: Configuration group + Tools page (status, seeding, maintenance,
  shim health, debug log).
