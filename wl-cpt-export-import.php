<?php
/**
 * Plugin Name: Page/CPT Export Import with ACF Media
 * Description: Export/import selected page or selected post type with content, featured images, content images, taxonomies, post meta, and ACF image/file/gallery fields.
 * Version: 1.0.0
 * Author: Isuru Perera
 */

if (!defined('ABSPATH')) {
    exit;
}

class WL_Page_CPT_Export_Import_ACF_Media {

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
            'sort_order' => 'ASC',
        ]);
        ?>
        <div class="wrap">
            <h1>Page / CPT Export Import</h1>
            <p>Export/import selected page or selected post type with ACF images and media.</p>

            <?php if (!empty($_GET['imported'])) : ?>
                <div class="notice notice-success is-dismissible">
                    <p><strong>Import completed successfully.</strong></p>
                </div>
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
                </table>

                <?php submit_button('Export JSON'); ?>
            </form>

            <hr>

            <h2>Import JSON File</h2>

            <form method="post" enctype="multipart/form-data" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <?php wp_nonce_field('wl_cpt_import_nonce'); ?>

                <input type="hidden" name="action" value="wl_cpt_import">

                <table class="form-table">
                    <tr>
                        <th scope="row">JSON File</th>
                        <td>
                            <input type="file" name="import_file" accept=".json,application/json" required>
                            <p class="description">Upload the exported JSON file.</p>
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

    public function handle_export() {
        if (!current_user_can('manage_options')) {
            wp_die('Permission denied.');
        }

        check_admin_referer('wl_cpt_export_nonce');

        $export_type = !empty($_POST['export_type']) ? sanitize_key($_POST['export_type']) : 'page';

        $posts = [];
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

            $posts = [$page];
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

        $export = [
            'plugin_version' => '1.3.0',
            'site_url'       => site_url(),
            'export_type'    => $export_type,
            'exported_at'    => current_time('mysql'),
            'posts'          => [],
        ];

        foreach ($posts as $post) {
            $post_id = $post->ID;
            $featured_id = get_post_thumbnail_id($post_id);

            $export['posts'][] = [
                'old_id'         => $post_id,
                'post_type'      => $post->post_type,
                'post_title'     => $post->post_title,
                'post_name'      => $post->post_name,
                'post_content'   => $post->post_content,
                'post_excerpt'   => $post->post_excerpt,
                'post_status'    => $post->post_status,
                'post_date'      => $post->post_date,
                'menu_order'     => $post->menu_order,
                'post_parent'    => $post->post_parent,
                'featured_image' => $featured_id ? $this->get_media_package($featured_id) : null,
                'content_images' => $this->extract_content_images($post->post_content),
                'taxonomies'     => $this->export_taxonomies($post_id, $post->post_type),
                'meta'           => $this->export_meta_with_acf_media($post_id),
            ];
        }

        $filename = 'wl-export-' . sanitize_title($export_label) . '-' . date('Y-m-d-H-i-s') . '.json';

        nocache_headers();
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');

        echo wp_json_encode($export, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        exit;
    }

    public function handle_import() {
        if (!current_user_can('manage_options')) {
            wp_die('Permission denied.');
        }

        check_admin_referer('wl_cpt_import_nonce');

        if (empty($_FILES['import_file']['tmp_name'])) {
            wp_die('No file uploaded.');
        }

        $json = file_get_contents($_FILES['import_file']['tmp_name']);
        $data = json_decode($json, true);

        if (empty($data['posts']) || !is_array($data['posts'])) {
            wp_die('Invalid JSON file.');
        }

        foreach ($data['posts'] as $item) {
            if (empty($item['post_type']) || !post_type_exists($item['post_type'])) {
                continue;
            }

            $new_post_id = wp_insert_post([
                'post_type'    => sanitize_key($item['post_type']),
                'post_title'   => !empty($item['post_title']) ? wp_kses_post($item['post_title']) : '',
                'post_name'    => !empty($item['post_name']) ? sanitize_title($item['post_name']) : '',
                'post_content' => '',
                'post_excerpt' => !empty($item['post_excerpt']) ? wp_kses_post($item['post_excerpt']) : '',
                'post_status'  => !empty($item['post_status']) ? sanitize_key($item['post_status']) : 'publish',
                'post_date'    => !empty($item['post_date']) ? sanitize_text_field($item['post_date']) : current_time('mysql'),
                'menu_order'   => !empty($item['menu_order']) ? intval($item['menu_order']) : 0,
                'post_parent'  => 0,
            ], true);

            if (is_wp_error($new_post_id)) {
                continue;
            }

            if (!empty($item['featured_image']['url'])) {
                $featured_id = $this->import_media_from_url($item['featured_image']['url'], $new_post_id);

                if ($featured_id) {
                    set_post_thumbnail($new_post_id, $featured_id);

                    if (!empty($item['featured_image']['alt'])) {
                        update_post_meta($featured_id, '_wp_attachment_image_alt', sanitize_text_field($item['featured_image']['alt']));
                    }
                }
            }

            $content = !empty($item['post_content']) ? $item['post_content'] : '';

            if (!empty($item['content_images']) && is_array($item['content_images'])) {
                foreach ($item['content_images'] as $old_url) {
                    $new_img_id = $this->import_media_from_url($old_url, $new_post_id);

                    if ($new_img_id) {
                        $new_url = wp_get_attachment_url($new_img_id);
                        $content = str_replace($old_url, $new_url, $content);
                    }
                }
            }

            wp_update_post([
                'ID'           => $new_post_id,
                'post_content' => $content,
            ]);

            if (!empty($item['taxonomies']) && is_array($item['taxonomies'])) {
                $this->import_taxonomies($new_post_id, $item['taxonomies']);
            }

            if (!empty($item['meta']) && is_array($item['meta'])) {
                $this->import_meta_with_acf_media($new_post_id, $item['meta']);
            }
        }

        wp_safe_redirect(admin_url('tools.php?page=wl-cpt-export-import&imported=1'));
        exit;
    }

    private function export_meta_with_acf_media($post_id) {
        $all_meta = get_post_meta($post_id);
        $acf_field_map = $this->get_acf_field_map_from_meta($all_meta);

        $export_meta = [];

        foreach ($all_meta as $meta_key => $meta_values) {
            if (in_array($meta_key, ['_edit_lock', '_edit_last', '_thumbnail_id'], true)) {
                continue;
            }

            $export_meta[$meta_key] = [];

            foreach ($meta_values as $value) {
                $prepared_value = maybe_unserialize($value);

                if (!empty($acf_field_map[$meta_key])) {
                    $prepared_value = $this->prepare_acf_meta_value_for_export(
                        $prepared_value,
                        $acf_field_map[$meta_key]
                    );
                }

                $export_meta[$meta_key][] = $prepared_value;
            }
        }

        return $export_meta;
    }

    private function get_acf_field_map_from_meta($all_meta) {
        $map = [];

        foreach ($all_meta as $meta_key => $meta_values) {
            if (strpos($meta_key, '_') !== 0) {
                continue;
            }

            $actual_meta_key = substr($meta_key, 1);

            if (!$actual_meta_key || empty($all_meta[$actual_meta_key])) {
                continue;
            }

            $field_key = !empty($meta_values[0]) ? maybe_unserialize($meta_values[0]) : '';

            if (!is_string($field_key) || strpos($field_key, 'field_') !== 0) {
                continue;
            }

            if (function_exists('acf_get_field')) {
                $field = acf_get_field($field_key);

                if (!empty($field) && is_array($field)) {
                    $map[$actual_meta_key] = $field;
                }
            }
        }

        return $map;
    }

    private function prepare_acf_meta_value_for_export($value, $field) {
        $type = !empty($field['type']) ? $field['type'] : '';

        if (in_array($type, ['image', 'file'], true)) {
            return $this->prepare_media_value_for_export($value);
        }

        if ($type === 'gallery') {
            $gallery = [];

            if (is_array($value)) {
                foreach ($value as $attachment_id) {
                    $gallery[] = $this->prepare_media_value_for_export($attachment_id);
                }
            }

            return $gallery;
        }

        return $value;
    }

    private function prepare_media_value_for_export($value) {
        if (empty($value)) {
            return $value;
        }

        if (is_numeric($value)) {
            return $this->get_media_package(intval($value));
        }

        if (is_string($value) && filter_var($value, FILTER_VALIDATE_URL)) {
            $attachment_id = attachment_url_to_postid($value);

            if ($attachment_id) {
                return $this->get_media_package($attachment_id);
            }

            return [
                '__wl_media' => true,
                'old_id'     => 0,
                'url'        => $value,
                'title'      => '',
                'alt'        => '',
                'filename'   => basename(parse_url($value, PHP_URL_PATH)),
            ];
        }

        return $value;
    }

    private function import_meta_with_acf_media($post_id, $meta) {
        foreach ($meta as $meta_key => $values) {
            if (in_array($meta_key, ['_edit_lock', '_edit_last', '_thumbnail_id'], true)) {
                continue;
            }

            delete_post_meta($post_id, $meta_key);

            if (!is_array($values)) {
                $values = [$values];
            }

            foreach ($values as $value) {
                $value = $this->prepare_acf_meta_value_for_import($value, $post_id);
                add_post_meta($post_id, $meta_key, $value);
            }
        }
    }

    private function prepare_acf_meta_value_for_import($value, $post_id) {
        if (is_array($value) && !empty($value['__wl_media']) && !empty($value['url'])) {
            $attachment_id = $this->import_media_from_url($value['url'], $post_id);

            if ($attachment_id && !empty($value['alt'])) {
                update_post_meta($attachment_id, '_wp_attachment_image_alt', sanitize_text_field($value['alt']));
            }

            return $attachment_id ? $attachment_id : '';
        }

        if (is_array($value)) {
            $new_value = [];

            foreach ($value as $key => $item) {
                $new_value[$key] = $this->prepare_acf_meta_value_for_import($item, $post_id);
            }

            return $new_value;
        }

        return $value;
    }

    private function get_media_package($attachment_id) {
        $attachment_id = intval($attachment_id);

        if (!$attachment_id) {
            return null;
        }

        $url = wp_get_attachment_url($attachment_id);

        if (!$url) {
            return null;
        }

        return [
            '__wl_media' => true,
            'old_id'     => $attachment_id,
            'url'        => $url,
            'title'      => get_the_title($attachment_id),
            'alt'        => get_post_meta($attachment_id, '_wp_attachment_image_alt', true),
            'filename'   => basename(parse_url($url, PHP_URL_PATH)),
        ];
    }

    private function import_media_from_url($url, $post_id = 0) {
        if (empty($url)) {
            return false;
        }

        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';

        $existing = $this->find_attachment_by_original_url($url);

        if ($existing) {
            return $existing;
        }

        $tmp = download_url($url);

        if (is_wp_error($tmp)) {
            return false;
        }

        $filename = basename(parse_url($url, PHP_URL_PATH));

        if (!$filename) {
            $filename = 'imported-media-' . time() . '.jpg';
        }

        $file_array = [
            'name'     => sanitize_file_name($filename),
            'tmp_name' => $tmp,
        ];

        $attachment_id = media_handle_sideload($file_array, $post_id);

        if (is_wp_error($attachment_id)) {
            @unlink($tmp);
            return false;
        }

        update_post_meta($attachment_id, '_wl_original_source_url', esc_url_raw($url));

        return $attachment_id;
    }

    private function find_attachment_by_original_url($url) {
        $existing = get_posts([
            'post_type'      => 'attachment',
            'post_status'    => 'inherit',
            'posts_per_page' => 1,
            'fields'         => 'ids',
            'meta_key'       => '_wl_original_source_url',
            'meta_value'     => esc_url_raw($url),
        ]);

        return !empty($existing) ? intval($existing[0]) : false;
    }

    private function extract_content_images($content) {
        $images = [];

        if (preg_match_all('/<img[^>]+src=["\']([^"\']+)["\']/i', $content, $matches)) {
            $images = array_unique($matches[1]);
        }

        return array_values($images);
    }

    private function export_taxonomies($post_id, $post_type) {
        $taxonomies = get_object_taxonomies($post_type);
        $data = [];

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