<?php
namespace BanglaTrackServer\Database;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class ProviderLockRepository {
    public function get_locked_provider( $license_id, $activation_id ) {
        global $wpdb;
        $table = Installer::get_provider_locks_table();
        return $wpdb->get_var( $wpdb->prepare( "SELECT locked_provider FROM {$table} WHERE license_id = %d AND activation_id = %d", $license_id, $activation_id ) );
    }

    public function lock_provider( $license_id, $activation_id, $provider_slug ) {
        global $wpdb;
        $table = Installer::get_provider_locks_table();
        $existing = $this->get_locked_provider( $license_id, $activation_id );
        if ( $existing ) {
            return sanitize_key( $existing ) === sanitize_key( $provider_slug );
        }

        return (bool) $wpdb->insert( $table, array(
            'license_id' => absint( $license_id ),
            'activation_id' => absint( $activation_id ),
            'locked_provider' => sanitize_key( $provider_slug ),
        ), array( '%d', '%d', '%s' ) );
    }

    public function reset_lock( $license_id, $activation_id ) {
        global $wpdb;
        $table = Installer::get_provider_locks_table();
        return (bool) $wpdb->delete( $table, array( 'license_id' => absint( $license_id ), 'activation_id' => absint( $activation_id ) ), array( '%d', '%d' ) );
    }
}
