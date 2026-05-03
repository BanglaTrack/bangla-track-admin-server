<?php
namespace BanglaTrackServer\Database;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class UsageRepository {
    public function count_for_month( $license_id, $activation_id, $month_key ) {
        global $wpdb;
        $table = Installer::get_usage_table();
        return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE license_id = %d AND activation_id = %d AND month_key = %s", $license_id, $activation_id, $month_key ) );
    }

    public function insert_idempotent( $license_id, $activation_id, $month_key, $provider_slug, $order_ref, $consignment_id ) {
        global $wpdb;
        $table = Installer::get_usage_table();

        $existing = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE license_id = %d AND activation_id = %d AND order_ref = %s AND consignment_id = %s", $license_id, $activation_id, $order_ref, $consignment_id ) );
        if ( $existing ) {
            return (int) $existing;
        }

        $ok = $wpdb->insert( $table, array(
            'license_id' => absint( $license_id ),
            'activation_id' => absint( $activation_id ),
            'month_key' => sanitize_text_field( $month_key ),
            'provider_slug' => sanitize_key( $provider_slug ),
            'order_ref' => sanitize_text_field( $order_ref ),
            'consignment_id' => sanitize_text_field( $consignment_id ),
        ), array( '%d', '%d', '%s', '%s', '%s', '%s' ) );

        return $ok ? (int) $wpdb->insert_id : false;
    }

    /**
     * Count usage records for a given activation.
     *
     * @param int    $activation_id Activation ID.
     * @param string $month_key Optional month key.
     * @return int
     */
    public function count_for_activation( $activation_id, $month_key = '' ) {
        global $wpdb;
        $table = Installer::get_usage_table();

        if ( ! empty( $month_key ) ) {
            return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE activation_id = %d AND month_key = %s", absint( $activation_id ), sanitize_text_field( $month_key ) ) );
        }

        return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE activation_id = %d", absint( $activation_id ) ) );
    }
}
