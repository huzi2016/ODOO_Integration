/* LIMO Odoo Connector — Admin JS */
jQuery( function ( $ ) {

    // Fill webhook URLs
    if ( locAdmin.webhook_url ) {
        $( '#loc-webhook-url' ).text( locAdmin.webhook_url );
        $( '#loc-inv-url' ).text( locAdmin.inv_url );
    }
    if ( locAdmin.product_url ) {
        $( '#loc-product-url' ).text( locAdmin.product_url );
        $( '#loc-product-url2' ).text( locAdmin.product_url );

        // Generate Odoo Automated Action Python code with the live endpoint and secret placeholder.
        var code = [
            'import requests',
            '',
            'WP_URL = \'' + locAdmin.product_url + '\'',
            'WP_SECRET = \'' + ( locAdmin.webhook_secret || 'your_webhook_secret' ) + '\'',
            '',
            'for tmpl in records:',
            '    try:',
            '        r = requests.post(',
            '            WP_URL,',
            '            json={\'odoo_template_id\': tmpl.id},',
            '            headers={\'X-Odoo-Secret\': WP_SECRET, \'Content-Type\': \'application/json\'},',
            '            timeout=30,',
            '        )',
            '        # Optional: log result',
            '        # raise_if 400+ so Odoo marks the action as failed for review',
            '        if r.status_code >= 400:',
            '            raise UserError(\'WP sync failed: %s\' % r.text[:200])',
            '    except requests.exceptions.Timeout:',
            '        pass  # non-blocking: WP will catch up on its 15-min schedule',
        ].join('\n');

        $( '#loc-odoo-action-code' ).text( code );
    }

    // Copy Odoo action code to clipboard
    $( '#loc-copy-action-code' ).on( 'click', function () {
        var code = $( '#loc-odoo-action-code' ).text();
        if ( navigator.clipboard ) {
            navigator.clipboard.writeText( code ).then( function () {
                $( '#loc-copy-action-code' ).text( '✅ Copied!' );
                setTimeout( function () { $( '#loc-copy-action-code' ).text( '📋 Copy code' ); }, 2000 );
            } );
        }
    } );

    // Connection test
    $( '#loc-test-btn' ).on( 'click', function () {
        var $btn = $( this ).prop( 'disabled', true ).text( 'Testing…' );
        var $result = $( '#loc-test-result' );
        $.post( locAdmin.ajax_url, { action: 'loc_test_connection', nonce: locAdmin.nonce } )
            .done( function ( res ) {
                $result.css( 'color', res.success ? '#0a5' : '#c00' ).text( res.data );
            } )
            .always( function () { $btn.prop( 'disabled', false ).text( '🧪 Test Odoo connection' ); } );
    } );

    // Write test
    $( '#loc-write-test-btn' ).on( 'click', function () {
        var $btn    = $( this ).prop( 'disabled', true ).text( 'Testing write…' );
        var $result = $( '#loc-write-test-result' );
        $.post( locAdmin.ajax_url, { action: 'loc_test_write', nonce: locAdmin.nonce } )
            .done( function ( res ) {
                $result.css( 'color', res.success ? '#0a5' : '#c00' ).text( res.data );
            } )
            .always( function () { $btn.prop( 'disabled', false ).text( '✍️ Test Odoo write (safe — creates & immediately deletes a test note)' ); } );
    } );

    // Manual sync buttons
    $( '.loc-sync-btn' ).on( 'click', function () {
        var $btn    = $( this ).prop( 'disabled', true ).text( 'Syncing…' );
        var action  = $btn.data( 'action' );
        var $result = $( '#loc-sync-result' );
        $.post( locAdmin.ajax_url, { action: action, nonce: locAdmin.nonce } )
            .done( function ( res ) {
                $result.text( res.success ? '✅ ' + res.data : '❌ ' + res.data );
            } )
            .always( function () { $btn.prop( 'disabled', false ).text( 'Sync now' ); } );
    } );

    // Manual order push
    $( '#loc-push-order-btn' ).on( 'click', function () {
        var orderId = $( '#loc-order-id-input' ).val();
        if ( ! orderId ) { alert( 'Please enter a WC Order ID.' ); return; }
        var $btn    = $( this ).prop( 'disabled', true ).text( 'Pushing…' );
        var $result = $( '#loc-push-order-result' );
        $result.css( 'color', '#888' ).text( 'Sending to Odoo…' );
        $.post( locAdmin.ajax_url, { action: 'loc_push_order', nonce: locAdmin.nonce, order_id: orderId } )
            .done( function ( res ) {
                if ( res.success && res.data && res.data.odoo_sale_id > 0 ) {
                    $result.css( 'color', '#0a5' ).text( '✅ Odoo sale.order created: SO#' + res.data.odoo_sale_id + ' — check the WC order notes for details.' );
                } else if ( res.success && res.data && res.data.already_exists ) {
                    $result.css( 'color', '#888' ).text( 'ℹ️ Already synced: SO#' + res.data.odoo_sale_id + ' — order was previously created in Odoo.' );
                } else {
                    $result.css( 'color', '#c00' ).text( '❌ Failed to create Odoo sale order — check Sync Log tab for details.' );
                }
            } )
            .fail( function () { $result.css( 'color', '#c00' ).text( '❌ AJAX request failed.' ); } )
            .always( function () { $btn.prop( 'disabled', false ).text( '📦 Push order to Odoo' ); } );
    } );

    // Load log
    $( '#loc-load-log' ).on( 'click', function () {
        var $btn = $( this ).prop( 'disabled', true ).text( 'Loading…' );
        $.post( locAdmin.ajax_url, { action: 'loc_view_log', nonce: locAdmin.nonce } )
            .done( function ( res ) {
                if ( res.success ) { $( '#loc-log-container' ).html( res.data ); }
            } )
            .always( function () { $btn.prop( 'disabled', false ).text( 'Load logs' ); } );
    } );
} );
