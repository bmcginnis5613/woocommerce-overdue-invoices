<?php

/*
Plugin Name: WooCommerce - Overdue Invoices
Description: Display all WooCommerce orders that have not yet been completed, including status, items, total, and PDF invoice link.
Author: FirstTracks Marketing
Author URI: https://firsttracksmarketing.com/
Version: 1.0.1
*/

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

if (!function_exists('wco_is_xlsx_export_request')) {
    function wco_is_xlsx_export_request() {
        return isset($_GET['action'], $_GET['page']) &&
            $_GET['action'] === 'wco_export_xlsx' &&
            $_GET['page'] === 'wc-incomplete-orders';
    }
}

if (!function_exists('wco_write_export_error_log')) {
    function wco_write_export_error_log($message) {
        $primary_log_path = defined('WP_CONTENT_DIR')
            ? WP_CONTENT_DIR . '/uploads/wc-overdue-invoices-export-error.log'
            : __DIR__ . '/wc-overdue-invoices-export-error.log';
        $fallback_log_path = __DIR__ . '/wc-overdue-invoices-export-error.log';
        $line = '[' . gmdate('Y-m-d H:i:s') . ' UTC] ' . $message . PHP_EOL;

        if (@file_put_contents($primary_log_path, $line, FILE_APPEND) === false) {
            @file_put_contents($fallback_log_path, $line, FILE_APPEND);
        }
    }
}

if (wco_is_xlsx_export_request()) {
    register_shutdown_function(function () {
        $error = error_get_last();
        if (!$error) {
            return;
        }

        $fatal_types = array(E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR, E_RECOVERABLE_ERROR);
        if (!in_array($error['type'], $fatal_types, true)) {
            return;
        }

        wco_write_export_error_log(
            $error['message'] . ' in ' . $error['file'] . ':' . $error['line']
        );
    });
}

if (!class_exists('WC_Overdue_Invoices_ZipStream_Compat', false)) {
    class WC_Overdue_Invoices_ZipStream_Compat {
        /**
         * @param resource $fileHandle
         */
        public static function newZipStream($fileHandle) {
            if (
                (function_exists('enum_exists') && enum_exists('ZipStream\OperationMode')) ||
                class_exists('ZipStream\OperationMode')
            ) {
                return new \ZipStream\ZipStream(
                    enableZip64: false,
                    outputStream: $fileHandle,
                    sendHttpHeaders: false,
                    defaultEnableZeroHeader: false
                );
            }

            if (class_exists('ZipStream\Option\Archive')) {
                $options = new \ZipStream\Option\Archive();
                $options->setEnableZip64(false);
                $options->setOutputStream($fileHandle);

                return new \ZipStream\ZipStream(null, $options);
            }

            return new \ZipStream\ZipStream(
                enableZip64: false,
                outputStream: $fileHandle,
                sendHttpHeaders: false,
                defaultEnableZeroHeader: false
            );
        }
    }
}

class WC_Incomplete_Orders {

    private const THIRTY_DAYS_OVERDUE_ORDER_AGE_DAYS = 60;

    /**
     * Constructor
     */
    public function __construct() {
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_assets'));
        add_action('admin_init', array($this, 'handle_export'));
        add_action('wp_ajax_wco_save_column_prefs', array($this, 'save_column_preferences'));
    }

    /**
     * Add admin menu item
     */
    public function add_admin_menu() {
        add_menu_page(
            'Incomplete Orders',
            'Incomplete Orders',
            'manage_woocommerce',
            'wc-incomplete-orders',
            array($this, 'display_orders_page'),
            'dashicons-clipboard',
            56
        );
    }

    /**
     * Enqueue admin styles and scripts
     */
    public function enqueue_assets($hook) {
        if ($hook !== 'toplevel_page_wc-incomplete-orders') {
            return;
        }

        wp_enqueue_style(
            'wco-admin',
            plugin_dir_url(__FILE__) . 'admin-style.css',
            array(),
            '2.0.2'
        );

        wp_enqueue_script(
            'wco-admin',
            plugin_dir_url(__FILE__) . 'admin-script.js',
            array('jquery'),
            '2.0.2',
            true
        );

        wp_localize_script('wco-admin', 'usrData', array(
            'ajax_url'    => admin_url('admin-ajax.php'),
            'nonce'       => wp_create_nonce('wco_column_prefs'),
            'column_prefs' => $this->get_column_preferences(),
        ));
    }

    /**
     * Get column visibility preferences for the current user
     */
    public function get_column_preferences() {
        $user_id  = get_current_user_id();
        $prefs    = get_user_meta($user_id, 'wco_column_visibility', true);

        $defaults = array(
            'id'       => true,
            'date'     => true,
            'customer' => true,
            'status'   => true,
            'items'    => true,
            'total'    => true,
            'invoice'  => true,
        );

        if (empty($prefs) || !is_array($prefs)) {
            return $defaults;
        }

        return wp_parse_args($prefs, $defaults);
    }

    /**
     * Save column visibility preferences via AJAX
     */
    public function save_column_preferences() {
        check_ajax_referer('wco_column_prefs', 'nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error('Insufficient permissions');
        }

        $column  = sanitize_text_field($_POST['column']);
        $visible = filter_var($_POST['visible'], FILTER_VALIDATE_BOOLEAN);

        $user_id = get_current_user_id();
        $prefs   = $this->get_column_preferences();
        $prefs[$column] = $visible;

        update_user_meta($user_id, 'wco_column_visibility', $prefs);

        wp_send_json_success();
    }

    /**
     * Return all WooCommerce orders that are NOT completed.
     *
     * @param bool $thirty_days_overdue_only Whether to limit to orders 30+ days overdue.
     * @return WC_Order[]
     */
    public function get_incomplete_orders($thirty_days_overdue_only = false) {
        if (!function_exists('wc_get_orders')) {
            return array();
        }

        $incomplete_statuses = array(
            'wc-pending',
            'wc-processing',
            'wc-on-hold',
        );

        $orders = wc_get_orders(array(
            'status'  => $incomplete_statuses,
            'limit'   => -1,
            'orderby' => 'date',
            'order'   => 'DESC',
            'type'    => 'shop_order',
        ));

        if (!$thirty_days_overdue_only) {
            return $orders;
        }

        return array_values(array_filter($orders, array($this, 'is_order_thirty_days_overdue')));
    }

    /**
     * Return whether the 30+ days overdue filter is enabled.
     *
     * @return bool
     */
    private function is_thirty_days_overdue_filter_enabled() {
        return isset($_GET['wco_30_days_overdue']) &&
            sanitize_text_field(wp_unslash($_GET['wco_30_days_overdue'])) === '1';
    }

    /**
     * Return the order-date cutoff for orders 30+ days overdue.
     *
     * Due dates are 30 days after the order date, so orders become 30 days
     * overdue once they are at least 60 days old.
     *
     * @return string Date in Y-m-d format.
     */
    private function get_thirty_days_overdue_cutoff_date() {
        $timezone = function_exists('wp_timezone') ? wp_timezone() : new DateTimeZone('UTC');
        $cutoff   = new DateTimeImmutable('now', $timezone);

        return $cutoff->modify('-' . self::THIRTY_DAYS_OVERDUE_ORDER_AGE_DAYS . ' days')->format('Y-m-d');
    }

    /**
     * Return whether an order is 30+ days overdue.
     *
     * @param WC_Order $order
     * @return bool
     */
    private function is_order_thirty_days_overdue(WC_Order $order) {
        $order_date = $order->get_date_created();

        if (!$order_date) {
            return false;
        }

        return $order_date->date('Y-m-d') <= $this->get_thirty_days_overdue_cutoff_date();
    }

    /**
     * Return the export URL.
     *
     * @param string $format Either csv or xlsx.
     * @param bool   $thirty_days_overdue_only Whether to include the overdue filter.
     * @return string
     */
    private function get_export_url($format = 'xlsx', $thirty_days_overdue_only = false) {
        $format = $format === 'csv' ? 'csv' : 'xlsx';

        $args = array(
            'action' => 'wco_export_' . $format,
            'page'   => 'wc-incomplete-orders',
        );

        if ($thirty_days_overdue_only) {
            $args['wco_30_days_overdue'] = '1';
        }

        return wp_nonce_url(
            add_query_arg($args, admin_url('admin.php')),
            'wco_export'
        );
    }

    /**
     * Build the structured data array used by the table and the export.
     *
     * @param  WC_Order[] $orders
     * @return array
     */
    private function build_order_rows(array $orders) {
        $rows = array();

        foreach ($orders as $order) {
            $order_id = $order->get_id();

            // ── Customer name ─────────────────────────────────────────────
            $first_name = $order->get_billing_first_name();
            $last_name  = $order->get_billing_last_name();
            $customer   = trim($first_name . ' ' . $last_name);
            if ($customer === '') {
                $customer = $order->get_billing_email() ?: '(Guest)';
            }

            // ── Order items ───────────────────────────────────────────────
            $item_names = array();
            foreach ($order->get_items() as $item) {
                /** @var WC_Order_Item_Product $item */
                $qty          = $item->get_quantity();
                $item_names[] = esc_html($item->get_name()) . ($qty > 1 ? ' &times;' . $qty : '');
            }

            // ── Invoice number & PDF link ──────────────────────────
            // Webtoffee PDF Invoices plugin stores:
            //   wf_invoice_number  — the invoice number (may include prefix, e.g. "WC095510")
            $invoice_number = $this->get_invoice_number($order);
            $invoice_url    = $invoice_number ? $this->get_invoice_url($order_id) : '';

            $rows[] = array(
                'order_id'       => $order_id,
                'date'           => $order->get_date_created() ? $order->get_date_created()->date('Y-m-d H:i:s') : '',
                'customer'       => $customer,
                'customer_id'    => $order->get_customer_id(),
                'status'         => $order->get_status(),
                'items'          => $item_names,
                'total'          => $order->get_total(),
                'invoice_number' => $invoice_number,
                'invoice_url'    => $invoice_url,
            );
        }

        return $rows;
    }

    /**
     * Retrieve the invoice number stored by the Webtoffee PDF Invoices plugin.
     *
     * Confirmed meta key from official Webtoffee documentation:
     *   wf_invoice_number  (no leading underscore)
     *
     * This key stores the full invoice number as displayed in the plugin metabox,
     * including any configured prefix/suffix (e.g. "WC095510").
     *
     * @param  WC_Order $order
     * @return string   Invoice number, or empty string if not yet generated.
     */
    private function get_invoice_number(WC_Order $order) {
        $value = $order->get_meta('wf_invoice_number', true);
        if ($value !== '' && $value !== null && $value !== false) {
            return (string) $value;
        }
        return '';
    }

    /**
     * Build the admin URL to download a PDF invoice via the WebToffee plugin.
     *
     * WebToffee's admin helper builds:
     *   wp-admin/?print_packinglist=true&post={order_id}&type=download_invoice&_wpnonce={nonce}
     * with nonce action WF_PKLIST_PLUGIN_NAME.
     *
     * @param  int $order_id
     * @return string
     */
    private function get_invoice_url($order_id) {
        $order_id = absint($order_id);
        if ($order_id <= 0) {
            return '';
        }

        if (
            class_exists('Wf_Woocommerce_Packing_List_Admin') &&
            method_exists('Wf_Woocommerce_Packing_List_Admin', 'get_print_url')
        ) {
            return Wf_Woocommerce_Packing_List_Admin::get_print_url($order_id, 'download_invoice');
        }

        $nonce_action = defined('WF_PKLIST_PLUGIN_NAME')
            ? WF_PKLIST_PLUGIN_NAME
            : 'print-invoices-packing-slip-labels-for-woocommerce';

        return wp_nonce_url(
            admin_url('?print_packinglist=true&post=' . $order_id . '&type=download_invoice'),
            $nonce_action
        );
    }

    /**
     * Return a human-readable, CSS-class-friendly status label.
     *
     * @param  string $raw_status  e.g. "pending", "on-hold"
     * @return array{ label: string, class: string }
     */
    private function status_meta($raw_status) {
        $labels = array(
            'pending'        => array('label' => 'Pending Payment', 'class' => 'status-pending'),
            'processing'     => array('label' => 'Processing',      'class' => 'status-processing'),
            'on-hold'        => array('label' => 'On Hold',         'class' => 'status-on-hold'),
            'cancelled'      => array('label' => 'Cancelled',       'class' => 'status-cancelled'),
            'refunded'       => array('label' => 'Refunded',        'class' => 'status-refunded'),
            'failed'         => array('label' => 'Failed',          'class' => 'status-failed'),
            'checkout-draft' => array('label' => 'Draft',           'class' => 'status-draft'),
        );

        if (isset($labels[$raw_status])) {
            return $labels[$raw_status];
        }

        return array(
            'label' => ucwords(str_replace('-', ' ', $raw_status)),
            'class' => 'status-custom',
        );
    }

    // ──────────────────────────────────────────────────────────────────────────
    // EXPORT
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * Handle export requests triggered by query string
     */
    public function handle_export() {
        $action = isset($_GET['action']) ? sanitize_key(wp_unslash($_GET['action'])) : '';
        $page   = isset($_GET['page']) ? sanitize_key(wp_unslash($_GET['page'])) : '';

        if (
            $page !== 'wc-incomplete-orders' ||
            !in_array($action, array('wco_export_csv', 'wco_export_xlsx'), true)
        ) {
            return;
        }

        if (!current_user_can('manage_woocommerce')) {
            wp_die('Insufficient permissions');
        }

        check_admin_referer('wco_export');

        $orders = $this->get_incomplete_orders($this->is_thirty_days_overdue_filter_enabled());
        $rows   = $this->build_order_rows($orders);

        if ($action === 'wco_export_csv') {
            $this->export_csv($rows);
        }

        $this->export_xlsx($rows);
    }

    /**
     * Export column headers.
     *
     * @return string[]
     */
    private function get_export_headers() {
        return array('Order ID', 'Date', 'Customer', 'Status', 'Product', 'Total', 'Invoice #');
    }

    /**
     * Format one row for CSV/XLSX export.
     *
     * @param array $row
     * @return array
     */
    private function get_export_row(array $row) {
        return array(
            $row['order_id'],
            $row['date'] ? date('Y-m-d', strtotime($row['date'])) : '',
            $row['customer'],
            ucwords(str_replace('-', ' ', $row['status'])),
            implode(', ', array_map('strip_tags', $row['items'])),
            $row['total'],
            $row['invoice_number'],
        );
    }

    /**
     * Export order rows to CSV.
     *
     * @param array $rows
     */
    private function export_csv(array $rows) {
        $filename = 'incomplete-orders-' . date('Y-m-d') . '.csv';

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Cache-Control: max-age=0');

        $output = fopen('php://output', 'w');
        if ($output === false) {
            wp_die('Unable to create CSV export.');
        }

        fwrite($output, "\xEF\xBB\xBF");
        fputcsv($output, $this->get_export_headers());

        foreach ($rows as $row) {
            fputcsv($output, $this->get_export_row($row));
        }

        fclose($output);
        exit;
    }

    /**
     * Return whether any PhpSpreadsheet class/interface/trait is already loaded.
     *
     * Loading a second unprefixed PhpSpreadsheet copy in WordPress can mix class
     * versions with other plugins and cause fatal method signature errors.
     *
     * @return bool
     */
    private function has_loaded_phpspreadsheet_symbols() {
        $symbols = array_merge(get_declared_classes(), get_declared_interfaces());

        if (function_exists('get_declared_traits')) {
            $symbols = array_merge($symbols, get_declared_traits());
        }

        foreach ($symbols as $symbol) {
            if (strpos($symbol, 'PhpOffice\\PhpSpreadsheet\\') === 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * Export order rows to XLSX via PhpSpreadsheet.
     *
     * @param array $rows
     */
    private function export_xlsx(array $rows) {
        $autoload_path = plugin_dir_path(__FILE__) . 'vendor/autoload.php';
        $temp_file     = '';

        try {
            if (!file_exists($autoload_path)) {
                throw new \RuntimeException('PhpSpreadsheet library is not installed. Run: composer require phpoffice/phpspreadsheet');
            }

            $spreadsheet_loaded = class_exists('PhpOffice\PhpSpreadsheet\Spreadsheet');
            $xlsx_writer_loaded = class_exists('PhpOffice\PhpSpreadsheet\Writer\Xlsx');
            $using_bundled      = false;

            if (!$spreadsheet_loaded || !$xlsx_writer_loaded) {
                if ($this->has_loaded_phpspreadsheet_symbols()) {
                    throw new \RuntimeException(
                        'Another plugin has already loaded an incompatible PhpSpreadsheet version. ' .
                        'Please temporarily deactivate that plugin for this export, or use a build of this plugin with PhpSpreadsheet namespace-prefixed.'
                    );
                }

                class_alias(
                    'WC_Overdue_Invoices_ZipStream_Compat',
                    'PhpOffice\PhpSpreadsheet\Writer\ZipStream0'
                );

                require_once $autoload_path;
                $using_bundled = true;
            }

            if (!class_exists('PhpOffice\PhpSpreadsheet\Spreadsheet')) {
                throw new \RuntimeException('PhpSpreadsheet failed to load. Run: composer install');
            }

            if (!class_exists('PhpOffice\PhpSpreadsheet\Writer\Xlsx')) {
                throw new \RuntimeException('PhpSpreadsheet XLSX writer failed to load. Run: composer install');
            }

            if ($using_bundled && !class_exists('PhpOffice\PhpSpreadsheet\Writer\ZipStream0', false)) {
                class_alias(
                    'WC_Overdue_Invoices_ZipStream_Compat',
                    'PhpOffice\PhpSpreadsheet\Writer\ZipStream0'
                );
            }

            $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
            $sheet       = $spreadsheet->getActiveSheet();

            // Headers
            $headers = $this->get_export_headers();
            $columns = array('A', 'B', 'C', 'D', 'E', 'F', 'G');

            foreach ($headers as $index => $header) {
                $sheet->setCellValue($columns[$index] . '1', $header);
            }

            $header_style = array(
                'font' => array('bold' => true),
                'fill' => array(
                    'fillType'   => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID,
                    'startColor' => array('rgb' => 'E8E8E8'),
                ),
            );
            $sheet->getStyle('A1:G1')->applyFromArray($header_style);

            // Data rows
            $row = 2;
            foreach ($rows as $r) {
                foreach ($this->get_export_row($r) as $index => $value) {
                    $sheet->setCellValue($columns[$index] . $row, $value);
                }
                $row++;
            }

            foreach ($columns as $column) {
                $sheet->getColumnDimension($column)->setAutoSize(true);
            }

            $filename  = 'incomplete-orders-' . date('Y-m-d') . '.xlsx';
            $temp_file = wp_tempnam($filename);

            if (!$temp_file) {
                throw new \RuntimeException('Unable to create XLSX export file.');
            }

            $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
            $writer->save($temp_file);

            $file_size = filesize($temp_file);
            if ($file_size === false || $file_size === 0) {
                throw new \RuntimeException('Unable to finalize XLSX export file.');
            }

            while (ob_get_level() > 0) {
                if (!@ob_end_clean()) {
                    break;
                }
            }

            header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
            header('Content-Disposition: attachment; filename="' . $filename . '"');
            header('Content-Length: ' . $file_size);
            header('Cache-Control: max-age=0');

            readfile($temp_file);
            @unlink($temp_file);
            exit;
        } catch (\Throwable $e) {
            if ($temp_file && file_exists($temp_file)) {
                @unlink($temp_file);
            }

            wco_write_export_error_log(
                $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine()
            );

            $message = '<p>Unable to generate XLSX export.</p>' .
                '<p><strong>Error:</strong> ' . esc_html($e->getMessage()) . '</p>' .
                '<p><strong>Location:</strong> ' . esc_html($e->getFile() . ':' . $e->getLine()) . '</p>';

            wp_die($message, 'XLSX Export Error', array('response' => 500));
        }
    }

    // ──────────────────────────────────────────────────────────────────────────
    // DISPLAY
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * Main admin page callback
     */
    public function display_orders_page() {
        if (!function_exists('wc_get_orders')) {
            echo '<div class="notice notice-error"><p>WooCommerce is not active.</p></div>';
            return;
        }

        $column_prefs               = $this->get_column_preferences();
        $thirty_days_overdue_only   = $this->is_thirty_days_overdue_filter_enabled();
        $orders                     = $this->get_incomplete_orders($thirty_days_overdue_only);
        $rows                       = $this->build_order_rows($orders);

        $csv_export_url  = $this->get_export_url('csv', $thirty_days_overdue_only);
        $xlsx_export_url = $this->get_export_url('xlsx', $thirty_days_overdue_only);
        ?>
        <div class="wrap">
            <h1><?php echo esc_html(get_admin_page_title()); ?></h1>
            <p>Showing all orders with a status of <strong>Pending Payment</strong>, <strong>Processing</strong>, or <strong>On Hold</strong>.</p>

            <!-- Controls bar -->
            <div class="usr-controls">
                <div class="usr-export-buttons">
                    <a href="<?php echo esc_url($csv_export_url); ?>" class="button button-secondary">
                        <span class="dashicons dashicons-download"></span> Export CSV
                    </a>
                    <a href="<?php echo esc_url($xlsx_export_url); ?>" class="button button-secondary">
                        <span class="dashicons dashicons-download"></span> Export XLSX
                    </a>
                </div>

                <div class="usr-column-controls">
                    <div class="usr-column-toggles">
                        <strong>Show/Hide Columns:</strong>
                        <?php
                        $toggles = array(
                            'id'       => 'Order ID',
                            'date'     => 'Date',
                            'customer' => 'Customer',
                            'status'   => 'Status',
                            'items'    => 'Product',
                            'total'    => 'Total',
                            'invoice'  => 'Invoice #',
                        );
                        foreach ($toggles as $col_key => $col_label) :
                        ?>
                            <label>
                                <input
                                    type="checkbox"
                                    class="usr-column-toggle"
                                    data-column="<?php echo esc_attr($col_key); ?>"
                                    <?php checked($column_prefs[$col_key]); ?>
                                >
                                <?php echo esc_html($col_label); ?>
                            </label>
                        <?php endforeach; ?>
                    </div>

                    <form class="wco-order-filters" method="get" action="<?php echo esc_url(admin_url('admin.php')); ?>">
                        <input type="hidden" name="page" value="wc-incomplete-orders">
                        <strong>Filters:</strong>
                        <label>
                            <input
                                type="checkbox"
                                class="wco-overdue-filter-toggle"
                                name="wco_30_days_overdue"
                                value="1"
                                <?php checked($thirty_days_overdue_only); ?>
                            >
                            30+ days overdue
                        </label>
                    </form>
                </div>
            </div>

            <div id="wco-table-wrap">
                <?php if (empty($rows)) : ?>
                    <?php if ($thirty_days_overdue_only) : ?>
                        <div class="notice notice-success"><p>No incomplete orders are 30+ days overdue.</p></div>
                    <?php else : ?>
                    <div class="notice notice-success"><p>No incomplete orders found — all orders are completed!</p></div>
                    <?php endif; ?>
                <?php else : ?>
                    <?php $this->render_table($rows, $column_prefs); ?>
                <?php endif; ?>
            </div>

        </div>
        <?php
    }

    /**
     * Render the orders table
     *
     * @param array $rows         Rows from build_order_rows()
     * @param array $column_prefs Visibility preferences
     */
    private function render_table(array $rows, array $column_prefs) {
        // Helper: inline style to hide a column when pref is false
        $hide = function($key) use ($column_prefs) {
            return !$column_prefs[$key] ? ' style="display:none;"' : '';
        };
        ?>
        <table class="wp-list-table widefat fixed striped usr-renewals-table">
            <thead>
                <tr>
                    <th class="usr-col-id"<?php echo $hide('id'); ?>>
                        Order
                    </th>
                    <th class="usr-col-date"<?php echo $hide('date'); ?>>
                        Date
                    </th>
                    <th class="usr-col-customer"<?php echo $hide('customer'); ?>>
                        Customer
                    </th>
                    <th class="usr-col-status"<?php echo $hide('status'); ?>>
                        Status
                    </th>
                    <th class="usr-col-items"<?php echo $hide('items'); ?>>
                        Product
                    </th>
                    <th class="usr-col-total"<?php echo $hide('total'); ?>>
                        Total
                    </th>
                    <th class="usr-col-invoice"<?php echo $hide('invoice'); ?>>
                        Invoice #
                    </th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($rows as $row) :
                    $status_meta = $this->status_meta($row['status']);
                ?>
                    <tr>
                        <!-- Order ID -->
                        <td class="usr-col-id"<?php echo $hide('id'); ?>>
                            <a href="<?php echo esc_url(admin_url('post.php?post=' . $row['order_id'] . '&action=edit')); ?>">
                                #<?php echo absint($row['order_id']); ?>
                            </a>
                        </td>

                        <!-- Date -->
                        <td class="usr-col-date"<?php echo $hide('date'); ?>>
                            <?php echo $row['date'] ? esc_html(date_i18n(get_option('date_format'), strtotime($row['date']))) : '—'; ?>
                        </td>

                        <!-- Customer -->
                        <td class="usr-col-customer"<?php echo $hide('customer'); ?>>
                            <?php if ($row['customer_id']) : ?>
                                <a href="<?php echo esc_url(admin_url('user-edit.php?user_id=' . $row['customer_id'])); ?>">
                                    <?php echo esc_html($row['customer']); ?>
                                </a>
                            <?php else : ?>
                                <?php echo esc_html($row['customer']); ?>
                            <?php endif; ?>
                        </td>

                        <!-- Status badge -->
                        <td class="usr-col-status"<?php echo $hide('status'); ?>>
                            <span class="wco-status-badge <?php echo esc_attr($status_meta['class']); ?>">
                                <?php echo esc_html($status_meta['label']); ?>
                            </span>
                        </td>

                        <!-- Product -->
                        <td class="usr-col-items"<?php echo $hide('items'); ?>>
                            <?php if (!empty($row['items'])) : ?>
                                <ul class="wco-item-list">
                                    <?php foreach ($row['items'] as $item_name) : ?>
                                        <li><?php echo wp_kses($item_name, array('strong' => array())); ?></li>
                                    <?php endforeach; ?>
                                </ul>
                            <?php else : ?>
                                <em>No product</em>
                            <?php endif; ?>
                        </td>

                        <!-- Total -->
                        <td class="usr-col-total"<?php echo $hide('total'); ?>>
                            <?php echo wc_price($row['total']); ?>
                        </td>

                        <!-- Invoice -->
                        <td class="usr-col-invoice"<?php echo $hide('invoice'); ?>>
                            <?php if ($row['invoice_number'] && $row['invoice_url']) : ?>
                                <a
                                    href="<?php echo esc_url($row['invoice_url']); ?>"
                                    target="_blank"
                                    rel="noopener"
                                    class="wco-invoice-link"
                                    title="Download PDF invoice"
                                >
                                    <span class="dashicons dashicons-pdf"></span>
                                    <span class="wco-invoice-number"><?php echo esc_html($row['invoice_number']); ?></span>
                                </a>
                            <?php elseif ($row['invoice_number']) : ?>
                                <?php echo esc_html($row['invoice_number']); ?>
                            <?php else : ?>
                                <span class="wco-no-invoice">—</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
            <tfoot>
                <tr>
                    <td colspan="7">
                        <strong>Total shown incomplete orders: <?php echo count($rows); ?></strong>
                    </td>
                </tr>
            </tfoot>
        </table>
        <?php
    }
}

// Initialise
function wc_incomplete_orders_init() {
    new WC_Incomplete_Orders();
}
add_action('plugins_loaded', 'wc_incomplete_orders_init');
