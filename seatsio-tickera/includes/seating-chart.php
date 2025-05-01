<?php
// Define the shortcode function for rendering the Seats.io chart
function render_seatsio_chart($atts)
{
    // Set default attributes and merge with user-defined attributes
    $atts = shortcode_atts([
        'event_key' => '',
        'event_id' => '',
    ], $atts);

    $seatsio_event_key = $atts['event_key'];
    $event_id = $atts['event_id'];
    $public_key = get_option('seatsio_public_key');

    // Validate required values
    if (!$seatsio_event_key) return '<p>No seating chart available. (Missing event key)</p>';
    if (!$public_key) return '<p>No seating chart available. (Missing public key)</p>';

    // Start capturing output
    ob_start();
?>
    <!-- Seats.io chart and seat selection display -->
    <div id="chartwrapper">
        <div id="chart"></div>
        <div id="seating_cart_info">
            <div id="selected-seats" data-event="<?php echo esc_js($seatsio_event_key); ?>">
                <p>No seats selected</p>
            </div>
            <button id="add-to-cart" style="display:none;">Add to Cart</button>
        </div>
    </div>

    <!-- Load the Seats.io library -->
    <script src="https://cdn-eu.seatsio.net/chart.js"></script>

    <script>
        const selectedSeats = []; // Track selected seats
        let selectionDisabled = false; // Prevent rapid double clicks
        let initialLoadComplete = false; // Ensure actions only happen after initial chart render

        // Initialize the seating chart
        const seatingChart = new seatsio.SeatingChart({
            divId: "chart",
            publicKey: "<?php echo esc_js($public_key); ?>",
            event: "<?php echo esc_js($seatsio_event_key); ?>",
            session: "continue",

            // Runs when the chart is initially rendered
            onChartRendered: function(chart) {
                if (!initialLoadComplete) {
                    selectedSeats.length = 0;
                    seatingChart.listSelectedObjects().then(objects => {
                        if (objects && objects.length > 0) {
                            // Restore selected seats if any
                            objects.forEach((seat, index) => {
                                setTimeout(() => {
                                    if (!selectedSeats.some(s => s.id === seat.id)) {
                                        selectedSeats.push(seat);
                                        get_ticket_details(seat);
                                    }
                                }, index * 100); // Stagger processing
                            });
                        }
                        initialLoadComplete = true;
                    }).catch(error => {
                        console.error("Error getting selected objects:", error);
                        initialLoadComplete = true;
                    });
                }
            },

            // Triggered when a seat is selected
            onObjectSelected: function(seat) {
                if (selectionDisabled || !initialLoadComplete) return;

                selectionDisabled = true;
                console.dir(seat);

                if (!selectedSeats.some(s => s.id === seat.id)) {
                    selectedSeats.push(seat);
                    get_ticket_details(seat).always(() => {
                        selectionDisabled = false;
                    });
                } else {
                    selectionDisabled = false;
                }
            },

            // Triggered when a seat is deselected
            onObjectDeselected: function(seat) {
                if (selectionDisabled || !initialLoadComplete) return;

                selectionDisabled = true;
                console.dir(seat);

                const index = selectedSeats.findIndex(s => s.id === seat.id);
                if (index !== -1) {
                    selectedSeats.splice(index, 1);
                    get_ticket_details(seat).always(() => {
                        selectionDisabled = false;
                    });
                } else {
                    selectionDisabled = false;
                }
            }
        }).render();

        // Fetch seat-specific ticket information via AJAX
        function get_ticket_details(seat) {
            return jQuery.ajax({
                url: '/wp-admin/admin-ajax.php',
                type: "POST",
                data: {
                    action: "show_seats_details",
                    seats: selectedSeats.map(seat => ({
                        id: seat.id,
                        label: seat.label,
                        category_key: seat.category.key
                    }))
                },
                success: function(response) {
                    const parsedData = typeof response === 'string' ? JSON.parse(response) : response;
                    const dataArray = Array.isArray(parsedData.data) ? parsedData.data : [parsedData.data];
                    updateSeatSelectionDisplay(dataArray);
                },
                error: function(error) {
                    console.error("Error:", error);
                    selectionDisabled = false;
                }
            });
        }

        // Update the UI to reflect the selected seats and ticket prices
        function updateSeatSelectionDisplay(ticket_details) {
            const selectionDiv = document.getElementById("selected-seats");
            selectionDiv.innerHTML = "";

            if (selectedSeats.length === 0) {
                selectionDiv.innerHTML = "<p>No seats selected</p>";
                document.getElementById("add-to-cart").style.display = "none";
                return;
            }

            // Loop through each seat and find corresponding ticket info
            selectedSeats.forEach(seat => {
                let ticket_price = 0;
                let ticket_type_id = 0;

                ticket_details.forEach(function(ticket) {
                    if (ticket.category_key == seat.category.key) {
                        ticket_price = ticket.ticket_price;
                        ticket_type_id = ticket.ticket_id;
                    }
                });

                // Create and append the seat info block
                const seatInfo = document.createElement("div");
                seatInfo.innerHTML = `
                    <div class='seat_item_ar' tickey-type='${ticket_type_id}' seatkey='${seat.id}'> 
                        <div class='section_name_ar'>
                            <h4>Seat Info: <span>${seat.label}</span></h4> 
                            <h4>Ticket Price: <span>${ticket_price}</span></h4>
                        </div> 
                    </div>`;
                selectionDiv.appendChild(seatInfo);
            });

            // Show the "Add to Cart" button
            document.getElementById("add-to-cart").style.display = "block";
        }

        // Handle Add to Cart button click
        document.getElementById("add-to-cart").addEventListener("click", function() {
            if (selectedSeats.length === 0) return;

            const selected_tickets = jQuery("#selected-seats").find(".seat_item_ar");
            const event_id = jQuery("#selected-seats").attr("data-event");
            const ticketCounts = {};
            const ticketSeatKeys = {};

            // Count the number of seats per ticket type
            selected_tickets.each(function() {
                const ticket_id = jQuery(this).attr("tickey-type");
                const seatkey = jQuery(this).attr("seatkey");

                if (ticket_id) {
                    if (ticketCounts[ticket_id] === undefined) {
                        ticketCounts[ticket_id] = 1;
                        ticketSeatKeys[ticket_id] = [seatkey];
                    } else {
                        ticketCounts[ticket_id]++;
                        ticketSeatKeys[ticket_id].push(seatkey);
                    }
                }
            });

            // Prepare data to send to server
            const ajaxData = {
                action: "my_custom_add_to_cart",
                event_id: event_id,
            };

            const uniqueTicketIds = Object.keys(ticketCounts);

            if (uniqueTicketIds.length === 1) {
                // One ticket type selected
                ajaxData.ticket_id = uniqueTicketIds[0];
                ajaxData.tc_qty = ticketCounts[uniqueTicketIds[0]];
                ajaxData.seat_keys = ticketSeatKeys[uniqueTicketIds[0]];
            } else if (uniqueTicketIds.length > 1) {
                // Multiple ticket types selected
                ajaxData.ticket_ids = uniqueTicketIds;
                ajaxData.tc_qty = uniqueTicketIds.map(ticket_id => ticketCounts[ticket_id]);
                ajaxData.seat_keys = ticketSeatKeys;
            }

            // Send to WordPress backend via AJAX
            jQuery.ajax({
                url: '/wp-admin/admin-ajax.php',
                type: "POST",
                data: ajaxData,
                success: function(response) {
                    console.log(response);
                    // Redirect to cart page after successful add
                    window.location.href = "/cart/";
                },
                error: function(error) {
                    console.error("Error:", error);
                }
            });
        });
    </script>
<?php
    // Return the buffered HTML output
    return ob_get_clean();
}

// Register the shortcode [seatsio_chart]
add_shortcode('seatsio_chart', 'render_seatsio_chart');
