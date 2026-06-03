<?php
namespace BanglaTrackServer\REST;

use BanglaTrackServer\Database\ActivationRepository;
use BanglaTrackServer\Database\LicenseRepository;
use BanglaTrackServer\Database\ProviderLockRepository;
use BanglaTrackServer\Database\SitePluginsRepository;
use BanglaTrackServer\Database\UsageRepository;
use BanglaTrackServer\Core\PolicySigner;
use BanglaTrackServer\REST\SiteCheckinController;
use WP_REST_Controller;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class LicenseController extends WP_REST_Controller {
    protected $namespace = 'bt-server/v1';

    private $license_repo;
    private $activation_repo;
    private $usage_repo;
    private $provider_lock_repo;
    private $site_plugins_repo;
    private $policy_signer;

    public function __construct() {
        $this->license_repo = new LicenseRepository();
        $this->activation_repo = new ActivationRepository();
        $this->usage_repo = new UsageRepository();
        $this->provider_lock_repo = new ProviderLockRepository();
        $this->site_plugins_repo = new SitePluginsRepository();
        $this->policy_signer = new PolicySigner();
    }

    public function register_routes() {
        $public_args = array(
            'methods'             => WP_REST_Server::CREATABLE,
            'permission_callback' => array( SiteCheckinController::class, 'check_api_key' ),
        );

        // Shared schema for license_key + site_url parameters.
        $license_site_args = array(
            'license_key' => array(
                'type'              => 'string',
                'required'          => true,
                'sanitize_callback' => 'sanitize_text_field',
                'description'       => __( 'License key in BTP-XXXX-XXXX-XXXX format.', 'bangla-track-server' ),
            ),
            'site_url' => array(
                'type'              => 'string',
                'required'          => true,
                'sanitize_callback' => 'esc_url_raw',
                'description'       => __( 'The site URL requesting activation.', 'bangla-track-server' ),
            ),
            'site_name' => array(
                'type'              => 'string',
                'required'          => false,
                'sanitize_callback' => 'sanitize_text_field',
                'default'           => '',
            ),
            'wp_version' => array(
                'type'              => 'string',
                'required'          => false,
                'sanitize_callback' => 'sanitize_text_field',
                'default'           => '',
            ),
            'plugin_version' => array(
                'type'              => 'string',
                'required'          => false,
                'sanitize_callback' => 'sanitize_text_field',
                'default'           => '',
            ),
            'php_version' => array(
                'type'              => 'string',
                'required'          => false,
                'sanitize_callback' => 'sanitize_text_field',
                'default'           => '',
            ),
        );

        register_rest_route( $this->namespace, '/license/activate', array_merge( $public_args, array( 'callback' => array( $this, 'activate_license' ), 'args' => $license_site_args ) ) );
        register_rest_route( $this->namespace, '/license/deactivate', array_merge( $public_args, array( 'callback' => array( $this, 'deactivate_license' ), 'args' => $license_site_args ) ) );
        register_rest_route( $this->namespace, '/license/status', array_merge( $public_args, array( 'callback' => array( $this, 'license_status' ), 'args' => $license_site_args ) ) );
        register_rest_route( $this->namespace, '/usage/can-book', array_merge( $public_args, array( 'callback' => array( $this, 'can_book' ), 'args' => $license_site_args ) ) );
        register_rest_route( $this->namespace, '/usage/report-booking', array_merge( $public_args, array(
            'callback' => array( $this, 'report_booking' ),
            'args'     => array_merge( $license_site_args, array(
                'provider_slug'  => array( 'type' => 'string', 'required' => true, 'sanitize_callback' => 'sanitize_key' ),
                'order_id'       => array( 'type' => 'integer', 'required' => true ),
                'consignment_id' => array( 'type' => 'string', 'required' => false, 'sanitize_callback' => 'sanitize_text_field', 'default' => '' ),
            ) ),
        ) ) );
        register_rest_route( $this->namespace, '/provider/lock', array_merge( $public_args, array( 'callback' => array( $this, 'lock_provider' ), 'args' => $license_site_args ) ) );
        register_rest_route( $this->namespace, '/provider/can-use', array_merge( $public_args, array( 'callback' => array( $this, 'provider_can_use' ), 'args' => $license_site_args ) ) );
    }

    public function activate_license( WP_REST_Request $request ) {
        $ctx = $this->resolve_context( $request );
        if ( is_wp_error( $ctx ) ) { return $ctx; }

        $license = $ctx['license'];

        // ── max_sites enforcement ──
        // Check if the license has reached its maximum concurrent site slots.
        // Re-activations on the same URL are always allowed (slot reuse).
        $max_sites    = max( 1, (int) ( $license->max_sites ?: $license->max_activations ?: 1 ) );
        $active_count = $this->activation_repo->get_active_count( (int) $license->id );
        $existing     = $this->activation_repo->get_by_license_and_site( (int) $license->id, $ctx['site']['site_url'] );

        if ( $active_count >= $max_sites && ! $existing ) {
            return new WP_REST_Response( array(
                'success'      => false,
                'message'      => __( 'Maximum site activations reached. Deactivate an existing site first.', 'bangla-track-server' ),
                'max_sites'    => $max_sites,
                'active_count' => $active_count,
            ), 200 );
        }

        $activation_id = $this->activation_repo->activate( (int) $license->id, $ctx['site'] );
        if ( ! $activation_id ) {
            return new WP_REST_Response( array( 'success' => false, 'message' => __( 'Activation failed.', 'bangla-track-server' ) ), 200 );
        }

        // Save installed plugins telemetry.
        if ( ! empty( $ctx['installed_plugins'] ) ) {
            $this->site_plugins_repo->save_plugins( 'activation', (int) $activation_id, $ctx['installed_plugins'] );
        }

        return $this->status_response( $license, (int) $activation_id, true, $ctx['site']['site_url'] );
    }

    public function license_status( WP_REST_Request $request ) {
        $ctx = $this->resolve_context( $request );
        if ( is_wp_error( $ctx ) ) { return $ctx; }

        $license = $ctx['license'];
        $activation = $this->activation_repo->get_by_license_and_site( (int) $license->id, $ctx['site']['site_url'] );
        if ( $activation ) {
            $this->activation_repo->update_last_check( (int) $activation->id );

            // Save installed plugins telemetry.
            if ( ! empty( $ctx['installed_plugins'] ) ) {
                $this->site_plugins_repo->save_plugins( 'activation', (int) $activation->id, $ctx['installed_plugins'] );
            }

            return $this->status_response( $license, (int) $activation->id, true, $ctx['site']['site_url'] );
        }

        return $this->status_response( $license, 0, false, $ctx['site']['site_url'] );
    }

    public function can_book( WP_REST_Request $request ) {
        $ctx = $this->resolve_active_context( $request );
        if ( is_wp_error( $ctx ) ) { return $ctx; }

        $status = $this->status_array( $ctx['license'], $ctx['activation_id'], true, $ctx['site']['site_url'] );
        $remaining = (int) $status['usage']['remaining'];

        return new WP_REST_Response( array(
            'success' => true,
            'allowed' => ( -1 === $remaining || $remaining > 0 ),
            'reason' => ( 0 === $remaining ? 'monthly_limit_reached' : '' ),
            'message' => ( 0 === $remaining ? __( 'Monthly booking limit reached.', 'bangla-track-server' ) : '' ),
            'entitlement' => $status,
        ), 200 );
    }

    public function report_booking( WP_REST_Request $request ) {
        $ctx = $this->resolve_active_context( $request );
        if ( is_wp_error( $ctx ) ) { return $ctx; }

        $provider = $this->sanitize_provider( $request->get_param( 'provider_slug' ) );
        if ( ! $provider ) {
            return new WP_REST_Response( array( 'success' => false, 'message' => __( 'Invalid provider.', 'bangla-track-server' ) ), 200 );
        }

        $month_key = gmdate( 'Y-m' );
        $order_ref = (string) absint( $request->get_param( 'order_id' ) );
        $consignment_id = sanitize_text_field( (string) $request->get_param( 'consignment_id' ) );

        $inserted = $this->usage_repo->insert_idempotent( (int) $ctx['license']->id, (int) $ctx['activation_id'], $month_key, $provider, $order_ref, $consignment_id );

        if ( false !== $inserted ) {
            $booking_count = $this->usage_repo->count_for_month( (int) $ctx['license']->id, (int) $ctx['activation_id'], $month_key );
            $this->activation_repo->update_booking_count( (int) $ctx['activation_id'], $booking_count );
        }

        return new WP_REST_Response( array(
            'success' => (bool) $inserted,
            'idempotent' => (bool) $inserted,
            'entitlement' => $this->status_array( $ctx['license'], (int) $ctx['activation_id'], true, $ctx['site']['site_url'] ),
        ), 200 );
    }

    public function lock_provider( WP_REST_Request $request ) {
        $ctx = $this->resolve_active_context( $request );
        if ( is_wp_error( $ctx ) ) { return $ctx; }

        $provider = $this->sanitize_provider( $request->get_param( 'provider_slug' ) );
        if ( ! $provider ) {
            return new WP_REST_Response( array( 'success' => false, 'message' => __( 'Invalid provider.', 'bangla-track-server' ) ), 200 );
        }

        if ( $this->is_multi_provider_license( $ctx['license'] ) ) {
            return new WP_REST_Response( array( 'success' => true, 'locked_provider' => '' ), 200 );
        }

        $ok = $this->provider_lock_repo->lock_provider( (int) $ctx['license']->id, (int) $ctx['activation_id'], $provider );
        $locked = $this->provider_lock_repo->get_locked_provider( (int) $ctx['license']->id, (int) $ctx['activation_id'] );

        return new WP_REST_Response( array( 'success' => (bool) $ok, 'locked_provider' => sanitize_key( (string) $locked ) ), 200 );
    }

    public function provider_can_use( WP_REST_Request $request ) {
        $ctx = $this->resolve_active_context( $request );
        if ( is_wp_error( $ctx ) ) { return $ctx; }

        $provider = $this->sanitize_provider( $request->get_param( 'provider_slug' ) );
        if ( ! $provider ) {
            return new WP_REST_Response( array( 'success' => false, 'allowed' => false, 'reason' => 'invalid_provider' ), 200 );
        }

        if ( $this->is_multi_provider_license( $ctx['license'] ) ) {
            return new WP_REST_Response( array( 'success' => true, 'allowed' => true, 'locked_provider' => '' ), 200 );
        }

        $locked = sanitize_key( (string) $this->provider_lock_repo->get_locked_provider( (int) $ctx['license']->id, (int) $ctx['activation_id'] ) );
        if ( empty( $locked ) ) {
            $this->provider_lock_repo->lock_provider( (int) $ctx['license']->id, (int) $ctx['activation_id'], $provider );
            $locked = $provider;
        }

        return new WP_REST_Response( array( 'success' => true, 'allowed' => $locked === $provider, 'locked_provider' => $locked, 'reason' => $locked === $provider ? '' : 'provider_locked' ), 200 );
    }

    public function deactivate_license( WP_REST_Request $request ) {
        $ctx = $this->resolve_context( $request );
        if ( is_wp_error( $ctx ) ) {
            return $ctx;
        }

        $this->activation_repo->deactivate( (int) $ctx['license']->id, $ctx['site']['site_url'] );

        return new WP_REST_Response( array(
            'success' => true,
            'message' => __( 'License deactivated for this site.', 'bangla-track-server' ),
        ), 200 );
    }

    private function status_response( $license, $activation_id, $active, $site_url ) {
        return new WP_REST_Response( $this->status_array( $license, $activation_id, $active, $site_url ), 200 );
    }

    private function status_array( $license, $activation_id, $active, $site_url ) {
        $month = gmdate( 'Y-m' );
        $used = $activation_id ? $this->usage_repo->count_for_month( (int) $license->id, (int) $activation_id, $month ) : 0;
        $plan_code = $this->get_plan_code( $license );
        $limit = isset( $license->monthly_booking_limit ) ? intval( $license->monthly_booking_limit ) : ( 'pro' === $plan_code ? -1 : 100 );
        $allowed_active_providers = isset( $license->allowed_active_providers ) ? intval( $license->allowed_active_providers ) : ( 'pro' === $plan_code ? -1 : 1 );
        $multi_provider = isset( $license->multi_provider ) ? (bool) $license->multi_provider : ( 'pro' === $plan_code );
        $remaining = ( -1 === $limit ) ? -1 : max( 0, $limit - $used );
        $locked = $activation_id ? sanitize_key( (string) $this->provider_lock_repo->get_locked_provider( (int) $license->id, (int) $activation_id ) ) : '';

        return array(
            'success' => true,
            'license_status' => $active && 'active' === $license->status ? 'active' : $license->status,
            'plan' => $plan_code,
            'site_url' => esc_url_raw( 'https://' . ltrim( (string) $site_url, '/' ) ),
            'features' => array(
                'monthly_limit' => $limit,
                'multi_provider' => $multi_provider,
                'allowed_active_providers' => $allowed_active_providers,
                'allowed_providers' => array( 'steadfast', 'pathao', 'redx' ),
            ),
            'usage' => array( 'month' => $month, 'used' => $used, 'remaining' => $remaining ),
            'provider' => array( 'locked_provider' => $locked ),
            'expires_at' => $license->expires_at,
            'checked_at' => gmdate( 'c' ),
            // HMAC-signed policy for the unified client's PolicyGuard.
            // The client calls PolicyGuard::validate_and_apply() with this payload.
            'signed_policy' => $this->policy_signer->sign_license_policy( $license ),
        );
    }

    private function resolve_active_context( WP_REST_Request $request ) {
        $ctx = $this->resolve_context( $request );
        if ( is_wp_error( $ctx ) ) { return $ctx; }
        $activation = $this->activation_repo->get_by_license_and_site( (int) $ctx['license']->id, $ctx['site']['site_url'] );
        if ( ! $activation || 'active' !== $activation->status ) {
            return new \WP_Error( 'inactive_activation', __( 'License activation not found for this site.', 'bangla-track-server' ), array( 'status' => 200 ) );
        }
        return array( 'license' => $ctx['license'], 'site' => $ctx['site'], 'activation_id' => (int) $activation->id );
    }

    private function resolve_context( WP_REST_Request $request ) {
        $license_key = strtoupper( sanitize_text_field( (string) $request->get_param( 'license_key' ) ) );
        $site_url = esc_url_raw( (string) $request->get_param( 'site_url' ) );
        $site_url = preg_replace( '#^https?://#', '', strtolower( $site_url ) );
        $site_url = rtrim( $site_url, '/' );

        if ( empty( $license_key ) || empty( $site_url ) ) {
            return new \WP_Error( 'missing_required', __( 'license_key and site_url are required.', 'bangla-track-server' ), array( 'status' => 200 ) );
        }

        $license = $this->license_repo->get_by_key( $license_key );
        if ( ! $license ) {
            return new \WP_Error( 'license_invalid', __( 'Invalid license key.', 'bangla-track-server' ), array( 'status' => 200 ) );
        }

        if ( ! in_array( $license->status, array( 'active' ), true ) ) {
            return new \WP_Error( 'license_inactive', __( 'License is not active.', 'bangla-track-server' ), array( 'status' => 200 ) );
        }

        if ( ! empty( $license->expires_at ) && strtotime( $license->expires_at ) < time() ) {
            $this->license_repo->update( (int) $license->id, array( 'status' => 'expired' ) );
            return new \WP_Error( 'license_expired', __( 'License has expired.', 'bangla-track-server' ), array( 'status' => 200 ) );
        }

        return array( 'license' => $license, 'site' => array(
            'site_url' => sanitize_text_field( $site_url ),
            'site_name' => sanitize_text_field( (string) $request->get_param( 'site_name' ) ),
            'wp_version' => sanitize_text_field( (string) $request->get_param( 'wp_version' ) ),
            'plugin_version' => sanitize_text_field( (string) $request->get_param( 'plugin_version' ) ),
            'php_version' => sanitize_text_field( (string) $request->get_param( 'php_version' ) ),
        ), 'installed_plugins' => $this->sanitize_installed_plugins( $request->get_param( 'installed_plugins' ) ) );
    }

    private function sanitize_provider( $provider ) {
        $provider = sanitize_key( (string) $provider );
        return in_array( $provider, array( 'steadfast', 'pathao', 'redx' ), true ) ? $provider : '';
    }

    /**
     * Get normalized plan code from license row.
     *
     * @param object $license License row.
     * @return string
     */
    private function get_plan_code( $license ) {
        if ( isset( $license->plan_code ) && ! empty( $license->plan_code ) ) {
            return sanitize_key( (string) $license->plan_code );
        }
        if ( isset( $license->plan ) && ! empty( $license->plan ) ) {
            return sanitize_key( (string) $license->plan );
        }
        return 'free';
    }

    /**
     * Check whether license can use multiple providers.
     *
     * @param object $license License row.
     * @return bool
     */
    private function is_multi_provider_license( $license ) {
        if ( isset( $license->multi_provider ) ) {
            return (bool) $license->multi_provider;
        }
        return 'pro' === $this->get_plan_code( $license );
    }

    /**
     * Sanitize installed_plugins parameter from request.
     *
     * @param mixed $raw Raw parameter value.
     * @return array Sanitized plugins array or empty array.
     */
    private function sanitize_installed_plugins( $raw ) {
        if ( ! is_array( $raw ) || empty( $raw ) ) {
            return array();
        }

        $sanitized = array();
        foreach ( $raw as $slug => $data ) {
            $slug = sanitize_key( (string) $slug );
            if ( empty( $slug ) ) {
                continue;
            }
            $data = is_array( $data ) ? $data : array();
            $sanitized[ $slug ] = array(
                'name'    => sanitize_text_field( (string) ( $data['name'] ?? $slug ) ),
                'version' => sanitize_text_field( (string) ( $data['version'] ?? '' ) ),
                'active'  => ! empty( $data['active'] ),
            );
        }

        return $sanitized;
    }
}
