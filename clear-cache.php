<?php
/**
 * Simple script to clear OPcache on the host.
 */
if ( function_exists( 'opcache_reset' ) ) {
    opcache_reset();
    echo "OPcache cleared successfully!";
} else {
    echo "OPcache is not enabled or opcache_reset is disabled.";
}
