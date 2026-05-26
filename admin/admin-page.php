<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'STIRFR_PLUGIN_FILE' ) ) {
	define( 'STIRFR_PLUGIN_FILE',
		file_exists( dirname( __DIR__ ) . '/sti-rss-feed-reader.php' )
			? dirname( __DIR__ ) . '/sti-rss-feed-reader.php'
			: __FILE__
	);
}
if ( ! defined( 'STIRFR_PLUGIN_URL' ) ) {
	define( 'STIRFR_PLUGIN_URL', plugin_dir_url( STIRFR_PLUGIN_FILE ) );
}

if ( ! defined( 'STIRFR_DEFAULT_FALLBACK_IMAGE' ) ) {
	define(
		'STIRFR_DEFAULT_FALLBACK_IMAGE',
		trailingslashit( STIRFR_PLUGIN_URL ) . 'assets/img/default_fallback.png'
	);
}

// ── Register menu ─────────────────────────────────────────────────────────────
add_action( 'admin_menu', 'stirfr_add_admin_menu' );
function stirfr_add_admin_menu(): void {
	add_menu_page(
		'STI RSS Feed',
		'STI RSS Feed',
		'manage_options',
		'simple-rss-feed',
		'stirfr_admin_page',
		STIRFR_PLUGIN_URL . 'assets/img/stifeeds-icon.png',
		20
	);
}

// ── Delete profile data (used by admin) ───────────────────────────────────────
function stirfr_delete_profile_data( int $profile_id ): void {
	if ( $profile_id <= 0 ) {
		return;
	}

	global $wpdb;
	$cache_key = 'stirfr_profile_posts_' . $profile_id;
	$post_ids  = wp_cache_get( $cache_key, 'stirfr' );

	if ( false === $post_ids ) {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$post_ids = $wpdb->get_col( $wpdb->prepare(
			"SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value = %d",
			'_stirfr_profile_id', $profile_id
		) );
		wp_cache_set( $cache_key, $post_ids, 'stirfr', 300 );
	}

	foreach ( (array) $post_ids as $pid ) {
		$pid      = (int) $pid;
		$thumb_id = get_post_thumbnail_id( $pid );
		if ( $thumb_id ) {
			wp_delete_attachment( (int) $thumb_id, true );
		}
		wp_delete_post( $pid, true );
	}

	wp_cache_delete( $cache_key, 'stirfr' );
}

// ── Main admin page ───────────────────────────────────────────────────────────
function stirfr_admin_page(): void {

	/* ── SAVE HANDLER ── */
	if ( isset( $_POST['srf_save_all'] ) || isset( $_POST['srf_run_cleanup_now'] ) ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions', 'sti-rss-feed-reader' ) );
		}

		$post  = wp_unslash( $_POST );
		$nonce = sanitize_text_field( $post['srf_all_nonce'] ?? '' );
		if ( ! wp_verify_nonce( $nonce, 'srf_all_save' ) ) {
			wp_die( esc_html__( 'Security check failed.', 'sti-rss-feed-reader' ) );
		}

		// Removed profiles
		if ( ! empty( $post['stirfr_removed_profiles'] ) ) {
			foreach ( array_map( 'intval', explode( ',', sanitize_text_field( $post['stirfr_removed_profiles'] ) ) ) as $rid ) {
				stirfr_delete_profile_data( $rid );
			}
		}

		// Colors
		update_option( 'stirfr_card_color',     sanitize_hex_color( $post['stirfr_card_color']     ?? '' ) ?: '' );
		update_option( 'stirfr_text_color',     sanitize_hex_color( $post['stirfr_text_color']     ?? '' ) ?: '' );
		update_option( 'stirfr_readmore_color', sanitize_hex_color( $post['stirfr_readmore_color'] ?? '' ) ?: '' );

		// ── Profiles ──
		$existing = stirfr_get_profiles();  // defined in functions.php
		$new      = [];

		$ids         = (array) ( $post['profile_id']          ?? [] );
		$active      = (array) ( $post['profile_active']       ?? [] );
		$titles      = (array) ( $post['profile_title']        ?? [] );
		$urls        = (array) ( $post['profile_urls']         ?? [] );
		$layouts     = (array) ( $post['profile_layout']       ?? [] );
		$items       = (array) ( $post['profile_items']        ?? [] );
		$cols        = (array) ( $post['profile_cols']         ?? [] );
		$powered     = (array) ( $post['profile_powered']      ?? [] );
		$store       = (array) ( $post['profile_store']        ?? [] );
		$images      = (array) ( $post['profile_image']        ?? [] );
		$pstatus     = (array) ( $post['profile_status']       ?? [] );
		$cats        = (array) ( $post['profile_category']     ?? [] );
		$allow_pages = (array) ( $post['profile_allow_pages']  ?? [] );
		$positions   = (array) ( $post['profile_position']     ?? [] );

		$rows = max( count( $titles ), count( $urls ), count( $layouts ), count( $images ) );

		for ( $i = 0; $i < $rows; $i++ ) {
			$raw_title = sanitize_text_field( (string) ( $titles[ $i ] ?? '' ) );
			$raw_urls  = (string) ( $urls[ $i ] ?? '' );
			$list      = array_values( array_filter( array_map( 'trim', preg_split( '/\R+/', $raw_urls ) ) ) );
			$list      = array_map( 'esc_url_raw', $list );
			$img_val   = trim( (string) ( $images[ $i ] ?? '' ) );

			if ( $raw_title === '' && empty( $list ) && $img_val === '' ) {
				continue;
			}

			$id = isset( $ids[ $i ] ) ? (int) $ids[ $i ] : 0;
			if ( $id <= 0 ) {
				$id = stirfr_next_profile_id( $new ?: $existing ); // defined in functions.php
			}

			$layout_val  = ( isset( $layouts[ $i ] ) && $layouts[ $i ] === 'grid' ) ? 'grid' : 'list';
			$num_items   = max( 1, min( 50, (int) ( $items[ $i ] ?? 5 ) ) );
			$num_cols    = max( 1, min( 6,  (int) ( $cols[ $i ]  ?? 2 ) ) );
			$status_val  = sanitize_key( (string) ( $pstatus[ $i ] ?? '' ) );
			$position_val = sanitize_key( (string) ( $positions[ $i ] ?? 'before_content' ) );

			if ( ! in_array( $status_val, [ 'publish', 'draft', 'pending' ], true ) ) {
				$status_val = get_option( 'stirfr_store_status', 'draft' );
			}

			$new[ $id ] = [
				'active'      => isset( $active[ $i ] ) ? 1 : 0,
				'title'       => $raw_title ?: ( 'Feed ' . $id ),
				'urls'        => $list,
				'layout'      => $layout_val,
				'items'       => $num_items,
				'cols'        => $num_cols,
				'powered'     => isset( $powered[ $i ] ) ? 1 : 0,
				'store'       => isset( $store[ $i ] ) ? 1 : 0,
				'image'       => $img_val ? esc_url_raw( $img_val ) : '',
				'status'      => $status_val,
				'category'    => (int) ( $cats[ $i ] ?? 0 ),
				'allow_pages' => ! empty( $allow_pages[ $i ] ) ? 1 : 0,
				'position'    => $position_val,
			];
		}

		update_option( 'stirfr_profiles', $new );

		// ── Global Settings ──
		$feed_urls_raw = sanitize_textarea_field( $post['stirfr_feed_urls'] ?? '' );
		$feed_urls     = array_map( 'esc_url_raw', array_filter( array_map( 'trim', preg_split( '/\R/', $feed_urls_raw ) ) ) );
		update_option( 'stirfr_feed_urls', $feed_urls );

		update_option( 'stirfr_feed_items', max( 1, min( 50, intval( $post['stirfr_feed_items'] ?? 5 ) ) ) );

		$default_image = isset( $post['stirfr_default_image'] ) && $post['stirfr_default_image'] !== ''
			? esc_url_raw( $post['stirfr_default_image'] )
			: STIRFR_DEFAULT_FALLBACK_IMAGE;
		update_option( 'stirfr_default_image', $default_image );

		update_option( 'stirfr_feed_layout',   ( ( $post['stirfr_feed_layout'] ?? 'list' ) === 'grid' ) ? 'grid' : 'list' );
		update_option( 'stirfr_show_poweredby', ! empty( $post['stirfr_show_poweredby'] ) ? 1 : 0 );
		update_option( 'stirfr_grid_columns',   max( 1, min( 6, intval( $post['stirfr_grid_columns'] ?? 2 ) ) ) );
		update_option( 'stirfr_store_posts',    ! empty( $post['stirfr_store_posts'] ) ? 1 : 0 );
		update_option( 'stirfr_store_days',     max( 1, min( 365, intval( $post['stirfr_store_days'] ?? 3 ) ) ) );

		$status = sanitize_key( $post['stirfr_store_status'] ?? 'draft' );
		update_option( 'stirfr_store_status', in_array( $status, [ 'publish', 'draft', 'pending' ], true ) ? $status : 'draft' );

		$expire_mode = sanitize_key( $post['stirfr_expire_mode'] ?? 'rolling' );
		update_option( 'stirfr_expire_mode', in_array( $expire_mode, [ 'rolling', 'midnight' ], true ) ? $expire_mode : 'rolling' );

		update_option( 'stirfr_lock_seconds', max( 10, min( 3600, intval( $post['stirfr_lock_seconds'] ?? 60 ) ) ) );

		$cleanup_action = sanitize_key( $post['stirfr_cleanup_action'] ?? 'trash' );
		update_option( 'stirfr_cleanup_action', in_array( $cleanup_action, [ 'trash', 'delete' ], true ) ? $cleanup_action : 'trash' );

		update_option( 'stirfr_readmore_button_enabled', ! empty( $post['stirfr_readmore_button_enabled'] ) ? 1 : 0 );
		update_option( 'stirfr_readmore_button_style',
			in_array( $post['stirfr_readmore_button_style'] ?? 'style1', [ 'style1', 'style2', 'style3', 'style4', 'style5', 'style6', 'style7', 'style8' ], true )
				? $post['stirfr_readmore_button_style'] : 'style1'
		);
		update_option( 'stirfr_readmore_button_text', sanitize_text_field( $post['stirfr_readmore_button_text'] ?? 'Read more' ) );

		// ── Ticker ──
		update_option( 'stirfr_ticker_position',   sanitize_key( $post['stirfr_ticker_position']   ?? 'after_header' ) );
		update_option( 'stirfr_ticker_enabled',    ! empty( $post['stirfr_ticker_enabled'] ) ? 1 : 0 );
		update_option( 'stirfr_ticker_speed',      max( 5, min( 120, intval( $post['stirfr_ticker_speed'] ?? 30 ) ) ) );
		update_option( 'stirfr_ticker_bg',         sanitize_hex_color( $post['stirfr_ticker_bg']   ?? '#111111' ) ?: '#111111' );
		update_option( 'stirfr_ticker_text',       sanitize_hex_color( $post['stirfr_ticker_text'] ?? '#ffffff' ) ?: '#ffffff' );
		update_option( 'stirfr_ticker_source',     sanitize_key( $post['stirfr_ticker_source']     ?? 'profile' ) );
		update_option( 'stirfr_ticker_profile',    intval( $post['stirfr_ticker_profile']          ?? 0 ) );
		update_option( 'stirfr_ticker_custom_url', esc_url_raw( $post['stirfr_ticker_custom_url']  ?? '' ) );
		update_option( 'stirfr_ticker_post_count', max( 1, min( 50, intval( $post['stirfr_ticker_post_count'] ?? 5 ) ) ) );

		// Clear feed transients
		foreach ( (array) get_option( 'stirfr_feed_urls', [] ) as $feed_url ) {
			delete_transient( 'feed_' . md5( $feed_url ) );
		}

		if ( isset( $post['srf_run_cleanup_now'] ) ) {
			global $wpdb;
			stirfr_delete_all_stored_posts_now();
			$like_feed    = $wpdb->esc_like( '_transient_feed_' ) . '%';
			$like_timeout = $wpdb->esc_like( '_transient_timeout_feed_' ) . '%';
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->query( $wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
				$like_feed, $like_timeout
			) );
			stirfr_import_latest_topN_now();
			echo '<div class="updated"><p>' . esc_html__( 'Saved. Cleanup executed and latest items imported.', 'sti-rss-feed-reader' ) . '</p></div>';
		} else {
			echo '<div class="updated"><p>' . esc_html__( 'Settings & Profiles saved successfully!', 'sti-rss-feed-reader' ) . '</p></div>';
		}
	}

	// ── Load current values ──
	$store_days     = (int)    get_option( 'stirfr_store_days',     3 );
	$expire_mode    = (string) get_option( 'stirfr_expire_mode',    'rolling' );
	$lock_seconds   = (int)    get_option( 'stirfr_lock_seconds',   60 );
	$cleanup_action = (string) get_option( 'stirfr_cleanup_action', 'trash' );
	$default_image  = (string) get_option( 'stirfr_default_image',  '' );

	$card_color     = sanitize_hex_color( (string) get_option( 'stirfr_card_color',     '#ffffff' ) ) ?: '#ffffff';
	$text_color     = sanitize_hex_color( (string) get_option( 'stirfr_text_color',     '#111111' ) ) ?: '#111111';
	$readmore_color = sanitize_hex_color( (string) get_option( 'stirfr_readmore_color', '#0073aa' ) ) ?: '#0073aa';

	$profiles       = stirfr_get_profiles(); // defined in functions.php
	$brand_logo_url = STIRFR_PLUGIN_URL . 'assets/img/santechidea-logo.png';
	$categories     = get_categories( [ 'hide_empty' => false ] );
	$valid_positions = [
		'after_header'   => 'After Header',
		'before_header'  => 'Before Header',
		'before_content' => 'Before Post',
		'after_content'  => 'After Post',
		'before_footer'  => 'Before Footer',
		'after_footer'   => 'After Footer',
	];
	?>

	<div class="srf-wrap">
		<div class="srf-header srf-header-spaced">
			<div class="srf-badge">STI</div>
			<h1 class="srf-title"><span class="srf-gradient">STI RSS Feed Reader</span></h1>
			<p class="srf-subtitle">Beautiful, fast, image-rich RSS blocks—store as posts, control layouts, keep your theme styling intact.</p>
			<?php if ( $brand_logo_url ) : ?>
				<div class="srf-brand">
					<img src="<?php echo esc_url( $brand_logo_url ); ?>" alt="SantechIdea">
					<small>SantechIdea</small>
				</div>
			<?php endif; ?>
			<div class="srf-meter"><span id="srfMeter"></span></div>
		</div>

		<div class="sti-admin-tabs">
			<h2 class="nav-tab-wrapper">
				<a href="#stirfr-dashboard" class="nav-tab nav-tab-active">Dashboard</a>
				<a href="#stirfr-settings"  class="nav-tab">Settings</a>
				<a href="#stirfr-ticker"    class="nav-tab">Ticker</a>
				<a href="#stirfr-rss"       class="nav-tab">RSS</a>
				<a href="#stirfr-support"   class="nav-tab">Support</a>
			</h2>

			<!-- Dashboard tab -->
			<div id="stirfr-dashboard" class="sti-tab-content sti-tab-padded">
				<div class="sti-welcome-wrap">
					<div class="sti-hero">
						<div class="sti-badge">New</div>
						<h1 class="sti-title"><span class="sti-gradient-text">STI RSS Feed Reader</span></h1>
						<p class="sti-subtitle">Pull beautiful, fast, image‑rich RSS blocks into your site—with optional post storage, layout controls, and theme‑friendly "Read more" links.</p>
						<div class="sti-actions">
							<a href="<?php echo esc_url( admin_url( 'admin.php?page=stirfr-welcome#docs' ) ); ?>" class="button button-hero">Shortcode & Docs</a>
						</div>
						<?php if ( $brand_logo_url ) : ?>
							<div class="sti-logo-pill">
								<img src="<?php echo esc_url( $brand_logo_url ); ?>" alt="SantechIdea">
								<span>Welcome</span>
							</div>
						<?php endif; ?>
						<div class="sti-ornament"><div class="sti-pulse"></div><div class="sti-pulse delay"></div></div>
					</div>

					<div class="sti-grid">
						<div class="sti-card"><div class="sti-icon">🖼️</div><h3>Smart Images</h3><p>Extract from feeds, use FIFU if available, or save WebP locally.</p></div>
						<div class="sti-card"><div class="sti-icon">🧱</div><h3>Flexible Layouts</h3><p>List, grid, or cards—clean markup that inherits your theme styles.</p></div>
						<div class="sti-card"><div class="sti-icon">📦</div><h3>Optional Post Storage</h3><p>Store items as WP posts, set retention, and clean up safely.</p></div>
					</div>

					<div class="sti-steps" id="docs">
						<h2><?php esc_html_e( 'Get Started in 3 Steps', 'sti-rss-feed-reader' ); ?></h2>
						<ol>
							<li><strong><?php esc_html_e( 'Add feed URLs', 'sti-rss-feed-reader' ); ?></strong> <?php esc_html_e( 'in Settings tab.', 'sti-rss-feed-reader' ); ?></li>
							<li><strong><?php esc_html_e( 'Choose a layout', 'sti-rss-feed-reader' ); ?></strong> <?php esc_html_e( '(list/grid) and count per feed.', 'sti-rss-feed-reader' ); ?></li>
							<li><strong><?php esc_html_e( 'Drop the shortcode', 'sti-rss-feed-reader' ); ?></strong> <?php esc_html_e( 'anywhere:', 'sti-rss-feed-reader' ); ?> <code>[stirfr_rss_feed id="1"]</code></li>
						</ol>
					</div>
				</div>
			</div>

			<form method="post" id="srfAllForm">
				<?php wp_nonce_field( 'srf_all_save', 'srf_all_nonce' ); ?>
				<input type="hidden" name="stirfr_removed_profiles" id="stirfr_removed_profiles" value="">

				<!-- Settings tab -->
				<div id="stirfr-settings" class="sti-tab-content sti-tab-padded">

					<!-- Feed Profiles -->
					<div class="srf-card srf-profiles">
						<h2><?php esc_html_e( 'Feed Profiles', 'sti-rss-feed-reader' ); ?></h2>
						<p class="srf-help"><?php esc_html_e( 'Create different feeds for different pages. Use shortcode', 'sti-rss-feed-reader' ); ?> <code>[stirfr_rss_feed id="X"]</code></p>

						<div class="srf-acc-list" id="srfProfilesList">
							<?php
							$i = 0;
							foreach ( $profiles as $id => $p ) :
								$p         = wp_parse_args( $p, [ 'active' => 1, 'title' => '', 'urls' => [], 'layout' => 'list', 'items' => 5, 'cols' => 2, 'powered' => 0, 'store' => 0, 'image' => '', 'status' => get_option( 'stirfr_store_status', 'draft' ), 'category' => 0, 'allow_pages' => 0, 'position' => 'before_content' ] );
								$urls_text = esc_textarea( implode( "\n", (array) $p['urls'] ) );
								$ps        = (string) ( $p['status'] ?? get_option( 'stirfr_store_status', 'draft' ) );
							?>
							<details class="srf-acc">
								<summary class="srf-acc-head">
									<span class="srf-acc-id"><?php echo (int) $id; ?></span>
									<label class="srf-acc-toggle">
										<input type="checkbox" name="profile_active[<?php echo esc_attr( (string) $i ); ?>]" <?php checked( (int) $p['active'], 1 ); ?>>
										<span><?php esc_html_e( 'Active', 'sti-rss-feed-reader' ); ?></span>
									</label>
									<input class="srf-acc-title" type="text" name="profile_title[<?php echo esc_attr( (string) $i ); ?>]" value="<?php echo esc_attr( $p['title'] ); ?>" placeholder="<?php esc_attr_e( 'Profile title…', 'sti-rss-feed-reader' ); ?>">
									<span class="srf-acc-shortcode"><code>[stirfr_rss_feed id="<?php echo (int) $id; ?>"]</code></span>
									<span class="srf-acc-actions">
										<button type="button" class="button button-small srf-dup-row"><?php esc_html_e( 'Duplicate', 'sti-rss-feed-reader' ); ?></button>
										<button type="button" class="button button-small srf-remove-row button-danger"><?php esc_html_e( 'Remove', 'sti-rss-feed-reader' ); ?></button>
									</span>
									<input type="hidden" name="profile_id[<?php echo esc_attr( (string) $i ); ?>]" value="<?php echo (int) $id; ?>">
								</summary>

								<div class="srf-acc-body">
									<div class="srf-acc-row">
										<label><?php esc_html_e( 'Feed URLs (one per line)', 'sti-rss-feed-reader' ); ?></label>
										<textarea name="profile_urls[<?php echo esc_attr( (string) $i ); ?>]" rows="4" placeholder="https://example.com/rss"><?php echo esc_html( $urls_text ); ?></textarea>
									</div>

									<div class="srf-acc-grid">
										<div>
											<label><?php esc_html_e( 'Layout', 'sti-rss-feed-reader' ); ?></label>
											<select name="profile_layout[<?php echo esc_attr( (string) $i ); ?>]">
												<option value="list" <?php selected( $p['layout'], 'list' ); ?>><?php esc_html_e( 'List', 'sti-rss-feed-reader' ); ?></option>
												<option value="grid" <?php selected( $p['layout'], 'grid' ); ?>><?php esc_html_e( 'Grid', 'sti-rss-feed-reader' ); ?></option>
											</select>
										</div>
										<div>
											<label><?php esc_html_e( 'Items', 'sti-rss-feed-reader' ); ?></label>
											<input type="number" min="1" max="50" name="profile_items[<?php echo esc_attr( (string) $i ); ?>]" value="<?php echo esc_attr( (string) (int) $p['items'] ); ?>">
										</div>
										<div>
											<label><?php esc_html_e( 'Grid Cols', 'sti-rss-feed-reader' ); ?></label>
											<input type="number" min="1" max="6" name="profile_cols[<?php echo esc_attr( (string) $i ); ?>]" value="<?php echo esc_attr( (string) (int) $p['cols'] ); ?>">
										</div>
										<div>
											<label><?php esc_html_e( 'Show only in Category', 'sti-rss-feed-reader' ); ?></label>
											<select name="profile_category[<?php echo esc_attr( (string) $i ); ?>]">
												<option value="0"><?php esc_html_e( '— All Categories —', 'sti-rss-feed-reader' ); ?></option>
												<?php foreach ( $categories as $cat ) : ?>
													<option value="<?php echo esc_attr( (string) $cat->term_id ); ?>" <?php selected( (int) ( $p['category'] ?? 0 ), $cat->term_id ); ?>><?php echo esc_html( $cat->name ); ?></option>
												<?php endforeach; ?>
											</select>
										</div>
										<div>
											<label><?php esc_html_e( 'Feed Position', 'sti-rss-feed-reader' ); ?></label>
											<select name="profile_position[<?php echo esc_attr( (string) $i ); ?>]">
												<?php foreach ( $valid_positions as $val => $label ) : ?>
													<option value="<?php echo esc_attr( $val ); ?>" <?php selected( $p['position'] ?? 'before_content', $val ); ?>><?php echo esc_html( $label ); ?></option>
												<?php endforeach; ?>
											</select>
										</div>
										<div>
											<label><?php esc_html_e( 'Post Status', 'sti-rss-feed-reader' ); ?></label>
											<select name="profile_status[<?php echo esc_attr( (string) $i ); ?>]">
												<option value="publish" <?php selected( $ps, 'publish' ); ?>><?php esc_html_e( 'Publish', 'sti-rss-feed-reader' ); ?></option>
												<option value="draft"   <?php selected( $ps, 'draft' ); ?>><?php esc_html_e( 'Draft', 'sti-rss-feed-reader' ); ?></option>
												<option value="pending" <?php selected( $ps, 'pending' ); ?>><?php esc_html_e( 'Pending', 'sti-rss-feed-reader' ); ?></option>
											</select>
										</div>
									</div>

									<div class="srf-acc-grid srf-acc-grid-spaced">
										<div class="srf-acc-toggles srf-acc-toggles-wide">
											<label class="srf-switch">
												<input type="checkbox" name="profile_powered[<?php echo esc_attr( (string) $i ); ?>]" <?php checked( (int) $p['powered'], 1 ); ?>>
												<span class="srf-switch-ui" aria-hidden="true"></span>
												<span class="srf-switch-label"><?php esc_html_e( 'Source', 'sti-rss-feed-reader' ); ?></span>
											</label>
											<label class="srf-switch">
												<input type="checkbox" name="profile_store[<?php echo esc_attr( (string) $i ); ?>]" <?php checked( (int) $p['store'], 1 ); ?>>
												<span class="srf-switch-ui" aria-hidden="true"></span>
												<span class="srf-switch-label"><?php esc_html_e( 'Store as Posts', 'sti-rss-feed-reader' ); ?></span>
											</label>
											<label class="srf-switch">
												<input type="checkbox" name="profile_allow_pages[<?php echo esc_attr( (string) $i ); ?>]" value="1" <?php checked( ! empty( $p['allow_pages'] ), 1 ); ?>>
												<span class="srf-switch-ui" aria-hidden="true"></span>
												<span class="srf-switch-label"><?php esc_html_e( 'Show on Pages', 'sti-rss-feed-reader' ); ?></span>
											</label>
										</div>
									</div>

									<div class="srf-acc-row">
										<label><?php esc_html_e( 'Fallback Image', 'sti-rss-feed-reader' ); ?></label>
										<div class="srf-acc-imgpick">
											<input type="text" name="profile_image[<?php echo esc_attr( (string) $i ); ?>]" value="<?php echo esc_url( $p['image'] ); ?>" placeholder="https://…">
											<button type="button" class="button srf-pick-image"><?php esc_html_e( 'Select', 'sti-rss-feed-reader' ); ?></button>
											<div class="srf-acc-thumb">
												<?php if ( ! empty( $p['image'] ) ) : ?><img src="<?php echo esc_url( $p['image'] ); ?>" alt=""><?php endif; ?>
											</div>
										</div>
									</div>
								</div>
							</details>
							<?php
							$i++;
							endforeach;
							?>

							<!-- New profile template -->
							<details class="srf-acc srf-acc-template" open>
								<summary class="srf-acc-head">
									<span class="srf-acc-id"><?php esc_html_e( 'New', 'sti-rss-feed-reader' ); ?></span>
									<label class="srf-acc-toggle">
										<input type="checkbox" name="profile_active[<?php echo esc_attr( (string) $i ); ?>]" checked>
										<span><?php esc_html_e( 'Active', 'sti-rss-feed-reader' ); ?></span>
									</label>
									<input class="srf-acc-title" type="text" name="profile_title[<?php echo esc_attr( (string) $i ); ?>]" value="" placeholder="<?php esc_attr_e( 'Profile title…', 'sti-rss-feed-reader' ); ?>">
									<span class="srf-acc-shortcode"><em><?php esc_html_e( '(after save)', 'sti-rss-feed-reader' ); ?></em></span>
									<span class="srf-acc-actions">
										<button type="button" class="button button-small srf-add-row"><?php esc_html_e( 'Add', 'sti-rss-feed-reader' ); ?></button>
									</span>
								</summary>

								<div class="srf-acc-body">
									<div class="srf-acc-row">
										<label><?php esc_html_e( 'Feed URLs (one per line)', 'sti-rss-feed-reader' ); ?></label>
										<textarea name="profile_urls[<?php echo esc_attr( (string) $i ); ?>]" rows="4" placeholder="https://example.com/rss"></textarea>
									</div>

									<div class="srf-acc-grid">
										<div>
											<label><?php esc_html_e( 'Layout', 'sti-rss-feed-reader' ); ?></label>
											<select name="profile_layout[<?php echo esc_attr( (string) $i ); ?>]">
												<option value="list"><?php esc_html_e( 'List', 'sti-rss-feed-reader' ); ?></option>
												<option value="grid"><?php esc_html_e( 'Grid', 'sti-rss-feed-reader' ); ?></option>
											</select>
										</div>
										<div>
											<label><?php esc_html_e( 'Items', 'sti-rss-feed-reader' ); ?></label>
											<input type="number" min="1" max="50" name="profile_items[<?php echo esc_attr( (string) $i ); ?>]" value="5">
										</div>
										<div>
											<label><?php esc_html_e( 'Grid Cols', 'sti-rss-feed-reader' ); ?></label>
											<input type="number" min="1" max="6" name="profile_cols[<?php echo esc_attr( (string) $i ); ?>]" value="2">
										</div>
										<div>
											<label><?php esc_html_e( 'Show only in Category', 'sti-rss-feed-reader' ); ?></label>
											<select name="profile_category[<?php echo esc_attr( (string) $i ); ?>]">
												<option value="0"><?php esc_html_e( '— All Categories —', 'sti-rss-feed-reader' ); ?></option>
												<?php foreach ( $categories as $cat ) : ?>
													<option value="<?php echo esc_attr( (string) $cat->term_id ); ?>"><?php echo esc_html( $cat->name ); ?></option>
												<?php endforeach; ?>
											</select>
										</div>
										<div>
											<label><?php esc_html_e( 'Post Status', 'sti-rss-feed-reader' ); ?></label>
											<select name="profile_status[<?php echo esc_attr( (string) $i ); ?>]">
												<option value="publish" <?php selected( get_option( 'stirfr_store_status', 'draft' ), 'publish' ); ?>><?php esc_html_e( 'Publish', 'sti-rss-feed-reader' ); ?></option>
												<option value="draft"   <?php selected( get_option( 'stirfr_store_status', 'draft' ), 'draft' ); ?>><?php esc_html_e( 'Draft', 'sti-rss-feed-reader' ); ?></option>
												<option value="pending" <?php selected( get_option( 'stirfr_store_status', 'draft' ), 'pending' ); ?>><?php esc_html_e( 'Pending', 'sti-rss-feed-reader' ); ?></option>
											</select>
										</div>
									</div>

									<div class="srf-acc-grid srf-acc-grid-spaced">
										<div class="srf-acc-toggles srf-acc-toggles-wide">
											<label class="srf-switch">
												<input type="checkbox" name="profile_powered[<?php echo esc_attr( (string) $i ); ?>]">
												<span class="srf-switch-ui" aria-hidden="true"></span>
												<span class="srf-switch-label"><?php esc_html_e( 'Source', 'sti-rss-feed-reader' ); ?></span>
											</label>
											<label class="srf-switch">
												<input type="checkbox" name="profile_store[<?php echo esc_attr( (string) $i ); ?>]">
												<span class="srf-switch-ui" aria-hidden="true"></span>
												<span class="srf-switch-label"><?php esc_html_e( 'Store as Posts', 'sti-rss-feed-reader' ); ?></span>
											</label>
										</div>
									</div>

									<div class="srf-acc-row">
										<label><?php esc_html_e( 'Fallback Image', 'sti-rss-feed-reader' ); ?></label>
										<div class="srf-acc-imgpick">
											<input type="text" name="profile_image[<?php echo esc_attr( (string) $i ); ?>]" value="" placeholder="https://…">
											<button type="button" class="button srf-pick-image"><?php esc_html_e( 'Select', 'sti-rss-feed-reader' ); ?></button>
										</div>
									</div>
								</div>
							</details>
						</div><!-- /.srf-acc-list -->
					</div><!-- /.srf-card.srf-profiles -->

					<!-- Storage & Cleanup -->
					<div class="srf-card srf-card-spaced">
						<h2><?php esc_html_e( 'Storage, Import & Cleanup', 'sti-rss-feed-reader' ); ?></h2>

						<div class="srf-row">
							<div class="srf-label"><?php esc_html_e( 'Keep for (days)', 'sti-rss-feed-reader' ); ?></div>
							<div class="srf-controls">
								<input type="number" name="stirfr_store_days" value="<?php echo esc_attr( (string) $store_days ); ?>" min="1" max="365" class="srf-input-small">
							</div>
						</div>
						<div class="srf-row">
							<div class="srf-label"><?php esc_html_e( 'Expiry Mode', 'sti-rss-feed-reader' ); ?></div>
							<div class="srf-controls">
								<select name="stirfr_expire_mode" class="srf-select-medium">
									<option value="rolling"  <?php selected( $expire_mode, 'rolling' ); ?>><?php esc_html_e( 'Rolling (24 hours × N)', 'sti-rss-feed-reader' ); ?></option>
									<option value="midnight" <?php selected( $expire_mode, 'midnight' ); ?>><?php esc_html_e( 'Until midnight (N days at 23:59:59)', 'sti-rss-feed-reader' ); ?></option>
								</select>
							</div>
						</div>
						<div class="srf-row">
							<div class="srf-label"><?php esc_html_e( 'Import Lock (seconds)', 'sti-rss-feed-reader' ); ?></div>
							<div class="srf-controls">
								<input type="number" name="stirfr_lock_seconds" value="<?php echo esc_attr( (string) $lock_seconds ); ?>" min="10" max="3600" class="srf-input-small">
							</div>
						</div>
						<div class="srf-row">
							<div class="srf-label"><?php esc_html_e( 'Cleanup Action', 'sti-rss-feed-reader' ); ?></div>
							<div class="srf-controls">
								<select name="stirfr_cleanup_action" class="srf-select-small">
									<option value="trash"  <?php selected( $cleanup_action, 'trash' ); ?>><?php esc_html_e( 'Move to Trash', 'sti-rss-feed-reader' ); ?></option>
									<option value="delete" <?php selected( $cleanup_action, 'delete' ); ?>><?php esc_html_e( 'Delete Permanently', 'sti-rss-feed-reader' ); ?></option>
								</select>
							</div>
						</div>
						<div class="srf-row">
							<div class="srf-label"><?php esc_html_e( 'Run Cleanup Now', 'sti-rss-feed-reader' ); ?></div>
							<div class="srf-controls">
								<button type="submit" name="srf_run_cleanup_now" class="button"><?php esc_html_e( 'Run Cleanup', 'sti-rss-feed-reader' ); ?></button>
							</div>
						</div>
					</div>

					<!-- Display / Colors -->
					<div class="srf-card srf-card-spaced">
						<h2><?php esc_html_e( 'Display Settings', 'sti-rss-feed-reader' ); ?></h2>

						<div class="srf-row">
							<div class="srf-label"><?php esc_html_e( 'Card background', 'sti-rss-feed-reader' ); ?></div>
							<div class="srf-controls">
								<input type="color" id="stirfr_card_color" name="stirfr_card_color" value="<?php echo esc_attr( $card_color ); ?>">
							</div>
						</div>
						<div class="srf-row">
							<div class="srf-label"><?php esc_html_e( 'Text color', 'sti-rss-feed-reader' ); ?></div>
							<div class="srf-controls">
								<input type="color" id="stirfr_text_color" name="stirfr_text_color" value="<?php echo esc_attr( $text_color ); ?>">
							</div>
						</div>
						<div class="srf-row">
							<div class="srf-label"><?php esc_html_e( 'Read more', 'sti-rss-feed-reader' ); ?></div>
							<div class="srf-controls srf-readmore-controls">
								<input type="color" id="stirfr_readmore_color" name="stirfr_readmore_color" value="<?php echo esc_attr( $readmore_color ); ?>">
								<label class="srf-switch srf-readmore-switch">
									<input type="checkbox" name="stirfr_readmore_button_enabled" value="1" <?php checked( get_option( 'stirfr_readmore_button_enabled', 0 ), 1 ); ?>>
									<span class="srf-switch-ui"></span>
								</label>
								<select name="stirfr_readmore_button_style">
									<option value="style1" <?php selected( get_option( 'stirfr_readmore_button_style', 'style1' ), 'style1' ); ?>><?php esc_html_e( 'Solid', 'sti-rss-feed-reader' ); ?></option>
									<option value="style2" <?php selected( get_option( 'stirfr_readmore_button_style', 'style1' ), 'style2' ); ?>><?php esc_html_e( 'Outline', 'sti-rss-feed-reader' ); ?></option>
									<option value="style3" <?php selected( get_option( 'stirfr_readmore_button_style', 'style1' ), 'style3' ); ?>><?php esc_html_e( 'Gradient', 'sti-rss-feed-reader' ); ?></option>
									<option value="style4" <?php selected( get_option( 'stirfr_readmore_button_style', 'style1' ), 'style4' ); ?>><?php esc_html_e( 'Pill', 'sti-rss-feed-reader' ); ?></option>
									<option value="style5" <?php selected( get_option( 'stirfr_readmore_button_style', 'style1' ), 'style5' ); ?>><?php esc_html_e( 'Underline', 'sti-rss-feed-reader' ); ?></option>
									<option value="style6" <?php selected( get_option( 'stirfr_readmore_button_style', 'style1' ), 'style6' ); ?>><?php esc_html_e( 'Elevated', 'sti-rss-feed-reader' ); ?></option>
									<option value="style7" <?php selected( get_option( 'stirfr_readmore_button_style', 'style1' ), 'style7' ); ?>><?php esc_html_e( 'Glass', 'sti-rss-feed-reader' ); ?></option>
									<option value="style8" <?php selected( get_option( 'stirfr_readmore_button_style', 'style1' ), 'style8' ); ?>><?php esc_html_e( 'Dark', 'sti-rss-feed-reader' ); ?></option>
								</select>
								<input type="text" name="stirfr_readmore_button_text" value="<?php echo esc_attr( get_option( 'stirfr_readmore_button_text', 'Read more' ) ); ?>" placeholder="Read more" class="regular-text">
							</div>
						</div>

						<h3><?php esc_html_e( 'Preview', 'sti-rss-feed-reader' ); ?></h3>
						<div id="srf-color-preview" class="srf-color-preview" style="--stirfr-card-bg:<?php echo esc_attr( $card_color ); ?>;--stirfr-text-color:<?php echo esc_attr( $text_color ); ?>;--stirfr-readmore-color:<?php echo esc_attr( $readmore_color ); ?>">
							<h3 class="stirfr-title"><?php esc_html_e( 'Sample feed title', 'sti-rss-feed-reader' ); ?></h3>
							<img src="<?php echo esc_url( $default_image ?: STIRFR_DEFAULT_FALLBACK_IMAGE ); ?>" alt="" style="max-width:120px;border-radius:6px;margin-bottom:8px;">
							<p class="stirfr-excerpt"><?php esc_html_e( 'This is how your feed card will look with the selected colors.', 'sti-rss-feed-reader' ); ?></p>
							<a id="srf-readmore-preview" class="stirfr-read-more stirfr-btn stirfr-btn-style1" href="#" style="--stirfr-readmore-color:<?php echo esc_attr( $readmore_color ); ?>">
								<?php echo esc_html( get_option( 'stirfr_readmore_button_text', 'Read more' ) ); ?>
							</a>
						</div>
					</div>

					<div class="srf-actions">
						<input type="submit" name="srf_save_all" class="button-primary" value="<?php esc_attr_e( 'Save Settings & Profiles', 'sti-rss-feed-reader' ); ?>">
					</div>
				</div><!-- /#stirfr-settings -->

				<!-- Ticker tab -->
				<div id="stirfr-ticker" class="sti-tab-content sti-tab-padded">
					<h2><?php esc_html_e( 'Breaking News Ticker', 'sti-rss-feed-reader' ); ?></h2>

					<div class="srf-card">
						<div class="srf-row">
							<div class="srf-label"><?php esc_html_e( 'Enable Ticker', 'sti-rss-feed-reader' ); ?></div>
							<div class="srf-controls">
								<label class="srf-switch">
									<input type="checkbox" name="stirfr_ticker_enabled" value="1" <?php checked( get_option( 'stirfr_ticker_enabled', 1 ), 1 ); ?>>
									<span class="srf-switch-ui"></span>
								</label>
							</div>
						</div>
						<div class="srf-row">
							<div class="srf-label"><?php esc_html_e( 'Speed (seconds)', 'sti-rss-feed-reader' ); ?></div>
							<div class="srf-controls">
								<input type="number" name="stirfr_ticker_speed" value="<?php echo esc_attr( (string) (int) get_option( 'stirfr_ticker_speed', 30 ) ); ?>" min="5" max="120">
							</div>
						</div>
						<div class="srf-row">
							<div class="srf-label"><?php esc_html_e( 'Background', 'sti-rss-feed-reader' ); ?></div>
							<div class="srf-controls">
								<input type="color" name="stirfr_ticker_bg" value="<?php echo esc_attr( (string) get_option( 'stirfr_ticker_bg', '#111111' ) ); ?>">
							</div>
						</div>
						<div class="srf-row">
							<div class="srf-label"><?php esc_html_e( 'Text Color', 'sti-rss-feed-reader' ); ?></div>
							<div class="srf-controls">
								<input type="color" name="stirfr_ticker_text" value="<?php echo esc_attr( (string) get_option( 'stirfr_ticker_text', '#ffffff' ) ); ?>">
							</div>
						</div>
						<div class="srf-row">
							<div class="srf-label"><?php esc_html_e( 'Ticker Source', 'sti-rss-feed-reader' ); ?></div>
							<div class="srf-controls">
								<select name="stirfr_ticker_source" id="stirfr_ticker_source">
									<option value="profile" <?php selected( get_option( 'stirfr_ticker_source' ), 'profile' ); ?>><?php esc_html_e( 'Profile', 'sti-rss-feed-reader' ); ?></option>
									<option value="custom"  <?php selected( get_option( 'stirfr_ticker_source' ), 'custom' ); ?>><?php esc_html_e( 'Custom URL', 'sti-rss-feed-reader' ); ?></option>
									<option value="stored"  <?php selected( get_option( 'stirfr_ticker_source' ), 'stored' ); ?>><?php esc_html_e( 'Stored Posts', 'sti-rss-feed-reader' ); ?></option>
								</select>
							</div>
						</div>
						<div class="srf-row stirfr-source-profile" style="display:none">
							<div class="srf-label"><?php esc_html_e( 'Select Profile', 'sti-rss-feed-reader' ); ?></div>
							<div class="srf-controls">
								<select name="stirfr_ticker_profile">
									<?php foreach ( $profiles as $id => $p ) : ?>
										<option value="<?php echo esc_attr( (string) $id ); ?>" <?php selected( get_option( 'stirfr_ticker_profile' ), $id ); ?>>
											<?php echo esc_html( $p['title'] ?? ( 'Feed ' . $id ) ); ?>
										</option>
									<?php endforeach; ?>
								</select>
							</div>
						</div>
						<div class="srf-row stirfr-source-custom" style="display:none">
							<div class="srf-label"><?php esc_html_e( 'Custom RSS URL', 'sti-rss-feed-reader' ); ?></div>
							<div class="srf-controls">
								<input type="text" name="stirfr_ticker_custom_url" value="<?php echo esc_attr( (string) get_option( 'stirfr_ticker_custom_url' ) ); ?>" placeholder="https://example.com/feed">
							</div>
						</div>
						<div class="srf-row stirfr-source-stored" style="display:none">
							<div class="srf-label"><?php esc_html_e( 'Number of Latest Posts', 'sti-rss-feed-reader' ); ?></div>
							<div class="srf-controls">
								<input type="number" name="stirfr_ticker_post_count" value="<?php echo esc_attr( (string) (int) get_option( 'stirfr_ticker_post_count', 5 ) ); ?>" min="1" max="50">
							</div>
						</div>
					</div>

					<div class="srf-card srf-card-spaced">
						<h3><?php esc_html_e( 'Shortcode', 'sti-rss-feed-reader' ); ?></h3>
						<code>[stirfr_breaking_news]</code>
					</div>

					<div class="srf-card srf-card-spaced">
						<h3><?php esc_html_e( 'Live Preview', 'sti-rss-feed-reader' ); ?></h3>
						<div id="stirfr-live-preview">
							<?php echo do_shortcode( '[stirfr_breaking_news]' ); ?>
						</div>
					</div>

					<div>
						<button type="submit" name="srf_save_all" class="button-primary"><?php esc_html_e( 'Save Ticker Settings', 'sti-rss-feed-reader' ); ?></button>
					</div>
				</div><!-- /#stirfr-ticker -->
			</form>

			<!-- RSS tab (new) -->
			<div id="stirfr-rss" class="sti-tab-content sti-tab-padded">
				<h2><?php esc_html_e( 'Your RSS Feed URLs', 'sti-rss-feed-reader' ); ?></h2>
				<p class="srf-help"><?php esc_html_e( 'Share these URLs so anyone can subscribe to your site content via RSS readers.', 'sti-rss-feed-reader' ); ?></p>

				<!-- Shortcode reference -->
				<div class="srf-card srf-card-spaced" style="background: linear-gradient(135deg, #fff7ed 0%, #fffbeb 100%); border-color: #fed7aa;">
					<h3>📋 <?php esc_html_e( 'Display RSS Links on Your Site', 'sti-rss-feed-reader' ); ?></h3>
					<p class="srf-help"><?php esc_html_e( 'Use these shortcodes to display RSS feed links on any page, post, or widget.', 'sti-rss-feed-reader' ); ?></p>
					<table class="srf-rss-table widefat" style="margin-top: 10px;">
						<thead>
							<tr>
								<th><?php esc_html_e( 'Shortcode', 'sti-rss-feed-reader' ); ?></th>
								<th><?php esc_html_e( 'Description', 'sti-rss-feed-reader' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<tr><td><code style="user-select: all;">[stirfr_rss_links]</code></td><td><?php esc_html_e( 'Category feeds as buttons (default)', 'sti-rss-feed-reader' ); ?></td></tr>
							<tr><td><code style="user-select: all;">[stirfr_rss_links type="site"]</code></td><td><?php esc_html_e( 'Site-wide & comments feed links', 'sti-rss-feed-reader' ); ?></td></tr>
							<tr><td><code style="user-select: all;">[stirfr_rss_links type="tags" style="list"]</code></td><td><?php esc_html_e( 'Tag feeds as a vertical list', 'sti-rss-feed-reader' ); ?></td></tr>
							<tr><td><code style="user-select: all;">[stirfr_rss_links type="all" style="inline"]</code></td><td><?php esc_html_e( 'Everything — compact inline chips', 'sti-rss-feed-reader' ); ?></td></tr>
							<tr><td><code style="user-select: all;">[stirfr_rss_links type="categories" show_count="yes"]</code></td><td><?php esc_html_e( 'Categories with post count badges', 'sti-rss-feed-reader' ); ?></td></tr>
							<tr><td><code style="user-select: all;">[stirfr_rss_links title="Subscribe" show_icon="no"]</code></td><td><?php esc_html_e( 'Custom heading, no RSS icon', 'sti-rss-feed-reader' ); ?></td></tr>
						</tbody>
					</table>
					<p class="srf-rss-desc" style="margin-top: 8px;">
						<?php
						printf(
							/* translators: %s: list of attribute names */
							esc_html__( 'Available attributes: %s', 'sti-rss-feed-reader' ),
							'<code>type</code>, <code>style</code>, <code>show_count</code>, <code>bg</code>, <code>color</code>, <code>show_icon</code>, <code>title</code>, <code>target</code>'
						);
						?>
					</p>
				</div>

				<!-- Site-wide feed -->
				<div class="srf-card srf-card-spaced">
					<h3>🌐 <?php esc_html_e( 'Site-Wide Feed', 'sti-rss-feed-reader' ); ?></h3>
					<div class="srf-rss-url-row">
						<code class="srf-rss-url"><?php echo esc_url( get_bloginfo( 'rss2_url' ) ); ?></code>
						<button type="button" class="button button-small srf-copy-rss" data-url="<?php echo esc_attr( get_bloginfo( 'rss2_url' ) ); ?>">
							<?php esc_html_e( 'Copy', 'sti-rss-feed-reader' ); ?>
						</button>
					</div>
					<p class="srf-rss-desc"><?php esc_html_e( 'This feed includes all posts from your site.', 'sti-rss-feed-reader' ); ?></p>
				</div>

				<!-- Comments feed -->
				<div class="srf-card srf-card-spaced">
					<h3>💬 <?php esc_html_e( 'Comments Feed', 'sti-rss-feed-reader' ); ?></h3>
					<div class="srf-rss-url-row">
						<code class="srf-rss-url"><?php echo esc_url( get_bloginfo( 'comments_rss2_url' ) ); ?></code>
						<button type="button" class="button button-small srf-copy-rss" data-url="<?php echo esc_attr( get_bloginfo( 'comments_rss2_url' ) ); ?>">
							<?php esc_html_e( 'Copy', 'sti-rss-feed-reader' ); ?>
						</button>
					</div>
				</div>

				<!-- Category feeds -->
				<div class="srf-card srf-card-spaced">
					<h3>📁 <?php esc_html_e( 'Category Feeds', 'sti-rss-feed-reader' ); ?></h3>
					<p class="srf-help"><?php esc_html_e( 'Each category on your site has its own RSS feed. Select or share any of these.', 'sti-rss-feed-reader' ); ?></p>

					<?php
					$rss_cats = get_categories( [ 'hide_empty' => false ] );
					if ( empty( $rss_cats ) ) :
					?>
						<p class="srf-rss-empty"><?php esc_html_e( 'No categories found. Create some categories first.', 'sti-rss-feed-reader' ); ?></p>
					<?php else : ?>
						<div class="srf-rss-table-wrap">
							<table class="srf-rss-table widefat striped">
								<thead>
									<tr>
										<th><?php esc_html_e( 'Category', 'sti-rss-feed-reader' ); ?></th>
										<th><?php esc_html_e( 'Posts', 'sti-rss-feed-reader' ); ?></th>
										<th><?php esc_html_e( 'RSS Feed URL', 'sti-rss-feed-reader' ); ?></th>
										<th><?php esc_html_e( 'Actions', 'sti-rss-feed-reader' ); ?></th>
									</tr>
								</thead>
								<tbody>
									<?php foreach ( $rss_cats as $rss_cat ) :
										$cat_feed_url = get_category_feed_link( $rss_cat->term_id );
									?>
										<tr>
											<td>
												<strong><?php echo esc_html( $rss_cat->name ); ?></strong>
												<span class="srf-rss-slug"><?php echo esc_html( $rss_cat->slug ); ?></span>
											</td>
											<td class="srf-rss-count"><?php echo (int) $rss_cat->count; ?></td>
											<td>
												<code class="srf-rss-url"><?php echo esc_url( $cat_feed_url ); ?></code>
											</td>
											<td class="srf-rss-actions">
												<button type="button" class="button button-small srf-copy-rss" data-url="<?php echo esc_attr( $cat_feed_url ); ?>">
													<?php esc_html_e( 'Copy', 'sti-rss-feed-reader' ); ?>
												</button>
												<a href="<?php echo esc_url( $cat_feed_url ); ?>" target="_blank" class="button button-small">
													<?php esc_html_e( 'Open', 'sti-rss-feed-reader' ); ?>
												</a>
											</td>
										</tr>
									<?php endforeach; ?>
								</tbody>
							</table>
						</div>
					<?php endif; ?>
				</div>

				<!-- Tag feeds -->
				<?php
				$rss_tags = get_tags( [ 'hide_empty' => false ] );
				if ( ! empty( $rss_tags ) ) :
				?>
				<div class="srf-card srf-card-spaced">
					<h3>🏷️ <?php esc_html_e( 'Tag Feeds', 'sti-rss-feed-reader' ); ?></h3>
					<div class="srf-rss-table-wrap">
						<table class="srf-rss-table widefat striped">
							<thead>
								<tr>
									<th><?php esc_html_e( 'Tag', 'sti-rss-feed-reader' ); ?></th>
									<th><?php esc_html_e( 'Posts', 'sti-rss-feed-reader' ); ?></th>
									<th><?php esc_html_e( 'RSS Feed URL', 'sti-rss-feed-reader' ); ?></th>
									<th><?php esc_html_e( 'Actions', 'sti-rss-feed-reader' ); ?></th>
								</tr>
							</thead>
							<tbody>
								<?php foreach ( $rss_tags as $rss_tag ) :
									$tag_feed_url = get_tag_feed_link( $rss_tag->term_id );
								?>
									<tr>
										<td>
											<strong><?php echo esc_html( $rss_tag->name ); ?></strong>
											<span class="srf-rss-slug"><?php echo esc_html( $rss_tag->slug ); ?></span>
										</td>
										<td class="srf-rss-count"><?php echo (int) $rss_tag->count; ?></td>
										<td>
											<code class="srf-rss-url"><?php echo esc_url( $tag_feed_url ); ?></code>
										</td>
										<td class="srf-rss-actions">
											<button type="button" class="button button-small srf-copy-rss" data-url="<?php echo esc_attr( $tag_feed_url ); ?>">
												<?php esc_html_e( 'Copy', 'sti-rss-feed-reader' ); ?>
											</button>
											<a href="<?php echo esc_url( $tag_feed_url ); ?>" target="_blank" class="button button-small">
												<?php esc_html_e( 'Open', 'sti-rss-feed-reader' ); ?>
											</a>
										</td>
									</tr>
								<?php endforeach; ?>
							</tbody>
						</table>
					</div>
				</div>
				<?php endif; ?>
			</div><!-- /#stirfr-rss -->

			<!-- Support tab -->
			<div id="stirfr-support" class="sti-tab-content sti-tab-padded">
				<section class="sti-support" aria-labelledby="sti-support-heading">
					<h3 id="sti-support-heading"><?php esc_html_e( 'Support & Documentation', 'sti-rss-feed-reader' ); ?></h3>
					<p class="sti-lead"><?php esc_html_e( 'Need help with STI RSS Feed Reader? Below are fast, reliable ways to get assistance and self-help resources.', 'sti-rss-feed-reader' ); ?></p>

					<div class="sti-support-grid">
						<div class="sti-support-card">
							<h4 class="sti-support-title"><?php esc_html_e( 'Support Development ❤️', 'sti-rss-feed-reader' ); ?></h4>
							<p><?php esc_html_e( 'This plugin is 100% free — but your support motivates us to keep improving it.', 'sti-rss-feed-reader' ); ?></p>
							<div id="paypal-container-QHZ62YYUEXLE8"></div>
						</div>

						<div class="sti-support-card">
							<h4 class="sti-support-title"><?php esc_html_e( 'Quick Links', 'sti-rss-feed-reader' ); ?></h4>
							<ul class="sti-support-links">
								<li><strong><?php esc_html_e( 'Documentation:', 'sti-rss-feed-reader' ); ?></strong> <a href="https://santechidea.com/docs/sti-rss-feed-reader" target="_blank" rel="noopener"><?php esc_html_e( 'Plugin docs & user guide', 'sti-rss-feed-reader' ); ?></a></li>
								<li><strong><?php esc_html_e( 'FAQ:', 'sti-rss-feed-reader' ); ?></strong> <a href="https://santechidea.com/docs/sti-rss-feed-reader#faq" target="_blank" rel="noopener"><?php esc_html_e( 'Common issues & troubleshooting', 'sti-rss-feed-reader' ); ?></a></li>
							</ul>
						</div>

						<div class="sti-support-card">
							<h4 class="sti-support-title"><?php esc_html_e( 'Contact Support', 'sti-rss-feed-reader' ); ?></h4>
							<p class="sti-support-actions">
								<a class="button" href="<?php echo esc_url( 'mailto:support@santechidea.com?subject=' . rawurlencode( 'STI RSS Feed Reader Support' ) ); ?>"><?php esc_html_e( 'Email Support', 'sti-rss-feed-reader' ); ?></a>
								<button id="stiCopyDebug" class="button sti-btn-spaced" type="button"><?php esc_html_e( 'Copy Debug Info', 'sti-rss-feed-reader' ); ?></button>
							</p>
						</div>

						<div class="sti-support-card">
							<h4 class="sti-support-title"><?php esc_html_e( 'Developer / Debug Info', 'sti-rss-feed-reader' ); ?></h4>
							<pre id="stiDebugPre" class="sti-debug-pre">
								Site: <?php echo esc_html( get_bloginfo( 'name' ) ); ?> (<?php echo esc_url( home_url() ); ?>)
								WP: <?php echo esc_html( get_bloginfo( 'version' ) ); ?>
								Plugin: STI RSS Feed Reader <?php echo esc_html( STIRFR_PLUGIN_VER ); ?>
								PHP: <?php echo esc_html( phpversion() ); ?>
								User: <?php echo esc_html( wp_get_current_user()->user_login ?: '—' ); ?>
							</pre>
						</div>
					</div>
				</section>
			</div><!-- /#stirfr-support -->
		</div><!-- /.sti-admin-tabs -->
	</div><!-- /.srf-wrap -->
	<?php
}