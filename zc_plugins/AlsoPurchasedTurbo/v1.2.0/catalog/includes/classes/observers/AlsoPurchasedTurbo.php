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
// Two responsibilities:
//
// 1. Define APT_MODULE_PATH, the absolute path to this plugin version's
//    also-purchased engine module. The template-directory shim requires this
//    file when the constant is defined; when it isn't (plugin disabled or
//    removed), the shim falls back to the stock module. Because the path is
//    derived from __DIR__, it always points at the *installed* plugin version
//    with no version string baked into the shim.
//
// 2. Maintain the products_also_purchased pair table incrementally. We hook
//    NOTIFY_ORDER_DURING_CREATE_ADDED_PRODUCT_LINE_ITEM -- fired inside
//    order::create() for every inserted line item -- rather than a
//    checkout_process notifier, so any code path that creates orders through
//    the order class (storefront checkout, admin/order-copy tooling, most
//    order-creating plugins) keeps the table current. Each event pairs the
//    newly inserted product, in both directions, with the line items already
//    written for the same order: stateless incremental pairing, no
//    "order complete" event required.
//
class AlsoPurchasedTurbo extends base
{
    protected bool $enabled = false;

    public function __construct()
    {
        if (!defined('APT_MODULE_PATH')) {
            // __DIR__ = .../vX.Y.Z/catalog/includes/classes/observers
            define('APT_MODULE_PATH', dirname(__DIR__, 2) . '/modules/also_purchased_products.php');
        }

        if (defined('APT_ENABLED') && APT_ENABLED === 'true' && defined('TABLE_PRODUCTS_ALSO_PURCHASED')) {
            $this->enabled = true;
            $this->attach($this, [
                'NOTIFY_ORDER_DURING_CREATE_ADDED_PRODUCT_LINE_ITEM',
            ]);
        }
    }

    /**
     * Generic dispatcher (works on every ZC observer implementation).
     *
     * $p1 is array_merge(['orders_products_id' => id, 'i' => i], $sql_data_array)
     * per includes/classes/order.php; $p2 is the orders_products_id.
     */
    public function update(&$class, $eventID, $p1 = [], &$p2 = null, &$p3 = null, &$p4 = null, &$p5 = null)
    {
        if ($eventID === 'NOTIFY_ORDER_DURING_CREATE_ADDED_PRODUCT_LINE_ITEM') {
            $this->recordLineItemPairs(is_array($p1) ? $p1 : []);
        }
    }

    /**
     * Diagnostic logger (shared by the observer and the storefront engine).
     * Writes one JSON line per event to logs/also_purchased_turbo_debug.log
     * when APT_DEBUG_LOG is 'true'; a no-op otherwise.
     */
    public static function debugLog(array $entry): void
    {
        if (!defined('APT_DEBUG_LOG') || APT_DEBUG_LOG !== 'true') {
            return;
        }
        $logDir = defined('DIR_FS_LOGS') ? DIR_FS_LOGS : (DIR_FS_CATALOG . 'logs');
        @file_put_contents(
            $logDir . '/also_purchased_turbo_debug.log',
            json_encode(['ts' => date('c')] + $entry) . "\n",
            FILE_APPEND
        );
    }

    protected function recordLineItemPairs(array $params): void
    {
        global $db;

        if ($this->enabled === false) {
            return;
        }

        $orders_id = (int)($params['orders_id'] ?? 0);
        $orders_products_id = (int)($params['orders_products_id'] ?? 0);
        // order::create stores zen_get_prid() output, but re-derive defensively.
        $products_id = (int)zen_get_prid((string)($params['products_id'] ?? 0));

        if ($orders_id <= 0 || $products_id <= 0 || $orders_products_id <= 0) {
            return;
        }

        // -----
        // If this same product already appears as another line item of this
        // order (same product, different attributes), its pairings were
        // recorded when the first line item arrived; recording again would
        // double-count the pair for a single order.
        //
        $dup = $db->Execute(
            'SELECT 1 FROM ' . TABLE_ORDERS_PRODUCTS . '
              WHERE orders_id = ' . $orders_id . '
                AND products_id = ' . $products_id . '
                AND orders_products_id <> ' . $orders_products_id . '
              LIMIT 1'
        );
        if (!$dup->EOF) {
            self::debugLog(['event' => 'observer_skip_duplicate_product', 'orders_id' => $orders_id, 'products_id' => $products_id]);
            return;
        }

        // -----
        // Pair the new product with every distinct product already inserted
        // for this order, in both directions. Tiny primary-key upserts; a
        // k-item order performs this k times for a total of k*(k-1) upserts.
        //
        $db->Execute(
            'INSERT INTO ' . TABLE_PRODUCTS_ALSO_PURCHASED . '
                (products_id, also_products_id, times_purchased, last_purchased)
             SELECT DISTINCT op.products_id, ' . $products_id . ', 1, NOW()
               FROM ' . TABLE_ORDERS_PRODUCTS . ' op
              WHERE op.orders_id = ' . $orders_id . '
                AND op.products_id <> ' . $products_id . '
                AND op.products_id > 0
                AND op.orders_products_id <> ' . $orders_products_id . '
             ON DUPLICATE KEY UPDATE
                times_purchased = times_purchased + 1,
                last_purchased = NOW()'
        );

        $db->Execute(
            'INSERT INTO ' . TABLE_PRODUCTS_ALSO_PURCHASED . '
                (products_id, also_products_id, times_purchased, last_purchased)
             SELECT DISTINCT ' . $products_id . ', op.products_id, 1, NOW()
               FROM ' . TABLE_ORDERS_PRODUCTS . ' op
              WHERE op.orders_id = ' . $orders_id . '
                AND op.products_id <> ' . $products_id . '
                AND op.products_id > 0
                AND op.orders_products_id <> ' . $orders_products_id . '
             ON DUPLICATE KEY UPDATE
                times_purchased = times_purchased + 1,
                last_purchased = NOW()'
        );

        self::debugLog(['event' => 'observer_pairs_recorded', 'orders_id' => $orders_id, 'products_id' => $products_id]);
    }
}
