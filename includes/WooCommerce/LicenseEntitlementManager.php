<?php
/**
 * WooCommerce entitlement and customer license dashboard flow.
 *
 * @package BanglaTrackServer\WooCommerce
 */

namespace BanglaTrackServer\WooCommerce;

use BanglaTrackServer\Database\EntitlementRepository;
use BanglaTrackServer\Database\LicenseRepository;
use BanglaTrackServer\Services\LicenseProductRules;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class LicenseEntitlementManager {

    /**
     * My Account endpoint slug.
     */
    const ACCOUNT_ENDPOINT = 'bangla-track';

    /**
     * Entitlement repository.
     *
     * @var EntitlementRepository
     */
    private $entitlement_repo;

    /**
     * License repository.
     *
     * @var LicenseRepository
     */
    private $license_repo;

    /**
     * Constructor.
     */
    public function __construct() {
        $this->entitlement_repo = new EntitlementRepository();
        $this->license_repo     = new LicenseRepository();
    }

    /**
     * Register hooks.
     *
     * @return void
     */
    public function register_hooks() {
        add_action( 'init', array( $this, 'register_account_endpoint' ) );
        add_filter( 'query_vars', array( $this, 'register_query_vars' ), 0 );
        add_filter( 'woocommerce_account_menu_items', array( $this, 'add_my_account_menu_item' ) );
        add_action( 'woocommerce_account_' . self::ACCOUNT_ENDPOINT . '_endpoint', array( $this, 'render_my_account_dashboard' ) );
        add_filter( 'woocommerce_add_to_cart_redirect', array( $this, 'redirect_to_checkout_after_add' ) );
        add_action( 'template_redirect', array( $this, 'redirect_cart_page_to_checkout' ) );
        add_filter( 'woocommerce_add_to_cart_validation', array( $this, 'enforce_single_product_cart_on_add' ), 10, 6 );
        add_action( 'woocommerce_before_calculate_totals', array( $this, 'enforce_single_product_cart_integrity' ), 5 );

        add_action( 'woocommerce_order_status_completed', array( $this, 'handle_order_completed' ), 20, 1 );
        add_action( 'admin_post_bt_generate_license_key', array( $this, 'handle_generate_license_request' ) );
        add_action( 'admin_post_nopriv_bt_generate_license_key', array( $this, 'handle_generate_license_request' ) );
    }

    /**
     * Redirect to checkout after add to cart.
     *
     * @param string $url Existing redirect URL.
     * @return string
     */
    public function redirect_to_checkout_after_add( $url ) {
        if ( function_exists( 'wc_get_checkout_url' ) ) {
            return wc_get_checkout_url();
        }

        return $url;
    }

    /**
     * Redirect cart page to checkout so cart page is not used by customers.
     *
     * @return void
     */
    public function redirect_cart_page_to_checkout() {
        if ( is_admin() || wp_doing_ajax() ) {
            return;
        }

        if ( function_exists( 'is_cart' ) && is_cart() && function_exists( 'wc_get_checkout_url' ) ) {
            wp_safe_redirect( wc_get_checkout_url() );
            exit;
        }
    }

    /**
     * Keep only one product type in cart by replacing old cart contents.
     *
     * @param bool  $passed Validation status.
     * @param int   $product_id Product ID being added.
     * @param int   $quantity Quantity.
     * @param int   $variation_id Variation ID.
     * @param array $variations Variation data.
     * @param array $cart_item_data Cart item data.
     * @return bool
     */
    public function enforce_single_product_cart_on_add( $passed, $product_id, $quantity, $variation_id = 0, $variations = array(), $cart_item_data = array() ) {
        if ( ! $passed || ! function_exists( 'WC' ) || ! WC()->cart ) {
            return $passed;
        }

        if ( WC()->cart->is_empty() ) {
            return $passed;
        }

        // Replace old cart items with the newly added product.
        WC()->cart->empty_cart();

        return $passed;
    }

    /**
     * Final integrity guard: cart should contain only one line item.
     *
     * @param \WC_Cart $cart WooCommerce cart instance.
     * @return void
     */
    public function enforce_single_product_cart_integrity( $cart ) {
        if ( is_admin() && ! wp_doing_ajax() ) {
            return;
        }

        if ( ! is_object( $cart ) || ! method_exists( $cart, 'get_cart' ) ) {
            return;
        }

        $items = $cart->get_cart();
        if ( count( $items ) <= 1 ) {
            return;
        }

        $keys = array_keys( $items );
        $keep = end( $keys );

        foreach ( $items as $cart_item_key => $item ) {
            if ( $cart_item_key === $keep ) {
                continue;
            }
            $cart->remove_cart_item( $cart_item_key );
        }
    }

    /**
     * Register account endpoint.
     *
     * @return void
     */
    public function register_account_endpoint() {
        add_rewrite_endpoint( self::ACCOUNT_ENDPOINT, EP_ROOT | EP_PAGES );
    }

    /**
     * Register endpoint query var.
     *
     * @param array<int, string> $vars Existing vars.
     * @return array<int, string>
     */
    public function register_query_vars( $vars ) {
        if ( ! in_array( self::ACCOUNT_ENDPOINT, $vars, true ) ) {
            $vars[] = self::ACCOUNT_ENDPOINT;
        }
        return $vars;
    }

    /**
     * Add Bangla Track link into the My Account menu.
     *
     * @param array<string, string> $items Existing menu items.
     * @return array<string, string>
     */
    public function add_my_account_menu_item( $items ) {
        $logout = null;
        if ( isset( $items['customer-logout'] ) ) {
            $logout = $items['customer-logout'];
            unset( $items['customer-logout'] );
        }

        $items[ self::ACCOUNT_ENDPOINT ] = __( 'Bangla Track', 'bangla-track-server' );

        if ( null !== $logout ) {
            $items['customer-logout'] = $logout;
        }

        return $items;
    }

    /**
     * Create pending entitlement(s) after order completion.
     *
     * @param int $order_id WooCommerce order ID.
     * @return void
     */
    public function handle_order_completed( $order_id ) {
        if ( ! function_exists( 'wc_get_order' ) ) {
            return;
        }

        $order = wc_get_order( absint( $order_id ) );
        if ( ! $order ) {
            return;
        }

        $user_id     = absint( $order->get_user_id() );
        if ( $user_id <= 0 ) {
            $billing_email = sanitize_email( $order->get_billing_email() );
            if ( ! empty( $billing_email ) ) {
                $matched_user = get_user_by( 'email', $billing_email );
                if ( $matched_user ) {
                    $user_id = absint( $matched_user->ID );
                }
            }
        }
        $items       = $order->get_items( 'line_item' );
        $created_any = false;

        foreach ( $items as $item_id => $item ) {
            $already_created = wc_get_order_item_meta( $item_id, '_bt_license_entitlement_created', true );
            if ( 'yes' === $already_created ) {
                continue;
            }

            $product_id = absint( $item->get_product_id() );
            if ( $product_id <= 0 ) {
                continue;
            }

            $is_license_product = get_post_meta( $product_id, '_bt_is_license_product', true );
            if ( 'yes' !== $is_license_product ) {
                continue;
            }

            $product_code = sanitize_key( (string) get_post_meta( $product_id, '_bt_license_product_code', true ) );
            if ( ! LicenseProductRules::is_valid_product_code( $product_code ) ) {
                continue;
            }

            $existing = $this->entitlement_repo->get_by_order_item( (int) $order_id, (int) $item_id );
            if ( $existing ) {
                wc_update_order_item_meta( $item_id, '_bt_license_entitlement_created', 'yes' );
                wc_update_order_item_meta( $item_id, '_bt_license_entitlement_id', (int) $existing->id );
                continue;
            }

            $entitlement_id = $this->entitlement_repo->create(
                array(
                    'user_id'      => $user_id,
                    'order_id'     => (int) $order_id,
                    'order_item_id'=> (int) $item_id,
                    'product_id'   => $product_id,
                    'product_code' => $product_code,
                    'status'       => 'pending',
                )
            );

            if ( ! $entitlement_id ) {
                $existing_after_insert = $this->entitlement_repo->get_by_order_item( (int) $order_id, (int) $item_id );
                if ( $existing_after_insert ) {
                    wc_update_order_item_meta( $item_id, '_bt_license_entitlement_created', 'yes' );
                    wc_update_order_item_meta( $item_id, '_bt_license_entitlement_id', (int) $existing_after_insert->id );
                }
                continue;
            }

            wc_update_order_item_meta( $item_id, '_bt_license_entitlement_created', 'yes' );
            wc_update_order_item_meta( $item_id, '_bt_license_entitlement_id', (int) $entitlement_id );
            $created_any = true;
        }

        if ( $created_any ) {
            $order->add_order_note( 'Bangla Track license entitlement created. Customer can generate the license from dashboard.' );
        }
    }

    /**
     * Render Bangla Track dashboard in My Account.
     *
     * @return void
     */
    public function render_my_account_dashboard() {
        if ( ! is_user_logged_in() ) {
            echo '<p>' . esc_html__( 'Please log in to view your Bangla Track licenses.', 'bangla-track-server' ) . '</p>';
            return;
        }

        $user_id       = get_current_user_id();
        $entitlements  = $this->entitlement_repo->get_for_user( $user_id );
        $dashboard_url = wc_get_account_endpoint_url( self::ACCOUNT_ENDPOINT );

        if ( isset( $_GET['bt_license_message'] ) ) {
            $message = sanitize_key( (string) wp_unslash( $_GET['bt_license_message'] ) );
            if ( 'generated' === $message ) {
                wc_print_notice( __( 'License key generated successfully.', 'bangla-track-server' ), 'success' );
            } elseif ( 'already_generated' === $message ) {
                wc_print_notice( __( 'License key is already generated for this purchase.', 'bangla-track-server' ), 'notice' );
            } elseif ( 'error' === $message ) {
                wc_print_notice( __( 'Unable to generate license key. Please try again.', 'bangla-track-server' ), 'error' );
            }
        }

        echo '<h3>' . esc_html__( 'Bangla Track Licenses', 'bangla-track-server' ) . '</h3>';

        if ( empty( $entitlements ) ) {
            echo '<p>' . esc_html__( 'No Bangla Track purchase entitlement found yet.', 'bangla-track-server' ) . '</p>';
            return;
        }

        foreach ( $entitlements as $entitlement ) {
            $product_name = $this->get_product_name( $entitlement );
            $product_code = sanitize_key( (string) $entitlement->product_code );
            $order_number = absint( $entitlement->order_id );

            echo '<div style="border:1px solid #ddd;padding:16px;margin-bottom:16px;border-radius:4px;">';
            echo '<p><strong>' . esc_html__( 'You have purchased:', 'bangla-track-server' ) . '</strong> ' . esc_html( $product_name ) . '</p>';
            echo '<p><strong>' . esc_html__( 'Product code:', 'bangla-track-server' ) . '</strong> ' . esc_html( $product_code ) . '</p>';
            echo '<p><strong>' . esc_html__( 'Order:', 'bangla-track-server' ) . '</strong> #' . esc_html( (string) $order_number ) . '</p>';

            if ( 'pending' === $entitlement->status ) {
                echo '<p><strong>' . esc_html__( 'License status:', 'bangla-track-server' ) . '</strong> ' . esc_html__( 'License not generated yet', 'bangla-track-server' ) . '</p>';
                echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
                echo '<input type="hidden" name="action" value="bt_generate_license_key" />';
                echo '<input type="hidden" name="entitlement_id" value="' . esc_attr( (string) absint( $entitlement->id ) ) . '" />';
                wp_nonce_field( 'bt_generate_license_key_' . absint( $entitlement->id ), 'bt_generate_license_nonce' );
                echo '<button type="submit" class="button">' . esc_html__( 'Generate License Key', 'bangla-track-server' ) . '</button>';
                echo '</form>';
            } elseif ( 'generated' === $entitlement->status && ! empty( $entitlement->license_key ) ) {
                $license_key = strtoupper( sanitize_text_field( (string) $entitlement->license_key ) );
                $plan_label  = $this->format_plan_label( (string) $entitlement->plan_code );
                $status      = sanitize_key( (string) ( $entitlement->license_status ?: $entitlement->status ) );
                $starts_at   = $this->format_date_for_display( (string) $entitlement->starts_at );
                $expires_at  = $this->format_expires_for_display( $entitlement->expires_at );

                echo '<p><strong>' . esc_html__( 'License key:', 'bangla-track-server' ) . '</strong> <code>' . esc_html( $license_key ) . '</code></p>';
                echo '<p><strong>' . esc_html__( 'Plan:', 'bangla-track-server' ) . '</strong> ' . esc_html( $plan_label ) . '</p>';
                echo '<p><strong>' . esc_html__( 'Status:', 'bangla-track-server' ) . '</strong> ' . esc_html( ucfirst( $status ) ) . '</p>';
                echo '<p><strong>' . esc_html__( 'Starts at:', 'bangla-track-server' ) . '</strong> ' . esc_html( $starts_at ) . '</p>';
                echo '<p><strong>' . esc_html__( 'Expires at:', 'bangla-track-server' ) . '</strong> ' . esc_html( $expires_at ) . '</p>';
                echo '<button type="button" class="button bt-copy-license-key" data-license="' . esc_attr( $license_key ) . '">' . esc_html__( 'Copy License Key', 'bangla-track-server' ) . '</button>';
            } else {
                echo '<p><strong>' . esc_html__( 'License status:', 'bangla-track-server' ) . '</strong> ' . esc_html( ucfirst( sanitize_key( (string) $entitlement->status ) ) ) . '</p>';
            }

            echo '</div>';
        }

        echo '<script>
            (function() {
                var buttons = document.querySelectorAll(".bt-copy-license-key");
                if (!buttons.length) { return; }
                buttons.forEach(function(button) {
                    button.addEventListener("click", function() {
                        var key = button.getAttribute("data-license") || "";
                        if (!key) { return; }
                        if (navigator.clipboard && navigator.clipboard.writeText) {
                            navigator.clipboard.writeText(key);
                        } else {
                            var temp = document.createElement("textarea");
                            temp.value = key;
                            document.body.appendChild(temp);
                            temp.select();
                            document.execCommand("copy");
                            document.body.removeChild(temp);
                        }
                        button.textContent = "Copied";
                        setTimeout(function() { button.textContent = "Copy License Key"; }, 1500);
                    });
                });
            })();
        </script>';
    }

    /**
     * Handle manual generate license request.
     *
     * @return void
     */
    public function handle_generate_license_request() {
        $redirect_url = function_exists( 'wc_get_account_endpoint_url' )
            ? wc_get_account_endpoint_url( self::ACCOUNT_ENDPOINT )
            : home_url( '/' );

        if ( ! is_user_logged_in() ) {
            wp_safe_redirect( wp_login_url( $redirect_url ) );
            exit;
        }

        $entitlement_id = absint( $_POST['entitlement_id'] ?? 0 );
        $nonce          = sanitize_text_field( wp_unslash( $_POST['bt_generate_license_nonce'] ?? '' ) );
        if ( $entitlement_id <= 0 || ! wp_verify_nonce( $nonce, 'bt_generate_license_key_' . $entitlement_id ) ) {
            wp_safe_redirect( add_query_arg( 'bt_license_message', 'error', $redirect_url ) );
            exit;
        }

        $current_user_id = get_current_user_id();
        $entitlement     = $this->entitlement_repo->get_by_id( $entitlement_id );
        if ( ! $entitlement || absint( $entitlement->user_id ) !== absint( $current_user_id ) ) {
            wp_safe_redirect( add_query_arg( 'bt_license_message', 'error', $redirect_url ) );
            exit;
        }

        $existing_license = $this->license_repo->get_by_entitlement_id( $entitlement_id );
        if ( $existing_license ) {
            $this->entitlement_repo->sync_generated( $entitlement_id, (int) $existing_license->id );
            wp_safe_redirect( add_query_arg( 'bt_license_message', 'already_generated', $redirect_url ) );
            exit;
        }

        if ( 'pending' !== $entitlement->status ) {
            wp_safe_redirect( add_query_arg( 'bt_license_message', 'already_generated', $redirect_url ) );
            exit;
        }

        $product_code = sanitize_key( (string) $entitlement->product_code );
        if ( ! LicenseProductRules::is_valid_product_code( $product_code ) ) {
            wp_safe_redirect( add_query_arg( 'bt_license_message', 'error', $redirect_url ) );
            exit;
        }

        $rules = LicenseProductRules::get_rules( $product_code );
        if ( empty( $rules ) ) {
            wp_safe_redirect( add_query_arg( 'bt_license_message', 'error', $redirect_url ) );
            exit;
        }

        $plan_code = sanitize_key( (string) ( $rules['plan_code'] ?? 'free' ) );
        if ( ! in_array( $plan_code, array( 'free', 'starter', 'pro' ), true ) ) {
            $plan_code = 'free';
        }

        $starts_dt     = current_datetime();
        $starts_at     = $starts_dt->format( 'Y-m-d H:i:s' );
        $is_lifetime   = ! empty( $rules['is_lifetime'] );
        $duration_days = absint( $rules['duration_days'] ?? 0 );
        $expires_at    = null;

        if ( ! $is_lifetime && $duration_days > 0 ) {
            $expires_at = $starts_dt->modify( '+' . $duration_days . ' days' )->format( 'Y-m-d H:i:s' );
        }

        $user          = wp_get_current_user();
        $license_key   = $this->license_repo->generate_key( $plan_code );
        $new_license_id = $this->license_repo->create(
            array(
                'license_key'               => $license_key,
                'user_id'                   => $current_user_id,
                'entitlement_id'            => $entitlement_id,
                'order_id'                  => absint( $entitlement->order_id ),
                'order_item_id'             => absint( $entitlement->order_item_id ),
                'product_id'                => absint( $entitlement->product_id ),
                'product_code'              => $product_code,
                'plan_code'                 => $plan_code,
                'status'                    => 'active',
                'is_lifetime'               => $is_lifetime ? 1 : 0,
                'duration_days'             => $duration_days,
                'starts_at'                 => $starts_at,
                'expires_at'                => $expires_at,
                'monthly_booking_limit'     => intval( $rules['monthly_booking_limit'] ?? 100 ),
                'allowed_active_providers'  => intval( $rules['allowed_active_providers'] ?? 1 ),
                'multi_provider'            => ! empty( $rules['multi_provider'] ) ? 1 : 0,
                'max_sites'                 => absint( $rules['max_sites'] ?? 1 ),
                'customer_email'            => sanitize_email( $user->user_email ?? '' ),
                'customer_name'             => sanitize_text_field( $user->display_name ?? '' ),
            )
        );

        if ( ! $new_license_id ) {
            $race_license = $this->license_repo->get_by_entitlement_id( $entitlement_id );
            if ( $race_license ) {
                $this->entitlement_repo->sync_generated( $entitlement_id, (int) $race_license->id );
                wp_safe_redirect( add_query_arg( 'bt_license_message', 'already_generated', $redirect_url ) );
                exit;
            }

            wp_safe_redirect( add_query_arg( 'bt_license_message', 'error', $redirect_url ) );
            exit;
        }

        $marked = $this->entitlement_repo->mark_generated_if_pending( $entitlement_id, (int) $new_license_id );
        if ( ! $marked ) {
            $this->entitlement_repo->sync_generated( $entitlement_id, (int) $new_license_id );
        }
        $license = $this->license_repo->get_by_id( (int) $new_license_id );

        if ( function_exists( 'wc_get_order' ) ) {
            $order = wc_get_order( absint( $entitlement->order_id ) );
            if ( $order && $license ) {
                $order->add_order_note( 'Bangla Track license generated from dashboard: ' . $license->license_key );
            }
        }

        if ( $license ) {
            $this->send_generated_license_email( $entitlement, $license, $redirect_url );
        }

        wp_safe_redirect( add_query_arg( 'bt_license_message', 'generated', $redirect_url ) );
        exit;
    }

    /**
     * Send customer email after manual generation.
     *
     * @param object $entitlement Entitlement row.
     * @param object $license License row.
     * @param string $dashboard_url Dashboard URL.
     * @return void
     */
    private function send_generated_license_email( $entitlement, $license, $dashboard_url ) {
        $to = '';

        if ( ! empty( $entitlement->user_id ) ) {
            $user = get_user_by( 'id', absint( $entitlement->user_id ) );
            if ( $user && ! empty( $user->user_email ) ) {
                $to = sanitize_email( $user->user_email );
            }
        }

        if ( empty( $to ) && function_exists( 'wc_get_order' ) ) {
            $order = wc_get_order( absint( $entitlement->order_id ) );
            if ( $order ) {
                $to = sanitize_email( $order->get_billing_email() );
            }
        }

        if ( empty( $to ) ) {
            return;
        }

        $product_name = $this->get_product_name( $entitlement );
        $plan_name    = $this->format_plan_label( (string) $license->plan_code );
        $starts_at    = $this->format_date_for_display( (string) $license->starts_at );
        $expires_at   = $this->format_expires_for_display( $license->expires_at );

        $subject = 'Your Bangla Track license key';
        $body    = "Hello,\n\n";
        $body   .= "Your Bangla Track license key is ready.\n\n";
        $body   .= 'License key: ' . $license->license_key . "\n";
        $body   .= 'Product name: ' . $product_name . "\n";
        $body   .= 'Plan name: ' . $plan_name . "\n";
        $body   .= 'Starts at: ' . $starts_at . "\n";
        $body   .= 'Expires at: ' . $expires_at . "\n";
        $body   .= 'Dashboard link: ' . $dashboard_url . "\n\n";
        $body   .= "Thank you.";

        wp_mail( $to, $subject, $body );
    }

    /**
     * Format plan label.
     *
     * @param string $plan_code Plan code.
     * @return string
     */
    private function format_plan_label( $plan_code ) {
        $plan_code = sanitize_key( $plan_code );
        if ( empty( $plan_code ) ) {
            return 'Free';
        }
        return ucwords( str_replace( '_', ' ', $plan_code ) );
    }

    /**
     * Format display date in site timezone.
     *
     * @param string $date Date string.
     * @return string
     */
    private function format_date_for_display( $date ) {
        if ( empty( $date ) ) {
            return '-';
        }

        $timestamp = strtotime( $date );
        if ( ! $timestamp ) {
            return '-';
        }

        return wp_date( 'M j, Y g:i a', $timestamp, wp_timezone() );
    }

    /**
     * Format expiry display text.
     *
     * @param string|null $expires_at Expiry datetime.
     * @return string
     */
    private function format_expires_for_display( $expires_at ) {
        if ( empty( $expires_at ) ) {
            return __( 'Lifetime', 'bangla-track-server' );
        }
        return $this->format_date_for_display( (string) $expires_at );
    }

    /**
     * Resolve product name from product/order data.
     *
     * @param object $entitlement Entitlement row.
     * @return string
     */
    private function get_product_name( $entitlement ) {
        $product_name = '';

        if ( function_exists( 'wc_get_product' ) && ! empty( $entitlement->product_id ) ) {
            $product = wc_get_product( absint( $entitlement->product_id ) );
            if ( $product ) {
                $product_name = $product->get_name();
            }
        }

        if ( empty( $product_name ) && function_exists( 'wc_get_order' ) && ! empty( $entitlement->order_id ) && ! empty( $entitlement->order_item_id ) ) {
            $order = wc_get_order( absint( $entitlement->order_id ) );
            if ( $order ) {
                $item = $order->get_item( absint( $entitlement->order_item_id ) );
                if ( $item ) {
                    $product_name = $item->get_name();
                }
            }
        }

        if ( ! empty( $product_name ) ) {
            return $product_name;
        }

        $options = LicenseProductRules::get_product_options();
        $code    = sanitize_key( (string) $entitlement->product_code );
        if ( isset( $options[ $code ] ) ) {
            return (string) $options[ $code ];
        }

        return ucfirst( str_replace( '_', ' ', $code ) );
    }
}
