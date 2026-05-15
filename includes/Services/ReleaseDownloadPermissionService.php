<?php
namespace BanglaTrackServer\Services;

use BanglaTrackServer\Database\Installer;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class ReleaseDownloadPermissionService {

    /**
     * Check permission for free release download.
     *
     * @param int $user_id User ID.
     * @return bool
     */
    public function can_download_free( $user_id ) {
        $allowed_codes = array( 'free_forever', 'starter_monthly', 'starter_yearly', 'pro_monthly', 'pro_yearly' );
        return $this->user_has_product_code( $user_id, $allowed_codes );
    }

    /**
     * Check permission for pro release download.
     *
     * @param int $user_id User ID.
     * @return bool
     */
    public function can_download_pro( $user_id ) {
        $allowed_codes = array( 'starter_monthly', 'starter_yearly', 'pro_monthly', 'pro_yearly' );
        return $this->user_has_product_code( $user_id, $allowed_codes );
    }

    /**
     * Check whether user has entitlement/license for any of provided product codes.
     *
     * @param int              $user_id User ID.
     * @param array<int,string> $codes Allowed product codes.
     * @return bool
     */
    private function user_has_product_code( $user_id, array $codes ) {
        global $wpdb;

        $user_id = absint( $user_id );
        if ( $user_id <= 0 ) {
            return false;
        }

        $codes = array_values( array_filter( array_map( 'sanitize_key', $codes ) ) );
        if ( empty( $codes ) ) {
            return false;
        }

        $placeholders = implode( ',', array_fill( 0, count( $codes ), '%s' ) );

        $entitlements_table = Installer::get_license_entitlements_table();
        $license_table = Installer::get_licenses_table();

        $entitlement_sql = "SELECT COUNT(*) FROM {$entitlements_table} WHERE user_id = %d AND status IN ('pending','generated') AND product_code IN ({$placeholders})";
        $license_sql = "SELECT COUNT(*) FROM {$license_table} WHERE user_id = %d AND product_code IN ({$placeholders})";

        $entitlement_params = array_merge( array( $user_id ), $codes );
        $license_params = array_merge( array( $user_id ), $codes );

        $entitlement_count = (int) $wpdb->get_var( $wpdb->prepare( $entitlement_sql, ...$entitlement_params ) );
        if ( $entitlement_count > 0 ) {
            return true;
        }

        $license_count = (int) $wpdb->get_var( $wpdb->prepare( $license_sql, ...$license_params ) );
        return $license_count > 0;
    }
}
