<?php
// Handles the creation of a Tickera ticket based on seat information sent via AJAX
function create_tickera_ticket()
{
    // Get posted seat information
    $seat_info = $_POST['seat_info'];
    $event_id = intval($seat_info['eventID']); // Sanitize the event ID
    $seat_label = sanitize_text_field($seat_info['seatLabel']); // Sanitize the seat label

    // Prepare ticket post data
    $ticket = array(
        'post_title'  => "Ticket for Seat " . $seat_label,
        'post_status' => 'publish',
        'post_type'   => 'tc_tickets' // Custom post type for Tickera tickets
    );

    // Insert the ticket post and store the ID
    $ticket_id = wp_insert_post($ticket);

    // Save the seat label as post meta if the ticket was created successfully
    if ($ticket_id) update_post_meta($ticket_id, '_seat_label', $seat_label);

    // Return a success response in JSON format
    wp_send_json_success('Ticket created!');
}

// Register AJAX handlers for both logged-in and guest users
add_action('wp_ajax_create_tickera_ticket', 'create_tickera_ticket');
add_action('wp_ajax_nopriv_create_tickera_ticket', 'create_tickera_ticket');


// Adds custom seat metadata to the PDF ticket when generating with Tickera
add_action('tc_pdf_template', 'add_custom_metadata_to_pdf', 10, 8);
function add_custom_metadata_to_pdf($pdf, $metas, $page1, $rows, $paper_size, $ticket_instance, $template_id, $force_download)
{
    // Get the order ID related to this ticket instance
    $order_id = $ticket_instance->details->post_parent;

    // Get the individual ticket ID
    $ticket_id = $ticket_instance->details->ID;

    // Load the WooCommerce order object
    $order = wc_get_order($order_id);
    if (!$order) {
        return; // Stop if the order does not exist
    }

    // Start creating HTML content for seat info
    $html = '<h3>Seat Information:</h3>';
    $html .= '<table border="1" cellpadding="5" cellspacing="0">';
    $html .= '<tr><th>Name</th><th>Value</th></tr>';

    // Loop through each item in the WooCommerce order
    foreach ($order->get_items() as $item_id => $item) {
        // Retrieve the "Seat IDs" meta which is a comma-separated string
        $seat_ids_string = $item->get_meta('Seat IDs');

        if (!empty($seat_ids_string)) {
            // Split into an array of seat IDs
            $seat_ids_array = explode(',', $seat_ids_string);

            // Determine the index of this ticket within the order
            $ticket_index = get_ticket_index($ticket_id, $order_id);

            // If index found and matching seat exists, add it to the PDF
            if ($ticket_index !== false && isset($seat_ids_array[$ticket_index])) {
                $seat_id = trim($seat_ids_array[$ticket_index]);

                $html .= '<tr>';
                $html .= '<td>Seat ID</td>';
                $html .= '<td>' . esc_html($seat_id) . '</td>';
                $html .= '</tr>';

                // Stop once the relevant seat is found
                break;
            }
        }
    }

    $html .= '</table>';

    // Output the HTML into the PDF
    $pdf->writeHTML($html, true, false, true, false, '');
}


// Helper function to find the index of the ticket within the order
function get_ticket_index($ticket_id, $order_id)
{
    // Query all ticket instances linked to this order
    $args = array(
        'post_type' => 'tc_tickets_instances',
        'posts_per_page' => -1,
        'post_parent' => $order_id,
        'orderby' => 'ID',
        'order' => 'ASC'
    );

    $tickets = get_posts($args);

    // Find and return the index of the ticket ID
    foreach ($tickets as $index => $ticket) {
        if ($ticket->ID == $ticket_id) {
            return $index;
        }
    }

    // Return false if not found
    return false;
}
