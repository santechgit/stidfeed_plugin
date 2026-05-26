/**
 * Admin UI logic for STI RSS Feed Reader
 * Human-readable source file (not minified).
 */

(function ($) {
    "use strict";

    /* ===========================
     * Document Ready Block
     * =========================== */
    $(document).ready(function () {

        /* ===========================
         * Header meter animation
         * =========================== */
        const meter = document.getElementById("srfMeter");
        if (meter) {
            requestAnimationFrame(() => {
                meter.style.width = "100%";
            });
        }

        /* ===========================
         * Removed profiles tracking
         * =========================== */
        const form = document.getElementById("srfAllForm");
        let removedProfilesInput = document.getElementById("stirfr_removed_profiles");

        if (form && !removedProfilesInput) {
            removedProfilesInput = document.createElement("input");
            removedProfilesInput.type = "hidden";
            removedProfilesInput.id = "stirfr_removed_profiles";
            removedProfilesInput.name = "stirfr_removed_profiles";
            removedProfilesInput.value = "";
            form.appendChild(removedProfilesInput);
        }

        function markProfileRemoved(profileId) {
            if (!profileId || !removedProfilesInput) return;
            const ids = removedProfilesInput.value
                ? removedProfilesInput.value.split(",").map(Number)
                : [];
            if (!ids.includes(profileId)) {
                ids.push(profileId);
                removedProfilesInput.value = ids.join(",");
            }
        }

        /* ===========================
         * Tabs
         * =========================== */
        const $tabs = $(".sti-admin-tabs .nav-tab");
        const $panels = $(".sti-tab-content");

        function activateTab(id) {
            $tabs.removeClass("nav-tab-active");
            $tabs.filter('[href="#' + id + '"]').addClass("nav-tab-active");
            $panels.hide();
            $("#" + id).show();
        }

        $tabs.on("click", function (e) {
            e.preventDefault();
            const id = $(this).attr("href").replace("#", "");
            activateTab(id);
            window.location.hash = id;
        });

        const hash = window.location.hash.replace("#", "");
        activateTab(hash && $("#" + hash).length ? hash : "stirfr-dashboard");

        /* ===========================
         * Copy debug info
         * =========================== */
        const copyBtn = document.getElementById("stiCopyDebug");
        const pre = document.getElementById("stiDebugPre");

        if (copyBtn && pre && navigator.clipboard) {
            copyBtn.addEventListener("click", function () {
                navigator.clipboard
                    .writeText(pre.textContent)
                    .then(() => {
                        copyBtn.textContent = "Copied ✓";
                        setTimeout(() => (copyBtn.textContent = "Copy Debug Info"), 2200);
                    })
                    .catch(() => {
                        alert("Copy failed — please copy manually.");
                    });
            });
        }

        /* ===========================
         * FAQ accordion
         * =========================== */
        $(".sti-faq-toggle").on("click", function () {
            const $btn = $(this);
            const id = $btn.data("target");
            const $panel = $("#" + id);
            if (!$panel.length) return;

            const expanded = $btn.attr("aria-expanded") === "true";
            $btn.attr("aria-expanded", String(!expanded));
            $panel.prop("hidden", expanded);

            if (!expanded) {
                $panel[0].scrollIntoView({ behavior: "smooth", block: "nearest" });
            }
        });

        /* ===========================
         * Profile accordions
         * =========================== */
        const accordions = document.querySelectorAll(".srf-acc");
        accordions.forEach(function (acc) {
            acc.addEventListener("toggle", function () {
                if (!acc.open) return;
                accordions.forEach(function (other) {
                    if (other !== acc) other.removeAttribute("open");
                });
            });
        });

        /* ===========================
         * Preview elements
         * =========================== */
        const cardColorInput = document.getElementById("stirfr_card_color");
        const textColorInput = document.getElementById("stirfr_text_color");
        const readmoreColorInput = document.getElementById("stirfr_readmore_color");

        const previewBox = document.getElementById("srf-color-preview");
        const previewLink = document.getElementById("srf-readmore-preview");

        const readmoreToggle = document.querySelector('input[name="stirfr_readmore_button_enabled"]');
        const readmoreStyle = document.querySelector('select[name="stirfr_readmore_button_style"]');
        const readmoreText = document.querySelector('input[name="stirfr_readmore_button_text"]');

        /* ===========================
         * Apply color preview
         * =========================== */
        function applyPreviewColors() {
            if (!previewBox) return;
            previewBox.style.setProperty("--stirfr-card-bg", cardColorInput?.value || "#ffffff");
            previewBox.style.setProperty("--stirfr-text-color", textColorInput?.value || "#111111");
            previewBox.style.setProperty("--stirfr-readmore-color", readmoreColorInput?.value || "#0073aa");
        }

        /* ===========================
         * Apply Read More preview
         * =========================== */
        function applyReadMorePreview() {
            if (!previewLink) return;

            if (!readmoreToggle || !readmoreToggle.checked) {
                previewLink.className = "stirfr-read-more";
                previewLink.style.removeProperty("--stirfr-readmore-color");
                previewLink.textContent = readmoreText?.value || "Read more";
                return;
            }

            const style = readmoreStyle?.value || "style1";
            previewLink.className = "stirfr-read-more stirfr-btn stirfr-btn-" + style;

            if (readmoreText?.value.trim()) {
                previewLink.textContent = readmoreText.value;
            }

            if (readmoreColorInput?.value) {
                previewLink.style.setProperty("--stirfr-readmore-color", readmoreColorInput.value);
            }
        }

        /* ===========================
         * Bind preview events
         * =========================== */
        [cardColorInput, textColorInput, readmoreColorInput, readmoreToggle, readmoreStyle, readmoreText]
            .forEach((el) => {
                if (!el) return;
                el.addEventListener("input", () => { applyPreviewColors(); applyReadMorePreview(); });
                el.addEventListener("change", () => { applyPreviewColors(); applyReadMorePreview(); });
            });

        applyPreviewColors();
        applyReadMorePreview();

        /* ===========================
         * Profiles list logic
         * =========================== */
        const list = document.getElementById("srfProfilesList");

        function renumberNames() {
            if (!list) return;
            list.querySelectorAll(".srf-acc:not(.srf-acc-template)").forEach((block, i) => {
                block.querySelectorAll("[name]").forEach((el) => {
                    el.name = el.name.replace(/\[\d+\]/, `[${i}]`);
                });
            });
        }

        function openMediaPicker(input) {
            if (typeof wp === "undefined" || !wp.media) return;
            const frame = wp.media({
                title: "Select Image",
                button: { text: "Use this image" },
                multiple: false,
            });
            frame.on("select", () => {
                const file = frame.state().get("selection").first().toJSON();
                if (input) input.value = file.url || "";
            });
            frame.open();
        }

        if (list) {
            list.addEventListener("click", function (e) {

                if (e.target.classList.contains("srf-pick-image")) {
                    e.preventDefault();
                    const input = e.target.closest(".srf-acc-imgpick")?.querySelector("input");
                    openMediaPicker(input);
                }

                if (e.target.classList.contains("srf-remove-row")) {
                    e.preventDefault();
                    if (!confirm("This will permanently delete all posts created by this profile.\nAre you sure?")) {
                        return;
                    }
                    const acc = e.target.closest(".srf-acc");
                    if (!acc || acc.classList.contains("srf-acc-template")) return;

                    const hiddenId = acc.querySelector('input[name^="profile_id"]');
                    if (hiddenId && hiddenId.value) {
                        markProfileRemoved(parseInt(hiddenId.value, 10));
                    }
                    acc.remove();
                    renumberNames();
                }
            });
        }

        /* ===========================
         * Ticker live preview (with nonce)
         * =========================== */
        function stirfrUpdateTickerPreview() {
            // Get nonce from localized script (added in PHP)
            var nonce = (window.stirfr_ajax && window.stirfr_ajax.ticker_nonce)
                ? window.stirfr_ajax.ticker_nonce
                : '';

            if (!nonce) {
                console.warn('STIRFR: Ticker preview nonce missing. AJAX request may fail.');
            }

            var data = {
                action: 'stirfr_live_preview',
                nonce: nonce,
                stirfr_ticker_source: $('select[name="stirfr_ticker_source"]').val(),
                stirfr_ticker_profile: $('select[name="stirfr_ticker_profile"]').val(),
                stirfr_ticker_custom_url: $('input[name="stirfr_ticker_custom_url"]').val(),
                stirfr_ticker_bg: $('input[name="stirfr_ticker_bg"]').val(),
                stirfr_ticker_text: $('input[name="stirfr_ticker_text"]').val(),
                stirfr_ticker_speed: $('input[name="stirfr_ticker_speed"]').val(),
            };
            $.post(ajaxurl, data, function (response) {
                $('#stirfr-live-preview').html(response);
            });
        }

        $(document).on(
            'change keyup',
            'select[name="stirfr_ticker_source"], ' +
            'select[name="stirfr_ticker_profile"], ' +
            'input[name="stirfr_ticker_custom_url"], ' +
            'input[name="stirfr_ticker_bg"], ' +
            'input[name="stirfr_ticker_text"], ' +
            'input[name="stirfr_ticker_speed"]',
            stirfrUpdateTickerPreview
        );

        /* ===========================
         * Ticker source toggle
         * =========================== */
        function toggleTickerSource() {
            var source = $('#stirfr_ticker_source').val();
            $('.stirfr-source-profile, .stirfr-source-custom, .stirfr-source-stored').hide();
            if (source === 'profile') { $('.stirfr-source-profile').fadeIn(); }
            if (source === 'custom') { $('.stirfr-source-custom').fadeIn(); }
            if (source === 'stored') { $('.stirfr-source-stored').fadeIn(); }
        }

        toggleTickerSource();
        $('#stirfr_ticker_source').on('change', toggleTickerSource);

        /* ===========================
         * RSS Suggestion Engine
         * =========================== */
        var cats = (window.STIRFR_CATS && STIRFR_CATS.categories) || {};
        var suggestion = (window.STIRFR_CATS && STIRFR_CATS.suggestions) || {};

        function getSuggestions(termId) {
            if (!termId || termId === '0') return [];
            var cat = cats[termId];
            if (!cat) return [];

            var keywords = [cat.name.toLowerCase(), cat.slug.toLowerCase()];
            var found = [];
            var seen = {};

            keywords.forEach(function (kw) {
                if (suggestion[kw]) {
                    suggestion[kw].forEach(function (s) {
                        if (!seen[s.url]) { seen[s.url] = 1; found.push(s); }
                    });
                }
                Object.keys(suggestion).forEach(function (key) {
                    if (key !== kw && (key.indexOf(kw) !== -1 || kw.indexOf(key) !== -1)) {
                        suggestion[key].forEach(function (s) {
                            if (!seen[s.url]) { seen[s.url] = 1; found.push(s); }
                        });
                    }
                });
            });
            return found;
        }

        function renderSuggestions($accBody, termId) {
            $accBody.find('.srf-rss-suggestions').remove();
            var list = getSuggestions(termId);
            if (!list.length) return;

            var $box = $('<div class="srf-rss-suggestions"></div>');
            $box.append('<p class="srf-suggestions-label">💡 Suggested RSS feeds for this category — click to add:</p>');

            var $ul = $('<ul class="srf-suggestions-list"></ul>');
            list.forEach(function (s) {
                var $li = $('<li></li>');
                var $btn = $('<button type="button" class="button button-small srf-add-suggestion"></button>')
                    .text('+ ' + s.label)
                    .data('url', s.url);
                $li.append($btn);
                $ul.append($li);
            });

            $box.append($ul);
            $accBody.find('.srf-acc-row').first().after($box);
        }

        function addUrlToTextarea($textarea, url) {
            var current = $textarea.val().trim();
            var lines = current ? current.split(/\n/) : [];
            if (lines.indexOf(url) !== -1) return;
            lines.push(url);
            $textarea.val(lines.join('\n'));
        }

        // On category change → show suggestions
        $(document).on('change', '#srfProfilesList select[name^="profile_category"]', function () {
            var $accBody = $(this).closest('.srf-acc-body');
            renderSuggestions($accBody, $(this).val());
        });

        // On page load → show suggestions for already-selected categories
        $('#srfProfilesList .srf-acc-body').each(function () {
            var $body = $(this);
            var $select = $body.find('select[name^="profile_category"]');
            if ($select.length && $select.val() !== '0') {
                renderSuggestions($body, $select.val());
            }
        });

        // On suggestion button click → add URL to textarea
        $(document).on('click', '.srf-add-suggestion', function () {
            var $btn = $(this);
            var url = $btn.data('url');
            var $accBody = $btn.closest('.srf-acc-body');
            var $textarea = $accBody.find('textarea[name^="profile_urls"]');
            addUrlToTextarea($textarea, url);
            $btn.text('✓ Added').prop('disabled', true).addClass('srf-btn-added');
        });

        /* ===========================
         * RSS tab – copy feed URL (optional)
         * =========================== */
        $(document).on('click', '.srf-copy-rss', function () {
            var $btn = $(this);
            var url = $btn.data('url');
            if (!url || !navigator.clipboard) return;

            navigator.clipboard.writeText(url).then(function () {
                var original = $btn.text();
                $btn.text('Copied ✓').addClass('srf-copied');
                setTimeout(function () {
                    $btn.text(original).removeClass('srf-copied');
                }, 2000);
            });
        });

    }); // end document.ready

})(jQuery);