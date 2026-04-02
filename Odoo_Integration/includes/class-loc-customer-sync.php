<?php
/**
 * LOC_Customer_Sync — Sync WP/WooCommerce customers to Odoo res.partner.
 *
 * Triggered on:
 *  • New WP user registration
 *  • WooCommerce account creation
 *  • WooCommerce customer data update (billing/shipping address save)
 *
 * Meta stored on WP user:
 *  _loc_odoo_partner_id  int  Odoo res.partner id
 *
 * @package LIMO_Odoo_Connector
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class LOC_Customer_Sync {

    public static function init(): void {
        // New WP user (covers WC account creation via My Account page)
        add_action( 'user_register',                             [ __CLASS__, 'on_user_register' ],  10, 1 );

        // WooCommerce customer address saved on My Account
        add_action( 'woocommerce_customer_save_address',         [ __CLASS__, 'on_address_save' ],   10, 2 );

        // WooCommerce customer details page
        add_action( 'woocommerce_save_account_details',          [ __CLASS__, 'on_account_save' ],   10, 1 );

        // LIMO_Membership: on new member id assigned (hook from class-smp-member-id.php)
        add_action( 'smp_member_id_assigned',                    [ __CLASS__, 'on_member_id' ],      10, 2 );
    }

    // ════════════════════════════════════════════════════════════════════════
    // Event handlers
    // ════════════════════════════════════════════════════════════════════════

    public static function on_user_register( int $user_id ): void {
        self::upsert_partner( $user_id );
    }

    public static function on_address_save( int $user_id, string $load_address ): void {
        self::upsert_partner( $user_id );
    }

    public static function on_account_save( int $user_id ): void {
        self::upsert_partner( $user_id );
    }

    /**
     * When LIMO assigns a member number, tag the Odoo partner with it.
     *
     * @param int    $user_id
     * @param string $member_number  e.g. "MBR000042"
     */
    public static function on_member_id( int $user_id, string $member_number ): void {
        $odoo_id = (int) get_user_meta( $user_id, '_loc_odoo_partner_id', true );
        if ( $odoo_id <= 0 ) {
            $odoo_id = self::upsert_partner( $user_id );
        }
        if ( $odoo_id > 0 ) {
            LOC_API::write( 'res.partner', [ $odoo_id ], [ 'ref' => $member_number ] );
        }
    }

    // ════════════════════════════════════════════════════════════════════════
    // Core upsert
    // ════════════════════════════════════════════════════════════════════════

    /**
     * Create or update an Odoo res.partner from a WP user.
     *
     * @param int $user_id
     * @return int Odoo partner id, or 0 on failure.
     */
    public static function upsert_partner( int $user_id ): int {
        $wp_user = get_userdata( $user_id );
        if ( ! $wp_user ) {
            return 0;
        }

        // Build Odoo partner vals
        $vals = self::build_partner_vals( $wp_user );

        $existing_odoo_id = (int) get_user_meta( $user_id, '_loc_odoo_partner_id', true );

        if ( $existing_odoo_id > 0 ) {
            // Update
            $ok = LOC_API::write( 'res.partner', [ $existing_odoo_id ], $vals );
            if ( $ok ) {
                LOC_API::log( 'customer_sync', $user_id, $existing_odoo_id, 'ok', 'Partner updated' );
                return $existing_odoo_id;
            }
            LOC_API::log( 'customer_sync', $user_id, $existing_odoo_id, 'error', 'write() failed' );
            return 0;
        }

        // Check by email in Odoo first (avoid duplicates on re-installs)
        $found = LOC_API::search( 'res.partner', [ [ 'email', '=', $wp_user->user_email ] ], [ 'limit' => 1 ] );
        if ( is_array( $found ) && ! empty( $found ) ) {
            $odoo_id = (int) $found[0];
            LOC_API::write( 'res.partner', [ $odoo_id ], $vals );
            update_user_meta( $user_id, '_loc_odoo_partner_id', $odoo_id );
            LOC_API::log( 'customer_sync', $user_id, $odoo_id, 'ok', 'Linked to existing partner' );
            return $odoo_id;
        }

        // Create new
        $vals['customer_rank'] = 1;
        $odoo_id = LOC_API::create( 'res.partner', $vals );
        if ( $odoo_id ) {
            update_user_meta( $user_id, '_loc_odoo_partner_id', $odoo_id );
            LOC_API::log( 'customer_sync', $user_id, $odoo_id, 'ok', 'Partner created' );
            return $odoo_id;
        }

        LOC_API::log( 'customer_sync', $user_id, 0, 'error', 'create() failed' );
        return 0;
    }

    // ════════════════════════════════════════════════════════════════════════
    // Build partner vals from WC customer data
    // ════════════════════════════════════════════════════════════════════════

    /**
     * Map WP user + WC customer meta to Odoo res.partner field values.
     *
     * @param WP_User $wp_user
     * @return array<string,mixed>
     */
    private static function build_partner_vals( WP_User $wp_user ): array {
        $wc_customer = new WC_Customer( $wp_user->ID );

        $first = $wc_customer->get_billing_first_name() ?: $wp_user->first_name;
        $last  = $wc_customer->get_billing_last_name()  ?: $wp_user->last_name;
        $name  = trim( "$first $last" ) ?: $wp_user->display_name;

        $vals = [
            'name'    => sanitize_text_field( $name ),
            'email'   => sanitize_email( $wp_user->user_email ),
            'company' => false,
        ];

        $phone = $wc_customer->get_billing_phone();
        if ( $phone ) {
            $vals['phone'] = sanitize_text_field( $phone );
        }

        $street  = $wc_customer->get_billing_address_1();
        $street2 = $wc_customer->get_billing_address_2();
        $city    = $wc_customer->get_billing_city();
        $state   = $wc_customer->get_billing_state();
        $postcode = $wc_customer->get_billing_postcode();
        $country  = $wc_customer->get_billing_country();

        if ( $street )   { $vals['street']  = sanitize_text_field( $street ); }
        if ( $street2 )  { $vals['street2'] = sanitize_text_field( $street2 ); }
        if ( $city )     { $vals['city']    = sanitize_text_field( $city ); }
        if ( $postcode ) { $vals['zip']     = sanitize_text_field( $postcode ); }

        // Resolve Odoo country id by ISO code
        if ( $country ) {
            $country_ids = LOC_API::search( 'res.country', [ [ 'code', '=', strtoupper( $country ) ] ], [ 'limit' => 1 ] );
            if ( is_array( $country_ids ) && ! empty( $country_ids ) ) {
                $vals['country_id'] = (int) $country_ids[0];

                // Resolve state
                if ( $state ) {
                    $state_ids = LOC_API::search(
                        'res.country.state',
                        [ [ 'code', '=', strtoupper( $state ) ], [ 'country_id', '=', $vals['country_id'] ] ],
                        [ 'limit' => 1 ]
                    );
                    if ( is_array( $state_ids ) && ! empty( $state_ids ) ) {
                        $vals['state_id'] = (int) $state_ids[0];
                    }
                }
            }
        }

        // LIMO membership number as external reference
        $member_number = get_user_meta( $wp_user->ID, 'smp_member_number', true );
        if ( $member_number ) {
            $vals['ref'] = sanitize_text_field( $member_number );
        }

        // LIMO tier info as a note
        $tier_index = (int) get_user_meta( $wp_user->ID, 'smp_tier_index', true );
        if ( $tier_index > 0 ) {
            $vals['comment'] = "LIMO Membership Tier: {$tier_index}";
        }

        return $vals;
    }
}
