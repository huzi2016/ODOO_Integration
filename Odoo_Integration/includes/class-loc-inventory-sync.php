<?php
/**
 * LOC_Inventory_Sync — Pull real-time inventory from Odoo into WooCommerce.
 *
 * Runs on a 15-minute schedule. Also exposes a REST endpoint so an Odoo
 * automated action can push inventory changes immediately.
 *
 * @package LIMO_Odoo_Connector
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class LOC_Inventory_Sync {

    public static function init(): void {
        // Schedule 15-minute stock sync
        if ( ! wp_next_scheduled( 'loc_sync_inventory' ) ) {
            wp_schedule_event( time(), 'loc_every_15_min', 'loc_sync_inventory' );
        }
        add_filter( 'cron_schedules', [ __CLASS__, 'add_cron_interval' ] );
        add_action( 'loc_sync_inventory', [ __CLASS__, 'pull_inventory' ] );

        // REST endpoint: Odoo pushes stock changes here
        add_action( 'rest_api_init', [ __CLASS__, 'register_rest_route' ] );

        // Admin AJAX manual trigger
        add_action( 'wp_ajax_loc_sync_inventory', [ __CLASS__, 'ajax_sync' ] );
    }

    public static function add_cron_interval( array $schedules ): array {
        $schedules['loc_every_15_min'] = [
            'interval' => 900,
            'display'  => __( 'Every 15 Minutes', 'limo-odoo-connector' ),
        ];
        return $schedules;
    }

    // ════════════════════════════════════════════════════════════════════════
    // Pull all product quantities from Odoo
    // ════════════════════════════════════════════════════════════════════════

    public static function pull_inventory(): void {
        // Get all WC products that have an Odoo id
        $args = [
            'post_type'      => 'product',
            'posts_per_page' => -1,
            'fields'         => 'ids',
            'meta_query'     => [ [
                'key'     => '_loc_odoo_product_id',
                'compare' => 'EXISTS',
            ] ],
        ];
        $wc_ids = get_posts( $args );

        if ( empty( $wc_ids ) ) {
            return;
        }

        // Build odoo_id → wc_id map
        $odoo_to_wc = [];
        foreach ( $wc_ids as $wc_id ) {
            $odoo_id = (int) get_post_meta( $wc_id, '_loc_odoo_product_id', true );
            if ( $odoo_id > 0 ) {
                $odoo_to_wc[ $odoo_id ] = $wc_id;
            }
        }

        // Stock is on product.product (variants); sum per product.template id (Odoo 17+ has no qty_available on template).
        $odoo_ids = array_keys( $odoo_to_wc );
        $qty_map  = LOC_API::sum_qty_available_by_template_ids( $odoo_ids );

        foreach ( $odoo_ids as $odoo_id ) {
            $qty   = (float) ( $qty_map[ $odoo_id ] ?? 0.0 );
            $wc_id = $odoo_to_wc[ $odoo_id ] ?? 0;

            if ( ! $wc_id ) {
                continue;
            }

            $product = wc_get_product( $wc_id );
            if ( ! $product ) {
                continue;
            }

            $old_qty = $product->get_stock_quantity();
            if ( (float) $old_qty === $qty ) {
                continue; // no change
            }

            // Suppress push-back to Odoo
            remove_action( 'woocommerce_update_product', [ 'LOC_Product_Sync', 'push_product' ], 20 );

            wc_update_product_stock( $product, $qty, 'set' );
            $product->set_stock_status( $qty > 0 ? 'instock' : 'outofstock' );
            $product->save();

            add_action( 'woocommerce_update_product', [ 'LOC_Product_Sync', 'push_product' ], 20 );

            LOC_API::log( 'inventory_pull', $wc_id, $odoo_id, 'ok', "Stock: {$old_qty} → {$qty}" );
        }
    }

    // ════════════════════════════════════════════════════════════════════════
    // REST endpoint — Odoo pushes single-product stock change
    // ════════════════════════════════════════════════════════════════════════

    public static function register_rest_route(): void {
        register_rest_route( 'loc/v1', '/inventory-update', [
            'methods'             => 'POST',
            'callback'            => [ __CLASS__, 'handle_inventory_push' ],
            'permission_callback' => [ 'LOC_Order_Sync', 'verify_webhook_secret' ],
        ] );
    }

    /**
     * Payload from Odoo automated action:
     * {
     *   "odoo_product_id": 42,
     *   "qty_available": 15.0
     * }
     */
    public static function handle_inventory_push( WP_REST_Request $request ): WP_REST_Response {
        $body     = $request->get_json_params();
        $odoo_id  = (int) ( $body['odoo_product_id'] ?? 0 );
        $qty      = (float) ( $body['qty_available']  ?? -1 );

        if ( $odoo_id <= 0 || $qty < 0 ) {
            return new WP_REST_Response( [ 'error' => 'invalid payload' ], 400 );
        }

        // Find the WC product
        $wc_ids = get_posts( [
            'post_type'   => 'product',
            'fields'      => 'ids',
            'meta_query'  => [ [
                'key'   => '_loc_odoo_product_id',
                'value' => $odoo_id,
            ] ],
            'numberposts' => 1,
        ] );

        if ( empty( $wc_ids ) ) {
            return new WP_REST_Response( [ 'error' => 'product not found' ], 404 );
        }

        $product = wc_get_product( $wc_ids[0] );
        if ( ! $product ) {
            return new WP_REST_Response( [ 'error' => 'wc product load failed' ], 500 );
        }

        remove_action( 'woocommerce_update_product', [ 'LOC_Product_Sync', 'push_product' ], 20 );
        wc_update_product_stock( $product, $qty, 'set' );
        $product->set_stock_status( $qty > 0 ? 'instock' : 'outofstock' );
        $product->save();
        add_action( 'woocommerce_update_product', [ 'LOC_Product_Sync', 'push_product' ], 20 );

        LOC_API::log( 'inventory_push', (int) $wc_ids[0], $odoo_id, 'ok', "Stock set to {$qty}" );
        return new WP_REST_Response( [ 'ok' => true, 'qty' => $qty ], 200 );
    }

    // Admin AJAX
    public static function ajax_sync(): void {
        check_ajax_referer( 'loc_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Permission denied.' );
        }
        self::pull_inventory();
        wp_send_json_success( 'Inventory sync complete.' );
    }
}
