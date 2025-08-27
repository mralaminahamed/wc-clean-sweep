<?php
/*
 * Plugin Name:       WooCommerce Clean Sweep
 * Plugin URI:        https://example.com/woocommerce-clean-sweep
 * Description:       A plugin to permanently delete all WooCommerce orders (including HPOS), products, tags, reviews, and customers with a single action, similar to an "empty trash" function.
 * Version:           1.0.3
 * Requires at least: 5.0
 * Requires PHP:      7.4
 * Author:            xAI
 * Author URI:        https://x.ai
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       woocommerce-clean-sweep
 * Domain Path:       /languages
 */

// Prevent direct access to the file
if (!defined('ABSPATH')) {
    exit;
}

// Check if WooCommerce is active
function wcs_is_woocommerce_active() {
    return in_array('woocommerce/woocommerce.php', apply_filters('active_plugins', get_option('active_plugins')));
}

// Add admin menu item for custom page
function wcs_register_admin_menu() {
    add_submenu_page(
        'woocommerce',
        'WooCommerce Clean Sweep',
        'Clean Sweep',
        'manage_woocommerce',
        'woocommerce-clean-sweep',
        'wcs_admin_page_callback'
    );
}
add_action('admin_menu', 'wcs_register_admin_menu');

// Admin page content
function wcs_admin_page_callback() {
    if (!wcs_is_woocommerce_active()) {
        echo '<div class="error"><p>WooCommerce is not active. Please activate WooCommerce to use this plugin.</p></div>';
        return;
    }

    // Check if the form is submitted
    if (isset($_POST['wcs_clean_sweep']) && check_admin_referer('wcs_clean_sweep_action', 'wcs_nonce')) {
        wcs_delete_all_data();
        echo '<div class="updated"><p>All WooCommerce orders (including HPOS), products, tags, reviews, and customers have been deleted.</p></div>';
    }
    ?>
    <div class="wrap">
        <h1>WooCommerce Clean Sweep</h1>
        <p>This action will permanently delete all WooCommerce orders (including HPOS), products, tags, reviews, and customers. This cannot be undone.</p>
        <form method="post" onsubmit="return confirm('Are you sure you want to delete all WooCommerce data, including orders (HPOS) and customers? This action cannot be undone.');">
            <?php wp_nonce_field('wcs_clean_sweep_action', 'wcs_nonce'); ?>
            <input type="submit" name="wcs_clean_sweep" class="button button-primary" value="Delete All WooCommerce Data">
        </form>
    </div>
    <?php
}

// Add Clean Sweep button to the right of product filters only
function wcs_add_clean_sweep_button() {
    $screen = get_current_screen();
    if (!wcs_is_woocommerce_active() || !current_user_can('manage_woocommerce')) {
        return;
    }

    // Check if we're on the Products admin page
    if ($screen && $screen->post_type === 'product') {
        ?>
        <form method="post" style="display: inline-block; margin-left: 10px;" onsubmit="return confirm('Are you sure you want to delete all WooCommerce data, including orders (HPOS) and customers? This action cannot be undone.');">
            <?php wp_nonce_field('wcs_clean_sweep_action', 'wcs_nonce'); ?>
            <input type="submit" name="wcs_clean_sweep" class="button button-primary" value="Clean Sweep All Data">
        </form>
        <?php
    }
}
add_action('restrict_manage_posts', 'wcs_add_clean_sweep_button');

// Handle form submission from the button
function wcs_handle_clean_sweep_submission() {
    if (isset($_POST['wcs_clean_sweep']) && check_admin_referer('wcs_clean_sweep_action', 'wcs_nonce')) {
        if (current_user_can('manage_woocommerce')) {
            wcs_delete_all_data();
            // Redirect to the same page with a success message
            $current_screen = get_current_screen();
            if (!$current_screen) {
                // If no screen (e.g., custom page), redirect to the custom page
                $redirect_url = add_query_arg(
                    ['wcs_message' => 'success'],
                    admin_url('admin.php?page=woocommerce-clean-sweep')
                );
            } else {
                $redirect_url = add_query_arg(
                    ['post_type' => $current_screen->post_type, 'wcs_message' => 'success'],
                    admin_url('edit.php')
                );
            }
            wp_safe_redirect($redirect_url);
            exit;
        }
    }
}
add_action('admin_init', 'wcs_handle_clean_sweep_submission');

// Display success message after deletion
function wcs_display_success_message() {
    if (isset($_GET['wcs_message']) && $_GET['wcs_message'] === 'success' && current_user_can('manage_woocommerce')) {
        echo '<div class="updated"><p>All WooCommerce orders (including HPOS), products, tags, reviews, and customers have been deleted.</p></div>';
    }
}
add_action('admin_notices', 'wcs_display_success_message');

// Function to delete all WooCommerce data, including HPOS orders and customers
function wcs_delete_all_data() {
    if (!current_user_can('manage_woocommerce')) {
        return;
    }

    global $wpdb;

    // Check if HPOS is enabled
    $hpos_enabled = class_exists('Automattic\WooCommerce\Utilities\OrderUtil') && Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();

    if ($hpos_enabled) {
        // Delete HPOS orders
        $order_ids = $wpdb->get_col("SELECT id FROM {$wpdb->prefix}wc_orders WHERE type = 'shop_order'");
        foreach ($order_ids as $order_id) {
            // Delete order and its metadata
            $wpdb->delete("{$wpdb->prefix}wc_orders", ['id' => $order_id], ['%d']);
            $wpdb->delete("{$wpdb->prefix}wc_orders_meta", ['order_id' => $order_id], ['%d']);
        }
    } else {
        // Delete legacy orders
        $order_ids = $wpdb->get_col("SELECT ID FROM {$wpdb->posts} WHERE post_type = 'shop_order'");
        foreach ($order_ids as $order_id) {
            wp_delete_post($order_id, true);
        }
    }

    // Delete products
    $product_ids = $wpdb->get_col("SELECT ID FROM {$wpdb->posts} WHERE post_type = 'product'");
    foreach ($product_ids as $product_id) {
        wp_delete_post($product_id, true);
    }

    // Delete tags (product tags)
    $tag_ids = get_terms(['taxonomy' => 'product_tag', 'fields' => 'ids', 'hide_empty' => false]);
    foreach ($tag_ids as $tag_id) {
        wp_delete_term($tag_id, 'product_tag');
    }

    // Delete reviews (comments associated with products)
    $review_ids = $wpdb->get_col("SELECT comment_ID FROM {$wpdb->comments} WHERE comment_type = 'review'");
    foreach ($review_ids as $review_id) {
        wp_delete_comment($review_id, true);
    }

    // Delete customers
    $customer_ids = $wpdb->get_col("
        SELECT u.ID
        FROM {$wpdb->users} u
        INNER JOIN {$wpdb->usermeta} um ON u.ID = um.user_id
        WHERE um.meta_key = '{$wpdb->prefix}capabilities'
        AND um.meta_value LIKE '%customer%'
    ");
    foreach ($customer_ids as $customer_id) {
        wp_delete_user($customer_id);
    }

    // Clear transients and caches
    $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_wc_%' OR option_name LIKE '_transient_timeout_wc_%'");
    wc_delete_product_transients();
}
?>
