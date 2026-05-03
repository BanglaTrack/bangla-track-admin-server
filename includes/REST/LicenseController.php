<?php
namespace BanglaTrackServer\REST;

use BanglaTrackServer\Database\ActivationRepository;
use BanglaTrackServer\Database\LicenseRepository;
use BanglaTrackServer\Database\ProviderLockRepository;
use BanglaTrackServer\Database\UsageRepository;
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

    public function __construct() {
        $this->license_repo = new LicenseRepository();
        $this->activation_repo = new ActivationRepository();
        $this->usage_repo = new UsageRepository();
        $this->provider_lock_repo = new ProviderLockRepository();
    }

    public function register_routes() {
        $public = array( 'methods' => WP_REST_Server::CREATABLE, 'permission_callback' => '__return_true' );
        register_rest_route( $this->namespace, '/license/activate', array_merge( $public, array( 'callback' => array( $this, 'activate_license' ) ) ) );
        register_rest_route( $this->namespace, '/license/deactivate', array_merge( $public, array( 'callback' => array( $this, 'deactivate_license' ) ) ) );
        register_rest_route( $this->namespace, '/license/status', array_merge( $public, array( 'callback' => array( $this, 'license_status' ) ) ) );
        register_rest_route( $this->namespace, '/usage/can-book', array_merge( $public, array( 'callback' => array( $this, 'can_book' ) ) ) );
        register_rest_route( $this->namespace, '/usage/report-booking', array_merge( $public, array( 'callback' => array( $this, 'report_booking' ) ) ) );
        register_rest_route( $this->namespace, '/provider/lock', array_merge( $public, array( 'callback' => array( $this, 'lock_provider' ) ) ) );
        register_rest_route( $this->namespace, '/provider/can-use', array_merge( $public, array( 'callback' => array( $this, 'provider_can_use' ) ) ) );
    }

    public function activate_license( WP_REST_Request $request ) {
        $ctx = $this->resolve_context( $request );
        if ( is_wp_error( $ctx ) ) { return $ctx; }

        $license = $ctx['license'];
        $activation_id = $this->activation_repo->activate( (int) $license->id, $ctx['site'] );
        if ( ! $activation_id ) {
            return new WP_REST_Response( array( 'success' => false, 'message' => __( 'Activation failed.', 'bangla-track-server' ) ), 200 );
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

        if ( 'pro' === $ctx['license']->plan ) {
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

        if ( 'pro' === $ctx['license']->plan ) {
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
        $limit = ( 'pro' === $license->plan ) ? -1 : 100;
        $remaining = ( -1 === $limit ) ? -1 : max( 0, $limit - $used );
        $locked = $activation_id ? sanitize_key( (string) $this->provider_lock_repo->get_locked_provider( (int) $license->id, (int) $activation_id ) ) : '';

        return array(
            'success' => true,
            'license_status' => $active && 'active' === $license->status ? 'active' : $license->status,
            'plan' => sanitize_key( $license->plan ),
            'site_url' => esc_url_raw( 'https://' . ltrim( (string) $site_url, '/' ) ),
            'features' => array(
                'monthly_limit' => $limit,
                'multi_provider' => 'pro' === $license->plan,
                'allowed_active_providers' => 'pro' === $license->plan ? -1 : 1,
                'allowed_providers' => array( 'steadfast', 'pathao', 'redx' ),
            ),
            'usage' => array( 'month' => $month, 'used' => $used, 'remaining' => $remaining ),
            'provider' => array( 'locked_provider' => $locked ),
            'expires_at' => $license->expires_at,
            'checked_at' => gmdate( 'c' ),
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
        ) );
    }

    private function sanitize_provider( $provider ) {
        $provider = sanitize_key( (string) $provider );
        return in_array( $provider, array( 'steadfast', 'pathao', 'redx' ), true ) ? $provider : '';
    }
}
