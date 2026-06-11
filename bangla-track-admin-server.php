<?php
/**
 * Plugin Name: Bangla Track Admin Server
 * Plugin URI: http://banglatrack.com/
 * Description: License management and monitoring server for Bangla Track Pro installations.
 * Version: 1.5.3
 * Author: Zahid Uddin
 * Author URI: http://zahiduddin.com/
 * Text Domain: bangla-track-server
 * Domain Path: /languages
 * Requires at least: 5.8
 * Requires PHP: 8.1
 *
 * @package BanglaTrackServer
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'BT_SERVER_VERSION', '1.5.3' );
define( 'BT_SERVER_PLUGIN_FILE', __FILE__ );
define( 'BT_SERVER_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'BT_SERVER_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'BT_SERVER_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

// Shared API key for REST header authentication (X-BT-API-Key).
// Must match BANGLA_TRACK_API_KEY in the client plugin.
if ( ! defined( 'BT_SERVER_API_KEY' ) ) {
    define( 'BT_SERVER_API_KEY', 'bt_live_sk_2026_bAngL4Tr4cK_s3rv3r_k3y_x9Qm2pL7' );
}

require_once BT_SERVER_PLUGIN_DIR . 'includes/Autoloader.php';
\BanglaTrackServer\Autoloader::register();

register_activation_hook( __FILE__, array( \BanglaTrackServer\Database\Installer::class, 'activate' ) );

/**
 * Initialize the plugin.
 *
 * @return \BanglaTrackServer\Bootstrap
 */
function bangla_track_server() {
    return \BanglaTrackServer\Bootstrap::instance();
}

add_action( 'plugins_loaded', function() {
    bangla_track_server();
}, 10 );

add_filter( 'plugin_action_links_' . BT_SERVER_PLUGIN_BASENAME, function( $links ) {
    $dashboard_link = '<a href="' . esc_url( admin_url( 'admin.php?page=bt-server-dashboard' ) ) . '">' 
                    . esc_html__( 'Dashboard', 'bangla-track-server' ) . '</a>';
    array_unshift( $links, $dashboard_link );
    return $links;
} );
