<?php
/*
Plugin Name: STI RSS Feed Reader
Description: A simple RSS feed reader with image fallback, layout options, and optional feed source display.
Version: 1.2.4
Author: Santechidea
Text Domain: sti-rss-feed-reader
Icon URI: stifeeds-icon.png
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html
*/

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Helpful constants
if ( ! defined( 'STIRFR_PLUGIN_FILE' ) ) {
    define( 'STIRFR_PLUGIN_FILE', __FILE__ );
}
if ( ! defined( 'STIRFR_PLUGIN_DIR' ) ) {
    define( 'STIRFR_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
}
if ( ! defined( 'STIRFR_PLUGIN_URL' ) ) {
    define( 'STIRFR_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
}
if ( ! defined( 'STIRFR_PLUGIN_PATH' ) ) {
    define( 'STIRFR_PLUGIN_PATH', plugin_dir_path( __FILE__ ) );
}
if ( ! defined( 'STIRFR_PLUGIN_VER' ) ) {
    define( 'STIRFR_PLUGIN_VER', '1.2.4' );
}

// Load core functions (always needed)
require_once STIRFR_PLUGIN_DIR . 'functions.php';
require_once STIRFR_PLUGIN_DIR . 'includes/shortcode.php';
require_once STIRFR_PLUGIN_DIR . 'includes/single-display.php';

// Load admin page only in admin
if ( is_admin() ) {
    require_once STIRFR_PLUGIN_DIR . 'admin/admin-page.php';
}

/* =====================================================
 * CRON SETUP & CLEANUP FUNCTIONS
 * ===================================================== */

/**
 * Trash/delete posts with _stirfr_expire_at <= now
 * Direct SQL using meta_key indexing (LIMIT/OFFSET batching).
 */
function stirfr_cleanup_expired_posts(): void 
{
    global $wpdb;

    $action   = get_option( 'stirfr_cleanup_action', 'trash' );
    $now      = time();
    $per_page = 50;
    $paged    = 1;

    $allowed_statuses = [ 'publish', 'draft', 'pending', 'future' ];

    do {
        $offset = ( $paged - 1 ) * $per_page;

        $prepare_args = array_merge( [ '_stirfr_expire_at', $now, 'post' ], $allowed_statuses, [ $per_page, $offset ] );
        $cache_key = 'srf_expired_posts_' . md5( implode( '|', array_merge( $prepare_args ) ) );

        $post_ids = wp_cache_get( $cache_key, 'stirfr_queries' );

        if ( false === $post_ids ) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
            $post_ids = $wpdb->get_col(
                $wpdb->prepare(
                    "
                    SELECT DISTINCT pm.post_id
                    FROM {$wpdb->postmeta} pm
                    INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
                    WHERE pm.meta_key = %s
                      AND CAST(pm.meta_value AS SIGNED) <= %d
                      AND p.post_type = %s
                      AND p.post_status IN (" . implode( ',', array_fill( 0, count( $allowed_statuses ), '%s' ) ) . ")
                    ORDER BY CAST(pm.meta_value AS SIGNED) ASC
                    LIMIT %d OFFSET %d
                    ",
                    ...$prepare_args
                )
            );
            wp_cache_set( $cache_key, $post_ids, 'stirfr_queries', 60 );
        }

        if ( empty( $post_ids ) ) {
            break;
        }

        foreach ( $post_ids as $pid ) {
            $pid = (int) $pid;
            if ( $action === 'delete' ) {
                wp_delete_post( $pid, true );
            } else {
                wp_trash_post( $pid );
            }
        }

        $paged++;
    } while ( count( $post_ids ) === $per_page );
}

/**
 * Delete posts where _stirfr_is_stored = 1
 */
function stirfr_delete_all_stored_posts_now(): void 
{
    global $wpdb;

    $per_page = 50;
    $paged    = 1;
    $allowed_statuses = [ 'publish', 'draft', 'pending', 'future' ];

    do {
        $offset = ( $paged - 1 ) * $per_page;
        $prepare_args = array_merge( [ '_stirfr_is_stored', '1', 'post' ], $allowed_statuses, [ $per_page, $offset ] );
        $cache_key = 'srf_stored_posts_' . md5( implode( '|', $prepare_args ) );

        $post_ids = wp_cache_get( $cache_key, 'stirfr_queries' );

        if ( false === $post_ids ) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
            $post_ids = $wpdb->get_col(
                $wpdb->prepare(
                    "
                    SELECT DISTINCT pm.post_id
                    FROM {$wpdb->postmeta} pm
                    INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
                    WHERE pm.meta_key = %s
                      AND pm.meta_value = %s
                      AND p.post_type = %s
                      AND p.post_status IN (" . implode( ',', array_fill( 0, count( $allowed_statuses ), '%s' ) ) . ")
                    ORDER BY pm.post_id ASC
                    LIMIT %d OFFSET %d
                    ",
                    ...$prepare_args
                )
            );
            wp_cache_set( $cache_key, $post_ids, 'stirfr_queries', 60 );
        }

        if ( empty( $post_ids ) ) {
            break;
        }

        foreach ( $post_ids as $pid ) {
            wp_delete_post( (int) $pid, true );
        }

        $paged++;
    } while ( count( $post_ids ) === $per_page );
}

/**
 * Fetch feeds now and import the latest top items (for “Run Cleanup Now”).
 */
function stirfr_import_latest_topN_now(): void 
{
    if ( ! function_exists( 'stirfr_store_feed_item_as_post' ) ) {
        // shortcode.php is already loaded, but guard just in case
        $inc = STIRFR_PLUGIN_DIR . 'includes/shortcode.php';
        if ( file_exists( $inc ) ) {
            require_once $inc;
        }
    }
    if ( ! function_exists( 'stirfr_store_feed_item_as_post' ) ) {
        return;
    }

    $profiles = stirfr_get_profiles();
    $active_profiles = array_filter( $profiles, static function( $p ) {
        return ! empty( $p['active'] ) && ! empty( $p['store'] ) && ! empty( $p['urls'] );
    });

    $jobs = [];
    if ( ! empty( $active_profiles ) ) {
        foreach ( $active_profiles as $pid => $p ) {
            $items    = max( 1, min( 50, (int) ( $p['items'] ?? get_option( 'stirfr_feed_items', 5 ) ) ) );
            $fallback = (string) ( $p['image'] ?? get_option( 'stirfr_default_image', '' ) );
            $pstatus  = (string) ( $p['status'] ?? get_option( 'stirfr_store_status', 'draft' ) );
            foreach ( (array) $p['urls'] as $u ) {
                $u = trim( (string) $u );
                if ( $u !== '' ) {
                    $jobs[] = [
                        'feed_url'     => $u,
                        'items'        => $items,
                        'fallback_img' => $fallback,
                        'profile_id'   => (int) $pid,
                        'pstatus'      => $pstatus,
                    ];
                }
            }
        }
    } else {
        // Fallback: global feed URLs
        $feed_urls_opt = get_option( 'stirfr_feed_urls', [] );
        $feed_urls = is_string( $feed_urls_opt )
            ? array_filter( array_map( 'trim', preg_split( '/[\r\n,]+/', $feed_urls_opt ) ) )
            : (array) $feed_urls_opt;

        $items    = max( 1, (int) get_option( 'stirfr_feed_items', 5 ) );
        $fallback = (string) get_option( 'stirfr_default_image', '' );
        $pstatus  = (string) get_option( 'stirfr_store_status', 'draft' );

        foreach ( $feed_urls as $u ) {
            $u = trim( (string) $u );
            if ( $u !== '' ) {
                $jobs[] = [
                    'feed_url'     => $u,
                    'items'        => $items,
                    'fallback_img' => $fallback,
                    'profile_id'   => 0,
                    'pstatus'      => $pstatus,
                ];
            }
        }
    }

    if ( empty( $jobs ) ) {
        return;
    }

    if ( ! function_exists( 'fetch_feed' ) ) {
        include_once ABSPATH . WPINC . '/feed.php';
    }

    add_filter( 'wp_feed_cache_transient_lifetime', static function() {
        return 10;
    } );

    foreach ( $jobs as $job ) {
        $feed_url     = $job['feed_url'];
        $items_to_get = (int) $job['items'];
        $fallback_img = (string) $job['fallback_img'];
        $profile_id   = (int) $job['profile_id'];
        $pstatus      = (string) ( $job['pstatus'] ?? get_option( 'stirfr_store_status', 'draft' ) );

        $rss = fetch_feed( $feed_url );
        if ( is_wp_error( $rss ) || ! $rss ) {
            continue;
        }

        $items = $rss->get_items( 0, $items_to_get );
        if ( ! $items ) {
            continue;
        }

        foreach ( $items as $item ) {
            /** @var SimplePie_Item $item */
            $title   = esc_html( (string) ( $item->get_title() ?? '' ) );
            $link    = esc_url( (string) ( $item->get_permalink() ?? '' ) );
            $dateu   = (int) ( $item->get_date( 'U' ) ?? 0 );

            $image   = function_exists( 'stirfr_get_feed_image' ) ? stirfr_get_feed_image( $item, $fallback_img ) : $fallback_img;
            $excerpt = function_exists( 'stirfr_get_excerpt_text' ) ? stirfr_get_excerpt_text( $item, 30 ) : '';
            $cat     = function_exists( 'stirfr_get_primary_category' ) ? stirfr_get_primary_category( $item ) : '';
            $guid    = (string) ( $item->get_id( true ) ?: $link );

            $parts = wp_parse_url( (string) $feed_url );
            stirfr_store_feed_item_as_post( [
                'title'           => $title,
                'link'            => $link,
                'date'            => $dateu,
                'image'           => $image,
                'excerpt'         => $excerpt,
                'category'        => $cat,
                'feed'            => $feed_url,
                'host'            => isset( $parts['host'] ) ? $parts['host'] : '',
                'source'          => $guid,
                'raw_item'        => $item,
                'profile_id'      => $profile_id,
                'feed_origin_url' => $feed_url,
                'post_status'     => $pstatus,
            ] );
        }
    }
}

// Hook the daily cleanup action (must be defined outside admin)
add_action( 'stirfr_daily_cleanup', 'stirfr_cleanup_expired_posts' );

/* =====================================================
 * ACTIVATION / DEACTIVATION HOOKS (cron scheduling)
 * ===================================================== */
register_activation_hook( __FILE__, function() {
    // Welcome flag (already set by stirfr_on_activate, but we keep both)
    if ( ! wp_next_scheduled( 'stirfr_daily_cleanup' ) ) {
        wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'stirfr_daily_cleanup' );
    }
} );

register_deactivation_hook( __FILE__, function() {
    $timestamp = wp_next_scheduled( 'stirfr_daily_cleanup' );
    if ( $timestamp ) {
        wp_unschedule_event( $timestamp, 'stirfr_daily_cleanup' );
    }
} );

/* ----------------------------------------------------
 * Welcome screen: redirect once after activation
 * ---------------------------------------------------- */
function stirfr_on_activate() {
    add_option( 'stirfr_show_welcome', 1 );
}
register_activation_hook( __FILE__, 'stirfr_on_activate' );

// Hidden page + redirect
function stirfr_register_welcome_page() {
    add_submenu_page(
        null,
        __( 'Welcome • STIRFR RSS Feed Reader', 'sti-rss-feed-reader' ),
        __( 'Welcome', 'sti-rss-feed-reader' ),
        'manage_options',
        'stirfr-welcome',
        'stirfr_render_welcome_page'
    );
}
add_action( 'admin_menu', 'stirfr_register_welcome_page' );

add_action( 'admin_init', function () {
    if ( ! is_admin() ) {
        return;
    }

    if ( ! current_user_can( 'activate_plugins' ) ) {
        return;
    }

    if ( is_network_admin() ) {
        return;
    }

    // phpcs:ignore WordPress.Security.NonceVerification.Recommended
    if ( ! empty( $_GET['activate-multi'] ) ) {
        $activate_multi = sanitize_text_field(
            filter_input( INPUT_GET, 'activate-multi', FILTER_SANITIZE_FULL_SPECIAL_CHARS )
        );
        if ( $activate_multi ) {
            return;
        }
    }

    if ( ! get_option( 'stirfr_show_welcome' ) ) {
        return;
    }

    delete_option( 'stirfr_show_welcome' );
    wp_safe_redirect( admin_url( 'admin.php?page=stirfr-welcome' ) );
    exit;
} );

function stirfr_render_welcome_page() {
    $file = trailingslashit( STIRFR_PLUGIN_DIR ) . 'admin/welcome.php';
    if ( file_exists( $file ) ) {
        include $file;
    } else {
        echo '<div class="wrap"><h1>Welcome</h1><p>Missing <code>admin/welcome.php</code>.</p></div>';
    }
}

/* ======================================================================
 * FULL CLEANUP HELPERS (posts, images, options, transients, cron)
 * ====================================================================== */

/**
 * Return all post IDs created by this plugin (meta _stirfr_is_stored = 1).
 */
function stirfr_get_all_stored_post_ids(): array {
    global $wpdb;

    $cache_key = 'stirfr_all_stored_post_ids';
    $cached = wp_cache_get( $cache_key, 'stirfr_queries' );
    if ( false !== $cached && is_array( $cached ) ) {
        return $cached;
    }

    $ids      = [];
    $per_page = 500;
    $paged    = 1;

    do {
        $offset = ( $paged - 1 ) * $per_page;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
        $post_ids = $wpdb->get_col(
            $wpdb->prepare(
                "
                SELECT DISTINCT pm.post_id
                FROM {$wpdb->postmeta} pm
                INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
                WHERE pm.meta_key = %s
                  AND pm.meta_value = %s
                ORDER BY pm.post_id ASC
                LIMIT %d OFFSET %d
                ",
                '_stirfr_is_stored',
                '1',
                $per_page,
                $offset
            )
        );

        if ( empty( $post_ids ) ) {
            break;
        }

        $ids = array_merge( $ids, $post_ids );
        $paged++;
    } while ( count( $post_ids ) === $per_page );

    $ids = array_map( 'intval', array_values( array_unique( $ids ) ) );
    wp_cache_set( $cache_key, $ids, 'stirfr_queries', 5 * MINUTE_IN_SECONDS );

    return $ids;
}

/**
 * Permanently delete media tied to plugin-created posts and any media the plugin marked.
 */
function stirfr_delete_related_media_for_posts( array $post_ids ): void {
    if ( empty( $post_ids ) ) {
        return;
    }

    global $wpdb;

    // 1) Delete featured images of those posts
    foreach ( $post_ids as $pid ) {
        $thumb_id = (int) get_post_thumbnail_id( $pid );
        if ( $thumb_id ) {
            delete_post_thumbnail( $pid );
            if ( get_post_status( $thumb_id ) ) {
                wp_delete_attachment( $thumb_id, true );
            }
        }
    }

    // 2) Delete attachments whose parent is one of those posts (chunked)
    $chunks = array_chunk( $post_ids, 200 );
    foreach ( $chunks as $chunk ) {
        $a = new WP_Query( [
            'post_type'       => 'attachment',
            'post_status'     => 'any',
            'posts_per_page'  => 500,
            'fields'          => 'ids',
            'post_parent__in' => $chunk,
            'no_found_rows'   => true,
        ] );
        if ( ! empty( $a->posts ) ) {
            foreach ( $a->posts as $att_id ) {
                wp_delete_attachment( (int) $att_id, true );
            }
        }
    }

    // 3) Delete any attachments flagged by our plugin even if detached
    $flag_keys = [ '_stirfr_is_media', '_stirfr_feed_image', '_stirfr_is_stored_media' ];
    $per_page  = 500;
    $cache_ttl = 60;

    foreach ( $flag_keys as $meta_key ) {
        $last_post_id = 0;

        while ( true ) {
            $cache_key = 'stirfr_del_media_' . md5( $meta_key . '|' . $last_post_id . '|' . $per_page );
            $rows = wp_cache_get( $cache_key, 'stirfr_queries' );

            if ( false === $rows ) {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
                $rows = $wpdb->get_results(
                    $wpdb->prepare(
                        "
                        SELECT DISTINCT pm.post_id
                        FROM {$wpdb->postmeta} pm
                        INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
                        WHERE pm.meta_key = %s
                          AND pm.meta_value = %s
                          AND pm.post_id > %d
                          AND p.post_type = %s
                        ORDER BY pm.post_id ASC
                        LIMIT %d
                        ",
                        $meta_key,
                        '1',
                        $last_post_id,
                        'attachment',
                        $per_page
                    )
                );
                wp_cache_set( $cache_key, $rows, 'stirfr_queries', $cache_ttl );
            }

            if ( empty( $rows ) ) {
                break;
            }

            foreach ( $rows as $row ) {
                $att_id = (int) $row->post_id;
                if ( get_post_type( $att_id ) === 'attachment' ) {
                    wp_delete_attachment( $att_id, true );
                }
                $last_post_id = $att_id;
            }

            if ( count( $rows ) < $per_page ) {
                break;
            }
        }
    }
}

/**
 * Permanently delete all plugin posts.
 */
function stirfr_delete_all_plugin_posts_permanently(): void {
    $post_ids = stirfr_get_all_stored_post_ids();
    if ( empty( $post_ids ) ) {
        return;
    }

    stirfr_delete_related_media_for_posts( $post_ids );

    foreach ( $post_ids as $pid ) {
        if ( get_post_status( $pid ) ) {
            wp_delete_post( $pid, true );
        }
    }
}

/**
 * Remove our options and transients & cron.
 */
function stirfr_remove_plugin_options_and_cron(): void {
    $ts = wp_next_scheduled( 'stirfr_daily_cleanup' );
    if ( $ts ) {
        wp_unschedule_event( $ts, 'stirfr_daily_cleanup' );
    }

    $options = [
        'stirfr_feed_urls',
        'stirfr_feed_items',
        'stirfr_default_image',
        'stirfr_feed_layout',
        'stirfr_show_poweredby',
        'stirfr_grid_columns',
        'stirfr_store_posts',
        'stirfr_store_days',
        'stirfr_store_status',
        'stirfr_expire_mode',
        'stirfr_lock_seconds',
        'stirfr_cleanup_action',
        'stirfr_profiles',
        'stirfr_show_welcome',
    ];
    foreach ( $options as $key ) {
        delete_option( $key );
    }

    global $wpdb;
    $like_1 = $wpdb->esc_like( '_transient_feed_' ) . '%';
    $like_2 = $wpdb->esc_like( '_transient_timeout_feed_' ) . '%';

    $cache_key = 'stirfr_transient_option_names_' . md5( $like_1 . '|' . $like_2 );
    $option_names = wp_cache_get( $cache_key, 'stirfr_queries' );

    if ( false === $option_names ) {
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
        $option_names = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
                $like_1,
                $like_2
            )
        );
        wp_cache_set( $cache_key, $option_names, 'stirfr_queries', 5 * MINUTE_IN_SECONDS );
    }

    if ( ! empty( $option_names ) ) {
        foreach ( $option_names as $option_name ) {
            if ( 0 === strpos( $option_name, '_transient_timeout_' ) ) {
                $transient_key = substr( $option_name, strlen( '_transient_timeout_' ) );
            } elseif ( 0 === strpos( $option_name, '_transient_' ) ) {
                $transient_key = substr( $option_name, strlen( '_transient_' ) );
            } else {
                continue;
            }
            delete_transient( $transient_key );
        }
    }
}

/**
 * Full cleanup: posts, media, options, transients, cron.
 */
function stirfr_full_cleanup_everything(): void {
    stirfr_delete_all_plugin_posts_permanently();
    stirfr_remove_plugin_options_and_cron();
}