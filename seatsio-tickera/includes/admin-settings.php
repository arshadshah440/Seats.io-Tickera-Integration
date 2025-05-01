<?php
// Main class for integrating Seats.io with Tickera (and optionally WooCommerce)
class SeatsioTickeraIntegration
{
    private $plugin_path;
    private $plugin_url;

    // Constructor: set plugin path & URL and initialize hooks
    public function __construct()
    {
        $this->plugin_path = plugin_dir_path(__FILE__);
        $this->plugin_url = plugin_dir_url(__FILE__);

        // Register hooks
        $this->init_hooks();
    }

    // Register all WordPress hooks used by the plugin
    private function init_hooks()
    {
        add_action('admin_menu', [$this, 'register_menu_pages']); // Adds plugin admin menu
        add_action('admin_init', [$this, 'register_settings']);   // Registers plugin settings
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_assets']); // Admin CSS/JS
        add_action('wp_enqueue_scripts', [$this, 'enqueue_frontend_assets']); // Frontend CSS
        add_action('wp_ajax_seatsio_save_mappings_ajax', [$this, 'save_mappings_ajax']); // AJAX for logged-in users
        add_action('wp_ajax_nopriv_seatsio_save_mappings_ajax', [$this, 'save_mappings_ajax']); // AJAX for guests
    }

    // Add menu and submenu pages in the WordPress admin
    public function register_menu_pages()
    {
        add_menu_page(
            'Seats.io Tickera Integration',
            'Seats.io Tickera',
            'manage_options',
            'seatsio_tickera',
            [$this, 'render_settings_page'],
            'dashicons-admin-generic',
            100
        );

        add_submenu_page(
            'seatsio_tickera',
            "Seats.io Ticket Mapping",
            'Ticket Mapping',
            'manage_options',
            'seatsio_ticket_mapping',
            [$this, 'render_mapping_page'],
            100
        );
    }

    // Register API settings (public & secret keys)
    public function register_settings()
    {
        register_setting('seatsio_tickera_options', 'seatsio_public_key');
        register_setting('seatsio_tickera_options', 'seatsio_secret_key');

        add_settings_section('seatsio_tickera_main', 'API Settings', null, 'seatsio_tickera');

        // Public Key field
        add_settings_field('seatsio_public_key', 'Seats.io Public Key', function () {
            echo '<input type="text" name="seatsio_public_key" value="' . get_option('seatsio_public_key') . '" class="regular-text">';
        }, 'seatsio_tickera', 'seatsio_tickera_main');

        // Secret Key field
        add_settings_field('seatsio_secret_key', 'Seats.io Secret Key', function () {
            echo '<input type="text" name="seatsio_secret_key" value="' . get_option('seatsio_secret_key') . '" class="regular-text">';
        }, 'seatsio_tickera', 'seatsio_tickera_main');
    }

    // Output the settings page HTML
    public function render_settings_page()
    {
?>
        <div class="wrap">
            <h2>Seats.io Tickera Integration Settings</h2>
            <form method="post" action="options.php">
                <?php
                settings_fields('seatsio_tickera_options');
                do_settings_sections('seatsio_tickera');
                submit_button();
                ?>
            </form>
        </div>
<?php
    }

    // Output the ticket mapping page
    public function render_mapping_page()
    {
        $api_key = get_option('seatsio_secret_key');
        $event_key = 'e9643937-6882-41f5-ab64-e888d6914db4'; // Replace with dynamic key

        // Fetch event and global categories from Seats.io
        $seatsio_categories = $this->fetch_categories($api_key, $event_key);
        $seatsio_all_categories = $this->get_all_seatsio_categories($api_key);

        // Fetch ticket types (WooCommerce or Tickera)
        $tickera_tickets = $this->is_bridge_active() ? $this->get_woo_tickets() : $this->get_tickera_tickets();

        // Get previously saved mappings
        $saved_mappings = get_option('seatsio_tickera_mappings', []);

        // Save mappings on POST
        if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['seatsio_mapping_submit'])) {
            $this->save_mappings();
        }

        // Include the mapping page template
        include($this->plugin_path . 'templates/mapping-page.php');
    }

    // Fetch event-specific categories from Seats.io API
    private function fetch_categories($api_key, $event_key)
    {
        $url = "https://api-eu.seatsio.net/events/{$event_key}";

        $response = wp_remote_get($url, [
            'headers' => [
                'Authorization' => 'Basic ' . base64_encode($api_key . ':')
            ],
        ]);

        if (is_wp_error($response)) {
            error_log('Seats.io API Error: ' . $response->get_error_message());
            return [];
        }

        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        if (empty($data['categories'])) {
            return [];
        }

        // Format categories
        $categories = [];
        foreach ($data['categories'] as $category) {
            $categories[$category['key']] = [
                'label' => $category['label'],
                'color' => $category['color']
            ];
        }

        return $categories;
    }

    // Get all categories across all charts from Seats.io
    private function get_all_seatsio_categories($api_key)
    {
        $base_url = 'https://api-eu.seatsio.net/';
        $charts_url = $base_url . '/charts';

        // Fetch all charts
        $charts_response = wp_remote_get($charts_url, [
            'headers' => [
                'Authorization' => 'Basic ' . base64_encode($api_key . ':')
            ],
        ]);

        if (is_wp_error($charts_response)) {
            return 'Error fetching charts: ' . $charts_response->get_error_message();
        }

        $charts_body = wp_remote_retrieve_body($charts_response);
        $charts_data = json_decode($charts_body, true);

        if (empty($charts_data['items'])) {
            return 'No charts found.';
        }

        $all_categories = [];

        // Loop through each chart and get its categories
        foreach ($charts_data['items'] as $chart) {
            $chart_key = $chart['key'];
            $categories_url = $base_url . '/charts/' . $chart_key . '/categories';

            $categories_response = wp_remote_get($categories_url, [
                'headers' => [
                    'Authorization' => 'Basic ' . base64_encode($api_key . ':')
                ],
            ]);

            if (is_wp_error($categories_response)) {
                error_log('Error fetching categories for chart ' . $chart_key . ': ' . $categories_response->get_error_message());
                continue;
            }

            $categories_body = wp_remote_retrieve_body($categories_response);
            $categories_data = json_decode($categories_body, true);

            if (!empty($categories_data)) {
                $all_categories = array_merge($all_categories, $categories_data);
            }
        }

        // Deduplicate categories by key
        $unique_categories = [];
        foreach ($all_categories as $category) {
            $unique_categories[$category['key']] = $category;
        }

        return array_values($unique_categories);
    }

    // Get ticket types from Tickera (if WooCommerce bridge is not active)
    private function get_tickera_tickets()
    {
        $ticket_types = get_posts([
            'post_type' => 'tc_tickets',
            'posts_per_page' => -1,
            'post_status' => 'publish'
        ]);

        $tickets = [];

        if (!empty($ticket_types)) {
            foreach ($ticket_types as $ticket) {
                $price = get_post_meta($ticket->ID, 'price_per_ticket', true);

                $tickets[] = [
                    'id' => $ticket->ID,
                    'title' => $ticket->post_title,
                    'price' => !empty($price) ? $price : '0.00',
                ];
            }
        }

        return $tickets;
    }

    // Check if WooCommerce-Tickera bridge is active
    private function is_bridge_active()
    {
        if (!function_exists('is_plugin_active')) {
            include_once(ABSPATH . 'wp-admin/includes/plugin.php');
        }
        return is_plugin_active('bridge-for-woocommerce/bridge-for-woocommerce.php');
    }

    // Get ticket types from WooCommerce products
    private function get_woo_tickets()
    {
        $ticket_products = [];

        $args = [
            'post_type' => 'product',
            'posts_per_page' => -1,
            'meta_query' => [
                [
                    'key' => '_tc_is_ticket',
                    'value' => 'yes',
                ],
            ],
        ];

        $query = new WP_Query($args);

        if ($query->have_posts()) {
            while ($query->have_posts()) {
                $query->the_post();
                $product_id = get_the_ID();
                $product = wc_get_product($product_id);

                $ticket_products[] = [
                    'id' => $product_id,
                    'title' => $product->get_name(),
                    'price' => $product->get_price(),
                ];
            }
            wp_reset_postdata();
        }

        return $ticket_products;
    }

    // Save mappings submitted via form (non-AJAX)
    public function save_mappings()
    {
        if (isset($_POST['seatsio_mapping']) && is_array($_POST['seatsio_mapping'])) {
            $mappings = array_map('sanitize_text_field', $_POST['seatsio_mapping']);
            update_option('seatsio_tickera_mappings', $mappings);
        }
    }

    // Save mappings submitted via AJAX
    public function save_mappings_ajax()
    {
        if (isset($_POST['seatsio_mapping']) && is_array($_POST['seatsio_mapping'])) {
            $mappings = array_map('sanitize_text_field', $_POST['seatsio_mapping']);
            update_option('seatsio_tickera_mappings', $mappings);
            wp_send_json_success(['message' => 'Mappings saved successfully']);
        } else {
            wp_send_json_error(['message' => 'Invalid data']);
        }
        wp_die(); // Required to properly end AJAX response
    }

    // Enqueue styles and scripts in the admin panel
    public function enqueue_admin_assets()
    {
        $css_file = $this->plugin_path . 'assets/css/style.css';
        $js_file = $this->plugin_path . 'assets/js/script.js';

        $css_version = file_exists($css_file) ? filemtime($css_file) : '1.0.0';
        $js_version = file_exists($js_file) ? filemtime($js_file) : '1.0.0';

        wp_enqueue_style(
            'seats-io-style',
            $this->plugin_url . 'assets/css/style.css',
            array(),
            $css_version,
            'all'
        );

        wp_enqueue_script(
            'seats-io-script',
            $this->plugin_url . 'assets/js/script.js',
            array('jquery'),
            $js_version,
            true
        );
    }

    // Enqueue styles for the frontend (if needed)
    public function enqueue_frontend_assets()
    {
        wp_enqueue_style(
            'frontendcss',
            $this->plugin_url . 'assets/css/frontend.css',
            array(),
            filemtime($this->plugin_path . 'assets/css/frontend.css'),
            'all'
        );
    }
}

// Initialize the plugin class
$seatsio_tickera = new SeatsioTickeraIntegration();
