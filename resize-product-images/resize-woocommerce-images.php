<?php

/**
 * Plugin Name: Resize WooCommerce Product Images
 * Description: Force resize all WooCommerce product images (original + thumbnails) to 550x500.
 * Version: 1.4
 * Author: Shrabon
 */

if (!defined('ABSPATH')) exit;

// 1. Set WooCommerce product image sizes
add_action('after_setup_theme', function () {
    // Set image dimensions
    update_option('woocommerce_single_image_width', 550);
    update_option('woocommerce_thumbnail_image_width', 550); // Corrected option name
    update_option('woocommerce_thumbnail_cropping', 'custom');
    update_option('woocommerce_thumbnail_cropping_custom_width', 550);
    update_option('woocommerce_thumbnail_cropping_custom_height', 500);
});

// 2. Admin page and AJAX handlers
add_action('admin_menu', function () {
    add_submenu_page(
        'tools.php',
        'Resize Product Images',
        'Resize Product Images',
        'manage_options',
        'resize-product-images',
        'resize_product_images_page'
    );
});

// Add AJAX handlers
add_action('wp_ajax_resize_images_ajax', 'handle_resize_images_ajax');

function handle_resize_images_ajax()
{
    check_ajax_referer('resize_images_nonce', 'nonce');

    if (!current_user_can('manage_options')) {
        wp_die(json_encode(['success' => false, 'message' => 'Permission denied']));
    }

    // Make sure required files are loaded
    require_once(ABSPATH . 'wp-admin/includes/image.php');
    require_once(ABSPATH . 'wp-admin/includes/file.php');
    require_once(ABSPATH . 'wp-admin/includes/media.php');

    $type = sanitize_text_field($_POST['type']);

    if ($type === 'all') {
        $resized = resize_all_product_images();
    } elseif ($type === 'new') {
        $resized = resize_new_product_images();
    } else {
        wp_die(json_encode(['success' => false, 'message' => 'Invalid type']));
    }

    // Add some debug info for verification
    $debug_info = '';
    if ($resized > 0) {
        // Get a sample product to verify dimensions
        $args = [
            'post_type' => 'product',
            'posts_per_page' => 1,
            'post_status' => 'publish',
        ];
        $sample_query = new WP_Query($args);
        if ($sample_query->have_posts()) {
            $sample_query->the_post();
            $sample_id = get_the_ID();
            $thumb_id = get_post_thumbnail_id($sample_id);
            if ($thumb_id) {
                $dimensions = verify_image_dimensions($thumb_id);
                if ($dimensions) {
                    $debug_info = ' (Sample verification: ' . $dimensions['width'] . 'x' . $dimensions['height'] . ')';
                }
            }
            wp_reset_postdata();
        }
    }

    wp_die(json_encode([
        'success' => true,
        'resized' => $resized,
        'debug' => $debug_info
    ]));
}

function resize_product_images_page()
{
    echo '<div class="wrap"><h2>Resize WooCommerce Product Images</h2>';
    echo '<p>This will resize WooCommerce product images to 550x500 (original and thumbnails). The process may take some time depending on how many products you have.</p>';

    // Result message area
    echo '<div id="resize-message" style="display: none;"></div>';

    // Resize All Images Form
    echo '<div style="margin-bottom: 30px;">';
    echo '<h3>Resize All Product Images</h3>';
    echo '<p>This will resize images for ALL products in your store.</p>';
    echo '<p><button type="button" class="button-primary" id="resize-all-btn" onclick="startResize(\'all\')">Resize All Product Images</button></p>';
    echo '<div id="loading-all" class="resize-loading" style="display: none;">';
    echo '<span class="spinner is-active"></span> Processing all product images...';
    echo '</div>';
    echo '</div>';

    // Resize New Images Form
    echo '<div style="margin-bottom: 30px;">';
    echo '<h3>Resize New Product Images</h3>';
    echo '<p>This will resize images only for products added since the last resize operation.</p>';
    echo '<p><button type="button" class="button-primary" id="resize-new-btn" onclick="startResize(\'new\')">Resize New Product Images</button></p>';
    echo '<div id="loading-new" class="resize-loading" style="display: none;">';
    echo '<span class="spinner is-active"></span> Processing new product images...';
    echo '</div>';
    echo '</div>';

    // Add CSS and JavaScript for loading indicators
    echo '<style>
        .resize-loading {
            margin-top: 10px;
            padding: 10px;
            background: #f0f0f1;
            border-left: 4px solid #72aee6;
            display: inline-block;
            border-radius: 3px;
        }
        .resize-loading .spinner {
            float: left;
            margin-right: 8px;
            margin-top: 2px;
        }
        .button-primary:disabled {
            opacity: 0.6;
            cursor: not-allowed;
        }
        .notice.updated {
            padding: 12px;
            margin: 15px 0;
            background: #fff;
            border-left: 4px solid #00a32a;
            box-shadow: 0 1px 1px rgba(0,0,0,.04);
        }
    </style>';

    echo '<script>
        function startResize(type) {
            var btn = document.getElementById("resize-" + type + "-btn");
            var loader = document.getElementById("loading-" + type);
            var messageDiv = document.getElementById("resize-message");
            
            // Show loading and disable button
            btn.disabled = true;
            loader.style.display = "block";
            messageDiv.style.display = "none";
            
            // Create form data
            var formData = new FormData();
            formData.append("action", "resize_images_ajax");
            formData.append("type", type);
            formData.append("nonce", "' . wp_create_nonce('resize_images_nonce') . '");
            
            // Make AJAX request
            var xhr = new XMLHttpRequest();
            xhr.open("POST", "' . admin_url('admin-ajax.php') . '", true);
            
            xhr.onreadystatechange = function() {
                if (xhr.readyState === 4) {
                    // Hide loading and enable button
                    btn.disabled = false;
                    loader.style.display = "none";
                    
                    if (xhr.status === 200) {
                        try {
                            var response = JSON.parse(xhr.responseText);
                            if (response.success) {
                                var debugInfo = response.debug ? response.debug : "";
                                messageDiv.innerHTML = "<div class=\"notice updated\"><p>✅ Resized " + response.resized + " product images to 550x500 (original and thumbnails)." + debugInfo + "</p></div>";
                                messageDiv.style.display = "block";
                            } else {
                                throw new Error("Resize failed");
                            }
                        } catch (e) {
                            messageDiv.innerHTML = "<div class=\"notice notice-error\"><p>❌ Error processing images. Please try again.</p></div>";
                            messageDiv.style.display = "block";
                        }
                    } else {
                        messageDiv.innerHTML = "<div class=\"notice notice-error\"><p>❌ Network error. Please try again.</p></div>";
                        messageDiv.style.display = "block";
                    }
                }
            };
            
            xhr.send(formData);
        }
    </script>';

    echo '</div>';
}

// 3. Original resizer function (resize all images)
function resize_all_product_images()
{
    // Make sure required files are loaded
    require_once(ABSPATH . 'wp-admin/includes/image.php');

    $args = [
        'post_type'      => 'product',
        'posts_per_page' => -1,
        'post_status'    => 'publish',
    ];

    $query = new WP_Query($args);
    $resized = 0;

    if ($query->have_posts()) {
        while ($query->have_posts()) {
            $query->the_post();
            $product_id = get_the_ID();
            $resized += process_product_images($product_id);
        }
        wp_reset_postdata();
    }

    // Update the last resize timestamp
    update_option('rwpi_last_resize_timestamp', current_time('timestamp'));

    // Force clear any caches
    if (function_exists('wc_get_product_attachment_props')) {
        WC_Cache_Helper::get_transient_version('images', true);
    }

    return $resized;
}

// 4. New function to resize only new product images
function resize_new_product_images()
{
    // Make sure required files are loaded
    require_once(ABSPATH . 'wp-admin/includes/image.php');

    // Get the last resize timestamp
    $last_resize = get_option('rwpi_last_resize_timestamp', 0);

    $args = [
        'post_type'      => 'product',
        'posts_per_page' => -1,
        'post_status'    => 'publish',
        'date_query'     => [
            [
                'after' => date('Y-m-d H:i:s', $last_resize),
                'inclusive' => false,
            ],
        ],
    ];

    $query = new WP_Query($args);
    $resized = 0;

    if ($query->have_posts()) {
        while ($query->have_posts()) {
            $query->the_post();
            $product_id = get_the_ID();
            $resized += process_product_images($product_id);
        }
        wp_reset_postdata();
    }

    // Update the last resize timestamp
    update_option('rwpi_last_resize_timestamp', current_time('timestamp'));

    // Force clear any caches
    if (function_exists('wc_get_product_attachment_props')) {
        WC_Cache_Helper::get_transient_version('images', true);
    }

    return $resized;
}

// 5. Helper function to process images for a single product
function process_product_images($product_id)
{
    $resized = 0;
    $thumb_id = get_post_thumbnail_id($product_id);

    // Process featured image
    if ($thumb_id) {
        if (resize_single_image($thumb_id)) {
            $resized++;
        }
    }

    // Process gallery images
    $gallery_image_ids = get_post_meta($product_id, '_product_image_gallery', true);
    if ($gallery_image_ids) {
        $gallery_image_ids = explode(',', $gallery_image_ids);
        foreach ($gallery_image_ids as $image_id) {
            if (!empty($image_id)) {
                if (resize_single_image($image_id)) {
                    $resized++;
                }
            }
        }
    }

    return $resized;
}

// 5.1 Helper function to verify image dimensions (for debugging)
function verify_image_dimensions($image_id)
{
    $attached_file = get_attached_file($image_id);
    if ($attached_file && file_exists($attached_file)) {
        $image_data = getimagesize($attached_file);
        if ($image_data) {
            return array(
                'width' => $image_data[0],
                'height' => $image_data[1],
                'file' => basename($attached_file)
            );
        }
    }
    return false;
}

// 6. Helper function to resize a single image
function resize_single_image($image_id)
{
    $attached_file = get_attached_file($image_id);

    if ($attached_file && file_exists($attached_file)) {
        // Load WordPress image editor
        $image_editor = wp_get_image_editor($attached_file);

        if (is_wp_error($image_editor)) {
            return false;
        }

        // Get current image dimensions
        $current_size = $image_editor->get_size();

        // Only resize if image is not already 550x500
        if ($current_size['width'] != 550 || $current_size['height'] != 500) {
            // Resize the original image to exactly 550x500
            $resize_result = $image_editor->resize(550, 500, true); // true = crop to exact dimensions

            if (is_wp_error($resize_result)) {
                return false;
            }

            // Save the resized original image (overwrite the original)
            $save_result = $image_editor->save($attached_file);

            if (is_wp_error($save_result)) {
                return false;
            }
        }

        // Force delete old thumbnail sizes
        $metadata = wp_get_attachment_metadata($image_id);
        if (is_array($metadata) && isset($metadata['sizes'])) {
            $base_dir = dirname($attached_file) . '/';

            foreach ($metadata['sizes'] as $size_info) {
                if (isset($size_info['file'])) {
                    $file_path = $base_dir . $size_info['file'];
                    if (file_exists($file_path)) {
                        @unlink($file_path);
                    }
                }
            }
        }

        // Update attachment metadata with new dimensions
        $new_metadata = array(
            'width' => 550,
            'height' => 500,
            'file' => _wp_relative_upload_path($attached_file),
            'sizes' => array(),
            'image_meta' => array()
        );

        // Regenerate all thumbnail sizes with the new original image
        $generated_metadata = wp_generate_attachment_metadata($image_id, $attached_file);

        // Merge the metadata
        if (is_array($generated_metadata)) {
            $new_metadata = array_merge($new_metadata, $generated_metadata);
            $new_metadata['width'] = 550;
            $new_metadata['height'] = 500;
        }

        wp_update_attachment_metadata($image_id, $new_metadata);

        // Clear any image caches
        wp_cache_delete($image_id, 'posts');

        return true;
    }

    return false;
}

// 7. Add function to clear image cache when activating plugin
register_activation_hook(__FILE__, 'rwpi_activate_plugin');

function rwpi_activate_plugin()
{
    // Set image dimensions on activation
    update_option('woocommerce_single_image_width', 550);
    update_option('woocommerce_thumbnail_image_width', 550);
    update_option('woocommerce_thumbnail_cropping', 'custom');
    update_option('woocommerce_thumbnail_cropping_custom_width', 550);
    update_option('woocommerce_thumbnail_cropping_custom_height', 500);

    // Initialize last resize timestamp
    if (!get_option('rwpi_last_resize_timestamp')) {
        update_option('rwpi_last_resize_timestamp', 0);
    }

    // Clear image cache if WooCommerce is active
    if (function_exists('WC')) {
        WC_Cache_Helper::get_transient_version('images', true);
    }
}
