<?php
/**
 * Plugin releases admin page.
 *
 * @package BanglaTrackServer\Admin
 */

namespace BanglaTrackServer\Admin;

use BanglaTrackServer\Database\PluginReleaseRepository;
use BanglaTrackServer\Services\PluginZipMetadataExtractor;
use BanglaTrackServer\Services\ReleaseStorageService;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class PluginReleasesPage {

    /**
     * Page slug.
     */
    const PAGE_SLUG = 'bt-server-plugin-releases';

    /**
     * Release repository.
     *
     * @var PluginReleaseRepository
     */
    private $release_repo;

    /**
     * Storage service.
     *
     * @var ReleaseStorageService
     */
    private $storage_service;

    /**
     * ZIP extractor service.
     *
     * @var PluginZipMetadataExtractor
     */
    private $zip_extractor;

    /**
     * Constructor.
     */
    public function __construct() {
        $this->release_repo = new PluginReleaseRepository();
        $this->storage_service = new ReleaseStorageService();
        $this->zip_extractor = new PluginZipMetadataExtractor();

        add_action( 'admin_init', array( $this, 'handle_upload' ) );
        add_action( 'admin_post_bt_admin_download_release', array( $this, 'handle_admin_download' ) );
    }

    /**
     * Handle release upload.
     *
     * @return void
     */
    public function handle_upload() {
        if ( ! is_admin() ) {
            return;
        }

        if ( ! isset( $_GET['page'] ) || self::PAGE_SLUG !== sanitize_key( (string) $_GET['page'] ) ) {
            return;
        }

        if ( empty( $_POST['bt_upload_plugin_release'] ) ) {
            return;
        }

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'You are not allowed to upload plugin releases.', 'bangla-track-server' ) );
        }

        $plugin_type = sanitize_key( (string) ( $_POST['plugin_type'] ?? '' ) );
        if ( ! in_array( $plugin_type, array( 'free', 'pro' ), true ) ) {
            $this->redirect_with_notice( 'error', __( 'Invalid plugin type for upload.', 'bangla-track-server' ) );
        }

        check_admin_referer( 'bt_upload_plugin_release_' . $plugin_type );

        if ( empty( $_FILES['plugin_zip'] ) || ! is_array( $_FILES['plugin_zip'] ) ) {
            $this->redirect_with_notice( 'error', __( 'Please upload a ZIP file.', 'bangla-track-server' ) );
        }

        $file = $_FILES['plugin_zip'];

        if ( ! empty( $file['error'] ) ) {
            $this->redirect_with_notice( 'error', __( 'File upload failed. Please try again.', 'bangla-track-server' ) );
        }

        $original_name = sanitize_file_name( (string) ( $file['name'] ?? '' ) );
        $tmp_name = (string) ( $file['tmp_name'] ?? '' );

        if ( empty( $original_name ) || empty( $tmp_name ) ) {
            $this->redirect_with_notice( 'error', __( 'Invalid uploaded file.', 'bangla-track-server' ) );
        }

        $extension = strtolower( pathinfo( $original_name, PATHINFO_EXTENSION ) );
        if ( 'zip' !== $extension ) {
            $this->redirect_with_notice( 'error', __( 'Only .zip files are allowed.', 'bangla-track-server' ) );
        }

        if ( ! is_uploaded_file( $tmp_name ) || ! is_readable( $tmp_name ) ) {
            $this->redirect_with_notice( 'error', __( 'Uploaded ZIP file is not readable.', 'bangla-track-server' ) );
        }

        $metadata = $this->zip_extractor->extract( $tmp_name, $plugin_type );
        if ( is_wp_error( $metadata ) ) {
            $this->redirect_with_notice( 'error', $metadata->get_error_message() );
        }

        if ( empty( $metadata['version'] ) || empty( $metadata['plugin_name'] ) ) {
            $this->redirect_with_notice( 'error', __( 'Plugin ZIP is missing required plugin headers: Plugin Name and Version.', 'bangla-track-server' ) );
        }

        $storage = $this->storage_service->ensure_storage_directory();
        if ( empty( $storage['path'] ) || empty( $storage['writable'] ) ) {
            $this->redirect_with_notice( 'error', __( 'Release storage folder is not writable.', 'bangla-track-server' ) );
        }

        $target_name = $this->storage_service->build_release_filename( $plugin_type, (string) $metadata['version'] );
        $target_path = $this->storage_service->unique_file_path( (string) $storage['path'], $target_name );

        if ( ! @move_uploaded_file( $tmp_name, $target_path ) ) {
            $this->redirect_with_notice( 'error', __( 'Could not move uploaded ZIP into secure release storage.', 'bangla-track-server' ) );
        }

        $file_size = file_exists( $target_path ) ? (int) filesize( $target_path ) : 0;

        $release_id = $this->release_repo->create_release(
            array(
                'plugin_type' => $plugin_type,
                'plugin_name' => (string) $metadata['plugin_name'],
                'version' => (string) $metadata['version'],
                'file_path' => wp_normalize_path( $target_path ),
                'file_name' => basename( $target_path ),
                'file_size' => $file_size,
                'changelog' => (string) ( $metadata['changelog'] ?? '' ),
                'requires_wp' => (string) ( $metadata['requires_wp'] ?? '' ),
                'requires_php' => (string) ( $metadata['requires_php'] ?? '' ),
                'description' => (string) ( $metadata['description'] ?? '' ),
                'text_domain' => (string) ( $metadata['text_domain'] ?? '' ),
                'uploaded_by' => get_current_user_id(),
            )
        );

        if ( ! $release_id ) {
            @unlink( $target_path );
            $this->redirect_with_notice( 'error', __( 'Could not save plugin release record in database.', 'bangla-track-server' ) );
        }

        $this->redirect_with_notice( 'success', sprintf( __( '%s plugin release uploaded successfully.', 'bangla-track-server' ), strtoupper( $plugin_type ) ) );
    }

    /**
     * Admin-only test download endpoint.
     *
     * @return void
     */
    public function handle_admin_download() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'You are not allowed to download release files.', 'bangla-track-server' ) );
        }

        $release_id = absint( $_GET['release_id'] ?? 0 );
        if ( $release_id <= 0 ) {
            wp_die( esc_html__( 'Invalid release ID.', 'bangla-track-server' ) );
        }

        check_admin_referer( 'bt_admin_download_release_' . $release_id );

        $release = $this->release_repo->get_by_id( $release_id );
        if ( ! $release || empty( $release->file_path ) || ! file_exists( $release->file_path ) ) {
            wp_die( esc_html__( 'Release file not found.', 'bangla-track-server' ) );
        }

        $this->stream_file_download( (string) $release->file_path, (string) $release->file_name );
    }

    /**
     * Render page.
     *
     * @return void
     */
    public function render() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'You are not allowed to access this page.', 'bangla-track-server' ) );
        }

        $free_release = $this->release_repo->get_active_by_type( 'free' );
        $pro_release = $this->release_repo->get_active_by_type( 'pro' );
        $storage = $this->storage_service->ensure_storage_directory();

        $this->render_notices();
        ?>
        <div class="wrap bt-server-plugin-releases">
            <h1><?php esc_html_e( 'Plugin Releases', 'bangla-track-server' ); ?></h1>

            <div class="notice notice-info"><p>
                <?php esc_html_e( 'Upload Free and Pro ZIP files. Version and metadata are read automatically from inside ZIP file headers.', 'bangla-track-server' ); ?>
            </p></div>

            <div class="bt-server-card" style="margin-bottom:20px;">
                <h2><?php esc_html_e( 'Release Storage', 'bangla-track-server' ); ?></h2>
                <p><strong><?php esc_html_e( 'Location:', 'bangla-track-server' ); ?></strong> <?php echo esc_html( $storage['location'] ?? '' ); ?></p>
                <p><strong><?php esc_html_e( 'Path:', 'bangla-track-server' ); ?></strong> <code><?php echo esc_html( (string) ( $storage['path'] ?? '' ) ); ?></code></p>
                <p><strong><?php esc_html_e( 'Writable:', 'bangla-track-server' ); ?></strong> <?php echo ! empty( $storage['writable'] ) ? esc_html__( 'Yes', 'bangla-track-server' ) : esc_html__( 'No', 'bangla-track-server' ); ?></p>
                <p><strong><?php esc_html_e( 'Protected:', 'bangla-track-server' ); ?></strong> <?php echo ! empty( $storage['protected'] ) ? esc_html__( 'Yes', 'bangla-track-server' ) : esc_html__( 'No', 'bangla-track-server' ); ?></p>
            </div>

            <div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;">
                <?php $this->render_release_card( 'free', __( 'Bangla Track Free Plugin', 'bangla-track-server' ), $free_release ); ?>
                <?php $this->render_release_card( 'pro', __( 'Bangla Track Pro Plugin', 'bangla-track-server' ), $pro_release ); ?>
            </div>
        </div>
        <?php
    }

    /**
     * Render release card.
     *
     * @param string      $plugin_type Plugin type.
     * @param string      $title Card title.
     * @param object|null $release Active release.
     * @return void
     */
    private function render_release_card( $plugin_type, $title, $release ) {
        ?>
        <div class="bt-server-card">
            <h2><?php echo esc_html( $title ); ?></h2>

            <form method="post" enctype="multipart/form-data">
                <input type="hidden" name="plugin_type" value="<?php echo esc_attr( $plugin_type ); ?>" />
                <input type="hidden" name="bt_upload_plugin_release" value="1" />
                <?php wp_nonce_field( 'bt_upload_plugin_release_' . $plugin_type ); ?>

                <p>
                    <label for="bt_plugin_zip_<?php echo esc_attr( $plugin_type ); ?>"><strong><?php esc_html_e( 'Upload ZIP', 'bangla-track-server' ); ?></strong></label><br />
                    <input type="file" id="bt_plugin_zip_<?php echo esc_attr( $plugin_type ); ?>" name="plugin_zip" accept=".zip" required />
                </p>

                <p>
                    <button type="submit" class="button button-primary"><?php esc_html_e( 'Upload ZIP', 'bangla-track-server' ); ?></button>
                </p>
            </form>

            <hr />

            <h3><?php esc_html_e( 'Current Active Release', 'bangla-track-server' ); ?></h3>

            <?php if ( ! $release ) : ?>
                <p><?php esc_html_e( 'No active release uploaded yet.', 'bangla-track-server' ); ?></p>
            <?php else : ?>
                <p><strong><?php esc_html_e( 'Plugin type:', 'bangla-track-server' ); ?></strong> <?php echo esc_html( strtoupper( (string) $release->plugin_type ) ); ?></p>
                <p><strong><?php esc_html_e( 'Plugin name:', 'bangla-track-server' ); ?></strong> <?php echo esc_html( (string) $release->plugin_name ); ?></p>
                <p><strong><?php esc_html_e( 'Version:', 'bangla-track-server' ); ?></strong> <?php echo esc_html( (string) $release->version ); ?></p>
                <p><strong><?php esc_html_e( 'File name:', 'bangla-track-server' ); ?></strong> <code><?php echo esc_html( (string) $release->file_name ); ?></code></p>
                <p><strong><?php esc_html_e( 'File size:', 'bangla-track-server' ); ?></strong> <?php echo esc_html( size_format( (int) $release->file_size ) ); ?></p>
                <p><strong><?php esc_html_e( 'Uploaded date:', 'bangla-track-server' ); ?></strong> <?php echo esc_html( $this->format_datetime( (string) $release->created_at ) ); ?></p>
                <p><strong><?php esc_html_e( 'Minimum WordPress version:', 'bangla-track-server' ); ?></strong> <?php echo esc_html( (string) ( $release->requires_wp ?: '-' ) ); ?></p>
                <p><strong><?php esc_html_e( 'Minimum PHP version:', 'bangla-track-server' ); ?></strong> <?php echo esc_html( (string) ( $release->requires_php ?: '-' ) ); ?></p>
                <p><strong><?php esc_html_e( 'Description:', 'bangla-track-server' ); ?></strong> <?php echo esc_html( (string) ( $release->description ?: '-' ) ); ?></p>
                <p><strong><?php esc_html_e( 'Storage path:', 'bangla-track-server' ); ?></strong> <code><?php echo esc_html( (string) $release->file_path ); ?></code></p>

                <p><strong><?php esc_html_e( 'Changelog:', 'bangla-track-server' ); ?></strong></p>
                <pre style="white-space:pre-wrap;max-height:220px;overflow:auto;background:#f6f7f7;padding:10px;border:1px solid #ddd;"><?php echo esc_html( (string) ( $release->changelog ?: '-' ) ); ?></pre>

                <p>
                    <a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=bt_admin_download_release&release_id=' . absint( $release->id ) ), 'bt_admin_download_release_' . absint( $release->id ) ) ); ?>">
                        <?php esc_html_e( 'Download/Test ZIP (Admin)', 'bangla-track-server' ); ?>
                    </a>
                </p>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * Render admin notices from query args.
     *
     * @return void
     */
    private function render_notices() {
        $notice_type = sanitize_key( (string) wp_unslash( $_GET['bt_release_notice'] ?? '' ) );
        $message = sanitize_text_field( (string) wp_unslash( $_GET['bt_release_message'] ?? '' ) );

        if ( empty( $notice_type ) || empty( $message ) ) {
            return;
        }

        $class = 'notice notice-info';
        if ( 'success' === $notice_type ) {
            $class = 'notice notice-success';
        } elseif ( 'error' === $notice_type ) {
            $class = 'notice notice-error';
        }

        echo '<div class="' . esc_attr( $class ) . ' is-dismissible"><p>' . esc_html( $message ) . '</p></div>';
    }

    /**
     * Redirect with notice.
     *
     * @param string $notice_type Notice type.
     * @param string $message Notice message.
     * @return void
     */
    private function redirect_with_notice( $notice_type, $message ) {
        wp_safe_redirect(
            add_query_arg(
                array(
                    'page' => self::PAGE_SLUG,
                    'bt_release_notice' => sanitize_key( (string) $notice_type ),
                    'bt_release_message' => sanitize_text_field( (string) $message ),
                ),
                admin_url( 'admin.php' )
            )
        );
        exit;
    }

    /**
     * Stream file download.
     *
     * @param string $file_path File path.
     * @param string $file_name File name.
     * @return void
     */
    private function stream_file_download( $file_path, $file_name ) {
        if ( ! file_exists( $file_path ) || ! is_readable( $file_path ) ) {
            wp_die( esc_html__( 'Release file not found or not readable.', 'bangla-track-server' ) );
        }

        nocache_headers();
        header( 'Content-Description: File Transfer' );
        header( 'Content-Type: application/zip' );
        header( 'Content-Disposition: attachment; filename="' . basename( $file_name ) . '"' );
        header( 'Content-Length: ' . (string) filesize( $file_path ) );
        header( 'X-Content-Type-Options: nosniff' );

        while ( ob_get_level() ) {
            ob_end_clean();
        }

        readfile( $file_path );
        exit;
    }

    /**
     * Format datetime for admin display.
     *
     * @param string $date Date time string.
     * @return string
     */
    private function format_datetime( $date ) {
        $timestamp = strtotime( (string) $date );
        if ( ! $timestamp ) {
            return '-';
        }
        return wp_date( 'M j, Y g:i a', $timestamp, wp_timezone() );
    }
}
