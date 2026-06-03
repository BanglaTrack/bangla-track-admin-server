<?php
namespace BanglaTrackServer\Database;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class ActivationRepository {
    public function get_all( $args = array() ) {
        global $wpdb;
        $args = wp_parse_args( $args, array( 'license_id' => 0, 'is_active' => null, 'plan_filter' => '', 'limit' => 20, 'offset' => 0, 'orderby' => 'activated_at', 'order' => 'DESC' ) );
        $table = Installer::get_activations_table();
        $licenses = Installer::get_licenses_table();
        $where = '1=1';
        if ( $args['license_id'] > 0 ) { $where .= $wpdb->prepare( ' AND a.license_id = %d', absint( $args['license_id'] ) ); }
        if ( null !== $args['is_active'] ) { $where .= $wpdb->prepare( " AND a.status = %s", $args['is_active'] ? 'active' : 'inactive' ); }

        // Plan filter: 'free' = license_id = 0, 'paid' = license_id > 0.
        if ( 'free' === $args['plan_filter'] ) {
            $where .= ' AND a.license_id = 0';
        } elseif ( 'paid' === $args['plan_filter'] ) {
            $where .= ' AND a.license_id > 0';
        }

        $orderby = sanitize_sql_orderby( 'a.' . $args['orderby'] . ' ' . $args['order'] );
        if ( ! $orderby ) { $orderby = 'a.activated_at DESC'; }
        return $wpdb->get_results( "SELECT a.*, l.license_key, l.customer_email, l.customer_name, l.plan_code AS license_plan_code FROM {$table} a LEFT JOIN {$licenses} l ON a.license_id = l.id WHERE {$where} ORDER BY {$orderby} LIMIT " . absint( $args['limit'] ) . " OFFSET " . absint( $args['offset'] ) );
    }

    public function get_by_license_and_site( $license_id, $site_url ) {
        global $wpdb;
        $table = Installer::get_activations_table();
        return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE license_id = %d AND site_url = %s", absint( $license_id ), $this->normalize_url( $site_url ) ) );
    }

    /**
     * Find a free activation (license_id = 0) by site URL.
     *
     * @param string $site_url Site URL.
     * @return object|null
     */
    public function get_free_by_site( $site_url ) {
        global $wpdb;
        $table = Installer::get_activations_table();
        return $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$table} WHERE license_id = 0 AND site_url = %s",
            $this->normalize_url( $site_url )
        ) );
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
                'wc_version' => sanitize_text_field( $site_data['wc_version'] ?? '' ),
                'active_provider_count' => absint( $site_data['active_provider_count'] ?? 0 ),
                'booking_count' => absint( $site_data['booking_count'] ?? 0 ),
            ), array( 'id' => absint( $existing->id ) ) );
            return (int) $existing->id;
        }

        $ok = $wpdb->insert( $table, array(
            'license_id' => absint( $license_id ), 'site_url' => $site_url,
            'site_name' => sanitize_text_field( $site_data['site_name'] ?? '' ), 'wp_version' => sanitize_text_field( $site_data['wp_version'] ?? '' ),
            'plugin_version' => sanitize_text_field( $site_data['plugin_version'] ?? '' ), 'php_version' => sanitize_text_field( $site_data['php_version'] ?? '' ),
            'wc_version' => sanitize_text_field( $site_data['wc_version'] ?? '' ),
            'active_provider_count' => absint( $site_data['active_provider_count'] ?? 0 ),
            'booking_count' => absint( $site_data['booking_count'] ?? 0 ),
            'plan_code' => sanitize_key( $site_data['plan_code'] ?? 'free' ),
            'status' => 'active', 'last_seen_at' => current_time( 'mysql' ),
        ) );
        return $ok ? (int) $wpdb->insert_id : false;
    }

    /**
     * Check-in a free site (license_id = 0). Upserts into bt_activations.
     *
     * @param array $site_data Site data from the check-in request.
     * @return int|false Activation ID or false on failure.
     */
    public function checkin_free_site( array $site_data ) {
        global $wpdb;
        $table    = Installer::get_activations_table();
        $site_url = $this->normalize_url( $site_data['site_url'] ?? '' );

        if ( empty( $site_url ) ) {
            return false;
        }

        $existing = $this->get_free_by_site( $site_url );

        $row = array(
            'site_name'             => sanitize_text_field( $site_data['site_name'] ?? '' ),
            'plan_code'             => sanitize_key( $site_data['plan_code'] ?? 'free' ),
            'wp_version'            => sanitize_text_field( $site_data['wp_version'] ?? '' ),
            'wc_version'            => sanitize_text_field( $site_data['wc_version'] ?? '' ),
            'plugin_version'        => sanitize_text_field( $site_data['plugin_version'] ?? '' ),
            'php_version'           => sanitize_text_field( $site_data['php_version'] ?? '' ),
            'active_provider_count' => absint( $site_data['active_provider_count'] ?? 0 ),
            'booking_count'         => absint( $site_data['booking_count'] ?? 0 ),
            'status'                => 'active',
            'last_seen_at'          => current_time( 'mysql' ),
        );

        if ( $existing ) {
            $wpdb->update( $table, $row, array( 'id' => absint( $existing->id ) ) );
            return (int) $existing->id;
        }

        $row['license_id'] = 0;
        $row['site_url']   = $site_url;
        $ok = $wpdb->insert( $table, $row );

        return $ok ? (int) $wpdb->insert_id : false;
    }

    /**
     * Deactivate a free site by URL.
     *
     * @param string $site_url Site URL.
     * @return bool
     */
    public function deactivate_free_site( $site_url ) {
        global $wpdb;
        $table = Installer::get_activations_table();
        return $wpdb->update(
            $table,
            array( 'status' => 'inactive' ),
            array( 'license_id' => 0, 'site_url' => $this->normalize_url( $site_url ) )
        ) !== false;
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
        return array(
            'total'  => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ),
            'active' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE status = 'active'" ),
            'free'   => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE license_id = 0 AND status = 'active'" ),
            'paid'   => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE license_id > 0 AND status = 'active'" ),
        );
    }

    public function get_count( $is_active = null, $plan_filter = '' ) {
        global $wpdb;
        $table = Installer::get_activations_table();
        $where = '1=1';

        if ( null !== $is_active ) {
            $where .= $wpdb->prepare( " AND status = %s", $is_active ? 'active' : 'inactive' );
        }
        if ( 'free' === $plan_filter ) {
            $where .= ' AND license_id = 0';
        } elseif ( 'paid' === $plan_filter ) {
            $where .= ' AND license_id > 0';
        }

        return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE {$where}" );
    }

    /**
     * Deactivate ALL active activations for a given license.
     *
     * @param int $license_id License ID.
     * @return int Number of rows updated.
     */
    public function deactivate_all_for_license( int $license_id ): int {
        global $wpdb;
        $table = Installer::get_activations_table();

        $updated = $wpdb->query(
            $wpdb->prepare(
                "UPDATE {$table} SET status = 'inactive' WHERE license_id = %d AND status = 'active'",
                $license_id
            )
        );

        return ( false !== $updated ) ? (int) $updated : 0;
    }

    private function normalize_url( $url ) {
        $url = esc_url_raw( trim( (string) $url ) );
        $url = preg_replace( '#^https?://#', '', strtolower( $url ) );
        return rtrim( $url, '/' );
    }
}
