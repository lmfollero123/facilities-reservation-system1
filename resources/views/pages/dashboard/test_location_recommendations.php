<?php
/**
 * Test Page for Location-Based Recommendations
 * This page helps verify that location-based recommendations are working correctly
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../../../../config/app.php';

if (!($_SESSION['user_authenticated'] ?? false)) {
    header('Location: ' . base_path() . '/resources/views/pages/auth/login.php');
    exit;
}

require_once __DIR__ . '/../../../../config/database.php';
require_once __DIR__ . '/../../../../config/geocoding.php';
require_once __DIR__ . '/../../../../config/ai_helpers.php';

$pdo = db();
$userId = $_SESSION['user_id'] ?? null;
$userEmail = $_SESSION['user_email'] ?? '';

// Get user data
$userStmt = $pdo->prepare("SELECT id, name, email, address, latitude, longitude FROM users WHERE id = ?");
$userStmt->execute([$userId]);
$user = $userStmt->fetch(PDO::FETCH_ASSOC);

// Get all facilities with coordinates
$facilitiesStmt = $pdo->query(
    "SELECT id, name, location, latitude, longitude, status 
     FROM facilities 
     WHERE status = 'available' 
     ORDER BY name"
);
$facilities = $facilitiesStmt->fetchAll(PDO::FETCH_ASSOC);

// Test recommendations
$testPurpose = "zumba";
$recommendations = [];
if ($userId) {
    $recommendations = recommendFacilities($testPurpose, null, null, $userId, 10);
}

// Calculate distances for all facilities
$userCoords = null;
if ($user && $user['latitude'] !== null && $user['longitude'] !== null) {
    $userCoords = [
        'lat' => (float)$user['latitude'],
        'lng' => (float)$user['longitude'],
    ];
}

$facilitiesWithDistance = [];
foreach ($facilities as $facility) {
    $facilityData = $facility;
    if ($userCoords && $facility['latitude'] !== null && $facility['longitude'] !== null) {
        $distance = calculateDistance(
            $userCoords['lat'],
            $userCoords['lng'],
            (float)$facility['latitude'],
            (float)$facility['longitude']
        );
        $facilityData['distance_km'] = $distance;
        $facilityData['distance_formatted'] = formatDistance($distance);
    } else {
        $facilityData['distance_km'] = null;
        $facilityData['distance_formatted'] = 'N/A';
    }
    $facilitiesWithDistance[] = $facilityData;
}

// Sort by distance
usort($facilitiesWithDistance, function($a, $b) {
    if ($a['distance_km'] === null && $b['distance_km'] === null) return 0;
    if ($a['distance_km'] === null) return 1;
    if ($b['distance_km'] === null) return -1;
    return $a['distance_km'] <=> $b['distance_km'];
});

$pageTitle = 'Test Location Recommendations | LGU Facilities Reservation';

ob_start();
?>
<div class="page-header">
    <div class="breadcrumb">
        <span>Testing</span><span class="sep">/</span><span>Location Recommendations</span>
    </div>
    <h1>Test Location-Based Recommendations</h1>
    <small>Verify that location-based facility recommendations are working correctly</small>
</div>

<div class="booking-wrapper">
    <section class="booking-card">
        <h2>Your Location Information</h2>
        
        <?php if ($user): ?>
            <div style="background:#f5f7fa; padding:1rem; border-radius:8px; margin-bottom:1rem;">
                <p><strong>Name:</strong> <?= htmlspecialchars($user['name']); ?></p>
                <p><strong>Email:</strong> <?= htmlspecialchars($user['email']); ?></p>
                <p><strong>Address:</strong> <?= htmlspecialchars($user['address'] ?? 'Not set'); ?></p>
                <p><strong>Latitude:</strong> 
                    <?php if ($user['latitude'] !== null): ?>
                        <span style="color:#0d7a43; font-weight:600;"><?= htmlspecialchars($user['latitude']); ?></span>
                    <?php else: ?>
                        <span style="color:#b23030;">Not set</span>
                    <?php endif; ?>
                </p>
                <p><strong>Longitude:</strong> 
                    <?php if ($user['longitude'] !== null): ?>
                        <span style="color:#0d7a43; font-weight:600;"><?= htmlspecialchars($user['longitude']); ?></span>
                    <?php else: ?>
                        <span style="color:#b23030;">Not set</span>
                    <?php endif; ?>
                </p>
            </div>
            
            <?php if ($user['latitude'] === null || $user['longitude'] === null): ?>
                <div style="background:#fff3cd; color:#856404; padding:1rem; border-radius:8px; margin-bottom:1rem; border-left:4px solid #ffc107;">
                    <strong>⚠️ Action Required:</strong> Your coordinates are not set. 
                    <a href="<?= base_path(); ?>/resources/views/pages/dashboard/profile.php" style="color:#856404; text-decoration:underline;">Go to Profile</a> to add your address or manually enter coordinates.
                </div>
            <?php else: ?>
                <div style="background:#e3f8ef; color:#0d7a43; padding:1rem; border-radius:8px; margin-bottom:1rem; border-left:4px solid #28a745;">
                    <strong>✓ Coordinates Set:</strong> Location-based recommendations are enabled for your account.
                </div>
            <?php endif; ?>
        <?php else: ?>
            <p style="color:#b23030;">Unable to load user information.</p>
        <?php endif; ?>
    </section>

    <section class="booking-card">
        <h2>Test Recommendations</h2>
        <p>Testing with purpose: <strong>"<?= htmlspecialchars($testPurpose); ?>"</strong></p>
        
        <?php if (empty($recommendations)): ?>
            <p style="color:#8b95b5;">No recommendations available. Make sure you have coordinates set and facilities have coordinates.</p>
        <?php else: ?>
            <table class="table" style="margin-top:1rem;">
                <thead>
                    <tr>
                        <th>Rank</th>
                        <th>Facility</th>
                        <th>Match Score</th>
                        <th>Distance</th>
                        <th>Reasons</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($recommendations as $index => $rec): ?>
                        <tr>
                            <td><strong>#<?= $index + 1; ?></strong></td>
                            <td><?= htmlspecialchars($rec['name']); ?></td>
                            <td>
                                <span style="background:#2563eb; color:#fff; padding:0.25rem 0.5rem; border-radius:4px; font-size:0.85rem;">
                                    <?= $rec['match_score']; ?>%
                                </span>
                            </td>
                            <td>
                                <?php if (isset($rec['distance'])): ?>
                                    <span style="color:#0d7a43; font-weight:600;"><?= htmlspecialchars($rec['distance']); ?></span>
                                <?php else: ?>
                                    <span style="color:#8b95b5;">N/A</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <small style="color:#5c6a8c;">
                                    <?= htmlspecialchars(implode(', ', array_slice($rec['reasons'] ?? [], 0, 2))); ?>
                                </small>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </section>

    <section class="booking-card">
        <h2>All Facilities (Sorted by Distance)</h2>
        
        <?php if (empty($facilitiesWithDistance)): ?>
            <p style="color:#8b95b5;">No facilities available.</p>
        <?php else: ?>
            <table class="table" style="margin-top:1rem;">
                <thead>
                    <tr>
                        <th>Facility</th>
                        <th>Location</th>
                        <th>Coordinates</th>
                        <th>Distance from You</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($facilitiesWithDistance as $facility): ?>
                        <tr>
                            <td><strong><?= htmlspecialchars($facility['name']); ?></strong></td>
                            <td><?= htmlspecialchars($facility['location'] ?? 'N/A'); ?></td>
                            <td>
                                <?php if ($facility['latitude'] !== null && $facility['longitude'] !== null): ?>
                                    <small style="color:#5c6a8c;">
                                        <?= htmlspecialchars($facility['latitude']); ?>, <?= htmlspecialchars($facility['longitude']); ?>
                                    </small>
                                <?php else: ?>
                                    <span style="color:#b23030;">Not set</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($facility['distance_km'] !== null): ?>
                                    <span style="color:#2563eb; font-weight:600;"><?= htmlspecialchars($facility['distance_formatted']); ?></span>
                                <?php else: ?>
                                    <span style="color:#8b95b5;">N/A</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="status-badge <?= $facility['status']; ?>">
                                    <?= ucfirst($facility['status']); ?>
                                </span>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </section>

    <section class="booking-card">
        <h2>Debug Information</h2>
        <div style="background:#f5f7fa; padding:1rem; border-radius:8px; font-family:monospace; font-size:0.85rem;">
            <p><strong>User Coordinates:</strong> 
                <?php if ($userCoords): ?>
                    <?= $userCoords['lat']; ?>, <?= $userCoords['lng']; ?>
                <?php else: ?>
                    <span style="color:#b23030;">Not available</span>
                <?php endif; ?>
            </p>
            <p><strong>Facilities with Coordinates:</strong> 
                <?= count(array_filter($facilities, function($f) { return $f['latitude'] !== null && $f['longitude'] !== null; })); ?> / <?= count($facilities); ?>
            </p>
            <p><strong>Recommendations Returned:</strong> <?= count($recommendations); ?></p>
            <p><strong>Recommendations with Distance:</strong> 
                <?= count(array_filter($recommendations, function($r) { return isset($r['distance']); })); ?>
            </p>
        </div>
    </section>
</div>

<?php
$content = ob_get_clean();
include __DIR__ . '/../../layouts/dashboard_layout.php';



