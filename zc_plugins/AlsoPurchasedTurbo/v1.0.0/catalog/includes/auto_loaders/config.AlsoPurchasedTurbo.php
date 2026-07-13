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
// Loaded at point 78 (same convention as other observer-based plugins):
// well after the database/notifier bootstrap, and early enough that the
// APT_MODULE_PATH constant is defined before any page template renders and
// the observer is attached before checkout's order-create notifications fire.
//
$autoLoadConfig[78][] = [
    'autoType' => 'class',
    'loadFile' => 'observers/AlsoPurchasedTurbo.php',
];
$autoLoadConfig[78][] = [
    'autoType' => 'classInstantiate',
    'className' => 'AlsoPurchasedTurbo',
    'objectName' => 'alsoPurchasedTurbo',
];
