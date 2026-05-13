<?php
/**
 * Bootstrap class for Bangla Track Admin Server.
 *
 * @package BanglaTrackServer
 */

namespace BanglaTrackServer;

use BanglaTrackServer\Admin\Dashboard;
use BanglaTrackServer\Admin\LicensesPage;
use BanglaTrackServer\Admin\ActivationsPage;
use BanglaTrackServer\Database\Installer;
use BanglaTrackServer\REST\LicenseController;
use BanglaTrackServer\WooCommerce\ProductLicenseFields;
use BanglaTrackServer\WooCommerce\LicenseEntitlementManager;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class Bootstrap
 */
class Bootstrap {

    /**
     * Plugin instance.
     *
     * @var Bootstrap|null
     */
    private static $instance = null;

    /**
     * Get the singleton instance.
     *
     * @return Bootstrap
     */
    public static function instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Constructor.
     */
    private function __construct() {
        $this->init_hooks();
    }

    /**
     * Initialize hooks.
     *
     * @return void
     */
    private function init_hooks() {
        if ( class_exists( 'WooCommerce' ) ) {
            $entitlement_manager = new LicenseEntitlementManager();
            $entitlement_manager->register_hooks();
        }

        if ( is_admin() ) {
            add_action( 'admin_menu', array( $this, 'register_admin_menu' ) );
            add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );

            if ( class_exists( 'WooCommerce' ) ) {
                $product_license_fields = new ProductLicenseFields();
                $product_license_fields->register_hooks();
            }
        }

        add_action( 'rest_api_init', array( $this, 'init_rest_api' ) );
        add_action( 'init', array( $this, 'load_textdomain' ) );
        add_action( 'init', array( Installer::class, 'maybe_migrate' ), 5 );
        add_action( 'admin_init', array( Installer::class, 'maybe_migrate' ) );
    }

    /**
     * Register admin menu.
     *
     * @return void
     */
    public function register_admin_menu() {
        add_menu_page(
            __( 'BT Server', 'bangla-track-server' ),
            __( 'BT Server', 'bangla-track-server' ),
            'manage_options',
            'bt-server-dashboard',
            array( new Dashboard(), 'render' ),
            'dashicons-cloud',
            3
        );

        add_submenu_page(
            'bt-server-dashboard',
            __( 'Dashboard', 'bangla-track-server' ),
            __( 'Dashboard', 'bangla-track-server' ),
            'manage_options',
            'bt-server-dashboard',
            array()
        );

        add_submenu_page(
            'bt-server-dashboard',
            __( 'Licenses', 'bangla-track-server' ),
            __( 'Licenses', 'bangla-track-server' ),
            'manage_options',
            'bt-server-licenses',
            array( new LicensesPage(), 'render' )
        );

        add_submenu_page(
            'bt-server-dashboard',
            __( 'Activations', 'bangla-track-server' ),
            __( 'Activations', 'bangla-track-server' ),
            'manage_options',
            'bt-server-activations',
            array( new ActivationsPage(), 'render' )
        );
    }

    /**
     * Initialize REST API.
     *
     * @return void
     */
    public function init_rest_api() {
        $controller = new LicenseController();
        $controller->register_routes();
    }

    /**
     * Enqueue admin assets.
     *
     * @param string $hook Current admin page hook.
     * @return void
     */
    public function enqueue_admin_assets( $hook ) {
        if ( strpos( $hook, 'bt-server' ) === false ) {
            return;
        }

        wp_enqueue_style(
            'bt-server-admin',
            BT_SERVER_PLUGIN_URL . 'assets/css/admin.css',
            array(),
            BT_SERVER_VERSION
        );

        wp_enqueue_script(
            'bt-server-admin',
            BT_SERVER_PLUGIN_URL . 'assets/js/admin.js',
            array( 'jquery' ),
            BT_SERVER_VERSION,
            true
        );

        wp_localize_script( 'bt-server-admin', 'btServer', array(
            'ajaxUrl' => admin_url( 'admin-ajax.php' ),
            'nonce'   => wp_create_nonce( 'bt_server_admin' ),
        ) );
    }

    /**
     * Load plugin text domain.
     *
     * @return void
     */
    public function load_textdomain() {
        load_plugin_textdomain(
            'bangla-track-server',
            false,
            dirname( BT_SERVER_PLUGIN_BASENAME ) . '/languages'
        );
    }
}
