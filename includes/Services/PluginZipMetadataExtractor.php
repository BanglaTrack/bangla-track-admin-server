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

        if ( ! class_exists( 'ZipArchive' ) ) {
            return new \WP_Error( 'zip_missing', __( 'ZipArchive extension is not available on the server.', 'bangla-track-server' ) );
        }

        $zip = new \ZipArchive();
        $opened = $zip->open( $zip_path );
        if ( true !== $opened ) {
            return new \WP_Error( 'zip_open_failed', __( 'Could not open ZIP file.', 'bangla-track-server' ) );
        }

        $candidate_headers = $this->find_candidate_headers( $zip );
        if ( empty( $candidate_headers ) ) {
            $zip->close();
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
            $zip->close();
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
        $changelog = $this->extract_changelog( $zip );
        $zip->close();

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
     * @param \ZipArchive $zip Zip object.
     * @return array<int,array<string,mixed>>
     */
    private function find_candidate_headers( \ZipArchive $zip ) {
        $priority_entries = array(
            'bangla-track/bangla-track.php',
            'bangla-track-pro/bangla-track-pro.php',
        );

        $candidates = array();

        foreach ( $priority_entries as $entry_name ) {
            $content = $zip->getFromName( $entry_name );
            if ( false === $content ) {
                continue;
            }

            $headers = $this->parse_plugin_headers( $content );
            if ( ! empty( $headers['Plugin Name'] ) && ! empty( $headers['Version'] ) ) {
                $candidates[] = array(
                    'entry_name' => $entry_name,
                    'headers' => $headers,
                );
            }
        }

        for ( $i = 0; $i < $zip->numFiles; $i++ ) {
            $entry_name = (string) $zip->getNameIndex( $i );
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

            $content = $zip->getFromIndex( $i );
            if ( false === $content ) {
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
     * @param \ZipArchive $zip Zip object.
     * @return string
     */
    private function extract_changelog( \ZipArchive $zip ) {
        $readme_entry = $this->find_file_case_insensitive( $zip, array( 'readme.txt' ) );
        if ( $readme_entry ) {
            $readme = $zip->getFromName( $readme_entry );
            if ( false !== $readme ) {
                $from_readme = $this->extract_from_readme_changelog( (string) $readme );
                if ( '' !== $from_readme ) {
                    return $from_readme;
                }
            }
        }

        $changelog_entry = $this->find_file_case_insensitive( $zip, array( 'changelog.md', 'changelog.txt', 'CHANGELOG.md', 'CHANGELOG.txt' ) );
        if ( $changelog_entry ) {
            $changelog = $zip->getFromName( $changelog_entry );
            if ( false !== $changelog ) {
                return trim( (string) $changelog );
            }
        }

        return '';
    }

    /**
     * Find a file in ZIP by file basename, case-insensitive.
     *
     * @param \ZipArchive     $zip Zip object.
     * @param array<int,string> $file_names Candidate names.
     * @return string
     */
    private function find_file_case_insensitive( \ZipArchive $zip, array $file_names ) {
        $targets = array_map( 'strtolower', $file_names );

        for ( $i = 0; $i < $zip->numFiles; $i++ ) {
            $entry = (string) $zip->getNameIndex( $i );
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
