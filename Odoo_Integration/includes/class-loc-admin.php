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
        add_submenu_page(
            'woocommerce',
            'Odoo Connector',
            'Odoo Connector',
            'manage_options',
            'loc-odoo-connector',
            [ __CLASS__, 'render_page' ]
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
            'ajax_url'   => admin_url( 'admin-ajax.php' ),
            'nonce'      => wp_create_nonce( 'loc_admin_nonce' ),
            'webhook_url' => rest_url( 'loc/v1/odoo-callback' ),
            'inv_url'    => rest_url( 'loc/v1/inventory-update' ),
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

        <?php settings_errors( 'loc_settings_group' ); ?>

        <nav class="nav-tab-wrapper">
            <a href="#tab-settings" class="nav-tab nav-tab-active" id="loc-tab-settings">⚙️ 连接设置</a>
            <a href="#tab-sync"     class="nav-tab"                id="loc-tab-sync">🔄 同步控制</a>
            <a href="#tab-log"      class="nav-tab"                id="loc-tab-log">📋 同步日志</a>
            <a href="#tab-guide"    class="nav-tab"                id="loc-tab-guide">📖 部署指南</a>
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
                    <p class="description">不含末尾斜线</p>
                </td>
            </tr>
            <tr>
                <th>数据库名</th>
                <td>
                    <input class="regular-text" type="text" name="loc_odoo_db"
                           value="<?php echo esc_attr( get_option( 'loc_odoo_db' ) ); ?>"
                           placeholder="mycompany" />
                </td>
            </tr>
            <tr>
                <th>登录用户（邮箱）</th>
                <td>
                    <input class="regular-text" type="email" name="loc_odoo_user"
                           value="<?php echo esc_attr( get_option( 'loc_odoo_user' ) ); ?>" />
                </td>
            </tr>
            <tr>
                <th>密码 / API Key</th>
                <td>
                    <input class="regular-text" type="password" name="loc_odoo_password"
                           value="<?php echo esc_attr( get_option( 'loc_odoo_password' ) ); ?>" />
                    <p class="description">建议使用 Odoo 后台生成的 API Key（Settings → Technical → API Keys）</p>
                </td>
            </tr>
            <tr>
                <th>Webhook 密钥</th>
                <td>
                    <input class="regular-text" type="text" name="loc_webhook_secret"
                           value="<?php echo esc_attr( get_option( 'loc_webhook_secret' ) ); ?>" />
                    <p class="description">Odoo 回调请求头 <code>X-Odoo-Secret</code> 需填写此值</p>
                </td>
            </tr>
        </table>
        <?php submit_button( '保存设置' ); ?>
        </form>

        <hr>
        <button id="loc-test-btn" class="button button-secondary">🧪 测试 Odoo 连接</button>
        <span id="loc-test-result" style="margin-left:12px;font-weight:600;"></span>
        </div>

        <!-- ══ Tab: Sync Controls ══════════════════════════════════════════ -->
        <div id="tab-sync" class="loc-tab-content" style="display:none">
        <h2>手动触发同步</h2>
        <table class="wp-list-table widefat fixed striped" style="max-width:680px">
            <thead><tr><th>同步任务</th><th>说明</th><th>操作</th></tr></thead>
            <tbody>
                <tr>
                    <td><strong>商品拉取</strong></td>
                    <td>从 Odoo 拉取商品信息 + 价格 → WooCommerce</td>
                    <td><button class="button loc-sync-btn" data-action="loc_sync_products">立即同步</button></td>
                </tr>
                <tr>
                    <td><strong>库存同步</strong></td>
                    <td>从 Odoo 拉取实时库存 → WooCommerce</td>
                    <td><button class="button loc-sync-btn" data-action="loc_sync_inventory">立即同步</button></td>
                </tr>
            </tbody>
        </table>
        <p id="loc-sync-result" style="font-weight:600;color:#2271b1;margin-top:12px;"></p>

        <h2 style="margin-top:32px;">Webhook 端点（配置到 Odoo）</h2>
        <table class="form-table" style="max-width:680px">
            <tr><th>发货完成回调</th><td><code id="loc-webhook-url"></code></td></tr>
            <tr><th>库存变更推送</th><td><code id="loc-inv-url"></code></td></tr>
        </table>
        </div>

        <!-- ══ Tab: Log ════════════════════════════════════════════════════ -->
        <div id="tab-log" class="loc-tab-content" style="display:none">
        <h2>最近 100 条同步日志</h2>
        <button id="loc-load-log" class="button">加载日志</button>
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
        <h2>部署步骤</h2>

        <h3>① 安装本插件</h3>
        <p>将 <code>limo-odoo-connector/</code> 文件夹上传到 <code>wp-content/plugins/</code>，后台启用。</p>

        <h3>② 配置连接信息</h3>
        <p>在「连接设置」页填写 Odoo URL、数据库名、登录账号和 API Key（推荐）。<br>
        也可在 <code>wp-config.php</code> 中直接定义常量：</p>
        <pre style="background:#f0f0f0;padding:12px;border-radius:6px;">define( 'LOC_ODOO_URL',      'https://mycompany.odoo.com' );
define( 'LOC_ODOO_DB',       'mycompany' );
define( 'LOC_ODOO_USER',     'admin@mycompany.com' );
define( 'LOC_ODOO_PASSWORD', 'your_api_key' );</pre>

        <h3>③ 配置 Odoo Webhook（发货回调）</h3>
        <p>在 Odoo 后台：<strong>设置 → 技术 → 自动化动作</strong>，新建一条规则：</p>
        <ul>
            <li>模型：<code>stock.picking</code></li>
            <li>触发：记录被修改，字段 <code>state</code> 变为 <code>done</code></li>
            <li>动作类型：执行代码</li>
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

        <h3>④ 配置 Odoo 库存实时推送（可选）</h3>
        <p>同上，模型改为 <code>stock.quant</code>，触发字段 <code>quantity</code>，推送到：</p>
        <pre style="background:#f0f0f0;padding:12px;border-radius:6px;">POST https://your-wp-site.com/wp-json/loc/v1/inventory-update
Body: {"odoo_product_id": 42, "qty_available": 15.0}</pre>

        <h3>⑤ 数据流全览</h3>
        <table class="wp-list-table widefat striped">
            <thead><tr><th>事件</th><th>方向</th><th>说明</th></tr></thead>
            <tbody>
                <tr><td>用户注册 / 更新地址</td><td>WP → Odoo</td><td>同步为 res.partner，带会员编号</td></tr>
                <tr><td>商品保存</td><td>WP → Odoo</td><td>推送名称、价格、SKU</td></tr>
                <tr><td>定时拉取（每小时）</td><td>Odoo → WP</td><td>同步商品信息、价格</td></tr>
                <tr><td>定时拉取（每 15 分钟）</td><td>Odoo → WP</td><td>同步库存数量</td></tr>
                <tr><td>客户下单（processing）</td><td>WP → Odoo</td><td>创建 sale.order 并确认</td></tr>
                <tr><td>订单完成（completed）</td><td>WP → Odoo</td><td>生成账单并过账，触发出库</td></tr>
                <tr><td>Odoo 发货完成</td><td>Odoo → WP</td><td>回写运单号，WC 订单标为完成</td></tr>
                <tr><td>订单取消 / 退款</td><td>WP → Odoo</td><td>取消 sale.order / 冲销账单</td></tr>
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

        // Reset cached uid
        $ref = new ReflectionProperty( 'LOC_API', 'uid' );
        $ref->setAccessible( true );
        $ref->setValue( null, null );

        $uid = LOC_API::authenticate();
        if ( $uid ) {
            wp_send_json_success( "✅ 连接成功！Odoo 用户 ID：{$uid}" );
        } else {
            wp_send_json_error( '❌ 连接失败，请检查 URL / 数据库 / 用户名 / 密码。' );
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
        echo '<thead><tr><th>ID</th><th>类型</th><th>WC ID</th><th>Odoo ID</th><th>状态</th><th>信息</th><th>时间</th></tr></thead><tbody>';
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
