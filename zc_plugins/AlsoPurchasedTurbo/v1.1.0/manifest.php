<?php
/**
 * Module: AlsoPurchasedTurbo
 *
 * @requires    Zen Cart 2.2.2 or later, PHP 8.0+ recommended
 * @author      Marcopolo
 * @copyright   2026
 * @license     GNU General Public License (GPL) - https://www.zen-cart.com/license/2_0.txt
 * @version     1.1.0
 * @updated     07-22-2026
 * @github      https://github.com/CcMarc/AlsoPurchasedTurbo
 */
return [
    'pluginVersion' => 'v1.1.0',
    'pluginName' => 'Also Purchased Turbo',
    'pluginDescription' => 'Replaces the stock "Customers who bought this product also purchased" engine with a precomputed product-pair table, eliminating the expensive orders_products self-join on every product page. Recommendations are ranked by real purchase affinity and kept current by a checkout observer. Requires no core-file or template changes; the store\'s existing also-purchased presentation is preserved.',
    'pluginAuthor' => 'Marcopolo',
    'pluginId' => 2443, // https://www.zen-cart.com/downloads.php?do=file&id=2443
    'zcVersions' => ['v210', 'v222'],
    'changelog' => '', // none
    'github_repo' => 'https://github.com/CcMarc/AlsoPurchasedTurbo',
    'pluginGroups' => [],
];
