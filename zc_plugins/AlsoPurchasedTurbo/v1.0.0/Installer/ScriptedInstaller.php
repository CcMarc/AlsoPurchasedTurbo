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
// Replaces the stock also_purchased engine with a precomputed pair table.
//
use Zencart\PluginSupport\ScriptedInstaller as ScriptedInstallBase;

class ScriptedInstaller extends ScriptedInstallBase
{
    protected string $configGroupTitle = 'Also Purchased Turbo';

    // Marker string that identifies a shim file as ours; never change this
    // between versions or uninstall will refuse to remove older shims.
    public const SHIM_MARKER = 'APT-SHIM-MARKER';

    protected function executeInstall()
    {
        // -----
        // The pair table. Deliberately lean: primary key only, so the
        // per-checkout upserts stay as cheap as possible. Per-product row
        // counts are small enough that the read-side ORDER BY is a trivial
        // in-memory sort. Table is preserved on uninstall (see README).
        //
        $this->executeInstallerSql(
            'CREATE TABLE IF NOT EXISTS ' . DB_PREFIX . 'products_also_purchased (
                products_id INT(11) UNSIGNED NOT NULL,
                also_products_id INT(11) UNSIGNED NOT NULL,
                times_purchased INT(11) UNSIGNED NOT NULL DEFAULT 1,
                last_purchased DATETIME NOT NULL,
                PRIMARY KEY (products_id, also_products_id)
            ) ENGINE=InnoDB'
        );

        // -----
        // Own configuration group rather than piggybacking a stock group.
        //
        $cgi = $this->getOrCreateConfigGroupId(
            $this->configGroupTitle,
            'Settings for the Also Purchased Turbo precomputed recommendation engine.'
        );

        if (!defined('APT_ENABLED')) {
            $this->addConfigurationKey('APT_ENABLED', [
                'configuration_title' => 'Enable Also Purchased Turbo?',
                'configuration_value' => 'true',
                'configuration_description' => '<br>When <b>true</b>, product pages read recommendations from the precomputed pair table and new orders update it at checkout. When <b>false</b>, the stock also-purchased query is used, exactly as if this plugin were not installed.<br><br>Display counts and column layout continue to honor the stock settings (<code>MIN_DISPLAY_ALSO_PURCHASED</code>, <code>MAX_DISPLAY_ALSO_PURCHASED</code>, columns) under Configuration &gt; Product Info.',
                'configuration_group_id' => $cgi,
                'sort_order' => 10,
                'set_function' => 'zen_cfg_select_option([\'true\', \'false\'],',
            ]);
        }

        if (!defined('APT_RANKING')) {
            $this->addConfigurationKey('APT_RANKING', [
                'configuration_title' => 'Recommendation ranking',
                'configuration_value' => 'Affinity',
                'configuration_description' => '<br><b>Affinity:</b> products most often purchased together with this one, strongest first (recommended).<br><b>Recency:</b> most recently co-purchased first (closest to stock behavior).<br><b>Random:</b> a random selection of co-purchased products.',
                'configuration_group_id' => $cgi,
                'sort_order' => 20,
                'set_function' => 'zen_cfg_select_option([\'Affinity\', \'Recency\', \'Random\'],',
            ]);
        }

        if (!defined('APT_FALLBACK_STOCK')) {
            $this->addConfigurationKey('APT_FALLBACK_STOCK', [
                'configuration_title' => 'Fall back to stock query when a product has no pair data?',
                'configuration_value' => 'true',
                'configuration_description' => '<br>When <b>true</b> and the pair table has no rows for the product being viewed (e.g. before the initial seed has completed), the stock also-purchased query is run for that product so the storefront display never regresses. Set to <b>false</b> on very large stores once seeding is complete, so an un-paired product costs nothing.',
                'configuration_group_id' => $cgi,
                'sort_order' => 30,
                'set_function' => 'zen_cfg_select_option([\'true\', \'false\'],',
            ]);
        }

        if (!defined('APT_DEBUG_LOG')) {
            $this->addConfigurationKey('APT_DEBUG_LOG', [
                'configuration_title' => 'Enable debug log',
                'configuration_value' => 'false',
                'configuration_description' => '<br>Write diagnostic info to <code>logs/also_purchased_turbo_debug.log</code>. Each storefront render logs one JSON line (product id, data source used, ranking mode, rows returned, query time); each checkout capture logs the order/product pair activity. ONLY enable while actively troubleshooting &mdash; the log grows with every product-page view. Safe to delete the log file at any time.',
                'configuration_group_id' => $cgi,
                'sort_order' => 40,
                'set_function' => 'zen_cfg_select_option([\'true\', \'false\'],',
            ]);
        }

        if (!defined('APT_SEED_PROGRESS')) {
            // On a REINSTALL the pair table was preserved from the previous
            // install; treat a populated table as already-seeded so the
            // status is truthful and a stray "Seed" click can't double-count.
            $apt_existing = $this->executeInstallerSelectQuery(
                'SELECT 1 FROM ' . DB_PREFIX . 'products_also_purchased LIMIT 1'
            );
            $apt_seed_initial = ($apt_existing !== false && !$apt_existing->EOF) ? 'done' : '';
            $this->addConfigurationKey('APT_SEED_PROGRESS', [
                'configuration_title' => 'Seed progress (managed automatically)',
                'configuration_value' => $apt_seed_initial,
                'configuration_description' => '<br>Internal bookkeeping for the historical seeding process. Managed by Tools &gt; Also Purchased Turbo &mdash; do not edit by hand. Empty = not yet seeded; a number = next orders_id to process; <code>done</code> = seeding complete.',
                'configuration_group_id' => $cgi,
                'sort_order' => 90,
            ]);
        }

        // -----
        // Admin pages: the Tools page, and a Configuration-menu entry for the
        // config group (creating the group row alone does not add it to the
        // Configuration dropdown -- that menu is built from the admin-pages
        // registry).
        //
        if (function_exists('zen_register_admin_page')) {
            if (!zen_page_key_exists('toolsAlsoPurchasedTurbo')) {
                zen_register_admin_page(
                    'toolsAlsoPurchasedTurbo',
                    'BOX_TOOLS_ALSO_PURCHASED_TURBO',
                    'FILENAME_ALSO_PURCHASED_TURBO',
                    '',
                    'tools',
                    'Y'
                );
            }
            if (!zen_page_key_exists('configAlsoPurchasedTurbo')) {
                zen_register_admin_page(
                    'configAlsoPurchasedTurbo',
                    'BOX_CONFIGURATION_ALSO_PURCHASED_TURBO',
                    'FILENAME_CONFIGURATION',
                    'gID=' . $cgi,
                    'configuration',
                    'Y'
                );
            }
        }

        // -----
        // Place the module-override shim for each active storefront template.
        // zen_get_module_directory() is not plugin-aware, so this one file per
        // template lives outside the plugin's directory; it is created here and
        // removed (with any backup restored) on uninstall. Failure to write is
        // reported but does not fail the install -- the Tools page provides a
        // "repair" action and the storefront simply keeps stock behavior until
        // the shim is in place.
        //
        // IMPORTANT: shim issues are reported through the messageStack, NOT
        // the errorContainer -- BasePluginInstaller::processInstall() aborts
        // the entire install if the errorContainer holds ANY error, and shim
        // placement is deliberately non-critical (the storefront runs stock
        // behavior until Tools > Also Purchased Turbo repairs it).
        global $messageStack;
        foreach ($this->installShims() as $shim_message) {
            if (isset($messageStack)) {
                $messageStack->add_session($shim_message, 'caution');
            } else {
                error_log('AlsoPurchasedTurbo install: ' . $shim_message);
            }
        }

        return parent::executeInstall();
    }

    protected function executeUpgrade($oldVersion)
    {
        // v1.0.0 is the first release. Future upgrade steps added here must
        // be idempotent, must bring upgraded installs to the same state as a
        // fresh install, and must preserve admin-edited configuration values.
        return parent::executeUpgrade($oldVersion);
    }

    protected function executeUninstall()
    {
        // Remove shims we own (marker-verified) and restore any backups.
        $this->removeShims();

        if (function_exists('zen_deregister_admin_pages')) {
            zen_deregister_admin_pages(['toolsAlsoPurchasedTurbo', 'configAlsoPurchasedTurbo']);
        }

        $this->deleteConfigurationKeys(['APT_ENABLED', 'APT_RANKING', 'APT_FALLBACK_STOCK', 'APT_DEBUG_LOG', 'APT_SEED_PROGRESS']);
        $this->deleteConfigurationGroup($this->configGroupTitle, true);

        // NOTE: the products_also_purchased table is deliberately preserved so
        // a reinstall does not require an expensive reseed. See README for the
        // one-line DROP if you want it gone.

        return parent::executeUninstall();
    }

    // -----
    // Shim management -------------------------------------------------------
    // -----

    /**
     * The shim's content. It resolves the plugin's engine through the
     * APT_MODULE_PATH constant defined by the plugin's observer (which is only
     * loaded when the plugin is installed AND enabled), so the shim needs no
     * version number baked in and self-defuses to stock behavior if the plugin
     * is disabled, upgraded, or removed while the shim remains.
     */
    protected function getShimContent(): string
    {
        return '<?php' . "\n"
            . '// ' . self::SHIM_MARKER . "\n"
            . '// Installed by the Also Purchased Turbo plugin; removed by its uninstaller.' . "\n"
            . '// Safe to delete manually: the store then reverts to the stock also_purchased module.' . "\n"
            . 'if (defined(\'APT_MODULE_PATH\') && is_file(APT_MODULE_PATH)) {' . "\n"
            . '    require APT_MODULE_PATH;' . "\n"
            . '} else {' . "\n"
            . '    require DIR_FS_CATALOG . DIR_WS_MODULES . \'also_purchased_products.php\';' . "\n"
            . '}' . "\n";
    }

    /**
     * Installs a shim into includes/modules/<template_dir>/ for every active
     * storefront template. A pre-existing customized module file (no marker)
     * is backed up alongside as .pre-APT.php.bak, never overwritten blindly.
     *
     * @return array error/warning strings (empty on full success)
     */
    protected function installShims(): array
    {
        $errors = [];
        $modules_dir = DIR_FS_CATALOG . DIR_WS_MODULES;

        $templates = $this->executeInstallerSelectQuery(
            'SELECT DISTINCT template_dir FROM ' . DB_PREFIX . 'template_select'
        );
        if ($templates === false) {
            return ['APT: could not read template_select; no shims installed. Use Tools > Also Purchased Turbo to repair.'];
        }

        foreach ($templates as $tpl) {
            $template_dir = basename($tpl['template_dir']); // sanity: no path segments
            if ($template_dir === '' || $template_dir === 'template_default') {
                continue;
            }
            $target_dir = $modules_dir . $template_dir;
            $target = $target_dir . '/also_purchased_products.php';

            if (!is_dir($target_dir) && !@mkdir($target_dir, 0755, true)) {
                $errors[] = 'APT: unable to create ' . $target_dir . '; shim not installed for template "' . $template_dir . '".';
                continue;
            }

            if (is_file($target)) {
                $existing = (string)file_get_contents($target);
                if (strpos($existing, 'APT-DATA-CONSUMER') !== false) {
                    // The template ships its own presentation that reads APT's
                    // pair table directly (integrated module). Leave it alone.
                    continue;
                }
                if (strpos($existing, self::SHIM_MARKER) === false) {
                    // A store-customized data module occupies the override
                    // slot. Never replace someone's customization silently --
                    // report it and leave the decision to the admin via the
                    // Tools page's explicit "Back up & take over" action.
                    $errors[] = 'APT notice: template "' . $template_dir . '" has a customized also_purchased module, so APT is NOT active for that template yet. Review it under Tools > Also Purchased Turbo: use "Back up & take over" to switch to APT, or integrate it (see the readme).';
                    continue;
                }
                // Ours (marker present): fall through and rewrite freshly.
            }

            if (@file_put_contents($target, $this->getShimContent()) === false) {
                $errors[] = 'APT: could not write shim to ' . $target . '. Check permissions, then use Tools > Also Purchased Turbo to repair.';
            }
        }
        return $errors;
    }

    /**
     * Removes every marker-verified shim under includes/modules/<anything>/,
     * including ones stranded by template switches, restoring backups.
     */
    protected function removeShims(): void
    {
        $candidates = glob(DIR_FS_CATALOG . DIR_WS_MODULES . '*/also_purchased_products.php') ?: [];
        foreach ($candidates as $file) {
            $contents = (string)file_get_contents($file);
            if (strpos($contents, self::SHIM_MARKER) === false) {
                continue; // not ours -- leave it alone
            }
            @unlink($file);
            $backup = dirname($file) . '/also_purchased_products.pre-APT.php.bak';
            if (is_file($backup)) {
                @rename($backup, $file);
            }
        }
    }
}
