<?php
/**
 * Unified Cryptographic Policy Signer.
 *
 * Generates HMAC-SHA256 signed policy payloads for the unified freemium
 * client architecture. The client's `Core\PolicyGuard` class verifies
 * these signatures using the same shared secret.
 *
 * This is SEPARATE from `Services\FreePolicySigner` which uses RSA
 * signing for the legacy free-plan endpoints. Both coexist: the RSA
 * signer serves `bt-server/v1/free/*` routes, while this HMAC signer
 * serves `bt-server/v1/license/*` routes.
 *
 * Payload Format (matches PolicyGuard::REQUIRED_FIELDS):
 * ──────────────────────────────────────────────────────
 * {
 *   "plan_code": "pro",
 *   "monthly_booking_limit": 9223372036854775807,
 *   "multi_provider_enabled": true,
 *   "expiration_timestamp": 1756396800,
 *   "hmac_signature": "a3f8c9d2..."
 * }
 *
 * @package BanglaTrackServer\Core
 * @since   1.4.0
 */

namespace BanglaTrackServer\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class PolicySigner
 *
 * HMAC-SHA256 policy payload assembly and signing.
 */
final class PolicySigner {

	/* ─── Signing Configuration ─────────────────────────────────────── */

	/**
	 * HMAC-SHA256 shared secret.
	 *
	 * MUST match the client's `BanglaTrack\Core\PolicyGuard::HMAC_KEY`.
	 * Both server and client use this same key to sign/verify payloads.
	 *
	 * @var string
	 */
	private const HMAC_KEY = 'bt_policy_hmac_k3y_2026_s3cur3_r4nd0m_pr0duct10n_v4lu3';

	/**
	 * Default policy Time-To-Live in seconds (24 hours).
	 *
	 * The `expiration_timestamp` field is set to `time() + TTL`.
	 * The client's PolicyGuard rejects expired timestamps, forcing
	 * periodic re-checks against this server.
	 *
	 * @var int
	 */
	private const DEFAULT_TTL_SECONDS = 86400;

	/* ═══════════════════════════════════════════════════════════════════
	 * PUBLIC API
	 * ═══════════════════════════════════════════════════════════════════ */

	/**
	 * Generate a signed policy payload from a license database row.
	 *
	 * This is the main entry point. Call this method when building
	 * any response that the client's PolicyGuard will verify.
	 *
	 * @param object $license License row from `bt_licenses` table.
	 * @return array<string, mixed> Signed payload with `hmac_signature` field.
	 */
	public function sign_license_policy( object $license ): array {
		$plan_code = $this->normalize_plan_code( $license );

		// Resolve the booking limit.
		// Server stores -1 for unlimited; client expects PHP_INT_MAX.
		$raw_limit = isset( $license->monthly_booking_limit )
			? (int) $license->monthly_booking_limit
			: $this->get_default_limit( $plan_code );

		$monthly_limit = ( -1 === $raw_limit ) ? PHP_INT_MAX : max( 1, $raw_limit );

		// Resolve multi-provider flag.
		$multi_provider = isset( $license->multi_provider )
			? (bool) $license->multi_provider
			: ( 'pro' === $plan_code );

		// Calculate expiration timestamp.
		$expiration = $this->calculate_expiration( $license );

		// Build the unsigned payload (the fields the client expects).
		$payload = array(
			'plan_code'              => $plan_code,
			'monthly_booking_limit'  => $monthly_limit,
			'multi_provider_enabled' => $multi_provider,
			'expiration_timestamp'   => $expiration,
		);

		// Compute and append the HMAC signature.
		$payload['hmac_signature'] = $this->compute_hmac( $payload );

		return $payload;
	}

	/**
	 * Generate a degradation (free) policy payload.
	 *
	 * Used when a license is cancelled, expired, or revoked.
	 * The signed payload forces the client to degrade to free limits.
	 *
	 * @return array<string, mixed> Signed free-tier payload.
	 */
	public function sign_free_degradation_policy(): array {
		$payload = array(
			'plan_code'              => 'free',
			'monthly_booking_limit'  => 100,
			'multi_provider_enabled' => false,
			'expiration_timestamp'   => time() + self::DEFAULT_TTL_SECONDS,
		);

		$payload['hmac_signature'] = $this->compute_hmac( $payload );

		return $payload;
	}

	/* ═══════════════════════════════════════════════════════════════════
	 * HMAC COMPUTATION
	 * ═══════════════════════════════════════════════════════════════════ */

	/**
	 * Compute HMAC-SHA256 over a payload.
	 *
	 * The signing process:
	 * 1. Sort the payload keys alphabetically (deterministic order).
	 * 2. JSON-encode the sorted array.
	 * 3. Compute HMAC-SHA256 using the shared secret.
	 *
	 * This MUST produce the same output as the client's
	 * `PolicyGuard::build_signing_string()` + `hash_hmac()`.
	 *
	 * @param array<string, mixed> $payload Unsigned payload (no hmac_signature key).
	 * @return string Hex-encoded HMAC-SHA256 signature.
	 */
	private function compute_hmac( array $payload ): string {
		$signing_string = $this->build_signing_string( $payload );

		return hash_hmac( 'sha256', $signing_string, self::HMAC_KEY );
	}

	/**
	 * Build a deterministic signing string from a payload.
	 *
	 * Keys are sorted alphabetically to ensure server and client
	 * produce identical strings regardless of PHP array key ordering.
	 *
	 * @param array<string, mixed> $payload Payload fields.
	 * @return string Deterministic JSON string.
	 */
	private function build_signing_string( array $payload ): string {
		ksort( $payload );

		$json = wp_json_encode( $payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );

		return ( false !== $json ) ? $json : '';
	}

	/* ═══════════════════════════════════════════════════════════════════
	 * HELPERS
	 * ═══════════════════════════════════════════════════════════════════ */

	/**
	 * Normalize the plan code from a license row.
	 *
	 * Handles both `plan_code` and legacy `plan` columns.
	 *
	 * @param object $license License row.
	 * @return string One of 'free', 'starter', 'pro'.
	 */
	private function normalize_plan_code( object $license ): string {
		$plan = '';

		if ( ! empty( $license->plan_code ) ) {
			$plan = sanitize_key( (string) $license->plan_code );
		} elseif ( ! empty( $license->plan ) ) {
			$plan = sanitize_key( (string) $license->plan );
		}

		return in_array( $plan, array( 'free', 'starter', 'pro' ), true ) ? $plan : 'free';
	}

	/**
	 * Get default monthly booking limit for a plan code.
	 *
	 * @param string $plan_code Plan code.
	 * @return int Limit value (-1 = unlimited).
	 */
	private function get_default_limit( string $plan_code ): int {
		return match ( $plan_code ) {
			'pro'     => -1,
			'starter' => 500,
			default   => 100,
		};
	}

	/**
	 * Calculate the expiration timestamp for a signed policy.
	 *
	 * Logic:
	 * 1. If the license has an `expires_at` date AND it's in the future,
	 *    use the MINIMUM of (license expiry, now + TTL). This prevents
	 *    a signed payload from outliving the license itself.
	 * 2. If the license is lifetime (no expiry), use now + TTL.
	 * 3. If the license has expired, use now + TTL anyway (the client
	 *    will still get a valid payload, but the status/plan will
	 *    reflect the revocation).
	 *
	 * @param object $license License row.
	 * @return int Unix timestamp.
	 */
	private function calculate_expiration( object $license ): int {
		$ttl_expiration = time() + self::DEFAULT_TTL_SECONDS;

		if ( empty( $license->expires_at ) ) {
			// Lifetime license — use TTL-based expiration only.
			return $ttl_expiration;
		}

		$license_expiry = strtotime( (string) $license->expires_at );

		if ( false === $license_expiry || $license_expiry <= 0 ) {
			return $ttl_expiration;
		}

		// If license expires before TTL window, use the license expiry
		// so the client is forced to re-check sooner.
		if ( $license_expiry > time() && $license_expiry < $ttl_expiration ) {
			return $license_expiry;
		}

		return $ttl_expiration;
	}
}
