<?php
namespace BanglaTrackServer\Database;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class ActivationRepository {
    public function get_all( $args = array() ) {
        global $wpdb;
        $args = wp_parse_args( $args, array( 'license_id' => 0, 'is_active' => null, 'limit' => 20, 'offset' => 0, 'orderby' => 'activated_at', 'order' => 'DESC' ) );
        $table = Installer::get_activations_table();
        $licenses = Installer::get_licenses_table();
        $where = '1=1';
        if ( $args['license_id'] > 0 ) { $where .= $wpdb->prepare( ' AND a.license_id = %d', absint( $args['license_id'] ) ); }
        if ( null !== $args['is_active'] ) { $where .= $wpdb->prepare( " AND a.status = %s", $args['is_active'] ? 'active' : 'inactive' ); }
        $orderby = sanitize_sql_orderby( 'a.' . $args['orderby'] . ' ' . $args['order'] );
        if ( ! $orderby ) { $orderby = 'a.activated_at DESC'; }
        return $wpdb->get_results( "SELECT a.*, l.license_key, l.customer_email, l.customer_name FROM {$table} a LEFT JOIN {$licenses} l ON a.license_id = l.id WHERE {$where} ORDER BY {$orderby} LIMIT " . absint( $args['limit'] ) . " OFFSET " . absint( $args['offset'] ) );
    }

    public function get_by_license_and_site( $license_id, $site_url ) {
        global $wpdb;
        $table = Installer::get_activations_table();
        return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE license_id = %d AND site_url = %s", absint( $license_id ), $this->normalize_url( $site_url ) ) );
    }

    public function get_active_count( $license_id ) {
        global $wpdb;
        $table = Installer::get_activations_table();
        return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE license_id = %d AND status = %s", absint( $license_id ), 'active' ) );
    }

    public function activate( $license_id, $site_data ) {
        global $wpdb;
        $table = Installer::get_activations_table();
        $site_url = $this->normalize_url( $site_data['site_url'] ?? '' );
        $existing = $this->get_by_license_and_site( $license_id, $site_url );
        if ( $existing ) {
            $wpdb->update( $table, array(
                'status' => 'active', 'site_name' => sanitize_text_field( $site_data['site_name'] ?? '' ),
                'wp_version' => sanitize_text_field( $site_data['wp_version'] ?? '' ), 'plugin_version' => sanitize_text_field( $site_data['plugin_version'] ?? '' ),
                'php_version' => sanitize_text_field( $site_data['php_version'] ?? '' ), 'last_seen_at' => current_time( 'mysql' ),
            ), array( 'id' => absint( $existing->id ) ) );
            return (int) $existing->id;
        }

        $ok = $wpdb->insert( $table, array(
            'license_id' => absint( $license_id ), 'site_url' => $site_url,
            'site_name' => sanitize_text_field( $site_data['site_name'] ?? '' ), 'wp_version' => sanitize_text_field( $site_data['wp_version'] ?? '' ),
            'plugin_version' => sanitize_text_field( $site_data['plugin_version'] ?? '' ), 'php_version' => sanitize_text_field( $site_data['php_version'] ?? '' ),
            'status' => 'active', 'last_seen_at' => current_time( 'mysql' ),
        ) );
        return $ok ? (int) $wpdb->insert_id : false;
    }

    public function deactivate( $license_id, $site_url ) {
        global $wpdb;
        return $wpdb->update( Installer::get_activations_table(), array( 'status' => 'inactive' ), array( 'license_id' => absint( $license_id ), 'site_url' => $this->normalize_url( $site_url ) ) ) !== false;
    }

    public function update_last_check( $activation_id ) {
        global $wpdb;
        return $wpdb->update( Installer::get_activations_table(), array( 'last_seen_at' => current_time( 'mysql' ) ), array( 'id' => absint( $activation_id ) ) ) !== false;
    }

    public function get_stats() {
        global $wpdb;
        $table = Installer::get_activations_table();
        return array( 'total' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ), 'active' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE status = 'active'" ) );
    }

    public function get_count( $is_active = null ) {
        global $wpdb;
        $table = Installer::get_activations_table();
        if ( null === $is_active ) { return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ); }
        return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE status = %s", $is_active ? 'active' : 'inactive' ) );
    }

    private function normalize_url( $url ) {
        $url = esc_url_raw( trim( (string) $url ) );
        $url = preg_replace( '#^https?://#', '', strtolower( $url ) );
        return rtrim( $url, '/' );
    }
}
