<?php
/**
 * Activations Page for Bangla Track Admin Server.
 *
 * @package BanglaTrackServer\Admin
 */

namespace BanglaTrackServer\Admin;

use BanglaTrackServer\Database\ActivationRepository;
use BanglaTrackServer\Database\ProviderLockRepository;
use BanglaTrackServer\Database\UsageRepository;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class ActivationsPage
 */
class ActivationsPage {

    /**
     * Activation repository.
     *
     * @var ActivationRepository
     */
    private $repo;
    private $usage_repo;
    private $lock_repo;

    /**
     * Constructor.
     */
    public function __construct() {
        $this->repo = new ActivationRepository();
        $this->usage_repo = new UsageRepository();
        $this->lock_repo  = new ProviderLockRepository();
        add_action( 'admin_init', array( $this, 'handle_actions' ) );
    }

    /**
     * Handle reset lock action.
     *
     * @return void
     */
    public function handle_actions() {
        if ( ! isset( $_GET['page'] ) || 'bt-server-activations' !== $_GET['page'] ) {
            return;
        }

        if ( isset( $_GET['action'] ) && 'reset_lock' === $_GET['action'] && check_admin_referer( 'bt_reset_provider_lock' ) ) {
            $activation_id = absint( $_GET['activation_id'] ?? 0 );
            $license_id    = absint( $_GET['license_id'] ?? 0 );
            if ( $activation_id > 0 && $license_id > 0 ) {
                $this->lock_repo->reset_lock( $license_id, $activation_id );
            }
            wp_safe_redirect( admin_url( 'admin.php?page=bt-server-activations&lock_reset=1' ) );
            exit;
        }
    }

    /**
     * Render the activations page.
     *
     * @return void
     */
    public function render() {
        $is_active = isset( $_GET['status'] ) && 'inactive' === $_GET['status'] ? 0 : null;
        $activations = $this->repo->get_all( array( 'limit' => 50, 'is_active' => $is_active ) );
        ?>
        <div class="wrap bt-server-activations">
            <h1><?php esc_html_e( 'Site Activations', 'bangla-track-server' ); ?></h1>
            <?php if ( isset( $_GET['lock_reset'] ) ) : ?>
                <div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Provider lock reset.', 'bangla-track-server' ); ?></p></div>
            <?php endif; ?>

            <ul class="subsubsub">
                <li>
                    <a href="<?php echo esc_url( admin_url( 'admin.php?page=bt-server-activations' ) ); ?>" 
                       class="<?php echo ! isset( $_GET['status'] ) ? 'current' : ''; ?>">
                        <?php esc_html_e( 'All', 'bangla-track-server' ); ?>
                        <span class="count">(<?php echo esc_html( $this->repo->get_count() ); ?>)</span>
                    </a> |
                </li>
                <li>
                    <a href="<?php echo esc_url( admin_url( 'admin.php?page=bt-server-activations&status=inactive' ) ); ?>"
                       class="<?php echo isset( $_GET['status'] ) && 'inactive' === $_GET['status'] ? 'current' : ''; ?>">
                        <?php esc_html_e( 'Inactive', 'bangla-track-server' ); ?>
                        <span class="count">(<?php echo esc_html( $this->repo->get_count( 0 ) ); ?>)</span>
                    </a>
                </li>
            </ul>

            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th><?php esc_html_e( 'Site', 'bangla-track-server' ); ?></th>
                        <th><?php esc_html_e( 'License', 'bangla-track-server' ); ?></th>
                        <th><?php esc_html_e( 'Customer', 'bangla-track-server' ); ?></th>
                        <th><?php esc_html_e( 'Environment', 'bangla-track-server' ); ?></th>
                        <th><?php esc_html_e( 'Last Check', 'bangla-track-server' ); ?></th>
                        <th><?php esc_html_e( 'Status', 'bangla-track-server' ); ?></th>
                        <th><?php esc_html_e( 'Usage (Month)', 'bangla-track-server' ); ?></th>
                        <th><?php esc_html_e( 'Locked Provider', 'bangla-track-server' ); ?></th>
                        <th><?php esc_html_e( 'Actions', 'bangla-track-server' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ( empty( $activations ) ) : ?>
                        <tr>
                            <td colspan="9"><?php esc_html_e( 'No activations found.', 'bangla-track-server' ); ?></td>
                        </tr>
                    <?php else : ?>
                        <?php foreach ( $activations as $activation ) : ?>
                            <tr>
                                <td>
                                    <strong><?php echo esc_html( $activation->site_name ?: $activation->site_url ); ?></strong>
                                    <br>
                                    <a href="<?php echo esc_url( 'https://' . $activation->site_url ); ?>" target="_blank" rel="noopener">
                                        <?php echo esc_html( $activation->site_url ); ?>
                                        <span class="dashicons dashicons-external"></span>
                                    </a>
                                </td>
                                <td><code><?php echo esc_html( $activation->license_key ); ?></code></td>
                                <td>
                                    <?php echo esc_html( $activation->customer_name ?: '—' ); ?>
                                    <?php if ( $activation->customer_email ) : ?>
                                        <br><small><?php echo esc_html( $activation->customer_email ); ?></small>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <small>
                                        WP <?php echo esc_html( $activation->wp_version ?: '—' ); ?><br>
                                        PHP <?php echo esc_html( $activation->php_version ?: '—' ); ?><br>
                                        Pro <?php echo esc_html( $activation->plugin_version ?: '—' ); ?>
                                    </small>
                                </td>
                                <td>
                                    <?php 
                                    if ( $activation->last_seen_at ) {
                                        echo esc_html( human_time_diff( strtotime( $activation->last_seen_at ), current_time( 'timestamp' ) ) ) . ' ago';
                                    } else {
                                        echo '—';
                                    }
                                    ?>
                                </td>
                            <td>
                                    <?php if ( 'active' === $activation->status ) : ?>
                                        <span class="bt-status bt-status-active"><?php esc_html_e( 'Active', 'bangla-track-server' ); ?></span>
                                    <?php else : ?>
                                        <span class="bt-status bt-status-revoked"><?php esc_html_e( 'Inactive', 'bangla-track-server' ); ?></span>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo esc_html( $this->usage_repo->count_for_activation( $activation->id, gmdate( 'Y-m' ) ) ); ?></td>
                                <td><?php echo esc_html( $this->lock_repo->get_locked_provider( $activation->license_id, $activation->id ) ?: '-' ); ?></td>
                                <td>
                                    <a class="button button-small" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin.php?page=bt-server-activations&action=reset_lock&license_id=' . $activation->license_id . '&activation_id=' . $activation->id ), 'bt_reset_provider_lock' ) ); ?>">
                                        <?php esc_html_e( 'Reset Lock', 'bangla-track-server' ); ?>
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php
    }
}
