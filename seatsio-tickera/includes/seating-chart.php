<?php
function render_seatsio_chart($atts)
{
    $atts = shortcode_atts([
        'event_key' => '',
        'event_id' => '',

    ], $atts);

    $seatsio_event_key = $atts['event_key'];
    $event_id = $atts['event_id'];
    $public_key = get_option('seatsio_public_key');

    if (!$seatsio_event_key) return '<p>No seating chart available. (Missing event key)</p>';
    if (!$public_key) return '<p>No seating chart available. (Missing public key)</p>';

    ob_start();
?>
    <div id="chartwrapper">
        <div id="chart"></div>
        <div id="seating_cart_info">
            <div id="selected-seats" data-event="<?php echo esc_js($seatsio_event_key); ?>">
                <p>No seats selected</p>
            </div>
            <button id="add-to-cart" style="display:none;">Add to Cart</button>
        </div>
    </div>
    <script src="https://cdn-eu.seatsio.net/chart.js"></script>
    <script>
        const selectedSeats = [];
        let selectionDisabled = false;
        let initialLoadComplete = false; // Flag to track initial load

        const seatingChart = new seatsio.SeatingChart({
            divId: "chart",
            publicKey: "<?php echo esc_js($public_key); ?>",
            event: "<?php echo esc_js($seatsio_event_key); ?>",
            session: "continue",
            onChartRendered: function(chart) {
                // Only run this if it's the first load
                if (!initialLoadComplete) {
                    selectedSeats.length = 0; // Clear the array first
                    seatingChart.listSelectedObjects().then(objects => {
                        if (objects && objects.length > 0) {
                            objects.forEach((seat, index) => {
                                setTimeout(() => {
                                    // Check if seat is already in the array
                                    if (!selectedSeats.some(s => s.id === seat.id)) {
                                        selectedSeats.push(seat);
                                        get_ticket_details(seat);
                                    }
                                }, index * 100);
                            });
                        }
                        initialLoadComplete = true;
                    }).catch(error => {
                        console.error("Error getting selected objects:", error);
                        initialLoadComplete = true;
                    });
                }
            },
            onObjectSelected: function(seat) {
                if (selectionDisabled) return;
                if (!initialLoadComplete) return; // Prevent selection during initial load

                selectionDisabled = true;
                console.dir(seat);

                // Check if seat is already in the array
                if (!selectedSeats.some(s => s.id === seat.id)) {
                    selectedSeats.push(seat);
                    get_ticket_details(seat).always(() => {
                        selectionDisabled = false;
                    });
                } else {
                    selectionDisabled = false;
                }
            },
            onObjectDeselected: function(seat) {
                if (selectionDisabled) return;
                if (!initialLoadComplete) return; // Prevent deselection during initial load

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

                    // Always update display since we're managing duplicates now
                    updateSeatSelectionDisplay(dataArray);
                },
                error: function(error) {
                    console.error("Error:", error);
                    selectionDisabled = false;
                }
            });
        }

        function updateSeatSelectionDisplay(ticket_details) {
            const selectionDiv = document.getElementById("selected-seats");
            selectionDiv.innerHTML = "";

            if (selectedSeats.length === 0) {
                selectionDiv.innerHTML = "<p>No seats selected</p>";
                document.getElementById("add-to-cart").style.display = "none";
                return;
            }

            selectedSeats.forEach(seat => {
                let ticket_price = 0;
                let ticket_type_id = 0;

                ticket_details.forEach(function(ticket) {
                    if (ticket.category_key == seat.category.key) {
                        ticket_price = ticket.ticket_price;
                        ticket_type_id = ticket.ticket_id;
                    }
                });

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

            document.getElementById("add-to-cart").style.display = "block";
        }
        document.getElementById("add-to-cart").addEventListener("click", function() {
            // If no seats are selected, do nothing.
            if (selectedSeats.length === 0) return;

            // Find all selected seat elements.
            var selected_tickets = jQuery("#selected-seats").find(".seat_item_ar");
            var event_id = jQuery("#selected-seats").attr("data-event");
            var ticketCounts = {};
            var ticketSeatKeys = {};

            // Loop through each selected ticket element.
            selected_tickets.each(function() {
                var ticket_id = jQuery(this).attr("tickey-type");
                var seatkey = jQuery(this).attr("seatkey");
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

            // Prepare the AJAX data object.
            var ajaxData = {
                action: "my_custom_add_to_cart", // Should match the PHP AJAX action.
                event_id: event_id,
            };

            // Get an array of unique ticket IDs.
            var uniqueTicketIds = Object.keys(ticketCounts);

            if (uniqueTicketIds.length === 1) {
                // Single ticket type: send as a single value.
                ajaxData.ticket_id = uniqueTicketIds[0];
                ajaxData.tc_qty = ticketCounts[uniqueTicketIds[0]];
                ajaxData.seat_keys = ticketSeatKeys[uniqueTicketIds[0]]; // Array of seat keys.
            } else if (uniqueTicketIds.length > 1) {
                // Multiple ticket types: send as arrays.
                ajaxData.ticket_ids = uniqueTicketIds;
                ajaxData.tc_qty = uniqueTicketIds.map(function(ticket_id) {
                    return ticketCounts[ticket_id];
                });
                // Send the entire ticketSeatKeys object mapping each ticket id to its seat keys.
                ajaxData.seat_keys = ticketSeatKeys;
            }

            // Make the AJAX call.
            jQuery.ajax({
                url: '/wp-admin/admin-ajax.php', // WordPress AJAX handler.
                type: "POST",
                data: ajaxData,
                success: function(response) {
                    console.log(response);
                    // Redirect to cart page (adjust URL as needed)
                    window.location.href = "/cart/";
                },
                error: function(error) {
                    console.error("Error:", error);
                }
            });
        });
    </script>

<?php
    return ob_get_clean();
}
add_shortcode('seatsio_chart', 'render_seatsio_chart');
