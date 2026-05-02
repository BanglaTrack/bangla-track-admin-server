<?php
/**
 * Activation Repository for Bangla Track Admin Server.
 *
 * @package BanglaTrackServer\Database
 */

namespace BanglaTrackServer\Database;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class ActivationRepository
 */
class ActivationRepository {

    /**
     * Get all activations.
     *
     * @param array $args Query arguments.
     * @return array
     */
    public function get_all( $args = array() ) {
        global $wpdb;

        $defaults = array(
            'license_id' => 0,
            'is_active'  => null,
            'limit'      => 20,
            'offset'     => 0,
            'orderby'    => 'activated_at',
            'order'      => 'DESC',
        );

        $args  = wp_parse_args( $args, $defaults );
        $table = Installer::get_activations_table();
        $licenses_table = Installer::get_licenses_table();
        $where = '1=1';

        if ( $args['license_id'] > 0 ) {
            $where .= $wpdb->prepare( ' AND a.license_id = %d', $args['license_id'] );
        }

        if ( $args['is_active'] !== null ) {
            $where .= $wpdb->prepare( ' AND a.is_active = %d', $args['is_active'] );
        }

        $orderby = sanitize_sql_orderby( 'a.' . $args['orderby'] . ' ' . $args['order'] );
        
        // If sanitize_sql_orderby returns false, use default ordering
        if ( ! $orderby ) {
            $orderby = 'a.activated_at DESC';
        }
        
        $limit   = absint( $args['limit'] );
        $offset  = absint( $args['offset'] );

        return $wpdb->get_results(
            "SELECT a.*, l.license_key, l.customer_email, l.customer_name 
             FROM {$table} a 
             LEFT JOIN {$licenses_table} l ON a.license_id = l.id 
             WHERE {$where} 
             ORDER BY {$orderby} 
             LIMIT {$limit} OFFSET {$offset}"
        );
    }

    /**
     * Get activation by license ID and site URL.
     *
     * @param int    $license_id License ID.
     * @param string $site_url   Site URL.
     * @return object|null
     */
    public function get_by_license_and_site( $license_id, $site_url ) {
        global $wpdb;
        $table = Installer::get_activations_table();

        $site_url = $this->normalize_url( $site_url );

        return $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$table} WHERE license_id = %d AND site_url = %s",
            $license_id,
            $site_url
        ) );
    }

    /**
     * Get active activation count for a license.
     *
     * @param int $license_id License ID.
     * @return int
     */
    public function get_active_count( $license_id ) {
        global $wpdb;
        $table = Installer::get_activations_table();

        return (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE license_id = %d AND is_active = 1",
            $license_id
        ) );
    }

    /**
     * Activate a license on a site.
     *
     * @param int   $license_id License ID.
     * @param array $site_data  Site data.
     * @return int|false Activation ID or false on failure.
     */
    public function activate( $license_id, $site_data ) {
        global $wpdb;
        $table = Installer::get_activations_table();

        $site_url = $this->normalize_url( $site_data['site_url'] ?? '' );

        $existing = $this->get_by_license_and_site( $license_id, $site_url );

        if ( $existing ) {
            $wpdb->update(
                $table,
                array(
                    'is_active'      => 1,
                    'site_name'      => $site_data['site_name'] ?? '',
                    'wp_version'     => $site_data['wp_version'] ?? '',
                    'plugin_version' => $site_data['plugin_version'] ?? '',
                    'php_version'    => $site_data['php_version'] ?? '',
                    'last_check'     => current_time( 'mysql' ),
                ),
                array( 'id' => $existing->id )
            );
            return $existing->id;
        }

        $result = $wpdb->insert(
            $table,
            array(
                'license_id'     => $license_id,
                'site_url'       => $site_url,
                'site_name'      => $site_data['site_name'] ?? '',
                'wp_version'     => $site_data['wp_version'] ?? '',
                'plugin_version' => $site_data['plugin_version'] ?? '',
                'php_version'    => $site_data['php_version'] ?? '',
                'is_active'      => 1,
            )
        );

        return $result ? $wpdb->insert_id : false;
    }

    /**
     * Deactivate a license from a site.
     *
     * @param int    $license_id License ID.
     * @param string $site_url   Site URL.
     * @return bool
     */
    public function deactivate( $license_id, $site_url ) {
        global $wpdb;
        $table = Installer::get_activations_table();

        $site_url = $this->normalize_url( $site_url );

        return $wpdb->update(
            $table,
            array( 'is_active' => 0 ),
            array( 'license_id' => $license_id, 'site_url' => $site_url )
        ) !== false;
    }

    /**
     * Update last check time.
     *
     * @param int $activation_id Activation ID.
     * @return bool
     */
    public function update_last_check( $activation_id ) {
        global $wpdb;
        $table = Installer::get_activations_table();

        return $wpdb->update(
            $table,
            array( 'last_check' => current_time( 'mysql' ) ),
            array( 'id' => $activation_id )
        ) !== false;
    }

    /**
     * Get activation statistics.
     *
     * @return array
     */
    public function get_stats() {
        global $wpdb;
        $table = Installer::get_activations_table();

        $total  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
        $active = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE is_active = 1" );

        return compact( 'total', 'active' );
    }

    /**
     * Get total count.
     *
     * @param bool|null $is_active Filter by active status.
     * @return int
     */
    public function get_count( $is_active = null ) {
        global $wpdb;
        $table = Installer::get_activations_table();
        $where = '1=1';

        if ( $is_active !== null ) {
            $where .= $wpdb->prepare( ' AND is_active = %d', $is_active );
        }

        return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE {$where}" );
    }

    /**
     * Normalize a URL for comparison.
     *
     * @param string $url URL to normalize.
     * @return string
     */
    private function normalize_url( $url ) {
        $url = strtolower( trim( $url ) );
        $url = preg_replace( '#^https?://#', '', $url );
        $url = rtrim( $url, '/' );
        return $url;
    }
}
