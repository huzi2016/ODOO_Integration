<?php
/**
 * LOC_Order_Sync — Full order lifecycle: WC order → Odoo sale.order → invoice → delivery.
 *
 * ┌────────────────────────────────────────────────────────────────────┐
 * │  WooCommerce         →  Odoo                                       │
 * │  wc-processing       →  sale.order (confirmed)                     │
 * │  wc-completed        →  account.move (invoice posted) + validate   │
 * │  Odoo delivery done  →  wc-completed + tracking meta               │
 * └────────────────────────────────────────────────────────────────────┘
 *
 * Meta stored on WC orders:
 *  _loc_odoo_sale_id      int   Odoo sale.order id
 *  _loc_odoo_invoice_id   int   Odoo account.move id
 *  _loc_odoo_delivery_id  int   Odoo stock.picking id
 *
 * @package LIMO_Odoo_Connector
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class LOC_Order_Sync {

    public static function init(): void {
        // New order placed (status: pending / processing)
        add_action( 'woocommerce_checkout_order_created',           [ __CLASS__, 'on_order_created' ],     10, 1 );

        // Status transitions
        add_action( 'woocommerce_order_status_processing',          [ __CLASS__, 'on_processing' ],        10, 1 );
        add_action( 'woocommerce_order_status_completed',           [ __CLASS__, 'on_completed' ],         10, 1 );
        add_action( 'woocommerce_order_status_cancelled',           [ __CLASS__, 'on_cancelled' ],         10, 1 );
        add_action( 'woocommerce_order_status_refunded',            [ __CLASS__, 'on_refunded' ],          10, 1 );

        // Webhook endpoint to receive Odoo shipping status callbacks
        add_action( 'rest_api_init', [ __CLASS__, 'register_webhook' ] );

        // Admin AJAX: manual push single order
        add_action( 'wp_ajax_loc_push_order', [ __CLASS__, 'ajax_push_order' ] );
    }

    // ════════════════════════════════════════════════════════════════════════
    // Event handlers
    // ════════════════════════════════════════════════════════════════════════

    public static function on_order_created( WC_Order $order ): void {
        self::create_sale_order( $order );
    }

    public static function on_processing( int $order_id ): void {
        $order    = wc_get_order( $order_id );
        $sale_id  = (int) $order->get_meta( '_loc_odoo_sale_id' );
        if ( $sale_id <= 0 ) {
            $sale_id = self::create_sale_order( $order );
        }
        // Confirm the sale order in Odoo
        if ( $sale_id > 0 ) {
            LOC_API::call( 'sale.order', 'action_confirm', [ [ $sale_id ] ] );
            LOC_API::log( 'order_confirm', $order_id, $sale_id, 'ok', 'Sale order confirmed' );
        }
    }

    public static function on_completed( int $order_id ): void {
        $order   = wc_get_order( $order_id );
        $sale_id = (int) $order->get_meta( '_loc_odoo_sale_id' );
        if ( $sale_id <= 0 ) {
            return;
        }
        self::create_and_post_invoice( $order, $sale_id );
    }

    public static function on_cancelled( int $order_id ): void {
        $order   = wc_get_order( $order_id );
        $sale_id = (int) $order->get_meta( '_loc_odoo_sale_id' );
        if ( $sale_id > 0 ) {
            LOC_API::call( 'sale.order', 'action_cancel', [ [ $sale_id ] ] );
            LOC_API::log( 'order_cancel', $order_id, $sale_id, 'ok', 'Cancelled in Odoo' );
        }
    }

    public static function on_refunded( int $order_id ): void {
        $order      = wc_get_order( $order_id );
        $invoice_id = (int) $order->get_meta( '_loc_odoo_invoice_id' );
        if ( $invoice_id > 0 ) {
            // Create a credit note (reverse the invoice)
            $result = LOC_API::call( 'account.move', 'button_draft', [ [ $invoice_id ] ] );
            LOC_API::log( 'order_refund', $order_id, $invoice_id, $result ? 'ok' : 'error', 'Credit note attempt' );
        }
    }

    // ════════════════════════════════════════════════════════════════════════
    // Create sale.order in Odoo
    // ════════════════════════════════════════════════════════════════════════

    /**
     * Map a WC order to an Odoo sale.order and create it.
     *
     * @param WC_Order $order
     * @return int Odoo sale.order id, or 0.
     */
    public static function create_sale_order( WC_Order $order ): int {
        $order_id = $order->get_id();

        // Ensure the customer exists in Odoo
        $customer_id = (int) $order->get_customer_id();
        $odoo_partner_id = 0;
        if ( $customer_id > 0 ) {
            $odoo_partner_id = (int) get_user_meta( $customer_id, '_loc_odoo_partner_id', true );
            if ( $odoo_partner_id <= 0 ) {
                $odoo_partner_id = LOC_Customer_Sync::upsert_partner( $customer_id );
            }
        } else {
            // Guest checkout — create a one-off partner
            $odoo_partner_id = self::create_guest_partner( $order );
        }

        if ( $odoo_partner_id <= 0 ) {
            LOC_API::log( 'order_push', $order_id, 0, 'error', 'Could not resolve Odoo partner' );
            return 0;
        }

        // Build order lines
        $order_lines = [];
        foreach ( $order->get_items() as $item ) {
            $product    = $item->get_product();
            $odoo_prod  = 0;
            if ( $product ) {
                $odoo_prod = (int) get_post_meta( $product->get_id(), '_loc_odoo_product_id', true );
            }

            if ( $odoo_prod <= 0 ) {
                // Fallback: find by name
                $found = LOC_API::search(
                    'product.template',
                    [ [ 'name', '=', $item->get_name() ] ],
                    [ 'limit' => 1 ]
                );
                $odoo_prod = ( is_array( $found ) && ! empty( $found ) ) ? (int) $found[0] : 0;
            }

            $line = [
                'product_id'    => $odoo_prod ?: false,
                'name'          => $item->get_name(),
                'product_uom_qty' => (float) $item->get_quantity(),
                'price_unit'    => (float) $order->get_item_subtotal( $item ),
            ];
            $order_lines[] = [ 0, 0, $line ];
        }

        // Shipping as a separate line
        $shipping_total = (float) $order->get_shipping_total();
        if ( $shipping_total > 0 ) {
            $order_lines[] = [ 0, 0, [
                'name'            => 'Shipping: ' . $order->get_shipping_method(),
                'product_uom_qty' => 1,
                'price_unit'      => $shipping_total,
            ]];
        }

        // Points discount (from LIMO Membership plugin)
        $pts_used = (int) $order->get_meta( '_smp_pts_used' );
        if ( $pts_used > 0 ) {
            $pts_amount = (float) $order->get_meta( '_smp_pts_amount' );
            if ( $pts_amount > 0 ) {
                $order_lines[] = [ 0, 0, [
                    'name'            => "Points Redemption ({$pts_used} pts)",
                    'product_uom_qty' => 1,
                    'price_unit'      => -abs( $pts_amount ),
                ]];
            }
        }

        $sale_vals = [
            'partner_id'         => $odoo_partner_id,
            'client_order_ref'   => 'WC#' . $order_id,
            'note'               => "WooCommerce order #{$order_id}",
            'order_line'         => $order_lines,
            'date_order'         => $order->get_date_created()?->format( 'Y-m-d H:i:s' ) ?? gmdate( 'Y-m-d H:i:s' ),
        ];

        // Add shipping address if different
        $shipping_partner_id = self::maybe_create_shipping_partner( $order, $odoo_partner_id );
        if ( $shipping_partner_id > 0 ) {
            $sale_vals['partner_shipping_id'] = $shipping_partner_id;
        }

        $sale_id = LOC_API::create( 'sale.order', $sale_vals );
        if ( $sale_id ) {
            $order->update_meta_data( '_loc_odoo_sale_id', $sale_id );
            $order->save_meta_data();
            $order->add_order_note( "Odoo sale order created: SO#{$sale_id}" );
            LOC_API::log( 'order_push', $order_id, $sale_id, 'ok', 'Sale order created' );
            return $sale_id;
        }

        LOC_API::log( 'order_push', $order_id, 0, 'error', 'create() failed' );
        return 0;
    }

    // ════════════════════════════════════════════════════════════════════════
    // Invoice (account.move)
    // ════════════════════════════════════════════════════════════════════════

    /**
     * Create and post an invoice for a confirmed sale order.
     *
     * @param WC_Order $order
     * @param int      $sale_id Odoo sale.order id.
     */
    private static function create_and_post_invoice( WC_Order $order, int $sale_id ): void {
        $order_id = $order->get_id();

        // Trigger Odoo's native invoice creation from sale lines
        $invoice_ids = LOC_API::call(
            'sale.order',
            'action_invoice_create',
            [ [ $sale_id ] ]
        );

        // Fallback: newer Odoo versions use _create_invoices
        if ( ! is_array( $invoice_ids ) || empty( $invoice_ids ) ) {
            LOC_API::call( 'sale.order', '_create_invoices', [ [ $sale_id ] ] );
            // Fetch the newly created invoice
            $invoice_ids = LOC_API::search(
                'account.move',
                [ [ 'invoice_origin', 'like', 'WC#' . $order_id ] ],
                [ 'limit' => 1 ]
            );
        }

        if ( ! is_array( $invoice_ids ) || empty( $invoice_ids ) ) {
            LOC_API::log( 'invoice_create', $order_id, $sale_id, 'error', 'No invoice created' );
            return;
        }

        $invoice_id = (int) $invoice_ids[0];

        // Post (confirm) the invoice
        LOC_API::call( 'account.move', 'action_post', [ [ $invoice_id ] ] );

        $order->update_meta_data( '_loc_odoo_invoice_id', $invoice_id );
        $order->save_meta_data();
        $order->add_order_note( "Odoo invoice created and posted: INV#{$invoice_id}" );
        LOC_API::log( 'invoice_post', $order_id, $invoice_id, 'ok', 'Invoice posted' );

        // Trigger delivery (stock.picking) if not already done
        self::validate_delivery( $order, $sale_id );
    }

    // ════════════════════════════════════════════════════════════════════════
    // Delivery (stock.picking)
    // ════════════════════════════════════════════════════════════════════════

    /**
     * Validate the delivery order in Odoo.
     *
     * @param WC_Order $order
     * @param int      $sale_id
     */
    private static function validate_delivery( WC_Order $order, int $sale_id ): void {
        $order_id = $order->get_id();

        // Find the outgoing delivery for this sale order
        $pickings = LOC_API::search_read(
            'stock.picking',
            [
                [ 'sale_id', '=', $sale_id ],
                [ 'picking_type_code', '=', 'outgoing' ],
                [ 'state', 'not in', [ 'done', 'cancel' ] ],
            ],
            [ 'id', 'name', 'state' ],
            [ 'limit' => 1 ]
        );

        if ( ! is_array( $pickings ) || empty( $pickings ) ) {
            LOC_API::log( 'delivery', $order_id, $sale_id, 'error', 'No outgoing picking found' );
            return;
        }

        $picking_id = (int) $pickings[0]['id'];

        // Set all move quantities to done (immediate transfer)
        LOC_API::call( 'stock.picking', 'action_set_quantities_to_reservation', [ [ $picking_id ] ] );

        // Validate the transfer
        $result = LOC_API::call( 'stock.picking', 'button_validate', [ [ $picking_id ] ] );

        $order->update_meta_data( '_loc_odoo_delivery_id', $picking_id );
        $order->save_meta_data();
        $order->add_order_note( "Odoo delivery validated: {$pickings[0]['name']}" );
        LOC_API::log( 'delivery', $order_id, $picking_id, 'ok', 'Delivery validated' );
    }

    // ════════════════════════════════════════════════════════════════════════
    // REST Webhook: Odoo → WordPress (shipping status update)
    // ════════════════════════════════════════════════════════════════════════

    public static function register_webhook(): void {
        register_rest_route( 'loc/v1', '/odoo-callback', [
            'methods'             => 'POST',
            'callback'            => [ __CLASS__, 'handle_odoo_callback' ],
            'permission_callback' => [ __CLASS__, 'verify_webhook_secret' ],
        ] );
    }

    public static function verify_webhook_secret( WP_REST_Request $request ): bool {
        $secret = (string) get_option( 'loc_webhook_secret', '' );
        if ( empty( $secret ) ) {
            return false;
        }
        return hash_equals( $secret, (string) $request->get_header( 'X-Odoo-Secret' ) );
    }

    /**
     * Handle Odoo → WP callback when a delivery is done.
     *
     * Expected payload:
     * {
     *   "event":      "delivery_done",
     *   "wc_order_id": 123,
     *   "tracking":   "SF1234567890",
     *   "carrier":    "顺丰速运"
     * }
     */
    public static function handle_odoo_callback( WP_REST_Request $request ): WP_REST_Response {
        $body     = $request->get_json_params();
        $event    = sanitize_text_field( $body['event']         ?? '' );
        $order_id = (int) ( $body['wc_order_id']               ?? 0 );
        $tracking = sanitize_text_field( $body['tracking']      ?? '' );
        $carrier  = sanitize_text_field( $body['carrier']       ?? '' );

        if ( ! $order_id ) {
            return new WP_REST_Response( [ 'error' => 'missing wc_order_id' ], 400 );
        }

        $order = wc_get_order( $order_id );
        if ( ! $order ) {
            return new WP_REST_Response( [ 'error' => 'order not found' ], 404 );
        }

        if ( $event === 'delivery_done' ) {
            if ( $tracking ) {
                $order->update_meta_data( '_loc_tracking_number', $tracking );
                $order->update_meta_data( '_loc_carrier',         $carrier );
            }
            $order->update_status(
                'completed',
                "Odoo delivery confirmed. Tracking: {$tracking} ({$carrier})"
            );
            $order->save();
            LOC_API::log( 'webhook_delivery', $order_id, 0, 'ok', "Tracking: {$tracking}" );
        }

        return new WP_REST_Response( [ 'ok' => true ], 200 );
    }

    // ════════════════════════════════════════════════════════════════════════
    // Helpers
    // ════════════════════════════════════════════════════════════════════════

    /**
     * Create a minimal Odoo partner for guest checkout.
     */
    private static function create_guest_partner( WC_Order $order ): int {
        $name  = trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() );
        $email = $order->get_billing_email();

        // Check if already exists
        $found = LOC_API::search( 'res.partner', [ [ 'email', '=', $email ] ], [ 'limit' => 1 ] );
        if ( is_array( $found ) && ! empty( $found ) ) {
            return (int) $found[0];
        }

        return (int) LOC_API::create( 'res.partner', [
            'name'          => sanitize_text_field( $name ?: $email ),
            'email'         => sanitize_email( $email ),
            'phone'         => sanitize_text_field( $order->get_billing_phone() ),
            'customer_rank' => 1,
            'comment'       => 'Guest checkout — WC#' . $order->get_id(),
        ] );
    }

    /**
     * Create a child shipping partner in Odoo if the shipping address differs.
     */
    private static function maybe_create_shipping_partner( WC_Order $order, int $parent_id ): int {
        $ship_name = trim(
            $order->get_shipping_first_name() . ' ' . $order->get_shipping_last_name()
        );
        if ( ! $ship_name || $order->get_shipping_address_1() === $order->get_billing_address_1() ) {
            return 0;
        }

        $existing = LOC_API::search(
            'res.partner',
            [ [ 'parent_id', '=', $parent_id ], [ 'type', '=', 'delivery' ] ],
            [ 'limit' => 1 ]
        );
        if ( is_array( $existing ) && ! empty( $existing ) ) {
            return (int) $existing[0];
        }

        return (int) LOC_API::create( 'res.partner', [
            'name'      => sanitize_text_field( $ship_name ),
            'parent_id' => $parent_id,
            'type'      => 'delivery',
            'street'    => sanitize_text_field( $order->get_shipping_address_1() ),
            'street2'   => sanitize_text_field( $order->get_shipping_address_2() ),
            'city'      => sanitize_text_field( $order->get_shipping_city() ),
            'zip'       => sanitize_text_field( $order->get_shipping_postcode() ),
        ] );
    }

    // Admin AJAX: push single order
    public static function ajax_push_order(): void {
        check_ajax_referer( 'loc_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Permission denied.' );
        }
        $order_id = (int) ( $_POST['order_id'] ?? 0 );
        $order    = wc_get_order( $order_id );
        if ( ! $order ) {
            wp_send_json_error( 'Order not found.' );
        }
        $sale_id = self::create_sale_order( $order );
        wp_send_json_success( [ 'odoo_sale_id' => $sale_id ] );
    }
}
