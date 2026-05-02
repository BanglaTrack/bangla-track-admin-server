<?php
/**
 * Database Installer for Bangla Track Admin Server.
 *
 * @package BanglaTrackServer\Database
 */

namespace BanglaTrackServer\Database;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class Installer
 */
class Installer {

    /**
     * Activate the plugin - create database tables.
     *
     * @return void
     */
    public static function activate() {
        self::create_tables();
        update_option( 'bt_server_db_version', BT_SERVER_VERSION );
    }

    /**
     * Create database tables.
     *
     * @return void
     */
    private static function create_tables() {
        global $wpdb;

        $charset_collate = $wpdb->get_charset_collate();

        $licenses_table = $wpdb->prefix . 'bt_licenses';
        $activations_table = $wpdb->prefix . 'bt_activations';

        $sql = "CREATE TABLE {$licenses_table} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            license_key VARCHAR(25) NOT NULL,
            customer_email VARCHAR(255) DEFAULT '',
            customer_name VARCHAR(255) DEFAULT '',
            status ENUM('active', 'expired', 'revoked') DEFAULT 'active',
            max_activations INT(11) DEFAULT 1,
            expires_at DATETIME DEFAULT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY license_key (license_key),
            KEY status (status)
        ) {$charset_collate};

        CREATE TABLE {$activations_table} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            license_id BIGINT(20) UNSIGNED NOT NULL,
            site_url VARCHAR(255) NOT NULL,
            site_name VARCHAR(255) DEFAULT '',
            wp_version VARCHAR(20) DEFAULT '',
            plugin_version VARCHAR(20) DEFAULT '',
            php_version VARCHAR(20) DEFAULT '',
            activated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            last_check DATETIME DEFAULT CURRENT_TIMESTAMP,
            is_active TINYINT(1) DEFAULT 1,
            PRIMARY KEY (id),
            KEY license_id (license_id),
            KEY site_url (site_url),
            KEY is_active (is_active)
        ) {$charset_collate};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql );
    }

    /**
     * Get licenses table name.
     *
     * @return string
     */
    public static function get_licenses_table() {
        global $wpdb;
        return $wpdb->prefix . 'bt_licenses';
    }

    /**
     * Get activations table name.
     *
     * @return string
     */
    public static function get_activations_table() {
        global $wpdb;
        return $wpdb->prefix . 'bt_activations';
    }
}
