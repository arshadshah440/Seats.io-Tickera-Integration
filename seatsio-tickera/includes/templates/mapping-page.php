<?php
// templates/mapping-page.php
?>
<div class="ticket-mapping-page-am">
    <h1>Map Seats.io Categories to Tickera Tickets</h1>
    <form id="maping_form_am" method="post">
        <table>
            <tr>
                <th>Seats.io Category</th>
                <th>Tickera Ticket</th>
                <th>Price</th>
            </tr>
            <?php if (!empty($seatsio_categories) && !empty($tickera_tickets)): ?>
                <?php foreach ($seatsio_categories as $key => $category): ?>
                    <tr>
                        <td>
                            <div class="seats_row_ar" style="background-color: <?php echo esc_attr($category['color']); ?>;">
                                <h2><?php echo esc_html($category['label']); ?></h2>
                            </div>
                        </td>
                        <td>
                            <select name="seatsio_mapping[<?php echo esc_attr($key); ?>]" class="seatsio-ticket-select"
                                data-category="<?php echo esc_attr($key); ?>">
                                <option value="">Select a Tickera Ticket</option>
                                <?php foreach ($tickera_tickets as $ticket): ?>
                                    <option value="<?php echo esc_attr($ticket['id']); ?>"
                                        data-price="<?php echo esc_attr($ticket['price']); ?>"
                                        <?php selected($saved_mappings[$key] ?? '', $ticket['id']); ?>>
                                        <?php echo esc_html($ticket['title']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                        <td class="ticket-price" id="price-<?php echo esc_attr($key); ?>">
                            <?php
                            $mapped_ticket_id = $saved_mappings[$key] ?? '';
                            $mapped_ticket = array_filter($tickera_tickets, fn($t) => $t['id'] == $mapped_ticket_id);
                            echo !empty($mapped_ticket) ? esc_html(current($mapped_ticket)['price']) : 'N/A';
                            ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php else: ?>
                <tr>
                    <td colspan="3">No categories or tickets found.</td>
                </tr>
            <?php endif; ?>
        </table>
        <input type="submit" name="seatsio_mapping_submit" value="Save Mapping">
    </form>
</div>