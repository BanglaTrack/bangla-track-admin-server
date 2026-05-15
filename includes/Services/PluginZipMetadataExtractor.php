<?php
namespace BanglaTrackServer\Services;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class PluginZipMetadataExtractor {

    /**
     * Extract metadata from plugin ZIP.
     *
     * @param string $zip_path ZIP path.
     * @param string $expected_type free|pro.
     * @return array<string,mixed>|\WP_Error
     */
    public function extract( $zip_path, $expected_type ) {
        $expected_type = sanitize_key( (string) $expected_type );
        if ( ! in_array( $expected_type, array( 'free', 'pro' ), true ) ) {
            return new \WP_Error( 'invalid_plugin_type', __( 'Invalid plugin type.', 'bangla-track-server' ) );
        }

        $entries = $this->read_zip_entries( $zip_path );
        if ( is_wp_error( $entries ) ) {
            return $entries;
        }

        $candidate_headers = $this->find_candidate_headers( $entries );
        if ( empty( $candidate_headers ) ) {
            return new \WP_Error( 'plugin_header_missing', __( 'Could not find a valid plugin main file with Plugin Name and Version headers.', 'bangla-track-server' ) );
        }

        $selected = null;
        foreach ( $candidate_headers as $candidate ) {
            $detected = $this->detect_plugin_type( $candidate['entry_name'], $candidate['headers'] );
            if ( $detected === $expected_type ) {
                $candidate['detected_type'] = $detected;
                $selected = $candidate;
                break;
            }
            if ( null === $selected ) {
                $candidate['detected_type'] = $detected;
                $selected = $candidate;
            }
        }

        if ( ! $selected || $selected['detected_type'] !== $expected_type ) {
            return new \WP_Error(
                'wrong_zip_type',
                sprintf(
                    __( 'This ZIP looks like %1$s plugin, but you are uploading to %2$s release slot.', 'bangla-track-server' ),
                    strtoupper( $selected ? $selected['detected_type'] : 'UNKNOWN' ),
                    strtoupper( $expected_type )
                )
            );
        }

        $headers = $selected['headers'];
        $changelog = $this->extract_changelog( $entries );

        return array(
            'plugin_type' => $expected_type,
            'entry_name' => $selected['entry_name'],
            'plugin_name' => $headers['Plugin Name'],
            'version' => $headers['Version'],
            'requires_wp' => $headers['Requires at least'],
            'requires_php' => $headers['Requires PHP'],
            'description' => $headers['Description'],
            'text_domain' => $headers['Text Domain'],
            'changelog' => $changelog,
        );
    }

    /**
     * Find possible plugin main files and parsed headers.
     *
     * @param array<string,string> $entries ZIP entries map.
     * @return array<int,array<string,mixed>>
     */
    private function find_candidate_headers( array $entries ) {
        $priority_entries = array(
            'bangla-track/bangla-track.php',
            'bangla-track-pro/bangla-track-pro.php',
        );

        $candidates = array();

        foreach ( $priority_entries as $entry_name ) {
            if ( ! isset( $entries[ $entry_name ] ) ) {
                continue;
            }
            $content = (string) $entries[ $entry_name ];

            $headers = $this->parse_plugin_headers( $content );
            if ( ! empty( $headers['Plugin Name'] ) && ! empty( $headers['Version'] ) ) {
                $candidates[] = array(
                    'entry_name' => $entry_name,
                    'headers' => $headers,
                );
            }
        }

        foreach ( $entries as $entry_name => $content ) {
            if ( empty( $entry_name ) || substr( $entry_name, -4 ) !== '.php' ) {
                continue;
            }

            $already = false;
            foreach ( $candidates as $candidate ) {
                if ( $candidate['entry_name'] === $entry_name ) {
                    $already = true;
                    break;
                }
            }
            if ( $already ) {
                continue;
            }

            $headers = $this->parse_plugin_headers( $content );
            if ( empty( $headers['Plugin Name'] ) || empty( $headers['Version'] ) ) {
                continue;
            }

            $candidates[] = array(
                'entry_name' => $entry_name,
                'headers' => $headers,
            );
        }

        return $candidates;
    }

    /**
     * Read ZIP entries as a map of relative path => content.
     *
     * @param string $zip_path ZIP path.
     * @return array<string,string>|\WP_Error
     */
    private function read_zip_entries( $zip_path ) {
        $zip_path = (string) $zip_path;
        if ( '' === $zip_path || ! is_readable( $zip_path ) ) {
            return new \WP_Error( 'zip_unreadable', __( 'Uploaded ZIP file is not readable.', 'bangla-track-server' ) );
        }

        if ( class_exists( 'ZipArchive' ) ) {
            $zip_entries = $this->read_zip_entries_with_ziparchive( $zip_path );
            if ( ! is_wp_error( $zip_entries ) ) {
                return $zip_entries;
            }
        }

        return $this->read_zip_entries_with_pclzip( $zip_path );
    }

    /**
     * Read ZIP entries with ZipArchive extension.
     *
     * @param string $zip_path ZIP path.
     * @return array<string,string>|\WP_Error
     */
    private function read_zip_entries_with_ziparchive( $zip_path ) {
        $zip = new \ZipArchive();
        $opened = $zip->open( $zip_path );
        if ( true !== $opened ) {
            return new \WP_Error( 'zip_open_failed', __( 'Could not open ZIP file.', 'bangla-track-server' ) );
        }

        $entries = array();
        for ( $i = 0; $i < $zip->numFiles; $i++ ) {
            $entry_name = $this->normalize_entry_name( (string) $zip->getNameIndex( $i ) );
            if ( '' === $entry_name || str_ends_with( $entry_name, '/' ) ) {
                continue;
            }

            $content = $zip->getFromIndex( $i );
            if ( false === $content ) {
                continue;
            }

            $entries[ $entry_name ] = (string) $content;
        }

        $zip->close();
        return $entries;
    }

    /**
     * Read ZIP entries with bundled WordPress PclZip fallback.
     *
     * @param string $zip_path ZIP path.
     * @return array<string,string>|\WP_Error
     */
    private function read_zip_entries_with_pclzip( $zip_path ) {
        mbstring_binary_safe_encoding();
        require_once ABSPATH . 'wp-admin/includes/class-pclzip.php';

        $archive = new \PclZip( $zip_path );
        $files = $archive->extract( PCLZIP_OPT_EXTRACT_AS_STRING );
        reset_mbstring_encoding();

        if ( ! is_array( $files ) ) {
            return new \WP_Error( 'zip_open_failed', __( 'Could not open ZIP file.', 'bangla-track-server' ) );
        }

        $entries = array();
        foreach ( $files as $file ) {
            $entry_name = $this->normalize_entry_name( (string) ( $file['filename'] ?? '' ) );
            if ( '' === $entry_name || str_ends_with( $entry_name, '/' ) ) {
                continue;
            }

            $entries[ $entry_name ] = (string) ( $file['content'] ?? '' );
        }

        return $entries;
    }

    /**
     * Normalize ZIP entry names to forward-slash relative paths.
     *
     * @param string $entry_name Entry path.
     * @return string
     */
    private function normalize_entry_name( $entry_name ) {
        $entry_name = str_replace( '\\', '/', (string) $entry_name );
        return ltrim( $entry_name, '/' );
    }

    /**
     * Parse plugin headers from file content.
     *
     * @param string $content File content.
     * @return array<string,string>
     */
    private function parse_plugin_headers( $content ) {
        $content = (string) $content;
        $content = substr( $content, 0, 8192 );

        $headers = array(
            'Plugin Name' => '',
            'Version' => '',
            'Requires at least' => '',
            'Requires PHP' => '',
            'Description' => '',
            'Text Domain' => '',
        );

        foreach ( $headers as $key => $unused ) {
            $pattern = '/^[ \t\/*#@]*' . preg_quote( $key, '/' ) . ':(.*)$/mi';
            if ( preg_match( $pattern, $content, $matches ) ) {
                $headers[ $key ] = trim( wp_strip_all_tags( $matches[1] ) );
            }
        }

        return $headers;
    }

    /**
     * Detect plugin type from path/header hints.
     *
     * @param string               $entry_name ZIP entry.
     * @param array<string,string> $headers Parsed plugin headers.
     * @return string
     */
    private function detect_plugin_type( $entry_name, array $headers ) {
        $entry_name = strtolower( (string) $entry_name );
        $plugin_name = strtolower( (string) ( $headers['Plugin Name'] ?? '' ) );
        $text_domain = strtolower( (string) ( $headers['Text Domain'] ?? '' ) );

        if ( false !== strpos( $entry_name, 'bangla-track-pro/bangla-track-pro.php' ) ) {
            return 'pro';
        }

        if ( false !== strpos( $entry_name, 'bangla-track/bangla-track.php' ) ) {
            return 'free';
        }

        if ( 'bangla-track-pro' === $text_domain || false !== strpos( $plugin_name, 'bangla track pro' ) ) {
            return 'pro';
        }

        if ( 'bangla-track' === $text_domain || false !== strpos( $plugin_name, 'bangla track' ) ) {
            return 'free';
        }

        return 'unknown';
    }

    /**
     * Extract changelog text from ZIP.
     *
     * @param array<string,string> $entries ZIP entries map.
     * @return string
     */
    private function extract_changelog( array $entries ) {
        $readme_entry = $this->find_file_case_insensitive( $entries, array( 'readme.txt' ) );
        if ( $readme_entry ) {
            $readme = (string) $entries[ $readme_entry ];
            $from_readme = $this->extract_from_readme_changelog( $readme );
            if ( '' !== $from_readme ) {
                return $from_readme;
            }
        }

        $changelog_entry = $this->find_file_case_insensitive( $entries, array( 'changelog.md', 'changelog.txt', 'CHANGELOG.md', 'CHANGELOG.txt' ) );
        if ( $changelog_entry ) {
            $changelog = (string) $entries[ $changelog_entry ];
            return trim( $changelog );
        }

        return '';
    }

    /**
     * Find a file in ZIP by file basename, case-insensitive.
     *
     * @param array<string,string> $entries ZIP entries map.
     * @param array<int,string> $file_names Candidate names.
     * @return string
     */
    private function find_file_case_insensitive( array $entries, array $file_names ) {
        $targets = array_map( 'strtolower', $file_names );

        foreach ( $entries as $entry => $unused_content ) {
            if ( empty( $entry ) ) {
                continue;
            }

            $base = strtolower( basename( $entry ) );
            if ( in_array( $base, $targets, true ) ) {
                return $entry;
            }
        }

        return '';
    }

    /**
     * Extract changelog section from WordPress readme.txt format.
     *
     * @param string $readme Readme content.
     * @return string
     */
    private function extract_from_readme_changelog( $readme ) {
        $readme = (string) $readme;
        if ( '' === trim( $readme ) ) {
            return '';
        }

        $stable_tag = '';
        if ( preg_match( '/^\s*Stable tag:\s*(.+)$/mi', $readme, $stable_matches ) ) {
            $stable_tag = trim( (string) $stable_matches[1] );
        }

        $parts = preg_split( '/^==\s*Changelog\s*==\s*$/mi', $readme, 2 );
        if ( count( $parts ) < 2 ) {
            return '';
        }

        $after = trim( (string) $parts[1] );
        if ( '' === $after ) {
            return '';
        }

        if ( '' !== $stable_tag && 'trunk' !== strtolower( $stable_tag ) ) {
            $stable_section = $this->extract_changelog_version_section( $after, $stable_tag );
            if ( '' !== $stable_section ) {
                return $stable_section;
            }
        }

        $top_level = preg_split( '/^==[^=].*==\s*$/m', $after );
        $changelog_section = trim( (string) ( $top_level[0] ?? $after ) );

        $lines = preg_split( '/\R/', $changelog_section );
        $latest_started = false;
        $latest = array();

        foreach ( $lines as $line ) {
            $line = rtrim( (string) $line );
            if ( preg_match( '/^=\s*[^=]+\s*=\s*$/', $line ) ) {
                if ( $latest_started ) {
                    break;
                }
                $latest_started = true;
                $latest[] = $line;
                continue;
            }

            if ( $latest_started ) {
                $latest[] = $line;
            }
        }

        if ( ! empty( $latest ) ) {
            return trim( implode( "\n", $latest ) );
        }

        return trim( $changelog_section );
    }

    /**
     * Try to extract changelog section for a specific version.
     *
     * @param string $changelog Changelog content.
     * @param string $version Stable tag version.
     * @return string
     */
    private function extract_changelog_version_section( $changelog, $version ) {
        $changelog = (string) $changelog;
        $version = trim( (string) $version );
        if ( '' === $version ) {
            return '';
        }

        $target_heading = '=' . $version . '=';
        $lines = preg_split( '/\R/', $changelog );
        $capture = array();
        $started = false;

        foreach ( $lines as $line ) {
            $line = rtrim( (string) $line );
            $compact = preg_replace( '/\s+/', '', $line );

            if ( preg_match( '/^=\s*[^=]+\s*=\s*$/', $line ) ) {
                if ( $started ) {
                    break;
                }

                if ( strtolower( $compact ) === strtolower( $target_heading ) ) {
                    $started = true;
                    $capture[] = $line;
                }

                continue;
            }

            if ( $started ) {
                $capture[] = $line;
            }
        }

        return trim( implode( "\n", $capture ) );
    }
}
