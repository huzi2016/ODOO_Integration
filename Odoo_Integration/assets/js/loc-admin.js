/* LIMO Odoo Connector — Admin JS */
jQuery( function ( $ ) {

    // Fill webhook URLs
    if ( locAdmin.webhook_url ) {
        $( '#loc-webhook-url' ).text( locAdmin.webhook_url );
        $( '#loc-inv-url' ).text( locAdmin.inv_url );
    }

    // Connection test
    $( '#loc-test-btn' ).on( 'click', function () {
        var $btn = $( this ).prop( 'disabled', true ).text( '测试中…' );
        var $result = $( '#loc-test-result' );
        $.post( locAdmin.ajax_url, { action: 'loc_test_connection', nonce: locAdmin.nonce } )
            .done( function ( res ) {
                $result.css( 'color', res.success ? '#0a5' : '#c00' ).text( res.data );
            } )
            .always( function () { $btn.prop( 'disabled', false ).text( '🧪 测试 Odoo 连接' ); } );
    } );

    // Manual sync buttons
    $( '.loc-sync-btn' ).on( 'click', function () {
        var $btn    = $( this ).prop( 'disabled', true ).text( '同步中…' );
        var action  = $btn.data( 'action' );
        var $result = $( '#loc-sync-result' );
        $.post( locAdmin.ajax_url, { action: action, nonce: locAdmin.nonce } )
            .done( function ( res ) {
                $result.text( res.success ? '✅ ' + res.data : '❌ ' + res.data );
            } )
            .always( function () { $btn.prop( 'disabled', false ).text( '立即同步' ); } );
    } );

    // Load log
    $( '#loc-load-log' ).on( 'click', function () {
        var $btn = $( this ).prop( 'disabled', true ).text( '加载中…' );
        $.post( locAdmin.ajax_url, { action: 'loc_view_log', nonce: locAdmin.nonce } )
            .done( function ( res ) {
                if ( res.success ) { $( '#loc-log-container' ).html( res.data ); }
            } )
            .always( function () { $btn.prop( 'disabled', false ).text( '加载日志' ); } );
    } );
} );
