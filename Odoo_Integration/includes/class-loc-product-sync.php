<?php
/**
 * LOC_Product_Sync — Product data from Odoo into WooCommerce (one-way).
 *
 * Flow (Odoo → WP): scheduled pull copies Odoo product data to WooCommerce.
 * WordPress does not write product.template fields back to Odoo (no push).
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

        // ── Manual trigger via admin AJAX ───────────────────────────────────
        add_action( 'wp_ajax_loc_sync_products', [ __CLASS__, 'ajax_sync' ] );
    }

    // ════════════════════════════════════════════════════════════════════════
    // Pull: Odoo → WooCommerce
    // ════════════════════════════════════════════════════════════════════════

    /**
     * Pull all active products from Odoo and upsert into WooCommerce.
     *
     * Uses search() + read() with offset pagination (not search_read): some JSON-2 stacks
     * return only one row per search_read; search+read is more reliable. Filters:
     * loc_odoo_product_pull_batch_size, loc_odoo_product_pull_max_pages, loc_odoo_product_pull_order.
     */
    public static function pull_all(): void {
        if ( function_exists( 'set_time_limit' ) ) {
            @set_time_limit( 300 );
        }

        // Inventory > Products lists product.product (variants); we sync product.template (fewer rows). Filter to widen/narrow: loc_odoo_product_template_domain.
        $domain = apply_filters(
            'loc_odoo_product_template_domain',
            [
                [ 'active', '=', true ],
                [ 'sale_ok', '=', true ],
            ]
        );
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
        $skipped  = 0;
        $page     = 0;
        $fetched  = 0;

        $order = (string) apply_filters( 'loc_odoo_product_pull_order', 'id asc' );

        while ( $page < $max_pages ) {
            ++$page;
            $ids = LOC_API::search(
                'product.template',
                $domain,
                [
                    'limit'  => $batch,
                    'offset' => $offset,
                    'order'  => $order,
                ]
            );

            if ( ! is_array( $ids ) ) {
                if ( $offset === 0 ) {
                    LOC_API::log( 'product_pull', 0, 0, 'error', 'product.template search failed' );
                }
                break;
            }

            if ( $ids === [] ) {
                break;
            }

            $records = LOC_API::read( 'product.template', $ids, self::ODOO_FIELDS );
            if ( ! is_array( $records ) ) {
                if ( $offset === 0 ) {
                    LOC_API::log( 'product_pull', 0, 0, 'error', 'product.template read failed after search' );
                }
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
                $out = self::upsert_wc_product( $rec );
                if ( $out === 'skipped' ) {
                    ++$skipped;
                } elseif ( $out === 'saved' ) {
                    ++$total;
                }
            }

            $fetched = count( $ids );
            $offset += $fetched;
        }

        $suffix = '';
        if ( $page >= $max_pages && $fetched >= $batch ) {
            $suffix = " — stopped at page cap ({$max_pages}); more products may exist in Odoo (raise loc_odoo_product_pull_max_pages).";
        }

        $skip_msg = $skipped > 0 ? " {$skipped} duplicate Internal Reference(s) skipped (same SKU already mapped)." : '';
        LOC_API::log( 'product_pull', 0, 0, 'ok', "Pull finished: {$total} product(s) saved.{$skip_msg}{$suffix}" );
    }

    /**
     * WooCommerce SKUs must be unique. Empty SKU allows unlimited duplicate products on each sync — always use a stable SKU.
     * Odoo often has several product.template rows sharing the same Internal Reference (non-empty case).
     */
    private static function fallback_sku_for_template( int $odoo_template_id ): string {
        return (string) apply_filters( 'loc_odoo_empty_internal_reference_sku', 'ODOO-T-' . $odoo_template_id, $odoo_template_id );
    }

    /**
     * Remove duplicate-resolution suffixes (-T123) that we append when Internal Reference collides.
     * If those strings were pushed back to Odoo default_code and re-imported, each sync would append again
     * (snowball: AN 5960-T930-T1197-T…). Strip all trailing -T{digits} until stable.
     *
     * @param string $code Raw default_code from Odoo or WC SKU before collision logic.
     */
    private static function strip_duplicate_resolution_suffixes( string $code ): string {
        $code = trim( $code );
        if ( $code === '' ) {
            return '';
        }
        $stripped = $code;
        $prev     = '';
        while ( $prev !== $stripped ) {
            $prev     = $stripped;
            $stripped = preg_replace( '/-T\d+$/', '', $stripped );
        }
        return trim( $stripped );
    }

    /**
     * Odoo may store Internal Reference as [AN5648]; normalize so duplicate detection matches WooCommerce SKU AN5648.
     */
    private static function normalize_internal_reference( string $code ): string {
        $code = trim( $code );
        if ( $code === '' ) {
            return '';
        }
        return trim( $code, " \t\n\r\0\x0B[]" );
    }

    /**
     * WooCommerce SKUs must be unique. Several Odoo product.template rows often share the same Internal Reference.
     *
     * Default: only one WC product per Internal Reference — extra templates are skipped (see filter
     * loc_odoo_skip_duplicate_internal_reference_templates). Legacy behaviour: append -T&lt;template_id&gt;.
     *
     * @return string|null SKU to use, or null when this template is skipped as a duplicate.
     */
    private static function resolve_pull_sku( string $default_code, int $odoo_template_id ): ?string {
        $base = sanitize_text_field( $default_code );
        if ( $base === '' ) {
            return self::fallback_sku_for_template( $odoo_template_id );
        }
        if ( ! function_exists( 'wc_get_product_id_by_sku' ) ) {
            return $base;
        }
        $pid = wc_get_product_id_by_sku( $base );
        if ( ! $pid ) {
            return $base;
        }
        $linked_odoo = (int) get_post_meta( $pid, '_loc_odoo_product_id', true );
        if ( $linked_odoo === $odoo_template_id ) {
            return $base;
        }
        if ( $linked_odoo === 0 ) {
            return $base;
        }
        if ( apply_filters( 'loc_odoo_skip_duplicate_internal_reference_templates', true ) ) {
            return null;
        }
        $extra = (string) apply_filters( 'loc_odoo_duplicate_sku_suffix', '-T' . $odoo_template_id, $odoo_template_id, $base );
        return $base . $extra;
    }

    /**
     * Find existing WC product: meta first, then canonical SKU for templates without Internal Reference.
     */
    private static function find_wc_product_id_for_odoo_template( int $odoo_id ): int {
        if ( $odoo_id < 1 ) {
            return 0;
        }
        $ids = wc_get_products(
            [
                'meta_key'   => '_loc_odoo_product_id',
                'meta_value' => $odoo_id,
                'return'     => 'ids',
                'limit'      => 1,
                'status'     => 'any',
            ]
        );
        if ( ! empty( $ids ) ) {
            return (int) $ids[0];
        }
        if ( function_exists( 'wc_get_product_id_by_sku' ) ) {
            $sku = self::fallback_sku_for_template( $odoo_id );
            $pid = wc_get_product_id_by_sku( $sku );
            if ( $pid ) {
                return (int) $pid;
            }
        }
        return 0;
    }

    /**
     * Create or update a WooCommerce product from an Odoo product.template record.
     *
     * @param array $rec Odoo product.template fields.
     * @return 'saved'|'skipped'|'error'
     */
    private static function upsert_wc_product( array $rec ): string {
        $odoo_id = (int) $rec['id'];

        $base_code = ! empty( $rec['default_code'] ) ? (string) $rec['default_code'] : '';
        $base_code = self::strip_duplicate_resolution_suffixes( $base_code );
        $base_code = self::normalize_internal_reference( $base_code );
        $base_code = (string) apply_filters( 'loc_odoo_normalize_internal_reference', $base_code, $rec );

        $sku = self::resolve_pull_sku( $base_code, $odoo_id );
        if ( $sku === null ) {
            if ( apply_filters( 'loc_odoo_log_skipped_duplicate_templates', false ) ) {
                LOC_API::log( 'product_pull', 0, $odoo_id, 'ok', 'Skipped duplicate Internal Reference (SKU already used by another Odoo template).' );
            }
            return 'skipped';
        }

        $existing_wc_id = self::find_wc_product_id_for_odoo_template( $odoo_id );
        if ( $existing_wc_id <= 0 && function_exists( 'wc_get_product_id_by_sku' ) && $sku !== '' ) {
            $by_sku = wc_get_product_id_by_sku( $sku );
            if ( $by_sku ) {
                $linked = (int) get_post_meta( $by_sku, '_loc_odoo_product_id', true );
                if ( $linked === 0 || $linked === $odoo_id ) {
                    $existing_wc_id = (int) $by_sku;
                }
            }
        }

        if ( $existing_wc_id > 0 ) {
            $product = wc_get_product( $existing_wc_id );
            if ( ! $product ) {
                $product = new WC_Product_Simple();
            }
        } else {
            $product = new WC_Product_Simple();
        }

        // Map Odoo fields → WC
        $product->set_name( sanitize_text_field( $rec['name'] ) );
        $product->set_regular_price( (string) $rec['list_price'] );

        if ( ! empty( $rec['description_sale'] ) ) {
            $product->set_description( wp_kses_post( $rec['description_sale'] ) );
        }

        $product->set_sku( $sku );

        // Manage stock based on Odoo qty
        $product->set_manage_stock( true );
        $product->set_stock_quantity( (float) $rec['qty_available'] );
        $product->set_stock_status( $rec['qty_available'] > 0 ? 'instock' : 'outofstock' );

        $wc_id = 0;
        try {
            $wc_id = $product->save();
        } catch ( \Throwable $e ) {
            LOC_API::log( 'product_pull', 0, $odoo_id, 'error', 'WC save failed: ' . $e->getMessage() );
            return 'error';
        }

        if ( $wc_id ) {
            update_post_meta( $wc_id, '_loc_odoo_product_id',      $odoo_id );
            update_post_meta( $wc_id, '_loc_odoo_product_tmpl_id', $odoo_id );
            update_post_meta( $wc_id, '_loc_last_synced',          gmdate( 'Y-m-d H:i:s' ) );
            if ( apply_filters( 'loc_odoo_log_each_product_pull_success', false ) ) {
                LOC_API::log( 'product_pull', $wc_id, $odoo_id, 'ok', "Upserted '{$rec['name']}'" );
            }
            return 'saved';
        }

        return 'error';
    }

    // ════════════════════════════════════════════════════════════════════════
    // Push disabled: WooCommerce must not write product.template to Odoo
    // ════════════════════════════════════════════════════════════════════════

    /**
     * Legacy hook target — intentionally no-op. Product data is Odoo → WP only.
     *
     * @param int $product_id WC product post id.
     */
    public static function push_product( int $product_id ): void {
        // Intentionally empty — product master data is not written to Odoo.
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
