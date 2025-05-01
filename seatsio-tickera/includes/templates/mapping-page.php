<?php
// templates/mapping-page.php
?>

<!-- Wrapper for shortcode instruction -->
<div class="seatsio_info_wrapper_ar">
    <div class="seatsio_info_ar">
        <p>
            Shortcode to display the seating chart: 
            <code>[seatsio_chart event_key="seat.io_event_key"]</code>
        </p>
    </div>
</div>

<!-- Main mapping interface -->
<div class="ticket-mapping-page-am">
    <h1>Map Seats.io Categories to Tickera Tickets</h1>

    <?php // Debug: Uncomment below to inspect all categories
    // var_dump($all_categories);
    ?>

    <!-- Form to submit category-to-ticket mapping -->
    <form id="maping_form_am" method="post">
        <table>
            <tr>
                <th>Seats.io Category</th>
                <th>Tickera Ticket</th>
                <th>Price</th>
            </tr>

            <?php if (!empty($seatsio_all_categories) && !empty($tickera_tickets)): ?>
                <?php
                $categoryindex = 1; // Used to keep track of category mapping keys

                // Loop through all Seats.io categories
                foreach ($seatsio_all_categories[0] as $key => $category):
                ?>
                    <tr>
                        <!-- Display category name with its background color -->
                        <td>
                            <div class="seats_row_ar" style="background-color: <?php echo esc_attr($category['color']); ?>;">
                                <h2><?php echo esc_html($category['label']); ?></h2>
                            </div>
                        </td>

                        <!-- Dropdown to select Tickera ticket for each category -->
                        <td>
                            <select name="seatsio_mapping[<?php echo ($categoryindex); ?>]" 
                                    class="seatsio-ticket-select"
                                    data-category="<?php echo ($categoryindex); ?>">
                                <option value="">Select a Tickera Ticket</option>

                                <!-- Loop through available Tickera tickets -->
                                <?php foreach ($tickera_tickets as $ticket): ?>
                                    <option value="<?php echo esc_attr($ticket['id']); ?>"
                                            data-price="<?php echo esc_attr($ticket['price']); ?>"
                                            <?php selected($saved_mappings[$categoryindex] ?? '', $ticket['id']); ?>>
                                        <?php echo esc_html($ticket['title']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </td>

                        <!-- Display the price of the currently mapped ticket -->
                        <td class="ticket-price" id="price-<?php echo esc_attr($categoryindex); ?>">
                            <?php
                            $mapped_ticket_id = $saved_mappings[$categoryindex] ?? '';
                            $mapped_ticket = array_filter($tickera_tickets, fn($t) => $t['id'] == $mapped_ticket_id);
                            echo !empty($mapped_ticket) ? esc_html(current($mapped_ticket)['price']) : 'N/A';
                            ?>
                        </td>
                    </tr>
                <?php
                    $categoryindex++; // Increment index for the next category
                endforeach; ?>
            <?php else: ?>
                <!-- Show message if no categories or tickets are available -->
                <tr>
                    <td colspan="3">No categories or tickets found.</td>
                </tr>
            <?php endif; ?>
        </table>

        <!-- Submit button to save the mapping -->
        <input type="submit" name="seatsio_mapping_submit" value="Save Mapping">
    </form>
</div>
