<?php
/**
 * Module: AlsoPurchasedTurbo
 *
 * @requires    Zen Cart 2.2.2 or later, PHP 8.0+ recommended
 * @author      Marcopolo
 * @copyright   2026
 * @license     GNU General Public License (GPL) - https://www.zen-cart.com/license/2_0.txt
 * @version     1.0.0
 * @updated     07-13-2026
 * @github      https://github.com/CcMarc/AlsoPurchasedTurbo
 */
$define = [
    'BOX_TOOLS_ALSO_PURCHASED_TURBO' => 'Also Purchased Turbo',
    'BOX_CONFIGURATION_ALSO_PURCHASED_TURBO' => 'Also Purchased Turbo',

    'APT_HEADING_TITLE' => 'Also Purchased Turbo',
    'APT_HEADING_SUBTITLE' => 'precomputed also-purchased recommendations',

    'APT_PANEL_STATUS' => 'Engine status',
    'APT_PANEL_SHIMS' => 'Template integration',
    'APT_PANEL_DISPLAY_SETTINGS' => 'Display settings (shared Zen Cart settings)',
    'APT_PANEL_PLUGIN_SETTINGS' => 'Plugin settings',
    'APT_PANEL_MAINTENANCE' => 'Maintenance',

    'APT_TEXT_STATUS' => 'Status',
    'APT_TEXT_ENABLED' => 'Enabled',
    'APT_TEXT_DISABLED' => 'Disabled',
    'APT_TEXT_PAIR_ROWS' => 'Pair rows',
    'APT_TEXT_PRODUCTS_COVERED' => 'Products with recommendations',
    'APT_TEXT_SEED_STATE' => 'Historical seed',
    'APT_TEXT_SEED_DONE' => 'Complete',
    'APT_TEXT_SEED_NOT_STARTED_SHORT' => 'Not started',
    'APT_TEXT_SEED_NOT_STARTED' => 'product pages are using the stock-query fallback.',
    'APT_TEXT_SEED_IN_PROGRESS_SHORT' => 'In progress',
    'APT_TEXT_SEED_IN_PROGRESS' => 'next orders_id to process: %s (of max %s).',

    'APT_TEXT_SHIMS_EXPLAIN' => 'Per active template, how the also-purchased data engine is wired in. OK and INTEGRATED both mean this plugin supplies the data.',
    'APT_TEXT_SHIM_OK' => 'OK',
    'APT_TEXT_SHIM_MISSING_SHORT' => 'MISSING',
    'APT_TEXT_SHIM_MISSING' => 'this template is using stock behavior. Use "Repair template shims" below.',
    'APT_TEXT_SHIM_FOREIGN_SHORT' => 'CUSTOMIZED',
    'APT_TEXT_SHIM_FOREIGN' => 'a non-APT customized module is present &mdash; APT is NOT active for this template.',
    'APT_TEXT_SHIM_INTEGRATED_SHORT' => 'INTEGRATED',
    'APT_TEXT_SHIM_INTEGRATED' => 'template ships its own presentation reading this plugin\'s pair table.',

    'APT_TEXT_DISPLAY_SETTINGS_EXPLAIN' => 'These are Zen Cart\'s standard Also Purchased settings (Minimum Values, Maximum Values, and Product Info configuration groups). They apply to the classic module and to this plugin alike; editing here updates the same values.',
    'APT_TEXT_MIN_DISPLAY' => 'Minimum products required to show the box',
    'APT_TEXT_MAX_DISPLAY' => 'Maximum products to display',
    'APT_TEXT_COLUMNS' => 'Columns per row (0 = box off)',
    'APT_BUTTON_SAVE_DISPLAY' => 'Save',
    'APT_TEXT_DISPLAY_SETTINGS_SAVED' => 'Display settings saved.',

    'APT_TEXT_SETTING_ENABLED' => 'Engine enabled (APT_ENABLED)',
    'APT_TEXT_SETTING_RANKING' => 'Ranking (APT_RANKING)',
    'APT_TEXT_SETTING_FALLBACK' => 'Stock-query fallback (APT_FALLBACK_STOCK)',
    'APT_TEXT_SETTING_DEBUG' => 'Debug log (APT_DEBUG_LOG)',
    'APT_BUTTON_EDIT_PLUGIN_SETTINGS' => 'Edit in Configuration &raquo; Also Purchased Turbo',

    'APT_BUTTON_SEED' => 'Seed / resume from order history',
    'APT_HELP_SEED' => 'Builds (or resumes building) the pair table from your existing orders, in chunks that continue automatically. Safe to run any time; already-counted orders are not double-counted unless you rebuild.',
    'APT_BUTTON_REBUILD' => 'Truncate and rebuild from scratch',
    'APT_HELP_REBUILD' => 'Empties the pair table and reseeds from the full order history. Use after importing/deleting orders in bulk, or if you suspect drift from tools that write orders with raw SQL.',
    'APT_BUTTON_PURGE' => 'Purge pairs for deleted products',
    'APT_HELP_PURGE' => 'Removes pair rows that reference products no longer in the catalog. Deleted products never display either way (the storefront query checks the live products table); this just reclaims the rows.',
    'APT_BUTTON_REPAIR_SHIMS' => 'Repair template shims',
    'APT_HELP_REPAIR_SHIMS' => 'Re-creates a missing shim for any active template (e.g. after switching templates). Never overwrites a customized module &mdash; those get an explicit per-template "Back up &amp; take over" button in the Template integration panel.',

    'APT_TEXT_REBUILD_CONFIRM_JS' => 'Truncate the pair table and rebuild from scratch?',
    'APT_TEXT_SEED_GUARD' => 'The pair table already contains %s rows but no seed-progress pointer exists (typically after a reinstall). Seeding now would double-count the existing data, so it was not started. If the table is complete, nothing is needed (status has been set to Complete). To rebuild from scratch, use "Truncate and rebuild".',
    'APT_TEXT_SEED_CHUNK_DONE' => 'Processed orders %s through %s. Continuing automatically&hellip;',
    'APT_TEXT_SEED_COMPLETE' => 'Seeding complete. %s pair rows now cover %s products.',
    'APT_TEXT_REBUILD_RESET' => 'Pair table truncated; starting rebuild from the beginning of order history.',
    'APT_TEXT_PURGED' => 'Removed %s pair rows that referenced deleted products.',
    'APT_TEXT_SHIMS_REPAIRED' => 'Shim repair completed. Review the Template integration panel.',

    'APT_BUTTON_TAKEOVER' => 'Back up &amp; take over',
    'APT_TEXT_TAKEOVER_CONFIRM_JS' => 'Back up the customized module for template %s (restored on uninstall) and install the APT shim in its place?',
    'APT_TEXT_TAKEOVER_OK' => 'Template "%s": customized module backed up as also_purchased_products.pre-APT.php.bak and APT shim installed.',
    'APT_TEXT_TAKEOVER_FAILED' => 'Template "%s": could not back up the customized module.',
    'APT_TEXT_TAKEOVER_BACKUP_EXISTS' => 'Template "%s": a previous .pre-APT.php.bak backup already exists alongside the customized module. Resolve manually before taking over.',
    'APT_TEXT_SHIM_WRITE_FAILED' => 'Backup succeeded but writing the shim to %s failed; the backup was left in place. Check permissions and retry.',
    'APT_TEXT_DIR_NOT_WRITABLE' => 'directory %s is not writable by the web-server user',
    'APT_TEXT_PERMS_UNCLEAR' => 'reason unclear &mdash; check ownership/permissions on %s',
];

return $define;
