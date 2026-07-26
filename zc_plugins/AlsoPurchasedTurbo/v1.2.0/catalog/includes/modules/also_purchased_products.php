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
// This is the replacement for includes/modules/also_purchased_products.php.
// It is required by the small shim the installer places at
// includes/modules/<template_dir>/also_purchased_products.php, which is the
// stock override location honored by zen_get_module_directory() -- so every
// wrapper template (stock, or a template's customized copy) receives its data
// from here without modification.
//
// Output contract is identical to the stock module: $list_box_contents,
// $title and $zc_show_also_purchased are populated the same way, so
// downstream presentation (tpl_columnar_display.php or any template-specific
// display include) renders unchanged.
//
if (!defined('IS_ADMIN_FLAG')) {
    die('Illegal Access');
}
if (isset($_GET['products_id']) && SHOW_PRODUCT_INFO_COLUMNS_ALSO_PURCHASED_PRODUCTS > 0 && MIN_DISPLAY_ALSO_PURCHASED > 0) {
    $apt_products_id = (int)$_GET['products_id'];
    $apt_rows = [];

    $apt_active = (defined('APT_ENABLED') && APT_ENABLED === 'true' && defined('TABLE_PRODUCTS_ALSO_PURCHASED'));
    $apt_source = 'disabled';
    $apt_t0 = microtime(true);

    if ($apt_active) {
        switch (defined('APT_RANKING') ? APT_RANKING : 'Affinity') {
            case 'Recency':
                $apt_order_by = 'ap.last_purchased DESC, ap.times_purchased DESC, ap.also_products_id';
                break;
            case 'Random':
                $apt_order_by = 'RAND()';
                break;
            case 'Affinity':
            default:
                $apt_order_by = 'ap.times_purchased DESC, ap.last_purchased DESC, ap.also_products_id';
                break;
        }

        // Indexed primary-key range scan on the pair table; the join to
        // products enforces status at read time so disabled/deleted products
        // never surface regardless of pair-table staleness.
        $apt_result = $db->Execute(
            'SELECT ap.also_products_id AS products_id, p.products_image
               FROM ' . TABLE_PRODUCTS_ALSO_PURCHASED . ' ap
                    INNER JOIN ' . TABLE_PRODUCTS . ' p
                        ON p.products_id = ap.also_products_id
                       AND p.products_status = 1
              WHERE ap.products_id = ' . $apt_products_id . '
              ORDER BY ' . $apt_order_by . '
              LIMIT ' . (int)MAX_DISPLAY_ALSO_PURCHASED
        );
        foreach ($apt_result as $apt_fields) {
            $apt_rows[] = $apt_fields;
        }
        if (count($apt_rows) > 0) {
            $apt_source = 'pair_table';
        }
    }

    // -----
    // Stock-query fallback: plugin disabled, or no pair data yet for this
    // product (typically pre-seed) and the fallback is enabled. Reproduces
    // stock behavior exactly, including the ExecuteRandomMulti window.
    //
    if (count($apt_rows) === 0 && (!$apt_active || (defined('APT_FALLBACK_STOCK') && APT_FALLBACK_STOCK === 'true'))) {
        if ($apt_active) {
            $apt_source = 'stock_fallback';
        }
        $apt_stock = $db->ExecuteRandomMulti(sprintf(SQL_ALSO_PURCHASED, $apt_products_id, $apt_products_id), (int)MAX_DISPLAY_ALSO_PURCHASED);
        while (!$apt_stock->EOF) {
            $apt_rows[] = [
                'products_id' => $apt_stock->fields['products_id'],
                'products_image' => $apt_stock->fields['products_image'],
            ];
            $apt_stock->MoveNextRandom();
        }
    }

    if (class_exists('AlsoPurchasedTurbo')) {
        AlsoPurchasedTurbo::debugLog([
            'event' => 'render',
            'products_id' => $apt_products_id,
            'source' => $apt_source,
            'ranking' => defined('APT_RANKING') ? APT_RANKING : null,
            'rows' => count($apt_rows),
            'ms' => round((microtime(true) - $apt_t0) * 1000, 3),
        ]);
    }

    $num_products_ordered = count($apt_rows);

    $row = 0;
    $col = 0;
    $list_box_contents = [];
    $title = '';

    // show only when 1 or more and equal to or greater than minimum set in admin
    if ($num_products_ordered >= MIN_DISPLAY_ALSO_PURCHASED && $num_products_ordered > 0) {
        if ($num_products_ordered < SHOW_PRODUCT_INFO_COLUMNS_ALSO_PURCHASED_PRODUCTS) {
            $col_width = floor(100 / $num_products_ordered);
        } else {
            $col_width = floor(100 / SHOW_PRODUCT_INFO_COLUMNS_ALSO_PURCHASED_PRODUCTS);
        }

        foreach ($apt_rows as $apt_fields) {
            $product_info = new Product((int)$apt_fields['products_id']);
            $data = array_merge($apt_fields, $product_info->getDataForLanguage());

            $list_box_contents[$row][$col] = [
                'params' => 'class="centerBoxContentsAlsoPurch"' . ' ' . 'style="width:' . $col_width . '%;"',
                'text' => ((empty($data['products_image']) && (int)PRODUCTS_IMAGE_NO_IMAGE_STATUS === 0) ? ''
                        : '<a href="' . zen_href_link(zen_get_info_page($data['products_id']), 'products_id=' . $data['products_id']) . '">'
                        . zen_image(DIR_WS_IMAGES . $data['products_image'], $data['products_name'], SMALL_IMAGE_WIDTH, SMALL_IMAGE_HEIGHT)
                        . '</a><br>')
                    . '<a href="' . zen_href_link(zen_get_info_page($data['products_id']), 'products_id=' . $data['products_id']) . '">' . $data['products_name'] . '</a>',
            ];

            $col++;
            if ($col > (SHOW_PRODUCT_INFO_COLUMNS_ALSO_PURCHASED_PRODUCTS - 1)) {
                $col = 0;
                $row++;
            }
        }

        $title = '<h2 class="centerBoxHeading">' . TEXT_ALSO_PURCHASED_PRODUCTS . '</h2>';
        $zc_show_also_purchased = true;
    }
}
