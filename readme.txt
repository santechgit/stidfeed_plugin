=== STI RSS Feed Reader ===
Contributors: santechidea
Tags: rss, feed, reader, import, posts
Requires at least: 5.9
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 1.2.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

A simple and lightweight RSS feed reader plugin to display feeds in list or grid layouts, with optional post storage and cleanup.

== Description ==
STI RSS Feed Reader is a modern, lightweight plugin that makes it easy to display RSS feeds beautifully in your WordPress site.
It includes image fallback support, flexible layouts (list or grid), an optional "Store as Posts" feature, and automatic cleanup for stored posts.

**Key Features:**
* Display RSS feeds in responsive list or grid layout.
* Store feed items as WordPress posts (optional).
* Choose custom post status: publish, draft, or pending.
* Built-in cleanup system to automatically expire old items.
* Fallback image support for feeds without featured images.
* Works with multiple feed profiles (create and manage different feeds).
* Breaking news ticker shortcode with live admin preview.
* No theme lock-in: inherits your theme's styling.

== Installation ==
1. Upload the plugin files to `/wp-content/plugins/sti-rss-feed-reader/`, or install via the WordPress Plugins screen.
2. Activate the plugin through the "Plugins" screen in WordPress.
3. Go to the **STI RSS Feed** menu in your WordPress admin dashboard to configure settings.
4. Add the shortcode `[stirfr_rss_feed id="X"]` to any page or post (replace `X` with the profile ID).

== Frequently Asked Questions ==

= Does this plugin create posts automatically? =
Only if you enable the "Store as Posts" option in a feed profile. Otherwise, it only displays feeds.

= Can I customize the layout? =
Yes, you can switch between **List** and **Grid** layouts, and choose the number of columns.

= How does cleanup work? =
Stored posts get an expiration date. You can set how many days they remain before being automatically trashed or deleted.

= Will it slow down my site? =
No, the plugin uses caching and efficient queries. You can also control how often feeds are fetched.

== Shortcodes ==

Display a feed profile:
  [stirfr_rss_feed id="1"]

Breaking news ticker:
  [stirfr_breaking_news]

Site-wide RSS feed button:
  [stirfr_rss_links]

RSS button with custom colors:
  [stirfr_rss_links label="Subscribe" color="#fff" bg="#e11d48"]

All category feeds as buttons:
  [stirfr_rss_links type="categories"]

Single category RSS button:
  [stirfr_rss_link category="your-category-slug"]

Single category with custom colors:
  [stirfr_rss_link category="tech" color="#fff" bg="#2563eb" label="Tech Feed"]

== Screenshots ==
1. Pull beautiful, fast, image-rich RSS blocks into your site.
2. Example of RSS feed in grid layout with images.
3. Example of RSS feed in list layout with images.
4. Store items as posts, configure retention and run cleanup.
5. Create different feeds for different pages.
6. Choose card background, main text color, and Read more link color.
7. Breaking News Ticker with live preview, customizable speed, colors, and multiple feed sources.

== Changelog ==

= 1.2.4 =
* Fixed: Admin menu icon path and duplicate function declarations.
* Fixed: PHP 7.4 compatibility (replaced `str_contains` with `strpos`).
* Fixed: Cron job duplication and activation hook placement.
* Improved: Live ticker preview AJAX handler with nonce verification.
* Improved: CSS and JS asset versioning with filemtime fallback.

= 1.2.0 =
* New: RSS tab in admin panel — view and copy RSS feed URLs for all categories, tags, and site-wide feeds.
* New: `[stirfr_rss_links]` shortcode — display a site-wide RSS feed button on any page, post, or widget.
* New: `[stirfr_rss_link]` shortcode — display a single RSS button for a specific category or tag.
* New: Custom color support (`color` and `bg` attributes) for RSS link shortcodes.
* New: 6 additional "Read More" button styles — Gradient, Pill, Underline, Elevated, Glass, and Dark.
* New: Shortcode support in block-based widgets (Custom HTML block, Shortcode block).
* Improved: Feed image extraction now checks `content:encoded` and enclosure thumbnails, not just description.
* Improved: Local feed fallback — own-site RSS feeds now query the database directly instead of HTTP loopback.
* Fixed: RSS feed XML corruption caused by plugin HTML being injected into category feeds.
* Fixed: Missing images when fetching feeds from same-site category URLs.
* Security: Improved escaping and input sanitization across multiple files.

= 1.1.8 =
* New: "Suggest RSS Feed URL" feature to help users discover valid feed links.
* Improved: Full plugin optimization — cleaner code, better performance, reduced DB queries.
* Improved: Consolidated asset enqueuing and cleanup helpers.
* Improved: Activation/deactivation hooks centralized in main plugin file.
* Fixed: Minor bug fixes and stability improvements.

= 1.1.7 =
* New: Breaking News Ticker tab in admin panel.
* New: Customizable ticker with multiple sources (profile, custom URL, stored posts).
* Improved: Refactored feed rendering for better performance.
* Fixed: Feed positioning issues across header, footer, and content areas.
* Security: Added nonce verification for admin preview requests.

= 1.1.6 =
* Improved feed structure and positioning.

= 1.1.5 =
* New: Category-based Feed Visibility.
* New: Automatic Feed Positioning with multiple placement options.
* New: "Show on Pages" toggle.
* Improved: Admin UI layout and responsiveness.

= 1.1.4 =
* Improved: Read More control layout and live preview.
* New: Default Fallback Image Support.
* Improved: Cleaner markup and styling.

= 1.1.3 =
* Security hardening and sanitization improvements.
* Removed frontend branding, replaced with source attribution.

= 1.1.2 =
* Security hardening and sanitization improvements.

= 1.1.0 =
* Initial public release.
* Added List and Grid layouts.
* Added "Store as Posts" feature with cleanup system.
* Added fallback image support.

== Development Notes ==
All JavaScript files included in this plugin are human-readable source files.
No minified or obfuscated JavaScript is used.