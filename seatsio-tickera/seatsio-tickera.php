<?php

/**
 * Plugin Name: Seats.io Tickera Integration
 * Description: Integrates Seats.io seating charts with Tickera ticketing system.
 * Version: 1.0
 * Author: ArshadWPDev
 */

if (!defined('ABSPATH')) exit;

// Include required files
include_once plugin_dir_path(__FILE__) . 'includes/admin-settings.php';
include_once plugin_dir_path(__FILE__) . 'includes/fetch-events.php';
include_once plugin_dir_path(__FILE__) . 'includes/seating-chart.php';
include_once plugin_dir_path(__FILE__) . 'includes/ticket-handler.php';
include_once plugin_dir_path(__FILE__) . 'includes/ajax/tickera-seating-reservations.php';
