<?php
/**
 * Free-plan policy REST controller.
 *
 * Handles the bt-server/v1/free endpoints that the free plugin's
 * BT_Remote_Policy_Client communicates with. Uses API key header
 * authentication instead of RSA signing.
 *
 * @package BanglaTrackServer\REST
 */

namespace BanglaTrackServer\REST;

use BanglaTrackServer\Database\ActivationRepository;
use BanglaTrackServer\Database\SitePluginsRepository;
use WP_REST_Controller;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class FreePolicyController
 */
class FreePolicyController extends WP_REST_Controller {

	/**
	 * REST namespace.
	 *
	 * @var string
	 */
	protected $namespace = 'bt-server/v1/free';

	/**
	 * Free-plan limits.
	 */
	const FREE_MAX_BOOKINGS         = 100;
	const FREE_MAX_ACTIVE_PROVIDERS = 1;

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
	 * Constructor.
	 */
	public function __construct() {
		$this->activation_repo   = new ActivationRepository();
		$this->site_plugins_repo = new SitePluginsRepository();
	}

	/**
	 * Register REST routes.
	 *
	 * @return void
	 */
	public function register_routes() {
		$public = array(
			'methods'             => WP_REST_Server::CREATABLE,
			'permission_callback' => array( SiteCheckinController::class, 'check_api_key' ),
		);

		register_rest_route(
			$this->namespace,
			'/site/register',
			array_merge( $public, array( 'callback' => array( $this, 'register_site' ) ) )
		);

		register_rest_route(
			$this->namespace,
			'/policy/fetch',
			array_merge( $public, array( 'callback' => array( $this, 'fetch_policy' ) ) )
		);

		register_rest_route(
			$this->namespace,
			'/action/validate',
			array_merge( $public, array( 'callback' => array( $this, 'validate_action' ) ) )
		);

		register_rest_route(
			$this->namespace,
			'/usage/report-booking',
			array_merge( $public, array( 'callback' => array( $this, 'report_booking_usage' ) ) )
		);

		register_rest_route(
			$this->namespace,
			'/site/deactivate',
			array_merge( $public, array( 'callback' => array( $this, 'deactivate_site' ) ) )
		);
	}

	/**
	 * Handle site registration.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response
	 */
	public function register_site( WP_REST_Request $request ) {
		$validated = $this->validate_base_request( $request );
		if ( is_wp_error( $validated ) ) {
			return new WP_REST_Response(
				array( 'success' => false, 'message' => $validated->get_error_message() ),
				200
			);
		}

		$site_url = sanitize_text_field( $validated['site_url_hash'] ?? '' );

		// Upsert into bt_activations as a free site.
		$site_data = array(
			'site_url'              => $site_url ?: 'hash-' . $validated['site_uuid'],
			'site_name'             => '',
			'plan_code'             => 'free',
			'wp_version'            => $validated['wp_version'],
			'plugin_version'        => $validated['plugin_version'],
			'php_version'           => $validated['php_version'],
			'active_provider_count' => $validated['active_provider_count'],
			'booking_count'         => $validated['booking_count'],
		);

		$activation_id = $this->activation_repo->checkin_free_site( $site_data );

		// Save installed plugins telemetry.
		if ( $activation_id && ! empty( $validated['installed_plugins'] ) ) {
			$this->site_plugins_repo->save_plugins( 'activation', (int) $activation_id, $validated['installed_plugins'] );
		}

		$response = $this->build_policy_response(
			'site_register',
			$validated['site_uuid'],
			true,
			'registered'
		);

		return new WP_REST_Response( $response, 200 );
	}

	/**
	 * Handle policy fetch.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response
	 */
	public function fetch_policy( WP_REST_Request $request ) {
		$validated = $this->validate_base_request( $request );
		if ( is_wp_error( $validated ) ) {
			return new WP_REST_Response(
				array( 'success' => false, 'message' => $validated->get_error_message() ),
				200
			);
		}

		$response = $this->build_policy_response(
			'policy_fetch',
			$validated['site_uuid'],
			true,
			'within_free_limit'
		);

		return new WP_REST_Response( $response, 200 );
	}

	/**
	 * Validate a specific gated action.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response
	 */
	public function validate_action( WP_REST_Request $request ) {
		$validated = $this->validate_base_request( $request );
		if ( is_wp_error( $validated ) ) {
			return new WP_REST_Response(
				array( 'success' => false, 'message' => $validated->get_error_message() ),
				200
			);
		}

		$action = sanitize_key( (string) $request->get_param( 'action' ) );
		if ( empty( $action ) ) {
			return new WP_REST_Response(
				array( 'success' => false, 'message' => __( 'Action parameter is required.', 'bangla-track-server' ) ),
				200
			);
		}

		$decision = $this->evaluate_action( $action, $validated );

		$response = $this->build_policy_response(
			$action,
			$validated['site_uuid'],
			$decision['allowed'],
			$decision['reason'],
			$decision['message']
		);

		return new WP_REST_Response( $response, 200 );
	}

	/**
	 * Handle booking usage report.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response
	 */
	public function report_booking_usage( WP_REST_Request $request ) {
		$validated = $this->validate_base_request( $request );
		if ( is_wp_error( $validated ) ) {
			return new WP_REST_Response(
				array( 'success' => false, 'message' => $validated->get_error_message() ),
				200
			);
		}

		$action = sanitize_key( (string) $request->get_param( 'action' ) );
		if ( 'booking_created' !== $action || ! $request->has_param( 'booking_count' ) ) {
			return new WP_REST_Response(
				array( 'success' => false, 'message' => __( 'A valid booking_created usage payload is required.', 'bangla-track-server' ) ),
				200
			);
		}

		$response = $this->build_policy_response(
			'booking_created',
			$validated['site_uuid'],
			true,
			'usage_recorded'
		);

		$response['success']       = true;
		$response['booking_count'] = absint( $validated['booking_count'] );

		return new WP_REST_Response( $response, 200 );
	}

	/**
	 * Handle site deactivation.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response
	 */
	public function deactivate_site( WP_REST_Request $request ) {
		$validated = $this->validate_base_request( $request );
		if ( is_wp_error( $validated ) ) {
			return new WP_REST_Response(
				array( 'success' => false, 'message' => $validated->get_error_message() ),
				200
			);
		}

		$response = $this->build_policy_response(
			'site_deactivate',
			$validated['site_uuid'],
			true,
			'deactivated'
		);

		return new WP_REST_Response( $response, 200 );
	}

	/**
	 * Build a plain (unsigned) policy response.
	 *
	 * @param string $action   Action name.
	 * @param string $site_uuid Site UUID.
	 * @param bool   $allowed  Whether action is allowed.
	 * @param string $reason   Reason code.
	 * @param string $message  Human message.
	 * @return array
	 */
	private function build_policy_response( $action, $site_uuid, $allowed, $reason, $message = '' ) {
		$payload = array(
			'allowed'    => (bool) $allowed,
			'action'     => sanitize_key( (string) $action ),
			'site_uuid'  => sanitize_text_field( (string) $site_uuid ),
			'plan'       => 'free',
			'limits'     => array(
				'max_bookings'         => self::FREE_MAX_BOOKINGS,
				'max_active_providers' => self::FREE_MAX_ACTIVE_PROVIDERS,
			),
			'reason'     => sanitize_key( (string) $reason ),
			'expires_at' => time() + HOUR_IN_SECONDS,
		);

		if ( ! empty( $message ) ) {
			$payload['message'] = sanitize_text_field( (string) $message );
		}

		return array(
			'payload'   => $payload,
			'signature' => '', // No longer signed — API key auth used instead.
		);
	}

	/**
	 * Evaluate an action against free-plan rules.
	 *
	 * @param string $action    Action name.
	 * @param array  $site_data Validated request data.
	 * @return array{allowed: bool, reason: string, message: string}
	 */
	private function evaluate_action( $action, array $site_data ) {
		// Pro-only actions are always denied.
		$pro_only_actions = array( 'pro_feature_access', 'pro_setting_save', 'pro_bulk_action' );
		if ( in_array( $action, $pro_only_actions, true ) ) {
			return array(
				'allowed' => false,
				'reason'  => 'pro_only_locked',
				'message' => __( 'This feature is available in Pro only.', 'bangla-track-server' ),
			);
		}

		$booking_count  = absint( $site_data['booking_count'] ?? 0 );
		$provider_count = absint( $site_data['active_provider_count'] ?? 0 );

		// Booking limit check.
		$booking_actions = array( 'create_booking', 'bulk_booking' );
		if ( in_array( $action, $booking_actions, true ) ) {
			if ( $booking_count >= self::FREE_MAX_BOOKINGS ) {
				return array(
					'allowed' => false,
					'reason'  => 'max_bookings_reached',
					'message' => __( 'Free booking limit reached (100/month). Upgrade to Pro for unlimited bookings.', 'bangla-track-server' ),
				);
			}
		}

		// Provider limit check.
		$provider_actions = array( 'activate_provider', 'save_provider_settings' );
		if ( in_array( $action, $provider_actions, true ) ) {
			if ( $provider_count >= self::FREE_MAX_ACTIVE_PROVIDERS ) {
				return array(
					'allowed' => false,
					'reason'  => 'max_active_providers_reached',
					'message' => __( 'Only one active provider is allowed in the free plan. Upgrade to Pro for multiple providers.', 'bangla-track-server' ),
				);
			}
		}

		return array(
			'allowed' => true,
			'reason'  => 'within_free_limit',
			'message' => '',
		);
	}

	/**
	 * Validate common request fields.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return array|\WP_Error Validated data or error.
	 */
	private function validate_base_request( WP_REST_Request $request ) {
		$site_uuid = sanitize_text_field( (string) $request->get_param( 'site_uuid' ) );
		if ( empty( $site_uuid ) ) {
			return new \WP_Error(
				'missing_site_uuid',
				__( 'site_uuid is required.', 'bangla-track-server' )
			);
		}

		$plan = sanitize_key( (string) $request->get_param( 'plan' ) );
		if ( ! empty( $plan ) && 'free' !== $plan ) {
			return new \WP_Error(
				'invalid_plan',
				__( 'Only free plan is supported by this endpoint.', 'bangla-track-server' )
			);
		}

		return array(
			'site_uuid'             => $site_uuid,
			'site_url_hash'         => sanitize_text_field( (string) $request->get_param( 'site_url_hash' ) ),
			'plugin_version'        => sanitize_text_field( (string) $request->get_param( 'plugin_version' ) ),
			'wp_version'            => sanitize_text_field( (string) $request->get_param( 'wp_version' ) ),
			'php_version'           => sanitize_text_field( (string) $request->get_param( 'php_version' ) ),
			'plan'                  => 'free',
			'active_provider_count' => absint( $request->get_param( 'active_provider_count' ) ),
			'booking_count'         => absint( $request->get_param( 'booking_count' ) ),
			'installed_plugins'     => $this->sanitize_installed_plugins( $request->get_param( 'installed_plugins' ) ),
			'action'                => sanitize_key( (string) $request->get_param( 'action' ) ),
			'timestamp'             => absint( $request->get_param( 'timestamp' ) ),
			'request_id'            => sanitize_text_field( (string) $request->get_param( 'request_id' ) ),
		);
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
