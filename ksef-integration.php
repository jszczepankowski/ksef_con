<?php
/**
 * Plugin Name: KSeF Integration (własna implementacja)
 * Version: 1.0.5
 * Requires PHP: 8.1
 */

defined('ABSPATH') || exit;

define('KSEF_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('KSEF_PLUGIN_URL', plugin_dir_url(__FILE__));

// Autoloader dla phpseclib3
spl_autoload_register(function ($class) {
    $prefix = 'phpseclib3\\';
    $base_dir = KSEF_PLUGIN_DIR . 'lib/phpseclib/';
    if (strncmp($prefix, $class, strlen($prefix)) !== 0) return;
    $relative_class = substr($class, strlen($prefix));
    $file = $base_dir . str_replace('\\', '/', $relative_class) . '.php';
    if (file_exists($file)) require_once $file;
});

require_once KSEF_PLUGIN_DIR . 'includes/class-ksef-crypto.php';
require_once KSEF_PLUGIN_DIR . 'includes/class-ksef-token-manager.php';
require_once KSEF_PLUGIN_DIR . 'includes/class-ksef-auth.php';
require_once KSEF_PLUGIN_DIR . 'includes/class-ksef-invoices.php';
require_once KSEF_PLUGIN_DIR . 'includes/class-ksef-client.php';

if (is_admin()) {
    require_once KSEF_PLUGIN_DIR . 'admin/settings-page.php';
}

// Reszta kodu (aktywacja, cron, save) – identyczna jak w wersji 1.0.4
register_activation_hook(__FILE__, function () {
    if (!wp_next_scheduled('ksef_sync_invoices')) {
        wp_schedule_event(time(), 'hourly', 'ksef_sync_invoices');
    }
    add_option('ksef_environment', 'test');
    add_option('ksef_nip', '');
    add_option('ksef_token', '');
    add_option('ksef_last_sync', '');
    add_option('ksef_sync_start_date', '');
    add_option('ksef_sync_types', ['Subject1', 'Subject2']);
});

register_deactivation_hook(__FILE__, function () {
    wp_clear_scheduled_hook('ksef_sync_invoices');
});

add_action('ksef_sync_invoices', 'ksef_cron_sync');

function ksef_cron_sync() {
    try {
        $client    = new KSeF_Client();
        $token_mgr = new KSeF_Token_Manager();
        if (!$token_mgr->is_configured()) {
            error_log('[KSeF] Pominięto – brak konfiguracji');
            return;
        }
        $access_token = $token_mgr->get_valid_access_token();
        $invoices_obj = new KSeF_Invoices($client);

        $start_date_opt = get_option('ksef_sync_start_date', '');
        $date_from = !empty($start_date_opt)
            ? $start_date_opt . 'T00:00:00'
            : get_option('ksef_last_sync', date('Y-m-01') . 'T00:00:00');
        $date_to = date('Y-m-d') . 'T23:59:59';

        $types = get_option('ksef_sync_types', ['Subject1', 'Subject2']);
        $total = 0;

        foreach ($types as $subject_type) {
            $invoices = $invoices_obj->query_all_invoices($access_token, $date_from, $date_to, $subject_type);
            foreach ($invoices as $inv) {
                $xml = $invoices_obj->download_invoice($access_token, $inv['ksefReferenceNumber']);
                ksef_save_invoice_to_db($inv, $xml, $subject_type);
                $total++;
            }
        }

        update_option('ksef_last_sync', date('Y-m-d\TH:i:s'));
        error_log('[KSeF] Pobrano ' . $total . ' faktur');
    } catch (Exception $e) {
        error_log('[KSeF] Błąd: ' . $e->getMessage());
    }
}

function ksef_save_invoice_to_db(array $metadata, string $xml, string $subject_type = '') {
    $post_data = [
        'post_type'   => 'ksef_invoice',
        'post_title'  => sanitize_text_field($metadata['ksefReferenceNumber']),
        'post_status' => 'publish',
        'meta_input'  => [
            '_ksef_xml'            => $xml,
            '_ksef_invoice_hash'   => sanitize_text_field($metadata['invoiceHash'] ?? ''),
            '_ksef_invoicing_date' => sanitize_text_field($metadata['invoicingDate'] ?? ''),
            '_ksef_due_date'       => sanitize_text_field($metadata['dueDate'] ?? ''),
            '_ksef_subject_type'   => $subject_type,
            '_ksef_acquisition_date'=> current_time('mysql'),
        ],
    ];
    $existing = get_posts([
        'post_type'      => 'ksef_invoice',
        'title'          => $metadata['ksefReferenceNumber'],
        'posts_per_page' => 1,
        'post_status'    => 'any',
    ]);
    if (!empty($existing)) {
        $post_data['ID'] = $existing[0]->ID;
        wp_update_post($post_data);
    } else {
        wp_insert_post($post_data);
    }
}

add_action('init', function () {
    register_post_type('ksef_invoice', [
        'labels' => [
            'name'          => __('Faktury KSeF', 'ksef-integration'),
            'singular_name' => __('Faktura KSeF', 'ksef-integration'),
        ],
        'public'       => false,
        'show_ui'      => true,
        'menu_icon'    => 'dashicons-media-text',
        'supports'     => ['title', 'custom-fields'],
        'has_archive'  => false,
    ]);
});