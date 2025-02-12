<?php
class SeatsioTickeraEvents
{
    private $api_key;
    private $api_base_url = 'https://api-eu.seatsio.net';

    public function __construct()
    {
        $this->api_key = get_option('seatsio_secret_key');
        $this->init_hooks();
    }

    private function init_hooks()
    {
        add_action('init', [$this, 'create_events_from_seatsio']);

        // Optional: Add hook for manual sync trigger
        add_action('admin_post_sync_seatsio_events', [$this, 'handle_manual_sync']);
        add_action('admin_post_nopriv_sync_seatsio_events', [$this, 'handle_manual_sync']);
    }

    /**
     * Fetch events from Seats.io API
     *
     * @return array Array of events from Seats.io
     */
    public function fetch_events()
    {
        if (!$this->api_key) {
            return [];
        }

        $auth = base64_encode($this->api_key . ':');
        $response = wp_remote_get(
            "{$this->api_base_url}/events",
            [
                'headers' => [
                    'Authorization' => 'Basic ' . $auth
                ]
            ]
        );

        if (is_wp_error($response)) {
            $this->log_error('Failed to fetch events: ' . $response->get_error_message());
            return [];
        }

        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        return $data['items'] ?? [];
    }

    /**
     * Create or update Tickera events based on Seats.io events
     */
    public function create_events_from_seatsio()
    {
        $seatsio_events = $this->fetch_events();

        foreach ($seatsio_events as $event) {
            $this->process_single_event($event);
        }
    }

    /**
     * Process a single Seats.io event
     *
     * @param array $event Event data from Seats.io
     * @return int|false Post ID on success, false on failure
     */
    private function process_single_event($event)
    {
        $event_name = $event['name'] ?? "Event " . $event['id'];
        $event_key = $event['key'];

        // Check for existing event
        $existing_event = $this->get_existing_event($event_name);

        if ($existing_event) {
            return $this->update_existing_event($existing_event, $event_key);
        }

        return $this->create_new_event($event_name, $event_key);
    }

    /**
     * Get existing event by title
     *
     * @param string $event_name Event title
     * @return WP_Post|false Post object if exists, false otherwise
     */
    private function get_existing_event($event_name)
    {
        return get_page_by_title($event_name, OBJECT, 'tc_events');
    }

    /**
     * Update existing event with new data
     *
     * @param WP_Post $existing_event Existing event post object
     * @param string $event_key Seats.io event key
     * @return int Post ID
     */
    private function update_existing_event($existing_event, $event_key)
    {
        update_post_meta($existing_event->ID, '_seatsio_event_key', $event_key);

        // Optional: Update other event metadata here

        return $existing_event->ID;
    }

    /**
     * Create new Tickera event
     *
     * @param string $event_name Event title
     * @param string $event_key Seats.io event key
     * @return int|false Post ID on success, false on failure
     */
    private function create_new_event($event_name, $event_key)
    {
        $tickera_event = [
            'post_title'  => $event_name,
            'post_status' => 'publish',
            'post_type'   => 'tc_events'
        ];

        $event_id = wp_insert_post($tickera_event);

        if (!$event_id) {
            $this->log_error('Failed to create event: ' . $event_name);
            return false;
        }

        update_post_meta($event_id, '_seatsio_event_key', $event_key);

        return $event_id;
    }

    /**
     * Handle manual sync request
     */
    public function handle_manual_sync()
    {
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }

        $this->create_events_from_seatsio();

        wp_redirect(admin_url('edit.php?post_type=tc_events'));
        exit;
    }

    /**
     * Log error messages
     *
     * @param string $message Error message to log
     */
    private function log_error($message)
    {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('[SeatsioTickera] ' . $message);
        }
    }

    /**
     * Get event key for a Tickera event
     *
     * @param int $event_id Tickera event ID
     * @return string|false Event key if exists, false otherwise
     */
    public function get_event_key($event_id)
    {
        return get_post_meta($event_id, '_seatsio_event_key', true);
    }
}

// Initialize the events management
$seatsio_tickera_events = new SeatsioTickeraEvents();
