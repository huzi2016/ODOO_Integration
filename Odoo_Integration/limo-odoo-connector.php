<?php
/**
 * Plugin Name: LIMO Odoo Connector
 * Description: Integrates LIMO_Membership / WooCommerce with Odoo: default JSON-2 API (Odoo 19+), optional JSON-RPC for self-hosted 14–18. Read-only to Odoo by default; optional writes for orders/partners. Product catalog writes blocked unless explicitly allowed.
 * Version: 1.2.4
 * Author: LIMO
 * Requires at least: 6.0
 * Requires PHP: 8.0
 * WC requires at least: 7.0
 *
 * @package LIMO_Odoo_Connector
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'LOC_VERSION',     '1.2.4' );
define( 'LOC_PLUGIN_PATH', plugin_dir_path( __FILE__ ) );
define( 'LOC_PLUGIN_URL',  plugin_dir_url( __FILE__ ) );

// ── Load sub-modules ────────────────────────────────────────────────────────
require_once LOC_PLUGIN_PATH . 'includes/class-loc-api.php';
require_once LOC_PLUGIN_PATH . 'includes/class-loc-product-sync.php';
require_once LOC_PLUGIN_PATH . 'includes/class-loc-customer-sync.php';
require_once LOC_PLUGIN_PATH . 'includes/class-loc-order-sync.php';
require_once LOC_PLUGIN_PATH . 'includes/class-loc-inventory-sync.php';
require_once LOC_PLUGIN_PATH . 'includes/class-loc-admin.php';

add_action( 'plugins_loaded', static function () {
    LOC_Product_Sync::init();
    LOC_Customer_Sync::init();
    LOC_Order_Sync::init();
    LOC_Inventory_Sync::init();
    LOC_Admin::init();
} );

// ── Activation: create log table ────────────────────────────────────────────
register_activation_hook( __FILE__, static function () {
    wp_clear_scheduled_hook( 'loc_sync_inventory' );

    global $wpdb;
    $table   = $wpdb->prefix . 'loc_sync_log';
    $charset = $wpdb->get_charset_collate();
    $sql     = "CREATE TABLE IF NOT EXISTS {$table} (
        id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        sync_type   VARCHAR(40)     NOT NULL,
        direction   VARCHAR(10)     NOT NULL DEFAULT 'push',
        object_id   BIGINT UNSIGNED NOT NULL DEFAULT 0,
        odoo_id     BIGINT UNSIGNED NOT NULL DEFAULT 0,
        status      VARCHAR(10)     NOT NULL DEFAULT 'ok',
        message     TEXT,
        created_at  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY idx_type_obj (sync_type, object_id)
    ) {$charset};";
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta( $sql );
} );
