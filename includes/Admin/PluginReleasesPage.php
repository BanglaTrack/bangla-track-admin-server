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

        $active_release = $this->release_repo->get_active_by_type( 'free' );
        $all_releases = $this->release_repo->get_by_type( 'free', 30 );
        $storage = $this->storage_service->ensure_storage_directory();

        $this->render_notices();
        ?>
        <div class="wrap bt-server-plugin-releases">
            <h1 class="wp-heading-inline"><?php esc_html_e( 'Plugin Releases', 'bangla-track-server' ); ?></h1>
            <hr class="wp-header-end">

            <!-- Storage Info & Description Header -->
            <div class="bt-releases-header" style="display: flex; gap: 20px; align-items: stretch; margin: 20px 0;">
                <div class="bt-server-card" style="flex: 1; display: flex; flex-direction: column; justify-content: center; border-left: 4px solid #3b82f6; background: #fff; box-shadow: 0 1px 3px rgba(0,0,0,0.05);">
                    <p style="font-size: 14px; line-height: 1.5; color: #475569; margin: 0;">
                        <?php esc_html_e( 'Manage the distribution packages for the Bangla Track plugin. Upload a new zip package to automatically parse version information, replace the currently active release, and populate the release history log.', 'bangla-track-server' ); ?>
                    </p>
                </div>
                <div class="bt-server-card" style="flex: 1; background: #fff; box-shadow: 0 1px 3px rgba(0,0,0,0.05); display: grid; grid-template-columns: 1fr 1fr; gap: 10px; font-size: 12px; color: #475569;">
                    <div><strong><?php esc_html_e( 'Storage Location:', 'bangla-track-server' ); ?></strong> <span style="display:block; font-family: monospace; background: #f1f5f9; padding: 2px 6px; border-radius: 4px; margin-top: 3px;"><?php echo esc_html( $storage['location'] ?? '' ); ?></span></div>
                    <div><strong><?php esc_html_e( 'Storage Path:', 'bangla-track-server' ); ?></strong> <span style="display:block; font-family: monospace; background: #f1f5f9; padding: 2px 6px; border-radius: 4px; margin-top: 3px; word-break: break-all;"><?php echo esc_html( (string) ( $storage['path'] ?? '' ) ); ?></span></div>
                    <div><strong><?php esc_html_e( 'Folder Writable:', 'bangla-track-server' ); ?></strong> <span style="display:block; margin-top: 3px;"><?php echo ! empty( $storage['writable'] ) ? '<span style="color:#10b981; font-weight:600;">Yes</span>' : '<span style="color:#ef4444; font-weight:600;">No</span>'; ?></span></div>
                    <div><strong><?php esc_html_e( 'Folder Protected:', 'bangla-track-server' ); ?></strong> <span style="display:block; margin-top: 3px;"><?php echo ! empty( $storage['protected'] ) ? '<span style="color:#10b981; font-weight:600;">Yes</span>' : '<span style="color:#ef4444; font-weight:600;">No</span>'; ?></span></div>
                </div>
            </div>

            <!-- Upload & Active Release Details -->
            <div style="display:grid; grid-template-columns: 1fr 2fr; gap:20px; margin-bottom: 30px;">
                <!-- Upload form card -->
                <div class="bt-server-card" style="background:#fff; box-shadow: 0 1px 3px rgba(0,0,0,0.05); border-radius: 8px;">
                    <h2 style="font-size: 16px; font-weight: 600; color: #1e293b; margin-bottom: 20px; border-bottom: 1px solid #f1f5f9; padding-bottom: 10px;">
                        <?php esc_html_e( 'Upload New Release', 'bangla-track-server' ); ?>
                    </h2>
                    <form method="post" enctype="multipart/form-data">
                        <input type="hidden" name="plugin_type" value="free" />
                        <input type="hidden" name="bt_upload_plugin_release" value="1" />
                        <?php wp_nonce_field( 'bt_upload_plugin_release_free' ); ?>

                        <div style="margin-bottom: 20px;">
                            <label for="bt_plugin_zip" style="display: block; font-weight: 600; margin-bottom: 8px; color: #475569;">
                                <?php esc_html_e( 'Select Plugin ZIP File', 'bangla-track-server' ); ?>
                            </label>
                            <input type="file" id="bt_plugin_zip" name="plugin_zip" accept=".zip" required style="width: 100%; padding: 8px; border: 1px dashed #cbd5e1; border-radius: 6px; background: #f8fafc;" />
                            <p class="description" style="margin-top: 6px;">
                                <?php esc_html_e( 'Upload the plugin ZIP archive. Metadata (version, requires WP/PHP, description) will be parsed automatically.', 'bangla-track-server' ); ?>
                            </p>
                        </div>

                        <button type="submit" class="button button-primary button-large" style="width: 100%; justify-content: center; display: flex; align-items: center; height: 40px; font-size: 14px;">
                            <span class="dashicons dashicons-upload" style="margin-right: 5px; margin-top: 3px;"></span>
                            <?php esc_html_e( 'Publish Release', 'bangla-track-server' ); ?>
                        </button>
                    </form>
                </div>

                <!-- Active Release details card -->
                <div class="bt-server-card" style="background:#fff; box-shadow: 0 1px 3px rgba(0,0,0,0.05); border-radius: 8px;">
                    <h2 style="font-size: 16px; font-weight: 600; color: #1e293b; margin-bottom: 20px; border-bottom: 1px solid #f1f5f9; padding-bottom: 10px;">
                        <?php esc_html_e( 'Active Release Details', 'bangla-track-server' ); ?>
                    </h2>

                    <?php if ( ! $active_release ) : ?>
                        <div style="text-align: center; padding: 40px 20px; color: #94a3b8;">
                            <span class="dashicons dashicons-warning" style="font-size: 48px; width: 48px; height: 48px; margin-bottom: 10px;"></span>
                            <p style="font-size: 15px; margin: 0; font-weight: 500;"><?php esc_html_e( 'No active release published yet.', 'bangla-track-server' ); ?></p>
                        </div>
                    <?php else : ?>
                        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px; font-size: 13px;">
                            <div>
                                <p style="margin: 0 0 8px;"><strong><?php esc_html_e( 'Plugin Name:', 'bangla-track-server' ); ?></strong><br><span style="color:#334155; font-size: 14px; font-weight: 500;"><?php echo esc_html( (string) $active_release->plugin_name ); ?></span></p>
                                <p style="margin: 0 0 8px;"><strong><?php esc_html_e( 'Active Version:', 'bangla-track-server' ); ?></strong><br><span style="color:#0f172a; font-size: 16px; font-weight: 700; background: #e0f2fe; color: #0369a1; padding: 2px 8px; border-radius: 4px; display: inline-block; margin-top: 2px;"><?php echo esc_html( (string) $active_release->version ); ?></span></p>
                                <p style="margin: 0 0 8px;"><strong><?php esc_html_e( 'File Name:', 'bangla-track-server' ); ?></strong><br><code style="font-size: 11px; background: #f1f5f9; padding: 2px 6px; border-radius: 4px; display: inline-block; margin-top: 2px;"><?php echo esc_html( (string) $active_release->file_name ); ?></code></p>
                                <p style="margin: 0 0 8px;"><strong><?php esc_html_e( 'File Size:', 'bangla-track-server' ); ?></strong><br><span style="color:#334155;"><?php echo esc_html( size_format( (int) $active_release->file_size ) ); ?></span></p>
                                <p style="margin: 0 0 8px;"><strong><?php esc_html_e( 'Uploaded On:', 'bangla-track-server' ); ?></strong><br><span style="color:#334155;"><?php echo esc_html( $this->format_datetime( (string) $active_release->created_at ) ); ?></span></p>
                            </div>
                            <div>
                                <p style="margin: 0 0 8px;"><strong><?php esc_html_e( 'WordPress Required:', 'bangla-track-server' ); ?></strong><br><span style="color:#334155;"><?php echo esc_html( (string) ( $active_release->requires_wp ?: '-' ) ); ?></span></p>
                                <p style="margin: 0 0 8px;"><strong><?php esc_html_e( 'PHP Required:', 'bangla-track-server' ); ?></strong><br><span style="color:#334155;"><?php echo esc_html( (string) ( $active_release->requires_php ?: '-' ) ); ?></span></p>
                                <p style="margin: 0 0 8px;"><strong><?php esc_html_e( 'Text Domain:', 'bangla-track-server' ); ?></strong><br><code style="font-size: 11px;"><?php echo esc_html( (string) ( $active_release->text_domain ?: '-' ) ); ?></code></p>
                                <p style="margin: 0 0 8px;"><strong><?php esc_html_e( 'Uploaded By:', 'bangla-track-server' ); ?></strong><br><span style="color:#334155;">
                                    <?php 
                                    $uploader = get_userdata( $active_release->uploaded_by );
                                    echo esc_html( $uploader ? $uploader->display_name : '-' );
                                    ?>
                                </span></p>
                            </div>
                        </div>

                        <div style="margin-top: 15px;">
                            <strong><?php esc_html_e( 'Changelog:', 'bangla-track-server' ); ?></strong>
                            <pre style="white-space:pre-wrap; max-height:120px; overflow:auto; background:#f8fafc; padding:10px; border:1px solid #e2e8f0; border-radius: 6px; font-size:12px; margin-top: 6px; color:#475569;"><?php echo esc_html( (string) ( $active_release->changelog ?: '-' ) ); ?></pre>
                        </div>

                        <div style="margin-top: 15px; display: flex; gap: 10px;">
                            <a class="button button-secondary" style="height: 34px; line-height: 32px;" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=bt_admin_download_release&release_id=' . absint( $active_release->id ) ), 'bt_admin_download_release_' . absint( $active_release->id ) ) ); ?>">
                                <span class="dashicons dashicons-download" style="margin-top: 5px;"></span>
                                <?php esc_html_e( 'Download ZIP', 'bangla-track-server' ); ?>
                            </a>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Release History Log -->
            <div class="bt-server-card" style="background:#fff; box-shadow: 0 1px 3px rgba(0,0,0,0.05); border-radius: 8px;">
                <h2 style="font-size: 16px; font-weight: 600; color: #1e293b; margin-bottom: 20px; border-bottom: 1px solid #f1f5f9; padding-bottom: 10px;">
                    <?php esc_html_e( 'Release History Log', 'bangla-track-server' ); ?>
                </h2>

                <?php if ( empty( $all_releases ) ) : ?>
                    <p style="color: #64748b; font-style: italic; text-align: center; padding: 20px;"><?php esc_html_e( 'No releases uploaded yet.', 'bangla-track-server' ); ?></p>
                <?php else : ?>
                    <table class="wp-list-table widefat fixed striped table-view-list" style="border: 0; box-shadow: none;">
                        <thead>
                            <tr>
                                <th style="font-weight: 600; color: #334155; width: 10%;"><?php esc_html_e( 'Version', 'bangla-track-server' ); ?></th>
                                <th style="font-weight: 600; color: #334155; width: 25%;"><?php esc_html_e( 'File Name', 'bangla-track-server' ); ?></th>
                                <th style="font-weight: 600; color: #334155; width: 12%;"><?php esc_html_e( 'File Size', 'bangla-track-server' ); ?></th>
                                <th style="font-weight: 600; color: #334155; width: 18%;"><?php esc_html_e( 'Requirements', 'bangla-track-server' ); ?></th>
                                <th style="font-weight: 600; color: #334155; width: 15%;"><?php esc_html_e( 'Uploaded Date', 'bangla-track-server' ); ?></th>
                                <th style="font-weight: 600; color: #334155; width: 10%;"><?php esc_html_e( 'Uploader', 'bangla-track-server' ); ?></th>
                                <th style="font-weight: 600; color: #334155; width: 10%; text-align: center;"><?php esc_html_e( 'Status', 'bangla-track-server' ); ?></th>
                                <th style="font-weight: 600; color: #334155; width: 10%; text-align: right;"><?php esc_html_e( 'Actions', 'bangla-track-server' ); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ( $all_releases as $rel ) : ?>
                                <tr>
                                    <td>
                                        <span style="font-weight: 700; color: #0f172a; font-size: 13px;"><?php echo esc_html( (string) $rel->version ); ?></span>
                                    </td>
                                    <td>
                                        <code style="font-size: 11px; background:#f1f5f9; padding: 2px 4px; border-radius: 4px;"><?php echo esc_html( (string) $rel->file_name ); ?></code>
                                    </td>
                                    <td><?php echo esc_html( size_format( (int) $rel->file_size ) ); ?></td>
                                    <td style="font-size: 12px; color: #64748b;">
                                        WP: <code><?php echo esc_html( $rel->requires_wp ?: '-' ); ?></code> | PHP: <code><?php echo esc_html( $rel->requires_php ?: '-' ); ?></code>
                                    </td>
                                    <td><?php echo esc_html( $this->format_datetime( (string) $rel->created_at ) ); ?></td>
                                    <td>
                                        <?php 
                                        $rel_uploader = get_userdata( $rel->uploaded_by );
                                        echo esc_html( $rel_uploader ? $rel_uploader->display_name : '-' );
                                        ?>
                                    </td>
                                    <td style="text-align: center;">
                                        <?php if ( ! empty( $rel->is_active ) ) : ?>
                                            <span style="background: #d1fae5; color: #065f46; font-size: 11px; font-weight: 600; padding: 2px 8px; border-radius: 9999px; text-transform: uppercase;"><?php esc_html_e( 'Active', 'bangla-track-server' ); ?></span>
                                        <?php else : ?>
                                            <span style="background: #f1f5f9; color: #475569; font-size: 11px; font-weight: 600; padding: 2px 8px; border-radius: 9999px; text-transform: uppercase;"><?php esc_html_e( 'Inactive', 'bangla-track-server' ); ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td style="text-align: right;">
                                        <a class="button button-small" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=bt_admin_download_release&release_id=' . absint( $rel->id ) ), 'bt_admin_download_release_' . absint( $rel->id ) ) ); ?>" title="<?php esc_attr_e( 'Download release file', 'bangla-track-server' ); ?>">
                                            <span class="dashicons dashicons-download" style="font-size: 15px; width: 15px; height: 15px; margin-top: 2px;"></span>
                                        </a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
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
