<?php
function create_tickera_ticket()
{
    $seat_info = $_POST['seat_info'];
    $event_id = intval($seat_info['eventID']);
    $seat_label = sanitize_text_field($seat_info['seatLabel']);

    $ticket = array(
        'post_title'  => "Ticket for Seat " . $seat_label,
        'post_status' => 'publish',
        'post_type'   => 'tc_tickets'
    );

    $ticket_id = wp_insert_post($ticket);
    if ($ticket_id) update_post_meta($ticket_id, '_seat_label', $seat_label);

    wp_send_json_success('Ticket created!');
}
add_action('wp_ajax_create_tickera_ticket', 'create_tickera_ticket');
add_action('wp_ajax_nopriv_create_tickera_ticket', 'create_tickera_ticket');

add_action('tc_pdf_template', 'add_custom_metadata_to_pdf', 10, 8);
function add_custom_metadata_to_pdf($pdf, $metas, $page1, $rows, $paper_size, $ticket_instance, $template_id, $force_download)
{
    // Get the order ID from the ticket instance
    $order_id = $ticket_instance->details->post_parent;

    // Get the ticket ID (this is the specific ticket we're generating the PDF for)
    $ticket_id = $ticket_instance->details->ID;

    // Get the WooCommerce order object
    $order = wc_get_order($order_id);
    if (!$order) {
        return; // Exit if the order doesn't exist
    }

    // Start building the HTML content
    $html = '<h3>Seat Information:</h3>';
    $html .= '<table border="1" cellpadding="5" cellspacing="0">';
    $html .= '<tr><th>Name</th><th>Value</th></tr>';

    // Loop through all items in the order
    foreach ($order->get_items() as $item_id => $item) {
        // Get the value of the specific meta key "Seat IDs"
        $seat_ids_string = $item->get_meta('Seat IDs');

        if (!empty($seat_ids_string)) {
            // Split the comma-separated seat IDs into an array
            $seat_ids_array = explode(',', $seat_ids_string);

            // Find the corresponding seat ID for this specific ticket
            // This assumes tickets and seat IDs are in the same order
            $ticket_index = get_ticket_index($ticket_id, $order_id);

            if ($ticket_index !== false && isset($seat_ids_array[$ticket_index])) {
                $seat_id = trim($seat_ids_array[$ticket_index]);

                $html .= '<tr>';
                $html .= '<td>Seat ID</td>';
                $html .= '<td>' . esc_html($seat_id) . '</td>';
                $html .= '</tr>';

                // Once we've found and displayed the seat ID for this ticket, we can break
                break;
            }
        }
    }

    $html .= '</table>';

    // Write the HTML to the PDF
    $pdf->writeHTML($html, true, false, true, false, '');
}

// Helper function to determine the index of the current ticket in the order
function get_ticket_index($ticket_id, $order_id)
{
    // Get all tickets related to this order
    $args = array(
        'post_type' => 'tc_tickets_instances',
        'posts_per_page' => -1,
        'post_parent' => $order_id,
        'orderby' => 'ID',
        'order' => 'ASC'
    );

    $tickets = get_posts($args);

    // Find the index of the current ticket
    foreach ($tickets as $index => $ticket) {
        if ($ticket->ID == $ticket_id) {
            return $index;
        }
    }

    return false;
}
