<?php 
////////////////////////////////////////////////////////////////////////
// Plugin Name: Closed Plugin Checker v. 2.0
// Description: Detects closed WordPress.org plugins
// Add: Admin alert, alert-email, plugins page column, site health check 
// Inspiration: https://maciekpalmowski.dev/blog/lets-talk-about-closed-plugins-in-the-wordpress-repository/
// Prior version: https://github.com/palmiak/closed-plugin-checker/
////////////////////////////////////////////////////////////////////////



defined( 'ABSPATH' ) || exit;

/**
 * Reliable repo detection
 * Based on WP core update_plugins transient logic
 */

function cpc_is_official_wp_plugin( $plugin_file, $plugin_data ) {

    if ( ! function_exists( 'get_site_transient' ) ) {
        require_once ABSPATH . 'wp-includes/update.php';
    }

    $update_plugins = get_site_transient( 'update_plugins' );

    if ( empty( $update_plugins ) ) {
        return false;
    }

    if ( isset( $update_plugins->no_update[ $plugin_file ] ) ) {
        $info = $update_plugins->no_update[ $plugin_file ];
        if ( is_object( $info ) ) {
            if ( isset( $info->id ) && strpos( $info->id, 'w.org/' ) !== false ) {
                return true;
            }
            if ( isset( $info->package ) && strpos( $info->package, 'wordpress.org' ) !== false ) {
                return true;
            }
        }
    }

    if ( isset( $update_plugins->response[ $plugin_file ] ) ) {
        $info = $update_plugins->response[ $plugin_file ];
        if ( is_object( $info ) && isset( $info->package ) && strpos( $info->package, 'wordpress.org' ) !== false ) {
            return true;
        }
    }

    return false;
}

/**
 * Bulk fetch plugin repo statuses
 */
function cpc_get_plugin_statuses() {

    $cache_key = 'cpc_plugin_statuses';
    $cached = get_transient( $cache_key );

    if ( false !== $cached ) {
        return $cached;
    }

    $all_plugins = get_plugins();
    $requests = array();
    $statuses = array();

    foreach ( $all_plugins as $plugin_file => $plugin_data ) {
        $slug = dirname( $plugin_file );
        if ( '.' === $slug ) {
            $slug = basename( $plugin_file, '.php' );
        }

        if ( ! cpc_is_official_wp_plugin( $plugin_file, $plugin_data ) ) {
            $statuses[ $plugin_file ] = array(
                'status'  => 'external',
                'message' => 'Plugin not in WordPress.org repository',
            );
            continue;
        }

        $requests[ $plugin_file ] = array(
            'url' => 'https://api.wordpress.org/plugins/info/1.0/' . $slug . '.json',
        );
    }

    if ( ! empty( $requests ) ) {
        // Modern loop utilizing native wp_remote_get
        $responses = array();
        foreach ( $requests as $plugin_file => $request ) {
            $responses[ $plugin_file ] = wp_remote_get( $request['url'] );
        }

        foreach ( $responses as $plugin_file => $response ) {

            if ( is_wp_error( $response ) ) {
                $statuses[ $plugin_file ] = array(
                    'status'  => 'unknown',
                    'message' => $response->get_error_message(),
                );
                continue;
            }

            $code = wp_remote_retrieve_response_code( $response );
            $raw  = wp_remote_retrieve_body( $response );

            // API unreachable / weird response
            if ( empty( $raw ) ) {
                $statuses[ $plugin_file ] = array(
                    'status'  => 'unknown',
                    'message' => 'Empty API response',
                );
                continue;
            }

            $body = json_decode( $raw );

            /*
            * OPEN plugin:
            * valid plugin object containing name/version/etc
            */
            if (
                is_object( $body ) &&
                ! isset( $body->error ) &&
                isset( $body->name )
            ) {

                $statuses[ $plugin_file ] = array(
                    'status'  => 'open',
                    'message' => 'Plugin active in repository',
                );

                continue;
            }

            /*
            * CLOSED / REMOVED plugin:
            * API returns error object
            */
            if (
                is_object( $body ) &&
                isset( $body->error )
            ) {

                $statuses[ $plugin_file ] = array(
                    'status'  => 'closed',
                    'message' => wp_strip_all_tags( $body->error ),
                );

                continue;
            }

            /*
            * Not a WP.org plugin at all
            */
            $statuses[ $plugin_file ] = array(
                'status'  => 'external',
                'message' => 'Plugin not found in WordPress.org repository',
            );
        }


    }

    // Call processing engine
    cpc_send_alerts( $statuses );

    set_transient( $cache_key, $statuses, 12 * HOUR_IN_SECONDS );

    return $statuses;
}

/**
 * Email alerts when plugin status transitions to closed
 */
function cpc_send_alerts( $statuses ) {
    $previous = get_option( 'cpc_previous_statuses', array() );
    $new_closed = array();

    foreach ( $statuses as $file => $status ) {
        $old = $previous[ $file ]['status'] ?? '';

        // Safely uses array transition logic from your original build
        if ( 'closed' === $status['status'] && 'closed' !== $old ) {
            $plugin = get_plugin_data( WP_PLUGIN_DIR . '/' . $file );
            $new_closed[] = $plugin['Name'] . ' - ' . wp_strip_all_tags( $status['message'] );
        }
    }

    if ( ! empty( $new_closed ) ) {
        wp_mail(
            get_option( 'admin_email' ),
            '[' . get_bloginfo( 'name' ) . '] Closed Plugins Detected',
            implode( "\n", $new_closed )
        );
    }

    update_option( 'cpc_previous_statuses', $statuses );
}

/**
 * Add column
 */
add_filter( 'manage_plugins_columns', function( $cols ) {
    $cols['repo_status'] = 'Repo Status';
    return $cols;
} );

/**
 * Render column
 */
add_action( 'manage_plugins_custom_column', function( $col, $file ) {
    if ( 'repo_status' !== $col ) {
        return;
    }

    $statuses = cpc_get_plugin_statuses();
    $status = $statuses[ $file ] ?? array( 'status' => 'unknown', 'message' => '' );

    switch ( $status['status'] ) {
        case 'open':
            echo '<span class="dashicons dashicons-yes-alt" style="color:green" title="' . esc_attr( $status['message'] ) . '"></span>';
            break;
        case 'closed':
            echo '<span class="dashicons dashicons-warning" style="color:red" title="' . esc_attr( $status['message'] ) . '"></span>';
            break;
        case 'external':
            echo '<span title="Not in WP repo">n.a.</span>';
            break;
        default:
            echo '<span class="dashicons dashicons-editor-help"></span>';
            break;
    }
}, 10, 2 );

/**
 * Highlight closed plugins
 */
add_action( 'admin_head', function() {
    $screen = get_current_screen();
    if ( empty( $screen->id ) || 'plugins' !== $screen->id ) {
        return;
    }

    $statuses = cpc_get_plugin_statuses();
    $closed = array_keys( array_filter( $statuses, function( $s ) {
        return ( $s['status'] ?? '' ) === 'closed';
    } ) );
    ?>
    <style>
        /* Target the table cells directly inside the row to beat WP specificity */
        tr.cpc-closed-plugin th, 
        tr.cpc-closed-plugin td { 
            background-color: #ffc4c4 !important; 
        }
    </style>
    <script>
    document.addEventListener('DOMContentLoaded', function () {
        const closed = <?php echo wp_json_encode( $closed ); ?>;
        closed.forEach(function(file) {
            const row = document.querySelector('tr[data-plugin="' + file + '"]');
            if (row) row.classList.add('cpc-closed-plugin');
        });
    });
    </script>
    <?php
});

/**
 * Get closed plugins list for site health
 */
function cpc_get_closed_plugins_list() {
    $statuses = cpc_get_plugin_statuses();
    $closed_plugins = array();
    
    foreach ( $statuses as $file => $status ) {
        if ( 'closed' === $status['status'] ) {
            $plugin = get_plugin_data( WP_PLUGIN_DIR . '/' . $file );
            $closed_plugins[] = (object) array(
                'name'        => $plugin['Name'],
                'description' => $status['message'],
            );
        }
    }
    
    return $closed_plugins;
}

/**
 * Format closed plugins as HTML list
 */
function cpc_format_closed_plugins_html( $closed_plugins ) {
    if ( empty( $closed_plugins ) ) {
        return '';
    }
    
    $items = array();
    foreach ( $closed_plugins as $plugin ) {
        $items[] = '<li><strong>' . esc_html( $plugin->name ) . '</strong> - ' . esc_html( $plugin->description ) . '</li>';
    }
    return '<ul>' . implode( '', $items ) . '</ul>';
}

function cpc_add_site_health_test( $tests ) {
    $tests['async']['closed-plugins'] = array(
        'label' => __( 'Closed plugins' ),
        'test'  => 'closed-plugins',
    );
    return $tests;
}
add_filter( 'site_status_tests', 'cpc_add_site_health_test' );

/**
 * AJAX handler for site health test
 */
function cpc_closed_plugins_test() {
    if ( ! function_exists( 'get_plugins' ) ) {
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
    }

    $closed_plugins = cpc_get_closed_plugins_list();
    $has_closed = ! empty( $closed_plugins );
    
    $result = array(
        'label'       => __( 'None of the plugins is closed' ),
        'status'      => 'good',
        'badge'       => array( 'label' => __( 'Security' ), 'color' => 'green' ),
        'description' => sprintf(
            '<p>%s</p><p>%s</p>',
            __( 'None of the installed plugins are closed in the repository. That\'s probably good news.' ),
            __( 'The fact that a plugin is available in the official repository doesn\'t mean it\'s safe.' )
        ),
        'actions'     => '',
        'test'        => 'closed-plugins',
    );
    
    if ( $has_closed ) {
        $result['label'] = __( 'Some installed plugins are closed' );
        $result['status'] = 'critical';
        $result['badge']['color'] = 'red';
        $result['description'] = sprintf( '<p>%s</p>', __( 'Some of the installed plugins are closed in the repository.' ) );
        $result['actions'] = sprintf(
            '<p>%s<br/>%s</p>',
            __( 'Consider replacing the closed plugins with alternatives:' ),
            cpc_format_closed_plugins_html( $closed_plugins )
        );
    }
    wp_send_json_success( $result );
}
add_action( 'wp_ajax_health-check-closed-plugins', 'cpc_closed_plugins_test' );

/**
 * Admin notices warning
 */
add_action( 'admin_notices', function() {
    if ( ! current_user_can( 'activate_plugins' ) ) {
        return;
    }

    if ( get_user_meta( get_current_user_id(), 'cpc_dismissed_notice', true ) ) {
        return;
    }

    $statuses = cpc_get_plugin_statuses(); 
    $closed_plugins = array();

    foreach ( $statuses as $plugin_file => $status ) {
        if ( empty( $status['status'] ) || 'closed' !== $status['status'] ) {
            continue;
        }
        $plugin_data = get_plugin_data( WP_PLUGIN_DIR . '/' . $plugin_file, false, false );
        $closed_plugins[] = $plugin_data['Name'];
    }

    if ( empty( $closed_plugins ) ) {
        return;
    }
    ?>
    <div class="notice notice-error is-dismissible cpc-closed-notice">
        <p><strong><?php esc_html_e( 'Warning: Some installed plugins are closed in the WordPress.org repository.', 'closed-plugin-checker' ); ?></strong></p>
        <p><?php echo esc_html( implode( ', ', $closed_plugins ) ); ?></p>
    </div>
    <script>
    jQuery(document).on('click', '.cpc-closed-notice .notice-dismiss', function() {
        jQuery.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'cpc_dismiss_notice',
                nonce: '<?php echo wp_create_nonce("cpc_dismiss_nonce"); ?>'
            }
        });
    });
    </script>
    <?php
} );

add_action( 'wp_ajax_cpc_dismiss_notice', function() {
    check_ajax_referer( 'cpc_dismiss_nonce', 'nonce' );
    if ( current_user_can( 'activate_plugins' ) ) {
        update_user_meta( get_current_user_id(), 'cpc_dismissed_notice', '1' );
        wp_send_json_success();
    }
    wp_send_json_error();
} );

/**
 * Reset dismissal states safely when transients clean out
 */

function cpc_reset_dismissals_on_refresh( $transient, $value, $expiration ) {

    // Only process when our specific plugin checker transient is updated
    if ( 'cpc_plugin_statuses' !== $transient || ! is_array( $value ) ) {
        return;
    }

    $closed_count = 0;
    foreach ( $value as $item ) {
        if ( isset( $item['status'] ) && 'closed' === $item['status'] ) {
            $closed_count++;
        }
    }

    if ( $closed_count > 0 ) {
        global $wpdb;
        $wpdb->delete(
            $wpdb->usermeta,
            array(
                'meta_key' => 'cpc_dismissed_notice',
            )
        );
    }
}
// Use the modern core hook (fires after the transient value is set)
add_action( 'set_transient', 'cpc_reset_dismissals_on_refresh', 10, 3 );


// Reset-helper (For Testing!)
// Paste uncommented into separate snippet to reset all transients for testing purposes 
/*

add_action( 'admin_init', function() {

    if ( ! current_user_can( 'manage_options' ) ) {
        return;
    }
    delete_transient( 'cpc_plugin_statuses' );
    delete_option( 'cpc_previous_statuses' );
    delete_site_transient( 'update_plugins' );
    wp_update_plugins();
    global $wpdb;
    $wpdb->delete(
        $wpdb->usermeta,
        array(
            'meta_key' => 'cpc_dismissed_notice',
        )
    );
    error_log( 'CPC reset complete' );
});


// Force fresh check on Site Health page
add_action( 'load-site-health.php', function() {
    delete_transient( 'cpc_plugin_statuses' );
} );

*/
