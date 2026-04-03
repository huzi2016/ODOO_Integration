<?php
/**
 * LOC_Admin — Settings page, connection test, sync dashboard.
 *
 * @package LIMO_Odoo_Connector
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class LOC_Admin {

    public static function init(): void {
        add_action( 'admin_menu',            [ __CLASS__, 'add_menu' ] );
        add_action( 'admin_init',            [ __CLASS__, 'register_settings' ] );
        add_action( 'admin_enqueue_scripts', [ __CLASS__, 'enqueue' ] );
        add_action( 'wp_ajax_loc_test_connection', [ __CLASS__, 'ajax_test_connection' ] );
        add_action( 'wp_ajax_loc_view_log',        [ __CLASS__, 'ajax_view_log' ] );
    }

    public static function add_menu(): void {
        add_menu_page(
            'Odoo Connector',
            'Odoo Connector',
            'manage_options',
            'loc-odoo-connector',
            [ __CLASS__, 'render_page' ],
            'dashicons-networking'
        );
    }

    public static function register_settings(): void {
        $fields = [
            'loc_odoo_url',
            'loc_odoo_db',
            'loc_odoo_user',
            'loc_odoo_password',
            'loc_webhook_secret',
        ];
        foreach ( $fields as $f ) {
            register_setting( 'loc_settings_group', $f, [ 'sanitize_callback' => 'sanitize_text_field' ] );
        }
        register_setting(
            'loc_settings_group',
            'loc_odoo_api_mode',
            [
                'type'              => 'string',
                'sanitize_callback' => static function ( $v ) {
                    return in_array( $v, [ 'json2', 'jsonrpc' ], true ) ? $v : 'json2';
                },
                'default'           => 'json2',
            ]
        );
    }

    public static function enqueue( string $hook ): void {
        if ( strpos( $hook, 'loc-odoo-connector' ) === false ) {
            return;
        }
        wp_enqueue_script(
            'loc-admin-js',
            LOC_PLUGIN_URL . 'assets/js/loc-admin.js',
            [ 'jquery' ],
            LOC_VERSION,
            true
        );
        wp_localize_script( 'loc-admin-js', 'locAdmin', [
            'ajax_url'      => admin_url( 'admin-ajax.php' ),
            'nonce'         => wp_create_nonce( 'loc_admin_nonce' ),
            'webhook_url'   => rest_url( 'loc/v1/odoo-callback' ),
            'inv_url'       => rest_url( 'loc/v1/inventory-update' ),
            'product_url'   => rest_url( 'loc/v1/product-sync' ),
        ] );
    }

    // ════════════════════════════════════════════════════════════════════════
    // Settings page HTML
    // ════════════════════════════════════════════════════════════════════════

    public static function render_page(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }
        ?>
        <div class="wrap">
        <h1>🔗 Odoo Connector — LIMO Membership</h1>

        <?php if ( ! LOC_API::writes_allowed() ) : ?>
        <div class="notice notice-warning">
            <p><strong>Odoo writes disabled (default):</strong> WordPress does not run <code>create</code>, <code>write</code>, or workflow <code>call</code> on Odoo. Product pull uses only <code>search</code>/<code>read</code> — it never updates Odoo Internal Reference. To push orders/customers, set <code>define( 'LOC_ODOO_WRITES_ALLOWED', true );</code> in <code>wp-config.php</code>. Product master data (<code>product.template</code> / <code>product.product</code>) stays blocked unless you add <code>add_filter( 'loc_odoo_allow_product_catalog_write', '__return_true' );</code>.</p>
        </div>
        <?php endif; ?>

        <?php settings_errors( 'loc_settings_group' ); ?>

        <nav class="nav-tab-wrapper">
            <a href="#tab-settings" class="nav-tab nav-tab-active" id="loc-tab-settings">⚙️ odoo connection</a>
            <a href="#tab-sync"     class="nav-tab"                id="loc-tab-sync">🔄 sync control</a>
            <a href="#tab-log"      class="nav-tab"                id="loc-tab-log">📋 sync log</a>
            <a href="#tab-guide"    class="nav-tab"                id="loc-tab-guide">📖 deployment guide</a>
        </nav>

        <!-- ══ Tab: Settings ═══════════════════════════════════════════════ -->
        <div id="tab-settings" class="loc-tab-content" style="display:block">
        <form method="post" action="options.php" style="max-width:680px">
        <?php settings_fields( 'loc_settings_group' ); ?>
        <table class="form-table">
            <tr>
                <th>Odoo URL</th>
                <td>
                    <input class="regular-text" type="url" name="loc_odoo_url"
                           value="<?php echo esc_attr( get_option( 'loc_odoo_url' ) ); ?>"
                           placeholder="https://mycompany.odoo.com" />
                    <p class="description">without trailing slash</p>
                </td>
            </tr>
            <tr>
                <th>Database name</th>
                <td>
                    <input class="regular-text" type="text" name="loc_odoo_db"
                           value="<?php echo esc_attr( get_option( 'loc_odoo_db' ) ); ?>"
                           placeholder="mycompany" />
                    <p class="description">Required for multi-database setup; leave empty for single database (determined by Host). JSON-2 is recommended to always fill in.</p>
                </td>
            </tr>
            <tr>
                <th>API mode</th>
                <td>
                    <?php $api_mode = get_option( 'loc_odoo_api_mode', 'json2' ); ?>
                    <label><input type="radio" name="loc_odoo_api_mode" value="json2" <?php checked( $api_mode, 'json2' ); ?> />
                        <strong>JSON-2</strong> (recommended, Odoo 19+: <code>POST /json/2/…</code> + Bearer API key)</label><br>
                    <label><input type="radio" name="loc_odoo_api_mode" value="jsonrpc" <?php checked( $api_mode, 'jsonrpc' ); ?> />
                        <strong>JSON-RPC</strong> (self-hosted Odoo 14–18: <code>POST /jsonrpc</code>, login + password or API key)</label>
                    <p class="description">If you see <code>405 Method Not Allowed</code>, use JSON-2 and paste an API key below.</p>
                </td>
            </tr>
            <tr>
                <th>Login user (email)</th>
                <td>
                    <input class="regular-text" type="email" name="loc_odoo_user"
                           value="<?php echo esc_attr( get_option( 'loc_odoo_user' ) ); ?>" />
                </td>
            </tr>
            <tr>
                <th>Password / API Key</th>
                <td>
                    <input class="regular-text" type="password" name="loc_odoo_password"
                           value="<?php echo esc_attr( get_option( 'loc_odoo_password' ) ); ?>" />
                    <p class="description">In <strong>JSON-2 mode</strong>, use an <strong>API key</strong> here (not your login password): user menu → <strong>Preferences</strong> / <strong>My Profile</strong> → <strong>Account Security</strong> → New API Key. In <strong>JSON-RPC mode</strong>, you may use the account password or an API key.</p>
                </td>
            </tr>
            <tr>
                <th>Webhook secret</th>
                <td>
                    <input class="regular-text" type="text" name="loc_webhook_secret"
                           value="<?php echo esc_attr( get_option( 'loc_webhook_secret' ) ); ?>" />
                    <p class="description">Odoo must send this value in the <code>X-Odoo-Secret</code> request header when calling your site.</p>
                </td>
            </tr>
        </table>
        <?php submit_button( 'Save settings' ); ?>
        </form>

        <hr>
        <button id="loc-test-btn" class="button button-secondary">🧪 Test Odoo connection</button>
        <span id="loc-test-result" style="margin-left:12px;font-weight:600;"></span>
        </div>

        <!-- ══ Tab: Sync Controls ══════════════════════════════════════════ -->
        <div id="tab-sync" class="loc-tab-content" style="display:none">
        <h2>Manually trigger sync</h2>
        <table class="wp-list-table widefat fixed striped" style="max-width:680px">
            <thead><tr><th>Sync task</th><th>Description</th><th>Action</th></tr></thead>
            <tbody>
                <tr>
                    <td><strong>Product pull</strong></td>
                    <td>Pull name, price, SKU, and per-variant stock sums from Odoo → WooCommerce. Afterward, runs a full inventory pass for all linked products (same as the row below).</td>
                    <td><button class="button loc-sync-btn" data-action="loc_sync_products">Sync now</button></td>
                </tr>
                <tr>
                    <td><strong>Inventory sync</strong></td>
                    <td>Updates stock only for every WC product that has <code>_loc_odoo_product_id</code>. Runs every 15 minutes via WP-Cron if the site receives traffic (or use a real cron hitting <code>wp-cron.php</code>).</td>
                    <td><button class="button loc-sync-btn" data-action="loc_sync_inventory">Sync now</button></td>
                </tr>
            </tbody>
        </table>
        <p id="loc-sync-result" style="font-weight:600;color:#2271b1;margin-top:12px;"></p>

        <h2 style="margin-top:32px;">Webhook endpoints (configure to Odoo)</h2>
        <table class="form-table" style="max-width:680px">
            <tr><th>Delivery completed callback</th><td><code id="loc-webhook-url"></code></td></tr>
            <tr><th>Inventory change push</th><td><code id="loc-inv-url"></code></td></tr>
            <tr><th>Product sync (event)</th><td><code id="loc-product-url"></code></td></tr>
        </table>
        </div>

        <!-- ══ Tab: Log ════════════════════════════════════════════════════ -->
        <div id="tab-log" class="loc-tab-content" style="display:none">
        <h2>Last 100 sync logs</h2>
        <button id="loc-load-log" class="button">Load logs</button>
        <div id="loc-log-container" style="margin-top:16px;"></div>
        </div>

        <!-- ══ Tab: Guide ══════════════════════════════════════════════════ -->
        <div id="tab-guide" class="loc-tab-content" style="display:none">
        <?php self::render_guide(); ?>
        </div>

        </div><!-- .wrap -->

        <style>
        .loc-tab-content { padding: 20px 0; }
        </style>
        <script>
        document.querySelectorAll('.nav-tab').forEach(function(tab){
            tab.addEventListener('click', function(e){
                e.preventDefault();
                document.querySelectorAll('.nav-tab').forEach(t => t.classList.remove('nav-tab-active'));
                document.querySelectorAll('.loc-tab-content').forEach(c => c.style.display = 'none');
                tab.classList.add('nav-tab-active');
                document.querySelector(tab.getAttribute('href')).style.display = 'block';
            });
        });
        </script>
        <?php
    }

    private static function render_guide(): void {
        ?>
        <div style="max-width:720px;line-height:1.8;">
        <h2>Deployment steps</h2>

        <h3>① Install this plugin</h3>
        <p>Upload the <code>limo-odoo-connector/</code> folder to <code>wp-content/plugins/</code>, and enable it in the backend.</p>

        <h3>② Configure connection information</h3>
        <p>In the WordPress admin sidebar, open <strong>Odoo Connector</strong> and fill in the Odoo URL, database name, login (for JSON-RPC), and API key.<br>
        You can also define constants in <code>wp-config.php</code>:</p>
        <pre style="background:#f0f0f0;padding:12px;border-radius:6px;">define( 'LOC_ODOO_URL',      'https://mycompany.odoo.com' );
define( 'LOC_ODOO_DB',       'mycompany' );
define( 'LOC_ODOO_USER',     'admin@mycompany.com' );
define( 'LOC_ODOO_PASSWORD', 'your_api_key' );</pre>

        <h3>Odoo API version</h3>
        <ul>
            <li><strong>Default JSON-2</strong> (<code>POST /json/2/&lt;model&gt;/&lt;method&gt;</code>, <code>Authorization: bearer &lt;API key&gt;</code>) matches Odoo 19’s external API and avoids <code>405 Method Not Allowed</code> on routes that only accept browser session RPC.</li>
            <li>Use <strong>JSON-RPC</strong> only for self-hosted Odoo 14–18 where <code>/jsonrpc</code> is available; it requires database name, login, and password or API key.</li>
            <li>On Odoo Online, API access may depend on your <a href="https://www.odoo.com/pricing-plan" target="_blank" rel="noopener noreferrer">pricing plan</a>.</li>
        </ul>

        <h3>Products: Odoo list vs this plugin</h3>
        <ul>
            <li><strong>Inventory → Products</strong> in Odoo lists <code>product.product</code> rows (variants). The counter (e.g. 268) is usually <strong>not</strong> the same as the number of <code>product.template</code> records. This plugin pulls <strong>templates</strong> (one WooCommerce product per template).</li>
            <li>Pull domain default: <code>active</code> and <code>sale_ok</code>. To match more or fewer templates, use the WordPress filter <code>loc_odoo_product_template_domain</code> in a small custom plugin or theme.</li>
            <li>If several Odoo <code>product.template</code> rows share the same Internal Reference (e.g. duplicate imports in Odoo), by default only <strong>one</strong> WooCommerce product is kept for that SKU — extra templates are <strong>skipped</strong> on pull (lowest template id first with <code>id asc</code>). This also applies when existing WC rows only have legacy SKUs like <code>AN5648-T5702</code> (no bare <code>AN5648</code>): the first template in the run reserves the canonical SKU so duplicates are not recreated. Skipped rows with obsolete <code>-T&lt;id&gt;</code> SKUs are moved to trash unless you disable <code>loc_odoo_trash_skipped_duplicate_wc_products</code>. To restore the old behaviour (one WC row per template with <code>-T&lt;id&gt;</code> suffixes), set <code>loc_odoo_skip_duplicate_internal_reference_templates</code> to <code>false</code>.</li>
            <li>Templates <strong>without</strong> an Internal Reference get WooCommerce SKU <code>ODOO-T&lt;template_id&gt;</code> so every product has a stable unique key (empty WC SKU caused thousands of duplicate imports before v1.1.7). The plugin strips trailing <code>-T&lt;id&gt;</code> chains from Internal Reference on pull so long snowball SKUs (e.g. <code>AN 5960-T930-T1197-…</code>) do not grow each sync. WordPress does not write product master data back to Odoo. Delete duplicate junk products in WooCommerce manually once, then re-sync.</li>
        </ul>

        <h3>③ Configure Odoo Webhook (delivery callback)</h3>
        <p>In Odoo backend: <strong>Settings → Technical → Automated actions</strong>, create a new rule:</p>
        <ul>
            <li>Model: <code>stock.picking</code></li>
            <li>Trigger: Record is modified, field <code>state</code> becomes <code>done</code></li>
            <li>Action type: Execute code</li>
        </ul>
        <pre style="background:#f0f0f0;padding:12px;border-radius:6px;">import requests, json
for pick in records:
    sale = pick.sale_id
    if not sale or not sale.client_order_ref.startswith('WC#'):
        continue
    wc_order_id = int(sale.client_order_ref.replace('WC#',''))
    tracking = pick.carrier_tracking_ref or ''
    carrier  = pick.carrier_id.name if pick.carrier_id else ''
    payload  = {'event':'delivery_done','wc_order_id':wc_order_id,'tracking':tracking,'carrier':carrier}
    requests.post(
        'https://your-wp-site.com/wp-json/loc/v1/odoo-callback',
        json=payload,
        headers={'X-Odoo-Secret': 'your_webhook_secret'},
        timeout=10
    )</pre>

        <h3>④ Configure Odoo inventory real-time push (optional)</h3>
        <p>Same as above, change the model to <code>stock.quant</code>, trigger field <code>quantity</code>, push to:</p>
        <pre style="background:#f0f0f0;padding:12px;border-radius:6px;">POST https://your-wp-site.com/wp-json/loc/v1/inventory-update
Body: {"odoo_product_id": 42, "qty_available": 15.0}</pre>

        <h3>⑤ Odoo product.template change → WordPress (event sync, optional)</h3>
        <p><strong>Settings → Technical → Automated actions</strong>: model <code>product.template</code>, trigger when the record is saved (or created and updated). Action: Execute code. Use the same <code>X-Odoo-Secret</code> as other webhooks (must match WordPress option <em>Webhook secret</em>).</p>
        <pre style="background:#f0f0f0;padding:12px;border-radius:6px;">import requests
WP = 'https://your-wp-site.com/wp-json/loc/v1/product-sync'
SECRET = 'your_webhook_secret'
for tmpl in records:
    requests.post(
        WP,
        json={'odoo_template_id': tmpl.id},
        headers={'X-Odoo-Secret': SECRET, 'Content-Type': 'application/json'},
        timeout=60,
    )</pre>
        <p>Multiple ids: <code>{"template_ids": [101, 102]}</code>. Response includes <code>saved</code>, <code>skipped</code>, <code>not_found</code>. Duplicate webhook calls for the same template id within 5 seconds are ignored (filter <code>loc_odoo_product_webhook_debounce_seconds</code>). A full-catalog pull via HTTP is off by default; enable with WordPress filter <code>loc_odoo_webhook_allow_full_product_sync</code> and body <code>{"full_sync": true}</code> only if you understand the load. After a successful event sync, WooCommerce stock for <strong>all</strong> linked products may refresh (same as manual inventory sync) unless you disable <code>loc_odoo_pull_inventory_after_product_webhook</code>.</p>

        <p><strong>Redundant rows appearing in Odoo (thousands of similar products):</strong> This WordPress plugin does <strong>not</strong> create or duplicate <code>product.template</code> in Odoo — it only <code>read</code>s. If new duplicate cards appear <em>in Odoo</em> after you edit a product, the cause is not the WooCommerce pull: check <strong>Settings → Technical → Automated actions</strong> and <strong>Server actions</strong> on <code>product.template</code> for Python that calls <code>create</code>, <code>copy</code>, or import. The webhook snippet above must only use <code>requests.post</code> to WordPress. Long snowballing Internal Reference strings (e.g. <code>AN5648-T613-T985-…</code>) are usually legacy data or past two-way sync; clean duplicates in Odoo (merge/archive) and keep a single canonical Internal Reference per item.</p>

        <h3>⑥ Data flow overview</h3>
        <p>Long corrupted Internal Reference values in Odoo (e.g. <code>AN5648-T425-T896-…</code>) are almost always <strong>historical</strong>: they were produced when an older integration wrote WooCommerce SKUs back into Odoo <code>default_code</code>. The current plugin does not do that; you must clean bad values in Odoo manually or by import. Scheduled product sync only <strong>reads</strong> from Odoo into WordPress.</p>
        <table class="wp-list-table widefat striped">
            <thead><tr><th>Event</th><th>Direction</th><th>Description</th></tr></thead>
            <tbody>
                <tr><td>User registration / update address</td><td>WP → Odoo</td><td>Sync to res.partner, with membership number</td></tr>
                <tr><td>Product save</td><td>—</td><td>No product push to Odoo (Odoo is source of truth for catalog)</td></tr>
                <tr><td>Scheduled pull (hourly)</td><td>Odoo → WP</td><td>Sync product info & price</td></tr>
                <tr><td>Odoo <code>product.template</code> saved (optional)</td><td>Odoo → WP</td><td>POST <code>/wp-json/loc/v1/product-sync</code> with webhook secret</td></tr>
                <tr><td>Scheduled pull (every 15 minutes)</td><td>Odoo → WP</td><td>Sync inventory quantity</td></tr>
                <tr><td>Customer checkout (processing)</td><td>WP → Odoo</td><td>Create sale.order and confirm</td></tr>
                <tr><td>Order completed</td><td>WP → Odoo</td><td>Create and post invoice, validate delivery</td></tr>
                <tr><td>Odoo delivery completed</td><td>Odoo → WP</td><td>Write back tracking number, mark WC order as completed</td></tr>
                <tr><td>Order canceled / refunded</td><td>WP → Odoo</td><td>Cancel sale.order / reverse invoice</td></tr>
            </tbody>
        </table>
        </div>
        <?php
    }

    // ════════════════════════════════════════════════════════════════════════
    // AJAX handlers
    // ════════════════════════════════════════════════════════════════════════

    public static function ajax_test_connection(): void {
        check_ajax_referer( 'loc_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Permission denied.' );
        }

        LOC_API::reset_auth();

        $uid = LOC_API::authenticate();
        if ( $uid ) {
            wp_send_json_success( "✅ Connection successful! Odoo user ID: {$uid}" );
        } else {
            wp_send_json_error( '❌ Connection failed, please check URL / database / username / password.' );
        }
    }

    public static function ajax_view_log(): void {
        check_ajax_referer( 'loc_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Permission denied.' );
        }

        global $wpdb;
        $rows = $wpdb->get_results(
            "SELECT * FROM {$wpdb->prefix}loc_sync_log ORDER BY id DESC LIMIT 100"
        );

        ob_start();
        echo '<table class="wp-list-table widefat striped" style="font-size:12px">';
        echo '<thead><tr><th>ID</th><th>Type</th><th>WC ID</th><th>Odoo ID</th><th>Status</th><th>Message</th><th>Time</th></tr></thead><tbody>';
        foreach ( $rows as $r ) {
            $color = $r->status === 'ok' ? '#0a5' : '#c00';
            echo "<tr><td>{$r->id}</td><td><code>{$r->sync_type}</code></td><td>{$r->object_id}</td><td>{$r->odoo_id}</td>";
            echo "<td style='color:{$color};font-weight:700'>{$r->status}</td><td>" . esc_html( $r->message ) . "</td><td>{$r->created_at}</td></tr>";
        }
        echo '</tbody></table>';
        $html = ob_get_clean();

        wp_send_json_success( $html );
    }
}
