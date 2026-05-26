<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}
?>
<div class="stirfr-welcome-wrap">
  <div class="stirfr-hero">
    <div class="stirfr-badge">New</div>

    <h1 class="stirfr-title">
      <span class="stirfr-gradient-text">STI RSS Feed Reader</span>
    </h1>

    <p class="stirfr-subtitle">
      Pull beautiful, fast, image-rich RSS blocks into your site—with optional post storage, layout controls, and theme-friendly “Read more” links.
    </p>

    <div class="stirfr-actions">
      <a href="<?php echo esc_url( admin_url( 'admin.php?page=simple-rss-feed#stirfr-settings' ) ); ?>"
         class="button button-primary button-hero">
        Open Plugin Settings
      </a>

      <a href="<?php echo esc_url( admin_url( 'admin.php?page=stirfr-welcome#docs' ) ); ?>"
         class="button button-hero">
        Shortcode & Docs
      </a>
    </div>

    <div class="stirfr-logo-pill">
      <img src="<?php echo esc_url( trailingslashit( STIRFR_PLUGIN_URL ) . 'assets/img/santechidea-logo.png' ); ?>"
           alt="SantechIdea">
      <span>Welcome</span>
    </div>

    <div class="stirfr-ornament">
      <div class="stirfr-pulse"></div>
      <div class="sti-pulse delay"></div>
    </div>
  </div>

  <div class="stirfr-grid">
    <div class="stirfr-card">
      <div class="stirfr-icon">🖼️</div>
      <h3>Smart Images</h3>
      <p>Extract from feeds, use FIFU if available, or save WebP locally. Always keeps theme performance in mind.</p>
    </div>
    <div class="stirfr-card">
      <div class="stirfr-icon">🧱</div>
      <h3>Flexible Layouts</h3>
      <p>List, grid, or cards—clean markup that inherits your theme styles. Adds Customize color to your Design.</p>
    </div>
    <div class="stirfr-card">
      <div class="stirfr-icon">📦</div>
      <h3>Optional Post Storage</h3>
      <p>Store items as WP posts (draft/publish), set retention (rolling/fixed), and clean up safely.</p>
    </div>
  </div>

  <div class="stirfr-steps" id="docs">
    <h2>Get Started in 3 Steps</h2>
    <ol>
      <li><strong>Add feed URLs</strong> in <em>Settings → STI RSS Feed Reader</em>.</li>
      <li><strong>Choose a layout</strong> (list/grid) and count per feed.</li>
      <li><strong>Drop the shortcode</strong> anywhere: <code>[stirfr_rss_feed]</code></li>
    </ol>
    <p class="stirfr-note">Example: <code>[stirfr_rss_feed layout="grid" items="6"]</code></p>
  </div>

  <div class="stirfr-footer">
    <div class="stirfr-meter">
      <div class="stirfr-meter-bar" data-to="100"></div>
    </div>
  </div>
</div>