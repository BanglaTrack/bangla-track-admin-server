<?php
/**
 * Site Check-in REST controller.
 *
 * Handles the unified site registration/heartbeat endpoint that ALL
 * Bangla Track installations call on activation and periodically.
 * Both free and paid sites use this single endpoint.
 *
 * @package BanglaTrackServer\REST
 */

namespace BanglaTrackServer\REST;

use BanglaTrackServer\Database\ActivationRepository;
use BanglaTrackServer\Database\SitePluginsRepository;
use BanglaTrackServer\Database\LicenseRepository;
use WP_REST_Controller;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class SiteCheckinController
 */
class SiteCheckinController extends WP_REST_Controller {

	/**
	 * REST namespace.
	 *
	 * @var string
	 */
	protected $namespace = 'bt-server/v1';

	/**
	 * Activation repository.
	 *
	 * @var ActivationRepository
	 */
	private $activation_repo;

	/**
	 * Site plugins repository.
	 *
	 * @var SitePluginsRepository
	 */
	private $site_plugins_repo;

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
		$this->activation_repo   = new ActivationRepository();
		$this->site_plugins_repo = new SitePluginsRepository();
		$this->license_repo      = new LicenseRepository();
	}

	/**
	 * Register REST routes.
	 *
	 * @return void
	 */
	public function register_routes() {
		register_rest_route(
			$this->namespace,
			'/site/checkin',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'handle_checkin' ),
				'permission_callback' => array( $this, 'verify_api_key' ),
			)
		);

		register_rest_route(
			$this->namespace,
			'/site/deactivate',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'handle_deactivate' ),
				'permission_callback' => array( $this, 'verify_api_key' ),
			)
		);
	}

	/**
	 * Verify the X-BT-API-Key header.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return bool|\WP_Error
	 */
	public function verify_api_key( WP_REST_Request $request ) {
		return self::check_api_key( $request );
	}

	/**
	 * Static helper to verify API key — shared across controllers.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return bool|\WP_Error
	 */
	public static function check_api_key( WP_REST_Request $request ) {
		$api_key = $request->get_header( 'X-BT-API-Key' );

		if ( empty( $api_key ) ) {
			return new \WP_Error(
				'bt_missing_api_key',
				__( 'Missing API key. Include X-BT-API-Key header.', 'bangla-track-server' ),
				array( 'status' => 401 )
			);
		}

		$expected = defined( 'BT_SERVER_API_KEY' ) ? BT_SERVER_API_KEY : '';

		if ( empty( $expected ) || ! hash_equals( $expected, $api_key ) ) {
			return new \WP_Error(
				'bt_invalid_api_key',
				__( 'Invalid API key.', 'bangla-track-server' ),
				array( 'status' => 403 )
			);
		}

		return true;
	}

	/**
	 * Handle site check-in (activation heartbeat).
	 *
	 * Creates or updates an activation row with license_id = 0 for free sites.
	 * Licensed sites also call this on activation for telemetry.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response
	 */
	public function handle_checkin( WP_REST_Request $request ) {
		$site_url = esc_url_raw( (string) $request->get_param( 'site_url' ) );
		if ( empty( $site_url ) ) {
			return new WP_REST_Response(
				array( 'success' => false, 'message' => __( 'site_url is required.', 'bangla-track-server' ) ),
				400
			);
		}

		$active_providers = $request->get_param( 'active_providers' );
		if ( is_array( $active_providers ) ) {
			$active_providers = array_map( 'sanitize_text_field', $active_providers );
		} else {
			$active_providers = array();
		}

		$site_data = array(
			'site_url'              => $site_url,
			'site_name'             => sanitize_text_field( (string) $request->get_param( 'site_name' ) ),
			'wp_version'            => sanitize_text_field( (string) $request->get_param( 'wp_version' ) ),
			'plugin_version'        => sanitize_text_field( (string) $request->get_param( 'plugin_version' ) ),
			'php_version'           => sanitize_text_field( (string) $request->get_param( 'php_version' ) ),
			'wc_version'            => sanitize_text_field( (string) $request->get_param( 'wc_version' ) ),
			'active_provider_count' => absint( $request->get_param( 'active_provider_count' ) ),
			'active_providers'      => $active_providers,
			'booking_count'         => absint( $request->get_param( 'booking_count' ) ),
			'plugin_status'         => sanitize_key( (string) $request->get_param( 'plugin_status' ) ) ?: 'active',
			'plan_code'             => sanitize_key( (string) $request->get_param( 'plan_code' ) ) ?: 'free',
		);

		$license_key = strtoupper( sanitize_text_field( (string) $request->get_param( 'license_key' ) ) );
		$license = null;
		if ( ! empty( $license_key ) ) {
			$license = $this->license_repo->get_by_key( $license_key );
		}

		if ( $license ) {
			$site_data['plan_code'] = $license->plan_code;
			$activation_id = $this->activation_repo->activate( (int) $license->id, $site_data );
		} else {
			$activation_id = $this->activation_repo->checkin_free_site( $site_data );
		}

		if ( ! $activation_id ) {
			return new WP_REST_Response(
				array( 'success' => false, 'message' => __( 'Check-in failed.', 'bangla-track-server' ) ),
				500
			);
		}

		// Save installed plugins telemetry.
		$installed_plugins = $this->sanitize_installed_plugins( $request->get_param( 'installed_plugins' ) );
		if ( ! empty( $installed_plugins ) ) {
			$this->site_plugins_repo->save_plugins( 'activation', (int) $activation_id, $installed_plugins );
		}

		return new WP_REST_Response( array(
			'success'       => true,
			'activation_id' => (int) $activation_id,
			'plan_code'     => $site_data['plan_code'],
			'message'       => __( 'Site check-in successful.', 'bangla-track-server' ),
		), 200 );
	}

	/**
	 * Handle site deactivation.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response
	 */
	public function handle_deactivate( WP_REST_Request $request ) {
		$site_url = esc_url_raw( (string) $request->get_param( 'site_url' ) );
		if ( empty( $site_url ) ) {
			return new WP_REST_Response(
				array( 'success' => false, 'message' => __( 'site_url is required.', 'bangla-track-server' ) ),
				400
			);
		}

		$this->activation_repo->deactivate_free_site( $site_url );

		return new WP_REST_Response( array(
			'success' => true,
			'message' => __( 'Site deactivated.', 'bangla-track-server' ),
		), 200 );
	}

	/**
	 * Sanitize installed_plugins parameter.
	 *
	 * @param mixed $raw Raw parameter value.
	 * @return array Sanitized plugins array.
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
