<?php
/**
 * Activations Page for Bangla Track Admin Server.
 *
 * @package BanglaTrackServer\Admin
 */

namespace BanglaTrackServer\Admin;

use BanglaTrackServer\Database\ActivationRepository;

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

    /**
     * Constructor.
     */
    public function __construct() {
        $this->repo = new ActivationRepository();
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
                    </tr>
                </thead>
                <tbody>
                    <?php if ( empty( $activations ) ) : ?>
                        <tr>
                            <td colspan="6"><?php esc_html_e( 'No activations found.', 'bangla-track-server' ); ?></td>
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
                                    if ( $activation->last_check ) {
                                        echo esc_html( human_time_diff( strtotime( $activation->last_check ), current_time( 'timestamp' ) ) ) . ' ago';
                                    } else {
                                        echo '—';
                                    }
                                    ?>
                                </td>
                                <td>
                                    <?php if ( $activation->is_active ) : ?>
                                        <span class="bt-status bt-status-active"><?php esc_html_e( 'Active', 'bangla-track-server' ); ?></span>
                                    <?php else : ?>
                                        <span class="bt-status bt-status-revoked"><?php esc_html_e( 'Inactive', 'bangla-track-server' ); ?></span>
                                    <?php endif; ?>
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
