<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../../../../config/app.php';

if (!($_SESSION['user_authenticated'] ?? false) || !in_array($_SESSION['role'] ?? '', ['Admin', 'Staff'], true)) {
    header('Location: ' . base_path() . '/resources/views/pages/dashboard/index.php');
    exit;
}

require_once __DIR__ . '/../../../../config/database.php';
require_once __DIR__ . '/../../../../config/document_archival.php';
require_once __DIR__ . '/../../../../config/audit.php';

$pdo = db();
$pageTitle = 'Document Management | LGU Facilities Reservation';
$currentUserId = $_SESSION['user_id'] ?? null;

$success = '';
$error = '';

// Handle archival actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['archive_user'])) {
        $userId = (int)$_POST['user_id'];
        try {
            $result = archiveUserDocuments($userId, $currentUserId);
            if ($result['count'] > 0) {
                $success = "Successfully archived {$result['count']} document(s).";
                if (!empty($result['failed'])) {
                    $error = "Failed to archive " . count($result['failed']) . " document(s).";
                }
            } else {
                $error = 'No documents found to archive for this user.';
            }
        } catch (Exception $e) {
            $error = 'Failed to archive documents: ' . $e->getMessage();
        }
    } elseif (isset($_POST['restore_user'])) {
        $userId = (int)$_POST['user_id'];
        try {
            $result = restoreArchivedDocuments($userId, $currentUserId);
            if ($result['count'] > 0) {
                $success = "Successfully restored {$result['count']} document(s).";
                if (!empty($result['failed'])) {
                    $error = "Failed to restore " . count($result['failed']) . " document(s).";
                }
            } else {
                $error = 'No archived documents found for this user.';
            }
        } catch (Exception $e) {
            $error = 'Failed to restore documents: ' . $e->getMessage();
        }
    }
}

// Get storage statistics
$stats = getStorageStatistics();

// Get users for archival
$usersForArchival = getUsersForArchival();

// Get retention policies
$policies = [];
$stmt = $pdo->query('SELECT * FROM document_retention_policy ORDER BY document_type');
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $policies[] = $row;
}

ob_start();
?>
<div class="page-header">
    <div class="breadcrumb">
        <span>Administration</span><span class="sep">/</span><span>Document Management</span>
    </div>
    <h1>Document Management</h1>
    <small>Manage document archival, storage, and retention policies.</small>
</div>

<?php if ($error): ?>
    <div class="message error" style="padding:0.85rem 1rem;border-radius:8px;margin-bottom:1.25rem;background:#fdecee;color:#b23030;">
        <?= htmlspecialchars($error); ?>
    </div>
<?php elseif ($success): ?>
    <div class="message success" style="padding:0.85rem 1rem;border-radius:8px;margin-bottom:1.25rem;background:#e3f8ef;color:#0d7a43;">
        <?= htmlspecialchars($success); ?>
    </div>
<?php endif; ?>

<div class="booking-wrapper">
    <!-- Storage Statistics -->
    <section class="booking-card" style="grid-column: 1 / -1;">
        <h2>Storage Statistics</h2>
        <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap:1.5rem; margin-top:1rem;">
            <div style="padding:1rem; background:linear-gradient(135deg, rgba(37, 99, 235, 0.1), rgba(29, 78, 216, 0.05)); border-radius:8px; border:2px solid rgba(37, 99, 235, 0.2);">
                <div style="font-size:0.85rem; color:#5b6888; margin-bottom:0.5rem;">Active Documents</div>
                <div style="font-size:2rem; font-weight:700; color:#2563eb; margin-bottom:0.25rem;"><?= number_format($stats['active']['count']); ?></div>
                <div style="font-size:0.9rem; color:#5b6888;"><?= number_format($stats['active']['size_mb'], 2); ?> MB</div>
            </div>
            <div style="padding:1rem; background:linear-gradient(135deg, rgba(107, 114, 128, 0.1), rgba(75, 85, 99, 0.05)); border-radius:8px; border:2px solid rgba(107, 114, 128, 0.2);">
                <div style="font-size:0.85rem; color:#5b6888; margin-bottom:0.5rem;">Archived Documents</div>
                <div style="font-size:2rem; font-weight:700; color:#6b7280; margin-bottom:0.25rem;"><?= number_format($stats['archived']['count']); ?></div>
                <div style="font-size:0.9rem; color:#5b6888;"><?= number_format($stats['archived']['size_mb'], 2); ?> MB</div>
            </div>
            <div style="padding:1rem; background:linear-gradient(135deg, rgba(16, 185, 129, 0.1), rgba(5, 150, 105, 0.05)); border-radius:8px; border:2px solid rgba(16, 185, 129, 0.2);">
                <div style="font-size:0.85rem; color:#5b6888; margin-bottom:0.5rem;">Total Documents</div>
                <div style="font-size:2rem; font-weight:700; color:#10b981; margin-bottom:0.25rem;"><?= number_format($stats['total']['count']); ?></div>
                <div style="font-size:0.9rem; color:#5b6888;"><?= number_format($stats['total']['size_mb'], 2); ?> MB</div>
            </div>
        </div>
    </section>

    <!-- Retention Policies -->
    <section class="booking-card">
        <h2>Retention Policies</h2>
        <p style="color:#5b6888; font-size:0.9rem; margin-bottom:1rem;">
            Document retention periods based on Philippine legal requirements (Data Privacy Act, BIR, Local Government retention policies).
        </p>
        <div style="display:flex; flex-direction:column; gap:0.75rem;">
            <?php foreach ($policies as $policy): ?>
                <div style="padding:1rem; background:#f8f9fa; border-radius:6px; border-left:4px solid #2563eb;">
                    <div style="display:flex; justify-content:space-between; align-items:start; flex-wrap:wrap; gap:0.5rem;">
                        <div style="flex:1;">
                            <h4 style="margin:0 0 0.25rem; color:#1b1b1f; font-size:1rem; font-weight:600;">
                                <?= ucwords(str_replace('_', ' ', htmlspecialchars($policy['document_type']))); ?>
                            </h4>
                            <p style="margin:0 0 0.5rem; color:#5b6888; font-size:0.85rem; line-height:1.5;">
                                <?= htmlspecialchars($policy['description'] ?? 'No description'); ?>
                            </p>
                            <div style="display:flex; gap:1rem; flex-wrap:wrap; font-size:0.85rem;">
                                <div>
                                    <span style="color:#5b6888;">Retention:</span>
                                    <strong style="color:#1b1b1f;"><?= $policy['retention_days']; ?> days</strong>
                                </div>
                                <div>
                                    <span style="color:#5b6888;">Archive After:</span>
                                    <strong style="color:#2563eb;"><?= $policy['archive_after_days']; ?> days</strong>
                                </div>
                                <?php if ($policy['auto_delete_after_days']): ?>
                                    <div>
                                        <span style="color:#5b6888;">Auto-Delete:</span>
                                        <strong style="color:#dc3545;"><?= $policy['auto_delete_after_days']; ?> days</strong>
                                    </div>
                                <?php else: ?>
                                    <div>
                                        <span style="color:#5b6888;">Auto-Delete:</span>
                                        <strong style="color:#6b7280;">Never (Manual Review)</strong>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </section>

    <!-- Users for Archival -->
    <section class="booking-card">
        <h2>Users Eligible for Archival</h2>
        <p style="color:#5b6888; font-size:0.9rem; margin-bottom:1rem;">
            Users with documents older than <?= DOCUMENT_ACTIVE_RETENTION_DAYS; ?> days (<?= round(DOCUMENT_ACTIVE_RETENTION_DAYS / 365, 1); ?> years) are eligible for archival.
        </p>
        
        <?php if (empty($usersForArchival)): ?>
            <div style="padding:2rem; text-align:center; color:#5b6888;">
                <p style="margin:0;">No users currently eligible for archival.</p>
                <small>All documents are within the active retention period.</small>
            </div>
        <?php else: ?>
            <div style="overflow-x:auto;">
                <table style="width:100%; border-collapse:collapse;">
                    <thead>
                        <tr style="background:#f8f9fa; border-bottom:2px solid #e1e7f0;">
                            <th style="padding:0.75rem; text-align:left; font-weight:600; color:#1b1b1f;">User</th>
                            <th style="padding:0.75rem; text-align:left; font-weight:600; color:#1b1b1f;">Email</th>
                            <th style="padding:0.75rem; text-align:left; font-weight:600; color:#1b1b1f;">Status</th>
                            <th style="padding:0.75rem; text-align:center; font-weight:600; color:#1b1b1f;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach (array_slice($usersForArchival, 0, 20) as $user): ?>
                            <?php
                            // Get document count for this user
                            $docStmt = $pdo->prepare(
                                'SELECT COUNT(*) as count FROM user_documents WHERE user_id = ? AND is_archived = FALSE'
                            );
                            $docStmt->execute([$user['user_id']]);
                            $docCount = $docStmt->fetch(PDO::FETCH_ASSOC)['count'];
                            ?>
                            <tr style="border-bottom:1px solid #e1e7f0;">
                                <td style="padding:0.75rem;">
                                    <strong style="color:#1b1b1f;"><?= htmlspecialchars($user['name']); ?></strong>
                                    <div style="font-size:0.85rem; color:#5b6888; margin-top:0.25rem;">
                                        <?= $docCount; ?> document(s) to archive
                                    </div>
                                </td>
                                <td style="padding:0.75rem; color:#5b6888;"><?= htmlspecialchars($user['email']); ?></td>
                                <td style="padding:0.75rem;">
                                    <span class="status-badge <?= $user['status'] === 'active' ? 'active' : strtolower($user['status']); ?>" style="font-size:0.85rem; padding:0.35rem 0.75rem;">
                                        <?= ucfirst(htmlspecialchars($user['status'])); ?>
                                    </span>
                                </td>
                                <td style="padding:0.75rem; text-align:center;">
                                    <form method="POST" style="display:inline;" onsubmit="return confirm('Archive all documents for <?= htmlspecialchars($user['name'], ENT_QUOTES); ?>? Documents will be moved to archive storage.');">
                                        <input type="hidden" name="user_id" value="<?= $user['user_id']; ?>">
                                        <input type="hidden" name="archive_user" value="1">
                                        <button type="submit" class="btn-primary" style="padding:0.5rem 1rem; font-size:0.85rem;">Archive Documents</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php if (count($usersForArchival) > 20): ?>
                <div style="margin-top:1rem; padding:0.75rem; background:#f8f9fa; border-radius:6px; text-align:center; color:#5b6888; font-size:0.9rem;">
                    Showing first 20 of <?= count($usersForArchival); ?> users. Run archival script for batch processing.
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </section>
</div>

<?php
$content = ob_get_clean();
// From resources/views/pages/dashboard -> resources/views/layouts
include __DIR__ . '/../../layouts/dashboard_layout.php';

