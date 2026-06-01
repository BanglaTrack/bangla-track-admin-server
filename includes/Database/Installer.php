<?php
namespace BanglaTrackServer\Database;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Installer {
    public static function activate() {
        self::create_tables();
        self::run_data_migrations();

        if ( function_exists( 'add_rewrite_endpoint' ) ) {
            add_rewrite_endpoint( 'bangla-track', EP_ROOT | EP_PAGES );
            flush_rewrite_rules();
        }

        update_option( 'bt_server_db_version', BT_SERVER_VERSION );
    }

    /**
     * Run schema and data migrations if plugin version changed.
     *
     * @return void
     */
    public static function maybe_migrate() {
        $installed = (string) get_option( 'bt_server_db_version', '' );
        if ( $installed === BT_SERVER_VERSION ) {
            return;
        }

        self::create_tables();
        self::run_data_migrations();

        if ( function_exists( 'add_rewrite_endpoint' ) ) {
            add_rewrite_endpoint( 'bangla-track', EP_ROOT | EP_PAGES );
            flush_rewrite_rules();
        }

        update_option( 'bt_server_db_version', BT_SERVER_VERSION, false );
    }

    private static function create_tables() {
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();

        $licenses_table        = $wpdb->prefix . 'bt_licenses';
        $plugin_releases_table = $wpdb->prefix . 'bt_plugin_releases';
        $entitlements_table    = $wpdb->prefix . 'bt_license_entitlements';
        $activations_table     = $wpdb->prefix . 'bt_activations';
        $usage_table           = $wpdb->prefix . 'bt_usage';
        $provider_lock         = $wpdb->prefix . 'bt_provider_locks';
        $free_sites_table      = $wpdb->prefix . 'bt_free_sites';
        $site_plugins_table    = $wpdb->prefix . 'bt_site_plugins';

        $sql = "CREATE TABLE {$licenses_table} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            license_key VARCHAR(64) NOT NULL,
            user_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
            entitlement_id BIGINT(20) UNSIGNED NULL,
            order_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
            order_item_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
            product_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
            product_code VARCHAR(64) NOT NULL DEFAULT '',
            plan_code VARCHAR(32) NOT NULL DEFAULT 'free',
            is_lifetime TINYINT(1) NOT NULL DEFAULT 0,
            duration_days INT(11) NOT NULL DEFAULT 0,
            starts_at DATETIME NULL,
            monthly_booking_limit INT(11) NOT NULL DEFAULT 100,
            allowed_active_providers INT(11) NOT NULL DEFAULT 1,
            multi_provider TINYINT(1) NOT NULL DEFAULT 0,
            max_sites INT(11) NOT NULL DEFAULT 1,
            customer_email VARCHAR(255) DEFAULT '',
            customer_name VARCHAR(255) DEFAULT '',
            plan ENUM('free','starter','pro') NOT NULL DEFAULT 'free',
            status ENUM('active','expired','disabled','cancelled') NOT NULL DEFAULT 'active',
            max_activations INT(11) NOT NULL DEFAULT 1,
            expires_at DATETIME NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY license_key (license_key),
            UNIQUE KEY entitlement_id (entitlement_id),
            KEY user_id (user_id),
            KEY order_id (order_id),
            KEY order_item_id (order_item_id),
            KEY product_id (product_id),
            KEY product_code (product_code),
            KEY plan_code (plan_code),
            KEY plan (plan),
            KEY status (status)
        ) {$charset_collate};

        CREATE TABLE {$entitlements_table} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
            order_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
            order_item_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
            product_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
            product_code VARCHAR(64) NOT NULL DEFAULT '',
            status ENUM('pending','generated','cancelled','refunded') NOT NULL DEFAULT 'pending',
            license_id BIGINT(20) UNSIGNED NULL,
            generated_at DATETIME NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY order_item_unique (order_id, order_item_id),
            KEY user_id (user_id),
            KEY order_id (order_id),
            KEY product_id (product_id),
            KEY status (status),
            KEY license_id (license_id)
        ) {$charset_collate};

        CREATE TABLE {$plugin_releases_table} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            plugin_type VARCHAR(20) NOT NULL,
            plugin_name VARCHAR(255) NULL,
            version VARCHAR(50) NOT NULL,
            file_path TEXT NOT NULL,
            file_name VARCHAR(255) NOT NULL,
            file_size BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
            changelog LONGTEXT NULL,
            requires_wp VARCHAR(50) NULL,
            requires_php VARCHAR(50) NULL,
            description TEXT NULL,
            text_domain VARCHAR(100) NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            uploaded_by BIGINT(20) UNSIGNED NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY plugin_type (plugin_type),
            KEY is_active (is_active)
        ) {$charset_collate};

        CREATE TABLE {$activations_table} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            license_id BIGINT(20) UNSIGNED NOT NULL,
            site_url VARCHAR(255) NOT NULL,
            site_name VARCHAR(255) DEFAULT '',
            wp_version VARCHAR(20) DEFAULT '',
            plugin_version VARCHAR(20) DEFAULT '',
            php_version VARCHAR(20) DEFAULT '',
            status ENUM('active','inactive') NOT NULL DEFAULT 'active',
            activated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            last_seen_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY license_site (license_id, site_url),
            KEY status (status),
            KEY license_id (license_id)
        ) {$charset_collate};

        CREATE TABLE {$usage_table} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            license_id BIGINT(20) UNSIGNED NOT NULL,
            activation_id BIGINT(20) UNSIGNED NOT NULL,
            month_key CHAR(7) NOT NULL,
            provider_slug VARCHAR(20) NOT NULL,
            order_ref VARCHAR(191) NOT NULL,
            consignment_id VARCHAR(100) DEFAULT '',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY unique_booking (license_id, activation_id, order_ref, consignment_id),
            KEY month_key (month_key),
            KEY provider_slug (provider_slug)
        ) {$charset_collate};

        CREATE TABLE {$provider_lock} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            license_id BIGINT(20) UNSIGNED NOT NULL,
            activation_id BIGINT(20) UNSIGNED NOT NULL,
            locked_provider VARCHAR(20) NOT NULL,
            locked_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY license_activation (license_id, activation_id)
        ) {$charset_collate};

        CREATE TABLE {$free_sites_table} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            site_uuid VARCHAR(36) NOT NULL,
            site_url_hash VARCHAR(100) NOT NULL DEFAULT '',
            plugin_version VARCHAR(20) NOT NULL DEFAULT '',
            wp_version VARCHAR(20) NOT NULL DEFAULT '',
            php_version VARCHAR(20) NOT NULL DEFAULT '',
            active_provider_count INT(11) NOT NULL DEFAULT 0,
            booking_count INT(11) NOT NULL DEFAULT 0,
            status ENUM('active','inactive') NOT NULL DEFAULT 'active',
            registered_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            last_seen_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY site_uuid (site_uuid),
            KEY status (status),
            KEY site_url_hash (site_url_hash)
        ) {$charset_collate};

        CREATE TABLE {$site_plugins_table} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            source_type ENUM('activation','free_site') NOT NULL,
            source_id BIGINT(20) UNSIGNED NOT NULL,
            plugin_slug VARCHAR(191) NOT NULL,
            plugin_name VARCHAR(255) NOT NULL DEFAULT '',
            plugin_version VARCHAR(50) NOT NULL DEFAULT '',
            is_active TINYINT(1) NOT NULL DEFAULT 0,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY source_plugin (source_type, source_id, plugin_slug),
            KEY source_type_id (source_type, source_id)
        ) {$charset_collate};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql );

        // dbDelta() strips ENGINE= clauses, so enforce InnoDB separately.
        self::enforce_innodb_engine();
    }

    /**
     * Ensure all plugin tables use InnoDB engine.
     *
     * InnoDB provides row-level locking, ACID transactions, and foreign
     * key support — critical for license activation concurrency safety.
     *
     * @return void
     */
    private static function enforce_innodb_engine(): void {
        global $wpdb;

        $tables = array(
            'bt_licenses',
            'bt_license_entitlements',
            'bt_activations',
            'bt_plugin_releases',
            'bt_usage',
            'bt_provider_locks',
            'bt_free_sites',
            'bt_site_plugins',
        );

        foreach ( $tables as $table ) {
            $full_name = $wpdb->prefix . $table;
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $wpdb->query( "ALTER TABLE `{$full_name}` ENGINE = InnoDB" );
        }
    }

    public static function get_licenses_table() { global $wpdb; return $wpdb->prefix . 'bt_licenses'; }
    public static function get_plugin_releases_table() { global $wpdb; return $wpdb->prefix . 'bt_plugin_releases'; }
    public static function get_license_entitlements_table() { global $wpdb; return $wpdb->prefix . 'bt_license_entitlements'; }
    public static function get_activations_table() { global $wpdb; return $wpdb->prefix . 'bt_activations'; }
    public static function get_usage_table() { global $wpdb; return $wpdb->prefix . 'bt_usage'; }
    public static function get_provider_locks_table() { global $wpdb; return $wpdb->prefix . 'bt_provider_locks'; }
    public static function get_free_sites_table() { global $wpdb; return $wpdb->prefix . 'bt_free_sites'; }
    public static function get_site_plugins_table() { global $wpdb; return $wpdb->prefix . 'bt_site_plugins'; }

    /**
     * Normalize older legacy data values.
     *
     * @return void
     */
    private static function run_data_migrations() {
        global $wpdb;

        $licenses = self::get_licenses_table();
        $activations = self::get_activations_table();

        // Old status values to new enum.
        $wpdb->query( "UPDATE {$licenses} SET status = 'disabled' WHERE status = 'revoked'" );
        $wpdb->query( "UPDATE {$licenses} SET plan = 'free' WHERE plan IS NULL OR plan = ''" );
        $wpdb->query( "UPDATE {$licenses} SET plan_code = plan WHERE plan_code IS NULL OR plan_code = ''" );
        $wpdb->query( "UPDATE {$licenses} SET user_id = 0 WHERE user_id IS NULL" );
        $wpdb->query( "UPDATE {$licenses} SET order_id = 0 WHERE order_id IS NULL" );
        $wpdb->query( "UPDATE {$licenses} SET order_item_id = 0 WHERE order_item_id IS NULL" );
        $wpdb->query( "UPDATE {$licenses} SET product_id = 0 WHERE product_id IS NULL" );
        $wpdb->query( "UPDATE {$licenses} SET product_code = '' WHERE product_code IS NULL" );
        $wpdb->query( "UPDATE {$licenses} SET monthly_booking_limit = CASE WHEN plan_code = 'pro' THEN -1 WHEN plan_code = 'starter' THEN 500 ELSE 100 END WHERE monthly_booking_limit IS NULL OR monthly_booking_limit = 0" );
        $wpdb->query( "UPDATE {$licenses} SET allowed_active_providers = CASE WHEN plan_code = 'pro' THEN -1 ELSE 1 END WHERE allowed_active_providers IS NULL OR allowed_active_providers = 0" );
        $wpdb->query( "UPDATE {$licenses} SET multi_provider = CASE WHEN plan_code = 'pro' THEN 1 ELSE 0 END WHERE multi_provider IS NULL" );
        $wpdb->query( "UPDATE {$licenses} SET max_sites = CASE WHEN plan_code = 'pro' THEN 3 ELSE 1 END WHERE max_sites IS NULL OR max_sites = 0" );
        $wpdb->query( "UPDATE {$licenses} SET duration_days = 0 WHERE duration_days IS NULL" );
        $wpdb->query( "UPDATE {$licenses} SET is_lifetime = CASE WHEN expires_at IS NULL THEN 1 ELSE 0 END WHERE is_lifetime IS NULL" );
        $wpdb->query( "UPDATE {$licenses} SET starts_at = created_at WHERE starts_at IS NULL" );
        $wpdb->query( "UPDATE {$licenses} SET max_activations = max_sites WHERE max_activations IS NULL OR max_activations = 0" );

        // Legacy active flag migration for existing rows.
        $has_is_active = $wpdb->get_var( $wpdb->prepare( "SHOW COLUMNS FROM {$activations} LIKE %s", 'is_active' ) );
        if ( $has_is_active ) {
            $wpdb->query( "UPDATE {$activations} SET status = 'active' WHERE is_active = 1" );
            $wpdb->query( "UPDATE {$activations} SET status = 'inactive' WHERE is_active = 0" );
        }
    }
}
