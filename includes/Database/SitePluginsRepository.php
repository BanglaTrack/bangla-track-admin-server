<?php
/**
 * Repository for site plugin telemetry.
 *
 * Handles CRUD operations for the bt_site_plugins table which tracks
 * installed WordPress plugins on each connected site (both paid activations
 * and free-plan sites).
 *
 * @package BanglaTrackServer\Database
 */

namespace BanglaTrackServer\Database;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class SitePluginsRepository
 */
class SitePluginsRepository {

	/**
	 * Valid source types.
	 */
	const SOURCE_ACTIVATION = 'activation';
	const SOURCE_FREE_SITE  = 'free_site';

	/**
	 * Save (upsert) plugins for a site.
	 *
	 * Replaces the full plugin list for the given source. Old plugins that
	 * are no longer reported by the client are removed so the data stays
	 * in sync with the actual site state.
	 *
	 * @param string $source_type 'activation' or 'free_site'.
	 * @param int    $source_id   Row ID in the source table.
	 * @param array  $plugins     Associative array of slug => {name, version, active}.
	 * @return bool True on success.
	 */
	public function save_plugins( $source_type, $source_id, array $plugins ) {
		global $wpdb;

		$source_type = $this->validate_source_type( $source_type );
		$source_id   = absint( $source_id );

		if ( empty( $source_type ) || $source_id < 1 || empty( $plugins ) ) {
			return false;
		}

		$table       = Installer::get_site_plugins_table();
		$now         = current_time( 'mysql', true );
		$seen_slugs  = array();

		foreach ( $plugins as $slug => $data ) {
			$slug = sanitize_key( (string) $slug );
			if ( empty( $slug ) || strlen( $slug ) > 191 ) {
				continue;
			}

			$data = is_array( $data ) ? $data : array();

			$plugin_name    = sanitize_text_field( (string) ( $data['name'] ?? $slug ) );
			$plugin_version = sanitize_text_field( (string) ( $data['version'] ?? '' ) );
			$is_active      = ! empty( $data['active'] ) ? 1 : 0;

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->query(
				$wpdb->prepare(
					"INSERT INTO {$table} (source_type, source_id, plugin_slug, plugin_name, plugin_version, is_active, updated_at)
					VALUES (%s, %d, %s, %s, %s, %d, %s)
					ON DUPLICATE KEY UPDATE
						plugin_name = VALUES(plugin_name),
						plugin_version = VALUES(plugin_version),
						is_active = VALUES(is_active),
						updated_at = VALUES(updated_at)",
					$source_type,
					$source_id,
					$slug,
					$plugin_name,
					$plugin_version,
					$is_active,
					$now
				)
			);

			$seen_slugs[] = $slug;
		}

		// Remove plugins that were previously recorded but are no longer installed.
		if ( ! empty( $seen_slugs ) ) {
			$placeholders = implode( ',', array_fill( 0, count( $seen_slugs ), '%s' ) );
			$query_args   = array_merge(
				array( $source_type, $source_id ),
				$seen_slugs
			);

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query(
				$wpdb->prepare(
					"DELETE FROM {$table} WHERE source_type = %s AND source_id = %d AND plugin_slug NOT IN ({$placeholders})",
					...$query_args
				)
			);
		}

		return true;
	}

	/**
	 * Get all plugins for a specific site.
	 *
	 * @param string $source_type 'activation' or 'free_site'.
	 * @param int    $source_id   Row ID in the source table.
	 * @return array Array of plugin row objects.
	 */
	public function get_plugins_for_site( $source_type, $source_id ) {
		global $wpdb;
		$table = Installer::get_site_plugins_table();

		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE source_type = %s AND source_id = %d ORDER BY is_active DESC, plugin_name ASC",
				$this->validate_source_type( $source_type ),
				absint( $source_id )
			)
		);
	}

	/**
	 * Get plugin count for a specific site.
	 *
	 * @param string $source_type 'activation' or 'free_site'.
	 * @param int    $source_id   Row ID in the source table.
	 * @return int
	 */
	public function get_count( $source_type, $source_id ) {
		global $wpdb;
		$table = Installer::get_site_plugins_table();

		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table} WHERE source_type = %s AND source_id = %d",
				$this->validate_source_type( $source_type ),
				absint( $source_id )
			)
		);
	}

	/**
	 * Get plugin counts for multiple sites in a single query.
	 *
	 * @param string $source_type 'activation' or 'free_site'.
	 * @param array  $source_ids  Array of row IDs.
	 * @return array Associative array of source_id => count.
	 */
	public function get_counts_for_sites( $source_type, array $source_ids ) {
		global $wpdb;
		$table = Installer::get_site_plugins_table();

		if ( empty( $source_ids ) ) {
			return array();
		}

		$source_type  = $this->validate_source_type( $source_type );
		$ids          = array_map( 'absint', $source_ids );
		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );

		$query_args = array_merge( array( $source_type ), $ids );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT source_id, COUNT(*) AS cnt FROM {$table} WHERE source_type = %s AND source_id IN ({$placeholders}) GROUP BY source_id",
				...$query_args
			)
		);

		$result = array();
		if ( is_array( $rows ) ) {
			foreach ( $rows as $row ) {
				$result[ (int) $row->source_id ] = (int) $row->cnt;
			}
		}

		return $result;
	}

	/**
	 * Validate source type.
	 *
	 * @param string $type Source type.
	 * @return string Validated type or empty string.
	 */
	private function validate_source_type( $type ) {
		$type = sanitize_key( (string) $type );
		return in_array( $type, array( self::SOURCE_ACTIVATION, self::SOURCE_FREE_SITE ), true ) ? $type : '';
	}
}
