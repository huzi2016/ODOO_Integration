<?php
/**
 * LOC_API — Odoo HTTP API client.
 *
 * Default: **JSON-2** (`POST /json/2/<model>/<method>`, `Authorization: bearer <api-key>`),
 * required for typical Odoo 19+ setups where `/web/dataset/call_kw` rejects external POST (405).
 *
 * Optional: **JSON-RPC** (`POST /jsonrpc`, `common` + `object.execute_kw`) for self-hosted
 * Odoo 14–18. Choose in settings or set `LOC_ODOO_API_MODE` to `jsonrpc` in wp-config.php.
 *
 * Credentials: `LOC_ODOO_URL`, `LOC_ODOO_DB`, `LOC_ODOO_PASSWORD` (API key for JSON-2).
 * JSON-RPC mode also needs `LOC_ODOO_USER` + password/API key.
 *
 * @package LIMO_Odoo_Connector
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class LOC_API {

    /** Cached uid from JSON-2 context_get or JSON-RPC authenticate. */
    private static ?int $uid = null;

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

    /** API key (JSON-2) or password (JSON-RPC). */
    public static function password(): string {
        return defined( 'LOC_ODOO_PASSWORD' )
            ? LOC_ODOO_PASSWORD
            : (string) get_option( 'loc_odoo_password', '' );
    }

    /**
     * `json2` (default) or `jsonrpc`.
     */
    public static function api_mode(): string {
        if ( defined( 'LOC_ODOO_API_MODE' ) && in_array( LOC_ODOO_API_MODE, [ 'json2', 'jsonrpc' ], true ) ) {
            return LOC_ODOO_API_MODE;
        }
        $m = (string) get_option( 'loc_odoo_api_mode', 'json2' );
        return in_array( $m, [ 'json2', 'jsonrpc' ], true ) ? $m : 'json2';
    }

    /**
     * Verify credentials; cache uid when possible.
     *
     * @return int|false Odoo user id or false on failure.
     */
    public static function authenticate(): int|false {
        if ( self::$uid !== null ) {
            return self::$uid;
        }

        if ( self::api_mode() === 'jsonrpc' ) {
            $result = self::legacy_jsonrpc(
                self::url() . '/jsonrpc',
                'common',
                'authenticate',
                [ self::db(), self::user(), self::password(), [] ]
            );
            if ( is_int( $result ) && $result > 0 ) {
                self::$uid = $result;
                return self::$uid;
            }
            self::log( 'auth', 0, 0, 'error', 'JSON-RPC authenticate failed: ' . wp_json_encode( $result ) );
            return false;
        }

        $ctx = self::json2_request( 'res.users', 'context_get', [] );
        if ( is_array( $ctx ) && ! empty( $ctx['uid'] ) ) {
            self::$uid = (int) $ctx['uid'];
            return self::$uid;
        }

        self::log( 'auth', 0, 0, 'error', 'JSON-2 context_get failed (check API key & database name).' );
        return false;
    }

    public static function search( string $model, array $domain = [], array $kwargs = [] ): array|false {
        if ( self::api_mode() === 'jsonrpc' ) {
            $r = self::legacy_execute( $model, 'search', [ $domain ], $kwargs );
            return is_array( $r ) ? $r : false;
        }
        $body = array_merge( [ 'domain' => $domain ], $kwargs );
        $r    = self::json2_request( $model, 'search', $body );
        return is_array( $r ) ? $r : false;
    }

    public static function search_read( string $model, array $domain = [], array $fields = [], array $kwargs = [] ): array|false {
        $kwargs['fields'] = $fields;
        if ( self::api_mode() === 'jsonrpc' ) {
            $r = self::legacy_execute( $model, 'search_read', [ $domain ], $kwargs );
            return self::normalize_search_read_rows( $r );
        }
        $kwargs['domain'] = $domain;
        $r                = self::json2_request( $model, 'search_read', $kwargs );
        return self::normalize_search_read_rows( $r );
    }

    public static function read( string $model, array $ids, array $fields = [] ): array|false {
        if ( self::api_mode() === 'jsonrpc' ) {
            $r = self::legacy_execute( $model, 'read', [ $ids, $fields ], [] );
            return is_array( $r ) ? $r : false;
        }
        $r = self::json2_request( $model, 'read', [ 'ids' => $ids, 'fields' => $fields ] );
        return is_array( $r ) ? $r : false;
    }

    public static function create( string $model, array $vals ): int|false {
        if ( self::api_mode() === 'jsonrpc' ) {
            $r = self::legacy_execute( $model, 'create', [ $vals ], [] );
            return self::normalize_create_result( $r );
        }
        $r = self::json2_request( $model, 'create', [ 'vals_list' => [ $vals ] ] );
        return self::normalize_create_result( $r );
    }

    public static function write( string $model, array $ids, array $vals ): bool {
        if ( self::api_mode() === 'jsonrpc' ) {
            $r = self::legacy_execute( $model, 'write', [ $ids, $vals ], [] );
            return $r === true;
        }
        $r = self::json2_request( $model, 'write', [ 'ids' => $ids, 'vals' => $vals ] );
        return $r === true;
    }

    public static function call( string $model, string $method, array $args = [], array $kwargs = [] ): mixed {
        if ( self::api_mode() === 'jsonrpc' ) {
            return self::legacy_execute( $model, $method, $args, $kwargs );
        }
        $body = $kwargs;
        if ( $args !== [] && isset( $args[0] ) && is_array( $args[0] ) ) {
            $body['ids'] = $args[0];
        }
        return self::json2_request( $model, $method, $body );
    }

    /**
     * Sum qty_available on product.product variants per product.template id.
     *
     * Odoo 17+ / 19+ often reject qty_available on product.template (HTTP 500 / invalid field).
     * Stock lives on variants; multi-variant templates get summed qty.
     *
     * @param int[] $template_ids product.template database ids.
     * @return array<int,float> template_id => total qty_available
     */
    public static function sum_qty_available_by_template_ids( array $template_ids ): array {
        $ids = [];
        foreach ( $template_ids as $id ) {
            $i = (int) $id;
            if ( $i > 0 ) {
                $ids[] = $i;
            }
        }
        $ids = array_values( array_unique( $ids, SORT_NUMERIC ) );
        if ( $ids === [] ) {
            return [];
        }

        /** @var array<int,float> $sums */
        $sums = array_fill_keys( $ids, 0.0 );

        $chunk = (int) apply_filters( 'loc_odoo_variant_stock_chunk', 80 );
        if ( $chunk < 1 ) {
            $chunk = 80;
        }

        for ( $i = 0, $n = count( $ids ); $i < $n; $i += $chunk ) {
            $slice = array_slice( $ids, $i, $chunk );
            $variants = self::search_read(
                'product.product',
                [ [ 'product_tmpl_id', 'in', $slice ] ],
                [ 'product_tmpl_id', 'qty_available' ],
                [ 'limit' => 10000 ]
            );
            if ( ! is_array( $variants ) ) {
                continue;
            }
            foreach ( $variants as $v ) {
                if ( ! is_array( $v ) ) {
                    continue;
                }
                $tid = self::many2one_to_int( $v['product_tmpl_id'] ?? null );
                if ( $tid > 0 && array_key_exists( $tid, $sums ) ) {
                    $sums[ $tid ] += (float) ( $v['qty_available'] ?? 0 );
                }
            }
        }

        return $sums;
    }

    /**
     * @param mixed $val Many2one value: id or [ id, display_name ].
     */
    private static function many2one_to_int( mixed $val ): int {
        if ( is_int( $val ) || is_float( $val ) ) {
            return (int) $val;
        }
        if ( is_array( $val ) && array_key_exists( 0, $val ) && is_numeric( $val[0] ) ) {
            return (int) $val[0];
        }
        return 0;
    }

    // ── JSON-2 transport (Odoo 19+) ──────────────────────────────────────────

    private static function json2_request( string $model, string $method, array $body ): mixed {
        $key = self::password();
        if ( $key === '' ) {
            self::log( 'json2', 0, 0, 'error', 'Missing API key (password field).' );
            return false;
        }

        $base = self::url();
        $url  = $base . '/json/2/' . rawurlencode( $model ) . '/' . rawurlencode( $method );

        $headers = [
            'Content-Type'  => 'application/json; charset=utf-8',
            'Authorization' => 'bearer ' . $key,
        ];
        $db = self::db();
        if ( $db !== '' ) {
            $headers['X-Odoo-Database'] = $db;
        }
        $ua_ver = defined( 'LOC_VERSION' ) ? LOC_VERSION : '1.0.0';
        $headers['User-Agent'] = 'LIMO-Odoo-Connector/' . $ua_ver . '; ' . home_url( '/' );

        $response = wp_remote_post(
            $url,
            [
                'headers'     => $headers,
                'body'        => wp_json_encode( $body ),
                'timeout'     => 60,
                'data_format' => 'body',
            ]
        );

        if ( is_wp_error( $response ) ) {
            self::log( 'http', 0, 0, 'error', $response->get_error_message() );
            return false;
        }

        $code = wp_remote_retrieve_response_code( $response );
        $raw  = wp_remote_retrieve_body( $response );
        $data = json_decode( $raw, true );

        if ( $code < 200 || $code >= 300 ) {
            $msg = is_array( $data ) && isset( $data['message'] )
                ? (string) $data['message']
                : $raw;
            self::log( 'json2', 0, 0, 'error', 'HTTP ' . $code . ': ' . self::truncate( $msg, 500 ) );
            return false;
        }

        return $data;
    }

    // ── Legacy JSON-RPC (/jsonrpc) ───────────────────────────────────────────

    private static function legacy_execute( string $model, string $method, array $args = [], array $kwargs = [] ): mixed {
        $uid = self::authenticate();
        if ( $uid === false ) {
            return false;
        }
        return self::legacy_jsonrpc(
            self::url() . '/jsonrpc',
            'object',
            'execute_kw',
            [ self::db(), $uid, self::password(), $model, $method, $args, $kwargs ]
        );
    }

    private static function legacy_jsonrpc( string $url, string $service, string $method, array $params ): mixed {
        $payload = wp_json_encode( [
            'jsonrpc' => '2.0',
            'method'  => 'call',
            'params'  => [
                'service' => $service,
                'method'  => $method,
                'args'    => $params,
            ],
            'id'      => 1,
        ] );

        $ua_ver = defined( 'LOC_VERSION' ) ? LOC_VERSION : '1.0.0';
        $response = wp_remote_post(
            $url,
            [
                'headers'     => [
                    'Content-Type' => 'application/json',
                    'User-Agent'   => 'LIMO-Odoo-Connector/' . $ua_ver . '; ' . home_url( '/' ),
                ],
                'body'        => $payload,
                'timeout'     => 60,
                'data_format' => 'body',
            ]
        );

        if ( is_wp_error( $response ) ) {
            self::log( 'http', 0, 0, 'error', $response->get_error_message() );
            return false;
        }

        $parsed = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( isset( $parsed['error'] ) ) {
            $msg = $parsed['error']['data']['message'] ?? wp_json_encode( $parsed['error'] );
            self::log( 'rpc', 0, 0, 'error', self::truncate( (string) $msg, 500 ) );
            return false;
        }

        return $parsed['result'] ?? false;
    }

    /**
     * Odoo normally returns a list of dicts. JSON-2 may rarely return one dict; JSON must not be iterated as key/value rows.
     *
     * @param mixed $data Decoded JSON body.
     * @return array<int,array<string,mixed>>|false
     */
    private static function normalize_search_read_rows( mixed $data ): array|false {
        if ( $data === false || $data === null ) {
            return false;
        }
        if ( ! is_array( $data ) ) {
            return false;
        }
        if ( $data === [] ) {
            return [];
        }
        $keys   = array_keys( $data );
        $is_seq = $keys === range( 0, count( $data ) - 1 );
        if ( $is_seq && isset( $data[0] ) && is_array( $data[0] ) && array_key_exists( 'id', $data[0] ) ) {
            return $data;
        }
        if ( ! $is_seq && isset( $data['id'] ) && is_scalar( $data['id'] ) ) {
            return [ $data ];
        }
        return false;
    }

    private static function normalize_create_result( mixed $r ): int|false {
        if ( is_int( $r ) && $r > 0 ) {
            return $r;
        }
        if ( is_array( $r ) && isset( $r[0] ) && is_int( $r[0] ) ) {
            return $r[0];
        }
        return false;
    }

    public static function log( string $type, int $obj_id, int $odoo_id, string $status, string $msg = '' ): void {
        global $wpdb;
        $wpdb->insert(
            $wpdb->prefix . 'loc_sync_log',
            [
                'sync_type' => $type,
                'object_id' => $obj_id,
                'odoo_id'   => $odoo_id,
                'status'    => $status,
                'message'   => self::truncate( $msg, 2000 ),
            ],
            [ '%s', '%d', '%d', '%s', '%s' ]
        );
    }

    private static function truncate( string $str, int $len ): string {
        if ( function_exists( 'mb_substr' ) ) {
            return mb_substr( $str, 0, $len );
        }
        return substr( $str, 0, $len );
    }
}
