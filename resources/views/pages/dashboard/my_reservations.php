<?php
/**
 * Backward-compatible entry: full My Reservations calendar and actions are rendered
 * from book_facility.php with the My Reservations tab selected.
 */
$_SERVER['_RESERVATIONS_HUB_ROUTE'] = 'mine';
require __DIR__ . '/book_facility.php';
