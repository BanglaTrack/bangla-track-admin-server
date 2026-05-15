<?php
/**
 * Dashboard page for Bangla Track Admin Server.
 *
 * @package BanglaTrackServer\Admin
 */

namespace BanglaTrackServer\Admin;

use BanglaTrackServer\Database\LicenseRepository;
use BanglaTrackServer\Database\ActivationRepository;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class Dashboard
 */
class Dashboard {

    /**
     * Render the dashboard page.
     *
     * @return void
     */
    public function render() {
        $license_repo    = new LicenseRepository();
        $activation_repo = new ActivationRepository();

        $license_stats    = $license_repo->get_stats();
        $activation_stats = $activation_repo->get_stats();
        $recent_activations = $activation_repo->get_all( array( 'limit' => 5, 'is_active' => 1 ) );
        ?>
        <div class="wrap bt-server-dashboard">
            <h1><?php esc_html_e( 'Bangla Track Admin Server', 'bangla-track-server' ); ?></h1>

            <div class="bt-server-stats-grid">
                <div class="bt-server-stat-card">
                    <div class="stat-icon dashicons dashicons-admin-network"></div>
                    <div class="stat-content">
                        <span class="stat-number"><?php echo esc_html( $license_stats['total'] ); ?></span>
                        <span class="stat-label"><?php esc_html_e( 'Total Licenses', 'bangla-track-server' ); ?></span>
                    </div>
                </div>

                <div class="bt-server-stat-card stat-success">
                    <div class="stat-icon dashicons dashicons-yes-alt"></div>
                    <div class="stat-content">
                        <span class="stat-number"><?php echo esc_html( $license_stats['active'] ); ?></span>
                        <span class="stat-label"><?php esc_html_e( 'Active Licenses', 'bangla-track-server' ); ?></span>
                    </div>
                </div>

                <div class="bt-server-stat-card stat-warning">
                    <div class="stat-icon dashicons dashicons-clock"></div>
                    <div class="stat-content">
                        <span class="stat-number"><?php echo esc_html( $license_stats['expired'] ); ?></span>
                        <span class="stat-label"><?php esc_html_e( 'Expired', 'bangla-track-server' ); ?></span>
                    </div>
                </div>

                <div class="bt-server-stat-card stat-info">
                    <div class="stat-icon dashicons dashicons-admin-site-alt3"></div>
                    <div class="stat-content">
                        <span class="stat-number"><?php echo esc_html( $activation_stats['active'] ); ?></span>
                        <span class="stat-label"><?php esc_html_e( 'Active Sites', 'bangla-track-server' ); ?></span>
                    </div>
                </div>
            </div>

            <div class="bt-server-dashboard-row">
                <div class="bt-server-card bt-server-recent-activations">
                    <h2><?php esc_html_e( 'Recent Activations', 'bangla-track-server' ); ?></h2>
                    <?php if ( empty( $recent_activations ) ) : ?>
                        <p class="bt-server-no-data"><?php esc_html_e( 'No activations yet.', 'bangla-track-server' ); ?></p>
                    <?php else : ?>
                        <table class="wp-list-table widefat fixed striped">
                            <thead>
                                <tr>
                                    <th><?php esc_html_e( 'Site', 'bangla-track-server' ); ?></th>
                                    <th><?php esc_html_e( 'License', 'bangla-track-server' ); ?></th>
                                    <th><?php esc_html_e( 'Activated', 'bangla-track-server' ); ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ( $recent_activations as $activation ) : ?>
                                    <tr>
                                        <td>
                                            <strong><?php echo esc_html( $activation->site_name ?: $activation->site_url ); ?></strong>
                                            <br><small><?php echo esc_html( $activation->site_url ); ?></small>
                                        </td>
                                        <td><code><?php echo esc_html( $activation->license_key ); ?></code></td>
                                        <td><?php echo esc_html( human_time_diff( strtotime( $activation->activated_at ), current_time( 'timestamp' ) ) ); ?> ago</td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </div>

                <div class="bt-server-card bt-server-quick-actions">
                    <h2><?php esc_html_e( 'Quick Actions', 'bangla-track-server' ); ?></h2>
                    <a href="<?php echo esc_url( admin_url( 'admin.php?page=bt-server-licenses&action=new' ) ); ?>" class="button button-primary button-hero">
                        <span class="dashicons dashicons-plus-alt"></span>
                        <?php esc_html_e( 'Generate New License', 'bangla-track-server' ); ?>
                    </a>
                    <a href="<?php echo esc_url( admin_url( 'admin.php?page=bt-server-licenses' ) ); ?>" class="button button-secondary">
                        <?php esc_html_e( 'View All Licenses', 'bangla-track-server' ); ?>
                    </a>
                    <a href="<?php echo esc_url( admin_url( 'admin.php?page=bt-server-activations' ) ); ?>" class="button button-secondary">
                        <?php esc_html_e( 'View All Activations', 'bangla-track-server' ); ?>
                    </a>
                    <a href="<?php echo esc_url( admin_url( 'admin.php?page=bt-server-plugin-releases' ) ); ?>" class="button button-secondary">
                        <?php esc_html_e( 'Manage Plugin Releases', 'bangla-track-server' ); ?>
                    </a>

                    <hr>

                    <h3><?php esc_html_e( 'API Endpoint', 'bangla-track-server' ); ?></h3>
                    <p><code><?php echo esc_html( rest_url( 'bt-server/v1/' ) ); ?></code></p>
                </div>
            </div>
        </div>
        <?php
    }
}
