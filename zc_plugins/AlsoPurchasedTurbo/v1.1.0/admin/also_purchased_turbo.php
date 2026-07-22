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
// Tools page: engine status, template-shim health/repair/takeover, related
// display settings (the stock Zen Cart keys the also-purchased box honors),
// historical seeding (chunked, resumable, auto-continuing), full rebuild,
// and stale-pair purge.
//
require 'includes/application_top.php';

// Orders per INSERT..SELECT statement. Each chunk is one grouped pass over a
// bounded orders_id range; multiple chunks run per request within the time
// budget below, so even multi-million-row stores seed without timeouts.
const APT_SEED_ORDERS_PER_CHUNK = 500;
const APT_SEED_TIME_BUDGET_SECONDS = 8;

const APT_SHIM_MARKER = 'APT-SHIM-MARKER';

$action = $_POST['action'] ?? '';
$apt_auto_continue = false;
$apt_chain_prune_after_seed = false;

// -----
// Helpers ------------------------------------------------------------------
// -----

function apt_set_seed_progress(string $value): void
{
    global $db;
    $db->Execute(
        "UPDATE " . TABLE_CONFIGURATION . "
            SET configuration_value = '" . zen_db_input($value) . "', last_modified = NOW()
          WHERE configuration_key = 'APT_SEED_PROGRESS'
          LIMIT 1"
    );
}

function apt_get_seed_progress(): string
{
    global $db;
    // Read fresh from the db (the defined constant is stale within a request).
    $result = $db->Execute(
        "SELECT configuration_value FROM " . TABLE_CONFIGURATION . "
          WHERE configuration_key = 'APT_SEED_PROGRESS' LIMIT 1"
    );
    return $result->EOF ? '' : (string)$result->fields['configuration_value'];
}

function apt_max_orders_id(): int
{
    global $db;
    $result = $db->Execute('SELECT MAX(orders_id) AS max_id FROM ' . TABLE_ORDERS);
    return $result->EOF ? 0 : (int)$result->fields['max_id'];
}

/**
 * Seed one orders_id range into the pair table. COUNT(DISTINCT orders_id)
 * collapses duplicate line items (same product, different attributes) to one
 * co-purchase per order pair.
 */
function apt_seed_range(int $start, int $end): void
{
    global $db;
    $db->Execute(
        'INSERT INTO ' . TABLE_PRODUCTS_ALSO_PURCHASED . '
            (products_id, also_products_id, times_purchased, last_purchased)
         SELECT opa.products_id,
                opb.products_id,
                COUNT(DISTINCT opa.orders_id),
                MAX(o.date_purchased)
           FROM ' . TABLE_ORDERS_PRODUCTS . ' opa
                INNER JOIN ' . TABLE_ORDERS_PRODUCTS . ' opb
                    ON opb.orders_id = opa.orders_id
                   AND opb.products_id <> opa.products_id
                INNER JOIN ' . TABLE_ORDERS . ' o
                    ON o.orders_id = opa.orders_id
          WHERE opa.orders_id BETWEEN ' . $start . ' AND ' . $end . '
            AND opa.products_id > 0
            AND opb.products_id > 0
          GROUP BY opa.products_id, opb.products_id
          ON DUPLICATE KEY UPDATE
             times_purchased = times_purchased + VALUES(times_purchased),
             last_purchased = GREATEST(last_purchased, VALUES(last_purchased))'
    );
}

function apt_shim_path_for(string $template_dir): string
{
    return DIR_FS_CATALOG . DIR_WS_MODULES . basename($template_dir) . '/also_purchased_products.php';
}

function apt_shim_content(): string
{
    return '<?php' . "\n"
        . '// ' . APT_SHIM_MARKER . "\n"
        . '// Installed by the Also Purchased Turbo plugin; removed by its uninstaller.' . "\n"
        . '// Safe to delete manually: the store then reverts to the stock also_purchased module.' . "\n"
        . 'if (defined(\'APT_MODULE_PATH\') && is_file(APT_MODULE_PATH)) {' . "\n"
        . '    require APT_MODULE_PATH;' . "\n"
        . '} else {' . "\n"
        . '    require DIR_FS_CATALOG . DIR_WS_MODULES . \'also_purchased_products.php\';' . "\n"
        . '}' . "\n";
}

/**
 * @return array [template_dir => 'ok'|'missing'|'foreign'|'integrated']
 */
function apt_shim_status(): array
{
    global $db;
    $status = [];
    $templates = $db->Execute('SELECT DISTINCT template_dir FROM ' . TABLE_TEMPLATE_SELECT);
    foreach ($templates as $tpl) {
        $template_dir = basename($tpl['template_dir']);
        if ($template_dir === '' || $template_dir === 'template_default') {
            continue;
        }
        $path = apt_shim_path_for($template_dir);
        if (!is_file($path)) {
            $status[$template_dir] = 'missing';
        } else {
            $contents = (string)file_get_contents($path);
            if (strpos($contents, 'APT-DATA-CONSUMER') !== false) {
                $status[$template_dir] = 'integrated';
            } elseif (strpos($contents, APT_SHIM_MARKER) === false) {
                $status[$template_dir] = 'foreign';
            } else {
                $status[$template_dir] = 'ok';
            }
        }
    }
    return $status;
}

/**
 * Current values of the stock Zen Cart settings the also-purchased box
 * honors (shared with the classic module; these are NOT APT-specific keys).
 */
function apt_get_stock_display_settings(): array
{
    global $db;
    $keys = ['MIN_DISPLAY_ALSO_PURCHASED', 'MAX_DISPLAY_ALSO_PURCHASED', 'SHOW_PRODUCT_INFO_COLUMNS_ALSO_PURCHASED_PRODUCTS'];
    $values = [];
    $result = $db->Execute(
        "SELECT configuration_key, configuration_value FROM " . TABLE_CONFIGURATION . "
          WHERE configuration_key IN ('" . implode("','", $keys) . "')"
    );
    foreach ($result as $row) {
        $values[$row['configuration_key']] = $row['configuration_value'];
    }
    return $values;
}

/**
 * Read a configuration value fresh from the database (the defined constant
 * may be stale within the request that changed it).
 */
function apt_get_config(string $key): string
{
    global $db;
    $result = $db->Execute(
        "SELECT configuration_value FROM " . TABLE_CONFIGURATION . "
          WHERE configuration_key = '" . zen_db_input($key) . "' LIMIT 1"
    );
    return $result->EOF ? '' : (string)$result->fields['configuration_value'];
}

/**
 * Ranking used to decide which pairs SURVIVE a prune. Follows the configured
 * storefront ranking so pruning never removes what the storefront would have
 * shown; Random has no stable order, so it prunes by affinity.
 */
function apt_prune_order_by(): string
{
    $ranking = apt_get_config('APT_RANKING');
    if ($ranking === 'Recency') {
        return 'last_purchased DESC, times_purchased DESC, also_products_id';
    }
    return 'times_purchased DESC, last_purchased DESC, also_products_id';
}

function apt_config_group_id(): int
{
    global $db;
    $result = $db->Execute(
        "SELECT configuration_group_id FROM " . TABLE_CONFIGURATION_GROUP . "
          WHERE configuration_group_title = 'Also Purchased Turbo' LIMIT 1"
    );
    return $result->EOF ? 0 : (int)$result->fields['configuration_group_id'];
}

// -----
// Actions ------------------------------------------------------------------
// -----

if ($action === 'save_display_settings') {
    $updates = [
        'MIN_DISPLAY_ALSO_PURCHASED' => max(0, (int)($_POST['min_display'] ?? 0)),
        'MAX_DISPLAY_ALSO_PURCHASED' => max(0, (int)($_POST['max_display'] ?? 0)),
        'SHOW_PRODUCT_INFO_COLUMNS_ALSO_PURCHASED_PRODUCTS' => min(12, max(0, (int)($_POST['columns'] ?? 0))),
    ];
    foreach ($updates as $key => $value) {
        $db->Execute(
            "UPDATE " . TABLE_CONFIGURATION . "
                SET configuration_value = '" . (int)$value . "', last_modified = NOW()
              WHERE configuration_key = '" . $key . "'
              LIMIT 1"
        );
    }
    $messageStack->add(APT_TEXT_DISPLAY_SETTINGS_SAVED, 'success');
}

if ($action === 'rebuild') {
    $db->Execute('TRUNCATE TABLE ' . TABLE_PRODUCTS_ALSO_PURCHASED);
    apt_set_seed_progress('');
    $messageStack->add_session(APT_TEXT_REBUILD_RESET, 'caution');
    zen_redirect(zen_href_link(FILENAME_ALSO_PURCHASED_TURBO, 'autoseed=1'));
}

if ($action === 'seed' || isset($_GET['autoseed'])) {
    $max_orders_id = apt_max_orders_id();
    $progress = apt_get_seed_progress();

    // Guard: a populated pair table with no progress pointer means the
    // pointer was lost (typically uninstall/reinstall, which preserves the
    // table but recreates the config key). Seeding from orders_id 1 into
    // existing rows would double-count every pair -- mark done instead and
    // direct the admin to Rebuild if a from-scratch pass is wanted.
    if ($progress === '') {
        $apt_guard = $db->Execute('SELECT COUNT(*) AS pair_rows FROM ' . TABLE_PRODUCTS_ALSO_PURCHASED);
        if ((int)$apt_guard->fields['pair_rows'] > 0) {
            apt_set_seed_progress('done');
            $messageStack->add(sprintf(APT_TEXT_SEED_GUARD, number_format((float)$apt_guard->fields['pair_rows'])), 'caution');
            $progress = 'done';
        }
    }

    if ($progress !== 'done' && $max_orders_id > 0) {
        $next = ($progress === '') ? 1 : (int)$progress;
        $stop_at = microtime(true) + APT_SEED_TIME_BUDGET_SECONDS;
        $first_processed = $next;

        while ($next <= $max_orders_id && microtime(true) < $stop_at) {
            $end = $next + APT_SEED_ORDERS_PER_CHUNK - 1;
            apt_seed_range($next, $end);
            $next = $end + 1;
        }

        if ($next > $max_orders_id) {
            apt_set_seed_progress('done');
            $counts = $db->Execute(
                'SELECT COUNT(*) AS pair_rows, COUNT(DISTINCT products_id) AS products_covered
                   FROM ' . TABLE_PRODUCTS_ALSO_PURCHASED
            );
            $messageStack->add(sprintf(APT_TEXT_SEED_COMPLETE, number_format((float)$counts->fields['pair_rows']), number_format((float)$counts->fields['products_covered'])), 'success');
            if ((int)apt_get_config('APT_MAX_PAIRS_PER_PRODUCT') > 0) {
                $messageStack->add(APT_TEXT_SEED_PRUNE_CHAIN, 'caution');
                $apt_chain_prune_after_seed = true;
            }
        } else {
            apt_set_seed_progress((string)$next);
            $messageStack->add(sprintf(APT_TEXT_SEED_CHUNK_DONE, number_format($first_processed), number_format($next - 1)), 'success');
            $apt_auto_continue = true;
        }
    }
}

$apt_auto_continue_prune = false;
$apt_prune_state = null;

if ($action === 'prune_pairs') {
    $apt_limit = max(0, (int)apt_get_config('APT_MAX_PAIRS_PER_PRODUCT'));
    if ($apt_limit === 0) {
        $messageStack->add(APT_TEXT_PRUNE_DISABLED, 'caution');
    } else {
        $apt_cursor = max(0, (int)($_POST['prune_cursor'] ?? 0));
        $apt_deleted = max(0, (int)($_POST['prune_deleted'] ?? 0));
        $apt_order_by = apt_prune_order_by();
        $apt_stop_at = microtime(true) + APT_SEED_TIME_BUDGET_SECONDS;
        $apt_done = false;

        while (microtime(true) < $apt_stop_at) {
            // Next batch of products holding more pairs than the limit,
            // walked in products_id order via cursor (index-only scan).
            $batch = $db->Execute(
                'SELECT products_id, COUNT(*) AS pair_count
                   FROM ' . TABLE_PRODUCTS_ALSO_PURCHASED . '
                  WHERE products_id > ' . $apt_cursor . '
                  GROUP BY products_id
                 HAVING pair_count > ' . $apt_limit . '
                  ORDER BY products_id
                  LIMIT 100'
            );
            if ($batch->EOF) {
                $apt_done = true;
                break;
            }
            foreach ($batch as $apt_row) {
                $apt_pid = (int)$apt_row['products_id'];
                // Survivors: this product's top-N by the configured ranking.
                $keep = $db->Execute(
                    'SELECT also_products_id
                       FROM ' . TABLE_PRODUCTS_ALSO_PURCHASED . '
                      WHERE products_id = ' . $apt_pid . '
                      ORDER BY ' . $apt_order_by . '
                      LIMIT ' . $apt_limit
                );
                $keep_ids = [];
                foreach ($keep as $keep_row) {
                    $keep_ids[] = (int)$keep_row['also_products_id'];
                }
                if (count($keep_ids) > 0) {
                    $db->Execute(
                        'DELETE FROM ' . TABLE_PRODUCTS_ALSO_PURCHASED . '
                          WHERE products_id = ' . $apt_pid . '
                            AND also_products_id NOT IN (' . implode(',', $keep_ids) . ')'
                    );
                    $apt_deleted += (int)$db->affectedRows();
                }
                $apt_cursor = $apt_pid;
                if (microtime(true) >= $apt_stop_at) {
                    break;
                }
            }
        }

        if ($apt_done) {
            // Persist the run for the status panel: before = after + removed.
            $apt_after = $db->Execute('SELECT COUNT(*) AS c FROM ' . TABLE_PRODUCTS_ALSO_PURCHASED);
            $apt_after_rows = (int)$apt_after->fields['c'];
            $db->Execute(
                "UPDATE " . TABLE_CONFIGURATION . "
                    SET configuration_value = '" . zen_db_input(date('Y-m-d H:i:s') . '|' . ($apt_after_rows + $apt_deleted) . '|' . $apt_after_rows . '|' . $apt_limit) . "', last_modified = NOW()
                  WHERE configuration_key = 'APT_PRUNE_STATS'
                  LIMIT 1"
            );
            $messageStack->add(sprintf(APT_TEXT_PRUNE_COMPLETE, number_format($apt_deleted), number_format($apt_limit)), 'success');
        } else {
            $messageStack->add(sprintf(APT_TEXT_PRUNE_CHUNK_DONE, number_format($apt_cursor), number_format($apt_deleted)), 'success');
            $apt_auto_continue_prune = true;
            $apt_prune_state = ['cursor' => $apt_cursor, 'deleted' => $apt_deleted];
        }
    }
}

if ($action === 'purge') {
    $db->Execute(
        'DELETE ap FROM ' . TABLE_PRODUCTS_ALSO_PURCHASED . ' ap
                LEFT JOIN ' . TABLE_PRODUCTS . ' pa ON pa.products_id = ap.products_id
                LEFT JOIN ' . TABLE_PRODUCTS . ' pb ON pb.products_id = ap.also_products_id
          WHERE pa.products_id IS NULL
             OR pb.products_id IS NULL'
    );
    $messageStack->add(sprintf(APT_TEXT_PURGED, number_format((float)$db->affectedRows())), 'success');
}

if ($action === 'takeover_shim') {
    // Explicit, per-template, admin-confirmed: back up a customized (non-APT)
    // module and install the shim in its place. Backup is restored on uninstall.
    $template_dir = basename($_POST['template_dir'] ?? '');
    $status = apt_shim_status();
    if ($template_dir !== '' && ($status[$template_dir] ?? '') === 'foreign') {
        $target = apt_shim_path_for($template_dir);
        $target_dir = dirname($target);
        $backup = $target_dir . '/also_purchased_products.pre-APT.php.bak';
        if (is_file($backup)) {
            $messageStack->add(sprintf(APT_TEXT_TAKEOVER_BACKUP_EXISTS, $template_dir), 'error');
        } else {
            $backed_up = @rename($target, $backup);
            if (!$backed_up && @copy($target, $backup)) {
                $backed_up = @unlink($target);
                if (!$backed_up) {
                    @unlink($backup);
                }
            }
            if (!$backed_up) {
                $diagnosis = !is_writable($target_dir)
                    ? sprintf(APT_TEXT_DIR_NOT_WRITABLE, $target_dir)
                    : sprintf(APT_TEXT_PERMS_UNCLEAR, $target);
                $messageStack->add(sprintf(APT_TEXT_TAKEOVER_FAILED, $template_dir) . ' ' . $diagnosis, 'error');
            } elseif (@file_put_contents($target, apt_shim_content()) === false) {
                $messageStack->add(sprintf(APT_TEXT_SHIM_WRITE_FAILED, $target), 'error');
            } else {
                $messageStack->add(sprintf(APT_TEXT_TAKEOVER_OK, $template_dir), 'success');
            }
        }
    }
}

if ($action === 'repair_shims') {
    foreach (apt_shim_status() as $template_dir => $state) {
        if ($state !== 'missing') {
            continue; // never overwrite a foreign (customized) module from here
        }
        $dir = dirname(apt_shim_path_for($template_dir));
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        @file_put_contents(apt_shim_path_for($template_dir), apt_shim_content());
    }
    $messageStack->add(APT_TEXT_SHIMS_REPAIRED, 'success');
}

// -----
// Page state ----------------------------------------------------------------
// -----

$stats = $db->Execute(
    'SELECT COUNT(*) AS pair_rows, COUNT(DISTINCT products_id) AS products_covered
       FROM ' . TABLE_PRODUCTS_ALSO_PURCHASED
);
$pair_rows = (int)$stats->fields['pair_rows'];
$products_covered = (int)$stats->fields['products_covered'];
$seed_progress = apt_get_seed_progress();
$max_orders_id = apt_max_orders_id();
$shim_status = apt_shim_status();
$display_settings = apt_get_stock_display_settings();
$apt_gid = apt_config_group_id();

$apt_settings_summary = [
    APT_TEXT_SETTING_ENABLED => (defined('APT_ENABLED') ? APT_ENABLED : '—'),
    APT_TEXT_SETTING_RANKING => (defined('APT_RANKING') ? APT_RANKING : '—'),
    APT_TEXT_SETTING_FALLBACK => (defined('APT_FALLBACK_STOCK') ? APT_FALLBACK_STOCK : '—'),
    APT_TEXT_SETTING_DEBUG => (defined('APT_DEBUG_LOG') ? APT_DEBUG_LOG : '—'),
    APT_TEXT_SETTING_MAX_PAIRS => apt_get_config('APT_MAX_PAIRS_PER_PRODUCT'),
];
?>
<!doctype html>
<html <?php echo HTML_PARAMS; ?>>
<head>
    <?php require DIR_WS_INCLUDES . 'admin_html_head.php'; ?>
</head>
<body>
<!-- header //-->
<?php require DIR_WS_INCLUDES . 'header.php'; ?>
<!-- header_eof //-->

<div class="container-fluid">
    <h1 class="pageHeading"><?php echo APT_HEADING_TITLE; ?> <small><?php echo APT_HEADING_SUBTITLE; ?></small></h1>

    <div class="row">
        <div class="col-md-6">
            <div class="panel panel-default">
                <div class="panel-heading"><strong><?php echo APT_PANEL_STATUS; ?></strong></div>
                <div class="panel-body">
                    <table class="table table-condensed" style="margin-bottom: 0;">
                        <tr>
                            <td style="width: 45%;"><?php echo APT_TEXT_STATUS; ?></td>
                            <td>
                                <?php if (defined('APT_ENABLED') && APT_ENABLED === 'true') { ?>
                                    <span class="label label-success"><?php echo APT_TEXT_ENABLED; ?></span>
                                <?php } else { ?>
                                    <span class="label label-default"><?php echo APT_TEXT_DISABLED; ?></span>
                                <?php } ?>
                            </td>
                        </tr>
                        <tr>
                            <td><?php echo APT_TEXT_PAIR_ROWS; ?></td>
                            <td><?php echo number_format($pair_rows); ?></td>
                        </tr>
                        <tr>
                            <td><?php echo APT_TEXT_PRODUCTS_COVERED; ?></td>
                            <td><?php echo number_format($products_covered); ?></td>
                        </tr>
                        <tr>
                            <td><?php echo APT_TEXT_SEED_STATE; ?></td>
                            <td>
                                <?php
                                if ($seed_progress === 'done') {
                                    echo '<span class="label label-success">' . APT_TEXT_SEED_DONE . '</span>';
                                } elseif ($seed_progress === '') {
                                    echo '<span class="label label-warning">' . APT_TEXT_SEED_NOT_STARTED_SHORT . '</span> ' . APT_TEXT_SEED_NOT_STARTED;
                                } else {
                                    echo '<span class="label label-info">' . APT_TEXT_SEED_IN_PROGRESS_SHORT . '</span> ' . sprintf(APT_TEXT_SEED_IN_PROGRESS, number_format((int)$seed_progress), number_format($max_orders_id));
                                }
                                ?>
                            </td>
                        </tr>
                        <tr>
                            <td><?php echo APT_TEXT_LAST_PRUNE; ?></td>
                            <td>
                                <?php
                                $apt_prune_stats = explode('|', apt_get_config('APT_PRUNE_STATS'));
                                if (count($apt_prune_stats) === 4 && $apt_prune_stats[0] !== '') {
                                    echo sprintf(
                                        APT_TEXT_LAST_PRUNE_DETAIL,
                                        zen_date_short($apt_prune_stats[0]) . ' ' . date('H:i', strtotime($apt_prune_stats[0])),
                                        number_format((float)$apt_prune_stats[1]),
                                        number_format((float)$apt_prune_stats[2]),
                                        number_format((float)$apt_prune_stats[1] - (float)$apt_prune_stats[2]),
                                        (int)$apt_prune_stats[3]
                                    );
                                } else {
                                    echo APT_TEXT_LAST_PRUNE_NEVER;
                                }
                                ?>
                            </td>
                        </tr>
                    </table>
                </div>
            </div>

            <div class="panel panel-default">
                <div class="panel-heading"><strong><?php echo APT_PANEL_SHIMS; ?></strong></div>
                <div class="panel-body">
                    <p class="text-muted" style="margin-top: 0;"><?php echo APT_TEXT_SHIMS_EXPLAIN; ?></p>
                    <table class="table table-condensed" style="margin-bottom: 0;">
                        <?php
                        if (count($shim_status) === 0) {
                            echo '<tr><td>&mdash;</td></tr>';
                        }
                        foreach ($shim_status as $template_dir => $state) {
                            $dir_writable = is_writable(dirname(apt_shim_path_for($template_dir)));
                            echo '<tr><td style="width: 30%;"><code>' . htmlspecialchars($template_dir) . '</code></td><td>';
                            if ($state === 'ok') {
                                echo '<span class="label label-success">' . APT_TEXT_SHIM_OK . '</span>';
                            } elseif ($state === 'integrated') {
                                echo '<span class="label label-success">' . APT_TEXT_SHIM_INTEGRATED_SHORT . '</span> ' . APT_TEXT_SHIM_INTEGRATED;
                            } elseif ($state === 'missing') {
                                echo '<span class="label label-danger">' . APT_TEXT_SHIM_MISSING_SHORT . '</span> ' . APT_TEXT_SHIM_MISSING;
                            } elseif ($state === 'foreign') {
                                echo '<span class="label label-warning">' . APT_TEXT_SHIM_FOREIGN_SHORT . '</span> ' . APT_TEXT_SHIM_FOREIGN;
                            }
                            if (!$dir_writable && ($state === 'missing' || $state === 'foreign')) {
                                echo '<br><span class="text-danger">' . sprintf(APT_TEXT_DIR_NOT_WRITABLE, htmlspecialchars(dirname(apt_shim_path_for($template_dir)))) . '</span>';
                            }
                            if ($state === 'foreign' && $dir_writable) {
                                echo '<div style="margin-top: 5px;">'
                                    . zen_draw_form('apt_takeover_' . $template_dir, FILENAME_ALSO_PURCHASED_TURBO, '', 'post', 'style="display:inline;" onsubmit="return confirm(\'' . sprintf(APT_TEXT_TAKEOVER_CONFIRM_JS, addslashes($template_dir)) . '\');"', true)
                                    . zen_draw_hidden_field('action', 'takeover_shim')
                                    . zen_draw_hidden_field('template_dir', $template_dir)
                                    . '<button type="submit" class="btn btn-xs btn-warning">' . APT_BUTTON_TAKEOVER . '</button></form>'
                                    . '</div>';
                            }
                            echo '</td></tr>';
                        }
                        ?>
                    </table>
                </div>
            </div>
        </div>

        <div class="col-md-6">
            <div class="panel panel-default">
                <div class="panel-heading"><strong><?php echo APT_PANEL_DISPLAY_SETTINGS; ?></strong></div>
                <div class="panel-body">
                    <p class="text-muted" style="margin-top: 0;"><?php echo APT_TEXT_DISPLAY_SETTINGS_EXPLAIN; ?></p>
                    <?php echo zen_draw_form('apt_display', FILENAME_ALSO_PURCHASED_TURBO, '', 'post', 'class="form-horizontal"', true); ?>
                        <?php echo zen_draw_hidden_field('action', 'save_display_settings'); ?>
                        <div class="form-group">
                            <label class="col-sm-7 control-label" for="apt-min"><?php echo APT_TEXT_MIN_DISPLAY; ?></label>
                            <div class="col-sm-4">
                                <input type="number" min="0" class="form-control input-sm" id="apt-min" name="min_display" value="<?php echo (int)($display_settings['MIN_DISPLAY_ALSO_PURCHASED'] ?? 1); ?>">
                            </div>
                        </div>
                        <div class="form-group">
                            <label class="col-sm-7 control-label" for="apt-max"><?php echo APT_TEXT_MAX_DISPLAY; ?></label>
                            <div class="col-sm-4">
                                <input type="number" min="0" class="form-control input-sm" id="apt-max" name="max_display" value="<?php echo (int)($display_settings['MAX_DISPLAY_ALSO_PURCHASED'] ?? 6); ?>">
                            </div>
                        </div>
                        <div class="form-group">
                            <label class="col-sm-7 control-label" for="apt-cols"><?php echo APT_TEXT_COLUMNS; ?></label>
                            <div class="col-sm-4">
                                <select class="form-control input-sm" id="apt-cols" name="columns">
                                    <?php for ($apt_i = 0; $apt_i <= 12; $apt_i++) { ?>
                                        <option value="<?php echo $apt_i; ?>"<?php echo ((int)($display_settings['SHOW_PRODUCT_INFO_COLUMNS_ALSO_PURCHASED_PRODUCTS'] ?? 3) === $apt_i) ? ' selected' : ''; ?>><?php echo $apt_i; ?></option>
                                    <?php } ?>
                                </select>
                            </div>
                        </div>
                        <div class="form-group" style="margin-bottom: 0;">
                            <div class="col-sm-offset-7 col-sm-4">
                                <button type="submit" class="btn btn-primary btn-sm"><?php echo APT_BUTTON_SAVE_DISPLAY; ?></button>
                            </div>
                        </div>
                    </form>
                </div>
            </div>

            <div class="panel panel-default">
                <div class="panel-heading"><strong><?php echo APT_PANEL_PLUGIN_SETTINGS; ?></strong></div>
                <div class="panel-body">
                    <table class="table table-condensed" style="margin-bottom: 10px;">
                        <?php foreach ($apt_settings_summary as $apt_label => $apt_value) { ?>
                            <tr>
                                <td style="width: 45%;"><?php echo $apt_label; ?></td>
                                <td><code><?php echo htmlspecialchars((string)$apt_value); ?></code></td>
                            </tr>
                        <?php } ?>
                    </table>
                    <?php if ($apt_gid > 0) { ?>
                        <a class="btn btn-default btn-sm" href="<?php echo zen_href_link(FILENAME_CONFIGURATION, 'gID=' . $apt_gid); ?>"><?php echo APT_BUTTON_EDIT_PLUGIN_SETTINGS; ?></a>
                    <?php } ?>
                </div>
            </div>
        </div>
    </div>

    <div class="row">
        <div class="col-md-12">
            <div class="panel panel-default">
                <div class="panel-heading"><strong><?php echo APT_PANEL_MAINTENANCE; ?></strong></div>
                <div class="panel-body">
                    <table class="table table-condensed" style="margin-bottom: 0;">
                        <tr>
                            <td style="width: 260px; vertical-align: middle;">
                                <?php echo zen_draw_form('apt_seed', FILENAME_ALSO_PURCHASED_TURBO, '', 'post', '', true); ?>
                                    <?php echo zen_draw_hidden_field('action', 'seed'); ?>
                                    <button type="submit" class="btn btn-primary btn-block"><?php echo APT_BUTTON_SEED; ?></button>
                                </form>
                            </td>
                            <td class="text-muted" style="vertical-align: middle;"><?php echo APT_HELP_SEED; ?></td>
                        </tr>
                        <tr>
                            <td style="vertical-align: middle;">
                                <?php echo zen_draw_form('apt_rebuild', FILENAME_ALSO_PURCHASED_TURBO, '', 'post', 'onsubmit="return confirm(\'' . APT_TEXT_REBUILD_CONFIRM_JS . '\');"', true); ?>
                                    <?php echo zen_draw_hidden_field('action', 'rebuild'); ?>
                                    <button type="submit" class="btn btn-warning btn-block"><?php echo APT_BUTTON_REBUILD; ?></button>
                                </form>
                            </td>
                            <td class="text-muted" style="vertical-align: middle;"><?php echo APT_HELP_REBUILD; ?></td>
                        </tr>
                        <tr>
                            <td style="vertical-align: middle;">
                                <?php echo zen_draw_form('apt_purge', FILENAME_ALSO_PURCHASED_TURBO, '', 'post', '', true); ?>
                                    <?php echo zen_draw_hidden_field('action', 'purge'); ?>
                                    <button type="submit" class="btn btn-default btn-block"><?php echo APT_BUTTON_PURGE; ?></button>
                                </form>
                            </td>
                            <td class="text-muted" style="vertical-align: middle;"><?php echo APT_HELP_PURGE; ?></td>
                        </tr>
                        <tr>
                            <td style="vertical-align: middle;">
                                <?php echo zen_draw_form('apt_prune', FILENAME_ALSO_PURCHASED_TURBO, '', 'post', '', true); ?>
                                    <?php echo zen_draw_hidden_field('action', 'prune_pairs'); ?>
                                    <?php echo zen_draw_hidden_field('prune_cursor', ($apt_prune_state['cursor'] ?? 0), 'id="apt-prune-cursor"'); ?>
                                    <?php echo zen_draw_hidden_field('prune_deleted', ($apt_prune_state['deleted'] ?? 0), 'id="apt-prune-deleted"'); ?>
                                    <button type="submit" class="btn btn-default btn-block"><?php echo APT_BUTTON_PRUNE; ?></button>
                                </form>
                            </td>
                            <td class="text-muted" style="vertical-align: middle;"><?php echo sprintf(APT_HELP_PRUNE, (int)apt_get_config('APT_MAX_PAIRS_PER_PRODUCT')); ?></td>
                        </tr>
                        <tr>
                            <td style="vertical-align: middle;">
                                <?php echo zen_draw_form('apt_repair', FILENAME_ALSO_PURCHASED_TURBO, '', 'post', '', true); ?>
                                    <?php echo zen_draw_hidden_field('action', 'repair_shims'); ?>
                                    <button type="submit" class="btn btn-default btn-block"><?php echo APT_BUTTON_REPAIR_SHIMS; ?></button>
                                </form>
                            </td>
                            <td class="text-muted" style="vertical-align: middle;"><?php echo APT_HELP_REPAIR_SHIMS; ?></td>
                        </tr>
                    </table>
                </div>
            </div>
        </div>
    </div>

<?php if ($apt_auto_continue === true) { ?>
    <script>
        // Seeding incomplete: automatically continue with the next chunk set.
        setTimeout(function () {
            document.forms['apt_seed'].submit();
        }, 400);
    </script>
<?php } ?>
<?php if ($apt_auto_continue_prune === true || $apt_chain_prune_after_seed === true) { ?>
    <script>
        // Pruning in progress (or auto-started after seeding): continue.
        setTimeout(function () {
            document.forms['apt_prune'].submit();
        }, 400);
    </script>
<?php } ?>
</div>

<!-- footer //-->
<?php require DIR_WS_INCLUDES . 'footer.php'; ?>
<!-- footer_eof //-->
</body>
</html>
<?php require DIR_FS_ADMIN . 'includes/application_bottom.php'; ?>
