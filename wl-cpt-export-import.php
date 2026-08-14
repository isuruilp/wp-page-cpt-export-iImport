<?php
/**
 * Plugin Name: Page/CPT Export Import with ACF Media
 * Description: Export/import selected page or selected post type with content, featured images, content images, taxonomies, post meta, and ACF image/file/gallery fields. Media files can be embedded inside the JSON so a local site can be imported into a live site.
 * Version: 2.0.0
 * Author: Isuru Perera
 */

if (!defined('ABSPATH')) {
    exit;
}

class WL_Page_CPT_Export_Import_ACF_Media {

    const SCHEMA_VERSION   = 2;
    const SOURCE_URL_META  = '_wl_original_source_url';
    const SOURCE_ID_META   = '_wl_source_attachment_id';
    const SOURCE_SITE_META = '_wl_source_site';

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

    private $report = [];

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
        $post_types = get_post_types(['public' => true], 'objects');

        $pages = get_pages([
            'post_status' => ['publish', 'draft', 'pending', 'private'],
            'sort_column' => 'post_title',
            'sort_order'  => 'ASC',
        ]);

        $report = get_transient('wl_cpt_import_report_' . get_current_user_id());
        ?>
        <div class="wrap">
            <h1>Page / CPT Export Import</h1>
            <p>Export/import selected page or selected post type with ACF images and media.</p>

            <?php if (!empty($_GET['imported']) && is_array($report)) : ?>
                <?php $this->render_import_report($report); ?>
            <?php endif; ?>

            <hr>

            <h2>Export</h2>

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <?php wp_nonce_field('wl_cpt_export_nonce'); ?>

                <input type="hidden" name="action" value="wl_cpt_export">

                <table class="form-table">
                    <tr>
                        <th scope="row">Select Export Type</th>
                        <td>
                            <select name="export_type" id="wl_export_type" required>
                                <option value="page">Page</option>
                                <option value="post_type">Post Type</option>
                            </select>
                        </td>
                    </tr>

                    <tr id="wl_page_row">
                        <th scope="row">Select Page</th>
                        <td>
                            <select name="page_id" id="wl_page_id">
                                <option value="">Select Page</option>

                                <?php foreach ($pages as $page) : ?>
                                    <option value="<?php echo esc_attr($page->ID); ?>">
                                        <?php echo esc_html($page->post_title . ' — ID: ' . $page->ID); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                    </tr>

                    <tr id="wl_post_type_row" style="display:none;">
                        <th scope="row">Select Post Type</th>
                        <td>
                            <select name="post_type" id="wl_post_type">
                                <option value="">Select Post Type</option>

                                <?php foreach ($post_types as $post_type) : ?>
                                    <?php
                                    if (in_array($post_type->name, ['attachment', 'page', 'post'], true)) {
                                        continue;
                                    }
                                    ?>
                                    <option value="<?php echo esc_attr($post_type->name); ?>">
                                        <?php echo esc_html($post_type->label . ' (' . $post_type->name . ')'); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row">Media Files</th>
                        <td>
                            <label>
                                <input type="checkbox" name="embed_media" value="1" checked>
                                Embed the media files inside the JSON
                            </label>
                            <p class="description">
                                Keep this on when moving a <strong>local site to a live site</strong>. The live server
                                cannot download from a <code>.local</code> address, so the files have to travel inside
                                the JSON. Turn it off only when the source site is publicly reachable from the target
                                server — the JSON stays small, but the target downloads each file over HTTP.
                            </p>
                        </td>
                    </tr>
                </table>

                <?php submit_button('Export JSON'); ?>
            </form>

            <hr>

            <h2>Import JSON File</h2>

            <p class="description">
                This server accepts uploads up to <strong><?php echo esc_html(size_format(wp_max_upload_size())); ?></strong>
                (<code>upload_max_filesize</code> <?php echo esc_html(ini_get('upload_max_filesize')); ?>,
                <code>post_max_size</code> <?php echo esc_html(ini_get('post_max_size')); ?>).
                If the export is bigger than that, upload it with FTP into
                <code><?php echo esc_html($this->uploads_info()['basedir']); ?></code> and use the server path field below.
            </p>

            <form method="post" enctype="multipart/form-data" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <?php wp_nonce_field('wl_cpt_import_nonce'); ?>

                <input type="hidden" name="action" value="wl_cpt_import">

                <table class="form-table">
                    <tr>
                        <th scope="row">JSON File</th>
                        <td>
                            <input type="file" name="import_file" accept=".json,application/json">
                            <p class="description">Upload the exported JSON file.</p>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row">Or Server Path</th>
                        <td>
                            <input type="text" name="server_path" class="regular-text" placeholder="wl-export-page-12-2026-08-10.json">
                            <p class="description">
                                Filename or path of a JSON file already sitting inside the uploads folder. Use this when
                                the export is too large to upload through the browser.
                            </p>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row">Source URL Override</th>
                        <td>
                            <input type="url" name="source_url" class="regular-text" placeholder="https://staging.example.com">
                            <p class="description">
                                Only used when the JSON has no embedded media. Media URLs are re-pointed at this address
                                before downloading, which is handy when the files were exported from an unreachable host
                                but are available somewhere else.
                            </p>
                        </td>
                    </tr>
                </table>

                <?php submit_button('Import JSON'); ?>
            </form>
        </div>

        <script>
            document.addEventListener('DOMContentLoaded', function () {
                const exportType = document.getElementById('wl_export_type');
                const pageRow = document.getElementById('wl_page_row');
                const postTypeRow = document.getElementById('wl_post_type_row');

                function toggleExportFields() {
                    if (exportType.value === 'page') {
                        pageRow.style.display = '';
                        postTypeRow.style.display = 'none';
                    } else {
                        pageRow.style.display = 'none';
                        postTypeRow.style.display = '';
                    }
                }

                exportType.addEventListener('change', toggleExportFields);
                toggleExportFields();
            });
        </script>
        <?php
    }

    private function render_import_report($report) {
        $failed = !empty($report['media_failed']) ? $report['media_failed'] : [];
        $class  = empty($failed) ? 'notice-success' : 'notice-warning';
        ?>
        <div class="notice <?php echo esc_attr($class); ?>">
            <p><strong>Import completed.</strong></p>
            <p>
                Posts created: <strong><?php echo intval($report['posts_created']); ?></strong> &nbsp;|&nbsp;
                Media imported: <strong><?php echo intval($report['media_imported']); ?></strong> &nbsp;|&nbsp;
                Media already present: <strong><?php echo intval($report['media_reused']); ?></strong> &nbsp;|&nbsp;
                Media failed: <strong><?php echo count($failed); ?></strong>
            </p>

            <?php if (!empty($failed)) : ?>
                <p>These media files could not be imported:</p>
                <ul style="list-style:disc;margin-left:20px;">
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

        $export_type  = !empty($_POST['export_type']) ? sanitize_key($_POST['export_type']) : 'page';
        $embed_media  = !empty($_POST['embed_media']);
        $posts        = [];
        $export_label = '';

        if ($export_type === 'page') {
            $page_id = !empty($_POST['page_id']) ? intval($_POST['page_id']) : 0;

            if (!$page_id) {
                wp_die('Please select a page.');
            }

            $page = get_post($page_id);

            if (!$page || $page->post_type !== 'page') {
                wp_die('Selected page not found.');
            }

            $posts        = [$page];
            $export_label = 'page-' . $page_id;
        } elseif ($export_type === 'post_type') {
            $post_type = !empty($_POST['post_type']) ? sanitize_key($_POST['post_type']) : '';

            if (!$post_type || !post_type_exists($post_type)) {
                wp_die('Please select a valid post type.');
            }

            $posts = get_posts([
                'post_type'      => $post_type,
                'post_status'    => 'any',
                'posts_per_page' => -1,
                'orderby'        => 'ID',
                'order'          => 'ASC',
            ]);

            $export_label = $post_type;
        } else {
            wp_die('Invalid export type.');
        }

        $uploads       = $this->uploads_info();
        $exported_posts = [];

        foreach ($posts as $post) {
            $exported_posts[] = $this->build_post_export($post);
        }

        $header = [
            'plugin_version' => '2.0.0',
            'schema'         => self::SCHEMA_VERSION,
            'site_url'       => site_url(),
            'home_url'       => home_url(),
            'upload_baseurl' => $uploads['baseurl'],
            'export_type'    => $export_type,
            'embedded_media' => $embed_media,
            'exported_at'    => current_time('mysql'),
        ];

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

    private function stream_export($header, $exported_posts, $embed_media) {
        echo '{';

        foreach ($header as $key => $value) {
            echo wp_json_encode((string) $key) . ':' . wp_json_encode($value) . ',';
        }

        echo '"posts":' . wp_json_encode($exported_posts, JSON_UNESCAPED_SLASHES) . ',';
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
        $all_meta    = get_post_meta($post_id);

        $this->collect_media_from_content($post->post_content);

        $export_meta     = [];
        $media_meta_keys = [];

        foreach ($all_meta as $meta_key => $meta_values) {
            if (in_array($meta_key, $this->skip_meta_keys, true)) {
                continue;
            }

            $holds_media_ids = $this->meta_key_holds_media_ids($meta_key, $all_meta);

            if ($holds_media_ids) {
                $media_meta_keys[] = $meta_key;
            }

            $export_meta[$meta_key] = [];

            foreach ($meta_values as $value) {
                $value = maybe_unserialize($value);

                $this->collect_media_from_value($value, $holds_media_ids);

                $export_meta[$meta_key][] = $value;
            }
        }

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
            'meta'            => $export_meta,
            'media_meta_keys' => $media_meta_keys,
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

        if (!is_array($data) || empty($data['posts']) || !is_array($data['posts'])) {
            wp_die('Invalid JSON file.');
        }

        if (empty($data['schema']) || intval($data['schema']) < self::SCHEMA_VERSION) {
            wp_die('This JSON was produced by an older version of the plugin. Please re-export it from the source site using version 2.0.0 so the media files are included.');
        }

        $this->report = [
            'posts_created'  => 0,
            'media_imported' => 0,
            'media_reused'   => 0,
            'media_failed'   => [],
        ];

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

            $this->import_meta($new_post_id, $item['meta'], $media_meta_keys);
        }

        return $new_post_id;
    }

    private function import_meta($post_id, $meta, $media_meta_keys) {
        foreach ($meta as $meta_key => $values) {
            if (in_array($meta_key, $this->skip_meta_keys, true)) {
                continue;
            }

            $numeric_is_media = in_array($meta_key, $media_meta_keys, true);

            delete_post_meta($post_id, $meta_key);

            foreach ((array) $values as $value) {
                $value = $this->remap_meta_value($value, $numeric_is_media);

                add_post_meta($post_id, $meta_key, wp_slash($value));
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

    private function remap_meta_value($value, $numeric_is_media) {
        if (is_array($value)) {
            $remapped = [];

            foreach ($value as $key => $item) {
                $remapped[$key] = $this->remap_meta_value($item, $numeric_is_media);
            }

            return $this->remap_media_id_structure($remapped);
        }

        if (is_string($value)) {
            $decoded = $this->maybe_decode_json($value);

            if ($decoded !== null) {
                $remapped = $this->remap_meta_value($decoded, false);
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

            if ($numeric_is_media) {
                $value = $this->remap_numeric_id_list($value);
            }

            return $value;
        }

        if ($numeric_is_media && is_numeric($value) && isset($this->media_id_map[intval($value)])) {
            return $this->media_id_map[intval($value)];
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

    private function remap_numeric_id_list($value) {
        if (!preg_match('#^\s*\d+(\s*,\s*\d+)*\s*$#', $value)) {
            return $value;
        }

        $ids = array_map('trim', explode(',', $value));

        foreach ($ids as $index => $id) {
            if (isset($this->media_id_map[intval($id)])) {
                $ids[$index] = $this->media_id_map[intval($id)];
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
            $terms = wp_get_object_terms($post_id, $taxonomy);

            if (empty($terms) || is_wp_error($terms)) {
                continue;
            }

            foreach ($terms as $term) {
                $data[$taxonomy][] = [
                    'name' => $term->name,
                    'slug' => $term->slug,
                ];
            }
        }

        return $data;
    }

    private function import_taxonomies($post_id, $taxonomies) {
        foreach ($taxonomies as $taxonomy => $terms) {
            if (!taxonomy_exists($taxonomy) || !is_array($terms)) {
                continue;
            }

            $term_ids = [];

            foreach ($terms as $term_data) {
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
