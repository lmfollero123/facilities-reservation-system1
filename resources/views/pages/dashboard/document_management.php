<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../../../../config/app.php';

// RBAC: Document Management is Admin-only (archive/purge, data governance)
if (!($_SESSION['user_authenticated'] ?? false) || ($_SESSION['role'] ?? '') !== 'Admin') {
    header('Location: ' . base_path() . '/dashboard');
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
    <section class="booking-card" style="grid-column: 1 / -1;">
        <h2>Retention Policies</h2>
        <div style="background: #f0f7ff; border-left: 4px solid #2563eb; padding: 1rem; border-radius: 6px; margin-bottom: 1.5rem;">
            <h3 style="margin: 0 0 0.75rem 0; color: #1e3a5f; font-size: 1.1rem;">Legal Basis</h3>
            <p style="margin: 0 0 0.5rem 0; color: #374151; line-height: 1.6; font-size: 0.95rem;">
                Document retention periods are aligned with Philippine laws, including:
            </p>
            <ul style="margin: 0; padding-left: 1.5rem; color: #4a5568; line-height: 1.8; font-size: 0.9rem;">
                <li><strong>Republic Act No. 10173</strong> – Data Privacy Act of 2012</li>
                <li><strong>Republic Act No. 9470</strong> – National Archives of the Philippines Act of 2007</li>
                <li><strong>COA Circular No. 2012-003</strong> – Prescribes retention of government records for audit and accountability</li>
                <li><strong>BIR Revenue Regulations No. 9-2009</strong> – Books of accounts and supporting records (minimum 10 years)</li>
                <li><strong>Local Government Code of 1991 (RA 7160)</strong> – Establishes LGU accountability, transparency, and records management duties</li>
            </ul>
            <p style="margin: 0.75rem 0 0 0; color: #6b7280; font-size: 0.85rem; font-style: italic;">
                <strong>Note:</strong> Final disposal of records is subject to LGU approval and applicable COA and National Archives guidelines.
            </p>
        </div>

        <div style="display:flex; flex-direction:column; gap:1.25rem;">
            <!-- User Documents -->
            <div style="padding:1.25rem; background:#ffffff; border:2px solid #e5e7eb; border-radius:8px; border-left:4px solid #2563eb;">
                <div style="margin-bottom:1rem;">
                    <h3 style="margin:0 0 0.5rem; color:#1e3a5f; font-size:1.15rem; font-weight:700;">1. User Documents (Identity Records)</h3>
                    <div style="background:#f9fafb; padding:0.75rem; border-radius:6px; margin-bottom:0.75rem;">
                        <strong style="color:#374151; font-size:0.9rem;">Legal Basis:</strong>
                        <ul style="margin:0.5rem 0 0 0; padding-left:1.5rem; color:#6b7280; font-size:0.85rem; line-height:1.6;">
                            <li>RA 10173 (Data Privacy Act)</li>
                            <li>RA 9470 (National Archives Act)</li>
                            <li>BIR RR 9-2009 (Supporting records)</li>
                        </ul>
                    </div>
                    <p style="margin:0 0 0.75rem 0; color:#4a5568; line-height:1.6; font-size:0.9rem;">
                        <strong>Policy:</strong> Identity documents are retained only as long as necessary for verification, accountability, and audit purposes.
                    </p>
                    <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap:1rem; margin-top:1rem;">
                        <div style="padding:0.75rem; background:#f0f9ff; border-radius:6px;">
                            <div style="font-size:0.85rem; color:#5b6888; margin-bottom:0.25rem;">Retention Period</div>
                            <div style="font-size:1.25rem; font-weight:700; color:#2563eb;">7 years</div>
                            <div style="font-size:0.8rem; color:#6b7280; margin-top:0.25rem;">2555 days</div>
                        </div>
                        <div style="padding:0.75rem; background:#fef3c7; border-radius:6px;">
                            <div style="font-size:0.85rem; color:#5b6888; margin-bottom:0.25rem;">Archive After</div>
                            <div style="font-size:1.25rem; font-weight:700; color:#d97706;">3 years</div>
                            <div style="font-size:0.8rem; color:#6b7280; margin-top:0.25rem;">1095 days</div>
                        </div>
                        <div style="padding:0.75rem; background:#fee2e2; border-radius:6px;">
                            <div style="font-size:0.85rem; color:#5b6888; margin-bottom:0.25rem;">Auto-Delete</div>
                            <div style="font-size:1.25rem; font-weight:700; color:#dc2626;">7 years</div>
                            <div style="font-size:0.8rem; color:#6b7280; margin-top:0.25rem;">2555 days</div>
                        </div>
                    </div>
                    <p style="margin:1rem 0 0 0; padding:0.75rem; background:#e0f2fe; border-radius:6px; color:#0c4a6e; font-size:0.85rem; line-height:1.5;">
                        <strong>Justification:</strong> Seven years balances audit defensibility and data minimization under the Data Privacy Act.
                    </p>
                </div>
            </div>

            <!-- Reservation Records -->
            <div style="padding:1.25rem; background:#ffffff; border:2px solid #e5e7eb; border-radius:8px; border-left:4px solid #10b981;">
                <div style="margin-bottom:1rem;">
                    <h3 style="margin:0 0 0.5rem; color:#1e3a5f; font-size:1.15rem; font-weight:700;">2. Reservation Records</h3>
                    <div style="background:#f9fafb; padding:0.75rem; border-radius:6px; margin-bottom:0.75rem;">
                        <strong style="color:#374151; font-size:0.9rem;">Legal Basis:</strong>
                        <ul style="margin:0.5rem 0 0 0; padding-left:1.5rem; color:#6b7280; font-size:0.85rem; line-height:1.6;">
                            <li>RA 7160 (Local Government Code)</li>
                            <li>RA 9470 (National Archives Act)</li>
                            <li>COA Circular No. 2012-003</li>
                        </ul>
                    </div>
                    <p style="margin:0 0 0.75rem 0; color:#4a5568; line-height:1.6; font-size:0.9rem;">
                        <strong>Policy:</strong> Reservation records are treated as official LGU transaction records.
                    </p>
                    <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap:1rem; margin-top:1rem;">
                        <div style="padding:0.75rem; background:#f0f9ff; border-radius:6px;">
                            <div style="font-size:0.85rem; color:#5b6888; margin-bottom:0.25rem;">Retention Period</div>
                            <div style="font-size:1.25rem; font-weight:700; color:#2563eb;">5 years</div>
                            <div style="font-size:0.8rem; color:#6b7280; margin-top:0.25rem;">1825 days</div>
                        </div>
                        <div style="padding:0.75rem; background:#fef3c7; border-radius:6px;">
                            <div style="font-size:0.85rem; color:#5b6888; margin-bottom:0.25rem;">Archive After</div>
                            <div style="font-size:1.25rem; font-weight:700; color:#d97706;">3 years</div>
                            <div style="font-size:0.8rem; color:#6b7280; margin-top:0.25rem;">1095 days</div>
                        </div>
                        <div style="padding:0.75rem; background:#fee2e2; border-radius:6px;">
                            <div style="font-size:0.85rem; color:#5b6888; margin-bottom:0.25rem;">Auto-Delete</div>
                            <div style="font-size:1.25rem; font-weight:700; color:#dc2626;">5 years</div>
                            <div style="font-size:0.8rem; color:#6b7280; margin-top:0.25rem;">1825 days</div>
                        </div>
                    </div>
                    <p style="margin:1rem 0 0 0; padding:0.75rem; background:#d1fae5; border-radius:6px; color:#065f46; font-size:0.85rem; line-height:1.5;">
                        <strong>Justification:</strong> Five years is standard for government transaction records unless escalated to audit or dispute.
                    </p>
                </div>
            </div>

            <!-- Reservation History -->
            <div style="padding:1.25rem; background:#ffffff; border:2px solid #e5e7eb; border-radius:8px; border-left:4px solid #8b5cf6;">
                <div style="margin-bottom:1rem;">
                    <h3 style="margin:0 0 0.5rem; color:#1e3a5f; font-size:1.15rem; font-weight:700;">3. Reservation History (User-Facing Logs)</h3>
                    <div style="background:#f9fafb; padding:0.75rem; border-radius:6px; margin-bottom:0.75rem;">
                        <strong style="color:#374151; font-size:0.9rem;">Legal Basis:</strong>
                        <ul style="margin:0.5rem 0 0 0; padding-left:1.5rem; color:#6b7280; font-size:0.85rem; line-height:1.6;">
                            <li>RA 10173 (Data Privacy Act)</li>
                            <li>RA 9470 (National Archives Act)</li>
                        </ul>
                    </div>
                    <p style="margin:0 0 0.75rem 0; color:#4a5568; line-height:1.6; font-size:0.9rem;">
                        <strong>Policy:</strong> Reservation history mirrors reservation record retention to maintain consistency.
                    </p>
                    <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap:1rem; margin-top:1rem;">
                        <div style="padding:0.75rem; background:#f0f9ff; border-radius:6px;">
                            <div style="font-size:0.85rem; color:#5b6888; margin-bottom:0.25rem;">Retention Period</div>
                            <div style="font-size:1.25rem; font-weight:700; color:#2563eb;">5 years</div>
                            <div style="font-size:0.8rem; color:#6b7280; margin-top:0.25rem;">1825 days</div>
                        </div>
                        <div style="padding:0.75rem; background:#fef3c7; border-radius:6px;">
                            <div style="font-size:0.85rem; color:#5b6888; margin-bottom:0.25rem;">Archive After</div>
                            <div style="font-size:1.25rem; font-weight:700; color:#d97706;">3 years</div>
                            <div style="font-size:0.8rem; color:#6b7280; margin-top:0.25rem;">1095 days</div>
                        </div>
                        <div style="padding:0.75rem; background:#fee2e2; border-radius:6px;">
                            <div style="font-size:0.85rem; color:#5b6888; margin-bottom:0.25rem;">Auto-Delete</div>
                            <div style="font-size:1.25rem; font-weight:700; color:#dc2626;">5 years</div>
                            <div style="font-size:0.8rem; color:#6b7280; margin-top:0.25rem;">1825 days</div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Audit Logs -->
            <div style="padding:1.25rem; background:#ffffff; border:2px solid #e5e7eb; border-radius:8px; border-left:4px solid #f59e0b;">
                <div style="margin-bottom:1rem;">
                    <h3 style="margin:0 0 0.5rem; color:#1e3a5f; font-size:1.15rem; font-weight:700;">4. Audit Logs</h3>
                    <div style="background:#f9fafb; padding:0.75rem; border-radius:6px; margin-bottom:0.75rem;">
                        <strong style="color:#374151; font-size:0.9rem;">Legal Basis:</strong>
                        <ul style="margin:0.5rem 0 0 0; padding-left:1.5rem; color:#6b7280; font-size:0.85rem; line-height:1.6;">
                            <li>COA Circular No. 2012-003</li>
                            <li>RA 7160 (Local Government Code)</li>
                            <li>RA 9470 (National Archives Act)</li>
                        </ul>
                    </div>
                    <p style="margin:0 0 0.75rem 0; color:#4a5568; line-height:1.6; font-size:0.9rem;">
                        <strong>Policy:</strong> Audit logs are critical accountability records and must not be automatically deleted.
                    </p>
                    <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap:1rem; margin-top:1rem;">
                        <div style="padding:0.75rem; background:#f0f9ff; border-radius:6px;">
                            <div style="font-size:0.85rem; color:#5b6888; margin-bottom:0.25rem;">Retention Period</div>
                            <div style="font-size:1.25rem; font-weight:700; color:#2563eb;">7 years minimum</div>
                            <div style="font-size:0.8rem; color:#6b7280; margin-top:0.25rem;">2555 days</div>
                        </div>
                        <div style="padding:0.75rem; background:#fef3c7; border-radius:6px;">
                            <div style="font-size:0.85rem; color:#5b6888; margin-bottom:0.25rem;">Archive After</div>
                            <div style="font-size:1.25rem; font-weight:700; color:#d97706;">5 years</div>
                            <div style="font-size:0.8rem; color:#6b7280; margin-top:0.25rem;">1825 days</div>
                        </div>
                        <div style="padding:0.75rem; background:#f3f4f6; border-radius:6px;">
                            <div style="font-size:0.85rem; color:#5b6888; margin-bottom:0.25rem;">Auto-Delete</div>
                            <div style="font-size:1.25rem; font-weight:700; color:#6b7280;">Not Allowed</div>
                            <div style="font-size:0.8rem; color:#6b7280; margin-top:0.25rem;">Manual Review Required</div>
                        </div>
                    </div>
                    <p style="margin:1rem 0 0 0; padding:0.75rem; background:#fef3c7; border-radius:6px; color:#92400e; font-size:0.85rem; line-height:1.5;">
                        <strong>Justification:</strong> Audit logs may be required beyond 7 years for investigations, COA review, or legal proceedings.
                    </p>
                </div>
            </div>

            <!-- Security Logs -->
            <div style="padding:1.25rem; background:#ffffff; border:2px solid #e5e7eb; border-radius:8px; border-left:4px solid #ef4444;">
                <div style="margin-bottom:1rem;">
                    <h3 style="margin:0 0 0.5rem; color:#1e3a5f; font-size:1.15rem; font-weight:700;">5. Security Logs</h3>
                    <div style="background:#f9fafb; padding:0.75rem; border-radius:6px; margin-bottom:0.75rem;">
                        <strong style="color:#374151; font-size:0.9rem;">Legal Basis:</strong>
                        <ul style="margin:0.5rem 0 0 0; padding-left:1.5rem; color:#6b7280; font-size:0.85rem; line-height:1.6;">
                            <li>RA 10173 (Security of personal data)</li>
                            <li>NPC Advisory Opinions on breach investigation</li>
                            <li>RA 9470 (National Archives Act)</li>
                        </ul>
                    </div>
                    <p style="margin:0 0 0.75rem 0; color:#4a5568; line-height:1.6; font-size:0.9rem;">
                        <strong>Policy:</strong> Security logs are retained for incident response, forensic review, and compliance reporting.
                    </p>
                    <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap:1rem; margin-top:1rem;">
                        <div style="padding:0.75rem; background:#f0f9ff; border-radius:6px;">
                            <div style="font-size:0.85rem; color:#5b6888; margin-bottom:0.25rem;">Retention Period</div>
                            <div style="font-size:1.25rem; font-weight:700; color:#2563eb;">3 years</div>
                            <div style="font-size:0.8rem; color:#6b7280; margin-top:0.25rem;">1095 days</div>
                        </div>
                        <div style="padding:0.75rem; background:#fef3c7; border-radius:6px;">
                            <div style="font-size:0.85rem; color:#5b6888; margin-bottom:0.25rem;">Archive After</div>
                            <div style="font-size:1.25rem; font-weight:700; color:#d97706;">2 years</div>
                            <div style="font-size:0.8rem; color:#6b7280; margin-top:0.25rem;">730 days</div>
                        </div>
                        <div style="padding:0.75rem; background:#f3f4f6; border-radius:6px;">
                            <div style="font-size:0.85rem; color:#5b6888; margin-bottom:0.25rem;">Auto-Delete</div>
                            <div style="font-size:1.25rem; font-weight:700; color:#6b7280;">Not Allowed</div>
                            <div style="font-size:0.8rem; color:#6b7280; margin-top:0.25rem;">Manual Review Required</div>
                        </div>
                    </div>
                    <p style="margin:1rem 0 0 0; padding:0.75rem; background:#fee2e2; border-radius:6px; color:#991b1b; font-size:0.85rem; line-height:1.5;">
                        <strong>Justification:</strong> Logs may be required as evidence in data breach or misuse investigations.
                    </p>
                </div>
            </div>
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

