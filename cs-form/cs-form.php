<?php
/*
Plugin Name: CS Form
Plugin URI: https://example.com/plugins/the-basics/
Description: Handle the basics with this plugin.
Version: 1.10.3
Requires at least: 5.2
Requires PHP: 7.2
Author: Minhaz faisal
Author URI: https://author.example.com/
License: GPL v2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html
Text Domain: wpt1
Domain Path: /languages
*/

if (!defined('ABSPATH')) {
    exit;
}

register_activation_hook(__FILE__, 'cs_form_activate');
register_uninstall_hook(__FILE__, 'cs_form_uninstall');

function cs_form_uninstall() {
    global $wpdb;

    $table_name = $wpdb->prefix . 'cs_form_entries';
    $wpdb->query("DROP TABLE IF EXISTS `$table_name`");
}

function cs_form_activate() {
    global $wpdb;

    $table_name = $wpdb->prefix . 'cs_form_entries';
    $charset_collate = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE IF NOT EXISTS $table_name (
        id bigint(20) NOT NULL AUTO_INCREMENT,
        name varchar(255) NOT NULL,
        email varchar(255) NOT NULL,
        message longtext NOT NULL,
        created_at datetime DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY  (id)
    ) $charset_collate;";

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta($sql);
}

add_shortcode('cs_form', 'cs_form_shortcode');
add_action('template_redirect', 'cs_form_handle_frontend_submission');

add_action('admin_menu', 'cs_form_register_admin_page');
add_action('admin_init', 'cs_form_handle_admin_action');

function cs_form_handle_frontend_submission() {
    if (is_admin() || !isset($_POST['cs_form_submit'])) {
        return;
    }

    $result = cs_form_handle_submission();
    $page_url = get_permalink(get_queried_object_id());

    if (!$page_url) {
        $page_url = home_url('/');
    }

    $redirect_url = add_query_arg(
        'cs_form_status',
        $result['success'] ? 'success' : 'error',
        remove_query_arg('cs_form_status', $page_url)
    );

    wp_safe_redirect($redirect_url);
    exit;
}

function cs_form_register_admin_page() {
    add_menu_page(
        'CS Form',
        'CS Form',
        'manage_options',
        'cs-form',
        'cs_form_render_form_admin_page',
        'dashicons-feedback'
    );

    add_submenu_page(
        'cs-form',
        'Form',
        'Form',
        'manage_options',
        'cs-form',
        'cs_form_render_form_admin_page'
    );

    add_submenu_page(
        'cs-form',
        'Form Submissions',
        'Data',
        'manage_options',
        'cs-form-entries',
        'cs_form_render_admin_page'
    );
}

function cs_form_render_form_admin_page() {
    if (!current_user_can('manage_options')) {
        wp_die(esc_html__('You do not have permission to view this page.', 'wpt1'));
    }
    ?>
    <div class="wrap">
        <h1><?php esc_html_e('CS Form', 'wpt1'); ?></h1>
        <h2><?php esc_html_e('Form Preview', 'wpt1'); ?></h2>
        <div style="max-width: 500px; background: #fff; border: 1px solid #c3c4c7; padding: 20px;">
            <p>
                <label for="cs-form-preview-name"><?php esc_html_e('Name', 'wpt1'); ?></label><br>
                <input class="regular-text" type="text" id="cs-form-preview-name" disabled>
            </p>
            <p>
                <label for="cs-form-preview-email"><?php esc_html_e('Email', 'wpt1'); ?></label><br>
                <input class="regular-text" type="email" id="cs-form-preview-email" disabled>
            </p>
            <p>
                <label for="cs-form-preview-message"><?php esc_html_e('Message', 'wpt1'); ?></label><br>
                <textarea class="large-text" id="cs-form-preview-message" rows="5" disabled></textarea>
            </p>
            <button type="button" class="button button-primary" disabled><?php esc_html_e('Send Message', 'wpt1'); ?></button>
        </div>
        <h2><?php esc_html_e('How to Use This Form', 'wpt1'); ?></h2>
        <p><?php esc_html_e('Copy and paste this shortcode into any page or post where you want the form to appear:', 'wpt1'); ?></p>
        <p><code>[cs_form]</code></p>
    </div>
    <?php
}

function cs_form_handle_admin_action() {
    if (!isset($_POST['cs_form_admin_action'])) {
        return;
    }

    if (!current_user_can('manage_options')) {
        wp_die(esc_html__('You do not have permission to manage form entries.', 'wpt1'));
    }

    $action = sanitize_key(wp_unslash($_POST['cs_form_admin_action']));
    $entry_id = isset($_POST['entry_id']) ? absint($_POST['entry_id']) : 0;

    if (!$entry_id || !in_array($action, ['save', 'delete'], true)) {
        wp_die(esc_html__('Invalid form entry action.', 'wpt1'));
    }

    check_admin_referer('cs_form_' . $action . '_entry_' . $entry_id);

    global $wpdb;
    $table_name = $wpdb->prefix . 'cs_form_entries';

    if ($action === 'delete') {
        $result = $wpdb->delete($table_name, ['id' => $entry_id], ['%d']);
        $notice = $result === false ? 'error' : ($result ? 'deleted' : 'not_found');
    } else {
        $name = isset($_POST['cs_name']) ? sanitize_text_field(wp_unslash($_POST['cs_name'])) : '';
        $email = isset($_POST['cs_email']) ? sanitize_email(wp_unslash($_POST['cs_email'])) : '';
        $message = isset($_POST['cs_message']) ? sanitize_textarea_field(wp_unslash($_POST['cs_message'])) : '';

        if ($name === '' || $email === '' || $message === '' || !is_email($email)) {
            $redirect_url = add_query_arg(
                ['page' => 'cs-form-entries', 'edit' => $entry_id, 'notice' => 'invalid'],
                admin_url('admin.php')
            );
            wp_safe_redirect($redirect_url);
            exit;
        }

        $result = $wpdb->update(
            $table_name,
            ['name' => $name, 'email' => $email, 'message' => $message],
            ['id' => $entry_id],
            ['%s', '%s', '%s'],
            ['%d']
        );
        if ($result === false) {
            $notice = 'error';
        } elseif ($result === 0) {
            $entry_exists = $wpdb->get_var(
                $wpdb->prepare("SELECT id FROM `$table_name` WHERE id = %d", $entry_id)
            );
            $notice = $wpdb->last_error !== '' ? 'error' : ($entry_exists ? 'updated' : 'not_found');
        } else {
            $notice = 'updated';
        }
    }

    $redirect_url = add_query_arg(
        ['page' => 'cs-form-entries', 'notice' => $notice],
        admin_url('admin.php')
    );
    wp_safe_redirect($redirect_url);
    exit;
}

function cs_form_render_admin_page() {
    if (!current_user_can('manage_options')) {
        wp_die(esc_html__('You do not have permission to view form entries.', 'wpt1'));
    }

    global $wpdb;

    $table_name = $wpdb->prefix . 'cs_form_entries';
    $per_page = 20;
    $current_page = isset($_GET['paged']) ? max(1, absint($_GET['paged'])) : 1;
    $offset = ($current_page - 1) * $per_page;
    $edit_id = isset($_GET['edit']) ? absint($_GET['edit']) : 0;
    $total_entries = $wpdb->get_var("SELECT COUNT(*) FROM `$table_name`");

    echo '<div class="wrap"><h1>CS Form Entries</h1>';

    if (isset($_GET['notice'])) {
        $notice = sanitize_key(wp_unslash($_GET['notice']));
        $notices = [
            'updated' => ['success', __('Form entry updated.', 'wpt1')],
            'deleted' => ['success', __('Form entry deleted.', 'wpt1')],
            'not_found' => ['warning', __('That form entry no longer exists.', 'wpt1')],
            'invalid' => ['error', __('Please provide a name, a valid email address, and a message.', 'wpt1')],
            'error' => ['error', __('The form entry could not be changed. Please try again.', 'wpt1')],
        ];

        if (isset($notices[$notice])) {
            echo '<div class="notice notice-' . esc_attr($notices[$notice][0]) . ' is-dismissible"><p>';
            echo esc_html($notices[$notice][1]);
            echo '</p></div>';
        }
    }

    if ($wpdb->last_error !== '') {
        echo '<div class="notice notice-error"><p>';
        echo esc_html__('Could not load form entries. Please check that the plugin database table exists.', 'wpt1');
        echo '</p></div></div>';
        return;
    }

    if ($edit_id) {
        $entry_to_edit = $wpdb->get_row(
            $wpdb->prepare("SELECT id, name, email, message FROM `$table_name` WHERE id = %d", $edit_id)
        );

        if ($wpdb->last_error !== '') {
            echo '<div class="notice notice-error"><p>';
            echo esc_html__('Could not load the form entry for editing.', 'wpt1');
            echo '</p></div></div>';
            return;
        }

        if ($entry_to_edit) {
            echo '<h2>' . esc_html__('Edit Form Entry', 'wpt1') . '</h2>';
            echo '<form method="post" action="' . esc_url(admin_url('admin.php?page=cs-form-entries')) . '">';
            echo '<input type="hidden" name="cs_form_admin_action" value="save">';
            echo '<input type="hidden" name="entry_id" value="' . esc_attr($entry_to_edit->id) . '">';
            wp_nonce_field('cs_form_save_entry_' . $entry_to_edit->id);
            echo '<table class="form-table"><tbody>';
            echo '<tr><th><label for="cs-edit-name">' . esc_html__('Name', 'wpt1') . '</label></th><td>';
            echo '<input class="regular-text" id="cs-edit-name" name="cs_name" type="text" value="' . esc_attr($entry_to_edit->name) . '" required></td></tr>';
            echo '<tr><th><label for="cs-edit-email">' . esc_html__('Email', 'wpt1') . '</label></th><td>';
            echo '<input class="regular-text" id="cs-edit-email" name="cs_email" type="email" value="' . esc_attr($entry_to_edit->email) . '" required></td></tr>';
            echo '<tr><th><label for="cs-edit-message">' . esc_html__('Message', 'wpt1') . '</label></th><td>';
            echo '<textarea class="large-text" id="cs-edit-message" name="cs_message" rows="6" required>' . esc_textarea($entry_to_edit->message) . '</textarea></td></tr>';
            echo '</tbody></table>';
            submit_button(__('Save Changes', 'wpt1'));
            echo '</form>';
        } else {
            echo '<div class="notice notice-warning"><p>';
            echo esc_html__('That form entry no longer exists.', 'wpt1');
            echo '</p></div>';
        }
    }

    $total_entries = absint($total_entries);
    $entries = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT id, name, email, message, created_at FROM `$table_name` ORDER BY created_at DESC, id DESC LIMIT %d OFFSET %d",
            $per_page,
            $offset
        )
    );

    if ($wpdb->last_error !== '') {
        echo '<div class="notice notice-error"><p>';
        echo esc_html__('Could not load form entries. Please try again later.', 'wpt1');
        echo '</p></div></div>';
        return;
    }

    if (empty($entries)) {
        echo '<p>' . esc_html__('No form submissions found.', 'wpt1') . '</p></div>';
        return;
    }

    echo '<table class="widefat striped"><thead><tr>';
    echo '<th>' . esc_html__('Name', 'wpt1') . '</th>';
    echo '<th>' . esc_html__('Email', 'wpt1') . '</th>';
    echo '<th>' . esc_html__('Message', 'wpt1') . '</th>';
    echo '<th>' . esc_html__('Submitted', 'wpt1') . '</th>';
    echo '<th>' . esc_html__('Actions', 'wpt1') . '</th>';
    echo '</tr></thead><tbody>';

    foreach ($entries as $entry) {
        echo '<tr>';
        echo '<td>' . esc_html($entry->name) . '</td>';
        echo '<td>' . esc_html($entry->email) . '</td>';
        echo '<td>' . nl2br(esc_html($entry->message)) . '</td>';
        echo '<td>' . esc_html($entry->created_at) . '</td>';
        echo '<td><a class="button button-small" href="' . esc_url(add_query_arg(['page' => 'cs-form-entries', 'edit' => $entry->id], admin_url('admin.php'))) . '">' . esc_html__('Edit', 'wpt1') . '</a> ';
        echo '<form method="post" action="' . esc_url(admin_url('admin.php?page=cs-form-entries')) . '" style="display:inline" onsubmit="return confirm(\'' . esc_js(__('Delete this form entry?', 'wpt1')) . '\');">';
        echo '<input type="hidden" name="cs_form_admin_action" value="delete">';
        echo '<input type="hidden" name="entry_id" value="' . esc_attr($entry->id) . '">';
        wp_nonce_field('cs_form_delete_entry_' . $entry->id, '_wpnonce', false);
        echo '<button type="submit" class="button button-small">' . esc_html__('Delete', 'wpt1') . '</button></form></td>';
        echo '</tr>';
    }

    echo '</tbody></table>';

    $total_pages = (int) ceil($total_entries / $per_page);
    if ($total_pages > 1) {
        $pagination = paginate_links([
            'base' => add_query_arg('paged', '%#%'),
            'format' => '',
            'current' => $current_page,
            'total' => $total_pages,
            'prev_text' => esc_html__('&laquo; Previous', 'wpt1'),
            'next_text' => esc_html__('Next &raquo;', 'wpt1'),
        ]);

        if ($pagination) {
            echo '<div class="tablenav"><div class="tablenav-pages">' . wp_kses_post($pagination) . '</div></div>';
        }
    }

    echo '</div>';
}

function cs_form_shortcode() {
    $message = '';
    if (isset($_GET['cs_form_status']) && is_string($_GET['cs_form_status'])) {
        $status = sanitize_key(wp_unslash($_GET['cs_form_status']));

        if ($status === 'success') {
            $message = '<div class="cs-form-success">' . esc_html__('Thank you! Your message has been submitted successfully.', 'wpt1') . '</div>';
        } elseif ($status === 'error') {
            $message = '<div class="cs-form-error">' . esc_html__('There was a problem submitting your message. Please check the form and try again.', 'wpt1') . '</div>';
        }
    }

    ob_start();
    ?>
    <style>
        .cs-form-wrap { max-width: 500px; margin: 20px 0; }
        .cs-form-wrap input,
        .cs-form-wrap textarea {
            width: 100%;
            padding: 10px;
            margin-bottom: 12px;
            box-sizing: border-box;
        }
        .cs-form-wrap button {
            padding: 10px 20px;
            cursor: pointer;
        }
        .cs-form-success { color: green; margin-bottom: 10px; }
        .cs-form-error { color: red; margin-bottom: 10px; }
    </style>

    <div class="cs-form-wrap">
        <?php echo $message; ?>
        <form method="post" action="">
            <?php wp_nonce_field('cs_form_nonce_action', 'cs_form_nonce'); ?>

            <p>
                <label for="cs_name">Name</label><br>
                <input type="text" id="cs_name" name="cs_name" required>
            </p>

            <p>
                <label for="cs_email">Email</label><br>
                <input type="email" id="cs_email" name="cs_email" required>
            </p>

            <p>
                <label for="cs_message">Message</label><br>
                <textarea id="cs_message" name="cs_message" rows="5" required></textarea>
            </p>

            <button type="submit" name="cs_form_submit" value="1">Send Message</button>
        </form>
    </div>
    <?php
    return ob_get_clean();
}

function cs_form_handle_submission() {
    if (!isset($_POST['cs_form_submit'])) {
        return [
            'success' => false,
            'message' => 'Form not submitted.',
        ];
    }

    if (!isset($_POST['cs_form_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['cs_form_nonce'])), 'cs_form_nonce_action')) {
        return [
            'success' => false,
            'message' => 'Security check failed.',
        ];
    }

    $name = isset($_POST['cs_name']) ? sanitize_text_field(wp_unslash($_POST['cs_name'])) : '';
    $email = isset($_POST['cs_email']) ? sanitize_email(wp_unslash($_POST['cs_email'])) : '';
    $message = isset($_POST['cs_message']) ? sanitize_textarea_field(wp_unslash($_POST['cs_message'])) : '';

    if (empty($name) || empty($email) || empty($message)) {
        return [
            'success' => false,
            'message' => 'Please fill in all fields.',
        ];
    }

    if (!is_email($email)) {
        return [
            'success' => false,
            'message' => 'Please enter a valid email address.',
        ];
    }

    global $wpdb;
    $table_name = $wpdb->prefix . 'cs_form_entries';

    $inserted = $wpdb->insert(
        $table_name,
        [
            'name' => $name,
            'email' => $email,
            'message' => $message,
        ],
        ['%s', '%s', '%s']
    );

    if ($inserted === false) {
        return [
            'success' => false,
            'message' => 'Something went wrong while saving the form.',
        ];
    }

    return [
        'success' => true,
        'message' => 'Thank you! Your message has been submitted successfully.',
    ];
}