<?php
/**
 * License REST Controller for Bangla Track Admin Server.
 *
 * @package BanglaTrackServer\REST
 */

namespace BanglaTrackServer\REST;

use BanglaTrackServer\Database\LicenseRepository;
use BanglaTrackServer\Database\ActivationRepository;
use WP_REST_Controller;
use WP_REST_Server;
use WP_REST_Request;
use WP_REST_Response;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class LicenseController
 */
class LicenseController extends WP_REST_Controller {

    /**
     * Namespace.
     *
     * @var string
     */
    protected $namespace = 'bt-server/v1';

    /**
     * License repository.
     *
     * @var LicenseRepository
     */
    private $license_repo;

    /**
     * Activation repository.
     *
     * @var ActivationRepository
     */
    private $activation_repo;

    /**
     * Constructor.
     */
    public function __construct() {
        $this->license_repo    = new LicenseRepository();
        $this->activation_repo = new ActivationRepository();
    }

    /**
     * Register REST routes.
     *
     * @return void
     */
    public function register_routes() {
        register_rest_route( $this->namespace, '/license/validate', array(
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => array( $this, 'validate_license' ),
            'permission_callback' => '__return_true',
            'args'                => array(
                'license_key' => array(
                    'required'          => true,
                    'type'              => 'string',
                    'sanitize_callback' => 'sanitize_text_field',
                ),
            ),
        ) );

        register_rest_route( $this->namespace, '/license/activate', array(
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => array( $this, 'activate_license' ),
            'permission_callback' => '__return_true',
            'args'                => array(
                'license_key' => array(
                    'required'          => true,
                    'type'              => 'string',
                    'sanitize_callback' => 'sanitize_text_field',
                ),
                'site_url' => array(
                    'required'          => true,
                    'type'              => 'string',
                    'sanitize_callback' => 'sanitize_text_field',
                ),
            ),
        ) );

        register_rest_route( $this->namespace, '/license/deactivate', array(
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => array( $this, 'deactivate_license' ),
            'permission_callback' => '__return_true',
            'args'                => array(
                'license_key' => array(
                    'required'          => true,
                    'type'              => 'string',
                    'sanitize_callback' => 'sanitize_text_field',
                ),
                'site_url' => array(
                    'required'          => true,
                    'type'              => 'string',
                    'sanitize_callback' => 'sanitize_text_field',
                ),
            ),
        ) );

        register_rest_route( $this->namespace, '/license/status', array(
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => array( $this, 'check_status' ),
            'permission_callback' => '__return_true',
            'args'                => array(
                'license_key' => array(
                    'required'          => true,
                    'type'              => 'string',
                    'sanitize_callback' => 'sanitize_text_field',
                ),
                'site_url' => array(
                    'required'          => true,
                    'type'              => 'string',
                    'sanitize_callback' => 'sanitize_text_field',
                ),
            ),
        ) );
    }

    /**
     * Validate a license key.
     *
     * @param WP_REST_Request $request Request object.
     * @return WP_REST_Response|WP_Error
     */
    public function validate_license( $request ) {
        $key = strtoupper( $request->get_param( 'license_key' ) );

        $license = $this->license_repo->get_by_key( $key );

        if ( ! $license ) {
            return new WP_REST_Response( array(
                'valid'   => false,
                'message' => __( 'Invalid license key.', 'bangla-track-server' ),
            ), 200 );
        }

        if ( 'active' !== $license->status ) {
            return new WP_REST_Response( array(
                'valid'   => false,
                'message' => sprintf( __( 'License is %s.', 'bangla-track-server' ), $license->status ),
            ), 200 );
        }

        if ( $license->expires_at && strtotime( $license->expires_at ) < time() ) {
            $this->license_repo->update( $license->id, array( 'status' => 'expired' ) );
            return new WP_REST_Response( array(
                'valid'   => false,
                'message' => __( 'License has expired.', 'bangla-track-server' ),
            ), 200 );
        }

        return new WP_REST_Response( array(
            'valid'           => true,
            'message'         => __( 'License is valid.', 'bangla-track-server' ),
            'max_activations' => (int) $license->max_activations,
            'expires_at'      => $license->expires_at,
        ), 200 );
    }

    /**
     * Activate a license on a site.
     *
     * @param WP_REST_Request $request Request object.
     * @return WP_REST_Response|WP_Error
     */
    public function activate_license( $request ) {
        $key      = strtoupper( $request->get_param( 'license_key' ) );
        $site_url = $request->get_param( 'site_url' );

        $license = $this->license_repo->get_by_key( $key );

        if ( ! $license ) {
            return new WP_REST_Response( array(
                'success' => false,
                'message' => __( 'Invalid license key.', 'bangla-track-server' ),
            ), 200 );
        }

        if ( 'active' !== $license->status ) {
            return new WP_REST_Response( array(
                'success' => false,
                'message' => sprintf( __( 'License is %s.', 'bangla-track-server' ), $license->status ),
            ), 200 );
        }

        if ( $license->expires_at && strtotime( $license->expires_at ) < time() ) {
            $this->license_repo->update( $license->id, array( 'status' => 'expired' ) );
            return new WP_REST_Response( array(
                'success' => false,
                'message' => __( 'License has expired.', 'bangla-track-server' ),
            ), 200 );
        }

        $existing = $this->activation_repo->get_by_license_and_site( $license->id, $site_url );
        $active_count = $this->activation_repo->get_active_count( $license->id );

        if ( ! $existing && $active_count >= $license->max_activations ) {
            return new WP_REST_Response( array(
                'success' => false,
                'message' => sprintf(
                    __( 'Maximum activation limit (%d) reached. Deactivate from another site first.', 'bangla-track-server' ),
                    $license->max_activations
                ),
            ), 200 );
        }

        $site_data = array(
            'site_url'       => $site_url,
            'site_name'      => $request->get_param( 'site_name' ) ?: '',
            'wp_version'     => $request->get_param( 'wp_version' ) ?: '',
            'plugin_version' => $request->get_param( 'plugin_version' ) ?: '',
            'php_version'    => $request->get_param( 'php_version' ) ?: '',
        );

        $activation_id = $this->activation_repo->activate( $license->id, $site_data );

        if ( ! $activation_id ) {
            return new WP_REST_Response( array(
                'success' => false,
                'message' => __( 'Activation failed. Please try again.', 'bangla-track-server' ),
            ), 200 );
        }

        return new WP_REST_Response( array(
            'success'    => true,
            'message'    => __( 'License activated successfully.', 'bangla-track-server' ),
            'activated'  => true,
            'expires_at' => $license->expires_at,
        ), 200 );
    }

    /**
     * Deactivate a license from a site.
     *
     * @param WP_REST_Request $request Request object.
     * @return WP_REST_Response|WP_Error
     */
    public function deactivate_license( $request ) {
        $key      = strtoupper( $request->get_param( 'license_key' ) );
        $site_url = $request->get_param( 'site_url' );

        $license = $this->license_repo->get_by_key( $key );

        if ( ! $license ) {
            return new WP_REST_Response( array(
                'success' => false,
                'message' => __( 'Invalid license key.', 'bangla-track-server' ),
            ), 200 );
        }

        $this->activation_repo->deactivate( $license->id, $site_url );

        return new WP_REST_Response( array(
            'success'     => true,
            'message'     => __( 'License deactivated.', 'bangla-track-server' ),
            'deactivated' => true,
        ), 200 );
    }

    /**
     * Check license status for a site.
     *
     * @param WP_REST_Request $request Request object.
     * @return WP_REST_Response|WP_Error
     */
    public function check_status( $request ) {
        $key      = strtoupper( $request->get_param( 'license_key' ) );
        $site_url = $request->get_param( 'site_url' );

        $license = $this->license_repo->get_by_key( $key );

        if ( ! $license ) {
            return new WP_REST_Response( array(
                'valid'  => false,
                'active' => false,
            ), 200 );
        }

        $activation = $this->activation_repo->get_by_license_and_site( $license->id, $site_url );

        if ( $activation && $activation->is_active ) {
            $this->activation_repo->update_last_check( $activation->id );
        }

        $is_valid = 'active' === $license->status;
        if ( $license->expires_at && strtotime( $license->expires_at ) < time() ) {
            $is_valid = false;
        }

        return new WP_REST_Response( array(
            'valid'      => $is_valid,
            'active'     => $activation && $activation->is_active,
            'status'     => $license->status,
            'expires_at' => $license->expires_at,
        ), 200 );
    }
}
