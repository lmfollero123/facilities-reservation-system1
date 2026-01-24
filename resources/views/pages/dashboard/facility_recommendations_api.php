<?php
/**
 * Facility Recommendations API
 * Returns ML-based or rule-based facility recommendations.
 * Considers: purpose, distance from user, capacity, suggested times for purpose.
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!($_SESSION['user_authenticated'] ?? false)) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

require_once __DIR__ . '/../../../../config/database.php';
require_once __DIR__ . '/../../../../config/geocoding.php';
require_once __DIR__ . '/../../../../config/ai_helpers.php';
require_once __DIR__ . '/../../../../config/ai_ml_integration.php';

header('Content-Type: application/json');

$pdo = db();
$userId = (int)($_SESSION['user_id'] ?? 0);

$purpose = trim($_POST['purpose'] ?? $_GET['purpose'] ?? '');
$expectedAttendees = !empty($_POST['expected_attendees']) ? (int)$_POST['expected_attendees'] : (!empty($_GET['expected_attendees']) ? (int)$_GET['expected_attendees'] : 50);
$timeSlot = $_POST['time_slot'] ?? $_GET['time_slot'] ?? '08:00 - 12:00';
$reservationDate = $_POST['reservation_date'] ?? $_GET['reservation_date'] ?? date('Y-m-d');
$isCommercial = isset($_POST['is_commercial']) && $_POST['is_commercial'] === '1';

if (empty($purpose)) {
    echo json_encode(['error' => 'Purpose is required']);
    exit;
}

$suggestedTimes = function_exists('getSuggestedTimesForPurpose') ? getSuggestedTimesForPurpose($purpose) : ['slots' => [], 'label' => ''];

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
            'recommendations' => [],
            'suggested_times' => $suggestedTimes,
            'best_times_label' => $suggestedTimes['label'] ?? '',
        ]);
        exit;
    }

    $userCoords = $userId ? getUserCoordinates($userId) : null;

    $baseResponse = [
        'suggested_times' => $suggestedTimes['slots'] ?? [],
        'best_times_label' => $suggestedTimes['label'] ?? '',
    ];

    $facilitiesForMl = array_map(function ($f) {
        return [
            'id' => $f['id'],
            'name' => $f['name'],
            'capacity' => $f['capacity'],
            'amenities' => $f['amenities'] ?? '',
        ];
    }, $facilities);

    if (function_exists('recommendFacilitiesML')) {
        try {
            $startTime = microtime(true);
            $mlTimeLimit = 2.0; // Reduced from 3.0 to 2.0 seconds for faster fallback

            $recommendations = recommendFacilitiesML(
                facilities: $facilitiesForMl,
                userId: $userId,
                purpose: $purpose,
                expectedAttendees: $expectedAttendees,
                timeSlot: $timeSlot,
                reservationDate: $reservationDate,
                isCommercial: $isCommercial,
                userBookingCount: $userBookingCount,
                limit: 5
            );

            $mlTime = microtime(true) - $startTime;

            if (($mlTime <= $mlTimeLimit) && !isset($recommendations['error']) && !empty($recommendations['recommendations'])) {
                $recs = $recommendations['recommendations'];
                $facilityMap = [];
                foreach ($facilities as $f) {
                    $facilityMap[$f['id']] = $f;
                }

                foreach ($recs as &$r) {
                    $f = $facilityMap[$r['id'] ?? 0] ?? null;
                    $r['distance'] = null;
                    $r['distance_km'] = null;
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
                        $reason = $r['reason'] ?? '';
                        $r['reason'] = $reason ? $reason . '; ' . $r['distance'] . ' from you' : $r['distance'] . ' from you';
                    }
                }
                unset($r);

                usort($recs, function ($a, $b) {
                    $sA = $a['ml_relevance_score'] ?? 0;
                    $sB = $b['ml_relevance_score'] ?? 0;
                    if ($sB !== $sA) {
                        return $sB <=> $sA;
                    }
                    $dA = $a['distance_km'] ?? 999;
                    $dB = $b['distance_km'] ?? 999;
                    return $dA <=> $dB;
                });

                echo json_encode(array_merge($baseResponse, [
                    'recommendations' => $recs,
                    'ml_enabled' => true,
                    'ml_time' => round($mlTime, 2),
                ]));
                exit;
            }
        } catch (Exception $e) {
            error_log("Facility recommendation ML exception: " . $e->getMessage());
        } catch (Throwable $e) {
            error_log("Facility recommendation ML fatal error: " . $e->getMessage());
        }
    }

    $purposeLower = strtolower($purpose);
    $scoredFacilities = [];

    foreach ($facilities as $facility) {
        $score = 0.0;
        $reasons = [];
        $distance = null;
        $distanceKm = null;

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
                $reasons[] = 'Very close to you (' . $distance . ')';
            } elseif ($distanceKm <= 3) {
                $score += 1.5;
                $reasons[] = 'Nearby (' . $distance . ')';
            } elseif ($distanceKm <= 5) {
                $score += 1.0;
                $reasons[] = 'Moderately close (' . $distance . ')';
            } elseif ($distanceKm <= 10) {
                $score += 0.5;
                $reasons[] = 'Within 10 km (' . $distance . ')';
            }
        }

        $ft = ($facility['name'] ?? '') . ' ' . ($facility['description'] ?? '') . ' ' . ($facility['location'] ?? '') . ' ' . ($facility['amenities'] ?? '');
        if ($purpose && function_exists('matchPurpose')) {
            $pm = matchPurpose($purpose, $ft);
            if (($pm['score'] ?? 0) > 0) {
                $score += (float)($pm['score'] ?? 0) / 10;
                $reasons[] = $pm['reason'] ?? 'Matches purpose';
            }
        }

        if (stripos($purposeLower, 'sport') !== false || stripos($purposeLower, 'basketball') !== false || stripos($purposeLower, 'volleyball') !== false || stripos($purposeLower, 'zumba') !== false || stripos($purposeLower, 'fitness') !== false) {
            if (stripos($facility['amenities'] ?? '', 'court') !== false || stripos($facility['name'] ?? '', 'sport') !== false || stripos($facility['name'] ?? '', 'court') !== false) {
                $score += 2.0;
                $reasons[] = 'Matches sports/fitness activities';
            }
        }
        if (stripos($purposeLower, 'meeting') !== false || stripos($purposeLower, 'assembly') !== false || stripos($purposeLower, 'conference') !== false) {
            if (stripos($facility['amenities'] ?? '', 'conference') !== false || stripos($facility['name'] ?? '', 'hall') !== false) {
                $score += 2.0;
                $reasons[] = 'Suitable for meetings/conferences';
            }
        }
        if (stripos($purposeLower, 'celebration') !== false || stripos($purposeLower, 'party') !== false || stripos($purposeLower, 'wedding') !== false) {
            if (stripos($facility['name'] ?? '', 'hall') !== false || stripos($facility['amenities'] ?? '', 'event') !== false) {
                $score += 2.0;
                $reasons[] = 'Great for celebrations/events';
            }
        }

        $capacity = (int)filter_var($facility['capacity'] ?? '100', FILTER_SANITIZE_NUMBER_INT);
        if ($capacity >= $expectedAttendees * 0.8 && $capacity <= $expectedAttendees * 1.5) {
            $score += 1.0;
            $reasons[] = 'Capacity matches expected attendees';
        }

        $scoredFacilities[] = [
            'id' => $facility['id'],
            'name' => $facility['name'],
            'capacity' => $facility['capacity'],
            'amenities' => $facility['amenities'] ?? '',
            'operating_hours' => $facility['operating_hours'] ?? null,
            'ml_relevance_score' => round($score, 1),
            'reason' => !empty($reasons) ? implode('; ', $reasons) : 'General purpose facility',
            'distance' => $distance,
            'distance_km' => $distanceKm,
        ];
    }

    usort($scoredFacilities, function ($a, $b) {
        if ($b['ml_relevance_score'] !== $a['ml_relevance_score']) {
            return $b['ml_relevance_score'] <=> $a['ml_relevance_score'];
        }
        $dA = $a['distance_km'] ?? 999;
        $dB = $b['distance_km'] ?? 999;
        return $dA <=> $dB;
    });

    echo json_encode(array_merge($baseResponse, [
        'recommendations' => array_slice($scoredFacilities, 0, 5),
        'ml_enabled' => false,
    ]));

} catch (Exception $e) {
    error_log("Facility recommendation error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Failed to get recommendations']);
}
