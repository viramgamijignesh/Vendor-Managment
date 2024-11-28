<?php
/**
 * Plugin Name: Vendor Payments
 * Description: Practical Task
 * Version: 1.0.0
 * Author: Jignesh Viramgami
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

class VendorPayments {
    private $table_name;

    public function __construct() {
        global $wpdb;
        $this->table_name = $wpdb->prefix . 'vendor_payments';

        register_activation_hook(__FILE__, [$this, 'activation_tasks']);
        add_action('plugins_loaded', [$this, 'check_dependencies']);
        add_action('woocommerce_product_options_general_product_data', [$this, 'add_product_fields']);
        add_action('woocommerce_process_product_meta', [$this, 'save_product_fields']);
        add_action('woocommerce_thankyou', [$this, 'create_payment_records']);
        add_action('admin_menu', [$this, 'register_admin_pages']);
        add_action('admin_post_update_payment_status', [$this, 'update_payment_status']); 
        //add_action('woocommerce_admin_order_data_after_order_details', [$this, 'display_vendor_details_in_order']);
    }

    // activation hook Create database table
    public function activation_tasks() {
        $this->create_database_table();
    }

    // Give error if WooCommerce not active
    public function check_dependencies() {
        if (!class_exists('WooCommerce')) {
            add_action('admin_notices', function () {
                echo '<div class="notice notice-error"><p><strong>' . esc_html__('Vendor Payments', 'vendor-payments') . '</strong> requires WooCommerce to be active.</p></div>';
            });
    
            add_action('admin_init', function () {                
                deactivate_plugins(plugin_basename(__FILE__));
                if (isset($_GET['activate'])) {
                    unset($_GET['activate']);
                }
            });
        }
    }    


    // Create the custom database table.    
    public function create_database_table() {
        global $wpdb;

        $charset_collate = $wpdb->get_charset_collate();
        $sql = "CREATE TABLE $this->table_name (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            vendor VARCHAR(255) NOT NULL,
            product_name VARCHAR(255) NOT NULL,
            order_id BIGINT UNSIGNED NOT NULL,
            order_status VARCHAR(50) NOT NULL,
            payment_term VARCHAR(50) NOT NULL,
            transaction_detail VARCHAR(255),
            payment_status VARCHAR(50) DEFAULT 'Pending' NOT NULL
        ) $charset_collate;";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
    }

    // Add custom product fields.
    public function add_product_fields() {

        $vendors = ['Vendor A' => 'Vendor A', 'Vendor B' => 'Vendor B', 'Vendor C' => 'Vendor C'];

        echo '<div class="options_group">';
        woocommerce_wp_select([
            'id' => '_vendor_name',
            'label' => esc_html__('Vendor Name', 'vendor-payments'),
            'options' => $vendors,
            'description' => esc_html__('Select the vendor for this product.', 'vendor-payments'),
            'desc_tip' => true,
        ]);

        woocommerce_wp_text_input([
            'id' => '_purchase_cost',
            'label' => esc_html__('Purchase Cost', 'vendor-payments'),
            'type' => 'number',
            'custom_attributes' => ['step' => '0.01'],
            'description' => esc_html__('Enter the purchase cost of the product.', 'vendor-payments'),
            'desc_tip' => true,
        ]);

        woocommerce_wp_select([
            'id' => '_payment_term',
            'label' => esc_html__('Payment Term', 'vendor-payments'),
            'options' => [
                'Post Payment' => esc_html__('Post Payment', 'vendor-payments'),
                'Pre Payment' => esc_html__('Pre Payment', 'vendor-payments'),
                'Weekly' => esc_html__('Weekly', 'vendor-payments'),
                'Monthly' => esc_html__('Monthly', 'vendor-payments'),
            ],
            'description' => esc_html__('Select the payment term for the vendor.', 'vendor-payments'),
            'desc_tip' => true,
        ]);
        echo '</div>';
    }

    // Save custom product fields.
    public function save_product_fields($post_id) {
        if (isset($_POST['_purchase_cost'])) {
            $purchase_cost = sanitize_text_field($_POST['_purchase_cost']);
            if ($purchase_cost < 0) {               
                $purchase_cost = 0;
            }
            update_post_meta($post_id, '_purchase_cost', $purchase_cost);
        }
        if (isset($_POST['_vendor_name'])) {
            update_post_meta($post_id, '_vendor_name', sanitize_text_field($_POST['_vendor_name']));
        }
        if (isset($_POST['_payment_term'])) {
            update_post_meta($post_id, '_payment_term', sanitize_text_field($_POST['_payment_term']));
        }
    }

    // Create vendor payment records after order success.
    public function create_payment_records($order_id) {
        global $wpdb;

        $order = wc_get_order($order_id);
        if (!$order) {
            return;
        }

        foreach ($order->get_items() as $item_id => $item) {
            $product = $item->get_product();
            if (!$product) {
                continue;
            }

            $vendor_name = get_post_meta($product->get_id(), '_vendor_name', true);
            $payment_term = get_post_meta($product->get_id(), '_payment_term', true);

            $wpdb->insert($this->table_name, [
                'vendor' => $vendor_name,
                'product_name' => $product->get_name(),
                'order_id' => $order_id,
                'order_status' => $order->get_status(),
                'payment_term' => $payment_term,
                'payment_status' => 'Pending',
            ]);
        }
    }

    // Create New sidebar menu and register
    public function register_admin_pages() {
        add_menu_page(
            'Vendor Payments',
            'Vendor Payments',
            'manage_options',
            'vendor-payments',
            [$this, 'render_payment_list_page'],
            'dashicons-money-alt',
            20
        );

        add_submenu_page(
            'vendor-payments',
            'Edit Payment Status',
            'Edit Payment Status',
            'manage_options',
            'edit-vendor-payment-status',
            [$this, 'render_edit_payment_status_page']
        );
    }

    // Show payment list
    public function render_payment_list_page() {
        global $wpdb;
        $current_user = wp_get_current_user();
        $user_roles = $current_user->roles;

        $query = "SELECT * FROM {$this->table_name}";
        $params = [];

        
        if (!in_array('administrator', $user_roles, true)) {
            $query .= " WHERE vendor = %s";
            $params[] = $current_user->display_name;
        }

        
        if (!empty($params)) {
            $payments = $wpdb->get_results($wpdb->prepare($query, $params));
        } else {
            $payments = $wpdb->get_results($query);
        }

        
        echo '<div class="wrap"><h1>Vendor Payments</h1>';
        if (empty($payments)) {
            echo '<p>No records found.</p>';
        } else {
            echo '<table class="widefat fixed">';
            echo '<thead><tr><th>ID</th><th>Vendor</th><th>Product</th><th>Order ID</th><th>Order Status</th><th>Payment Term</th><th>Payment Status</th>';
            if (in_array('administrator', $user_roles, true)) {
                echo '<th>Action</th>'; 
            }
            echo '</tr></thead><tbody>';

            foreach ($payments as $payment) {
                echo "<tr>
                    <td>{$payment->id}</td>
                    <td>{$payment->vendor}</td>
                    <td>{$payment->product_name}</td>
                    <td>{$payment->order_id}</td>
                    <td>{$payment->order_status}</td>
                    <td>{$payment->payment_term}</td>
                    <td>{$payment->payment_status}</td>";
                if (in_array('administrator', $user_roles, true)) {
                    $edit_link = admin_url('admin.php?page=edit-vendor-payment-status&payment_id=' . $payment->id);
                    echo "<td><a href='$edit_link'>Edit</a></td>";
                }
                echo '</tr>';
            }
            echo '</tbody></table>';
        }
        echo '</div>';
    }


    // Edit Payment List
    public function render_edit_payment_status_page() {
        global $wpdb;
        $table_name = $this->table_name;

        if (!isset($_GET['payment_id'])) {
            echo '<p>Invalid request.</p>';
            return;
        }

        $payment_id = intval($_GET['payment_id']);
        $payment = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table_name WHERE id = %d", $payment_id));

        if (!$payment) {
            echo '<p>Payment not found.</p>';
            return;
        }

        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['payment_status'])) {
            $new_status = sanitize_text_field($_POST['payment_status']);
            $allowed_statuses = ['Pending', 'Paid', 'Refunded', 'Credit Note'];

            if (in_array($new_status, $allowed_statuses)) {
                $wpdb->update($table_name, ['payment_status' => $new_status], ['id' => $payment_id]);
                echo '<p>Status updated successfully.</p>';
                $payment->payment_status = $new_status; 
            } else {
                echo '<p>Invalid status.</p>';
            }
        }

        echo '<h1>Edit Payment Status</h1>';
        echo '<form method="post">';
        echo '<p><label for="payment_status">Payment Status:</label>';
        echo '<select id="payment_status" name="payment_status">';
        foreach (['Pending', 'Paid', 'Refunded', 'Credit Note'] as $status) {
            $selected = selected($payment->payment_status, $status, false);
            echo "<option value='$status' $selected>$status</option>";
        }
        echo '</select></p>';
        echo '<p><button type="submit" class="button button-primary">Update</button></p>';
        echo '</form>';
    }

    // Update Paymnt status 
    public function update_payment_status() {
        
        if (!current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('You do not have sufficient permissions to access this page.', 'vendor-payments'));
        }

        
        if (!isset($_POST['_wpnonce']) || !wp_verify_nonce($_POST['_wpnonce'], 'update_payment_status_nonce')) {
            wp_die(esc_html__('Invalid request.', 'vendor-payments'));
        }

        
        if (!isset($_POST['payment_id']) || !is_numeric($_POST['payment_id'])) {
            wp_die(esc_html__('Invalid Payment ID.', 'vendor-payments'));
        }

        if (!isset($_POST['payment_status'])) {
            wp_die(esc_html__('Payment status is required.', 'vendor-payments'));
        }

        $payment_id = intval($_POST['payment_id']);
        $new_status = sanitize_text_field($_POST['payment_status']);
        $allowed_statuses = ['Pending', 'Paid', 'Refunded', 'Credit Note'];

        
        if (!in_array($new_status, $allowed_statuses, true)) {
            wp_die(esc_html__('Invalid payment status.', 'vendor-payments'));
        }

        global $wpdb;        
        $payment = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->table_name} WHERE id = %d", $payment_id));

        if (!$payment) {
            wp_die(esc_html__('Payment record not found.', 'vendor-payments'));
        }

        
        $updated = $wpdb->update(
            $this->table_name,
            ['payment_status' => $new_status],
            ['id' => $payment_id],
            ['%s'],
            ['%d']
        );

        if ($updated === false) {
            wp_die(esc_html__('Failed to update the payment status. Please try again.', 'vendor-payments'));
        }
        
        wp_redirect(admin_url('admin.php?page=vendor-payments&message=updated'));
        exit;
    }

    // show vendor detail in order detail page
    public function display_vendor_details_in_order($order) {
        global $wpdb;

        // Get all items for the current order
        $order_id = $order->get_id();

        // Fetch vendor payment details for this order from the custom table
        $payments = $wpdb->get_results(
            $wpdb->prepare("SELECT * FROM {$this->table_name} WHERE order_id = %d", $order_id)
        );

        if (empty($payments)) {
            echo '<h3>Vendor Details</h3><p>No vendor details available for this order.</p>';
            return;
        }

        echo '<h3>Vendor Details</h3>';
        echo '<table class="table fixed">';
        echo '<thead><tr><th>Vendor</th><th>Product</th><th>Cost</th><th>Payment Status</th></tr></thead>';
        echo '<tbody>';
        foreach ($payments as $payment) {
            echo "<tr>
                <td>{$payment->vendor}</td>
                <td>{$payment->product_name}</td>
                <td>" . wc_price(get_post_meta($payment->product_name, '_purchase_cost', true)) . "</td>
                <td>{$payment->payment_status}</td>
            </tr>";
        }
        echo '</tbody></table>';
    }

}

new VendorPayments();
