<?php
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

// Load only what is required
require_once dirname( __FILE__ ) . '/sti-rss-feed-reader.php';

// Run full cleanup
if ( function_exists( 'stirfr_full_cleanup_everything' ) ) {
    stirfr_full_cleanup_everything();
}
