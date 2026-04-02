<?php
/**
 * LOC_API — Odoo JSON-RPC client.
 *
 * All HTTP calls go through here. Credentials are read from wp-config.php
 * constants or from the plugin settings page.
 *
 * Required wp-config.php constants (or set via admin settings):
 *   LOC_ODOO_URL      e.g. https://mycompany.odoo.com
 *   LOC_ODOO_DB       e.g. mycompany
 *   LOC_ODOO_USER     e.g. admin@mycompany.com
 *   LOC_ODOO_PASSWORD e.g. secret
 *
 * @package LIMO_Odoo_Connector
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class LOC_API {

    // ── Singleton session state ──────────────────────────────────────────────

    /** Odoo user-id returned by authenticate(). */
    private static ?int $uid = null;

    // ── Config helpers ───────────────────────────────────────────────────────

    public static function url(): string {
        return defined( 'LOC_ODOO_URL' )
            ? rtrim( LOC_ODOO_URL, '/' )
            : rtrim( (string) get_option( 'loc_odoo_url', '' ), '/' );
    }

    public static function db(): string {
        return defined( 'LOC_ODOO_DB' )
            ? LOC_ODOO_DB
            : (string) get_option( 'loc_odoo_db', '' );
    }

    public static function user(): string {
        return defined( 'LOC_ODOO_USER' )
            ? LOC_ODOO_USER
            : (string) get_option( 'loc_odoo_user', '' );
    }

    public static function password(): string {
        return defined( 'LOC_ODOO_PASSWORD' )
            ? LOC_ODOO_PASSWORD
            : (string) get_option( 'loc_odoo_password', '' );
    }

    // ── Authentication ───────────────────────────────────────────────────────

    /**
     * Authenticate and cache the Odoo uid for this request.
     *
     * @return int|false Odoo user id or false on failure.
     */
    public static function authenticate(): int|false {
        if ( self::$uid !== null ) {
            return self::$uid;
        }

        $result = self::json_rpc(
            self::url() . '/web/dataset/call_kw',
            'common',
            'authenticate',
            [ self::db(), self::user(), self::password(), [] ]
        );

        if ( is_int( $result ) && $result > 0 ) {
            self::$uid = $result;
            return self::$uid;
        }

        self::log( 'auth', 0, 0, 'error', 'Authentication failed: ' . wp_json_encode( $result ) );
        return false;
    }

    // ── Public CRUD helpers ──────────────────────────────────────────────────

    /**
     * Search records and return their IDs.
     *
     * @param string $model   e.g. 'product.template'
     * @param array  $domain  Odoo domain filter
     * @param array  $kwargs  Extra keyword args (limit, offset, order…)
     * @return int[]|false
     */
    public static function search( string $model, array $domain = [], array $kwargs = [] ): array|false {
        return self::execute( $model, 'search', [ $domain ], $kwargs );
    }

    /**
     * Search and read records in one call.
     *
     * @param string   $model
     * @param array    $domain
     * @param string[] $fields
     * @param array    $kwargs
     * @return array[]|false
     */
    public static function search_read( string $model, array $domain = [], array $fields = [], array $kwargs = [] ): array|false {
        $kwargs['fields'] = $fields;
        return self::execute( $model, 'search_read', [ $domain ], $kwargs );
    }

    /**
     * Read specific record IDs.
     *
     * @param string   $model
     * @param int[]    $ids
     * @param string[] $fields
     * @return array[]|false
     */
    public static function read( string $model, array $ids, array $fields = [] ): array|false {
        return self::execute( $model, 'read', [ $ids, $fields ] );
    }

    /**
     * Create a record and return the new id.
     *
     * @param string $model
     * @param array  $vals
     * @return int|false
     */
    public static function create( string $model, array $vals ): int|false {
        return self::execute( $model, 'create', [ $vals ] );
    }

    /**
     * Write (update) records.
     *
     * @param string $model
     * @param int[]  $ids
     * @param array  $vals
     * @return bool|false
     */
    public static function write( string $model, array $ids, array $vals ): bool|false {
        return self::execute( $model, 'write', [ $ids, $vals ] );
    }

    /**
     * Call an arbitrary method (e.g. action_confirm, action_invoice_sent…).
     *
     * @param string $model
     * @param string $method
     * @param array  $args
     * @param array  $kwargs
     * @return mixed
     */
    public static function call( string $model, string $method, array $args = [], array $kwargs = [] ): mixed {
        return self::execute( $model, $method, $args, $kwargs );
    }

    // ── Low-level execute ────────────────────────────────────────────────────

    /**
     * Call models/execute_kw on the Odoo object endpoint.
     *
     * @return mixed|false
     */
    public static function execute( string $model, string $method, array $args = [], array $kwargs = [] ): mixed {
        $uid = self::authenticate();
        if ( $uid === false ) {
            return false;
        }

        return self::json_rpc(
            self::url() . '/web/dataset/call_kw',
            'object',
            'execute_kw',
            [ self::db(), $uid, self::password(), $model, $method, $args, $kwargs ]
        );
    }

    // ── HTTP transport ───────────────────────────────────────────────────────

    /**
     * Send a JSON-RPC 2.0 request using wp_remote_post.
     *
     * @param string $url
     * @param string $service  'common' | 'object'
     * @param string $method
     * @param array  $params
     * @return mixed|false
     */
    private static function json_rpc( string $url, string $service, string $method, array $params ): mixed {
        $payload = wp_json_encode( [
            'jsonrpc' => '2.0',
            'method'  => 'call',
            'params'  => [
                'service' => $service,
                'method'  => $method,
                'args'    => $params,
            ],
        ] );

        $response = wp_remote_post( $url, [
            'headers'     => [ 'Content-Type' => 'application/json' ],
            'body'        => $payload,
            'timeout'     => 30,
            'data_format' => 'body',
        ] );

        if ( is_wp_error( $response ) ) {
            self::log( 'http', 0, 0, 'error', $response->get_error_message() );
            return false;
        }

        $body   = wp_remote_retrieve_body( $response );
        $parsed = json_decode( $body, true );

        if ( isset( $parsed['error'] ) ) {
            $msg = $parsed['error']['data']['message'] ?? wp_json_encode( $parsed['error'] );
            self::log( 'rpc', 0, 0, 'error', $msg );
            return false;
        }

        return $parsed['result'] ?? false;
    }

    // ── Internal log helper ──────────────────────────────────────────────────

    public static function log( string $type, int $obj_id, int $odoo_id, string $status, string $msg = '' ): void {
        global $wpdb;
        $wpdb->insert(
            $wpdb->prefix . 'loc_sync_log',
            [
                'sync_type' => $type,
                'object_id' => $obj_id,
                'odoo_id'   => $odoo_id,
                'status'    => $status,
                'message'   => mb_substr( $msg, 0, 2000 ),
            ],
            [ '%s', '%d', '%d', '%s', '%s' ]
        );
    }
}
