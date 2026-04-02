<?php
/**
 * LOC_Product_Sync — Two-way product data and inventory sync.
 *
 * Flow A  (Odoo → WP)  : scheduled pull — copies Odoo product data to WooCommerce.
 * Flow B  (WP  → Odoo) : push on WC product save — keeps Odoo price/description in sync.
 *
 * Meta keys stored on WC products:
 *   _loc_odoo_product_id      int   Odoo product.template id
 *   _loc_odoo_product_tmpl_id int   Same (alias for clarity)
 *   _loc_last_synced          str   ISO datetime of last successful sync
 *
 * @package LIMO_Odoo_Connector
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class LOC_Product_Sync {

    // Odoo product.template fields (no qty_available: invalid on template in Odoo 17+ / 19 — use variants via LOC_API::sum_qty_available_by_template_ids).
    const ODOO_FIELDS = [ 'id', 'name', 'description_sale', 'list_price', 'active', 'default_code' ];

    public static function init(): void {
        // ── Scheduled pull (Odoo → WP) ──────────────────────────────────────
        add_action( 'loc_sync_products_from_odoo', [ __CLASS__, 'pull_all' ] );
        if ( ! wp_next_scheduled( 'loc_sync_products_from_odoo' ) ) {
            wp_schedule_event( time(), 'hourly', 'loc_sync_products_from_odoo' );
        }

        // ── Push on WC product save (WP → Odoo) ────────────────────────────
        add_action( 'woocommerce_update_product', [ __CLASS__, 'push_product' ], 20 );
        add_action( 'woocommerce_new_product',    [ __CLASS__, 'push_product' ], 20 );

        // ── Manual trigger via admin AJAX ───────────────────────────────────
        add_action( 'wp_ajax_loc_sync_products', [ __CLASS__, 'ajax_sync' ] );
    }

    // ════════════════════════════════════════════════════════════════════════
    // Pull: Odoo → WooCommerce
    // ════════════════════════════════════════════════════════════════════════

    /**
     * Pull all active products from Odoo and upsert into WooCommerce.
     *
     * Uses offset pagination: many Odoo instances cap how many rows one search_read returns
     * (often 80), so a single large limit is not enough. Filter: loc_odoo_product_pull_batch_size.
     */
    public static function pull_all(): void {
        if ( function_exists( 'set_time_limit' ) ) {
            @set_time_limit( 300 );
        }

        $domain = [ [ 'active', '=', true ], [ 'sale_ok', '=', true ] ];
        // Ask up to this many rows per request; Odoo often caps lower (~80). Do NOT stop when count < limit — that was skipping all following pages.
        $batch = (int) apply_filters( 'loc_odoo_product_pull_batch_size', 80 );
        if ( $batch < 1 ) {
            $batch = 80;
        }

        $max_pages = (int) apply_filters( 'loc_odoo_product_pull_max_pages', 500 );
        if ( $max_pages < 1 ) {
            $max_pages = 500;
        }

        $offset   = 0;
        $total    = 0;
        $page     = 0;
        $fetched  = 0;

        while ( $page < $max_pages ) {
            ++$page;
            $records = LOC_API::search_read(
                'product.template',
                $domain,
                self::ODOO_FIELDS,
                [
                    'limit'  => $batch,
                    'offset' => $offset,
                ]
            );

            if ( ! is_array( $records ) ) {
                if ( $offset === 0 ) {
                    LOC_API::log( 'product_pull', 0, 0, 'error', 'search_read failed or unexpected response shape' );
                }
                break;
            }

            if ( $records === [] ) {
                break;
            }

            $batch_tmpl_ids = [];
            foreach ( $records as $rec ) {
                if ( is_array( $rec ) && isset( $rec['id'] ) ) {
                    $batch_tmpl_ids[] = (int) $rec['id'];
                }
            }
            $qty_by_tmpl = LOC_API::sum_qty_available_by_template_ids( $batch_tmpl_ids );

            foreach ( $records as $rec ) {
                if ( ! is_array( $rec ) || ! isset( $rec['id'] ) ) {
                    continue;
                }
                $tid = (int) $rec['id'];
                $rec['qty_available'] = $qty_by_tmpl[ $tid ] ?? 0.0;
                self::upsert_wc_product( $rec );
                ++$total;
            }

            $fetched  = count( $records );
            $offset  += $fetched;
        }

        $suffix = '';
        if ( $page >= $max_pages && $fetched >= $batch ) {
            $suffix = " — stopped at page cap ({$max_pages}); more products may exist in Odoo (raise loc_odoo_product_pull_max_pages).";
        }

        LOC_API::log( 'product_pull', 0, 0, 'ok', "Pull finished: {$total} Odoo product template(s) processed.{$suffix}" );
    }

    /**
     * Create or update a WooCommerce product from an Odoo product.template record.
     *
     * @param array $rec Odoo product.template fields.
     */
    private static function upsert_wc_product( array $rec ): void {
        $odoo_id = (int) $rec['id'];

        // Find existing WC product by odoo id meta
        $existing_ids = wc_get_products( [
            'meta_key'   => '_loc_odoo_product_id',
            'meta_value' => $odoo_id,
            'return'     => 'ids',
            'limit'      => 1,
        ] );

        if ( ! empty( $existing_ids ) ) {
            $product = wc_get_product( $existing_ids[0] );
        } else {
            $product = new WC_Product_Simple();
        }

        // Map Odoo fields → WC
        $product->set_name( sanitize_text_field( $rec['name'] ) );
        $product->set_regular_price( (string) $rec['list_price'] );

        if ( ! empty( $rec['description_sale'] ) ) {
            $product->set_description( wp_kses_post( $rec['description_sale'] ) );
        }

        if ( ! empty( $rec['default_code'] ) ) {
            $product->set_sku( sanitize_text_field( $rec['default_code'] ) );
        }

        // Manage stock based on Odoo qty
        $product->set_manage_stock( true );
        $product->set_stock_quantity( (float) $rec['qty_available'] );
        $product->set_stock_status( $rec['qty_available'] > 0 ? 'instock' : 'outofstock' );

        // Suppress re-push to Odoo while saving this product
        remove_action( 'woocommerce_update_product', [ __CLASS__, 'push_product' ], 20 );

        $wc_id = 0;
        try {
            $wc_id = $product->save();
        } catch ( \Throwable $e ) {
            LOC_API::log( 'product_pull', 0, $odoo_id, 'error', 'WC save failed: ' . $e->getMessage() );
        } finally {
            add_action( 'woocommerce_update_product', [ __CLASS__, 'push_product' ], 20 );
        }

        if ( $wc_id ) {
            update_post_meta( $wc_id, '_loc_odoo_product_id',      $odoo_id );
            update_post_meta( $wc_id, '_loc_odoo_product_tmpl_id', $odoo_id );
            update_post_meta( $wc_id, '_loc_last_synced',          gmdate( 'Y-m-d H:i:s' ) );
            LOC_API::log( 'product_pull', $wc_id, $odoo_id, 'ok', "Upserted '{$rec['name']}'" );
        }
    }

    // ════════════════════════════════════════════════════════════════════════
    // Push: WooCommerce → Odoo
    // ════════════════════════════════════════════════════════════════════════

    /**
     * Push a WooCommerce product save event to Odoo.
     *
     * @param int $product_id WC product post id.
     */
    public static function push_product( int $product_id ): void {
        $product = wc_get_product( $product_id );
        if ( ! $product ) {
            return;
        }

        $odoo_id = (int) get_post_meta( $product_id, '_loc_odoo_product_id', true );

        $vals = [
            'name'             => $product->get_name(),
            'list_price'       => (float) $product->get_regular_price(),
            'description_sale' => wp_strip_all_tags( $product->get_description() ),
        ];

        if ( $sku = $product->get_sku() ) {
            $vals['default_code'] = $sku;
        }

        if ( $odoo_id > 0 ) {
            // Update existing Odoo product
            $ok = LOC_API::write( 'product.template', [ $odoo_id ], $vals );
            LOC_API::log( 'product_push', $product_id, $odoo_id, $ok ? 'ok' : 'error', $ok ? 'Updated' : 'Write failed' );
        } else {
            // Create new Odoo product
            $vals['type']    = 'consu';   // storable goods
            $vals['sale_ok'] = true;
            $new_id = LOC_API::create( 'product.template', $vals );
            if ( $new_id ) {
                update_post_meta( $product_id, '_loc_odoo_product_id',      $new_id );
                update_post_meta( $product_id, '_loc_odoo_product_tmpl_id', $new_id );
                LOC_API::log( 'product_push', $product_id, $new_id, 'ok', 'Created in Odoo' );
            } else {
                LOC_API::log( 'product_push', $product_id, 0, 'error', 'Create failed' );
            }
        }
    }

    // ════════════════════════════════════════════════════════════════════════
    // Admin AJAX manual sync
    // ════════════════════════════════════════════════════════════════════════

    public static function ajax_sync(): void {
        check_ajax_referer( 'loc_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Permission denied.' );
        }
        self::pull_all();
        wp_send_json_success( 'Product sync complete.' );
    }
}
