<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Force full content when a theme calls the_excerpt() on single posts.
 * Archives/search/admin/feeds remain unchanged.
 *
 * @param string   $excerpt The post excerpt.
 * @param WP_Post  $post    The post object.
 * @return string
 */
function stirfr_force_full_content_on_single_excerpt( $excerpt, $post ) {

    if ( is_admin() || is_feed() ) {
        return $excerpt;
    }

    if ( is_singular( 'post' ) && is_main_query() && in_the_loop() ) {

        $is_stored = get_post_meta( $post->ID, '_stirfr_is_stored', true );

        if ( (string) $is_stored === '1' ) {

            // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
            $full = apply_filters( 'the_content', (string) $post->post_content );

            return ! empty( $full ) ? $full : $excerpt;
        }
    }

    return $excerpt;
}

add_filter( 'get_the_excerpt', 'stirfr_force_full_content_on_single_excerpt', 9, 2 );
