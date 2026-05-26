<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// ════════════════════════════════════════════════════════════════
// HELPERS: Image
// ════════════════════════════════════════════════════════════════

function stirfr_is_valid_image_url( string $url ): bool {
	if ( empty( $url ) ) {
		return false;
	}
	$path = wp_parse_url( $url, PHP_URL_PATH );
	if ( ! $path ) {
		return false;
	}
	return in_array(
		strtolower( pathinfo( (string) $path, PATHINFO_EXTENSION ) ),
		[ 'jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp' ],
		true
	);
}

function stirfr_resolve_image_url( string $image_url = '', string $default = '' ): string {
	if ( empty( $default ) ) {
		$default = (string) get_option( 'stirfr_default_image', '' );
		if ( ! $default && defined( 'STIRFR_DEFAULT_FALLBACK_IMAGE' ) ) {
			$default = STIRFR_DEFAULT_FALLBACK_IMAGE;
		}
	}

	if ( empty( $image_url ) || ! stirfr_is_valid_image_url( $image_url ) ) {
		return esc_url( $default );
	}

	// Local images: always trust
	$img_host  = (string) wp_parse_url( $image_url, PHP_URL_HOST );
	$site_host = (string) wp_parse_url( home_url(), PHP_URL_HOST );
	if ( $img_host && $site_host && strcasecmp( $img_host, $site_host ) === 0 ) {
		return esc_url( $image_url );
	}

	// External: cache a HEAD check
	$cache_key = 'stirfr_img_head_' . md5( $image_url );
	$cached    = get_transient( $cache_key );

	if ( false !== $cached ) {
		return $cached === 'bad' ? esc_url( $default ) : esc_url( $image_url );
	}

	$response = wp_remote_head( $image_url, [
		'timeout'     => 4,
		'redirection' => 3,
		'user-agent'  => 'STI-RSS-Reader/1.0',
	] );

	if ( is_wp_error( $response ) ) {
		set_transient( $cache_key, 'bad', DAY_IN_SECONDS );
		return esc_url( $default );
	}

	$code = (int) wp_remote_retrieve_response_code( $response );
	if ( $code < 200 || $code >= 400 ) {
		set_transient( $cache_key, 'bad', DAY_IN_SECONDS );
		return esc_url( $default );
	}

	set_transient( $cache_key, 'ok', DAY_IN_SECONDS );
	return esc_url( $image_url );
}

function stirfr_get_feed_image( ?SimplePie_Item $item, string $default_image = '' ): string {
	$image_url = '';

	if ( $item ) {
		$enc = $item->get_enclosure();
		if ( $enc && $enc->get_link() ) {
			$tmp = (string) $enc->get_link();
			if ( stirfr_is_valid_image_url( $tmp ) ) {
				$image_url = $tmp;
			}
		}

		// Try enclosure thumbnail
		if ( ! $image_url && $enc && $enc->get_thumbnail() ) {
			$tmp = (string) $enc->get_thumbnail();
			if ( stirfr_is_valid_image_url( $tmp ) ) {
				$image_url = $tmp;
			}
		}

		// Try description <img>
		if ( ! $image_url ) {
			$desc = (string) $item->get_description();
			if ( $desc && preg_match( '/<img[^>]+src=["\']([^"\']+)["\']/', $desc, $m ) ) {
				$tmp = $m[1] ?? '';
				if ( stirfr_is_valid_image_url( $tmp ) ) {
					$image_url = $tmp;
				}
			}
		}

		// Try content:encoded <img> (WordPress puts images here)
		if ( ! $image_url ) {
			$content = (string) $item->get_content();
			if ( $content && preg_match( '/<img[^>]+src=["\']([^"\']+)["\']/', $content, $m ) ) {
				$tmp = $m[1] ?? '';
				if ( stirfr_is_valid_image_url( $tmp ) ) {
					$image_url = $tmp;
				}
			}
		}
	}

	return esc_url( $image_url ?: $default_image );
}

// Background image fetch (scheduled event handler)
add_action( 'stirfr_bg_fetch_image', 'stirfr_bg_fetch_image_handler' );
function stirfr_bg_fetch_image_handler( string $url ): void {
	if ( empty( $url ) ) {
		return;
	}
	$cache_key = 'stirfr_img_' . md5( $url );
	if ( false !== get_transient( $cache_key ) ) {
		return;
	}
	$image = stirfr_fetch_image_from_article( $url );
	set_transient( $cache_key, $image ?: 'none', 7 * DAY_IN_SECONDS );
}

function stirfr_fetch_image_from_article( string $url ): string {
	if ( empty( $url ) ) {
		return '';
	}

	$resp = wp_remote_get( $url, [
		'timeout' => 10,
		'headers' => [ 'User-Agent' => 'Mozilla/5.0' ],
	] );

	if ( is_wp_error( $resp ) ) {
		return '';
	}

	$html = (string) wp_remote_retrieve_body( $resp );

	foreach ( [
		'/<meta property="og:image" content="([^"]+)"/i',
		'/<meta name="twitter:image" content="([^"]+)"/i',
		'/<img[^>]+src="([^"]+)"/i',
	] as $pattern ) {
		if ( preg_match( $pattern, $html, $m ) ) {
			$candidate = esc_url_raw( $m[1] );
			if ( stirfr_is_valid_image_url( $candidate ) ) {
				return $candidate;
			}
		}
	}

	return '';
}

// ════════════════════════════════════════════════════════════════
// HELPERS: Feed item parsing
// ════════════════════════════════════════════════════════════════

function stirfr_get_primary_category( ?SimplePie_Item $item ): string {
	if ( ! $item ) {
		return '';
	}
	$cats = $item->get_categories();
	if ( ! is_array( $cats ) || empty( $cats ) ) {
		return '';
	}
	$c = $cats[0];
	if ( is_object( $c ) ) {
		return esc_html( (string) ( $c->term ?? $c->label ?? '' ) );
	}
	return '';
}

function stirfr_get_excerpt_text( ?SimplePie_Item $item, int $words = 30 ): string {
	if ( ! $item ) {
		return '';
	}
	$raw = (string) ( $item->get_description() ?? '' );
	$raw = preg_replace( '/<img[^>]*>/i', '', $raw ) ?? '';
	$txt = preg_replace( '/\s+/', ' ', trim( wp_strip_all_tags( $raw, true ) ) );
	if ( ! $txt ) {
		$txt = (string) ( $item->get_title() ?? '' );
	}
	return esc_html( wp_trim_words( $txt, $words, '…' ) );
}

function stirfr_get_item_content_html( ?SimplePie_Item $item ): string {
	if ( ! $item ) {
		return '';
	}

	$html = (string) ( $item->get_content() ?? '' );
	if ( $html === '' ) {
		$html = (string) ( $item->get_description() ?? '' );
	}

	if ( $html !== '' ) {
		$html = wp_kses_post(
			preg_replace( '#<(script|style|iframe|noscript)[^>]*>.*?</\1>#is', '', $html ) ?? ''
		);
	}

	if ( '' === trim( wp_strip_all_tags( $html, true ) ) ) {
		$desc = wp_strip_all_tags( (string) ( $item->get_description() ?? '' ), true );
		$html = $desc ? '<p>' . esc_html( $desc ) . '</p>' : '<p>' . esc_html( (string) ( $item->get_title() ?? '' ) ) . '</p>';
	}

	return trim( $html );
}

function stirfr_looks_truncated( string $plain_text ): bool {
	$plain = trim( $plain_text );
	if ( $plain === '' ) {
		return false;
	}
	if ( in_array( substr( $plain, -1 ), [ '…' ], true ) || str_ends_with( $plain, '[…]' ) || str_ends_with( $plain, '...' ) ) {
		return true;
	}
	$words = preg_split( '/\s+/', $plain );
	return is_array( $words ) && count( $words ) < 120;
}

// ════════════════════════════════════════════════════════════════
// HELPERS: Color CSS vars
// ════════════════════════════════════════════════════════════════

function stirfr_get_color_style_attr(): string {
	$card     = sanitize_hex_color( (string) get_option( 'stirfr_card_color', '' ) );
	$text     = sanitize_hex_color( (string) get_option( 'stirfr_text_color', '' ) );
	$readmore = sanitize_hex_color( (string) get_option( 'stirfr_readmore_color', '' ) );

	$vars = [];
	if ( $card )     { $vars[] = '--stirfr-card-bg:' . $card; }
	if ( $text )     { $vars[] = '--stirfr-text-color:' . $text; }
	if ( $readmore ) { $vars[] = '--stirfr-readmore-color:' . $readmore; }

	return implode( ';', $vars );
}

// ════════════════════════════════════════════════════════════════
// HELPERS: Read More
// ════════════════════════════════════════════════════════════════

function stirfr_get_readmore_config(): array {
	$enabled = (int) get_option( 'stirfr_readmore_button_enabled', 0 );
	$style   = (string) get_option( 'stirfr_readmore_button_style', 'style1' );
	$text    = (string) get_option( 'stirfr_readmore_button_text', 'Read more' );

	return [
		'class' => $enabled
			? 'stirfr-read-more stirfr-btn stirfr-btn-' . esc_attr( $style )
			: 'stirfr-read-more',
		'text'  => esc_html( $text ),
	];
}

// ════════════════════════════════════════════════════════════════
// HELPERS: Stored post lookups
// ════════════════════════════════════════════════════════════════

function stirfr_get_published_post_id_with_content( array $row ): int {
	global $wpdb;

	$source = (string) ( $row['source'] ?? '' );
	if ( $source === '' ) {
		return 0;
	}

	$source_key = md5( $source );
	$cache_key  = 'stirfr_pub_post_for_' . $source_key;
	$cached     = wp_cache_get( $cache_key, 'stirfr' );

	if ( false !== $cached ) {
		$pid = (int) $cached;
		if ( $pid > 0 ) {
			$p = get_post( $pid );
			if ( $p && $p->post_status === 'publish' && trim( wp_strip_all_tags( (string) $p->post_content, true ) ) !== '' ) {
				return $pid;
			}
		}
		return 0;
	}

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
	$post_id = $wpdb->get_var( $wpdb->prepare(
		"SELECT pm.post_id
		 FROM {$wpdb->postmeta} pm
		 INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
		 WHERE pm.meta_key = %s AND pm.meta_value = %s
		   AND p.post_type = %s AND p.post_status = %s
		 ORDER BY pm.post_id ASC LIMIT 1",
		'_stirfr_source', $source_key, 'post', 'publish'
	) );

	wp_cache_set( $cache_key, $post_id ? (int) $post_id : 0, 'stirfr', 5 * MINUTE_IN_SECONDS );

	if ( ! $post_id ) {
		return 0;
	}

	$pid = (int) $post_id;
	$p   = get_post( $pid );
	if ( ! $p || $p->post_status !== 'publish' ) {
		return 0;
	}

	return trim( wp_strip_all_tags( (string) $p->post_content, true ) ) !== '' ? $pid : 0;
}

function stirfr_build_excerpt_with_read_more( array $row, int $limit = 20 ): string {
	$config = stirfr_get_readmore_config();

	$plain = trim( preg_replace( '/\s+/', ' ',
		wp_strip_all_tags( stirfr_get_item_content_html( $row['raw_item'] ?? null ), true )
	) );

	if ( $plain === '' && ! empty( $row['excerpt'] ) ) {
		$plain = (string) $row['excerpt'];
	}

	$pid = stirfr_get_published_post_id_with_content( $row );
	if ( $pid ) {
		$href  = (string) ( get_permalink( $pid ) ?: '' );
		$attrs = '';
	} else {
		$href  = (string) ( $row['link'] ?? '' );
		$attrs = ' target="_blank" rel="nofollow noopener"';
	}

	$link_html = '<a class="' . esc_attr( $config['class'] ) . '" href="' . esc_url( $href ) . '"' . $attrs . '>'
		. esc_html( $config['text'] ) . '</a>';

	if ( $plain === '' ) {
		return '<div class="stirfr-excerpt">' . $link_html . '</div>';
	}

	return '<div class="stirfr-excerpt">'
		. esc_html( wp_trim_words( $plain, max( 1, $limit ), '…' ) ) . ' '
		. $link_html
		. '</div>';
}

// ════════════════════════════════════════════════════════════════
// STORAGE: Insert / dedupe feed items as WP posts
// ════════════════════════════════════════════════════════════════

function stirfr_store_feed_item_as_post( array $args ): ?int {
	global $wpdb;

	$title   = wp_strip_all_tags( (string) ( $args['title']  ?? '' ) );
	$link    = esc_url_raw( (string) ( $args['link']   ?? '' ) );
	$excerpt = wp_strip_all_tags( (string) ( $args['excerpt'] ?? '' ) );
	$image   = esc_url_raw( (string) ( $args['image']  ?? '' ) );
	$feed    = esc_url_raw( (string) ( $args['feed_origin_url'] ?? $args['feed'] ?? '' ) );
	$profile = (int) ( $args['profile_id'] ?? 0 );

	$source     = (string) ( $args['source'] ?? ( $link ?: md5( $title . $link ) ) );
	$source_key = md5( $source );

	$in_status = (string) ( $args['post_status'] ?? get_option( 'stirfr_store_status', 'draft' ) );
	$status    = in_array( $in_status, [ 'publish', 'draft', 'pending' ], true ) ? $in_status : 'draft';

	$ts     = (int) ( $args['date'] ?? 0 );
	$now_ts = time();
	if ( $ts <= 0 ) {
		$ts = $now_ts;
	}
	if ( $status === 'publish' && $ts > $now_ts ) {
		$ts = $now_ts;
	}

	$post_date     = wp_date( 'Y-m-d H:i:s', $ts, wp_timezone() );
	$post_date_gmt = get_gmt_from_date( (string) $post_date );

	// Deduplicate
	$cache_key = 'stirfr_existing_post_for_' . $source_key;
	$existing  = wp_cache_get( $cache_key, 'stirfr' );

	if ( false === $existing ) {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$existing = $wpdb->get_var( $wpdb->prepare(
			"SELECT pm.post_id
			 FROM {$wpdb->postmeta} pm
			 INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
			 WHERE pm.meta_key = %s AND pm.meta_value = %s
			   AND p.post_type = %s AND p.post_status IN (%s,%s,%s,%s)
			 ORDER BY pm.post_id ASC LIMIT 1",
			'_stirfr_source', $source_key, 'post', 'publish', 'draft', 'pending', 'future'
		) );
		wp_cache_set( $cache_key, $existing ?: 0, 'stirfr', 5 * MINUTE_IN_SECONDS );
	}

	if ( $existing && (int) $existing > 0 ) {
		return (int) $existing;
	}

	// Build post content
	if ( $status === 'publish' ) {
		$full_html = '';

		if ( ! empty( $args['content_html'] ) ) {
			$full_html = wp_kses_post( (string) $args['content_html'] );
		} elseif ( ! empty( $args['raw_item'] ) ) {
			$full_html = stirfr_get_item_content_html( $args['raw_item'] );
		}

		if ( ( $full_html === '' || stirfr_looks_truncated( wp_strip_all_tags( $full_html, true ) ) ) && $link !== '' ) {
			$fetched = stirfr_fetch_full_article( $link );
			if ( $fetched !== '' ) {
				$full_html = $fetched;
			}
		}

		if ( $full_html === '' ) {
			$parts = [];
			if ( $excerpt ) { $parts[] = esc_html( $excerpt ); }
			if ( $link )    { $parts[] = sprintf( '<p><a href="%s" rel="nofollow noopener" target="_blank">%s</a></p>', esc_url( $link ), esc_html__( 'Read original', 'sti-rss-feed-reader' ) ); }
			$full_html = implode( "\n\n", $parts );
		}

		$post_content = $full_html;
	} else {
		$parts = [];
		if ( $excerpt ) { $parts[] = esc_html( $excerpt ); }
		if ( $link )    { $parts[] = sprintf( '<p><a href="%s" rel="nofollow noopener" target="_blank">%s</a></p>', esc_url( $link ), esc_html__( 'Read original', 'sti-rss-feed-reader' ) ); }
		$post_content = implode( "\n\n", $parts );
	}

	$post_id = wp_insert_post( [
		'post_type'      => 'post',
		'post_title'     => $title ?: __( '(no title)', 'sti-rss-feed-reader' ),
		'post_content'   => $post_content,
		'post_status'    => $status,
		'post_date'      => $post_date,
		'post_date_gmt'  => $post_date_gmt,
		'post_author'    => get_current_user_id() ?: 1,
		'comment_status' => 'closed',
		'ping_status'    => 'closed',
	], true );

	if ( is_wp_error( $post_id ) || ! $post_id ) {
		return null;
	}

	// Featured image
	if ( ! empty( $image ) ) {
		if ( ! function_exists( 'media_sideload_image' ) ) {
			require_once ABSPATH . 'wp-admin/includes/media.php';
			require_once ABSPATH . 'wp-admin/includes/file.php';
			require_once ABSPATH . 'wp-admin/includes/image.php';
		}
		$att_id = media_sideload_image( $image, $post_id, null, 'id' );
		if ( ! is_wp_error( $att_id ) && $att_id ) {
			set_post_thumbnail( $post_id, (int) $att_id );
		}
	}

	// Meta
	update_post_meta( $post_id, '_stirfr_is_stored',  '1' );
	update_post_meta( $post_id, '_stirfr_source',     $source_key );
	update_post_meta( $post_id, '_stirfr_feed_url',   $feed );
	update_post_meta( $post_id, '_stirfr_profile_id', $profile );

	// Expiry
	$days        = max( 1, min( 365, (int) get_option( 'stirfr_store_days', 3 ) ) );
	$expire_mode = (string) get_option( 'stirfr_expire_mode', 'rolling' );

	if ( $expire_mode === 'midnight' ) {
		$site_time = new DateTime( 'now', wp_timezone() );
		$site_time->setTime( 23, 59, 59 );
		if ( $days > 1 ) {
			$site_time->modify( '+' . ( $days - 1 ) . ' days' );
		}
		$expire_at = $site_time->getTimestamp();
	} else {
		$expire_at = $now_ts + ( $days * DAY_IN_SECONDS );
	}

	update_post_meta( $post_id, '_stirfr_expire_at', (int) $expire_at );

	return (int) $post_id;
}

// ════════════════════════════════════════════════════════════════
// STORAGE: Fetch full article (for published posts)
// ════════════════════════════════════════════════════════════════

function stirfr_fetch_full_article( string $url, int $timeout = 15 ): string {
	if ( $url === '' ) {
		return '';
	}

	$resp = wp_remote_get( $url, [
		'timeout'     => $timeout,
		'redirection' => 5,
		'headers'     => [
			'User-Agent' => 'Mozilla/5.0 (compatible; STI-RSS-Reader/1.0)',
			'Accept'     => 'text/html,application/xhtml+xml;q=0.9,*/*;q=0.8',
		],
	] );

	if ( is_wp_error( $resp ) ) {
		return '';
	}

	$code = (int) wp_remote_retrieve_response_code( $resp );
	if ( $code < 200 || $code >= 400 ) {
		return '';
	}

	$html = (string) wp_remote_retrieve_body( $resp );
	if ( $html === '' ) {
		return '';
	}

	libxml_use_internal_errors( true );
	$dom   = new DOMDocument();
	$loaded = $dom->loadHTML( '<meta http-equiv="Content-Type" content="text/html; charset=utf-8">' . $html );
	libxml_clear_errors();

	if ( ! $loaded ) {
		return '';
	}

	$xp = new DOMXPath( $dom );
	foreach ( [ '//script', '//style', '//noscript', '//iframe', '//nav', '//aside', '//footer', '//form' ] as $q ) {
		foreach ( $xp->query( $q ) ?: [] as $n ) {
			$n->parentNode->removeChild( $n );
		}
	}

	$candidates = [];
	foreach ( [ '//article', '//main', '//div' ] as $q ) {
		foreach ( $xp->query( $q ) ?: [] as $node ) {
			$plen = 0;
			foreach ( ( new DOMXPath( $dom ) )->query( './/p', $node ) ?: [] as $p ) {
				$plen += mb_strlen( trim( $p->textContent ) );
			}
			if ( $plen > 300 ) {
				$candidates[] = [ 'node' => $node, 'score' => $plen ];
			}
		}
		if ( $candidates ) {
			break;
		}
	}

	if ( ! $candidates ) {
		return '';
	}

	usort( $candidates, fn( $a, $b ) => $b['score'] <=> $a['score'] );
	$best  = $candidates[0]['node'];
	$inner = '';
	foreach ( $best->childNodes as $child ) {
		$inner .= $dom->saveHTML( $child );
	}

	return trim( wp_kses_post(
		preg_replace( '#<(script|style|iframe|noscript)[^>]*>.*?</\1>#is', '', $inner ) ?? ''
	) );
}

// ════════════════════════════════════════════════════════════════
// AUTO POSITIONING (header / footer via JS injection)
// ════════════════════════════════════════════════════════════════

add_action( 'wp_head', 'stirfr_auto_positions_js', 99 );
function stirfr_auto_positions_js(): void {
	if ( ! is_category() || is_feed() ) {
		return;
	}

	$cat = get_queried_object();
	if ( ! $cat || empty( $cat->term_id ) ) {
		return;
	}

	$profiles = stirfr_get_profiles(); // defined in functions.php
	$before_header = '';
	$after_header  = '';
	$before_footer = '';

	foreach ( $profiles as $id => $profile ) {
		if ( empty( $profile['active'] ) || empty( $profile['category'] ) ) {
			continue;
		}
		if ( (int) $profile['category'] !== (int) $cat->term_id ) {
			continue;
		}

		$pos = $profile['position'] ?? '';

		if ( $pos === 'before_header' && ! $before_header ) {
			$before_header = do_shortcode( '[stirfr_rss_feed id="' . (int) $id . '"]' );
		}
		if ( $pos === 'after_header' && ! $after_header ) {
			$after_header = do_shortcode( '[stirfr_rss_feed id="' . (int) $id . '"]' );
		}
		if ( $pos === 'before_footer' && ! $before_footer ) {
			$before_footer = do_shortcode( '[stirfr_rss_feed id="' . (int) $id . '"]' );
		}
	}

	if ( ! $before_header && ! $after_header && ! $before_footer ) {
		return;
	}
	?>
	<script>
	document.addEventListener("DOMContentLoaded", function () {
		var header = document.querySelector("header.site-header,#masthead,header,.site-header");
		var footer = document.querySelector("footer.site-footer,#colophon,footer,.site-footer");

		if (header) {
			<?php if ( $before_header ) : ?>
			header.insertAdjacentHTML("beforebegin", <?php echo wp_json_encode( $before_header ); ?>);
			<?php endif; ?>
			<?php if ( $after_header ) : ?>
			header.insertAdjacentHTML("afterend", <?php echo wp_json_encode( $after_header ); ?>);
			<?php endif; ?>
		}

		<?php if ( $before_footer ) : ?>
		if (footer) {
			footer.insertAdjacentHTML("beforebegin", <?php echo wp_json_encode( '<div class="stirfr-before-footer">' . $before_footer . '</div>' ); ?>);
		}
		<?php endif; ?>
	});
	</script>
	<?php
}

// ── Content position hooks ────────────────────────────────────────────────────

function stirfr_render_profile_by_position( string $position ): void {
	if ( ! is_category() || is_feed() ) {
		return;
	}

	$cat = get_queried_object();
	if ( ! $cat || empty( $cat->term_id ) ) {
		return;
	}

	foreach ( stirfr_get_profiles() as $id => $profile ) {
		if ( empty( $profile['active'] ) || empty( $profile['category'] ) ) {
			continue;
		}
		if ( (int) $profile['category'] !== (int) $cat->term_id ) {
			continue;
		}
		if ( ( $profile['position'] ?? 'before_content' ) !== $position ) {
			continue;
		}
		echo do_shortcode( '[stirfr_rss_feed id="' . (int) $id . '"]' );
		break;
	}
}

add_action( 'loop_start', function ( WP_Query $query ): void {
	if ( $query->is_main_query() ) {
		stirfr_render_profile_by_position( 'before_content' );
	}
}, 5 );

add_action( 'loop_end', function ( WP_Query $query ): void {
	if ( $query->is_main_query() ) {
		stirfr_render_profile_by_position( 'after_content' );
	}
}, 20 );

add_action( 'wp_footer', function (): void {
	stirfr_render_profile_by_position( 'after_footer' );
}, 50 );

// ════════════════════════════════════════════════════════════════
// HELPER: Local feed fallback (avoids loopback HTTP requests)
// ════════════════════════════════════════════════════════════════

function stirfr_local_feed_fallback( string $feed_url, int $feed_items, string $default_image, int $store_posts, string $pstatus ): array {
	$query_args = [
		'post_type'      => 'post',
		'post_status'    => 'publish',
		'posts_per_page' => $feed_items,
		'orderby'        => 'date',
		'order'          => 'DESC',
	];

	// Try to detect category from feed URL path
	$feed_path = (string) wp_parse_url( $feed_url, PHP_URL_PATH );
	$feed_path = trim( $feed_path, '/' );

	// Remove trailing /feed or /feed/rss2 etc.
	$feed_path = preg_replace( '#/feed(/.*)?$#', '', $feed_path ) ?? $feed_path;

	// Match common WordPress URL patterns
	$category_base = ltrim( (string) get_option( 'category_base', 'category' ), '/' ) ?: 'category';
	$tag_base      = ltrim( (string) get_option( 'tag_base', 'tag' ), '/' ) ?: 'tag';

	if ( preg_match( '#^' . preg_quote( $category_base, '#' ) . '/(.+)$#', $feed_path, $m ) ) {
		$cat = get_category_by_slug( sanitize_title( $m[1] ) );
		if ( $cat ) {
			$query_args['cat'] = $cat->term_id;
		}
	} elseif ( preg_match( '#^' . preg_quote( $tag_base, '#' ) . '/(.+)$#', $feed_path, $m ) ) {
		$query_args['tag'] = sanitize_title( $m[1] );
	} elseif ( preg_match( '#^author/(.+)$#', $feed_path, $m ) ) {
		$author = get_user_by( 'slug', sanitize_title( $m[1] ) );
		if ( $author ) {
			$query_args['author'] = $author->ID;
		}
	}

	$posts = get_posts( $query_args );
	if ( empty( $posts ) ) {
		return [];
	}

	$rows = [];
	foreach ( $posts as $post ) {
		$thumb_url = (string) get_the_post_thumbnail_url( $post->ID, 'medium' );

		// If no featured image, try to find the first image in post content
		if ( empty( $thumb_url ) && ! empty( $post->post_content ) ) {
			if ( preg_match( '/<img[^>]+src=["\']([^"\']+)["\']/', $post->post_content, $img_match ) ) {
				$candidate = $img_match[1] ?? '';
				if ( stirfr_is_valid_image_url( $candidate ) ) {
					$thumb_url = $candidate;
				}
			}
		}

		$image = stirfr_resolve_image_url( $thumb_url, $default_image );

		$excerpt_text = has_excerpt( $post->ID )
			? get_the_excerpt( $post )
			: wp_trim_words( wp_strip_all_tags( $post->post_content, true ), 30, '…' );

		$cats     = get_the_category( $post->ID );
		$cat_name = ( ! empty( $cats ) && is_array( $cats ) ) ? $cats[0]->name : '';

		$row = [
			'title'    => get_the_title( $post ),
			'link'     => (string) get_permalink( $post->ID ),
			'date'     => (int) get_post_timestamp( $post ),
			'image'    => $image,
			'excerpt'  => esc_html( (string) $excerpt_text ),
			'category' => esc_html( $cat_name ),
			'host'     => (string) wp_parse_url( home_url(), PHP_URL_HOST ),
			'source'   => (string) get_permalink( $post->ID ),
			'raw_item' => null,
		];

		$rows[] = $row;
	}

	return $rows;
}

// ════════════════════════════════════════════════════════════════
// SHORTCODE: [stirfr_rss_feed id="X"]
// ════════════════════════════════════════════════════════════════

add_shortcode( 'stirfr_rss_feed', 'stirfr_display_feed' );

function stirfr_display_feed( array $atts ): string {
	$atts = shortcode_atts( [ 'id' => 0 ], $atts, 'stirfr_rss_feed' );

	$profile_id = (int) $atts['id'];
	if ( $profile_id <= 0 ) {
		return '<p>' . esc_html__( 'No profile ID provided.', 'sti-rss-feed-reader' ) . '</p>';
	}

	$profiles = stirfr_get_profiles();
	if ( ! isset( $profiles[ $profile_id ] ) ) {
		// translators: %s is the numeric profile ID.
		return '<p>' . sprintf( esc_html__( 'No feed profile found for ID %s.', 'sti-rss-feed-reader' ), esc_html( (string) $profile_id ) ) . '</p>';
	}

	$profile = $profiles[ $profile_id ];

	// ── Visibility check ─────────────────────────────────────────
	if ( ! empty( $profile['category'] ) ) {
		$cat_id      = (int) $profile['category'];
		$allow_pages = ! empty( $profile['allow_pages'] );

		if ( is_category() ) {
			if ( ! is_category( $cat_id ) ) { return ''; }
		} elseif ( is_single() ) {
			if ( ! has_category( $cat_id ) ) { return ''; }
		} elseif ( is_page() ) {
			if ( ! $allow_pages ) { return ''; }
		} else {
			return '';
		}
	}

	$feed_urls     = is_array( $profile['urls'] ?? null ) ? $profile['urls'] : [];
	$feed_items    = max( 1, (int) ( $profile['items']   ?? 5 ) );
	$default_image = (string) ( $profile['image']   ?? '' );
	$feed_layout   = (string) ( $profile['layout']  ?? 'list' );
	$show_powered  = (int) ( $profile['powered'] ?? 1 );
	$grid_columns  = max( 1, min( 6, (int) ( $profile['cols'] ?? 2 ) ) );
	$store_posts   = (int) ( $profile['store']   ?? 0 );
	$pstatus       = (string) ( $profile['status'] ?? get_option( 'stirfr_store_status', 'draft' ) );

	if ( empty( $feed_urls ) ) {
		// translators: %s is the numeric profile ID.
		return '<p>' . sprintf( esc_html__( 'No feed URLs found for profile ID %s.', 'sti-rss-feed-reader' ), esc_html( (string) $profile_id ) ) . '</p>';
	}

	add_filter( 'wp_feed_cache_transient_lifetime', function() {
		return 6 * HOUR_IN_SECONDS;
	} );

	$all = [];
	foreach ( $feed_urls as $feed_url ) {
		$feed_url = trim( (string) $feed_url );
		if ( $feed_url === '' ) {
			continue;
		}

		$rss = fetch_feed( $feed_url );
		if ( is_wp_error( $rss ) || ! $rss ) {
			// Loopback fallback: if this is our own site's feed, query posts directly
			$feed_host = (string) wp_parse_url( $feed_url, PHP_URL_HOST );
			$site_host = (string) wp_parse_url( home_url(), PHP_URL_HOST );

			if ( $feed_host && $site_host && strcasecmp( $feed_host, $site_host ) === 0 ) {
				$local_items = stirfr_local_feed_fallback( $feed_url, $feed_items, $default_image, $store_posts, $pstatus );
				if ( ! empty( $local_items ) ) {
					$all = array_merge( $all, $local_items );
				}
			}
			continue;
		}

		foreach ( (array) $rss->get_items( 0, $feed_items ) as $item ) {
			$image_raw = stirfr_get_feed_image( $item, '' );

			if ( ! $image_raw && ! $store_posts ) {
				$item_link = (string) $item->get_link();
				$cache_key = 'stirfr_img_' . md5( $item_link );
				$cached    = get_transient( $cache_key );
				if ( false !== $cached ) {
					$image_raw = $cached === 'none' ? '' : (string) $cached;
				} elseif ( ! wp_next_scheduled( 'stirfr_bg_fetch_image', [ $item_link ] ) ) {
					wp_schedule_single_event( time() + 5, 'stirfr_bg_fetch_image', [ $item_link ] );
				}
			}

			$row = [
				'title'    => (string) ( $item->get_title()     ?? '' ),
				'link'     => (string) ( $item->get_permalink() ?? '' ),
				'date'     => (int)    ( $item->get_date( 'U' ) ?: time() ),
				'image'    => stirfr_resolve_image_url( $image_raw, $default_image ),
				'excerpt'  => stirfr_get_excerpt_text( $item, 30 ),
				'category' => stirfr_get_primary_category( $item ),
				'host'     => (string) wp_parse_url( $feed_url, PHP_URL_HOST ),
				'source'   => (string) ( $item->get_id( true ) ?: $item->get_link() ?: '' ),
				'raw_item' => $item,
			];

			if ( $store_posts ) {
				stirfr_store_feed_item_as_post( array_merge( $row, [
					'feed_origin_url' => $feed_url,
					'post_status'     => in_array( $pstatus, [ 'publish', 'draft', 'pending' ], true ) ? $pstatus : get_option( 'stirfr_store_status', 'draft' ),
					'content_html'    => stirfr_get_item_content_html( $item ),
				] ) );
			}

			$all[] = $row;
		}
	}

	remove_all_filters( 'wp_feed_cache_transient_lifetime' );

	if ( empty( $all ) ) {
		return '<p>' . esc_html__( 'No items found in any feed.', 'sti-rss-feed-reader' ) . '</p>';
	}

	usort( $all, fn( $a, $b ) => $b['date'] <=> $a['date'] );
	$all = array_slice( $all, 0, $feed_items );

	// ── Render ───────────────────────────────────────────────────
	$wrapper_class = $feed_layout === 'grid'
		? 'stirfr-feed stirfr-grid stirfr-cols-' . (int) $grid_columns
		: 'stirfr-feed';

	$style_parts = [];
	if ( $feed_layout === 'grid' ) {
		$style_parts[] = '--stirfr-cols:' . (int) $grid_columns;
	}
	$color_vars = stirfr_get_color_style_attr();
	if ( $color_vars ) {
		$style_parts[] = $color_vars;
	}

	$style_attr = $style_parts
		? ' style="' . esc_attr( implode( ';', $style_parts ) ) . '"'
		: '';

	$allowed_excerpt_tags = [
		'a'      => [ 'href' => [], 'title' => [], 'target' => [], 'rel' => [], 'class' => [] ],
		'p'      => [],
		'br'     => [],
		'strong' => [],
		'em'     => [],
		'span'   => [ 'class' => [] ],
	];

	ob_start();
	?>
	<div class="<?php echo esc_attr( $wrapper_class ); ?>"<?php echo $style_attr; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- already escaped ?>>
		<?php foreach ( $all as $it ) :
			$title_out   = esc_html( $it['title'] ?: 'Untitled' );
			$href        = esc_url( $it['link'] );
			$img_src     = $it['image'];

			$excerpt_html = wp_kses(
				stirfr_build_excerpt_with_read_more( [
					'raw_item' => $it['raw_item'],
					'title'    => $it['title'],
					'link'     => $it['link'],
					'source'   => $it['source'],
				], 20 ),
				$allowed_excerpt_tags
			);

			$pid         = stirfr_get_published_post_id_with_content( $it );
			$final_href  = $pid ? ( (string) ( get_permalink( $pid ) ?: '' ) ) : (string) ( $it['link'] ?? '' );
			$is_external = ( ! $pid && $final_href );
		?>
		<div class="stirfr-item" role="article">
			<?php if ( $final_href ) : ?>
				<a class="stirfr-card-link"
				   href="<?php echo esc_url( $final_href ); ?>"
				   aria-label="<?php echo esc_attr( $title_out ); ?>"
				   <?php if ( $is_external ) { echo 'target="_blank" rel="noopener noreferrer"'; } ?>></a>
			<?php endif; ?>

			<div class="stirfr-content">
				<?php if ( $final_href ) : ?>
					<a class="stirfr-title" href="<?php echo esc_url( $final_href ); ?>"
					   <?php if ( $is_external ) { echo 'target="_blank" rel="nofollow noopener"'; } ?>>
						<?php echo esc_html( $title_out ); ?>
					</a>
				<?php else : ?>
					<div class="stirfr-title"><?php echo esc_html( $title_out ); ?></div>
				<?php endif; ?>

				<?php if ( ! empty( $it['category'] ) ) : ?>
					<div class="stirfr-category"><?php echo esc_html( $it['category'] ); ?></div>
				<?php endif; ?>
			</div>

			<?php if ( $img_src ) : ?>
				<div class="stirfr-thumb">
					<img src="<?php echo esc_url( $img_src ); ?>" alt="<?php echo esc_attr( $title_out ); ?>" loading="lazy">
				</div>
			<?php endif; ?>

			<?php echo wp_kses_post( $excerpt_html ); ?>

			<?php if ( $show_powered && ! empty( $it['host'] ) ) : ?>
				<div class="stirfr-powered">
					<?php esc_html_e( 'Source:', 'sti-rss-feed-reader' ); ?>
					<a href="<?php echo esc_url( $it['link'] ); ?>" target="_blank" rel="nofollow noopener">
						<?php echo esc_html( (string) $it['host'] ); ?>
					</a>
				</div>
			<?php endif; ?>
		</div>
		<?php endforeach; ?>
	</div>
	<?php
	return (string) ob_get_clean();
}

// ════════════════════════════════════════════════════════════════
// SHORTCODE: [stirfr_rss_links]
// ════════════════════════════════════════════════════════════════

add_shortcode( 'stirfr_rss_links', 'stirfr_rss_links_shortcode' );

function stirfr_rss_links_shortcode( $atts ): string {
	$atts = shortcode_atts( [
		'type'       => 'site',
		'style'      => 'buttons',
		'show_count' => 'no',
		'show_icon'  => 'yes',
		'label'      => '',
		'title'      => '',
		'target'     => '_blank',
		'color'      => '',
		'bg'         => '',
	], $atts, 'stirfr_rss_links' );

	$type       = sanitize_key( $atts['type'] );
	$style      = sanitize_key( $atts['style'] );
	$show_count = $atts['show_count'] === 'yes';
	$show_icon  = $atts['show_icon'] === 'yes';
	$label      = sanitize_text_field( $atts['label'] );
	$title      = sanitize_text_field( $atts['title'] );
	$target     = $atts['target'] === '_self' ? '_self' : '_blank';
	$color      = sanitize_hex_color( $atts['color'] ) ?: '';
	$bg         = sanitize_hex_color( $atts['bg'] ) ?: '';

	$rss_icon = $show_icon
		? '<svg class="stirfr-rss-icon" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="16" height="16" fill="currentColor"><path d="M6.18 15.64a2.18 2.18 0 1 1 0 4.36 2.18 2.18 0 0 1 0-4.36zM4 4.44v2.83c7.03 0 12.73 5.7 12.73 12.73h2.83C19.56 11.33 12.67 4.44 4 4.44zm0 5.66v2.83c3.9 0 7.07 3.17 7.07 7.07h2.83c0-5.47-4.43-9.9-9.9-9.9z"/></svg> '
		: '';

	$inline_style = '';
	$style_parts  = [];
	if ( $color ) {
		$style_parts[] = 'color:' . $color;
	}
	if ( $bg ) {
		$style_parts[] = 'background:' . $bg;
		$style_parts[] = 'border-color:' . $bg;
		$style_parts[] = 'box-shadow:0 2px 8px ' . $bg . '33';
	}
	if ( $style_parts ) {
		$inline_style = ' style="' . esc_attr( implode( ';', $style_parts ) ) . '"';
	}

	$items = [];

	// Site-wide feed (default).
	if ( $type === 'site' || $type === 'all' ) {
		$items[] = [
			'label' => $label ?: __( 'RSS Feed', 'sti-rss-feed-reader' ),
			'url'   => get_bloginfo( 'rss2_url' ),
			'count' => (int) wp_count_posts()->publish,
		];
	}

	// Categories.
	if ( $type === 'categories' || $type === 'all' ) {
		$cats = get_categories( [ 'hide_empty' => true ] );
		foreach ( $cats as $cat ) {
			$items[] = [
				'label' => $cat->name,
				'url'   => get_category_feed_link( $cat->term_id ),
				'count' => (int) $cat->count,
			];
		}
	}

	// Tags.
	if ( $type === 'tags' || $type === 'all' ) {
		$tags = get_tags( [ 'hide_empty' => true ] );
		if ( is_array( $tags ) ) {
			foreach ( $tags as $tag ) {
				$items[] = [
					'label' => $tag->name,
					'url'   => get_tag_feed_link( $tag->term_id ),
					'count' => (int) $tag->count,
				];
			}
		}
	}

	if ( empty( $items ) ) {
		return '<p class="stirfr-rss-empty">' . esc_html__( 'No feeds available.', 'sti-rss-feed-reader' ) . '</p>';
	}

	$wrapper_class = 'stirfr-rss-links stirfr-rss-' . esc_attr( $style );

	ob_start();
	?>
	<div class="<?php echo esc_attr( $wrapper_class ); ?>">
		<?php if ( $title ) : ?>
			<h3 class="stirfr-rss-heading"><?php echo esc_html( $title ); ?></h3>
		<?php endif; ?>

		<?php if ( $style === 'list' ) : ?>
			<ul class="stirfr-rss-list">
				<?php foreach ( $items as $item ) : ?>
					<li>
						<a href="<?php echo esc_url( $item['url'] ); ?>" target="<?php echo esc_attr( $target ); ?>" rel="noopener" class="stirfr-rss-link"<?php echo $inline_style; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- already escaped ?>>
							<?php echo $rss_icon; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG icon ?>
							<?php echo esc_html( $item['label'] ); ?>
							<?php if ( $show_count && $item['count'] > 0 ) : ?>
								<span class="stirfr-rss-count"><?php echo (int) $item['count']; ?></span>
							<?php endif; ?>
						</a>
					</li>
				<?php endforeach; ?>
			</ul>
		<?php else : ?>
			<div class="stirfr-rss-items">
				<?php foreach ( $items as $item ) : ?>
					<a href="<?php echo esc_url( $item['url'] ); ?>" target="<?php echo esc_attr( $target ); ?>" rel="noopener" class="stirfr-rss-btn"<?php echo $inline_style; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- already escaped ?>>
						<?php echo $rss_icon; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG icon ?>
						<?php echo esc_html( $item['label'] ); ?>
						<?php if ( $show_count && $item['count'] > 0 ) : ?>
							<span class="stirfr-rss-count"><?php echo (int) $item['count']; ?></span>
						<?php endif; ?>
					</a>
				<?php endforeach; ?>
			</div>
		<?php endif; ?>
	</div>
	<?php
	return (string) ob_get_clean();
}

// ════════════════════════════════════════════════════════════════
// SHORTCODE: [stirfr_rss_link]
// ════════════════════════════════════════════════════════════════

add_shortcode( 'stirfr_rss_link', 'stirfr_rss_link_shortcode' );

function stirfr_rss_link_shortcode( $atts ): string {
	$atts = shortcode_atts( [
		'category'  => '',
		'tag'       => '',
		'label'     => '',
		'show_icon' => 'yes',
		'target'    => '_blank',
		'color'     => '',
		'bg'        => '',
		'style'     => 'solid',
	], $atts, 'stirfr_rss_link' );

	$cat_input  = sanitize_text_field( $atts['category'] );
	$tag_input  = sanitize_text_field( $atts['tag'] );
	$label      = sanitize_text_field( $atts['label'] );
	$show_icon  = $atts['show_icon'] === 'yes';
	$target     = $atts['target'] === '_self' ? '_self' : '_blank';
	$color      = sanitize_hex_color( $atts['color'] ) ?: '';
	$bg         = sanitize_hex_color( $atts['bg'] ) ?: '';
	$btn_style  = sanitize_key( $atts['style'] );

	$feed_url   = '';
	$feed_label = '';

	// Find category by slug or name.
	if ( $cat_input ) {
		$cat = get_category_by_slug( $cat_input );
		if ( ! $cat ) {
			$cat = get_term_by( 'name', $cat_input, 'category' );
		}
		if ( $cat ) {
			$feed_url   = get_category_feed_link( $cat->term_id );
			$feed_label = $label ?: $cat->name;
		}
	}

	// Find tag by slug or name.
	if ( ! $feed_url && $tag_input ) {
		$tag = get_term_by( 'slug', $tag_input, 'post_tag' );
		if ( ! $tag ) {
			$tag = get_term_by( 'name', $tag_input, 'post_tag' );
		}
		if ( $tag ) {
			$feed_url   = get_tag_feed_link( $tag->term_id );
			$feed_label = $label ?: $tag->name;
		}
	}

	if ( ! $feed_url ) {
		return '<!-- stirfr_rss_link: category or tag not found -->';
	}

	$rss_icon = $show_icon
		? '<svg class="stirfr-rss-icon" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="16" height="16" fill="currentColor"><path d="M6.18 15.64a2.18 2.18 0 1 1 0 4.36 2.18 2.18 0 0 1 0-4.36zM4 4.44v2.83c7.03 0 12.73 5.7 12.73 12.73h2.83C19.56 11.33 12.67 4.44 4 4.44zm0 5.66v2.83c3.9 0 7.07 3.17 7.07 7.07h2.83c0-5.47-4.43-9.9-9.9-9.9z"/></svg> '
		: '';

	$style_parts = [];
	if ( $btn_style === 'outline' ) {
		$border_color = $bg ?: '#f97316';
		$style_parts[] = 'background:transparent';
		$style_parts[] = 'color:' . ( $color ?: $border_color );
		$style_parts[] = 'border:2px solid ' . $border_color;
		$style_parts[] = 'box-shadow:none';
	} else {
		if ( $color ) {
			$style_parts[] = 'color:' . $color;
		}
		if ( $bg ) {
			$style_parts[] = 'background:' . $bg;
			$style_parts[] = 'border-color:' . $bg;
			$style_parts[] = 'box-shadow:0 2px 8px ' . $bg . '33';
		}
	}

	$inline_style = $style_parts
		? ' style="' . esc_attr( implode( ';', $style_parts ) ) . '"'
		: '';

	return '<a href="' . esc_url( $feed_url ) . '" target="' . esc_attr( $target ) . '" rel="noopener" class="stirfr-rss-btn"' . $inline_style . '>'
		. $rss_icon
		. esc_html( $feed_label )
		. '</a>';
}

// ════════════════════════════════════════════════════════════════
// SHORTCODE: [stirfr_breaking_news]
// ════════════════════════════════════════════════════════════════

add_shortcode( 'stirfr_breaking_news', 'stirfr_breaking_news_shortcode' );

function stirfr_breaking_news_shortcode(): string {
	if ( ! get_option( 'stirfr_ticker_enabled', 1 ) ) {
		return '';
	}

	// Admin live-preview via AJAX POST (uses nonce from functions.php)
	if ( is_admin() && isset( $_POST['stirfr_ticker_source'], $_POST['stirfr_nonce'] ) &&
		wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['stirfr_nonce'] ) ), 'stirfr_ticker_action' )
	) {
		$source  = sanitize_text_field( wp_unslash( $_POST['stirfr_ticker_source'] ) );
		$custom  = sanitize_text_field( wp_unslash( $_POST['stirfr_ticker_custom_url'] ?? '' ) );
		$profile = (int) sanitize_text_field( wp_unslash( $_POST['stirfr_ticker_profile'] ?? '0' ) );
	} else {
		$source  = (string) get_option( 'stirfr_ticker_source', 'global' );
		$custom  = (string) get_option( 'stirfr_ticker_custom_url', '' );
		$profile = (int)   get_option( 'stirfr_ticker_profile', 0 );
	}

	// Stored posts source
	if ( $source === 'stored' ) {
		$posts = get_posts( [
			'post_type'      => 'post',
			'posts_per_page' => (int) get_option( 'stirfr_ticker_post_count', 5 ),
			'post_status'    => 'publish',
			'orderby'        => 'date',
			'order'          => 'DESC',
		] );

		$items = [];
		foreach ( $posts as $p ) {
			$items[] = [ 'title' => esc_html( $p->post_title ), 'link' => get_permalink( $p->ID ) ];
		}
		return stirfr_render_ticker_html( $items );
	}

	// Build feeds list
	$feeds = [];
	if ( $source === 'global' ) {
		$feeds = (array) get_option( 'stirfr_feed_urls', [] );
	} elseif ( $source === 'profile' ) {
		$profiles = stirfr_get_profiles();
		if ( ! empty( $profiles[ $profile ]['urls'] ) ) {
			$feeds = $profiles[ $profile ]['urls'];
		}
	} elseif ( $source === 'custom' && ! empty( $custom ) ) {
		$feeds = [ $custom ];
	}

	if ( ! function_exists( 'fetch_feed' ) ) {
		require_once ABSPATH . WPINC . '/feed.php';
	}

	$items = [];
	foreach ( $feeds as $url ) {
		$url = trim( (string) $url );
		if ( ! $url ) {
			continue;
		}
		$feed = fetch_feed( $url );
		if ( is_wp_error( $feed ) ) {
			continue;
		}
		foreach ( (array) $feed->get_items( 0, 10 ) as $it ) {
			$items[] = [
				'title' => esc_html( (string) ( $it->get_title() ?? '' ) ),
				'link'  => esc_url( (string) ( $it->get_link()  ?? '' ) ),
			];
		}
	}

	return stirfr_render_ticker_html( $items );
}

function stirfr_render_ticker_html( array $items ): string {
	if ( empty( $items ) ) {
		$items = [
			[ 'title' => 'Breaking: Demo News Headline 1', 'link' => '#' ],
			[ 'title' => 'Latest Update: Demo News Headline 2', 'link' => '#' ],
			[ 'title' => 'News Flash: Demo News Headline 3', 'link' => '#' ],
		];
	}

	$bg        = esc_attr( (string) get_option( 'stirfr_ticker_bg',   '#111111' ) );
	$text      = esc_attr( (string) get_option( 'stirfr_ticker_text', '#ffffff' ) );
	$speed     = max( 5, min( 120, (int) get_option( 'stirfr_ticker_speed', 30 ) ) );
	$direction = get_option( 'stirfr_ticker_direction', 'left' ) === 'right' ? '50%' : '-50%';
	$label     = esc_html( (string) get_option( 'stirfr_ticker_label', 'BREAKING NEWS' ) );

	$loop_items = array_merge( $items, $items );

	ob_start();
	?>
	<div class="stirfr-breaking-news" style="--stirfr-bg:<?php echo esc_attr( $bg ); ?>;--stirfr-text:<?php echo esc_attr( $text ); ?>;--stirfr-speed:<?php echo esc_attr( (string) $speed ); ?>s;--stirfr-direction:<?php echo esc_attr( $direction ); ?>">
		<div class="stirfr-label"><?php echo esc_html( $label ); ?></div>
		<div class="stirfr-ticker">
			<div class="stirfr-ticker-track">
				<?php foreach ( $loop_items as $item ) : ?>
					<span class="stirfr-ticker-item">
						<a href="<?php echo esc_url( $item['link'] ); ?>" target="_blank">
							<?php echo esc_html( $item['title'] ); ?>
						</a>
					</span>
				<?php endforeach; ?>
			</div>
		</div>
	</div>
	<?php
	return (string) ob_get_clean();
}