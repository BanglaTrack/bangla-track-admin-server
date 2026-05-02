<?php
/**
 * Licenses Page for Bangla Track Admin Server.
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
 * Class LicensesPage
 */
class LicensesPage {

    /**
     * License repository.
     *
     * @var LicenseRepository
     */
    private $repo;

    /**
     * Constructor.
     */
    public function __construct() {
        $this->repo = new LicenseRepository();
        add_action( 'admin_init', array( $this, 'handle_actions' ) );
    }

    /**
     * Handle form actions.
     *
     * @return void
     */
    public function handle_actions() {
        if ( ! isset( $_GET['page'] ) || 'bt-server-licenses' !== $_GET['page'] ) {
            return;
        }

        if ( isset( $_POST['bt_create_license'] ) && check_admin_referer( 'bt_create_license' ) ) {
            $data = array(
                'customer_email'  => sanitize_email( wp_unslash( $_POST['customer_email'] ?? '' ) ),
                'customer_name'   => sanitize_text_field( wp_unslash( $_POST['customer_name'] ?? '' ) ),
                'max_activations' => absint( $_POST['max_activations'] ?? 1 ),
                'expires_at'      => ! empty( $_POST['expires_at'] ) ? sanitize_text_field( wp_unslash( $_POST['expires_at'] ) ) : null,
            );

            $id = $this->repo->create( $data );
            if ( $id ) {
                wp_safe_redirect( add_query_arg( array( 'created' => $id ), admin_url( 'admin.php?page=bt-server-licenses' ) ) );
                exit;
            }
        }

        if ( isset( $_GET['action'] ) && 'revoke' === $_GET['action'] && isset( $_GET['id'] ) ) {
            if ( check_admin_referer( 'bt_revoke_license' ) ) {
                $this->repo->update( absint( $_GET['id'] ), array( 'status' => 'revoked' ) );
                wp_safe_redirect( admin_url( 'admin.php?page=bt-server-licenses&revoked=1' ) );
                exit;
            }
        }

        if ( isset( $_GET['action'] ) && 'activate' === $_GET['action'] && isset( $_GET['id'] ) ) {
            if ( check_admin_referer( 'bt_activate_license' ) ) {
                $this->repo->update( absint( $_GET['id'] ), array( 'status' => 'active' ) );
                wp_safe_redirect( admin_url( 'admin.php?page=bt-server-licenses&activated=1' ) );
                exit;
            }
        }
    }

    /**
     * Render the licenses page.
     *
     * @return void
     */
    public function render() {
        $action = isset( $_GET['action'] ) ? sanitize_text_field( wp_unslash( $_GET['action'] ) ) : 'list';

        if ( 'new' === $action ) {
            $this->render_new_form();
            return;
        }

        $this->render_list();
    }

    /**
     * Render the new license form.
     *
     * @return void
     */
    private function render_new_form() {
        ?>
        <div class="wrap bt-server-licenses">
            <h1><?php esc_html_e( 'Generate New License', 'bangla-track-server' ); ?></h1>

            <div class="bt-server-card">
                <form method="post" action="">
                    <?php wp_nonce_field( 'bt_create_license' ); ?>

                    <table class="form-table">
                        <tr>
                            <th><label for="customer_name"><?php esc_html_e( 'Customer Name', 'bangla-track-server' ); ?></label></th>
                            <td><input type="text" id="customer_name" name="customer_name" class="regular-text"></td>
                        </tr>
                        <tr>
                            <th><label for="customer_email"><?php esc_html_e( 'Customer Email', 'bangla-track-server' ); ?></label></th>
                            <td><input type="email" id="customer_email" name="customer_email" class="regular-text"></td>
                        </tr>
                        <tr>
                            <th><label for="max_activations"><?php esc_html_e( 'Max Activations', 'bangla-track-server' ); ?></label></th>
                            <td>
                                <input type="number" id="max_activations" name="max_activations" value="1" min="1" max="100" class="small-text">
                                <p class="description"><?php esc_html_e( 'Number of sites this license can be activated on.', 'bangla-track-server' ); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th><label for="expires_at"><?php esc_html_e( 'Expires On', 'bangla-track-server' ); ?></label></th>
                            <td>
                                <input type="date" id="expires_at" name="expires_at" class="regular-text">
                                <p class="description"><?php esc_html_e( 'Leave empty for lifetime license.', 'bangla-track-server' ); ?></p>
                            </td>
                        </tr>
                    </table>

                    <p class="submit">
                        <button type="submit" name="bt_create_license" class="button button-primary">
                            <?php esc_html_e( 'Generate License Key', 'bangla-track-server' ); ?>
                        </button>
                        <a href="<?php echo esc_url( admin_url( 'admin.php?page=bt-server-licenses' ) ); ?>" class="button">
                            <?php esc_html_e( 'Cancel', 'bangla-track-server' ); ?>
                        </a>
                    </p>
                </form>
            </div>
        </div>
        <?php
    }

    /**
     * Render the licenses list.
     *
     * @return void
     */
    private function render_list() {
        $licenses = $this->repo->get_all( array( 'limit' => 50 ) );
        $activation_repo = new ActivationRepository();

        if ( isset( $_GET['created'] ) ) : ?>
            <div class="notice notice-success is-dismissible">
                <p>
                    <?php esc_html_e( 'License created successfully:', 'bangla-track-server' ); ?>
                    <code><?php echo esc_html( $this->repo->get_by_id( absint( $_GET['created'] ) )->license_key ?? '' ); ?></code>
                </p>
            </div>
        <?php endif; ?>

        <div class="wrap bt-server-licenses">
            <h1>
                <?php esc_html_e( 'Licenses', 'bangla-track-server' ); ?>
                <a href="<?php echo esc_url( admin_url( 'admin.php?page=bt-server-licenses&action=new' ) ); ?>" class="page-title-action">
                    <?php esc_html_e( 'Add New', 'bangla-track-server' ); ?>
                </a>
            </h1>

            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th><?php esc_html_e( 'License Key', 'bangla-track-server' ); ?></th>
                        <th><?php esc_html_e( 'Customer', 'bangla-track-server' ); ?></th>
                        <th><?php esc_html_e( 'Status', 'bangla-track-server' ); ?></th>
                        <th><?php esc_html_e( 'Activations', 'bangla-track-server' ); ?></th>
                        <th><?php esc_html_e( 'Expires', 'bangla-track-server' ); ?></th>
                        <th><?php esc_html_e( 'Actions', 'bangla-track-server' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ( empty( $licenses ) ) : ?>
                        <tr>
                            <td colspan="6"><?php esc_html_e( 'No licenses found.', 'bangla-track-server' ); ?></td>
                        </tr>
                    <?php else : ?>
                        <?php foreach ( $licenses as $license ) : 
                            $active_count = $activation_repo->get_active_count( $license->id );
                        ?>
                            <tr>
                                <td><code><?php echo esc_html( $license->license_key ); ?></code></td>
                                <td>
                                    <?php if ( $license->customer_name ) : ?>
                                        <?php echo esc_html( $license->customer_name ); ?><br>
                                    <?php endif; ?>
                                    <small><?php echo esc_html( $license->customer_email ?: '—' ); ?></small>
                                </td>
                                <td>
                                    <span class="bt-status bt-status-<?php echo esc_attr( $license->status ); ?>">
                                        <?php echo esc_html( ucfirst( $license->status ) ); ?>
                                    </span>
                                </td>
                                <td><?php echo esc_html( $active_count . ' / ' . $license->max_activations ); ?></td>
                                <td><?php echo $license->expires_at ? esc_html( date_i18n( 'M j, Y', strtotime( $license->expires_at ) ) ) : '—'; ?></td>
                                <td>
                                    <?php if ( 'active' === $license->status ) : ?>
                                        <a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin.php?page=bt-server-licenses&action=revoke&id=' . $license->id ), 'bt_revoke_license' ) ); ?>" class="button button-small" onclick="return confirm('<?php esc_attr_e( 'Revoke this license?', 'bangla-track-server' ); ?>');">
                                            <?php esc_html_e( 'Revoke', 'bangla-track-server' ); ?>
                                        </a>
                                    <?php else : ?>
                                        <a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin.php?page=bt-server-licenses&action=activate&id=' . $license->id ), 'bt_activate_license' ) ); ?>" class="button button-small button-primary">
                                            <?php esc_html_e( 'Activate', 'bangla-track-server' ); ?>
                                        </a>
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
