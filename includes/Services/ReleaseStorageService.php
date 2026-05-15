<?php
namespace BanglaTrackServer\Services;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class ReleaseStorageService {
    const DIRECTORY_NAME = 'bangla-track-releases';

    /**
     * Ensure storage directory exists and return details.
     *
     * @return array<string,mixed>
     */
    public function ensure_storage_directory() {
        $outside = $this->get_outside_webroot_path();
        if ( ! empty( $outside ) && $this->ensure_directory( $outside ) ) {
            return array(
                'path' => $outside,
                'location' => 'outside-webroot',
                'writable' => is_writable( $outside ),
                'protected' => true,
            );
        }

        $fallback = wp_normalize_path( trailingslashit( WP_CONTENT_DIR ) . self::DIRECTORY_NAME );
        $ok = $this->ensure_directory( $fallback );
        if ( $ok ) {
            $this->ensure_fallback_protection( $fallback );
        }

        return array(
            'path' => $fallback,
            'location' => 'wp-content-fallback',
            'writable' => $ok && is_writable( $fallback ),
            'protected' => $this->has_fallback_protection( $fallback ),
        );
    }

    /**
     * Build final filename for a release.
     *
     * @param string $plugin_type Plugin type.
     * @param string $version Version string.
     * @return string
     */
    public function build_release_filename( $plugin_type, $version ) {
        $plugin_type = ( 'pro' === sanitize_key( $plugin_type ) ) ? 'pro' : 'free';
        $version = preg_replace( '/[^a-zA-Z0-9._-]/', '-', (string) $version );
        $version = trim( (string) $version, '-_' );
        if ( '' === $version ) {
            $version = 'unknown';
        }

        return sprintf( 'bangla-track-%s-%s.zip', $plugin_type, $version );
    }

    /**
     * Return a unique file path in storage folder.
     *
     * @param string $dir Directory path.
     * @param string $file_name File name.
     * @return string
     */
    public function unique_file_path( $dir, $file_name ) {
        $dir = untrailingslashit( wp_normalize_path( $dir ) );
        $file_name = sanitize_file_name( $file_name );
        $target = $dir . '/' . $file_name;

        if ( ! file_exists( $target ) ) {
            return $target;
        }

        $ext = pathinfo( $file_name, PATHINFO_EXTENSION );
        $base = basename( $file_name, '.' . $ext );

        return $dir . '/' . $base . '-' . wp_generate_password( 6, false, false ) . '.' . $ext;
    }

    /**
     * Try to detect outside-webroot storage path.
     *
     * @return string
     */
    private function get_outside_webroot_path() {
        $abspath = untrailingslashit( wp_normalize_path( ABSPATH ) );
        if ( empty( $abspath ) ) {
            return '';
        }

        $parent = wp_normalize_path( dirname( $abspath ) );
        if ( empty( $parent ) || '.' === $parent || '/' === $parent || $parent === $abspath ) {
            return '';
        }

        return trailingslashit( $parent ) . self::DIRECTORY_NAME;
    }

    /**
     * Ensure directory exists and writable.
     *
     * @param string $path Directory path.
     * @return bool
     */
    private function ensure_directory( $path ) {
        $path = wp_normalize_path( $path );
        if ( ! file_exists( $path ) ) {
            if ( ! wp_mkdir_p( $path ) ) {
                return false;
            }
        }

        return is_dir( $path ) && is_writable( $path );
    }

    /**
     * Write fallback protections into wp-content storage.
     *
     * @param string $path Directory path.
     * @return void
     */
    private function ensure_fallback_protection( $path ) {
        $path = untrailingslashit( wp_normalize_path( $path ) );

        $index_file = $path . '/index.php';
        if ( ! file_exists( $index_file ) ) {
            file_put_contents( $index_file, "<?php\n// Silence is golden.\n" );
        }

        $htaccess_file = $path . '/.htaccess';
        $htaccess_rules = "Require all denied\nDeny from all\n";
        file_put_contents( $htaccess_file, $htaccess_rules );
    }

    /**
     * Check fallback protection files.
     *
     * @param string $path Directory path.
     * @return bool
     */
    private function has_fallback_protection( $path ) {
        $path = untrailingslashit( wp_normalize_path( $path ) );
        return file_exists( $path . '/index.php' ) && file_exists( $path . '/.htaccess' );
    }
}