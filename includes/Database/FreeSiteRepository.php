<?php
/**
 * Free site registration repository.
 *
 * Handles CRUD operations for the bt_free_sites table which tracks
 * free plugin installations that register with the server.
 *
 * @package BanglaTrackServer\Database
 */

namespace BanglaTrackServer\Database;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class FreeSiteRepository
 */
class FreeSiteRepository {

	/**
	 * Get a site by UUID.
	 *
	 * @param string $uuid Site UUID.
	 * @return object|null
	 */
	public function get_by_uuid( $uuid ) {
		global $wpdb;
		$table = Installer::get_free_sites_table();

		return $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE site_uuid = %s", sanitize_text_field( $uuid ) )
		);
	}

	/**
	 * Insert or update a free site registration.
	 *
	 * If the UUID already exists, update telemetry fields. Otherwise insert new row.
	 *
	 * @param array $data Site data.
	 * @return int|false Insert ID on new insert, row ID on update, false on failure.
	 */
	public function upsert( array $data ) {
		global $wpdb;
		$table = Installer::get_free_sites_table();

		$uuid = sanitize_text_field( (string) ( $data['site_uuid'] ?? '' ) );
		if ( empty( $uuid ) ) {
			return false;
		}

		$existing = $this->get_by_uuid( $uuid );

		$row = array(
			'site_uuid'             => $uuid,
			'site_url_hash'         => sanitize_text_field( (string) ( $data['site_url_hash'] ?? '' ) ),
			'plugin_version'        => sanitize_text_field( (string) ( $data['plugin_version'] ?? '' ) ),
			'wp_version'            => sanitize_text_field( (string) ( $data['wp_version'] ?? '' ) ),
			'php_version'           => sanitize_text_field( (string) ( $data['php_version'] ?? '' ) ),
			'active_provider_count' => absint( $data['active_provider_count'] ?? 0 ),
			'booking_count'         => absint( $data['booking_count'] ?? 0 ),
			'last_seen_at'          => current_time( 'mysql', true ),
		);

		if ( $existing ) {
			$row['status'] = 'active';

			$updated = $wpdb->update(
				$table,
				$row,
				array( 'id' => absint( $existing->id ) )
			);

			return ( false !== $updated ) ? absint( $existing->id ) : false;
		}

		$row['status']        = 'active';
		$row['registered_at'] = current_time( 'mysql', true );

		$ok = $wpdb->insert( $table, $row );

		return $ok ? $wpdb->insert_id : false;
	}

	/**
	 * Update telemetry fields for a site.
	 *
	 * @param string $uuid Site UUID.
	 * @param array  $data Telemetry data.
	 * @return bool
	 */
	public function update_telemetry( $uuid, array $data ) {
		global $wpdb;
		$table = Installer::get_free_sites_table();

		$update = array(
			'last_seen_at' => current_time( 'mysql', true ),
		);

		if ( isset( $data['plugin_version'] ) ) {
			$update['plugin_version'] = sanitize_text_field( (string) $data['plugin_version'] );
		}
		if ( isset( $data['wp_version'] ) ) {
			$update['wp_version'] = sanitize_text_field( (string) $data['wp_version'] );
		}
		if ( isset( $data['php_version'] ) ) {
			$update['php_version'] = sanitize_text_field( (string) $data['php_version'] );
		}
		if ( isset( $data['active_provider_count'] ) ) {
			$update['active_provider_count'] = absint( $data['active_provider_count'] );
		}
		if ( isset( $data['booking_count'] ) ) {
			$update['booking_count'] = absint( $data['booking_count'] );
		}
		if ( isset( $data['site_url_hash'] ) ) {
			$update['site_url_hash'] = sanitize_text_field( (string) $data['site_url_hash'] );
		}

		$result = $wpdb->update(
			$table,
			$update,
			array( 'site_uuid' => sanitize_text_field( $uuid ) )
		);

		return false !== $result;
	}

	/**
	 * Deactivate a site by UUID.
	 *
	 * @param string $uuid Site UUID.
	 * @return bool
	 */
	public function deactivate( $uuid ) {
		global $wpdb;
		$table = Installer::get_free_sites_table();

		$result = $wpdb->update(
			$table,
			array(
				'status'       => 'inactive',
				'last_seen_at' => current_time( 'mysql', true ),
			),
			array( 'site_uuid' => sanitize_text_field( $uuid ) )
		);

		return false !== $result;
	}

	/**
	 * Get all sites with pagination.
	 *
	 * @param array $args Query args (status, limit, offset, orderby, order).
	 * @return array
	 */
	public function get_all( $args = array() ) {
		global $wpdb;
		$table = Installer::get_free_sites_table();

		$args = wp_parse_args(
			$args,
			array(
				'status'  => '',
				'limit'   => 20,
				'offset'  => 0,
				'orderby' => 'last_seen_at',
				'order'   => 'DESC',
			)
		);

		$where = '1=1';
		if ( ! empty( $args['status'] ) ) {
			$where .= $wpdb->prepare( ' AND status = %s', sanitize_key( $args['status'] ) );
		}

		$limit   = absint( $args['limit'] );
		$offset  = absint( $args['offset'] );
		$orderby = sanitize_sql_orderby( $args['orderby'] . ' ' . $args['order'] );
		if ( ! $orderby ) {
			$orderby = 'last_seen_at DESC';
		}

		return $wpdb->get_results(
			"SELECT * FROM {$table} WHERE {$where} ORDER BY {$orderby} LIMIT {$limit} OFFSET {$offset}"
		);
	}

	/**
	 * Get count of sites by status.
	 *
	 * @param string $status Optional status filter.
	 * @return int
	 */
	public function get_count( $status = '' ) {
		global $wpdb;
		$table = Installer::get_free_sites_table();

		if ( empty( $status ) ) {
			return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
		}

		return (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE status = %s", sanitize_key( $status ) )
		);
	}

	/**
	 * Get summary stats.
	 *
	 * @return array{total: int, active: int, inactive: int}
	 */
	public function get_stats() {
		return array(
			'total'    => $this->get_count(),
			'active'   => $this->get_count( 'active' ),
			'inactive' => $this->get_count( 'inactive' ),
		);
	}
}
