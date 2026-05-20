<?php
/**
 * Export approved/pending reservations as iCalendar (.ics) for the logged-in user or all (staff/admin).
 */
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../../../../config/app.php';

if (!($_SESSION['user_authenticated'] ?? false)) {
    http_response_code(401);
    exit('Unauthorized');
}

require_once __DIR__ . '/../../../../config/database.php';
$pdo = db();
$userId = (int)($_SESSION['user_id'] ?? 0);
$role = (string)($_SESSION['role'] ?? 'Resident');
$scope = $_GET['scope'] ?? 'mine';
$from = $_GET['from'] ?? date('Y-m-01');
$to = $_GET['to'] ?? date('Y-m-t', strtotime('+2 months'));

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) {
    $from = date('Y-m-01');
}
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
    $to = date('Y-m-t', strtotime('+2 months'));
}

$sql = 'SELECT r.id, r.reservation_date, r.time_slot, r.status, r.purpose,
               f.name AS facility_name, u.name AS requester_name
        FROM reservations r
        JOIN facilities f ON r.facility_id = f.id
        JOIN users u ON r.user_id = u.id
        WHERE r.reservation_date >= :from AND r.reservation_date <= :to
          AND r.status IN (\'pending\', \'pending_payment\', \'approved\', \'postponed\')';
$params = ['from' => $from, 'to' => $to];
if ($scope !== 'all' || !in_array($role, ['Admin', 'Staff'], true)) {
    $sql .= ' AND r.user_id = :uid';
    $params['uid'] = $userId;
}
$sql .= ' ORDER BY r.reservation_date, r.time_slot';
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

function frs_ics_escape(string $s): string
{
    return str_replace(["\r\n", "\n", "\r", ',', ';'], ['\\n', '\\n', '\\n', '\\,', '\\;'], $s);
}

$lines = [
    'BEGIN:VCALENDAR',
    'VERSION:2.0',
    'PRODID:-//LGU Culiat//Facilities Reservation//EN',
    'CALSCALE:GREGORIAN',
    'METHOD:PUBLISH',
    'X-WR-CALNAME:LGU Facility Reservations',
];

$tz = 'Asia/Manila';
foreach ($rows as $r) {
    $date = (string)$r['reservation_date'];
    $slot = (string)$r['time_slot'];
    if (!preg_match('/(\d{1,2}):(\d{2})\s*-\s*(\d{1,2}):(\d{2})/', $slot, $m)) {
        continue;
    }
    $start = sprintf('%02d:%02d:00', (int)$m[1], (int)$m[2]);
    $end = sprintf('%02d:%02d:00', (int)$m[3], (int)$m[4]);
    $dtStart = str_replace('-', '', $date) . 'T' . str_replace(':', '', $start);
    $dtEnd = str_replace('-', '', $date) . 'T' . str_replace(':', '', $end);
    $uid = 'reservation-' . (int)$r['id'] . '@culiat-facilities.local';
    $summary = frs_ics_escape($r['facility_name'] . ' (' . $r['status'] . ')');
    $desc = frs_ics_escape(($r['requester_name'] ?? '') . ' — ' . ($r['purpose'] ?? ''));
    $lines[] = 'BEGIN:VEVENT';
    $lines[] = 'UID:' . $uid;
    $lines[] = 'DTSTAMP:' . gmdate('Ymd\THis\Z');
    $lines[] = 'DTSTART;TZID=' . $tz . ':' . $dtStart;
    $lines[] = 'DTEND;TZID=' . $tz . ':' . $dtEnd;
    $lines[] = 'SUMMARY:' . $summary;
    $lines[] = 'DESCRIPTION:' . $desc;
    $lines[] = 'URL:' . frs_ics_escape((function_exists('base_url') ? base_url() : '') . '/dashboard/reservation-detail?id=' . (int)$r['id']);
    $lines[] = 'END:VEVENT';
}
$lines[] = 'END:VCALENDAR';

header('Content-Type: text/calendar; charset=utf-8');
header('Content-Disposition: attachment; filename="lgu-reservations-' . $from . '-to-' . $to . '.ics"');
echo implode("\r\n", $lines);
