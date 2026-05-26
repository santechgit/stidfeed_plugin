<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * =====================================================
 * FRONTEND ASSETS
 * =====================================================
 */
add_action( 'wp_enqueue_scripts', 'stirfr_enqueue_frontend_assets', 20 );
function stirfr_enqueue_frontend_assets() {

    $base = plugin_dir_url( STIRFR_PLUGIN_FILE );
    wp_enqueue_style( 'stirfr-frontend', $base . 'assets/css/frontend.css', [], STIRFR_PLUGIN_VER );
}

/**
 * Inject dynamic CSS variables
 */
add_action( 'wp_enqueue_scripts', 'stirfr_frontend_css_variables', 30 );
function stirfr_frontend_css_variables() {

    $raw_card = get_option( 'stirfr_card_color', '#ffffff' );
    $raw_text = get_option( 'stirfr_text_color', '#222222' );
    $raw_link = get_option( 'stirfr_readmore_color', '#0073aa' );

    $card = sanitize_hex_color( is_string( $raw_card ) && $raw_card !== '' ? $raw_card : '#ffffff' ) ?: '#ffffff';
    $text = sanitize_hex_color( is_string( $raw_text ) && $raw_text !== '' ? $raw_text : '#222222' ) ?: '#222222';
    $link = sanitize_hex_color( is_string( $raw_link ) && $raw_link !== '' ? $raw_link : '#0073aa' ) ?: '#0073aa';

    wp_add_inline_style(
        'stirfr-frontend',
        ".stirfr-feed {
            --stirfr-card-bg: {$card};
            --stirfr-text-color: {$text};
            --stirfr-link-color: {$link};
        }"
    );
}

/**
 * =====================================================
 * ADMIN ASSETS
 * =====================================================
 */
add_action( 'admin_enqueue_scripts', 'stirfr_enqueue_admin_assets' );
function stirfr_enqueue_admin_assets( $hook ) {

    if ( $hook !== 'toplevel_page_simple-rss-feed' ) {
        return;
    }

    $base = plugin_dir_url( STIRFR_PLUGIN_FILE );
    $path = plugin_dir_path( STIRFR_PLUGIN_FILE );

    $admin_css_mtime = file_exists( $path . 'assets/css/admin.css' )
        ? filemtime( $path . 'assets/css/admin.css' )
        : STIRFR_PLUGIN_VER;

    wp_enqueue_style(
        'stirfr-admin',
        $base . 'assets/css/admin.css',
        [],
        $admin_css_mtime
    );

    $admin_js_mtime = file_exists( $path . 'assets/js/admin.js' )
        ? filemtime( $path . 'assets/js/admin.js' )
        : STIRFR_PLUGIN_VER;

    wp_enqueue_script(
        'stirfr-admin',
        $base . 'assets/js/admin.js',
        [ 'jquery' ],
        $admin_js_mtime,
        true
    );

    wp_localize_script(
        'stirfr-admin',
        'STIRFR_ADMIN',
        [
            'storeStatus' => get_option( 'stirfr_store_status', 'draft' ),
        ]
    );

    $categories = get_categories( [ 'hide_empty' => false ] );
    $cat_map    = [];
    foreach ( $categories as $cat ) {
        $cat_map[ $cat->term_id ] = [
            'name' => $cat->name,
            'slug' => $cat->slug,
        ];
    }

    wp_localize_script(
        'stirfr-admin',
        'STIRFR_CATS',
        [
            'categories'  => $cat_map,
            'suggestions' => stirfr_get_rss_suggestions_map(),
        ]
    );

    // ✅ ADD THIS: pass nonce for live ticker preview AJAX
    wp_localize_script(
        'stirfr-admin',
        'stirfr_ajax',
        [
            'ticker_nonce' => wp_create_nonce( 'stirfr_ticker_preview' ),
        ]
    );

    wp_enqueue_media();
}

/**
 * =====================================================
 * WELCOME PAGE ASSETS
 * =====================================================
 */
add_action( 'admin_enqueue_scripts', 'stirfr_enqueue_welcome_assets' );
function stirfr_enqueue_welcome_assets() {

    // phpcs:ignore WordPress.Security.NonceVerification.Recommended
    $page = isset( $_GET['page'] ) ? sanitize_key( $_GET['page'] ) : '';

    if ( $page !== 'stirfr-welcome' ) {
        return;
    }

    $base = plugin_dir_url( STIRFR_PLUGIN_FILE );
    $path = plugin_dir_path( STIRFR_PLUGIN_FILE );

    $welcome_css_mtime = file_exists( $path . 'assets/css/welcome.css' )
        ? filemtime( $path . 'assets/css/welcome.css' )
        : STIRFR_PLUGIN_VER;

    wp_enqueue_style(
        'stirfr-welcome',
        $base . 'assets/css/welcome.css',
        [],
        $welcome_css_mtime
    );

    $welcome_js_mtime = file_exists( $path . 'assets/js/welcome.js' )
        ? filemtime( $path . 'assets/js/welcome.js' )
        : STIRFR_PLUGIN_VER;

    wp_enqueue_script(
        'stirfr-welcome',
        $base . 'assets/js/welcome.js',
        [],
        $welcome_js_mtime,
        true
    );
}

add_action( 'admin_enqueue_scripts', function ( $hook ) {

    if ( $hook !== 'toplevel_page_simple-rss-feed' ) {
        return;
    }

    wp_enqueue_style(
        'stirfr-frontend-preview',
        STIRFR_PLUGIN_URL . 'assets/css/frontend.css',
        [],
        STIRFR_PLUGIN_VER
    );
} );

add_action( 'admin_enqueue_scripts', 'stirfr_paypal_button_admin_scripts' );
function stirfr_paypal_button_admin_scripts( $hook ) {

    if ( $hook !== 'toplevel_page_simple-rss-feed' ) {
        return;
    }

    wp_enqueue_script( // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion
        'stirfr-paypal-sdk',
        'https://www.paypal.com/sdk/js?client-id=BAA-UbOyXWFH9rwoUzDAGut23ezOw7S2wo5Ss5pEj-gtWRK_BLM-g9z-J5ghxBaPi_irdkn-wDbUS09Mfw&components=hosted-buttons&disable-funding=venmo&currency=USD',
        [],
        null,
        true
    );

    wp_add_inline_script( 'stirfr-paypal-sdk', "
        function initPayPalButton() {
            if (typeof paypal === 'undefined') {
                setTimeout(initPayPalButton, 200);
                return;
            }
            var el = document.querySelector('#paypal-container-QHZ62YYUEXLE8');
            if (el) {
                paypal.HostedButtons({
                    hostedButtonId: 'QHZ62YYUEXLE8'
                }).render('#paypal-container-QHZ62YYUEXLE8');
            }
        }
        document.addEventListener('DOMContentLoaded', initPayPalButton);
    " );
}

/**
 * =====================================================
 * AJAX HANDLER FOR LIVE TICKER PREVIEW
 * =====================================================
 */
add_action('wp_ajax_stirfr_live_preview', 'stirfr_ajax_live_preview');
function stirfr_ajax_live_preview() {
    // Verify nonce first (required)
    check_ajax_referer('stirfr_ticker_preview', 'nonce');

    // Pre‑sanitize all POST values with proper isset checks and unslashing
    $source      = isset( $_POST['stirfr_ticker_source'] )      ? sanitize_key( wp_unslash( $_POST['stirfr_ticker_source'] ) )      : '';
    $profile     = isset( $_POST['stirfr_ticker_profile'] )     ? intval( wp_unslash( $_POST['stirfr_ticker_profile'] ) )           : 0;
    $custom_url  = isset( $_POST['stirfr_ticker_custom_url'] )  ? esc_url_raw( wp_unslash( $_POST['stirfr_ticker_custom_url'] ) )   : '';
    $bg          = isset( $_POST['stirfr_ticker_bg'] )          ? sanitize_hex_color( wp_unslash( $_POST['stirfr_ticker_bg'] ) )    : '';
    $text        = isset( $_POST['stirfr_ticker_text'] )        ? sanitize_hex_color( wp_unslash( $_POST['stirfr_ticker_text'] ) )  : '';
    $speed       = isset( $_POST['stirfr_ticker_speed'] )       ? intval( wp_unslash( $_POST['stirfr_ticker_speed'] ) )             : 30;

    // Apply temporary filters using the sanitized values (no direct $_POST access)
    if ( $source !== '' ) {
        add_filter( 'pre_option_stirfr_ticker_source', function() use ( $source ) {
            return $source;
        } );
    }
    if ( $profile !== 0 ) {
        add_filter( 'pre_option_stirfr_ticker_profile', function() use ( $profile ) {
            return $profile;
        } );
    }
    if ( $custom_url !== '' ) {
        add_filter( 'pre_option_stirfr_ticker_custom_url', function() use ( $custom_url ) {
            return $custom_url;
        } );
    }
    if ( $bg !== '' ) {
        add_filter( 'pre_option_stirfr_ticker_bg', function() use ( $bg ) {
            return $bg;
        } );
    }
    if ( $text !== '' ) {
        add_filter( 'pre_option_stirfr_ticker_text', function() use ( $text ) {
            return $text;
        } );
    }
    if ( $speed !== 30 ) {
        add_filter( 'pre_option_stirfr_ticker_speed', function() use ( $speed ) {
            return $speed;
        } );
    }

    // Render the live preview
    echo do_shortcode( '[stirfr_breaking_news]' );
    wp_die();
}

/**
 * =====================================================
 * DYNAMIC RSS SUGGESTIONS (CACHED)
 * =====================================================
 */
function stirfr_get_rss_suggestions_map(): array {

    $cache_key = 'stirfr_rss_suggestions_map';
    $cached = wp_cache_get( $cache_key, 'stirfr' );
    if ( false !== $cached && is_array( $cached ) ) {
        return $cached;
    }

    // Master keyword → feeds library
    $library = [

        // WordPress-specific
        'wordpress' => [
            [ 'label' => 'WordPress.org News',    'url' => 'https://wordpress.org/news/feed/' ],
            [ 'label' => 'WP Tavern',             'url' => 'https://wptavern.com/feed' ],
            [ 'label' => 'WPBeginner',            'url' => 'https://www.wpbeginner.com/feed/' ],
            [ 'label' => 'WPMU Dev Blog',         'url' => 'https://wpmudev.com/blog/feed/' ],
        ],
        'plugin' => [
            [ 'label' => 'WPBeginner Plugins',    'url' => 'https://www.wpbeginner.com/category/plugins/feed/' ],
            [ 'label' => 'WP Tavern',             'url' => 'https://wptavern.com/feed' ],
            [ 'label' => 'WordPress.org News',    'url' => 'https://wordpress.org/news/feed/' ],
        ],
        'theme' => [
            [ 'label' => 'ThemeIsle Blog',        'url' => 'https://themeisle.com/blog/feed/' ],
            [ 'label' => 'Elegant Themes',        'url' => 'https://www.elegantthemes.com/blog/feed/' ],
            [ 'label' => 'WPBeginner Themes',     'url' => 'https://www.wpbeginner.com/category/wp-themes/feed/' ],
        ],
        'seo' => [
            [ 'label' => 'Yoast SEO Blog',        'url' => 'https://yoast.com/feed/' ],
            [ 'label' => 'Search Engine Journal', 'url' => 'https://www.searchenginejournal.com/feed/' ],
            [ 'label' => 'Moz Blog',              'url' => 'https://moz.com/blog/feed' ],
            [ 'label' => 'Ahrefs Blog',           'url' => 'https://ahrefs.com/blog/feed/' ],
        ],
        'api' => [
            [ 'label' => 'WordPress Developer',   'url' => 'https://developer.wordpress.org/news/feed/' ],
            [ 'label' => 'CSS-Tricks',            'url' => 'https://css-tricks.com/feed/' ],
            [ 'label' => 'Smashing Magazine',     'url' => 'https://www.smashingmagazine.com/feed/' ],
        ],
        'function' => [
            [ 'label' => 'WordPress Developer',   'url' => 'https://developer.wordpress.org/news/feed/' ],
            [ 'label' => 'CSS-Tricks',            'url' => 'https://css-tricks.com/feed/' ],
        ],
        'database' => [
            [ 'label' => 'Kinsta Blog',           'url' => 'https://kinsta.com/blog/feed/' ],
            [ 'label' => 'WPMU Dev Blog',         'url' => 'https://wpmudev.com/blog/feed/' ],
        ],
        'dashboard' => [
            [ 'label' => 'WPBeginner',            'url' => 'https://www.wpbeginner.com/feed/' ],
            [ 'label' => 'ManageWP Blog',         'url' => 'https://managewp.com/blog/feed/' ],
        ],
        'tip' => [
            [ 'label' => 'WPBeginner',            'url' => 'https://www.wpbeginner.com/feed/' ],
            [ 'label' => 'Smashing Magazine',     'url' => 'https://www.smashingmagazine.com/feed/' ],
            [ 'label' => 'CSS-Tricks',            'url' => 'https://css-tricks.com/feed/' ],
        ],
        'trick' => [
            [ 'label' => 'WPBeginner',            'url' => 'https://www.wpbeginner.com/feed/' ],
            [ 'label' => 'CSS-Tricks',            'url' => 'https://css-tricks.com/feed/' ],
        ],
        'basic' => [
            [ 'label' => 'WPBeginner',            'url' => 'https://www.wpbeginner.com/feed/' ],
            [ 'label' => 'WPLift',                'url' => 'https://wplift.com/feed' ],
        ],

        // Technology
        'technology' => [
            [ 'label' => 'TechCrunch',            'url' => 'https://techcrunch.com/feed/' ],
            [ 'label' => 'The Verge',             'url' => 'https://www.theverge.com/rss/index.xml' ],
            [ 'label' => 'Wired',                 'url' => 'https://www.wired.com/feed/rss' ],
            [ 'label' => 'Ars Technica',          'url' => 'https://feeds.arstechnica.com/arstechnica/index' ],
        ],
        'tech' => [
            [ 'label' => 'TechCrunch',            'url' => 'https://techcrunch.com/feed/' ],
            [ 'label' => 'Hacker News',           'url' => 'https://hnrss.org/frontpage' ],
        ],
        'software' => [
            [ 'label' => 'TechCrunch',            'url' => 'https://techcrunch.com/feed/' ],
            [ 'label' => 'Ars Technica',          'url' => 'https://feeds.arstechnica.com/arstechnica/index' ],
        ],
        'developer' => [
            [ 'label' => 'Hacker News',           'url' => 'https://hnrss.org/frontpage' ],
            [ 'label' => 'Dev.to',                'url' => 'https://dev.to/feed' ],
            [ 'label' => 'CSS-Tricks',            'url' => 'https://css-tricks.com/feed/' ],
        ],
        'coding' => [
            [ 'label' => 'Dev.to',                'url' => 'https://dev.to/feed' ],
            [ 'label' => 'Hacker News',           'url' => 'https://hnrss.org/frontpage' ],
            [ 'label' => 'Smashing Magazine',     'url' => 'https://www.smashingmagazine.com/feed/' ],
        ],
        'programming' => [
            [ 'label' => 'Dev.to',                'url' => 'https://dev.to/feed' ],
            [ 'label' => 'Hacker News',           'url' => 'https://hnrss.org/frontpage' ],
        ],
        'web' => [
            [ 'label' => 'Smashing Magazine',     'url' => 'https://www.smashingmagazine.com/feed/' ],
            [ 'label' => 'CSS-Tricks',            'url' => 'https://css-tricks.com/feed/' ],
            [ 'label' => 'A List Apart',          'url' => 'https://alistapart.com/main/feed/' ],
        ],
        'design' => [
            [ 'label' => 'Smashing Magazine',     'url' => 'https://www.smashingmagazine.com/feed/' ],
            [ 'label' => 'A List Apart',          'url' => 'https://alistapart.com/main/feed/' ],
            [ 'label' => 'Creative Bloq',         'url' => 'https://www.creativebloq.com/feed' ],
        ],
        'ai' => [
            [ 'label' => 'MIT Tech Review AI',    'url' => 'https://www.technologyreview.com/feed/' ],
            [ 'label' => 'AI News',               'url' => 'https://artificialintelligence-news.com/feed/' ],
        ],
        'crypto' => [
            [ 'label' => 'CoinDesk',              'url' => 'https://www.coindesk.com/arc/outboundfeeds/rss/' ],
            [ 'label' => 'CoinTelegraph',         'url' => 'https://cointelegraph.com/rss' ],
        ],

        // News & Media
        'news' => [
            [ 'label' => 'BBC News',              'url' => 'https://feeds.bbci.co.uk/news/rss.xml' ],
            [ 'label' => 'Reuters',               'url' => 'https://feeds.reuters.com/reuters/topNews' ],
            [ 'label' => 'Al Jazeera',            'url' => 'https://www.aljazeera.com/xml/rss/all.xml' ],
            [ 'label' => 'AP News',               'url' => 'https://rsshub.app/apnews/topics/apf-topnews' ],
        ],
        'world' => [
            [ 'label' => 'BBC World',             'url' => 'https://feeds.bbci.co.uk/news/world/rss.xml' ],
            [ 'label' => 'Al Jazeera',            'url' => 'https://www.aljazeera.com/xml/rss/all.xml' ],
        ],
        'politics' => [
            [ 'label' => 'Politico',              'url' => 'https://www.politico.com/rss/politicopicks.xml' ],
            [ 'label' => 'The Hill',              'url' => 'https://thehill.com/feed/' ],
        ],
        'local' => [
            [ 'label' => 'BBC News',              'url' => 'https://feeds.bbci.co.uk/news/rss.xml' ],
            [ 'label' => 'Reuters',               'url' => 'https://feeds.reuters.com/reuters/topNews' ],
        ],

        // Business & Finance
        'business' => [
            [ 'label' => 'Forbes',                'url' => 'https://www.forbes.com/real-time/feed2/' ],
            [ 'label' => 'Bloomberg',             'url' => 'https://feeds.bloomberg.com/markets/news.rss' ],
            [ 'label' => 'Financial Times',       'url' => 'https://www.ft.com/?format=rss' ],
        ],
        'finance' => [
            [ 'label' => 'Investopedia',          'url' => 'https://www.investopedia.com/feedbuilder/feed/getfeed/?feedName=rss_headline' ],
            [ 'label' => 'MarketWatch',           'url' => 'https://feeds.marketwatch.com/marketwatch/topstories/' ],
        ],
        'marketing' => [
            [ 'label' => 'HubSpot Blog',          'url' => 'https://blog.hubspot.com/marketing/rss.xml' ],
            [ 'label' => 'Neil Patel Blog',       'url' => 'https://neilpatel.com/blog/feed/' ],
            [ 'label' => 'Moz Blog',              'url' => 'https://moz.com/blog/feed' ],
        ],
        'ecommerce' => [
            [ 'label' => 'Shopify Blog',          'url' => 'https://www.shopify.com/blog.atom' ],
            [ 'label' => 'WooCommerce Blog',      'url' => 'https://woocommerce.com/blog/feed/' ],
        ],
        'startup' => [
            [ 'label' => 'TechCrunch Startups',   'url' => 'https://techcrunch.com/category/startups/feed/' ],
            [ 'label' => 'Hacker News',           'url' => 'https://hnrss.org/frontpage' ],
        ],

        // Health & Science
        'health' => [
            [ 'label' => 'WebMD',                 'url' => 'https://rssfeeds.webmd.com/rss/rss.aspx?RSSSource=RSS_PUBLIC' ],
            [ 'label' => 'WHO',                   'url' => 'https://www.who.int/feeds/entity/mediacentre/news/en/rss.xml' ],
            [ 'label' => 'Healthline',            'url' => 'https://www.healthline.com/rss/health-news' ],
        ],
        'science' => [
            [ 'label' => 'NASA',                  'url' => 'https://www.nasa.gov/rss/dyn/breaking_news.rss' ],
            [ 'label' => 'Science Daily',         'url' => 'https://www.sciencedaily.com/rss/all.xml' ],
            [ 'label' => 'New Scientist',         'url' => 'https://www.newscientist.com/feed/home/' ],
        ],
        'fitness' => [
            [ 'label' => 'Healthline Fitness',    'url' => 'https://www.healthline.com/rss/health-news' ],
            [ 'label' => 'WebMD',                 'url' => 'https://rssfeeds.webmd.com/rss/rss.aspx?RSSSource=RSS_PUBLIC' ],
        ],
        'environment' => [
            [ 'label' => 'The Guardian Env',      'url' => 'https://www.theguardian.com/environment/rss' ],
            [ 'label' => 'TreeHugger',            'url' => 'https://www.treehugger.com/feeds/all/' ],
        ],

        // Sports
        'sport' => [
            [ 'label' => 'ESPN',                  'url' => 'https://www.espn.com/espn/rss/news' ],
            [ 'label' => 'BBC Sport',             'url' => 'https://feeds.bbci.co.uk/sport/rss.xml' ],
            [ 'label' => 'Sky Sports',            'url' => 'https://www.skysports.com/rss/12040' ],
        ],
        'cricket' => [
            [ 'label' => 'ESPNcricinfo',          'url' => 'https://www.espncricinfo.com/rss/content/story/feeds/0.xml' ],
        ],
        'football' => [
            [ 'label' => 'BBC Football',          'url' => 'https://feeds.bbci.co.uk/sport/football/rss.xml' ],
            [ 'label' => 'Sky Sports Football',   'url' => 'https://www.skysports.com/rss/12040' ],
        ],
        'basketball' => [
            [ 'label' => 'ESPN NBA',              'url' => 'https://www.espn.com/espn/rss/nba/news' ],
        ],

        // Entertainment & Lifestyle
        'entertainment' => [
            [ 'label' => 'Variety',               'url' => 'https://variety.com/feed/' ],
            [ 'label' => 'Hollywood Reporter',    'url' => 'https://www.hollywoodreporter.com/feed/' ],
        ],
        'movie' => [
            [ 'label' => 'Variety',               'url' => 'https://variety.com/feed/' ],
            [ 'label' => 'Hollywood Reporter',    'url' => 'https://www.hollywoodreporter.com/feed/' ],
        ],
        'music' => [
            [ 'label' => 'Rolling Stone',         'url' => 'https://www.rollingstone.com/feed/' ],
            [ 'label' => 'Pitchfork',             'url' => 'https://pitchfork.com/feed/feed-news/rss' ],
        ],
        'gaming' => [
            [ 'label' => 'IGN',                   'url' => 'https://feeds.ign.com/ign/all' ],
            [ 'label' => 'Kotaku',                'url' => 'https://kotaku.com/rss' ],
            [ 'label' => 'GameSpot',              'url' => 'https://www.gamespot.com/feeds/mashup/' ],
        ],
        'travel' => [
            [ 'label' => 'Lonely Planet',         'url' => 'https://www.lonelyplanet.com/news/feed/atom/' ],
            [ 'label' => 'Travel + Leisure',      'url' => 'https://www.travelandleisure.com/rss' ],
        ],
        'food' => [
            [ 'label' => 'Serious Eats',          'url' => 'https://www.seriouseats.com/atom.xml' ],
            [ 'label' => 'Food Network',          'url' => 'https://www.foodnetwork.com/fn-dish/feed/' ],
        ],
        'fashion' => [
            [ 'label' => 'Vogue RSS',             'url' => 'https://www.vogue.com/feed/rss' ],
            [ 'label' => 'Elle',                  'url' => 'https://www.elle.com/rss/all.xml/' ],
        ],
        'education' => [
            [ 'label' => 'EdSurge',               'url' => 'https://www.edsurge.com/news.rss' ],
            [ 'label' => 'Times Higher Ed',       'url' => 'https://www.timeshighereducation.com/rss.xml' ],
        ],
    ];

    $categories = get_categories( [ 'hide_empty' => false ] );
    $map        = [];

    foreach ( $categories as $cat ) {

        $cat_name = strtolower( $cat->name );
        $cat_slug = strtolower( $cat->slug );
        $found    = [];
        $seen_urls = [];

        foreach ( $library as $keyword => $feeds ) {
            // PHP 7.4 compatible matching (str_contains replaced with strpos)
            if (
                strpos( $cat_name, $keyword ) !== false ||
                strpos( $cat_slug, $keyword ) !== false ||
                strpos( $keyword, $cat_name ) !== false ||
                strpos( $keyword, $cat_slug ) !== false
            ) {
                foreach ( $feeds as $feed ) {
                    if ( ! isset( $seen_urls[ $feed['url'] ] ) ) {
                        $seen_urls[ $feed['url'] ] = true;
                        $found[] = $feed;
                    }
                }
            }
        }

        if ( ! empty( $found ) ) {
            $map[ $cat_name ] = $found;
            $map[ $cat_slug ] = $found;
        }
    }

    wp_cache_set( $cache_key, $map, 'stirfr', HOUR_IN_SECONDS );
    return $map;
}

/* =====================================================
 * Global helpers (used in admin, frontend, and cron)
 * ===================================================== */
function stirfr_get_profiles(): array {
    $profiles = get_option( 'stirfr_profiles', [] );
    return is_array( $profiles ) ? $profiles : [];
}

function stirfr_next_profile_id( array $profiles ): int {
    $ids = array_map( 'intval', array_keys( $profiles ) );
    return $ids ? max( $ids ) + 1 : 1;
}