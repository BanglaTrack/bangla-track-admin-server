<?php
namespace BanglaTrackServer\Database;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class LicenseRepository {
    public function get_all( $args = array() ) {
        global $wpdb;
        $args = wp_parse_args( $args, array( 'status' => '', 'limit' => 20, 'offset' => 0, 'orderby' => 'created_at', 'order' => 'DESC' ) );
        $table = Installer::get_licenses_table();
        $where = '1=1';
        if ( ! empty( $args['status'] ) ) {
            $where .= $wpdb->prepare( ' AND status = %s', sanitize_text_field( $args['status'] ) );
        }
        $limit = absint( $args['limit'] );
        $offset = absint( $args['offset'] );
        $orderby = sanitize_sql_orderby( $args['orderby'] . ' ' . $args['order'] );
        if ( ! $orderby ) { $orderby = 'created_at DESC'; }
        return $wpdb->get_results( "SELECT * FROM {$table} WHERE {$where} ORDER BY {$orderby} LIMIT {$limit} OFFSET {$offset}" );
    }

    public function get_by_id( $id ) { global $wpdb; $table = Installer::get_licenses_table(); return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", absint( $id ) ) ); }
    public function get_by_key( $key ) { global $wpdb; $table = Installer::get_licenses_table(); return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE license_key = %s", strtoupper( sanitize_text_field( $key ) ) ) ); }
    public function get_by_entitlement_id( $entitlement_id ) { global $wpdb; $table = Installer::get_licenses_table(); return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE entitlement_id = %d", absint( $entitlement_id ) ) ); }

    public function create( $data ) {
        global $wpdb;
        $table = Installer::get_licenses_table();
        $plan_code = sanitize_key( (string) ( $data['plan_code'] ?? $data['plan'] ?? 'free' ) );
        if ( ! in_array( $plan_code, array( 'free', 'starter', 'pro' ), true ) ) {
            $plan_code = 'free';
        }

        $license_key = ! empty( $data['license_key'] ) ? strtoupper( sanitize_text_field( $data['license_key'] ) ) : $this->generate_key( $plan_code );
        $max_sites   = absint( $data['max_sites'] ?? $data['max_activations'] ?? 1 );
        $status      = sanitize_key( (string) ( $data['status'] ?? 'active' ) );
        if ( ! in_array( $status, array( 'active', 'expired', 'disabled', 'cancelled' ), true ) ) {
            $status = 'active';
        }
        $legacy_plan = ( 'pro' === $plan_code ) ? 'pro' : 'free';

        $payload = array(
            'license_key'               => $license_key,
            'user_id'                   => absint( $data['user_id'] ?? 0 ),
            'entitlement_id'            => ! empty( $data['entitlement_id'] ) ? absint( $data['entitlement_id'] ) : null,
            'order_id'                  => absint( $data['order_id'] ?? 0 ),
            'order_item_id'             => absint( $data['order_item_id'] ?? 0 ),
            'product_id'                => absint( $data['product_id'] ?? 0 ),
            'product_code'              => sanitize_key( (string) ( $data['product_code'] ?? '' ) ),
            'plan_code'                 => $plan_code,
            'status'                    => $status,
            'is_lifetime'               => ! empty( $data['is_lifetime'] ) ? 1 : 0,
            'duration_days'             => absint( $data['duration_days'] ?? 0 ),
            'starts_at'                 => ! empty( $data['starts_at'] ) ? sanitize_text_field( (string) $data['starts_at'] ) : current_time( 'mysql' ),
            'expires_at'                => ! empty( $data['expires_at'] ) ? sanitize_text_field( (string) $data['expires_at'] ) : null,
            'monthly_booking_limit'     => isset( $data['monthly_booking_limit'] ) ? intval( $data['monthly_booking_limit'] ) : 100,
            'allowed_active_providers'  => isset( $data['allowed_active_providers'] ) ? intval( $data['allowed_active_providers'] ) : 1,
            'multi_provider'            => ! empty( $data['multi_provider'] ) ? 1 : 0,
            'max_sites'                 => $max_sites,
            // Legacy fields kept for backward compatibility.
            'customer_email'            => sanitize_email( $data['customer_email'] ?? '' ),
            'customer_name'             => sanitize_text_field( (string) ( $data['customer_name'] ?? '' ) ),
            'plan'                      => $legacy_plan,
            'max_activations'           => $max_sites,
        );

        $ok = $wpdb->insert( $table, $payload );
        return $ok ? $wpdb->insert_id : false;
    }

    public function update( $id, $data ) { global $wpdb; $table = Installer::get_licenses_table(); return $wpdb->update( $table, $data, array( 'id' => absint( $id ) ) ) !== false; }
    public function delete( $id ) { global $wpdb; $table = Installer::get_licenses_table(); return $wpdb->delete( $table, array( 'id' => absint( $id ) ) ) !== false; }

    public function generate_key( $plan_code = 'pro' ) {
        $chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
        $prefix = $this->get_key_prefix( $plan_code );

        do {
            $key = $prefix . '-';
            for ( $i = 0; $i < 3; $i++ ) {
                for ( $j = 0; $j < 4; $j++ ) { $key .= $chars[ wp_rand( 0, strlen( $chars ) - 1 ) ]; }
                if ( $i < 2 ) { $key .= '-'; }
            }
        } while ( $this->get_by_key( $key ) );
        return $key;
    }

    /**
     * Get key prefix by plan code.
     *
     * @param string $plan_code Plan code.
     * @return string
     */
    private function get_key_prefix( $plan_code ) {
        $plan_code = sanitize_key( (string) $plan_code );
        if ( 'free' === $plan_code ) {
            return 'BTF';
        }
        if ( 'starter' === $plan_code ) {
            return 'BTS';
        }
        return 'BTP';
    }

    public function get_stats() {
        global $wpdb;
        $table = Installer::get_licenses_table();
        return array(
            'total' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ),
            'active' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE status = 'active'" ),
            'expired' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE status = 'expired'" ),
            'revoked' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE status IN ('disabled','cancelled')" ),
        );
    }

    public function get_count( $status = '' ) {
        global $wpdb;
        $table = Installer::get_licenses_table();
        if ( empty( $status ) ) { return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ); }
        return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE status = %s", $status ) );
    }
}
