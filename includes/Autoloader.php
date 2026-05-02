<?php
/**
 * Autoloader for BanglaTrackServer classes.
 *
 * @package BanglaTrackServer
 */

namespace BanglaTrackServer;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class Autoloader
 */
class Autoloader {

    /**
     * Register the autoloader.
     *
     * @return void
     */
    public static function register() {
        spl_autoload_register( array( __CLASS__, 'autoload' ) );
    }

    /**
     * Autoload a class.
     *
     * @param string $class Class name to autoload.
     * @return void
     */
    public static function autoload( $class ) {
        $prefix = 'BanglaTrackServer\\';
        $len    = strlen( $prefix );

        if ( strncmp( $prefix, $class, $len ) !== 0 ) {
            return;
        }

        $relative_class = substr( $class, $len );
        $file           = BT_SERVER_PLUGIN_DIR . 'includes/' . str_replace( '\\', '/', $relative_class ) . '.php';

        if ( file_exists( $file ) ) {
            require_once $file;
        }
    }
}
