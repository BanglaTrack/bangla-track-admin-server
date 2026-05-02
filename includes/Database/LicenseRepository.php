<?php
/**
 * License Repository for Bangla Track Admin Server.
 *
 * @package BanglaTrackServer\Database
 */

namespace BanglaTrackServer\Database;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class LicenseRepository
 */
class LicenseRepository {

    /**
     * Get all licenses.
     *
     * @param array $args Query arguments.
     * @return array
     */
    public function get_all( $args = array() ) {
        global $wpdb;

        $defaults = array(
            'status'  => '',
            'limit'   => 20,
            'offset'  => 0,
            'orderby' => 'created_at',
            'order'   => 'DESC',
        );

        $args  = wp_parse_args( $args, $defaults );
        $table = Installer::get_licenses_table();
        $where = '1=1';

        if ( ! empty( $args['status'] ) ) {
            $where .= $wpdb->prepare( ' AND status = %s', $args['status'] );
        }

        $orderby = sanitize_sql_orderby( $args['orderby'] . ' ' . $args['order'] );
        $limit   = absint( $args['limit'] );
        $offset  = absint( $args['offset'] );

        return $wpdb->get_results(
            "SELECT * FROM {$table} WHERE {$where} ORDER BY {$orderby} LIMIT {$limit} OFFSET {$offset}"
        );
    }

    /**
     * Get license by ID.
     *
     * @param int $id License ID.
     * @return object|null
     */
    public function get_by_id( $id ) {
        global $wpdb;
        $table = Installer::get_licenses_table();
        return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ) );
    }

    /**
     * Get license by key.
     *
     * @param string $key License key.
     * @return object|null
     */
    public function get_by_key( $key ) {
        global $wpdb;
        $table = Installer::get_licenses_table();
        return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE license_key = %s", $key ) );
    }

    /**
     * Create a new license.
     *
     * @param array $data License data.
     * @return int|false License ID or false on failure.
     */
    public function create( $data ) {
        global $wpdb;
        $table = Installer::get_licenses_table();

        $defaults = array(
            'license_key'     => $this->generate_key(),
            'customer_email'  => '',
            'customer_name'   => '',
            'status'          => 'active',
            'max_activations' => 1,
            'expires_at'      => null,
        );

        $data = wp_parse_args( $data, $defaults );

        $result = $wpdb->insert( $table, $data );

        return $result ? $wpdb->insert_id : false;
    }

    /**
     * Update a license.
     *
     * @param int   $id   License ID.
     * @param array $data License data.
     * @return bool
     */
    public function update( $id, $data ) {
        global $wpdb;
        $table = Installer::get_licenses_table();

        return $wpdb->update( $table, $data, array( 'id' => $id ) ) !== false;
    }

    /**
     * Delete a license.
     *
     * @param int $id License ID.
     * @return bool
     */
    public function delete( $id ) {
        global $wpdb;
        $table = Installer::get_licenses_table();

        return $wpdb->delete( $table, array( 'id' => $id ) ) !== false;
    }

    /**
     * Generate a unique license key.
     *
     * @return string
     */
    public function generate_key() {
        $chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
        $key   = 'BTP-';

        for ( $i = 0; $i < 3; $i++ ) {
            $segment = '';
            for ( $j = 0; $j < 4; $j++ ) {
                $segment .= $chars[ wp_rand( 0, strlen( $chars ) - 1 ) ];
            }
            $key .= $segment;
            if ( $i < 2 ) {
                $key .= '-';
            }
        }

        if ( $this->get_by_key( $key ) ) {
            return $this->generate_key();
        }

        return $key;
    }

    /**
     * Get license statistics.
     *
     * @return array
     */
    public function get_stats() {
        global $wpdb;
        $table = Installer::get_licenses_table();

        $total    = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
        $active   = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE status = 'active'" );
        $expired  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE status = 'expired'" );
        $revoked  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE status = 'revoked'" );

        return compact( 'total', 'active', 'expired', 'revoked' );
    }

    /**
     * Get total count.
     *
     * @param string $status Optional status filter.
     * @return int
     */
    public function get_count( $status = '' ) {
        global $wpdb;
        $table = Installer::get_licenses_table();
        $where = '1=1';

        if ( ! empty( $status ) ) {
            $where .= $wpdb->prepare( ' AND status = %s', $status );
        }

        return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE {$where}" );
    }
}
