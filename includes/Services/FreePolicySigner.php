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
		if ( empty( $key ) ) {
			$key = "-----BEGIN PRIVATE KEY-----\n" .
				"MIIEvgIBADANBgkqhkiG9w0BAQEFAASCBKgwggSkAgEAAoIBAQDV8NUnAGQUSpiG\n" .
				"lGG3LnFtKTS9T1rxi3vQXEWpzsrAGW4xyAWD/ZDHAugqPGCYLEDoU4mq7wU4hruY\n" .
				"Hh80GeOyt879mQDOXNe8I9MHc+ckJ8GleBhRVTyvabs4RKn9bRWtRAXmBPsCbyrE\n" .
				"+NvMwFoilNpPuTkwBFy4ympcTdD2e4RG8rX1i2P83cb8uFO+gqXOLHxXXPxUguso\n" .
				"VE10DRCQdaSn5RyE5nRFlez2shXGexoQr6LLkn6zaUjsi/2UKcRpbjmM1l0owWWh\n" .
				"3O/KCcgpA1BJgKWV4Gaiy8FPX0y1H42mdM1rDCVgvI6JcAObxTPCsKMh/W8NFvCI\n" .
				"zhwKgV65AgMBAAECggEAGq+Veu2wzhom7+SK0sVjhx0Ec9e17Zq7OTiFRQLjJfaW\n" .
				"GPYNABONL1HEwV3yI5EjkIc5DR4O7efadwVM2ZwgG58TXe0rnVIV4+UpEc0qiAdA\n" .
				"f6IdEGocLXo4DPL9rDui4E+zl6dH4xwYPuUWz7GvnJAqMhcV09A7e55eCYD4X4I6\n" .
				"yh9R7tx1Xp8S4Z2JWAIcLuB0c9xGIig4IRoQ2cUzhKYPLEnGTc0IdxsQdaLAV+kA\n" .
				"emdwVB1627r93Toh5+vayRsD20u0b8dkEKsIy480uvret+7ZzwiChRTmxMpu2lUe\n" .
				"hyGozAJf65lrbJ+O0pU6WPjin2yTiybgMm9tkY4+AQKBgQDxoSPgRUSBLVpNQ8xG\n" .
				"wVcid6fknxX6dhbAaNnuEKo/1YT8q16aUyTXUeqntjA87e/JB2VkFuoVAHAclv24\n" .
				"4kVuQirG+ific3o/nFjslCx01KYK+P6aOp1j1Ugj9xV/f8U5+lzYSt/btC+RYLK9\n" .
				"tVU2VmIhoKD8QwXsFg0I8QE7AQKBgQDiqiBJJxHKV69VV8xaOnLqTB/izDny4OSt\n" .
				"a1l2BuaLbsj8BwyZvdhvH9raYUvV5amgRMD213y4W7FS2JDrDySFGONLCuLJC6MU\n" .
				"08ZnSxC+usWsAXDeKdPQb6GiuaasH2S2Y004aRg5a/94rvvIB3Vi42bX3XB92jRc\n" .
				"tdg9joS7uQKBgGTaFzlScAdiwKAjPi4CILZYLxFzfR7vDwv8N4nwFr9SBZHYjUHo\n" .
				"liXxIPojRvsHbOABYEZocgeWCTVFqFz0xHoC0AwA+YjBHjNStKL6LZwN7cgCqXIC\n" .
				"KRM0QEoSpTx0PgO3Be2ZQtpW2MvbCn+4IfruD0Nt4gOojd4+Te5/eT4BAoGBAMCl\n" .
				"Yb1q9Gbqsb2yWqARb1wNiUhE1bfFRvbR934mDUpSxYXXI/GDdnG0PFlBOqg4gzwR\n" .
				"U4Q4z+sNG6BTKpBuFVb+OSitvuSq/FeWStm95iSFL76qlthr6ngMeO+KJMvD/uA5\n" .
				"dAdO42TikoZrCtoO5MlAh0dPEO4WSEzHzVs8RzoZAoGBAMCdie6K6QXGYbImns8s\n" .
				"/X4r8zm8vvXSrtt2SstIexWdeNGFCUYqlI7PvMRwFM8HSKccibdLLCIZrpe0HBnP\n" .
				"E3yFV8lk6bevDpBkGqSNMVNkN/bJs9E7BeSu17c97gRFbnHeFkOyzpphD93MfVxr\n" .
				"Od/Ta6zjGRV+KM+agS0MDQIc\n" .
				"-----END PRIVATE KEY-----";
		}
		$key = trim( str_replace( '\\n', "\n", $key ) );

		return $key;
	}
}
