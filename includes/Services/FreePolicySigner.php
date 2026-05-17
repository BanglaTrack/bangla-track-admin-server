<?php
/**
 * Free-policy response signer.
 *
 * Signs policy response payloads with the server's RSA private key so that
 * the free plugin client can verify authenticity using the matching public key.
 *
 * @package BanglaTrackServer\Services
 */

namespace BanglaTrackServer\Services;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class FreePolicySigner
 */
class FreePolicySigner {

	/**
	 * Default policy TTL in seconds (1 hour).
	 */
	const DEFAULT_TTL = HOUR_IN_SECONDS;

	/**
	 * Free-plan limits.
	 */
	const FREE_MAX_BOOKINGS         = 100;
	const FREE_MAX_ACTIVE_PROVIDERS = 1;

	/**
	 * Sign a policy payload array with the configured private key.
	 *
	 * @param array $payload Policy payload.
	 * @return string Base64-encoded signature, or empty string on failure.
	 */
	public function sign( array $payload ) {
		$private_key = $this->get_private_key();
		if ( empty( $private_key ) ) {
			return '';
		}

		if ( ! function_exists( 'openssl_sign' ) ) {
			return '';
		}

		$message = wp_json_encode( $payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		if ( false === $message ) {
			return '';
		}

		$signature = '';
		$ok = openssl_sign( $message, $signature, $private_key, OPENSSL_ALGO_SHA256 );

		if ( ! $ok || empty( $signature ) ) {
			return '';
		}

		return base64_encode( $signature );
	}

	/**
	 * Build a complete signed response (payload + signature).
	 *
	 * @param array $payload Policy payload.
	 * @return array{payload: array, signature: string}
	 */
	public function build_signed_response( array $payload ) {
		return array(
			'payload'   => $payload,
			'signature' => $this->sign( $payload ),
		);
	}

	/**
	 * Build a standard free-plan policy payload.
	 *
	 * @param string $action    Action name.
	 * @param string $site_uuid Site UUID.
	 * @param bool   $allowed   Whether the action is allowed.
	 * @param string $reason    Reason code.
	 * @param string $message   Human-readable message.
	 * @return array
	 */
	public function build_policy_payload( $action, $site_uuid, $allowed, $reason, $message = '' ) {
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
			'expires_at' => time() + self::DEFAULT_TTL,
		);

		if ( ! empty( $message ) ) {
			$payload['message'] = sanitize_text_field( (string) $message );
		}

		return $payload;
	}

	/**
	 * Check whether the signing key is configured.
	 *
	 * @return bool
	 */
	public function is_configured() {
		return ! empty( $this->get_private_key() );
	}

	/**
	 * Get the RSA private key for signing.
	 *
	 * @return string
	 */
	private function get_private_key() {
		$key = defined( 'BT_SERVER_FREE_POLICY_PRIVATE_KEY' ) ? (string) BT_SERVER_FREE_POLICY_PRIVATE_KEY : '';
		$key = (string) apply_filters( 'bt_server_free_policy_private_key', $key );
		$key = trim( str_replace( '\\n', "\n", $key ) );

		return $key;
	}
}
