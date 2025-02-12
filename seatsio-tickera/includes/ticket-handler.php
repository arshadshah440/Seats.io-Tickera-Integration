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
