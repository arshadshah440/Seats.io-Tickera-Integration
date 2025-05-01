<?php
/**
 * Class SeatsioTickeraEvents
 *
 * Synchronizes events from the Seats.io API and creates or updates corresponding Tickera events.
 */
class SeatsioTickeraEvents
{
    /**
     * @var string|null Seats.io API key
     */
    private $api_key;

    /**
     * @var string Base URL for the Seats.io API
     */
    private $api_base_url = 'https://api-eu.seatsio.net';

    /**
     * SeatsioTickeraEvents constructor.
     */
    public function __construct()
    {
        $this->api_key = get_option('seatsio_secret_key');
        $this->init_hooks();
    }

    /**
     * Initialize WordPress hooks.
     */
    private function init_hooks()
    {
        add_action('init', [$this, 'create_events_from_seatsio']);
        add_action('admin_post_sync_seatsio_events', [$this, 'handle_manual_sync']);
        add_action('admin_post_nopriv_sync_seatsio_events', [$this, 'handle_manual_sync']);
    }

    /**
     * Fetch events from the Seats.io API.
     *
     * @return array Array of Seats.io event data
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
     * Loop through all Seats.io events and create or update Tickera events accordingly.
     */
    public function create_events_from_seatsio()
    {
        $seatsio_events = $this->fetch_events();

        foreach ($seatsio_events as $event) {
            $this->process_single_event($event);
        }
    }

    /**
     * Process a single Seats.io event to create or update a Tickera event.
     *
     * @param array $event Event data from Seats.io
     * @return int|false Post ID on success, false on failure
     */
    private function process_single_event($event)
    {
        $event_name = $event['name'] ?? "Event " . $event['id'];
        $event_key = $event['key'];

        $existing_event = $this->get_existing_event($event_name);

        if ($existing_event) {
            return $this->update_existing_event($existing_event, $event_key);
        }

        return $this->create_new_event($event_name, $event_key);
    }

    /**
     * Retrieve existing Tickera event by title.
     *
     * @param string $event_name Event title
     * @return WP_Post|false WP_Post object if found, false otherwise
     */
    private function get_existing_event($event_name)
    {
        return get_page_by_title($event_name, OBJECT, 'tc_events');
    }

    /**
     * Update an existing Tickera event's meta data.
     *
     * @param WP_Post $existing_event The existing event post object
     * @param string $event_key Seats.io event key
     * @return int Post ID
     */
    private function update_existing_event($existing_event, $event_key)
    {
        update_post_meta($existing_event->ID, '_seatsio_event_key', $event_key);
        return $existing_event->ID;
    }

    /**
     * Create a new Tickera event based on Seats.io data.
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
     * Manually trigger a sync from the WordPress admin.
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
     * Log error messages to the debug log.
     *
     * @param string $message Error message
     */
    private function log_error($message)
    {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('[SeatsioTickera] ' . $message);
        }
    }

    /**
     * Retrieve the Seats.io event key for a specific Tickera event.
     *
     * @param int $event_id The Tickera event post ID
     * @return string|false Event key if available, false otherwise
     */
    public function get_event_key($event_id)
    {
        return get_post_meta($event_id, '_seatsio_event_key', true);
    }
}

// Initialize the events management
$seatsio_tickera_events = new SeatsioTickeraEvents();
