<?php
namespace BanglaTrackServer\REST;

use BanglaTrackServer\Database\PluginReleaseRepository;
use BanglaTrackServer\Services\ReleaseDownloadPermissionService;
use WP_REST_Controller;
use WP_REST_Request;
use WP_REST_Server;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class ReleaseDownloadController extends WP_REST_Controller {
    /**
     * REST namespace.
     *
     * @var string
     */
    protected $namespace = 'bt-server/v1';

    /**
     * Release repository.
     *
     * @var PluginReleaseRepository
     */
    private $release_repo;

    /**
     * Download permission service.
     *
     * @var ReleaseDownloadPermissionService
     */
    private $permission_service;

    /**
     * Constructor.
     */
    public function __construct() {
        $this->release_repo = new PluginReleaseRepository();
        $this->permission_service = new ReleaseDownloadPermissionService();
    }

    /**
     * Register download endpoints.
     *
     * @return void
     */
    public function register_routes() {
        register_rest_route(
            $this->namespace,
            '/download/free',
            array(
                'methods' => WP_REST_Server::READABLE,
                'callback' => array( $this, 'download_free' ),
                'permission_callback' => array( $this, 'require_logged_in_user' ),
            )
        );

        register_rest_route(
            $this->namespace,
            '/download/pro',
            array(
                'methods' => WP_REST_Server::READABLE,
                'callback' => array( $this, 'download_pro' ),
                'permission_callback' => array( $this, 'require_logged_in_user' ),
            )
        );
    }

    /**
     * Require logged in user.
     *
     * @return bool
     */
    public function require_logged_in_user() {
        return is_user_logged_in();
    }

    /**
     * Download free plugin release.
     *
     * @param WP_REST_Request $request Request.
     * @return mixed
     */
    public function download_free( WP_REST_Request $request ) {
        unset( $request );

        $user_id = get_current_user_id();
        if ( ! $this->permission_service->can_download_free( $user_id ) ) {
            return new \WP_Error( 'bt_forbidden', __( 'You are not allowed to download the Free plugin.', 'bangla-track-server' ), array( 'status' => 403 ) );
        }

        return $this->stream_active_release( 'free' );
    }

    /**
     * Download pro plugin release.
     *
     * @param WP_REST_Request $request Request.
     * @return mixed
     */
    public function download_pro( WP_REST_Request $request ) {
        unset( $request );

        $user_id = get_current_user_id();
        if ( ! $this->permission_service->can_download_pro( $user_id ) ) {
            return new \WP_Error( 'bt_forbidden', __( 'Pro plugin is available with Starter or Pro licenses.', 'bangla-track-server' ), array( 'status' => 403 ) );
        }

        return $this->stream_active_release( 'pro' );
    }

    /**
     * Stream active release file.
     *
     * @param string $plugin_type Plugin type.
     * @return mixed
     */
    private function stream_active_release( $plugin_type ) {
        $release = $this->release_repo->get_active_by_type( $plugin_type );
        if ( ! $release ) {
            return new \WP_Error( 'release_not_found', __( 'No active plugin release found for this type.', 'bangla-track-server' ), array( 'status' => 404 ) );
        }

        $file_path = (string) $release->file_path;
        if ( empty( $file_path ) || ! file_exists( $file_path ) || ! is_readable( $file_path ) ) {
            return new \WP_Error( 'release_file_missing', __( 'Release file is missing or not readable.', 'bangla-track-server' ), array( 'status' => 404 ) );
        }

        $download_name = ! empty( $release->file_name ) ? (string) $release->file_name : basename( $file_path );

        nocache_headers();
        header( 'Content-Description: File Transfer' );
        header( 'Content-Type: application/zip' );
        header( 'Content-Disposition: attachment; filename="' . sanitize_file_name( $download_name ) . '"' );
        header( 'Content-Length: ' . (string) filesize( $file_path ) );
        header( 'X-Content-Type-Options: nosniff' );

        while ( ob_get_level() ) {
            ob_end_clean();
        }

        readfile( $file_path );
        exit;
    }
}