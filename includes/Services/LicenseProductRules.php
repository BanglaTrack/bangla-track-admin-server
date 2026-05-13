<?php
/**
 * Fixed Bangla Track license product rules.
 *
 * @package BanglaTrackServer
 */

namespace BanglaTrackServer\Services;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class LicenseProductRules
 */
class LicenseProductRules {

    /**
     * Get rules for a license product code.
     *
     * @param string $product_code Product code.
     * @return array<string, int|string|bool>
     */
    public static function get_rules( string $product_code ): array {
        $rules_map = self::get_rules_map();

        if ( ! isset( $rules_map[ $product_code ] ) ) {
            return array();
        }

        return $rules_map[ $product_code ];
    }

    /**
     * Validate a license product code.
     *
     * @param string $product_code Product code.
     * @return bool
     */
    public static function is_valid_product_code( string $product_code ): bool {
        $rules_map = self::get_rules_map();
        return isset( $rules_map[ $product_code ] );
    }

    /**
     * Get WooCommerce field options for product code select.
     *
     * @return array<string, string>
     */
    public static function get_product_options(): array {
        return array(
            ''                => __( 'Select license product', 'bangla-track-server' ),
            'free_forever'    => __( 'Free Forever', 'bangla-track-server' ),
            'starter_monthly' => __( 'Starter Monthly', 'bangla-track-server' ),
            'pro_monthly'     => __( 'Pro Monthly', 'bangla-track-server' ),
            'starter_yearly'  => __( 'Starter Yearly', 'bangla-track-server' ),
            'pro_yearly'      => __( 'Pro Yearly', 'bangla-track-server' ),
        );
    }

    /**
     * Get all product code rule mappings.
     *
     * @return array<string, array<string, int|string|bool>>
     */
    private static function get_rules_map(): array {
        return array(
            'free_forever' => array(
                'plan_code'                => 'free',
                'duration_days'            => 0,
                'is_lifetime'              => true,
                'monthly_booking_limit'    => 100,
                'allowed_active_providers' => 1,
                'multi_provider'           => false,
                'max_sites'                => 1,
            ),
            'starter_monthly' => array(
                'plan_code'                => 'starter',
                'duration_days'            => 30,
                'is_lifetime'              => false,
                'monthly_booking_limit'    => 500,
                'allowed_active_providers' => 1,
                'multi_provider'           => false,
                'max_sites'                => 1,
            ),
            'pro_monthly' => array(
                'plan_code'                => 'pro',
                'duration_days'            => 30,
                'is_lifetime'              => false,
                'monthly_booking_limit'    => -1,
                'allowed_active_providers' => -1,
                'multi_provider'           => true,
                'max_sites'                => 3,
            ),
            'starter_yearly' => array(
                'plan_code'                => 'starter',
                'duration_days'            => 365,
                'is_lifetime'              => false,
                'monthly_booking_limit'    => 500,
                'allowed_active_providers' => 1,
                'multi_provider'           => false,
                'max_sites'                => 1,
            ),
            'pro_yearly' => array(
                'plan_code'                => 'pro',
                'duration_days'            => 365,
                'is_lifetime'              => false,
                'monthly_booking_limit'    => -1,
                'allowed_active_providers' => -1,
                'multi_provider'           => true,
                'max_sites'                => 3,
            ),
        );
    }
}
