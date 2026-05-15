<?php
namespace BanglaTrackServer\Database;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class PluginReleaseRepository {

    /**
     * Get active release for a plugin type.
     *
     * @param string $plugin_type Plugin type.
     * @return object|null
     */
    public function get_active_by_type( $plugin_type ) {
        global $wpdb;
        $table = Installer::get_plugin_releases_table();

        return $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$table} WHERE plugin_type = %s AND is_active = 1 ORDER BY id DESC LIMIT 1",
                $this->sanitize_plugin_type( $plugin_type )
            )
        );
    }

    /**
     * Get latest releases per plugin type.
     *
     * @param string $plugin_type Plugin type.
     * @param int    $limit Limit.
     * @return array<int, object>
     */
    public function get_by_type( $plugin_type, $limit = 10 ) {
        global $wpdb;
        $table = Installer::get_plugin_releases_table();

        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$table} WHERE plugin_type = %s ORDER BY id DESC LIMIT %d",
                $this->sanitize_plugin_type( $plugin_type ),
                absint( $limit )
            )
        );
    }

    /**
     * Create release record and set it active.
     *
     * @param array<string,mixed> $data Release payload.
     * @return int|false
     */
    public function create_release( array $data ) {
        global $wpdb;
        $table = Installer::get_plugin_releases_table();

        $plugin_type = $this->sanitize_plugin_type( $data['plugin_type'] ?? '' );
        if ( empty( $plugin_type ) ) {
            return false;
        }

        $wpdb->query( 'START TRANSACTION' );

        $deactivated = $wpdb->update(
            $table,
            array( 'is_active' => 0 ),
            array( 'plugin_type' => $plugin_type ),
            array( '%d' ),
            array( '%s' )
        );

        if ( false === $deactivated ) {
            $wpdb->query( 'ROLLBACK' );
            return false;
        }

        $inserted = $wpdb->insert(
            $table,
            array(
                'plugin_type' => $plugin_type,
                'plugin_name' => sanitize_text_field( (string) ( $data['plugin_name'] ?? '' ) ),
                'version' => sanitize_text_field( (string) ( $data['version'] ?? '' ) ),
                'file_path' => sanitize_textarea_field( (string) ( $data['file_path'] ?? '' ) ),
                'file_name' => sanitize_file_name( (string) ( $data['file_name'] ?? '' ) ),
                'file_size' => isset( $data['file_size'] ) ? absint( $data['file_size'] ) : 0,
                'changelog' => isset( $data['changelog'] ) ? wp_kses_post( (string) $data['changelog'] ) : '',
                'requires_wp' => sanitize_text_field( (string) ( $data['requires_wp'] ?? '' ) ),
                'requires_php' => sanitize_text_field( (string) ( $data['requires_php'] ?? '' ) ),
                'description' => sanitize_textarea_field( (string) ( $data['description'] ?? '' ) ),
                'text_domain' => sanitize_key( (string) ( $data['text_domain'] ?? '' ) ),
                'is_active' => 1,
                'uploaded_by' => ! empty( $data['uploaded_by'] ) ? absint( $data['uploaded_by'] ) : null,
                'created_at' => current_time( 'mysql' ),
                'updated_at' => current_time( 'mysql' ),
            ),
            array( '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%s', '%s' )
        );

        if ( ! $inserted ) {
            $wpdb->query( 'ROLLBACK' );
            return false;
        }

        $new_id = (int) $wpdb->insert_id;
        $wpdb->query( 'COMMIT' );

        return $new_id;
    }

    /**
     * Get release by ID.
     *
     * @param int $id Release ID.
     * @return object|null
     */
    public function get_by_id( $id ) {
        global $wpdb;
        $table = Installer::get_plugin_releases_table();

        return $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$table} WHERE id = %d",
                absint( $id )
            )
        );
    }

    /**
     * Validate and normalize plugin type.
     *
     * @param string $plugin_type Plugin type.
     * @return string
     */
    public function sanitize_plugin_type( $plugin_type ) {
        $plugin_type = sanitize_key( (string) $plugin_type );
        if ( in_array( $plugin_type, array( 'free', 'pro' ), true ) ) {
            return $plugin_type;
        }
        return '';
    }
}