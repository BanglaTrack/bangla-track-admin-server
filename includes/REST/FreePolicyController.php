<?php
/**
 * Free-plan policy REST controller.
 *
 * Handles the bt-server/v1/free endpoints that the free plugin's
 * BT_Remote_Policy_Client communicates with. All responses are signed
 * with the server's RSA private key.
 *
 * @package BanglaTrackServer\REST
 */

namespace BanglaTrackServer\REST;

use BanglaTrackServer\Database\FreeSiteRepository;
use BanglaTrackServer\Database\SitePluginsRepository;
use BanglaTrackServer\Services\FreePolicySigner;
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
	 * Free site repository.
	 *
	 * @var FreeSiteRepository
	 */
	private $site_repo;

	/**
	 * Policy signer service.
	 *
	 * @var FreePolicySigner
	 */
	private $signer;

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
		$this->site_repo         = new FreeSiteRepository();
		$this->signer            = new FreePolicySigner();
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
			'permission_callback' => '__return_true',
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
			'/site/deactivate',
			array_merge( $public, array( 'callback' => array( $this, 'deactivate_site' ) ) )
		);
	}

	/**
	 * Handle site registration.
	 *
	 * Called when the free plugin is activated. Registers the site UUID and
	 * returns an initial signed policy.
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

		$site_uuid = $validated['site_uuid'];

		$site_id = $this->site_repo->upsert( $validated );

		// Save installed plugins telemetry.
		if ( $site_id && ! empty( $validated['installed_plugins'] ) ) {
			$this->site_plugins_repo->save_plugins( 'free_site', (int) $site_id, $validated['installed_plugins'] );
		}

		$payload  = $this->signer->build_policy_payload(
			'site_register',
			$site_uuid,
			true,
			'registered'
		);
		$response = $this->signer->build_signed_response( $payload );

		return new WP_REST_Response( $response, 200 );
	}

	/**
	 * Handle policy fetch.
	 *
	 * Returns the current signed free-plan policy for the requesting site.
	 * Called by the daily cron sync and the manual "Refresh Policy" button.
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

		$site_uuid = $validated['site_uuid'];

		// Update telemetry and last_seen.
		$site = $this->site_repo->get_by_uuid( $site_uuid );
		if ( $site ) {
			$this->site_repo->update_telemetry( $site_uuid, $validated );

			// Save installed plugins telemetry.
			if ( ! empty( $validated['installed_plugins'] ) ) {
				$this->site_plugins_repo->save_plugins( 'free_site', (int) $site->id, $validated['installed_plugins'] );
			}
		} else {
			// Auto-register if not found (handles edge case of DB reset).
			$site_id = $this->site_repo->upsert( $validated );

			if ( $site_id && ! empty( $validated['installed_plugins'] ) ) {
				$this->site_plugins_repo->save_plugins( 'free_site', (int) $site_id, $validated['installed_plugins'] );
			}
		}

		$payload  = $this->signer->build_policy_payload(
			'policy_fetch',
			$site_uuid,
			true,
			'within_free_limit'
		);
		$response = $this->signer->build_signed_response( $payload );

		return new WP_REST_Response( $response, 200 );
	}

	/**
	 * Validate a specific gated action.
	 *
	 * This is the primary enforcement endpoint. The free plugin calls this
	 * for every controlled action (create_booking, activate_provider, etc.).
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

		$site_uuid = $validated['site_uuid'];
		$action    = sanitize_key( (string) $request->get_param( 'action' ) );

		if ( empty( $action ) ) {
			return new WP_REST_Response(
				array( 'success' => false, 'message' => __( 'Action parameter is required.', 'bangla-track-server' ) ),
				200
			);
		}

		// Update telemetry.
		$site = $this->site_repo->get_by_uuid( $site_uuid );
		if ( $site ) {
			$this->site_repo->update_telemetry( $site_uuid, $validated );
		} else {
			$this->site_repo->upsert( $validated );
		}

		// Evaluate the action.
		$decision = $this->evaluate_action( $action, $validated );

		$payload  = $this->signer->build_policy_payload(
			$action,
			$site_uuid,
			$decision['allowed'],
			$decision['reason'],
			$decision['message']
		);
		$response = $this->signer->build_signed_response( $payload );

		return new WP_REST_Response( $response, 200 );
	}

	/**
	 * Handle site deactivation.
	 *
	 * Called when the free plugin is deactivated. Best-effort: the client
	 * never blocks deactivation on errors.
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

		$site_uuid = $validated['site_uuid'];

		$this->site_repo->deactivate( $site_uuid );

		$payload  = $this->signer->build_policy_payload(
			'site_deactivate',
			$site_uuid,
			true,
			'deactivated'
		);
		$response = $this->signer->build_signed_response( $payload );

		return new WP_REST_Response( $response, 200 );
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
			if ( $booking_count >= FreePolicySigner::FREE_MAX_BOOKINGS ) {
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
			if ( $provider_count >= FreePolicySigner::FREE_MAX_ACTIVE_PROVIDERS ) {
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
