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

    public function create( $data ) {
        global $wpdb;
        $table = Installer::get_licenses_table();
        $data = wp_parse_args( $data, array( 'license_key' => $this->generate_key(), 'customer_email' => '', 'customer_name' => '', 'plan' => 'free', 'status' => 'active', 'max_activations' => 1, 'expires_at' => null ) );
        $ok = $wpdb->insert( $table, array(
            'license_key' => strtoupper( sanitize_text_field( $data['license_key'] ) ),
            'customer_email' => sanitize_email( $data['customer_email'] ),
            'customer_name' => sanitize_text_field( $data['customer_name'] ),
            'plan' => in_array( $data['plan'], array( 'free', 'pro' ), true ) ? $data['plan'] : 'free',
            'status' => in_array( $data['status'], array( 'active', 'expired', 'disabled', 'cancelled' ), true ) ? $data['status'] : 'active',
            'max_activations' => absint( $data['max_activations'] ),
            'expires_at' => ! empty( $data['expires_at'] ) ? sanitize_text_field( $data['expires_at'] ) : null,
        ) );
        return $ok ? $wpdb->insert_id : false;
    }

    public function update( $id, $data ) { global $wpdb; $table = Installer::get_licenses_table(); return $wpdb->update( $table, $data, array( 'id' => absint( $id ) ) ) !== false; }
    public function delete( $id ) { global $wpdb; $table = Installer::get_licenses_table(); return $wpdb->delete( $table, array( 'id' => absint( $id ) ) ) !== false; }

    public function generate_key() {
        $chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
        do {
            $key = 'BTP-';
            for ( $i = 0; $i < 3; $i++ ) {
                for ( $j = 0; $j < 4; $j++ ) { $key .= $chars[ wp_rand( 0, strlen( $chars ) - 1 ) ]; }
                if ( $i < 2 ) { $key .= '-'; }
            }
        } while ( $this->get_by_key( $key ) );
        return $key;
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
