<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../../../../config/app.php';
require_once __DIR__ . '/../../../../config/database.php';
require_once __DIR__ . '/../../../../config/data_export.php';

// Require authentication
if (!($_SESSION['user_authenticated'] ?? false)) {
    header('Location: ' . base_path() . '/resources/views/pages/auth/login.php');
    exit;
}

$pdo = db();
$base = base_path();
$currentUserId = $_SESSION['user_id'] ?? null;
$role = $_SESSION['role'] ?? '';

$exportId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$pageTitle = 'My Data Export | LGU Facilities Reservation';

$error = '';
$data = null;
$exportMeta = null;

if ($exportId <= 0) {
    $error = 'Invalid export ID.';
} else {
    $exportMeta = getExportFile($exportId);

    if (!$exportMeta) {
        $error = 'The requested export was not found or has already expired.';
    } else {
        // Authorization: owner or admin/staff
        if ($exportMeta['user_id'] !== $currentUserId && !in_array($role, ['Admin', 'Staff'], true)) {
            $error = 'You are not allowed to view this export.';
        } else {
            $filePath = app_root_path() . '/' . $exportMeta['file_path'];
            if (!file_exists($filePath)) {
                $error = 'The export file could not be found on the server.';
            } else {
                $raw = file_get_contents($filePath);
                $decoded = json_decode($raw, true);
                if (!is_array($decoded)) {
                    $error = 'Unable to read export data.';
                } else {
                    $data = $decoded;
                }
            }
        }
    }
}

ob_start();
?>
<div class="page-header">
    <div class="breadcrumb">
        <span>Account</span><span class="sep">/</span><span>My Data Export</span>
    </div>
    <h1>My Data Export</h1>
    <small>Printable view of your account information and reservation history.</small>
</div>

<?php if ($error): ?>
    <div class="message error" style="padding:0.85rem 1rem;border-radius:8px;margin-bottom:1.25rem;background:#fdecee;color:#b23030;">
        <?= htmlspecialchars($error); ?>
    </div>
<?php elseif ($data && isset($data['user'])): ?>
    <?php $user = $data['user']; ?>
    <div class="booking-wrapper" style="font-size:1rem; line-height:1.6;">
        <section class="booking-card" style="grid-column:1 / -1;">
            <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:1rem;">
                <div>
                    <h2 style="margin:0 0 0.5rem; font-size:1.6rem; color:#1b1b1f;">Summary</h2>
                    <p style="margin:0; color:#5b6888;">
                        Export type: <strong><?= ucfirst(htmlspecialchars($data['export_type'] ?? 'full')); ?></strong><br>
                        Generated on: <strong><?= htmlspecialchars($data['exported_at'] ?? ($exportMeta['created_at'] ?? '')); ?></strong>
                    </p>
                </div>
                <div style="display:flex; gap:0.75rem; flex-wrap:wrap;">
                    <a href="<?= $base . '/resources/views/pages/dashboard/profile.php'; ?>" class="btn-outline" style="text-decoration:none; padding:0.6rem 1rem;">Back to Profile</a>
                    <button type="button" class="btn-primary" onclick="window.print();" style="padding:0.6rem 1rem;">Print / Save as PDF</button>
                </div>
            </div>
        </section>

        <!-- Profile Details -->
        <section class="booking-card" style="grid-column:1 / -1;">
            <h2 style="font-size:1.4rem; margin-bottom:0.75rem;">Your Profile Details</h2>
            <div style="display:grid; grid-template-columns:repeat(auto-fit,minmax(220px,1fr)); gap:1rem;">
                <div>
                    <div style="font-weight:600; color:#1b1b1f;">Full Name</div>
                    <div style="color:#111827; font-size:1.05rem;"><?= htmlspecialchars($user['name'] ?? ''); ?></div>
                </div>
                <div>
                    <div style="font-weight:600; color:#1b1b1f;">Email Address</div>
                    <div style="color:#111827; font-size:1.05rem;"><?= htmlspecialchars($user['email'] ?? ''); ?></div>
                </div>
                <div>
                    <div style="font-weight:600; color:#1b1b1f;">Mobile Number</div>
                    <div style="color:#111827; font-size:1.05rem;"><?= htmlspecialchars($user['mobile'] ?? ''); ?></div>
                </div>
                <div>
                    <div style="font-weight:600; color:#1b1b1f;">Address</div>
                    <div style="color:#111827; font-size:1.05rem;"><?= htmlspecialchars($user['address'] ?? ''); ?></div>
                </div>
                <div>
                    <div style="font-weight:600; color:#1b1b1f;">Role</div>
                    <div style="color:#111827; font-size:1.05rem;"><?= htmlspecialchars($user['role'] ?? 'Resident'); ?></div>
                </div>
                <div>
                    <div style="font-weight:600; color:#1b1b1f;">Account Status</div>
                    <div style="color:#111827; font-size:1.05rem;"><?= htmlspecialchars($user['status'] ?? ''); ?></div>
                </div>
                <div>
                    <div style="font-weight:600; color:#1b1b1f;">Member Since</div>
                    <div style="color:#111827; font-size:1.05rem;"><?= htmlspecialchars($user['created_at'] ?? ''); ?></div>
                </div>
            </div>
        </section>

        <!-- Reservations -->
        <?php if (!empty($user['reservations'])): ?>
            <section class="booking-card" style="grid-column:1 / -1;">
                <h2 style="font-size:1.4rem; margin-bottom:0.75rem;">Your Reservations</h2>
                <p style="color:#5b6888; font-size:0.95rem; margin-bottom:0.75rem;">A list of your past and upcoming reservations.</p>
                <div style="overflow-x:auto;">
                    <table style="width:100%; border-collapse:collapse; font-size:0.95rem;">
                        <thead>
                            <tr style="background:#f3f4f6;">
                                <th style="text-align:left; padding:0.6rem; border-bottom:1px solid #e5e7eb;">Date</th>
                                <th style="text-align:left; padding:0.6rem; border-bottom:1px solid #e5e7eb;">Time</th>
                                <th style="text-align:left; padding:0.6rem; border-bottom:1px solid #e5e7eb;">Facility</th>
                                <th style="text-align:left; padding:0.6rem; border-bottom:1px solid #e5e7eb;">Status</th>
                                <th style="text-align:left; padding:0.6rem; border-bottom:1px solid #e5e7eb;">Purpose</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($user['reservations'] as $res): ?>
                                <tr style="border-bottom:1px solid #e5e7eb;">
                                    <td style="padding:0.6rem;"><?= htmlspecialchars($res['reservation_date'] ?? ''); ?></td>
                                    <td style="padding:0.6rem;"><?= htmlspecialchars($res['time_slot'] ?? ($res['start_time'] ?? '') . (isset($res['end_time']) ? ' - ' . $res['end_time'] : '')); ?></td>
                                    <td style="padding:0.6rem;"><?= htmlspecialchars($res['facility_name'] ?? ''); ?></td>
                                    <td style="padding:0.6rem;"><?= ucfirst(htmlspecialchars($res['status'] ?? '')); ?></td>
                                    <td style="padding:0.6rem; max-width:260px;"><?= nl2br(htmlspecialchars($res['purpose'] ?? '')); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </section>
        <?php endif; ?>

        <!-- Documents -->
        <?php if (!empty($user['documents'])): ?>
            <section class="booking-card" style="grid-column:1 / -1;">
                <h2 style="font-size:1.4rem; margin-bottom:0.75rem;">Your Uploaded Documents</h2>
                <p style="color:#5b6888; font-size:0.95rem; margin-bottom:0.75rem;">Documents you submitted for verification (e.g., Valid ID).</p>
                <div style="overflow-x:auto;">
                    <table style="width:100%; border-collapse:collapse; font-size:0.95rem;">
                        <thead>
                            <tr style="background:#f3f4f6;">
                                <th style="text-align:left; padding:0.6rem; border-bottom:1px solid #e5e7eb;">Type</th>
                                <th style="text-align:left; padding:0.6rem; border-bottom:1px solid #e5e7eb;">File Name</th>
                                <th style="text-align:left; padding:0.6rem; border-bottom:1px solid #e5e7eb;">Size</th>
                                <th style="text-align:left; padding:0.6rem; border-bottom:1px solid #e5e7eb;">Uploaded</th>
                                <th style="text-align:left; padding:0.6rem; border-bottom:1px solid #e5e7eb;">Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($user['documents'] as $doc): ?>
                                <tr style="border-bottom:1px solid #e5e7eb;">
                                    <td style="padding:0.6rem;"><?= ucwords(str_replace('_', ' ', htmlspecialchars($doc['document_type'] ?? ''))); ?></td>
                                    <td style="padding:0.6rem;"><?= htmlspecialchars($doc['file_name'] ?? ''); ?></td>
                                    <td style="padding:0.6rem;"><?= isset($doc['file_size']) ? round(($doc['file_size'] / 1024), 1) . ' KB' : ''; ?></td>
                                    <td style="padding:0.6rem;"><?= htmlspecialchars($doc['uploaded_at'] ?? ''); ?></td>
                                    <td style="padding:0.6rem;"><?= !empty($doc['is_archived']) ? 'Archived' : 'Active'; ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </section>
        <?php endif; ?>

        <!-- Violations -->
        <?php if (!empty($user['violations'])): ?>
            <section class="booking-card" style="grid-column:1 / -1;">
                <h2 style="font-size:1.4rem; margin-bottom:0.75rem;">Recorded Violations</h2>
                <p style="color:#5b6888; font-size:0.95rem; margin-bottom:0.75rem;">For transparency, this section lists any recorded violations that may affect auto-approval.</p>
                <div style="overflow-x:auto;">
                    <table style="width:100%; border-collapse:collapse; font-size:0.95rem;">
                        <thead>
                            <tr style="background:#f3f4f6;">
                                <th style="text-align:left; padding:0.6rem; border-bottom:1px solid #e5e7eb;">Date</th>
                                <th style="text-align:left; padding:0.6rem; border-bottom:1px solid #e5e7eb;">Type</th>
                                <th style="text-align:left; padding:0.6rem; border-bottom:1px solid #e5e7eb;">Severity</th>
                                <th style="text-align:left; padding:0.6rem; border-bottom:1px solid #e5e7eb;">Details</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($user['violations'] as $vio): ?>
                                <tr style="border-bottom:1px solid #e5e7eb;">
                                    <td style="padding:0.6rem;"><?= htmlspecialchars($vio['created_at'] ?? ''); ?></td>
                                    <td style="padding:0.6rem;"><?= ucwords(str_replace('_', ' ', htmlspecialchars($vio['violation_type'] ?? ''))); ?></td>
                                    <td style="padding:0.6rem;"><?= ucfirst(htmlspecialchars($vio['severity'] ?? '')); ?></td>
                                    <td style="padding:0.6rem; max-width:260px;"><?= nl2br(htmlspecialchars($vio['description'] ?? '')); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </section>
        <?php endif; ?>

        <!-- Note for printing -->
        <section class="booking-card" style="grid-column:1 / -1; background:#f9fafb;">
            <h2 style="font-size:1.2rem; margin-bottom:0.5rem;">How to Save as PDF</h2>
            <p style="color:#5b6888; font-size:0.95rem; margin-bottom:0.5rem;">
                To save this report as a PDF:
            </p>
            <ol style="color:#4b5563; font-size:0.95rem; padding-left:1.25rem; margin:0;">
                <li>Click the <strong>Print / Save as PDF</strong> button at the top of the page.</li>
                <li>In the Print dialog, choose <strong>Save as PDF</strong> as the destination printer.</li>
                <li>Click <strong>Save</strong> and choose where to store the PDF file.</li>
            </ol>
        </section>
    </div>
<?php endif; ?>

<?php
$content = ob_get_clean();
include __DIR__ . '/../../layouts/dashboard_layout.php';


