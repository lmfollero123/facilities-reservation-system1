<?php
/**
 * Purpose-first smart hints: ranked facilities + calendar date highlights (top match).
 * Used before the user picks a date in the booking modal.
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!($_SESSION['user_authenticated'] ?? false)) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

require_once __DIR__ . '/../../../../config/app.php';
require_once __DIR__ . '/../../../../config/database.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    frs_reject_invalid_csrf_json();
}
require_once __DIR__ . '/../../../../config/geocoding.php';
require_once __DIR__ . '/../../../../config/ai_helpers.php';
require_once __DIR__ . '/../../../../config/ai_ml_integration.php';
require_once __DIR__ . '/../../../../config/booking_calendar_status.php';

header('Content-Type: application/json');

$pdo = db();
$userId = (int)($_SESSION['user_id'] ?? 0);

$purpose = trim((string)($_POST['purpose'] ?? $_GET['purpose'] ?? ''));
$year = (int)($_POST['year'] ?? $_GET['year'] ?? (int)date('Y'));
$month = (int)($_POST['month'] ?? $_GET['month'] ?? (int)date('n'));
$expectedAttendees = max(1, (int)($_POST['expected_attendees'] ?? $_GET['expected_attendees'] ?? 50));
$isCommercial = isset($_POST['is_commercial']) && $_POST['is_commercial'] === '1';

if (strlen($purpose) < 3) {
    echo json_encode(['error' => 'Purpose too short', 'highlight_dates' => [], 'facilities' => []]);
    exit;
}

if ($year < 2000 || $year > 2100) {
    $year = (int)date('Y');
}
if ($month < 1 || $month > 12) {
    $month = (int)date('n');
}

$nowYm = (int)date('Y') * 100 + (int)date('n');
$viewYm = $year * 100 + $month;
$today = date('Y-m-d');

$firstOfMonth = sprintf('%04d-%02d-01', $year, $month);
$lastOfMonth = date('Y-m-t', strtotime($firstOfMonth));

if ($viewYm < $nowYm) {
    echo json_encode([
        'highlight_dates' => [],
        'facilities' => [],
        'primary_facility_id' => null,
        'message' => 'past_month',
    ]);
    exit;
}

if ($viewYm === $nowYm) {
    $reservationDate = $today;
} else {
    $reservationDate = $firstOfMonth;
}

$timeSlot = '09:00 - 12:00';

$suggestedTimes = function_exists('getSuggestedTimesForPurpose')
    ? getSuggestedTimesForPurpose($purpose, $pdo)
    : ['slots' => [], 'label' => '', 'source' => 'rules'];

try {
    $userBookingStmt = $pdo->prepare('SELECT COUNT(*) FROM reservations WHERE user_id = :user_id');
    $userBookingStmt->execute(['user_id' => $userId]);
    $userBookingCount = (int)$userBookingStmt->fetchColumn();

    $facilitiesStmt = $pdo->query(
        'SELECT id, name, description, capacity, amenities, location, latitude, longitude, status, operating_hours
         FROM facilities
         WHERE status = "available"
         ORDER BY name'
    );
    $facilities = $facilitiesStmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($facilities)) {
        echo json_encode([
            'highlight_dates' => [],
            'facilities' => [],
            'primary_facility_id' => null,
            'best_times_label' => $suggestedTimes['label'] ?? '',
            'suggested_times' => $suggestedTimes['slots'] ?? [],
        ]);
        exit;
    }

    $userCoords = $userId ? getUserCoordinates($userId) : null;
    $popMap = function_exists('getFacilityApprovedBookingCounts') ? getFacilityApprovedBookingCounts($pdo) : [];

    $facilitiesForMl = array_map(static function ($f) {
        return [
            'id' => $f['id'],
            'name' => $f['name'],
            'capacity' => $f['capacity'],
            'amenities' => $f['amenities'] ?? '',
        ];
    }, $facilities);

    $recs = [];
    $mlTime = 0.0;

    if (function_exists('recommendFacilitiesML')) {
        $t0 = microtime(true);
        $recommendations = recommendFacilitiesML(
            facilities: $facilitiesForMl,
            userId: $userId,
            purpose: $purpose,
            expectedAttendees: $expectedAttendees,
            timeSlot: $timeSlot,
            reservationDate: $reservationDate,
            isCommercial: $isCommercial,
            userBookingCount: $userBookingCount,
            limit: 8
        );
        $mlTime = microtime(true) - $t0;
        $mlRecs = $recommendations['recommendations'] ?? [];
        $mlFailedHard = isset($recommendations['error']) && $mlRecs === [];
        if (!$mlFailedHard && $mlRecs !== [] && $mlTime <= 55.0) {
            $recs = $mlRecs;
            $facilityMap = [];
            foreach ($facilities as $f) {
                $facilityMap[$f['id']] = $f;
            }
            foreach ($recs as &$r) {
                $f = $facilityMap[$r['id'] ?? 0] ?? null;
                $r['operating_hours'] = $f['operating_hours'] ?? null;
                if ($userCoords && $f && $f['latitude'] !== null && $f['longitude'] !== null) {
                    $km = calculateDistance(
                        $userCoords['lat'],
                        $userCoords['lng'],
                        (float)$f['latitude'],
                        (float)$f['longitude']
                    );
                    $r['distance_km'] = $km;
                    $r['distance'] = formatDistance($km);
                } else {
                    $r['distance_km'] = null;
                    $r['distance'] = null;
                }
            }
            unset($r);
            if ($popMap !== [] && function_exists('applyFacilityBookingPopularityBoost')) {
                $recs = applyFacilityBookingPopularityBoost($recs, $popMap);
            }
            usort($recs, static function ($a, $b) {
                $sA = $a['ml_relevance_score'] ?? 0;
                $sB = $b['ml_relevance_score'] ?? 0;
                if ($sB !== $sA) {
                    return $sB <=> $sA;
                }
                $dA = $a['distance_km'] ?? 999;
                $dB = $b['distance_km'] ?? 999;
                return $dA <=> $dB;
            });
        }
    }

    if ($recs === []) {
        $purposeLower = strtolower($purpose);
        $scoredFacilities = [];
        foreach ($facilities as $facility) {
            $score = 0.0;
            $distanceKm = null;
            $distance = null;
            if ($userCoords && $facility['latitude'] !== null && $facility['longitude'] !== null) {
                $distanceKm = calculateDistance(
                    $userCoords['lat'],
                    $userCoords['lng'],
                    (float)$facility['latitude'],
                    (float)$facility['longitude']
                );
                $distance = formatDistance($distanceKm);
                if ($distanceKm <= 1) {
                    $score += 2.0;
                } elseif ($distanceKm <= 3) {
                    $score += 1.5;
                } elseif ($distanceKm <= 5) {
                    $score += 1.0;
                } elseif ($distanceKm <= 10) {
                    $score += 0.5;
                }
            }
            $ft = ($facility['name'] ?? '') . ' ' . ($facility['description'] ?? '') . ' ' . ($facility['location'] ?? '') . ' ' . ($facility['amenities'] ?? '');
            if ($purpose && function_exists('matchPurpose')) {
                $pm = matchPurpose($purpose, $ft);
                if (($pm['score'] ?? 0) > 0) {
                    $score += (float)($pm['score'] ?? 0) / 10;
                }
            }
            if (stripos($purposeLower, 'sport') !== false || stripos($purposeLower, 'zumba') !== false || stripos($purposeLower, 'fitness') !== false) {
                if (stripos((string)($facility['amenities'] ?? ''), 'court') !== false || stripos((string)($facility['name'] ?? ''), 'court') !== false) {
                    $score += 2.0;
                }
            }
            $capacity = (int)filter_var($facility['capacity'] ?? '100', FILTER_SANITIZE_NUMBER_INT);
            if ($capacity >= $expectedAttendees * 0.8 && $capacity <= $expectedAttendees * 1.5) {
                $score += 1.0;
            }
            $scoredFacilities[] = [
                'id' => $facility['id'],
                'name' => $facility['name'],
                'ml_relevance_score' => round($score, 2),
                'distance' => $distance,
                'distance_km' => $distanceKm,
                'operating_hours' => $facility['operating_hours'] ?? null,
            ];
        }
        if ($popMap !== [] && function_exists('applyFacilityBookingPopularityBoost')) {
            $scoredFacilities = applyFacilityBookingPopularityBoost($scoredFacilities, $popMap);
        }
        usort($scoredFacilities, static function ($a, $b) {
            if (($b['ml_relevance_score'] ?? 0) !== ($a['ml_relevance_score'] ?? 0)) {
                return ($b['ml_relevance_score'] ?? 0) <=> ($a['ml_relevance_score'] ?? 0);
            }
            return ($a['distance_km'] ?? 999) <=> ($b['distance_km'] ?? 999);
        });
        $recs = array_slice($scoredFacilities, 0, 8);
    }

    $primaryId = (int)($recs[0]['id'] ?? 0);
    $highlightDates = [];
    if ($primaryId > 0 && function_exists('frs_facility_calendar_matrix')) {
        $matrix = frs_facility_calendar_matrix($pdo, $primaryId, $year, $month);
        $greens = [];
        $yellows = [];
        foreach ($matrix as $d => $tone) {
            if ($d < $today) {
                continue;
            }
            if ($d < $firstOfMonth || $d > $lastOfMonth) {
                continue;
            }
            if ($tone === 'green') {
                $greens[] = $d;
            } elseif ($tone === 'yellow') {
                $yellows[] = $d;
            }
        }
        sort($greens);
        sort($yellows);
        $highlightDates = array_merge($greens, $yellows);
        $highlightDates = array_slice($highlightDates, 0, 14);
    }

    $outFacilities = [];
    foreach (array_slice($recs, 0, 5) as $row) {
        $outFacilities[] = [
            'id' => (int)($row['id'] ?? 0),
            'name' => (string)($row['name'] ?? ''),
            'score' => isset($row['ml_relevance_score']) ? round((float)$row['ml_relevance_score'], 2) : null,
            'distance' => $row['distance'] ?? null,
            'distance_km' => isset($row['distance_km']) ? round((float)$row['distance_km'], 2) : null,
        ];
    }

    echo json_encode([
        'highlight_dates' => $highlightDates,
        'primary_facility_id' => $primaryId ?: null,
        'facilities' => $outFacilities,
        'best_times_label' => $suggestedTimes['label'] ?? '',
        'suggested_times' => $suggestedTimes['slots'] ?? [],
        'suggested_times_source' => $suggestedTimes['source'] ?? 'rules',
        'reference_date_used' => $reservationDate,
    ]);
} catch (Throwable $e) {
    error_log('booking_smart_hints_api: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Could not compute hints']);
}
