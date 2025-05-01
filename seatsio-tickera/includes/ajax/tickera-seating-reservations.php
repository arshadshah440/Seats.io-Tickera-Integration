<?php
/**
 * SeatsioCartManager Class
 * 
 * This class manages integration between Seats.io seat booking system and 
 * e-commerce platforms (WooCommerce and Tickera) in WordPress.
 * It handles seat selection, cart management, and booking confirmation.
 */
class SeatsioCartManager
{
    /**
     * Seats.io API key for authentication
     * @var string
     */
    private $api_key;
    
    /**
     * Seats.io API base URL for EU region
     * @var string
     */
    private $api_base_url = 'https://api-eu.seatsio.net';
    
    /**
     * Global Tickera object reference
     * @var object
     */
    private $tc; // Tickera global object
    
    /**
     * Constructor - Initialize hooks and load dependencies
     */
    public function __construct()
    {
        global $tc;
        $this->tc = $tc;
        // Get API key from WordPress options
        $this->api_key = get_option('seatsio_secret_key');
        // Initialize WordPress hooks
        $this->init_hooks();
        // Start PHP session for data persistence
        $this->start_session();
    }
    
    /**
     * Initialize WordPress hooks for AJAX, cart display, and order processing
     */
    private function init_hooks()
    {
        // AJAX hooks for seat selection and cart operations
        add_action('wp_ajax_show_seats_details', [$this, 'show_seats_details']);
        add_action('wp_ajax_nopriv_show_seats_details', [$this, 'show_seats_details']);
        add_action('wp_ajax_my_custom_add_to_cart', [$this, 'handle_custom_add_to_cart']);
        add_action('wp_ajax_nopriv_my_custom_add_to_cart', [$this, 'handle_custom_add_to_cart']);
        
        // Cart display hooks - show seat information in cart
        add_filter('tc_cart_col_before_ticket_name', [$this, 'display_event_and_seat_details_in_cart'], 10, 2);
        add_filter('woocommerce_get_item_data', [$this, 'display_seat_ids_in_cart'], 10, 2);
        
        // Order creation hooks - handle booking seats when orders are created
        add_action('tc_order_created', [$this, 'handle_tickera_order_creation']);
        add_action('woocommerce_checkout_create_order_line_item', [$this, 'add_seat_ids_to_order_items'], 10, 4);
        add_action('woocommerce_thankyou', [$this, 'book_seats_on_order_creation']);
    }
    
    /**
     * Start PHP session if not already started
     * Used for temporarily storing seat information
     */
    private function start_session()
    {
        if (!session_id()) {
            session_start();
        }
    }
    
    /**
     * AJAX handler for showing seat details
     * Returns ticket type details for selected seats
     */
    public function show_seats_details()
    {
        // Get seats data from AJAX request
        $seats = isset($_POST['seats']) ? $_POST['seats'] : [];
        if (empty($seats)) {
            wp_send_json_error(['error' => 'No seats selected.']);
        }
        
        // Get saved mappings between seat categories and ticket types
        $saved_mappings = (array) get_option('seatsio_tickera_mappings', []);
        if (empty($saved_mappings)) {
            wp_send_json_error(['error' => 'No mappings found.']);
        }
        
        // Get mapped ticket information for selected seats
        $mapped_ticket_type = $this->get_mapped_tickets($seats, $saved_mappings);
        wp_send_json_success($mapped_ticket_type);
    }
    
    /**
     * Map seats to ticket types using saved mappings
     * 
     * @param array $seats Array of seat objects
     * @param array $saved_mappings Mappings between seat categories and ticket IDs
     * @return array Ticket information for each seat
     */
    private function get_mapped_tickets($seats, $saved_mappings)
    {
        // Initialize result array
        $mapped_ticket_type = [[
            'saved_mappings' => $saved_mappings
        ]];
        
        // Get currency symbol from Tickera settings
        $tc_settings = (array) get_option('tickera_general_setting', []);
        $currency_symbol = $tc_settings['currency_symbol'] ?? '$';
        
        // Process each seat
        foreach ($seats as $seat) {
            // Skip seats without category or mapping
            if (!isset($seat['category_key']) || !isset($saved_mappings[$seat['category_key']])) {
                continue;
            }
            
            // Get ticket ID from mapping
            $ticket_id = $saved_mappings[$seat['category_key']];
            // Get price and title for the ticket
            $price = $this->get_ticket_price($ticket_id);
            $title = get_the_title($ticket_id);
            
            // Add ticket information to result
            $mapped_ticket_type[] = [
                'ticket_id' => $ticket_id,
                'ticket_price' => $currency_symbol . $price,
                'category_key' => $seat['category_key'],
                'saved_mappings' => $saved_mappings
            ];
        }
        
        return $mapped_ticket_type;
    }
    
    /**
     * Get ticket price based on ticket ID
     * Handles both WooCommerce products and Tickera tickets
     * 
     * @param int $ticket_id Ticket or product ID
     * @return string Price formatted as string
     */
    private function get_ticket_price($ticket_id)
    {
        // Handle WooCommerce products
        if (get_post_type($ticket_id) == 'product') {
            $product = wc_get_product($ticket_id);
            return $product ? $product->get_price() : '0.00';
        }
        
        // Handle Tickera tickets
        return get_post_meta($ticket_id, 'price_per_ticket', true) ?: '0.00';
    }
    
    /**
     * AJAX handler for adding seats to cart
     * Processes both WooCommerce and Tickera items
     */
    public function handle_custom_add_to_cart()
    {
        try {
            // Get event ID from request
            $event_id = !empty($_POST['event_id']) ? ($_POST['event_id']) : 1;
            
            // Sort items into WooCommerce and Tickera arrays
            list($woo_products, $tickera_tickets) = $this->sort_cart_items($event_id);
            
            // Process WooCommerce and Tickera items
            $this->process_woo_products($woo_products);
            $this->process_tickera_tickets($tickera_tickets);
            
            wp_send_json_success(['message' => 'Items added to cart successfully.']);
        } catch (Exception $e) {
            wp_send_json_error(['message' => 'Error adding items to cart: ' . $e->getMessage()]);
        }
    }
    
    /**
     * Sort cart items into WooCommerce products and Tickera tickets
     * 
     * @param int $event_id The event ID
     * @return array Two arrays containing WooCommerce products and Tickera tickets
     */
    private function sort_cart_items($event_id)
    {
        $woo_products = [];
        $tickera_tickets = [];
        
        // Handle multiple ticket IDs
        if (isset($_POST['ticket_ids']) && is_array($_POST['ticket_ids'])) {
            foreach ($_POST['ticket_ids'] as $index => $ticket_id) {
                $this->add_to_sorted_arrays(
                    $woo_products,
                    $tickera_tickets,
                    $ticket_id,
                    $_POST['tc_qty'][$index] ?? 1,
                    $_POST['custom_meta'][$index] ?? '',
                    $_POST['seat_keys'][$ticket_id] ?? [],
                    $event_id
                );
            }
        } 
        // Handle single ticket ID
        elseif (isset($_POST['ticket_id'])) {
            $this->add_to_sorted_arrays(
                $woo_products,
                $tickera_tickets,
                $_POST['ticket_id'],
                $_POST['tc_qty'] ?? 1,
                $_POST['custom_meta'] ?? '',
                $_POST['seat_keys'] ?? [],
                $event_id
            );
        }
        
        return [$woo_products, $tickera_tickets];
    }
    
    /**
     * Add ticket/product to appropriate array based on post type
     * 
     * @param array &$woo_products Reference to WooCommerce products array
     * @param array &$tickera_tickets Reference to Tickera tickets array
     * @param int $ticket_id Ticket or product ID
     * @param int $quantity Quantity of tickets
     * @param string $custom_meta Custom metadata
     * @param array $seats Selected seat IDs
     * @param int $event_id Event ID
     */
    private function add_to_sorted_arrays(&$woo_products, &$tickera_tickets, $ticket_id, $quantity, $custom_meta, $seats, $event_id)
    {
        // Create item array with common properties
        $item = [
            'quantity' => (int)$quantity,
            'custom_meta' => sanitize_text_field($custom_meta),
            'seats' => (array)$seats,
            'event_id' => $event_id,
        ];
        
        // Add to appropriate array based on post type
        if (get_post_type($ticket_id) === 'product') {
            $item['product_id'] = (int)$ticket_id;
            $woo_products[] = $item;
        } else {
            $item['ticket_id'] = (int)$ticket_id;
            $tickera_tickets[] = $item;
        }
    }
    
    /**
     * Original WooCommerce product processing method (commented out)
     * This was replaced by the implementation below to handle seats individually
     */
    // private function process_woo_products($products)
    // {
    //     foreach ($products as $product) {
    //         WC()->cart->add_to_cart(
    //             $product['product_id'],
    //             $product['quantity'],
    //             0,
    //             [],
    //             [
    //                 'custom_meta' => $product['custom_meta'],
    //                 'seats' => $product['seats'],
    //                 // 'event_id' => $product['event_id'],
    //             ]
    //         );
    //     }
    // }
    
    /**
     * Process WooCommerce products for the cart
     * Adds each seat as a separate cart item to avoid duplicate seats
     * 
     * @param array $products Array of product information
     */
    private function process_woo_products($products)
    {
        // Track added seats to avoid duplicates
        $added_seats = [];
        
        // Get the current cart contents
        $cart = WC()->cart->get_cart();
        
        // Scan the cart for existing seats
        foreach ($cart as $cart_item_key => $cart_item) {
            if (isset($cart_item['seats'])) {
                foreach ($cart_item['seats'] as $seat) {
                    $added_seats[] = $seat; // Add existing seats to the tracking array
                }
            }
        }
        
        // Process new products
        foreach ($products as $product) {
            // Get the seats for the current product
            $seats = $product['seats'];
            
            // Loop through each seat
            foreach ($seats as $seat) {
                // Check if the seat has already been added (either in this session or in the cart)
                if (in_array($seat, $added_seats)) {
                    continue; // Skip this seat if it's already added
                }
                
                // Add the seat to the list of added seats
                $added_seats[] = $seat;
                
                // Add a new product to the cart for this seat
                WC()->cart->add_to_cart(
                    $product['product_id'], // Product ID
                    1, // Quantity (1 seat per product)
                    0, // Variation ID (if applicable)
                    [], // Variation data (if applicable)
                    [
                        'custom_meta' => $product['custom_meta'], // Custom meta data
                        'seats' => [$seat], // Add only this seat
                        'event_id' => $product['event_id'], // Event ID (if needed)
                    ]
                );
            }
        }
    }
    
    /**
     * Process Tickera tickets for the cart
     * 
     * @param array $tickets Array of ticket information
     */
    private function process_tickera_tickets($tickets)
    {
        if (empty($tickets)) {
            return;
        }
        
        // Get current Tickera cart
        $cart = $this->tc->get_cart_cookie(true) ?: [];
        
        foreach ($tickets as $ticket) {
            $ticket_id = $ticket['ticket_id'];
            
            // Update existing cart item or add new one
            if (isset($cart[$ticket_id])) {
                // Convert simple quantity to array if needed
                if (!is_array($cart[$ticket_id])) {
                    $cart[$ticket_id] = [
                        'quantity' => (int)$cart[$ticket_id],
                        'custom_meta' => '',
                        'seats' => $ticket['seats'],
                        'event_id' => $ticket['event_id'],
                    ];
                }
                // Update quantity and meta
                $cart[$ticket_id]['quantity'] += $ticket['quantity'];
                $cart[$ticket_id]['custom_meta'] = $ticket['custom_meta'];
            } else {
                // Add new item to cart
                $cart[$ticket_id] = $ticket;
            }
            
            // Store ticket data in session for later use
            $_SESSION['ticket_data'][$ticket_id] = [
                'seats' => $ticket['seats'],
                'event_id' => $ticket['event_id'],
            ];
        }
        
        // Update Tickera cart and apply discounts
        $this->tc->set_cart_cookie($cart);
        $discount = new TC_Discounts();
        $discount->discounted_cart_total();
        $this->tc->save_cart_post_data();
    }
    
    /**
     * Display event and seat details in Tickera cart
     * 
     * @param string $cart_item_key Cart item key
     * @param mixed $cart_item Cart item data
     * @return string HTML to display
     */
    public function display_event_and_seat_details_in_cart($cart_item_key, $cart_item)
    {
        // Get Tickera cart cookie
        $cookie_id = 'tc_cart_' . COOKIEHASH;
        if (!isset($_COOKIE[$cookie_id])) {
            return '';
        }
        
        // Parse cart data
        $cart_obj = tc_sanitize_array2(json_decode(stripslashes($_COOKIE[$cookie_id]), true));
        if (empty($cart_obj) || !isset($cart_obj[$cart_item])) {
            return '';
        }
        
        $cart_item = $cart_obj[$cart_item];
        
        // Build seat information string
        $seats_string = '';
        if (isset($cart_item['seats']) && is_array($cart_item['seats'])) {
            $seats_string = ' - ' . implode(', ', $cart_item['seats']);
        }
        
        // Return formatted HTML
        return '<p>' . esc_html($cart_item_key . $seats_string) . '</p>';
    }
    
    /**
     * Display seat IDs in WooCommerce cart
     * 
     * @param array $item_data Existing item data
     * @param array $cart_item Cart item
     * @return array Updated item data
     */
    public function display_seat_ids_in_cart($item_data, $cart_item)
    {
        // Add seat IDs if available
        if (!empty($cart_item['seats'])) {
            $item_data[] = [
                'key' => __('Seat IDs', 'seatsio'),
                'value' => wc_clean(implode(', ', $cart_item['seats'])),
                'display' => '',
            ];
        }
        
        // Add event ID if available
        if (!empty($cart_item['event_id'])) {
            $item_data[] = [
                'key' => __('Event IDs', 'seatsio'),
                'value' => wc_clean($cart_item['event_id']),
                'display' => '',
            ];
        }
        
        return $item_data;
    }
    
    /**
     * Add seat IDs to WooCommerce order items as meta data
     * 
     * @param WC_Order_Item_Product $item Order item
     * @param string $cart_item_key Cart item key
     * @param array $values Cart item values
     * @param WC_Order $order Order object
     */
    public function add_seat_ids_to_order_items($item, $cart_item_key, $values, $order)
    {
        // Add seat IDs as meta data
        if (!empty($values['seats'])) {
            $item->add_meta_data(__('Seat IDs', 'seatsio'), implode(', ', $values['seats']));
            $item->add_meta_data('seats', $values['seats']);
        }
        
        // Add event ID as meta data
        if (!empty($values['event_id'])) {
            $item->add_meta_data(__('Event IDs', 'seatsio'), $values['event_id']);
        }
    }
    
    /**
     * Handle Tickera order creation
     * Creates a post to store order details and clears session data
     * 
     * @param string $order_id Tickera order ID
     */
    public function handle_tickera_order_creation($order_id)
    {
        $order_id = strtoupper($order_id);
        $order = tc_get_order_id_by_name($order_id);
        
        // Check if order exists and session data is available
        if (!$order || empty($_SESSION['ticket_data'])) {
            return;
        }
        
        // Collect seat data from session
        $seats_data = [];
        foreach ($_SESSION['ticket_data'] as $ticket_id => $data) {
            $seats_data[] = $data['seats'];
        }
        
        // Create post with order details
        $this->create_order_details_post($order_id, $seats_data);
        
        // Clear session data
        unset($_SESSION['ticket_data']);
    }
    
    /**
     * Create a post to store order details
     * 
     * @param string $order_id Order ID
     * @param array $seats_data Seat data for the order
     */
    private function create_order_details_post($order_id, $seats_data)
    {
        // Post data
        $post_data = [
            'post_title' => 'Order Details - ' . $order_id,
            'post_content' => print_r($seats_data, true),
            'post_status' => 'publish',
            'post_type' => 'post'
        ];
        
        // Insert post
        $post_id = wp_insert_post($post_data);
        if (is_wp_error($post_id)) {
            error_log("Failed to create post for order ID: " . $order_id);
        }
    }
    
    /**
     * Book seats in Seats.io when a WooCommerce order is created
     * 
     * @param int $order_id WooCommerce order ID
     */
    public function book_seats_on_order_creation($order_id)
    {
        $order = wc_get_order($order_id);
        error_log("Order from thanks page ID: " . $order_id);
        
        // Process each order item
        foreach ($order->get_items() as $item) {
            $event_key = $item->get_meta('event_id');
            $seat_ids = $item->get_meta('seats');
            
            // Book seats if event and seats are available
            if ($seat_ids && $event_key) {
                $this->book_seats($event_key, $seat_ids);
            }
        }
    }
    
    /**
     * Book seats in Seats.io via API
     * 
     * @param string $event_key Event key in Seats.io
     * @param array $seat_ids Array of seat IDs to book
     * @return bool|WP_Error True on success or WP_Error on failure
     */
    private function book_seats($event_key, $seat_ids)
    {
        // Check if API key is available
        if (empty($this->api_key)) {
            return new WP_Error('missing_api_key', 'Seats.io API key is required.');
        }
        
        // Make API request to book seats
        $response = wp_remote_post(
            "{$this->api_base_url}/events/{$event_key}/actions/book",
            [
                'method' => 'POST',
                'body' => wp_json_encode(['objects' => $seat_ids]),
                'headers' => [
                    'Authorization' => 'Basic ' . base64_encode($this->api_key . ':')
                ],
                'timeout' => 30,
            ]
        );
        
        // Handle request errors
        if (is_wp_error($response)) {
            error_log("Seats.io booking error: " . $response->get_error_message());
            return $response;
        }
        
        // Handle API response errors
        $response_code = wp_remote_retrieve_response_code($response);
        if ($response_code !== 200) {
            error_log("Seats.io booking failed: " . wp_remote_retrieve_body($response));
            return new WP_Error('booking_failed', 'Failed to book seats');
        }
        
        return true;
    }
}

// Initialize the cart manager
$seatsio_cart_manager = new SeatsioCartManager();