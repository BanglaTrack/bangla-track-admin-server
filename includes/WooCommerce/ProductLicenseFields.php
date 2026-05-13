<?php
/**
 * WooCommerce product license fields.
 *
 * @package BanglaTrackServer
 */

namespace BanglaTrackServer\WooCommerce;

use BanglaTrackServer\Services\LicenseProductRules;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class ProductLicenseFields
 */
class ProductLicenseFields {

    /**
     * Register hooks.
     *
     * @return void
     */
    public function register_hooks() {
        add_filter( 'woocommerce_product_data_tabs', array( $this, 'add_product_data_tab' ) );
        add_action( 'woocommerce_product_data_panels', array( $this, 'render_product_data_panel' ) );
        add_action( 'woocommerce_process_product_meta', array( $this, 'save_product_fields' ) );
    }

    /**
     * Add custom product data tab.
     *
     * @param array<string, mixed> $tabs Existing tabs.
     * @return array<string, mixed>
     */
    public function add_product_data_tab( $tabs ) {
        $tabs['bt_license_product_data'] = array(
            'label'    => __( 'Bangla Track License', 'bangla-track-server' ),
            'target'   => 'bt_license_product_data',
            'class'    => array( 'show_if_simple', 'show_if_virtual' ),
            'priority' => 80,
        );

        return $tabs;
    }

    /**
     * Render custom product data panel.
     *
     * @return void
     */
    public function render_product_data_panel() {
        echo '<div id="bt_license_product_data" class="panel woocommerce_options_panel hidden">';
        echo '<div class="options_group">';

        woocommerce_wp_checkbox(
            array(
                'id'          => '_bt_is_license_product',
                'label'       => __( 'Enable Bangla Track License Product', 'bangla-track-server' ),
                'description' => __( 'Enable this if purchasing this product should create a Bangla Track entitlement. Customer can generate the license key later from dashboard.', 'bangla-track-server' ),
            )
        );

        woocommerce_wp_select(
            array(
                'id'      => '_bt_license_product_code',
                'label'   => __( 'License Product Type', 'bangla-track-server' ),
                'options' => LicenseProductRules::get_product_options(),
            )
        );

        echo '</div>';
        echo '</div>';
    }

    /**
     * Save product fields.
     *
     * @param int $product_id Product ID.
     * @return void
     */
    public function save_product_fields( $product_id ) {
        $this->save_checkbox_field( $product_id, '_bt_is_license_product' );
        $product_code = isset( $_POST['_bt_license_product_code'] ) ? sanitize_key( wp_unslash( $_POST['_bt_license_product_code'] ) ) : '';
        if ( ! LicenseProductRules::is_valid_product_code( $product_code ) ) {
            $product_code = '';
        }
        update_post_meta( $product_id, '_bt_license_product_code', $product_code );
    }

    /**
     * Save checkbox field as yes/no.
     *
     * @param int    $product_id Product ID.
     * @param string $meta_key   Meta key.
     * @return void
     */
    private function save_checkbox_field( $product_id, $meta_key ) {
        $value = isset( $_POST[ $meta_key ] ) ? 'yes' : 'no';
        update_post_meta( $product_id, $meta_key, $value );
    }
}
