<?php
/**
 * Free Sites admin page for Bangla Track Admin Server.
 *
 * Lists all free-plan site registrations with telemetry data
 * and installed plugin information.
 *
 * @package BanglaTrackServer\Admin
 */

namespace BanglaTrackServer\Admin;

use BanglaTrackServer\Database\FreeSiteRepository;
use BanglaTrackServer\Database\SitePluginsRepository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class FreeSitesPage
 */
class FreeSitesPage {

	/**
	 * Free site repository.
	 *
	 * @var FreeSiteRepository
	 */
	private $repo;

	/**
	 * Site plugins repository.
	 *
	 * @var SitePluginsRepository
	 */
	private $plugins_repo;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->repo         = new FreeSiteRepository();
		$this->plugins_repo = new SitePluginsRepository();
	}

	/**
	 * Render the free sites page.
	 *
	 * @return void
	 */
	public function render() {
		$status = isset( $_GET['status'] ) ? sanitize_key( $_GET['status'] ) : '';
		$sites  = $this->repo->get_all( array(
			'status' => $status,
			'limit'  => 50,
		) );

		// Batch-load plugin counts for all visible sites.
		$site_ids      = array_map( function( $s ) { return (int) $s->id; }, $sites );
		$plugin_counts = $this->plugins_repo->get_counts_for_sites( 'free_site', $site_ids );

		$stats = $this->repo->get_stats();
		?>
		<div class="wrap bt-server-free-sites">
			<h1><?php esc_html_e( 'Free Sites', 'bangla-track-server' ); ?></h1>

			<div class="bt-server-stats-grid bt-server-stats-grid-small">
				<div class="bt-server-stat-card stat-info">
					<div class="stat-icon dashicons dashicons-admin-site-alt3"></div>
					<div class="stat-content">
						<span class="stat-number"><?php echo esc_html( $stats['total'] ); ?></span>
						<span class="stat-label"><?php esc_html_e( 'Total Free Sites', 'bangla-track-server' ); ?></span>
					</div>
				</div>
				<div class="bt-server-stat-card stat-success">
					<div class="stat-icon dashicons dashicons-yes-alt"></div>
					<div class="stat-content">
						<span class="stat-number"><?php echo esc_html( $stats['active'] ); ?></span>
						<span class="stat-label"><?php esc_html_e( 'Active', 'bangla-track-server' ); ?></span>
					</div>
				</div>
				<div class="bt-server-stat-card stat-warning">
					<div class="stat-icon dashicons dashicons-dismiss"></div>
					<div class="stat-content">
						<span class="stat-number"><?php echo esc_html( $stats['inactive'] ); ?></span>
						<span class="stat-label"><?php esc_html_e( 'Inactive', 'bangla-track-server' ); ?></span>
					</div>
				</div>
			</div>

			<ul class="subsubsub">
				<li>
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=bt-server-free-sites' ) ); ?>"
					   class="<?php echo empty( $status ) ? 'current' : ''; ?>">
						<?php esc_html_e( 'All', 'bangla-track-server' ); ?>
						<span class="count">(<?php echo esc_html( $stats['total'] ); ?>)</span>
					</a> |
				</li>
				<li>
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=bt-server-free-sites&status=active' ) ); ?>"
					   class="<?php echo 'active' === $status ? 'current' : ''; ?>">
						<?php esc_html_e( 'Active', 'bangla-track-server' ); ?>
						<span class="count">(<?php echo esc_html( $stats['active'] ); ?>)</span>
					</a> |
				</li>
				<li>
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=bt-server-free-sites&status=inactive' ) ); ?>"
					   class="<?php echo 'inactive' === $status ? 'current' : ''; ?>">
						<?php esc_html_e( 'Inactive', 'bangla-track-server' ); ?>
						<span class="count">(<?php echo esc_html( $stats['inactive'] ); ?>)</span>
					</a>
				</li>
			</ul>

			<table class="wp-list-table widefat fixed striped">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Site UUID', 'bangla-track-server' ); ?></th>
						<th><?php esc_html_e( 'Plugin Version', 'bangla-track-server' ); ?></th>
						<th><?php esc_html_e( 'Environment', 'bangla-track-server' ); ?></th>
						<th><?php esc_html_e( 'Providers', 'bangla-track-server' ); ?></th>
						<th><?php esc_html_e( 'Bookings', 'bangla-track-server' ); ?></th>
						<th><?php esc_html_e( 'Status', 'bangla-track-server' ); ?></th>
						<th><?php esc_html_e( 'Last Seen', 'bangla-track-server' ); ?></th>
						<th><?php esc_html_e( 'Installed Plugins', 'bangla-track-server' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php if ( empty( $sites ) ) : ?>
						<tr>
							<td colspan="8"><?php esc_html_e( 'No free sites found.', 'bangla-track-server' ); ?></td>
						</tr>
					<?php else : ?>
						<?php foreach ( $sites as $site ) : ?>
							<tr>
								<td>
									<code class="bt-uuid"><?php echo esc_html( $this->mask_uuid( $site->site_uuid ) ); ?></code>
									<?php if ( ! empty( $site->site_url_hash ) ) : ?>
										<br><small class="bt-url-hash" title="<?php echo esc_attr( $site->site_url_hash ); ?>"><?php echo esc_html( substr( $site->site_url_hash, 0, 20 ) ); ?>…</small>
									<?php endif; ?>
								</td>
								<td><?php echo esc_html( $site->plugin_version ?: '—' ); ?></td>
								<td>
									<small>
										WP <?php echo esc_html( $site->wp_version ?: '—' ); ?><br>
										PHP <?php echo esc_html( $site->php_version ?: '—' ); ?>
									</small>
								</td>
								<td><?php echo esc_html( $site->active_provider_count ); ?></td>
								<td><?php echo esc_html( $site->booking_count ); ?></td>
								<td>
									<?php if ( 'active' === $site->status ) : ?>
										<span class="bt-status bt-status-active"><?php esc_html_e( 'Active', 'bangla-track-server' ); ?></span>
									<?php else : ?>
										<span class="bt-status bt-status-revoked"><?php esc_html_e( 'Inactive', 'bangla-track-server' ); ?></span>
									<?php endif; ?>
								</td>
								<td>
									<?php
									if ( $site->last_seen_at ) {
										echo esc_html( human_time_diff( strtotime( $site->last_seen_at ), current_time( 'timestamp' ) ) ) . ' ago';
									} else {
										echo '—';
									}
									?>
								</td>
								<td>
									<?php
									$count = isset( $plugin_counts[ (int) $site->id ] ) ? (int) $plugin_counts[ (int) $site->id ] : 0;
									if ( $count > 0 ) :
										$site_plugins = $this->plugins_repo->get_plugins_for_site( 'free_site', (int) $site->id );
									?>
										<details class="bt-plugin-details">
											<summary><?php echo esc_html( $count ); ?> <?php esc_html_e( 'plugins', 'bangla-track-server' ); ?></summary>
											<ul class="bt-plugin-list">
												<?php foreach ( $site_plugins as $sp ) : ?>
													<li>
														<?php if ( $sp->is_active ) : ?>
															<span class="bt-plugin-active" title="<?php esc_attr_e( 'Active', 'bangla-track-server' ); ?>">●</span>
														<?php else : ?>
															<span class="bt-plugin-inactive" title="<?php esc_attr_e( 'Inactive', 'bangla-track-server' ); ?>">○</span>
														<?php endif; ?>
														<?php echo esc_html( $sp->plugin_name ); ?>
														<small><?php echo esc_html( $sp->plugin_version ); ?></small>
													</li>
												<?php endforeach; ?>
											</ul>
										</details>
									<?php else : ?>
										<span class="bt-no-plugin-data">—</span>
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

	/**
	 * Mask a UUID for display (show first 8 + last 4 characters).
	 *
	 * @param string $uuid Full UUID.
	 * @return string Masked UUID.
	 */
	private function mask_uuid( $uuid ) {
		$uuid = (string) $uuid;
		if ( strlen( $uuid ) <= 12 ) {
			return $uuid;
		}
		return substr( $uuid, 0, 8 ) . '…' . substr( $uuid, -4 );
	}
}
