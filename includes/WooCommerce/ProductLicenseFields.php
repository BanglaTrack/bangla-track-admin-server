<?php
/**
 * WooCommerce product license fields.
 *
 * @package BanglaTrackServer
 */

namespace BanglaTrackServer\WooCommerce;

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
                'description' => __( 'Enable this if purchasing this product should generate a Bangla Track license key.', 'bangla-track-server' ),
            )
        );

        woocommerce_wp_select(
            array(
                'id'      => '_bt_plan_code',
                'label'   => __( 'Plan Code', 'bangla-track-server' ),
                'options' => array(
                    ''        => __( 'Select plan', 'bangla-track-server' ),
                    'free'    => __( 'Free', 'bangla-track-server' ),
                    'starter' => __( 'Starter', 'bangla-track-server' ),
                    'pro'     => __( 'Pro', 'bangla-track-server' ),
                ),
            )
        );

        woocommerce_wp_text_input(
            array(
                'id'          => '_bt_product_code',
                'label'       => __( 'Product Code', 'bangla-track-server' ),
                'placeholder' => 'free_forever, starter_monthly, pro_monthly, starter_yearly, pro_yearly',
                'desc_tip'    => true,
                'description' => __( 'Examples: free_forever, starter_monthly, pro_monthly, starter_yearly, pro_yearly.', 'bangla-track-server' ),
            )
        );

        woocommerce_wp_text_input(
            array(
                'id'                => '_bt_duration_days',
                'label'             => __( 'License Duration Days', 'bangla-track-server' ),
                'type'              => 'number',
                'custom_attributes' => array(
                    'min'  => '0',
                    'step' => '1',
                ),
                'description'       => __( 'Use 30 for monthly, 365 for yearly, 0 for lifetime/free forever.', 'bangla-track-server' ),
            )
        );

        woocommerce_wp_checkbox(
            array(
                'id'          => '_bt_is_lifetime',
                'label'       => __( 'Lifetime License', 'bangla-track-server' ),
                'description' => __( 'Enable this for Free Forever or lifetime products.', 'bangla-track-server' ),
            )
        );

        woocommerce_wp_text_input(
            array(
                'id'                => '_bt_monthly_booking_limit',
                'label'             => __( 'Monthly Booking Limit', 'bangla-track-server' ),
                'type'              => 'number',
                'custom_attributes' => array(
                    'step' => '1',
                ),
                'description'       => __( 'Use -1 for unlimited.', 'bangla-track-server' ),
            )
        );

        woocommerce_wp_text_input(
            array(
                'id'                => '_bt_allowed_active_providers',
                'label'             => __( 'Allowed Active Providers', 'bangla-track-server' ),
                'type'              => 'number',
                'custom_attributes' => array(
                    'step' => '1',
                ),
                'description'       => __( 'Use 1 for single provider, -1 for unlimited providers.', 'bangla-track-server' ),
            )
        );

        woocommerce_wp_checkbox(
            array(
                'id'    => '_bt_multi_provider',
                'label' => __( 'Allow Multi-provider', 'bangla-track-server' ),
            )
        );

        woocommerce_wp_text_input(
            array(
                'id'                => '_bt_max_sites',
                'label'             => __( 'Max Site Activations', 'bangla-track-server' ),
                'type'              => 'number',
                'custom_attributes' => array(
                    'min'  => '1',
                    'step' => '1',
                ),
                'description'       => __( 'How many WordPress sites can activate this license.', 'bangla-track-server' ),
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
        $this->save_checkbox_field( $product_id, '_bt_is_lifetime' );
        $this->save_checkbox_field( $product_id, '_bt_multi_provider' );

        $plan_code     = isset( $_POST['_bt_plan_code'] ) ? sanitize_key( wp_unslash( $_POST['_bt_plan_code'] ) ) : '';
        $allowed_plans = array( 'free', 'starter', 'pro' );

        if ( ! in_array( $plan_code, $allowed_plans, true ) ) {
            $plan_code = '';
        }

        update_post_meta( $product_id, '_bt_plan_code', $plan_code );

        $product_code = isset( $_POST['_bt_product_code'] ) ? sanitize_key( wp_unslash( $_POST['_bt_product_code'] ) ) : '';
        update_post_meta( $product_id, '_bt_product_code', $product_code );

        $duration_days = $this->get_posted_int( '_bt_duration_days', 0 );
        if ( $duration_days < 0 ) {
            $duration_days = 0;
        }
        update_post_meta( $product_id, '_bt_duration_days', $duration_days );

        $monthly_booking_limit = $this->get_posted_int( '_bt_monthly_booking_limit', 0 );
        update_post_meta( $product_id, '_bt_monthly_booking_limit', $monthly_booking_limit );

        $allowed_active_providers = $this->get_posted_int( '_bt_allowed_active_providers', 1 );
        update_post_meta( $product_id, '_bt_allowed_active_providers', $allowed_active_providers );

        $max_sites = $this->get_posted_int( '_bt_max_sites', 1 );
        if ( $max_sites < 1 ) {
            $max_sites = 1;
        }
        update_post_meta( $product_id, '_bt_max_sites', $max_sites );
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

    /**
     * Get a posted integer value.
     *
     * @param string $field_key Field key.
     * @param int    $default   Default value.
     * @return int
     */
    private function get_posted_int( $field_key, $default ) {
        if ( ! isset( $_POST[ $field_key ] ) ) {
            return $default;
        }

        return intval( wp_unslash( $_POST[ $field_key ] ) );
    }
}
