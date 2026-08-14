<?php
/**
 * Plugin Name: Page/CPT Export Import with ACF Media
 * Description: Export/import any number of pages, post types and taxonomies in one file, with content, featured images, content images, taxonomy terms (description, hierarchy, term meta and term images), post meta, and ACF image/file/gallery fields. Media files can be embedded inside the JSON so a local site can be imported into a live site, or the other way round.
 * Version: 2.2.0
 * Author: Isuru Perera
 */

if (!defined('ABSPATH')) {
    exit;
}

class WL_Page_CPT_Export_Import_ACF_Media {

    const SCHEMA_VERSION     = 3;
    const MIN_SCHEMA_VERSION = 2;
    const SOURCE_URL_META    = '_wl_original_source_url';
    const SOURCE_ID_META     = '_wl_source_attachment_id';
    const SOURCE_SITE_META   = '_wl_source_site';

    private $uploads_cache       = null;
    private $attachment_id_cache = [];

    private $media_manifest = [];
    private $media_paths    = [];

    private $media_key_map = [];
    private $media_id_map  = [];
    private $media_url_map = [];
    private $url_search    = [];
    private $url_replace   = [];
    private $source_base   = '';

    private $term_manifest      = [];
    private $term_queue         = [];
    private $term_key_map       = [];
    private $term_id_map        = [];
    private $missing_taxonomies = [];
    private $update_terms       = true;

    private $report = [];

    private $internal_taxonomies = [
        'nav_menu',
        'link_category',
        'post_format',
        'wp_theme',
        'wp_template_part_area',
        'wp_pattern_category',
    ];

    private $skip_meta_keys = [
        '_edit_lock',
        '_edit_last',
        '_thumbnail_id',
        '_wp_old_slug',
        '_wp_old_date',
        '_pingme',
        '_encloseme',
        self::SOURCE_URL_META,
        self::SOURCE_ID_META,
        self::SOURCE_SITE_META,
    ];

    private $media_id_meta_keys = [
        '_product_image_gallery',
        'thumbnail_id',
    ];

    public function __construct() {
        add_action('admin_menu', [$this, 'admin_menu']);
        add_action('admin_post_wl_cpt_export', [$this, 'handle_export']);
        add_action('admin_post_wl_cpt_import', [$this, 'handle_import']);
    }

    public function admin_menu() {
        add_management_page(
            'CPT Export Import',
            'CPT Export Import',
            'manage_options',
            'wl-cpt-export-import',
            [$this, 'admin_page']
        );
    }

    public function admin_page() {
        $report = get_transient('wl_cpt_import_report_' . get_current_user_id());
        ?>
        <div class="wrap wl-eix">
            <?php $this->render_admin_styles(); ?>

            <h1>Page / CPT / Taxonomy Export Import</h1>
            <p class="wl-eix-lede">
                Tick any number of pages, post types and taxonomies — they all travel in a single JSON file, together
                with their ACF images and media. Taxonomy terms carry their description, hierarchy, term meta and term
                images. Leave a panel untouched to skip it.
            </p>

            <?php if (!empty($_GET['imported']) && is_array($report)) : ?>
                <?php $this->render_import_report($report); ?>
            <?php endif; ?>

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="wl-eix-card">
                <?php wp_nonce_field('wl_cpt_export_nonce'); ?>

                <input type="hidden" name="action" value="wl_cpt_export">

                <div class="wl-eix-card-head">
                    <h2>Export</h2>
                    <p>Everything you tick below ends up in one file, so a page, a custom post type and a taxonomy can move together.</p>
                </div>

                <div class="wl-eix-grid">
                    <?php
                    $this->render_choice_panel([
                        'id'       => 'pages',
                        'title'    => 'Pages',
                        'field'    => 'page_id[]',
                        'singular' => 'page',
                        'plural'   => 'pages',
                        'empty'    => 'This site has no pages.',
                        'items'    => $this->page_choices(),
                        'note'     => 'A parent page that is not ticked is not exported, and its child arrives on the target site as a top-level page.',
                    ]);

                    $this->render_choice_panel([
                        'id'       => 'post_types',
                        'title'    => 'Post Types',
                        'field'    => 'post_type[]',
                        'singular' => 'post type',
                        'plural'   => 'post types',
                        'empty'    => 'No public post types are registered.',
                        'items'    => $this->post_type_choices(),
                        'note'     => 'Every post of a ticked type is exported, whatever its status.',
                    ]);

                    $this->render_choice_panel([
                        'id'       => 'taxonomies',
                        'title'    => 'Taxonomies',
                        'field'    => 'taxonomy[]',
                        'singular' => 'taxonomy',
                        'plural'   => 'taxonomies',
                        'empty'    => 'No taxonomies are registered.',
                        'items'    => $this->taxonomy_choices(),
                        'note'     => 'Terms travel with their description, parent, term meta and images — ACF term image/gallery fields and WooCommerce category thumbnails included. Terms can be exported on their own, with no posts at all.',
                    ]);
                    ?>
                </div>

                <div class="wl-eix-options">
                    <label class="wl-eix-option">
                        <input type="checkbox" name="embed_media" value="1" checked>
                        <span class="wl-eix-option-text">
                            <strong>Embed the media files inside the JSON</strong>
                            <span>
                                Keep this on when moving a <strong>local site to a live site</strong>. The live server
                                cannot download from a <code>.local</code> address, so the files have to travel inside
                                the JSON. Turn it off only when the source site is publicly reachable from the target
                                server — the JSON stays small, but the target downloads each file over HTTP.
                            </span>
                        </span>
                    </label>

                    <label class="wl-eix-option">
                        <input type="checkbox" name="include_all_terms" value="1">
                        <span class="wl-eix-option-text">
                            <strong>Also export every term of the ticked post types' taxonomies</strong>
                            <span>
                                By default only the terms actually assigned to the exported posts travel. Turn this on
                                to bring across the full term tree, including terms that currently have no posts.
                            </span>
                        </span>
                    </label>
                </div>

                <div class="wl-eix-footer">
                    <p class="wl-eix-summary" id="wl_eix_summary" aria-live="polite">
                        Nothing ticked yet.
                    </p>

                    <button type="submit" class="button button-primary" id="wl_eix_submit">Export JSON</button>
                </div>
            </form>

            <form method="post" enctype="multipart/form-data" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="wl-eix-card">
                <?php wp_nonce_field('wl_cpt_import_nonce'); ?>

                <input type="hidden" name="action" value="wl_cpt_import">

                <div class="wl-eix-card-head">
                    <h2>Import</h2>
                    <p>
                        This server accepts uploads up to
                        <strong><?php echo esc_html(size_format(wp_max_upload_size())); ?></strong>
                        (<code>upload_max_filesize</code> <?php echo esc_html(ini_get('upload_max_filesize')); ?>,
                        <code>post_max_size</code> <?php echo esc_html(ini_get('post_max_size')); ?>).
                        Anything bigger has to go up by FTP into
                        <code><?php echo esc_html($this->uploads_info()['basedir']); ?></code>
                        and be pointed at with the server path field.
                    </p>
                </div>

                <div class="wl-eix-field">
                    <label class="wl-eix-label" for="wl_import_file">JSON file</label>
                    <input type="file" name="import_file" id="wl_import_file" accept=".json,application/json">
                    <p class="description">Upload the file produced by the export above.</p>
                </div>

                <div class="wl-eix-field">
                    <label class="wl-eix-label" for="wl_server_path">Or a path inside the uploads folder</label>
                    <input type="text" name="server_path" id="wl_server_path" class="regular-text"
                           placeholder="wl-export-product-2026-08-14-10-30-00.json">
                    <p class="description">
                        Filename or path of a JSON file already sitting in the uploads folder. Use this when the export
                        is too large to upload through the browser.
                    </p>
                </div>

                <div class="wl-eix-field">
                    <label class="wl-eix-label" for="wl_source_url">Source URL override</label>
                    <input type="url" name="source_url" id="wl_source_url" class="regular-text"
                           placeholder="https://staging.example.com">
                    <p class="description">
                        Only used when the JSON has no embedded media. Media URLs are re-pointed at this address before
                        downloading, which helps when the files were exported from an unreachable host but are available
                        somewhere else.
                    </p>
                </div>

                <div class="wl-eix-options">
                    <label class="wl-eix-option">
                        <input type="checkbox" name="update_terms" value="1" checked>
                        <span class="wl-eix-option-text">
                            <strong>Update terms that already exist on this site</strong>
                            <span>
                                A term is matched by slug, then by name. With this on, the matched term's name,
                                description, parent, term meta and term images are overwritten with the exported values.
                                Turn it off to leave existing terms untouched and only attach posts to them.
                            </span>
                        </span>
                    </label>
                </div>

                <div class="wl-eix-footer">
                    <p class="wl-eix-summary">Media already on this site is reused rather than uploaded twice.</p>

                    <button type="submit" class="button button-primary">Import JSON</button>
                </div>
            </form>
        </div>

        <script>
            document.addEventListener('DOMContentLoaded', function () {
                const groups = Array.prototype.slice.call(document.querySelectorAll('.wl-eix-group'));
                const summary = document.getElementById('wl_eix_summary');
                const submit = document.getElementById('wl_eix_submit');

                if (!groups.length) {
                    return;
                }

                function itemsOf(group) {
                    return Array.prototype.slice.call(group.querySelectorAll('.wl-eix-item'));
                }

                function checkedCount(group) {
                    return group.querySelectorAll('input[type="checkbox"]:checked').length;
                }

                function updateSummary() {
                    const parts = [];

                    groups.forEach(function (group) {
                        const count = checkedCount(group);

                        if (count) {
                            const word = count === 1 ? group.dataset.singular : group.dataset.plural;

                            parts.push(count + ' ' + word);
                        }
                    });

                    if (summary) {
                        summary.textContent = parts.length
                            ? 'Exporting ' + parts.join(', ') + '.'
                            : 'Nothing ticked yet.';
                    }

                    if (submit) {
                        submit.disabled = parts.length === 0;
                    }
                }

                groups.forEach(function (group) {
                    const badge = group.querySelector('[data-role="badge"]');
                    const filter = group.querySelector('.wl-eix-filter');
                    const noMatch = group.querySelector('.wl-eix-nomatch');

                    function refresh() {
                        const count = checkedCount(group);

                        if (badge) {
                            badge.textContent = count;
                            badge.classList.toggle('is-active', count > 0);
                        }

                        updateSummary();
                    }

                    group.addEventListener('change', refresh);

                    group.querySelectorAll('[data-action]').forEach(function (button) {
                        button.addEventListener('click', function () {
                            const wanted = button.dataset.action === 'all';

                            // Only what the filter is currently showing, so
                            // "All" after a search means "all of these".
                            itemsOf(group).forEach(function (item) {
                                if (item.classList.contains('is-hidden')) {
                                    return;
                                }

                                const box = item.querySelector('input[type="checkbox"]');

                                if (box) {
                                    box.checked = wanted;
                                }
                            });

                            refresh();
                        });
                    });

                    if (filter) {
                        filter.addEventListener('input', function () {
                            const needle = filter.value.trim().toLowerCase();
                            let visible = 0;

                            itemsOf(group).forEach(function (item) {
                                const match = needle === '' || item.dataset.search.indexOf(needle) !== -1;

                                item.classList.toggle('is-hidden', !match);

                                if (match) {
                                    visible++;
                                }
                            });

                            if (noMatch) {
                                noMatch.hidden = visible > 0;
                            }
                        });
                    }

                    refresh();
                });
            });
        </script>
        <?php
    }

    private function render_admin_styles() {
        ?>
        <style>
            .wl-eix { max-width: 1180px; }
            .wl-eix-lede { max-width: 78ch; font-size: 14px; line-height: 1.6; color: #50575e; }
            .wl-eix-card { background: #fff; border: 1px solid #dcdcde; border-radius: 6px; padding: 22px 24px 20px; margin: 20px 0; box-shadow: 0 1px 2px rgba(0, 0, 0, .05); }
            .wl-eix-card-head { margin-bottom: 18px; }
            .wl-eix-card-head h2 { margin: 0 0 5px; padding: 0; font-size: 17px; line-height: 1.4; }
            .wl-eix-card-head p { margin: 0; max-width: 78ch; color: #50575e; }
            .wl-eix-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(258px, 1fr)); gap: 16px; }
            .wl-eix-group { min-width: 0; margin: 0; padding: 0; border: 0; }
            .wl-eix-panel { display: flex; flex-direction: column; height: 100%; overflow: hidden; background: #fff; border: 1px solid #dcdcde; border-radius: 5px; }
            .wl-eix-panel-head { display: flex; align-items: center; justify-content: space-between; gap: 8px; padding: 10px 12px; background: #f6f7f7; border-bottom: 1px solid #dcdcde; }
            .wl-eix-panel-title { font-size: 12px; font-weight: 600; letter-spacing: .03em; text-transform: uppercase; color: #1d2327; }
            .wl-eix-badge { min-width: 22px; padding: 1px 7px; border-radius: 10px; background: #dcdcde; color: #50575e; font-size: 12px; font-weight: 600; text-align: center; }
            .wl-eix-badge.is-active { background: #2271b1; color: #fff; }
            .wl-eix-panel-tools { display: flex; align-items: center; gap: 8px; padding: 8px 12px; border-bottom: 1px solid #f0f0f1; }
            .wl-eix-filter { flex: 1 1 auto; min-width: 0; }
            .wl-eix-actions { flex: 0 0 auto; white-space: nowrap; font-size: 12px; color: #c3c4c7; }
            .wl-eix-actions button { font-size: 12px; }
            .wl-eix-list { max-height: 320px; overflow-y: auto; }
            .wl-eix-item { display: flex; align-items: flex-start; gap: 9px; padding: 7px 12px; border-bottom: 1px solid #f0f0f1; cursor: pointer; }
            .wl-eix-item:last-child { border-bottom: 0; }
            .wl-eix-item:hover { background: #f0f6fc; }
            .wl-eix-item.is-hidden { display: none; }
            .wl-eix-item input[type="checkbox"] { flex: 0 0 auto; margin: 2px 0 0; }
            .wl-eix-item-text { min-width: 0; }
            .wl-eix-item-label { display: block; font-size: 13px; line-height: 1.35; color: #1d2327; overflow-wrap: anywhere; }
            .wl-eix-item-meta { display: block; font-size: 11px; line-height: 1.5; color: #787c82; }
            .wl-eix-empty, .wl-eix-nomatch { margin: 0; padding: 14px 12px; font-size: 13px; font-style: italic; color: #787c82; }
            .wl-eix-note { margin: 8px 2px 0; font-size: 12px; line-height: 1.5; color: #787c82; }
            .wl-eix-options { display: grid; gap: 14px; margin-top: 20px; padding-top: 18px; border-top: 1px solid #f0f0f1; }
            .wl-eix-options:first-child { margin-top: 0; padding-top: 0; border-top: 0; }
            .wl-eix-option { display: flex; align-items: flex-start; gap: 10px; cursor: pointer; }
            .wl-eix-option input[type="checkbox"] { flex: 0 0 auto; margin: 3px 0 0; }
            .wl-eix-option-text strong { display: block; font-size: 13px; color: #1d2327; }
            .wl-eix-option-text > span { display: block; max-width: 78ch; margin-top: 3px; font-size: 12px; line-height: 1.6; color: #646970; }
            .wl-eix-footer { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 12px; margin-top: 20px; padding-top: 16px; border-top: 1px solid #f0f0f1; }
            .wl-eix-summary { margin: 0; font-size: 13px; color: #50575e; }
            .wl-eix-field { max-width: 44em; margin-bottom: 18px; }
            .wl-eix-label { display: block; margin-bottom: 6px; font-size: 13px; font-weight: 600; color: #1d2327; }
            .wl-eix-field input[type="text"], .wl-eix-field input[type="url"] { width: 100%; }
            .wl-eix-stats { display: flex; flex-wrap: wrap; gap: 6px 26px; margin: 10px 0 6px; padding: 0; list-style: none; }
            .wl-eix-stats li { font-size: 12px; color: #50575e; }
            .wl-eix-stats strong { display: block; font-size: 19px; font-weight: 600; line-height: 1.3; color: #1d2327; }
            .wl-eix-fail { margin: 6px 0 10px 22px; list-style: disc; }
            @media screen and (max-width: 782px) {
                .wl-eix-card { padding: 16px; }
                .wl-eix-list { max-height: 260px; }
            }
        </style>
        <?php
    }

    /**
     * One panel of checkboxes — the three export lists are identical apart from
     * their contents.
     */
    private function render_choice_panel($panel) {
        $items = $panel['items'];
        ?>
        <fieldset class="wl-eix-group"
                  data-singular="<?php echo esc_attr($panel['singular']); ?>"
                  data-plural="<?php echo esc_attr($panel['plural']); ?>">
            <legend class="screen-reader-text"><?php echo esc_html($panel['title']); ?> to export</legend>

            <div class="wl-eix-panel">
                <div class="wl-eix-panel-head">
                    <span class="wl-eix-panel-title"><?php echo esc_html($panel['title']); ?></span>
                    <span class="wl-eix-badge" data-role="badge">0</span>
                </div>

                <?php if (!empty($items)) : ?>
                    <div class="wl-eix-panel-tools">
                        <input type="search" class="wl-eix-filter"
                               placeholder="Filter <?php echo esc_attr(strtolower($panel['title'])); ?>&hellip;"
                               aria-label="Filter <?php echo esc_attr(strtolower($panel['title'])); ?>">

                        <span class="wl-eix-actions">
                            <button type="button" class="button-link" data-action="all">All</button>
                            <span aria-hidden="true">·</span>
                            <button type="button" class="button-link" data-action="none">None</button>
                        </span>
                    </div>

                    <div class="wl-eix-list">
                        <?php foreach ($items as $item) : ?>
                            <label class="wl-eix-item" data-search="<?php echo esc_attr($item['search']); ?>">
                                <input type="checkbox"
                                       name="<?php echo esc_attr($panel['field']); ?>"
                                       value="<?php echo esc_attr($item['value']); ?>">

                                <span class="wl-eix-item-text">
                                    <span class="wl-eix-item-label"><?php echo esc_html($item['label']); ?></span>
                                    <span class="wl-eix-item-meta"><?php echo esc_html($item['meta']); ?></span>
                                </span>
                            </label>
                        <?php endforeach; ?>
                    </div>

                    <p class="wl-eix-nomatch" hidden>Nothing matches that filter.</p>
                <?php else : ?>
                    <p class="wl-eix-empty"><?php echo esc_html($panel['empty']); ?></p>
                <?php endif; ?>
            </div>

            <?php if (!empty($panel['note'])) : ?>
                <p class="wl-eix-note"><?php echo esc_html($panel['note']); ?></p>
            <?php endif; ?>
        </fieldset>
        <?php
    }

    private function page_choices() {
        $pages = get_pages([
            'post_status' => ['publish', 'draft', 'pending', 'private'],
            'sort_column' => 'post_title',
            'sort_order'  => 'ASC',
        ]);

        $choices = [];

        foreach ($pages as $page) {
            $title  = $page->post_title !== '' ? $page->post_title : '(no title)';
            $status = $page->post_status === 'publish' ? '' : ' · ' . $page->post_status;

            $choices[] = $this->build_choice($page->ID, $title, 'ID ' . $page->ID . $status);
        }

        return $choices;
    }

    private function post_type_choices() {
        $post_types = get_post_types(['public' => true], 'objects');
        $choices    = [];

        foreach ($post_types as $post_type) {
            if ($post_type->name === 'attachment') {
                continue;
            }

            $total = $this->post_type_total($post_type->name);

            $choices[] = $this->build_choice(
                $post_type->name,
                $post_type->label,
                $post_type->name . ' · ' . number_format_i18n($total) . ($total === 1 ? ' post' : ' posts')
            );
        }

        return $choices;
    }

    private function taxonomy_choices() {
        $choices = [];

        foreach ($this->exportable_taxonomies() as $taxonomy) {
            $total = $this->taxonomy_term_total($taxonomy->name);

            $choices[] = $this->build_choice(
                $taxonomy->name,
                $taxonomy->label,
                $taxonomy->name . ' · ' . number_format_i18n($total) . ($total === 1 ? ' term' : ' terms')
            );
        }

        return $choices;
    }

    private function build_choice($value, $label, $meta) {
        return [
            'value'  => $value,
            'label'  => $label,
            'meta'   => $meta,
            'search' => strtolower($label . ' ' . $meta),
        ];
    }

    private function post_type_total($post_type) {
        $counts = wp_count_posts($post_type);
        $total  = 0;

        foreach ((array) $counts as $status => $count) {
            if (in_array($status, ['auto-draft', 'trash', 'inherit'], true)) {
                continue;
            }

            $total += intval($count);
        }

        return $total;
    }

    private function taxonomy_term_total($taxonomy) {
        $count = get_terms([
            'taxonomy'   => $taxonomy,
            'hide_empty' => false,
            'fields'     => 'count',
        ]);

        return is_wp_error($count) ? 0 : intval($count);
    }

    private function render_import_report($report) {
        $failed       = !empty($report['media_failed']) ? $report['media_failed'] : [];
        $terms_failed = !empty($report['terms_failed']) ? $report['terms_failed'] : [];
        $class        = (empty($failed) && empty($terms_failed)) ? 'notice-success' : 'notice-warning';

        $terms_created = isset($report['terms_created']) ? intval($report['terms_created']) : 0;
        $terms_updated = isset($report['terms_updated']) ? intval($report['terms_updated']) : 0;

        $stats = [
            'Posts created'  => intval($report['posts_created']),
            'Terms created'  => $terms_created,
            'Terms matched'  => $terms_updated,
            'Media imported' => intval($report['media_imported']),
            'Media reused'   => intval($report['media_reused']),
            'Media failed'   => count($failed),
        ];
        ?>
        <div class="notice <?php echo esc_attr($class); ?>">
            <p><strong>Import completed.</strong></p>

            <ul class="wl-eix-stats">
                <?php foreach ($stats as $label => $value) : ?>
                    <li>
                        <strong><?php echo esc_html(number_format_i18n($value)); ?></strong>
                        <?php echo esc_html($label); ?>
                    </li>
                <?php endforeach; ?>
            </ul>

            <?php if (!empty($terms_failed)) : ?>
                <p>These taxonomy terms could not be imported:</p>
                <ul class="wl-eix-fail">
                    <?php foreach ($terms_failed as $failure) : ?>
                        <li>
                            <code><?php echo esc_html($failure['term']); ?></code> — <?php echo esc_html($failure['reason']); ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>

            <?php if (!empty($failed)) : ?>
                <p>These media files could not be imported:</p>
                <ul class="wl-eix-fail">
                    <?php foreach ($failed as $failure) : ?>
                        <li>
                            <code><?php echo esc_html($failure['url']); ?></code> — <?php echo esc_html($failure['reason']); ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>
        <?php

        delete_transient('wl_cpt_import_report_' . get_current_user_id());
    }

    private function exportable_taxonomies() {
        $taxonomies = get_taxonomies([], 'objects');
        $exportable = [];

        foreach ($taxonomies as $taxonomy) {
            if (in_array($taxonomy->name, $this->internal_taxonomies, true)) {
                continue;
            }

            $exportable[$taxonomy->name] = $taxonomy;
        }

        return $exportable;
    }

    /* ---------------------------------------------------------------------
     * Export
     * ------------------------------------------------------------------ */

    public function handle_export() {
        if (!current_user_can('manage_options')) {
            wp_die('Permission denied.');
        }

        check_admin_referer('wl_cpt_export_nonce');

        @set_time_limit(0);
        @ini_set('memory_limit', '1024M');

        $embed_media       = !empty($_POST['embed_media']);
        $include_all_terms = !empty($_POST['include_all_terms']);

        $page_ids   = $this->posted_id_list('page_id');
        $post_types = $this->posted_key_list('post_type');
        $taxonomies = $this->posted_key_list('taxonomy');

        if (empty($page_ids) && empty($post_types) && empty($taxonomies)) {
            wp_die('Select at least one page, post type, or taxonomy to export.');
        }

        $posts           = [];
        $seen_posts      = [];
        $bulk_taxonomies = [];

        foreach ($page_ids as $page_id) {
            $page = get_post($page_id);

            if (!$page || $page->post_type !== 'page' || isset($seen_posts[$page->ID])) {
                continue;
            }

            $seen_posts[$page->ID] = true;
            $posts[]               = $page;
        }

        foreach ($post_types as $post_type) {
            if (!post_type_exists($post_type)) {
                continue;
            }

            $found = get_posts([
                'post_type'      => $post_type,
                'post_status'    => 'any',
                'posts_per_page' => -1,
                'orderby'        => 'ID',
                'order'          => 'ASC',
            ]);

            foreach ($found as $found_post) {
                // A page can be picked individually and again through its post
                // type — it must only travel once.
                if (isset($seen_posts[$found_post->ID])) {
                    continue;
                }

                $seen_posts[$found_post->ID] = true;
                $posts[]                     = $found_post;
            }

            if ($include_all_terms) {
                $bulk_taxonomies = array_merge($bulk_taxonomies, get_object_taxonomies($post_type));
            }
        }

        foreach ($taxonomies as $taxonomy) {
            if (taxonomy_exists($taxonomy)) {
                $bulk_taxonomies[] = $taxonomy;
            }
        }

        $bulk_taxonomies = array_values(array_unique($bulk_taxonomies));

        $uploads       = $this->uploads_info();
        $exported_posts = [];

        foreach ($posts as $post) {
            $exported_posts[] = $this->build_post_export($post);
        }

        foreach ($bulk_taxonomies as $bulk_taxonomy) {
            $this->register_all_terms($bulk_taxonomy);
        }

        if (empty($exported_posts) && empty($this->term_manifest)) {
            wp_die('Nothing matched that selection — the chosen post types have no posts and the chosen taxonomies have no terms.');
        }

        $header = [
            'plugin_version'      => '2.2.0',
            'schema'              => self::SCHEMA_VERSION,
            'site_url'            => site_url(),
            'home_url'            => home_url(),
            'upload_baseurl'      => $uploads['baseurl'],
            'export_type'         => $this->export_type_summary($page_ids, $post_types, $taxonomies),
            'exported_pages'      => $page_ids,
            'exported_post_types' => $post_types,
            'exported_taxonomies' => $taxonomies,
            'embedded_media'      => $embed_media,
            'exported_at'         => current_time('mysql'),
        ];

        $export_label = $this->build_export_label($page_ids, $post_types, $taxonomies);

        $filename = 'wl-export-' . sanitize_title($export_label) . '-' . date('Y-m-d-H-i-s') . '.json';

        nocache_headers();
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');

        while (ob_get_level()) {
            ob_end_clean();
        }

        $this->stream_export($header, $exported_posts, $embed_media);
        exit;
    }

    private function posted_id_list($field) {
        if (empty($_POST[$field])) {
            return [];
        }

        $values = array_map('intval', (array) wp_unslash($_POST[$field]));

        return array_values(array_unique(array_filter($values)));
    }

    private function posted_key_list($field) {
        if (empty($_POST[$field])) {
            return [];
        }

        $values = array_map('sanitize_key', (array) wp_unslash($_POST[$field]));

        return array_values(array_unique(array_filter($values)));
    }

    private function export_type_summary($page_ids, $post_types, $taxonomies) {
        $kinds = 0;

        $kinds += empty($page_ids) ? 0 : 1;
        $kinds += empty($post_types) ? 0 : 1;
        $kinds += empty($taxonomies) ? 0 : 1;

        if ($kinds > 1) {
            return 'mixed';
        }

        if (!empty($page_ids)) {
            return 'page';
        }

        return empty($post_types) ? 'taxonomy' : 'post_type';
    }

    /**
     * Turns the selection into something readable in a filename without letting
     * a twenty-post-type export produce a filename nobody can use.
     */
    private function build_export_label($page_ids, $post_types, $taxonomies) {
        $parts = [];

        if (count($page_ids) === 1) {
            $parts[] = 'page-' . reset($page_ids);
        } elseif (!empty($page_ids)) {
            $parts[] = 'pages-' . count($page_ids);
        }

        foreach ($post_types as $post_type) {
            $parts[] = $post_type;
        }

        foreach ($taxonomies as $taxonomy) {
            $parts[] = 'taxonomy-' . $taxonomy;
        }

        if (empty($parts)) {
            return 'export';
        }

        if (count($parts) > 3) {
            return 'mixed-' . count($parts);
        }

        return implode('-', $parts);
    }

    private function stream_export($header, $exported_posts, $embed_media) {
        $terms = $this->exportable_terms();

        echo '{';

        foreach ($header as $key => $value) {
            echo wp_json_encode((string) $key) . ':' . wp_json_encode($value) . ',';
        }

        echo '"posts":' . wp_json_encode($exported_posts, JSON_UNESCAPED_SLASHES) . ',';

        // Keyed by "taxonomy:term_id", so this always encodes as a JSON object —
        // except when there is nothing to export.
        echo '"terms":' . (empty($terms) ? '{}' : wp_json_encode($terms, JSON_UNESCAPED_SLASHES)) . ',';

        echo '"media":{';

        $first = true;

        foreach ($this->media_manifest as $key => $entry) {
            if (!$first) {
                echo ',';
            }

            $first = false;

            $path      = isset($this->media_paths[$key]) ? $this->media_paths[$key] : '';
            $can_embed = $embed_media && $path && is_readable($path);

            $encoded = wp_json_encode($entry, JSON_UNESCAPED_SLASHES);
            echo wp_json_encode((string) $key) . ':' . substr($encoded, 0, -1);

            if ($can_embed) {
                echo ',"data":"';

                $handle = fopen($path, 'rb');

                if ($handle) {
                    while (!feof($handle)) {
                        echo base64_encode(fread($handle, 3 * 1024 * 1024));
                    }

                    fclose($handle);
                }

                echo '"';
            }

            echo '}';
            flush();
        }

        echo '}}';
    }

    private function build_post_export($post) {
        $post_id     = $post->ID;
        $featured_id = get_post_thumbnail_id($post_id);

        $this->collect_media_from_content($post->post_content);

        $meta = $this->build_meta_export(get_post_meta($post_id));

        return [
            'old_id'          => $post_id,
            'post_type'       => $post->post_type,
            'post_title'      => $post->post_title,
            'post_name'       => $post->post_name,
            'post_content'    => $post->post_content,
            'post_excerpt'    => $post->post_excerpt,
            'post_status'     => $post->post_status,
            'post_date'       => $post->post_date,
            'menu_order'      => $post->menu_order,
            'post_parent'     => $post->post_parent,
            'featured_media'  => $featured_id ? $this->register_attachment($featured_id) : '',
            'taxonomies'      => $this->export_taxonomies($post_id, $post->post_type),
            'meta'            => $meta['meta'],
            'media_meta_keys' => $meta['media_meta_keys'],
            'term_meta_keys'  => $meta['term_meta_keys'],
        ];
    }

    /**
     * Walks a raw meta array (post meta or term meta), registers every media
     * file it references, and reports which keys hold attachment or term IDs so
     * the importer knows which numbers to renumber.
     */
    private function build_meta_export($all_meta) {
        $export_meta     = [];
        $media_meta_keys = [];
        $term_meta_keys  = [];

        if (!is_array($all_meta)) {
            $all_meta = [];
        }

        foreach ($all_meta as $meta_key => $meta_values) {
            if (in_array($meta_key, $this->skip_meta_keys, true)) {
                continue;
            }

            $holds_media_ids = $this->meta_key_holds_media_ids($meta_key, $all_meta);
            $holds_term_ids  = !$holds_media_ids && $this->meta_key_holds_term_ids($meta_key);

            if ($holds_media_ids) {
                $media_meta_keys[] = $meta_key;
            }

            if ($holds_term_ids) {
                $term_meta_keys[] = $meta_key;
            }

            $export_meta[$meta_key] = [];

            foreach ((array) $meta_values as $value) {
                $value = maybe_unserialize($value);

                $this->collect_media_from_value($value, $holds_media_ids);

                $export_meta[$meta_key][] = $value;
            }
        }

        return [
            'meta'            => $export_meta,
            'media_meta_keys' => $media_meta_keys,
            'term_meta_keys'  => $term_meta_keys,
        ];
    }

    private function collect_media_from_content($content) {
        if (!is_string($content) || $content === '') {
            return;
        }

        foreach ($this->find_media_urls($content) as $url) {
            $this->register_media_url($url);
        }

        if (preg_match_all('#wp-image-(\d+)#', $content, $matches)) {
            foreach ($matches[1] as $attachment_id) {
                $this->register_attachment($attachment_id);
            }
        }

        foreach ($this->find_block_attribute_ids($content) as $attachment_id) {
            $this->register_attachment($attachment_id);
        }
    }

    private function collect_media_from_value($value, $numeric_is_media) {
        if (is_object($value)) {
            $value = get_object_vars($value);
        }

        if (is_array($value)) {
            foreach ($value as $item) {
                $this->collect_media_from_value($item, $numeric_is_media);
            }

            return;
        }

        if (is_string($value)) {
            foreach ($this->find_media_urls($value) as $url) {
                $this->register_media_url($url);
            }
        }

        if ($numeric_is_media) {
            foreach ($this->extract_numeric_ids($value) as $attachment_id) {
                $this->register_attachment($attachment_id);
            }
        }
    }

    /**
     * A meta key is treated as holding attachment IDs when it is an ACF field
     * (ACF stores a companion "_key" meta pointing at field_xxxxx) or when it is
     * a known media key. This works even when ACF is not active on the source.
     */
    private function meta_key_holds_media_ids($meta_key, $all_meta) {
        if (in_array($meta_key, $this->media_id_meta_keys, true)) {
            return true;
        }

        $reference_key = '_' . $meta_key;

        if (strpos($meta_key, '_') !== 0 && !empty($all_meta[$reference_key][0])) {
            $reference = maybe_unserialize($all_meta[$reference_key][0]);

            if (is_string($reference) && strpos($reference, 'field_') === 0) {
                return true;
            }
        }

        return (bool) apply_filters('wl_cpt_meta_key_holds_media_ids', false, $meta_key);
    }

    /**
     * Term IDs stored in meta (an ACF taxonomy field, for one) cannot be told
     * apart from any other number, so nothing is renumbered unless a site opts
     * a key in through this filter.
     */
    private function meta_key_holds_term_ids($meta_key) {
        return (bool) apply_filters('wl_cpt_meta_key_holds_term_ids', false, $meta_key);
    }

    private function extract_numeric_ids($value) {
        if (is_int($value)) {
            return [$value];
        }

        if (!is_string($value) || $value === '') {
            return [];
        }

        if (!preg_match('#^\s*\d+(\s*,\s*\d+)*\s*$#', $value)) {
            return [];
        }

        return array_map('intval', array_map('trim', explode(',', $value)));
    }

    private function register_attachment($attachment_id) {
        $attachment_id = intval($attachment_id);

        if ($attachment_id <= 0) {
            return '';
        }

        $key = 'id:' . $attachment_id;

        if (isset($this->media_manifest[$key])) {
            return $key;
        }

        if (get_post_type($attachment_id) !== 'attachment') {
            return '';
        }

        $url = wp_get_attachment_url($attachment_id);

        if (!$url) {
            return '';
        }

        $attachment = get_post($attachment_id);
        $metadata   = wp_get_attachment_metadata($attachment_id);
        $file       = get_attached_file($attachment_id);
        $sizes      = [];

        if (!empty($metadata['sizes']) && is_array($metadata['sizes'])) {
            foreach ($metadata['sizes'] as $size_name => $size) {
                if (!empty($size['file'])) {
                    $sizes[$size_name] = $size['file'];
                }
            }
        }

        $this->media_manifest[$key] = [
            'id'          => $attachment_id,
            'url'         => $url,
            'filename'    => wp_basename(parse_url($url, PHP_URL_PATH)),
            'mime_type'   => get_post_mime_type($attachment_id),
            'title'       => $attachment ? $attachment->post_title : '',
            'alt'         => (string) get_post_meta($attachment_id, '_wp_attachment_image_alt', true),
            'caption'     => $attachment ? $attachment->post_excerpt : '',
            'description' => $attachment ? $attachment->post_content : '',
            'date'        => $attachment ? $attachment->post_date : '',
            'sizes'       => $sizes,
            'original'    => !empty($metadata['original_image']) ? $metadata['original_image'] : '',
        ];

        $this->media_paths[$key] = ($file && file_exists($file)) ? $file : '';

        return $key;
    }

    /**
     * Files referenced by URL that have no attachment row (uploaded outside the
     * media library, or attached to a deleted post) still need to travel.
     */
    private function register_media_url($url) {
        $attachment_id = $this->resolve_attachment_id($url);

        if ($attachment_id) {
            return $this->register_attachment($attachment_id);
        }

        $normalized = $this->normalize_media_url($url);

        if ($normalized === '') {
            return '';
        }

        $uploads = $this->uploads_info();

        if (strpos($normalized, $uploads['baseurl'] . '/') !== 0) {
            return '';
        }

        $key = 'url:' . md5($normalized);

        if (isset($this->media_manifest[$key])) {
            return $key;
        }

        $path = $uploads['basedir'] . substr($normalized, strlen($uploads['baseurl']));

        if (!file_exists($path)) {
            return '';
        }

        $filetype = wp_check_filetype(wp_basename($path));

        $this->media_manifest[$key] = [
            'id'          => 0,
            'url'         => $normalized,
            'filename'    => wp_basename($path),
            'mime_type'   => !empty($filetype['type']) ? $filetype['type'] : '',
            'title'       => '',
            'alt'         => '',
            'caption'     => '',
            'description' => '',
            'date'        => '',
            'sizes'       => [],
            'original'    => '',
        ];

        $this->media_paths[$key] = $path;

        return $key;
    }

    /* ---------------------------------------------------------------------
     * Import
     * ------------------------------------------------------------------ */

    public function handle_import() {
        if (!current_user_can('manage_options')) {
            wp_die('Permission denied.');
        }

        if (empty($_POST) && !empty($_SERVER['CONTENT_LENGTH'])) {
            wp_die(
                'The uploaded file was larger than this server allows (post_max_size is '
                . esc_html(ini_get('post_max_size'))
                . '). Upload the JSON into the uploads folder with FTP and use the "Or Server Path" field instead.'
            );
        }

        check_admin_referer('wl_cpt_import_nonce');

        @set_time_limit(0);
        @ini_set('memory_limit', '2048M');

        $json = $this->read_import_file();
        $data = json_decode($json, true);

        unset($json);

        $has_posts = is_array($data) && !empty($data['posts']) && is_array($data['posts']);
        $has_terms = is_array($data) && !empty($data['terms']) && is_array($data['terms']);

        if (!$has_posts && !$has_terms) {
            wp_die('Invalid JSON file.');
        }

        if (empty($data['schema']) || intval($data['schema']) < self::MIN_SCHEMA_VERSION) {
            wp_die('This JSON was produced by an older version of the plugin. Please re-export it from the source site using version 2.2.0 so the media files and taxonomy terms are included.');
        }

        $this->report = [
            'posts_created'  => 0,
            'terms_created'  => 0,
            'terms_updated'  => 0,
            'terms_failed'   => [],
            'media_imported' => 0,
            'media_reused'   => 0,
            'media_failed'   => [],
        ];

        $this->update_terms = !empty($_POST['update_terms']);

        $this->source_base = !empty($_POST['source_url'])
            ? untrailingslashit(esc_url_raw(wp_unslash($_POST['source_url'])))
            : '';

        $source_site = !empty($data['site_url']) ? esc_url_raw($data['site_url']) : '';

        if (!empty($data['media']) && is_array($data['media'])) {
            foreach ($data['media'] as $key => $entry) {
                if (is_array($entry)) {
                    $this->import_media_entry($key, $entry, $source_site);
                }

                unset($data['media'][$key]);
            }
        }

        $this->build_url_replacements();

        // Terms come before posts so the media they point at is already
        // renumbered and the posts can be attached to the finished terms.
        if ($has_terms) {
            $this->import_terms($data['terms']);

            unset($data['terms']);
        }

        if (!$has_posts) {
            set_transient('wl_cpt_import_report_' . get_current_user_id(), $this->report, 10 * MINUTE_IN_SECONDS);

            wp_safe_redirect(admin_url('tools.php?page=wl-cpt-export-import&imported=1'));
            exit;
        }

        $post_id_map = [];
        $parent_map  = [];

        foreach ($data['posts'] as $item) {
            $new_post_id = $this->import_post($item);

            if (!$new_post_id) {
                continue;
            }

            $this->report['posts_created']++;

            if (!empty($item['old_id'])) {
                $post_id_map[intval($item['old_id'])] = $new_post_id;
            }

            if (!empty($item['post_parent'])) {
                $parent_map[$new_post_id] = intval($item['post_parent']);
            }
        }

        foreach ($parent_map as $new_post_id => $old_parent) {
            if (isset($post_id_map[$old_parent])) {
                wp_update_post([
                    'ID'          => $new_post_id,
                    'post_parent' => $post_id_map[$old_parent],
                ]);
            }
        }

        set_transient('wl_cpt_import_report_' . get_current_user_id(), $this->report, 10 * MINUTE_IN_SECONDS);

        wp_safe_redirect(admin_url('tools.php?page=wl-cpt-export-import&imported=1'));
        exit;
    }

    private function read_import_file() {
        if (!empty($_POST['server_path'])) {
            $uploads   = $this->uploads_info();
            $requested = wp_unslash($_POST['server_path']);
            $candidate = path_is_absolute($requested) ? $requested : $uploads['basedir'] . '/' . ltrim($requested, '/\\');
            $resolved  = realpath($candidate);
            $base      = realpath($uploads['basedir']);

            if (!$resolved || !$base || strpos($resolved, $base) !== 0) {
                wp_die('The server path must point at a file inside the uploads folder.');
            }

            $contents = file_get_contents($resolved);

            if ($contents === false) {
                wp_die('Could not read ' . esc_html($resolved) . '.');
            }

            return $contents;
        }

        if (empty($_FILES['import_file']['tmp_name']) || !is_uploaded_file($_FILES['import_file']['tmp_name'])) {
            wp_die('No file uploaded.');
        }

        $contents = file_get_contents($_FILES['import_file']['tmp_name']);

        if ($contents === false) {
            wp_die('Could not read the uploaded file.');
        }

        return $contents;
    }

    private function import_post($item) {
        if (empty($item['post_type']) || !post_type_exists($item['post_type'])) {
            return 0;
        }

        $content = !empty($item['post_content']) ? $this->remap_content($item['post_content']) : '';
        $excerpt = !empty($item['post_excerpt']) ? $this->remap_content($item['post_excerpt']) : '';

        $new_post_id = wp_insert_post(wp_slash([
            'post_type'    => sanitize_key($item['post_type']),
            'post_title'   => !empty($item['post_title']) ? $item['post_title'] : '',
            'post_name'    => !empty($item['post_name']) ? sanitize_title($item['post_name']) : '',
            'post_content' => $content,
            'post_excerpt' => $excerpt,
            'post_status'  => !empty($item['post_status']) ? sanitize_key($item['post_status']) : 'publish',
            'post_date'    => !empty($item['post_date']) ? sanitize_text_field($item['post_date']) : current_time('mysql'),
            'menu_order'   => !empty($item['menu_order']) ? intval($item['menu_order']) : 0,
            'post_parent'  => 0,
        ]), true);

        if (is_wp_error($new_post_id)) {
            return 0;
        }

        if (!empty($item['featured_media']) && isset($this->media_key_map[$item['featured_media']])) {
            set_post_thumbnail($new_post_id, $this->media_key_map[$item['featured_media']]);
        }

        if (!empty($item['taxonomies']) && is_array($item['taxonomies'])) {
            $this->import_taxonomies($new_post_id, $item['taxonomies']);
        }

        if (!empty($item['meta']) && is_array($item['meta'])) {
            $media_meta_keys = !empty($item['media_meta_keys']) && is_array($item['media_meta_keys'])
                ? $item['media_meta_keys']
                : [];

            $term_meta_keys = !empty($item['term_meta_keys']) && is_array($item['term_meta_keys'])
                ? $item['term_meta_keys']
                : [];

            $this->import_meta('post', $new_post_id, $item['meta'], $media_meta_keys, $term_meta_keys);
        }

        return $new_post_id;
    }

    /**
     * Shared by posts and terms — $meta_type is 'post' or 'term'.
     */
    private function import_meta($meta_type, $object_id, $meta, $media_meta_keys, $term_meta_keys = []) {
        foreach ($meta as $meta_key => $values) {
            if (in_array($meta_key, $this->skip_meta_keys, true)) {
                continue;
            }

            $numeric_map = null;

            if (in_array($meta_key, $media_meta_keys, true)) {
                $numeric_map = $this->media_id_map;
            } elseif (in_array($meta_key, $term_meta_keys, true)) {
                $numeric_map = $this->term_id_map;
            }

            delete_metadata($meta_type, $object_id, $meta_key);

            foreach ((array) $values as $value) {
                $value = $this->remap_meta_value($value, $numeric_map);

                add_metadata($meta_type, $object_id, $meta_key, wp_slash($value));
            }
        }
    }

    private function import_media_entry($key, $entry, $source_site) {
        $old_url = !empty($entry['url']) ? $entry['url'] : '';
        $old_id  = !empty($entry['id']) ? intval($entry['id']) : 0;

        if ($old_url === '') {
            return;
        }

        $existing = $this->find_existing_attachment($old_url, $old_id, $source_site);

        if ($existing) {
            $this->media_key_map[$key] = $existing;
            $this->map_media($entry, $existing);
            $this->report['media_reused']++;

            return;
        }

        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';

        $filename = !empty($entry['filename'])
            ? sanitize_file_name($entry['filename'])
            : wp_basename(parse_url($old_url, PHP_URL_PATH));

        if (!empty($entry['data'])) {
            $binary = base64_decode($entry['data'], true);

            if ($binary === false) {
                $this->fail_media($old_url, 'the embedded file data was corrupt');

                return;
            }

            $tmp = wp_tempnam($filename);

            if (!$tmp || file_put_contents($tmp, $binary) === false) {
                $this->fail_media($old_url, 'could not write a temporary file');

                return;
            }

            unset($binary);
        } else {
            $tmp = $this->download_media($old_url);

            if (!$tmp) {
                $this->fail_media(
                    $old_url,
                    'the file could not be downloaded — re-export with "Embed the media files inside the JSON" enabled'
                );

                return;
            }
        }

        $post_data = [
            'post_title'   => !empty($entry['title']) ? $entry['title'] : preg_replace('#\.[^.]+$#', '', $filename),
            'post_excerpt' => !empty($entry['caption']) ? $entry['caption'] : '',
            'post_content' => !empty($entry['description']) ? $entry['description'] : '',
        ];

        if (!empty($entry['date'])) {
            $post_data['post_date'] = $entry['date'];
        }

        $new_id = media_handle_sideload([
            'name'     => $filename,
            'tmp_name' => $tmp,
        ], 0, null, wp_slash($post_data));

        if (is_wp_error($new_id)) {
            @unlink($tmp);
            $this->fail_media($old_url, $new_id->get_error_message());

            return;
        }

        if (!empty($entry['alt'])) {
            update_post_meta($new_id, '_wp_attachment_image_alt', wp_slash($entry['alt']));
        }

        update_post_meta($new_id, self::SOURCE_URL_META, esc_url_raw($old_url));

        if ($old_id) {
            update_post_meta($new_id, self::SOURCE_ID_META, $old_id);
        }

        if ($source_site) {
            update_post_meta($new_id, self::SOURCE_SITE_META, $source_site);
        }

        $this->media_key_map[$key] = $new_id;
        $this->map_media($entry, $new_id);
        $this->report['media_imported']++;
    }

    private function fail_media($url, $reason) {
        $this->report['media_failed'][] = [
            'url'    => $url,
            'reason' => $reason,
        ];
    }

    private function find_existing_attachment($old_url, $old_id, $source_site) {
        $existing = get_posts([
            'post_type'              => 'attachment',
            'post_status'            => 'inherit',
            'posts_per_page'         => 1,
            'fields'                 => 'ids',
            'no_found_rows'          => true,
            'update_post_term_cache' => false,
            'meta_key'               => self::SOURCE_URL_META,
            'meta_value'             => esc_url_raw($old_url),
        ]);

        if (!empty($existing)) {
            return intval($existing[0]);
        }

        if ($old_id && $source_site) {
            $existing = get_posts([
                'post_type'              => 'attachment',
                'post_status'            => 'inherit',
                'posts_per_page'         => 1,
                'fields'                 => 'ids',
                'no_found_rows'          => true,
                'update_post_term_cache' => false,
                'meta_query'             => [
                    [
                        'key'   => self::SOURCE_ID_META,
                        'value' => $old_id,
                    ],
                    [
                        'key'   => self::SOURCE_SITE_META,
                        'value' => $source_site,
                    ],
                ],
            ]);

            if (!empty($existing)) {
                return intval($existing[0]);
            }
        }

        return 0;
    }

    private function download_media($url) {
        $target = $url;

        if ($this->source_base) {
            $path = parse_url($url, PHP_URL_PATH);

            if ($path) {
                $target = $this->source_base . $path;
            }
        }

        $tmp = download_url($target, 120);

        if (!is_wp_error($tmp)) {
            return $tmp;
        }

        $response = wp_remote_get($target, [
            'timeout'   => 120,
            'sslverify' => false,
        ]);

        if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
            return '';
        }

        $body = wp_remote_retrieve_body($response);

        if ($body === '') {
            return '';
        }

        $tmp = wp_tempnam(wp_basename(parse_url($target, PHP_URL_PATH)));

        if (!$tmp || file_put_contents($tmp, $body) === false) {
            return '';
        }

        return $tmp;
    }

    /**
     * Records every way the old site could have referenced this file so the
     * content and meta rewriter can find it: the full size, every registered
     * size variant, and the pre-scaled original.
     */
    private function map_media($entry, $new_id) {
        $new_url = wp_get_attachment_url($new_id);

        if (!$new_url) {
            return;
        }

        $old_url = $entry['url'];

        if (!empty($entry['id'])) {
            $this->media_id_map[intval($entry['id'])] = $new_id;
        }

        $this->media_url_map[$old_url] = $new_url;

        $old_dir = trailingslashit(dirname($old_url));

        if (!empty($entry['sizes']) && is_array($entry['sizes'])) {
            foreach ($entry['sizes'] as $size_name => $size_file) {
                if (!is_string($size_file) || $size_file === '') {
                    continue;
                }

                $new_size_url = wp_get_attachment_image_url($new_id, $size_name);

                $this->media_url_map[$old_dir . $size_file] = $new_size_url ? $new_size_url : $new_url;
            }
        }

        if (!empty($entry['original'])) {
            $this->media_url_map[$old_dir . $entry['original']] = $new_url;
        }
    }

    private function build_url_replacements() {
        $pairs = [];

        foreach ($this->media_url_map as $old_url => $new_url) {
            if (!$old_url || !$new_url || $old_url === $new_url) {
                continue;
            }

            $pairs[preg_replace('#^https?:#i', 'https:', $old_url)] = $new_url;
            $pairs[preg_replace('#^https?:#i', 'http:', $old_url)]  = $new_url;

            $old_schemeless = preg_replace('#^[a-z][a-z0-9+.\-]*:#i', '', $old_url);
            $new_schemeless = preg_replace('#^[a-z][a-z0-9+.\-]*:#i', '', $new_url);

            if ($old_schemeless !== $old_url) {
                $pairs[$old_schemeless] = $new_schemeless;
            }

            $old_path = parse_url($old_url, PHP_URL_PATH);
            $new_path = parse_url($new_url, PHP_URL_PATH);

            if ($old_path && $new_path && $old_path !== $new_path) {
                $pairs[$old_path] = $new_path;
            }
        }

        uksort($pairs, function ($a, $b) {
            return strlen($b) - strlen($a);
        });

        $this->url_search  = [];
        $this->url_replace = [];

        foreach ($pairs as $old_url => $new_url) {
            $this->url_search[]  = str_replace('/', '\\/', $old_url);
            $this->url_replace[] = str_replace('/', '\\/', $new_url);
        }

        foreach ($pairs as $old_url => $new_url) {
            $this->url_search[]  = $old_url;
            $this->url_replace[] = $new_url;
        }
    }

    private function replace_media_urls($string) {
        if (!is_string($string) || $string === '' || empty($this->url_search)) {
            return $string;
        }

        return str_replace($this->url_search, $this->url_replace, $string);
    }

    private function remap_content($content) {
        $content = $this->replace_media_urls($content);
        $content = $this->remap_image_classes($content);
        $content = $this->remap_block_attributes($content);

        return $content;
    }

    private function remap_image_classes($content) {
        if (strpos($content, 'wp-image-') === false) {
            return $content;
        }

        return preg_replace_callback('#wp-image-(\d+)#', function ($matches) {
            $old_id = intval($matches[1]);

            return isset($this->media_id_map[$old_id])
                ? 'wp-image-' . $this->media_id_map[$old_id]
                : $matches[0];
        }, $content);
    }

    private function remap_block_attributes($content) {
        if (strpos($content, '<!-- wp:') === false) {
            return $content;
        }

        return preg_replace_callback(
            '#(<!--\s+wp:[a-z][a-z0-9/-]*\s+)(\{.*?\})(\s+/?-->)#s',
            function ($matches) {
                $attributes = json_decode($matches[2], true);

                if (!is_array($attributes)) {
                    return $matches[0];
                }

                $changed    = false;
                $attributes = $this->remap_attribute_ids($attributes, $changed);

                if (!$changed) {
                    return $matches[0];
                }

                if (function_exists('serialize_block_attributes')) {
                    $encoded = serialize_block_attributes($attributes);
                } else {
                    $encoded = wp_json_encode($attributes, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                }

                if (!$encoded) {
                    return $matches[0];
                }

                return $matches[1] . $encoded . $matches[3];
            },
            $content
        );
    }

    private function remap_attribute_ids($attributes, &$changed) {
        $id_keys   = ['id', 'mediaId', 'imageId', 'thumbnailId', 'posterId', 'backgroundId'];
        $list_keys = ['ids', 'imageIds', 'galleryIds'];

        foreach ($attributes as $key => $value) {
            if (in_array($key, $list_keys, true) && is_array($value)) {
                foreach ($value as $index => $item) {
                    if (is_numeric($item) && isset($this->media_id_map[intval($item)])) {
                        $attributes[$key][$index] = $this->media_id_map[intval($item)];
                        $changed                  = true;
                    }
                }

                continue;
            }

            if (in_array($key, $id_keys, true) && is_numeric($value) && isset($this->media_id_map[intval($value)])) {
                $attributes[$key] = $this->media_id_map[intval($value)];
                $changed          = true;

                continue;
            }

            if (is_array($value)) {
                $attributes[$key] = $this->remap_attribute_ids($value, $changed);
            }
        }

        return $attributes;
    }

    /**
     * $numeric_map is the ID map bare numbers in this meta key should be looked
     * up in — media IDs, term IDs, or null when numbers must be left alone.
     */
    private function remap_meta_value($value, $numeric_map = null) {
        if (is_array($value)) {
            $remapped = [];

            foreach ($value as $key => $item) {
                $remapped[$key] = $this->remap_meta_value($item, $numeric_map);
            }

            return $this->remap_media_id_structure($remapped);
        }

        if (is_string($value)) {
            $decoded = $this->maybe_decode_json($value);

            if ($decoded !== null) {
                $remapped = $this->remap_meta_value($decoded, null);
                $flags    = JSON_UNESCAPED_UNICODE;

                if (strpos($value, '\\/') === false) {
                    $flags |= JSON_UNESCAPED_SLASHES;
                }

                $encoded = wp_json_encode($remapped, $flags);

                if ($encoded !== false) {
                    return $encoded;
                }
            }

            $value = $this->replace_media_urls($value);

            if (!empty($numeric_map)) {
                $value = $this->remap_numeric_id_list($value, $numeric_map);
            }

            return $value;
        }

        if (!empty($numeric_map) && is_numeric($value) && isset($numeric_map[intval($value)])) {
            return $numeric_map[intval($value)];
        }

        return $value;
    }

    /**
     * Page builders store media as an object carrying both the URL and the
     * attachment ID (Elementor, for one). When the object clearly describes a
     * file, its ID is safe to remap.
     */
    private function remap_media_id_structure($value) {
        if (!is_array($value)) {
            return $value;
        }

        $keys = ['image_id', 'imageId', 'mediaId', 'attachment_id', 'thumbnail_id'];

        if (isset($value['url']) || isset($value['source_url']) || isset($value['src'])) {
            $keys[] = 'id';
        }

        foreach ($keys as $key) {
            if (isset($value[$key]) && is_numeric($value[$key]) && isset($this->media_id_map[intval($value[$key])])) {
                $value[$key] = $this->media_id_map[intval($value[$key])];
            }
        }

        return $value;
    }

    private function remap_numeric_id_list($value, $numeric_map) {
        if (!preg_match('#^\s*\d+(\s*,\s*\d+)*\s*$#', $value)) {
            return $value;
        }

        $ids = array_map('trim', explode(',', $value));

        foreach ($ids as $index => $id) {
            if (isset($numeric_map[intval($id)])) {
                $ids[$index] = $numeric_map[intval($id)];
            }
        }

        return implode(',', $ids);
    }

    private function maybe_decode_json($value) {
        $trimmed = ltrim($value);

        if ($trimmed === '' || ($trimmed[0] !== '{' && $trimmed[0] !== '[')) {
            return null;
        }

        $decoded = json_decode($value, true);

        if (json_last_error() !== JSON_ERROR_NONE || !is_array($decoded)) {
            return null;
        }

        return $decoded;
    }

    /* ---------------------------------------------------------------------
     * URL helpers
     * ------------------------------------------------------------------ */

    private function uploads_info() {
        if ($this->uploads_cache === null) {
            $uploads   = wp_get_upload_dir();
            $path_part = parse_url($uploads['baseurl'], PHP_URL_PATH);

            $this->uploads_cache = [
                'basedir'   => untrailingslashit($uploads['basedir']),
                'baseurl'   => untrailingslashit($uploads['baseurl']),
                'path_part' => $path_part ? untrailingslashit($path_part) : '',
            ];
        }

        return $this->uploads_cache;
    }

    /**
     * Finds every uploads URL in a string, including the escaped form
     * (\/wp-content\/uploads\/...) that page builders store inside JSON meta.
     */
    private function find_media_urls($string) {
        if (!is_string($string) || $string === '') {
            return [];
        }

        $uploads = $this->uploads_info();

        if (!$uploads['path_part']) {
            return [];
        }

        $haystack = str_replace('\\/', '/', $string);
        $haystack = str_replace(['&#038;', '&amp;'], '&', $haystack);

        if (strpos($haystack, $uploads['path_part'] . '/') === false) {
            return [];
        }

        $quoted = preg_quote($uploads['path_part'], '#');
        $urls   = [];

        $absolute = '#(?:[a-z][a-z0-9+.\-]*:)?//[^\s"\'<>()\\\\/]*' . $quoted . '/[^\s"\'<>()\\\\]*?\.[a-zA-Z0-9]{2,6}#i';

        if (preg_match_all($absolute, $haystack, $matches)) {
            $urls = array_merge($urls, $matches[0]);
        }

        $relative = '#(?<![\w:/.\-])' . $quoted . '/[^\s"\'<>()\\\\]*?\.[a-zA-Z0-9]{2,6}#i';

        if (preg_match_all($relative, $haystack, $matches)) {
            $urls = array_merge($urls, $matches[0]);
        }

        return array_values(array_unique($urls));
    }

    private function find_block_attribute_ids($content) {
        $ids = [];

        if (strpos($content, '<!-- wp:') === false) {
            return $ids;
        }

        if (!preg_match_all('#<!--\s+wp:[a-z][a-z0-9/-]*\s+(\{.*?\})\s+/?-->#s', $content, $matches)) {
            return $ids;
        }

        foreach ($matches[1] as $json) {
            $attributes = json_decode($json, true);

            if (is_array($attributes)) {
                $this->collect_attribute_ids($attributes, $ids);
            }
        }

        return array_values(array_unique($ids));
    }

    private function collect_attribute_ids($attributes, &$ids) {
        $id_keys   = ['id', 'mediaId', 'imageId', 'thumbnailId', 'posterId', 'backgroundId'];
        $list_keys = ['ids', 'imageIds', 'galleryIds'];

        foreach ($attributes as $key => $value) {
            if (in_array($key, $list_keys, true) && is_array($value)) {
                foreach ($value as $item) {
                    if (is_numeric($item)) {
                        $ids[] = intval($item);
                    }
                }

                continue;
            }

            if (in_array($key, $id_keys, true) && is_numeric($value)) {
                $ids[] = intval($value);

                continue;
            }

            if (is_array($value)) {
                $this->collect_attribute_ids($value, $ids);
            }
        }
    }

    /**
     * Rebuilds a URL against this site's uploads base so that references saved
     * with a different host or port (localhost:10004, an old domain, a
     * protocol-relative URL) still resolve.
     */
    private function normalize_media_url($url) {
        if (!is_string($url) || $url === '') {
            return '';
        }

        $url = trim(str_replace('\\/', '/', $url));
        $url = html_entity_decode($url, ENT_QUOTES, 'UTF-8');
        $url = explode('#', $url, 2)[0];
        $url = explode('?', $url, 2)[0];

        if ($url === '') {
            return '';
        }

        $uploads = $this->uploads_info();
        $path    = parse_url($url, PHP_URL_PATH);

        if (!$path) {
            $path = $url;
        }

        if ($uploads['path_part']) {
            $position = strpos($path, $uploads['path_part'] . '/');

            if ($position !== false) {
                return $uploads['baseurl'] . substr($path, $position + strlen($uploads['path_part']));
            }
        }

        if (strpos($url, '//') === 0) {
            return (is_ssl() ? 'https:' : 'http:') . $url;
        }

        if (strpos($url, '/') === 0) {
            return site_url($url);
        }

        return $url;
    }

    /**
     * attachment_url_to_postid() only knows the full-size file, so sized and
     * scaled variants are peeled back to the original before looking up.
     */
    private function resolve_attachment_id($url) {
        $normalized = $this->normalize_media_url($url);

        if ($normalized === '') {
            return 0;
        }

        if (isset($this->attachment_id_cache[$normalized])) {
            return $this->attachment_id_cache[$normalized];
        }

        $candidates = [$normalized];
        $unsized    = preg_replace('#-\d+x\d+(?=\.[A-Za-z0-9]+$)#', '', $normalized);

        if ($unsized !== $normalized) {
            $candidates[] = $unsized;
        }

        foreach (array_values($candidates) as $candidate) {
            $unscaled = preg_replace('#-scaled(?=\.[A-Za-z0-9]+$)#', '', $candidate);

            if ($unscaled !== $candidate) {
                $candidates[] = $unscaled;
            }
        }

        $found = 0;

        foreach ($candidates as $candidate) {
            $found = attachment_url_to_postid($candidate);

            if ($found) {
                break;
            }
        }

        $this->attachment_id_cache[$normalized] = $found;

        return $found;
    }

    /* ---------------------------------------------------------------------
     * Taxonomies
     * ------------------------------------------------------------------ */

    private function export_taxonomies($post_id, $post_type) {
        $taxonomies = get_object_taxonomies($post_type);
        $data       = [];

        foreach ($taxonomies as $taxonomy) {
            if (in_array($taxonomy, $this->internal_taxonomies, true)) {
                continue;
            }

            $terms = wp_get_object_terms($post_id, $taxonomy);

            if (empty($terms) || is_wp_error($terms)) {
                continue;
            }

            foreach ($terms as $term) {
                $data[$taxonomy][] = [
                    'name' => $term->name,
                    'slug' => $term->slug,
                    'key'  => $this->register_term($term),
                ];
            }
        }

        return $data;
    }

    private function register_all_terms($taxonomy) {
        if (!$taxonomy || !taxonomy_exists($taxonomy) || in_array($taxonomy, $this->internal_taxonomies, true)) {
            return;
        }

        $terms = get_terms([
            'taxonomy'   => $taxonomy,
            'hide_empty' => false,
            'orderby'    => 'parent',
            'order'      => 'ASC',
        ]);

        if (is_wp_error($terms)) {
            return;
        }

        foreach ($terms as $term) {
            $this->register_term($term);
        }
    }

    /**
     * Adds a term to the export, together with its ancestors so the hierarchy
     * can be rebuilt, and registers every image its description or term meta
     * points at (ACF term image/file/gallery fields, WooCommerce category
     * thumbnails).
     */
    private function register_term($term) {
        if (is_numeric($term)) {
            $term = get_term(intval($term));
        }

        if (!$term || is_wp_error($term) || empty($term->term_id)) {
            return '';
        }

        $key = $term->taxonomy . ':' . $term->term_id;

        if (isset($this->term_manifest[$key])) {
            return $key;
        }

        // Reserve the slot first so a broken parent chain cannot loop forever.
        $this->term_manifest[$key] = true;

        $parent_key = '';

        if (!empty($term->parent)) {
            $parent = get_term(intval($term->parent), $term->taxonomy);

            if ($parent && !is_wp_error($parent)) {
                $parent_key = $this->register_term($parent);
            }
        }

        $this->collect_media_from_content($term->description);

        $meta = $this->build_meta_export(get_term_meta($term->term_id));

        $this->term_manifest[$key] = [
            'key'             => $key,
            'term_id'         => intval($term->term_id),
            'taxonomy'        => $term->taxonomy,
            'name'            => $term->name,
            'slug'            => $term->slug,
            'description'     => $term->description,
            'parent_key'      => $parent_key,
            'meta'            => $meta['meta'],
            'media_meta_keys' => $meta['media_meta_keys'],
            'term_meta_keys'  => $meta['term_meta_keys'],
        ];

        return $key;
    }

    private function exportable_terms() {
        $terms = [];

        foreach ($this->term_manifest as $key => $entry) {
            if (is_array($entry)) {
                $terms[$key] = $entry;
            }
        }

        return $terms;
    }

    private function import_terms($terms) {
        $this->term_queue = [];

        foreach ($terms as $key => $entry) {
            if (is_array($entry) && !empty($entry['taxonomy'])) {
                $this->term_queue[$key] = $entry;
            }
        }

        foreach (array_keys($this->term_queue) as $key) {
            $this->import_term($key, []);
        }
    }

    /**
     * Creates or matches a single term, resolving its parent first. $stack
     * guards against a parent chain that points back at itself.
     */
    private function import_term($key, $stack = []) {
        if (isset($this->term_key_map[$key])) {
            return $this->term_key_map[$key];
        }

        if (!isset($this->term_queue[$key]) || isset($stack[$key])) {
            return 0;
        }

        $entry    = $this->term_queue[$key];
        $taxonomy = sanitize_key($entry['taxonomy']);

        $name = !empty($entry['name']) ? $entry['name'] : '';
        $slug = !empty($entry['slug']) ? sanitize_title($entry['slug']) : sanitize_title($name);

        if ($name === '') {
            $name = $slug;
        }

        if ($name === '' || $slug === '') {
            return 0;
        }

        if (!taxonomy_exists($taxonomy)) {
            // One line per missing taxonomy rather than one per term.
            if (!isset($this->missing_taxonomies[$taxonomy])) {
                $this->missing_taxonomies[$taxonomy] = true;

                $this->fail_term($taxonomy, 'this taxonomy is not registered on the target site — activate the plugin or theme that registers it, then import again');
            }

            return 0;
        }

        $stack[$key] = true;

        $parent_id = !empty($entry['parent_key'])
            ? $this->import_term($entry['parent_key'], $stack)
            : 0;

        $description = !empty($entry['description']) ? $this->remap_content($entry['description']) : '';

        $existing = get_term_by('slug', $slug, $taxonomy);

        if (!$existing) {
            $existing = get_term_by('name', $name, $taxonomy);
        }

        $is_new  = false;
        $term_id = 0;

        if ($existing && !is_wp_error($existing)) {
            $term_id = intval($existing->term_id);

            if ($this->update_terms) {
                $args = ['name' => $name];

                if ($description !== '') {
                    $args['description'] = $description;
                }

                if ($parent_id) {
                    $args['parent'] = $parent_id;
                }

                wp_update_term($term_id, $taxonomy, $args);
            }

            $this->report['terms_updated']++;
        } else {
            $created = wp_insert_term($name, $taxonomy, [
                'slug'        => $slug,
                'description' => $description,
                'parent'      => $parent_id,
            ]);

            if (is_wp_error($created)) {
                $term_id = $this->term_id_from_error($created);

                if (!$term_id) {
                    $this->fail_term($name, $created->get_error_message());

                    return 0;
                }

                $this->report['terms_updated']++;
            } else {
                $term_id = intval($created['term_id']);
                $is_new  = true;

                $this->report['terms_created']++;
            }
        }

        if (!$term_id) {
            return 0;
        }

        $this->term_key_map[$key] = $term_id;

        if (!empty($entry['term_id'])) {
            $this->term_id_map[intval($entry['term_id'])] = $term_id;
        }

        if (($is_new || $this->update_terms) && !empty($entry['meta']) && is_array($entry['meta'])) {
            $media_meta_keys = !empty($entry['media_meta_keys']) && is_array($entry['media_meta_keys'])
                ? $entry['media_meta_keys']
                : [];

            $term_meta_keys = !empty($entry['term_meta_keys']) && is_array($entry['term_meta_keys'])
                ? $entry['term_meta_keys']
                : [];

            $this->import_meta('term', $term_id, $entry['meta'], $media_meta_keys, $term_meta_keys);
        }

        return $term_id;
    }

    /**
     * wp_insert_term() reports an existing term through the error data, which is
     * either the term ID or an array carrying it.
     */
    private function term_id_from_error($error) {
        $data = $error->get_error_data();

        if (is_array($data) && !empty($data['term_id'])) {
            return intval($data['term_id']);
        }

        if (is_numeric($data)) {
            return intval($data);
        }

        return 0;
    }

    private function fail_term($term, $reason) {
        $this->report['terms_failed'][] = [
            'term'   => $term,
            'reason' => $reason,
        ];
    }

    private function import_taxonomies($post_id, $taxonomies) {
        foreach ($taxonomies as $taxonomy => $terms) {
            if (!taxonomy_exists($taxonomy) || !is_array($terms)) {
                continue;
            }

            $term_ids = [];

            foreach ($terms as $term_data) {
                if (!is_array($term_data)) {
                    continue;
                }

                // Exports from 2.1.0 onwards carry the full term in the "terms"
                // section; older files only have the name and slug.
                if (!empty($term_data['key'])) {
                    $mapped = $this->import_term($term_data['key'], []);

                    if ($mapped) {
                        $term_ids[] = $mapped;

                        continue;
                    }
                }

                if (empty($term_data['name'])) {
                    continue;
                }

                $slug = !empty($term_data['slug'])
                    ? sanitize_title($term_data['slug'])
                    : sanitize_title($term_data['name']);

                $term = term_exists($slug, $taxonomy);

                if (!$term) {
                    $term = wp_insert_term($term_data['name'], $taxonomy, [
                        'slug' => $slug,
                    ]);
                }

                if (!is_wp_error($term) && !empty($term['term_id'])) {
                    $term_ids[] = intval($term['term_id']);
                }
            }

            if (!empty($term_ids)) {
                wp_set_object_terms($post_id, $term_ids, $taxonomy);
            }
        }
    }
}

new WL_Page_CPT_Export_Import_ACF_Media();
