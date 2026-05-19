<?php
namespace BanglaTrackServer\Database;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class EntitlementRepository {

    /**
     * Fetch entitlement by ID.
     *
     * @param int $id Entitlement ID.
     * @return object|null
     */
    public function get_by_id( $id ) {
        global $wpdb;
        $table = Installer::get_license_entitlements_table();

        return $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$table} WHERE id = %d",
                absint( $id )
            )
        );
    }

    /**
     * Fetch entitlement by order and order item ID.
     *
     * @param int $order_id Order ID.
     * @param int $order_item_id Order item ID.
     * @return object|null
     */
    public function get_by_order_item( $order_id, $order_item_id ) {
        global $wpdb;
        $table = Installer::get_license_entitlements_table();

        return $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$table} WHERE order_id = %d AND order_item_id = %d",
                absint( $order_id ),
                absint( $order_item_id )
            )
        );
    }

    /**
     * Fetch all entitlements for a user with attached license details.
     *
     * @param int $user_id User ID.
     * @return array<int, object>
     */
    public function get_for_user( $user_id ) {
        global $wpdb;
        $entitlements = Installer::get_license_entitlements_table();
        $licenses     = Installer::get_licenses_table();

        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT e.*, l.license_key, l.plan_code, l.status AS license_status, l.starts_at, l.expires_at
                FROM {$entitlements} e
                LEFT JOIN {$licenses} l ON e.license_id = l.id
                WHERE e.user_id = %d
                ORDER BY e.created_at DESC, e.id DESC",
                absint( $user_id )
            )
        );
    }

    /**
     * Create a new entitlement row.
     *
     * @param array<string, mixed> $data Entitlement data.
     * @return int|false
     */
    public function create( array $data ) {
        global $wpdb;
        $table = Installer::get_license_entitlements_table();

        $status = sanitize_key( (string) ( $data['status'] ?? 'pending' ) );
        if ( ! in_array( $status, array( 'pending', 'generated', 'cancelled', 'refunded' ), true ) ) {
            $status = 'pending';
        }

        $inserted = $wpdb->insert(
            $table,
            array(
                'user_id'      => absint( $data['user_id'] ?? 0 ),
                'order_id'     => absint( $data['order_id'] ?? 0 ),
                'order_item_id'=> absint( $data['order_item_id'] ?? 0 ),
                'product_id'   => absint( $data['product_id'] ?? 0 ),
                'product_code' => sanitize_key( (string) ( $data['product_code'] ?? '' ) ),
                'status'       => $status,
                'license_id'   => ! empty( $data['license_id'] ) ? absint( $data['license_id'] ) : null,
                'generated_at' => ! empty( $data['generated_at'] ) ? sanitize_text_field( (string) $data['generated_at'] ) : null,
            ),
            array( '%d', '%d', '%d', '%d', '%s', '%s', '%d', '%s' )
        );

        if ( ! $inserted ) {
            return false;
        }

        return (int) $wpdb->insert_id;
    }

    /**
     * Mark entitlement as generated if currently pending.
     *
     * @param int $entitlement_id Entitlement ID.
     * @param int $license_id License ID.
     * @return bool
     */
    public function mark_generated_if_pending( $entitlement_id, $license_id ) {
        global $wpdb;
        $table = Installer::get_license_entitlements_table();

        $updated = $wpdb->query(
            $wpdb->prepare(
                "UPDATE {$table}
                SET status = 'generated', license_id = %d, generated_at = %s, updated_at = %s
                WHERE id = %d AND status = 'pending'",
                absint( $license_id ),
                current_time( 'mysql' ),
                current_time( 'mysql' ),
                absint( $entitlement_id )
            )
        );

        return (bool) $updated;
    }

    /**
     * Update entitlement with generated license details.
     *
     * @param int $entitlement_id Entitlement ID.
     * @param int $license_id License ID.
     * @return bool
     */
    public function sync_generated( $entitlement_id, $license_id ) {
        global $wpdb;
        $table = Installer::get_license_entitlements_table();

        return false !== $wpdb->update(
            $table,
            array(
                'status'       => 'generated',
                'license_id'   => absint( $license_id ),
                'generated_at' => current_time( 'mysql' ),
            ),
            array( 'id' => absint( $entitlement_id ) ),
            array( '%s', '%d', '%s' ),
            array( '%d' )
        );
    }

    /**
     * Update the status of an entitlement record.
     *
     * Used during order cancellation/refund to revoke entitlements.
     *
     * @param int    $entitlement_id Entitlement ID.
     * @param string $status         New status ('cancelled', 'refunded', etc.).
     * @return bool True on success.
     */
    public function update_status( int $entitlement_id, string $status ): bool {
        global $wpdb;
        $table = Installer::get_license_entitlements_table();

        $allowed = array( 'pending', 'generated', 'cancelled', 'refunded' );
        if ( ! in_array( $status, $allowed, true ) ) {
            return false;
        }

        return false !== $wpdb->update(
            $table,
            array( 'status' => $status ),
            array( 'id' => $entitlement_id ),
            array( '%s' ),
            array( '%d' )
        );
    }

    /**
     * Get entitlement by linked license ID.
     *
     * @param int $license_id License ID.
     * @return object|null Entitlement row or null.
     */
    public function get_by_license_id( int $license_id ): ?object {
        global $wpdb;
        $table = Installer::get_license_entitlements_table();

        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$table} WHERE license_id = %d LIMIT 1",
                $license_id
            )
        );

        return $row ?: null;
    }
}
