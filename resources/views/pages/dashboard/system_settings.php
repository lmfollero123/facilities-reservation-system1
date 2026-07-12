<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../../../../config/app.php';

if (!($_SESSION['user_authenticated'] ?? false) || ($_SESSION['role'] ?? '') !== 'Admin') {
    header('Location: ' . base_path() . '/dashboard');
    exit;
}

require_once __DIR__ . '/../../../../config/database.php';
require_once __DIR__ . '/../../../../config/audit.php';
require_once __DIR__ . '/../../../../config/lookups.php';
require_once __DIR__ . '/../../../../config/permissions.php';
require_once __DIR__ . '/../../../../config/integration_status.php';

$pdo = db();
$pageTitle = 'System Settings | LGU Facilities Reservation';
$message = '';
$messageType = 'success';
$tablesReady = frs_lookups_table_ready($pdo);
$rolePermissionsTableReady = false;
try {
    $pdo->query('SELECT 1 FROM role_permissions LIMIT 1');
    $rolePermissionsTableReady = true;
} catch (Throwable $e) {
    $rolePermissionsTableReady = false;
}
$canShowSettingsLayout = true;
$activeCategory = preg_replace('/[^a-z0-9_]/', '', (string)($_GET['category'] ?? '')) ?: '';
if ($activeCategory === '') {
    $activeCategory = 'integrations';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && frs_csrf_ok()) {
    $action = $_POST['action'] ?? '';
    try {
        if ($action === 'sync_uman_requests') {
            require_once __DIR__ . '/../../../../services/uman_api.php';
            $count = frs_sync_local_uman_requests($pdo);
            $message = $count > 0
                ? "Synced {$count} UMAN request status update(s)."
                : 'UMAN request statuses are up to date (or API unavailable).';
            $messageType = 'success';
            logAudit('Synced UMAN requests', 'System Settings', (string)$count);
            $activeCategory = 'integrations';
        } elseif ($action === 'update_permissions') {
            if (!$rolePermissionsTableReady) {
                $message = 'Role permissions table not installed. Run migration_add_role_permissions.sql.';
                $messageType = 'error';
            } else {
                $role = trim($_POST['role'] ?? '');
                $permissionKey = trim($_POST['permission_key'] ?? '');
                $permissions = [
                    'create' => ((int)($_POST['can_create'] ?? 0)) === 1,
                    'read' => ((int)($_POST['can_read'] ?? 0)) === 1,
                    'update' => ((int)($_POST['can_update'] ?? 0)) === 1,
                    'delete' => ((int)($_POST['can_delete'] ?? 0)) === 1,
                ];
                $result = frs_update_permission($role, $permissionKey, $permissions);
                $message = $result['message'];
                $messageType = $result['ok'] ? 'success' : 'error';
                if ($result['ok']) {
                    logAudit('Updated role permissions', 'System Settings', $role . ' - ' . $permissionKey);
                    $activeCategory = 'role_permissions';
                }
            }
        } elseif (!$tablesReady) {
            $message = 'Lookup tables are not installed. Run database/migration_add_system_lookups.sql.';
            $messageType = 'error';
        } elseif ($action === 'add_value') {
            $category = preg_replace('/[^a-z0-9_]/', '', (string)($_POST['category'] ?? ''));
            $label = trim($_POST['label'] ?? '');
            $slug = trim($_POST['slug'] ?? '');
            $blocksBooking = isset($_POST['blocks_booking']);
            $result = frs_lookup_add_value($pdo, $category, $label, $slug !== '' ? $slug : null, [
                'blocks_booking' => $blocksBooking,
                'badge_class' => frs_slugify_lookup($slug !== '' ? $slug : $label),
            ]);
            $message = $result['message'];
            $messageType = $result['ok'] ? 'success' : 'error';
            if ($result['ok']) {
                logAudit('Added lookup value', 'System Settings', $category . ': ' . $label);
                $activeCategory = $category;
            }
        } elseif ($action === 'update_value') {
            $valueId = (int)($_POST['value_id'] ?? 0);
            $label = trim($_POST['label'] ?? '');
            $isActive = isset($_POST['is_active']);
            $blocksBooking = isset($_POST['blocks_booking']);
            $result = frs_lookup_update_value($pdo, $valueId, $label, $isActive, [
                'blocks_booking' => $blocksBooking,
                'badge_class' => frs_slugify_lookup($label),
            ]);
            $message = $result['message'];
            $messageType = $result['ok'] ? 'success' : 'error';
            if ($result['ok']) {
                logAudit('Updated lookup value', 'System Settings', 'ID ' . $valueId . ': ' . $label);
            }
        } elseif ($action === 'delete_value') {
            $valueId = (int)($_POST['value_id'] ?? 0);
            $result = frs_lookup_delete_value($pdo, $valueId);
            $message = $result['message'];
            $messageType = $result['ok'] ? 'success' : 'error';
            if ($result['ok']) {
                logAudit('Deleted lookup value', 'System Settings', 'ID ' . $valueId);
            }
        }
    } catch (Throwable $e) {
        $message = 'Unable to save changes. Please try again.';
        $messageType = 'error';
    }
}

$categories = frs_lookup_categories($pdo);
array_unshift($categories, [
    'id' => 0,
    'slug' => 'integrations',
    'name' => 'Integrations',
    'description' => 'Monitor CIMM, UMAN, and Infrastructure connections. Run sync actions from one place.',
]);
if ($rolePermissionsTableReady && !in_array('role_permissions', array_column($categories, 'slug'), true)) {
    $categories[] = [
        'id' => 0,
        'slug' => 'role_permissions',
        'name' => 'Role Permissions',
        'description' => 'Configure CRUD permissions for Staff and Resident roles per module.',
    ];
}
$categoryValues = $tablesReady ? frs_lookup_values($pdo, $activeCategory, false) : frs_lookup_fallback_values($activeCategory);

// Load permissions data for role_permissions category
$roles = frs_get_roles();
// Exclude Admin from permissions matrix since they have override access
$roles = array_filter($roles, fn($role) => $role !== 'Admin');
$permissionKeys = frs_get_permission_keys();
$allPermissions = [];
foreach ($roles as $role) {
    $allPermissions[$role] = frs_get_role_permissions($role);
}
$integrationCards = frs_integration_status_all($pdo);

ob_start();
?>
<div class="page-header">
    <div class="breadcrumb">
        <span>Administration</span><span class="sep">/</span><span>System Settings</span>
    </div>
    <?= frs_page_title('System Settings', 'Manage integrations, dropdown lookups, and role permissions.'); ?>
</div>

<?php if ($message): ?>
    <div class="ss-alert ss-alert-<?= $messageType === 'success' ? 'success' : 'error'; ?>" role="status">
        <?= htmlspecialchars($message); ?>
    </div>
<?php endif; ?>

<?php if (!$canShowSettingsLayout): ?>
    <div class="booking-card ss-notice">
        <strong>Setup required</strong>
        <p>Run <code>database/migration_add_system_lookups.sql</code> or <code>database/migration_add_role_permissions.sql</code> to enable system settings.</p>
    </div>
<?php else: ?>

<div class="ss-layout">
    <!-- Sidebar Navigation -->
    <aside class="ss-sidebar">
        <div class="ss-sidebar-header">
            <h2>Categories</h2>
            <p class="ss-sidebar-subtitle">Manage system-wide dropdown options</p>
        </div>
        <nav class="ss-cat-nav" aria-label="Lookup categories">
            <?php foreach ($categories as $cat): ?>
                <a href="?category=<?= urlencode($cat['slug']); ?>"
                   class="ss-cat-link<?= $activeCategory === $cat['slug'] ? ' is-active' : ''; ?>">
                    <i class="bi bi-folder ss-cat-icon"></i>
                    <span class="ss-cat-name"><?= htmlspecialchars($cat['name']); ?></span>
                    <?php if ($activeCategory === $cat['slug']): ?>
                        <i class="bi bi-chevron-right ss-cat-arrow"></i>
                    <?php endif; ?>
                </a>
            <?php endforeach; ?>
        </nav>
        <div class="ss-sidebar-footer">
            <p class="ss-sidebar-note">More categories (reservation statuses, inquiry types) can be added later using the same system.</p>
        </div>
    </aside>

    <!-- Main Content -->
    <main class="ss-main">
        <?php
        $catMeta = null;
        foreach ($categories as $cat) {
            if ($cat['slug'] === $activeCategory) {
                $catMeta = $cat;
                break;
            }
        }
        ?>

        <!-- Page Header -->
        <div class="ss-page-header">
            <div class="ss-page-title">
                <h1><?= htmlspecialchars($catMeta['name'] ?? 'Category'); ?></h1>
                <?php if (!empty($catMeta['description'])): ?>
                    <p class="ss-page-description"><?= htmlspecialchars($catMeta['description']); ?></p>
                <?php endif; ?>
            </div>
            <?php if ($activeCategory === 'facility_status'): ?>
            <button type="button" class="ss-btn-primary ss-btn-large" onclick="document.getElementById('add-modal').classList.add('is-open')">
                <i class="bi bi-plus-lg ss-btn-icon"></i>
                Add New Status
            </button>
            <?php endif; ?>
        </div>

        <?php if ($activeCategory === 'integrations'): ?>
        <div class="ss-integrations-grid">
            <?php foreach ($integrationCards as $card): ?>
                <article class="ss-integration-card">
                    <div class="ss-integration-head">
                        <div>
                            <h2><?= htmlspecialchars((string)$card['name']); ?></h2>
                            <p class="ss-page-description"><?= htmlspecialchars((string)$card['description']); ?></p>
                        </div>
                        <span class="status-badge <?= htmlspecialchars((string)($card['status_class'] ?? 'offline')); ?>">
                            <?= htmlspecialchars((string)($card['status_label'] ?? 'Unknown')); ?>
                        </span>
                    </div>
                    <?php if (!empty($card['preview'])): ?>
                        <p class="ss-integration-note">Preview module — sample or disconnected data until an external API is configured.</p>
                    <?php endif; ?>
                    <div class="ss-integration-meta">
                        <span>Last sync:
                            <?= !empty($card['last_sync'])
                                ? date('M d, Y H:i', strtotime((string)$card['last_sync']))
                                : 'Never'; ?>
                        </span>
                        <?php if (!empty($card['manage_url'])): ?>
                            <a href="<?= htmlspecialchars((string)$card['manage_url']); ?>">Open module →</a>
                        <?php endif; ?>
                    </div>
                    <?php if (!empty($card['metrics']) && is_array($card['metrics'])): ?>
                        <div class="ss-integration-metrics">
                            <?php foreach ($card['metrics'] as $label => $value): ?>
                                <span><strong><?= htmlspecialchars((string)$label); ?>:</strong> <?= htmlspecialchars((string)$value); ?></span>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                    <?php if (!empty($card['errors'])): ?>
                        <div class="ss-integration-errors">
                            <?php foreach ((array)$card['errors'] as $err): ?>
                                <div><?= htmlspecialchars((string)$err); ?></div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                    <div class="ss-integration-actions">
                        <?php if (($card['sync_type'] ?? '') === 'ajax' && !empty($card['can_sync'])): ?>
                            <button type="button" class="ss-btn-primary" data-cimm-sync data-sync-url="<?= htmlspecialchars((string)$card['sync_url']); ?>">Sync Now</button>
                        <?php elseif (($card['sync_type'] ?? '') === 'form' && !empty($card['can_sync'])): ?>
                            <form method="POST" style="margin:0;">
                                <?= csrf_field(); ?>
                                <input type="hidden" name="action" value="sync_uman_requests">
                                <button type="submit" class="ss-btn-primary">Sync Request Status</button>
                            </form>
                        <?php else: ?>
                            <button type="button" class="ss-btn-secondary" disabled>Sync unavailable</button>
                        <?php endif; ?>
                        <?php if (!empty($card['cron_hint'])): ?>
                            <small class="ss-hint">Cron: <code><?= htmlspecialchars((string)$card['cron_hint']); ?></code></small>
                        <?php endif; ?>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>
        <?php elseif ($activeCategory === 'role_permissions'): ?>
        <!-- Role Permissions Grid -->
        <div class="ss-permissions-card">
            <div class="ss-permissions-header">
                <h2>Role Permissions Matrix</h2>
                <p class="ss-permissions-subtitle">Configure CRUD permissions for each role per module</p>
            </div>
            <div class="ss-permissions-table-wrapper">
                <table class="ss-permissions-table">
                    <thead>
                        <tr>
                            <th class="ss-perm-role-col">Role / Module</th>
                            <?php foreach ($permissionKeys as $key): ?>
                            <th class="ss-perm-action-col"><?= htmlspecialchars(ucwords(str_replace('_', ' ', $key))); ?></th>
                            <?php endforeach; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($roles as $role): ?>
                        <tr>
                            <td class="ss-perm-role-cell">
                                <strong><?= htmlspecialchars($role); ?></strong>
                            </td>
                            <?php foreach ($permissionKeys as $key): ?>
                            <td class="ss-perm-action-cell">
                                <?php
                                $perms = $allPermissions[$role][$key] ?? ['create' => false, 'read' => false, 'update' => false, 'delete' => false];
                                ?>
                                <form method="POST" class="ss-perm-form">
                                    <?= csrf_field(); ?>
                                    <input type="hidden" name="action" value="update_permissions">
                                    <input type="hidden" name="role" value="<?= htmlspecialchars($role); ?>">
                                    <input type="hidden" name="permission_key" value="<?= htmlspecialchars($key); ?>">
                                    <div class="ss-perm-checks">
                                        <label class="ss-perm-check">
                                            <input type="hidden" name="can_create" value="0">
                                            <input type="checkbox" name="can_create" value="1" <?= $perms['create'] ? 'checked' : ''; ?> onchange="this.form.submit()">
                                            <span>Create</span>
                                        </label>
                                        <label class="ss-perm-check">
                                            <input type="hidden" name="can_read" value="0">
                                            <input type="checkbox" name="can_read" value="1" <?= $perms['read'] ? 'checked' : ''; ?> onchange="this.form.submit()">
                                            <span>Read</span>
                                        </label>
                                        <label class="ss-perm-check">
                                            <input type="hidden" name="can_update" value="0">
                                            <input type="checkbox" name="can_update" value="1" <?= $perms['update'] ? 'checked' : ''; ?> onchange="this.form.submit()">
                                            <span>Update</span>
                                        </label>
                                        <label class="ss-perm-check">
                                            <input type="hidden" name="can_delete" value="0">
                                            <input type="checkbox" name="can_delete" value="1" <?= $perms['delete'] ? 'checked' : ''; ?> onchange="this.form.submit()">
                                            <span>Delete</span>
                                        </label>
                                    </div>
                                </form>
                            </td>
                            <?php endforeach; ?>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <div class="ss-permissions-legend">
                <span class="ss-legend-item"><span class="ss-legend-dot ss-legend-admin"></span> Admin: Full access (not shown - always has all permissions)</span>
                <span class="ss-legend-item"><span class="ss-legend-dot ss-legend-staff"></span> Staff: Limited access</span>
                <span class="ss-legend-item"><span class="ss-legend-dot ss-legend-resident"></span> Resident: Self-only access</span>
            </div>
        </div>
        <?php elseif (!$tablesReady): ?>
        <div class="booking-card ss-notice">
            <strong>Setup required</strong>
            <p>Run <code>database/migration_add_system_lookups.sql</code> on your database to enable configurable lookup categories.</p>
        </div>
        <?php else: ?>
        <div class="ss-stats-grid">
            <div class="ss-stat-card">
                <div class="ss-stat-icon"><i class="bi bi-bar-chart"></i></div>
                <div class="ss-stat-content">
                    <div class="ss-stat-value"><?= count($categoryValues); ?></div>
                    <div class="ss-stat-label">Total Items</div>
                </div>
            </div>
            <div class="ss-stat-card">
                <div class="ss-stat-icon"><i class="bi bi-check-circle"></i></div>
                <div class="ss-stat-content">
                    <div class="ss-stat-value"><?= count(array_filter($categoryValues, fn($v) => !empty($v['is_active']))); ?></div>
                    <div class="ss-stat-label">Active</div>
                </div>
            </div>
            <div class="ss-stat-card">
                <div class="ss-stat-icon"><i class="bi bi-shield-lock"></i></div>
                <div class="ss-stat-content">
                    <div class="ss-stat-value"><?= count(array_filter($categoryValues, fn($v) => !empty($v['is_system']))); ?></div>
                    <div class="ss-stat-label">System Defaults</div>
                </div>
            </div>
        </div>

        <!-- Values Table -->
        <div class="ss-table-card">
            <div class="ss-table-header">
                <h2><?= htmlspecialchars($catMeta['name'] ?? 'Items'); ?></h2>
                <div class="ss-table-actions">
                    <input type="text" placeholder="Search items..." class="ss-search-input" id="ss-search" onkeyup="filterTable()">
                </div>
            </div>
            <?php if ($categoryValues === []): ?>
                <div class="ss-empty-state">
                    <div class="ss-empty-icon"><i class="bi bi-inbox"></i></div>
                    <h3>No items yet</h3>
                    <p>Add your first <?= htmlspecialchars($catMeta['name'] ?? 'item'); ?> to get started.</p>
                    <?php if ($activeCategory === 'facility_status'): ?>
                    <button type="button" class="ss-btn-primary" onclick="document.getElementById('add-modal').classList.add('is-open')">
                        Add First Status
                    </button>
                    <?php endif; ?>
                </div>
            <?php else: ?>
                <div class="ss-table-container">
                    <table class="ss-table" id="ss-values-table">
                        <thead>
                            <tr>
                                <th>Label</th>
                                <th>Key</th>
                                <th>Status</th>
                                <?php if ($activeCategory === 'facility_status'): ?>
                                <th>Blocks Booking</th>
                                <?php endif; ?>
                                <th>Usage</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($categoryValues as $val): ?>
                                <?php
                                $meta = is_array($val['metadata'] ?? null) ? $val['metadata'] : [];
                                $blocks = !empty($meta['blocks_booking']);
                                $inUseCount = 0;
                                if ($activeCategory === 'facility_status') {
                                    $c = $pdo->prepare('SELECT COUNT(*) FROM facilities WHERE status = ?');
                                    $c->execute([(string)$val['slug']]);
                                    $inUseCount = (int)$c->fetchColumn();
                                }
                                ?>
                                <tr class="ss-table-row" data-label="<?= strtolower(htmlspecialchars((string)$val['label'])); ?>">
                                   <td>
                                        <div class="ss-cell-content">
                                            <span class="ss-cell-label"><?= htmlspecialchars((string)$val['label']); ?></span>
                                            <?php if (!empty($val['is_system'])): ?>
                                                <span class="ss-badge-small ss-badge-system">System</span>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                    <td><code class="ss-cell-code"><?= htmlspecialchars((string)$val['slug']); ?></code></td>
                                    <td>
                                        <span class="ss-status-badge <?= !empty($val['is_active']) ? 'ss-status-active' : 'ss-status-inactive' ?>">
                                            <?= !empty($val['is_active']) ? 'Active' : 'Inactive' ?>
                                        </span>
                                    </td>
                                    <?php if ($activeCategory === 'facility_status'): ?>
                                    <td>
                                        <span class="ss-bool-badge <?= $blocks ? 'ss-bool-yes' : 'ss-bool-no' ?>">
                                            <?= $blocks ? 'Yes' : 'No' ?>
                                        </span>
                                    </td>
                                    <?php endif; ?>
                                    <td>
                                        <?php if ($inUseCount > 0): ?>
                                            <span class="ss-usage-badge"><?= $inUseCount; ?> facility<?= $inUseCount === 1 ? '' : 'ies'; ?></span>
                                        <?php else: ?>
                                            <span class="ss-usage-none">—</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div class="ss-action-buttons">
                                            <button type="button" class="ss-btn-icon-btn ss-btn-edit"
                                                data-edit-id="<?= (int)$val['id']; ?>"
                                                data-edit-label="<?= htmlspecialchars((string)$val['label'], ENT_QUOTES, 'UTF-8'); ?>"
                                                data-edit-slug="<?= htmlspecialchars((string)$val['slug'], ENT_QUOTES, 'UTF-8'); ?>"
                                                data-edit-active="<?= !empty($val['is_active']) ? '1' : '0'; ?>"
                                                data-edit-blocks="<?= $blocks ? '1' : '0'; ?>"
                                                onclick="openEditFromRow(this)" title="Edit">
                                                <i class="bi bi-pencil"></i>
                                            </button>
                                            <?php if (empty($val['is_system'])): ?>
                                            <button type="button" class="ss-btn-icon-btn ss-btn-delete"
                                                data-delete-id="<?= (int)$val['id']; ?>"
                                                data-delete-label="<?= htmlspecialchars((string)$val['label'], ENT_QUOTES, 'UTF-8'); ?>"
                                                onclick="confirmDeleteFromRow(this)" title="Delete">
                                                <i class="bi bi-trash"></i>
                                            </button>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </main>
</div>

<!-- Add Modal -->
<?php if ($activeCategory === 'facility_status'): ?>
<div id="add-modal" class="ss-modal-overlay">
    <div class="ss-modal">
        <div class="ss-modal-header">
            <h2>Add New Status</h2>
            <button type="button" class="ss-modal-close" onclick="document.getElementById('add-modal').classList.remove('is-open')">×</button>
        </div>
        <form method="POST" class="ss-modal-form">
            <?= csrf_field(); ?>
            <input type="hidden" name="action" value="add_value">
            <input type="hidden" name="category" value="facility_status">
            <div class="ss-form-group">
                <label for="add-label">Display Label <span class="ss-required">*</span></label>
                <input type="text" id="add-label" name="label" required maxlength="128" placeholder="e.g. Under Renovation">
                <small class="ss-hint">The name shown to users in dropdowns</small>
            </div>
            <div class="ss-form-group">
                <label for="add-slug">Key (optional)</label>
                <input type="text" id="add-slug" name="slug" maxlength="64" pattern="[a-z0-9_]+" placeholder="under_renovation">
                <small class="ss-hint">Lowercase letters, numbers, underscores only. Auto-generated if blank.</small>
            </div>
            <div class="ss-form-check">
                <label class="ss-checkbox-label">
                    <input type="checkbox" name="blocks_booking" value="1" checked>
                    <span class="ss-checkbox-custom"></span>
                    <span>Blocks new bookings when assigned to a facility</span>
                </label>
            </div>
            <div class="ss-modal-actions">
                <button type="button" class="ss-btn-secondary" onclick="document.getElementById('add-modal').classList.remove('is-open')">Cancel</button>
                <button type="submit" class="ss-btn-primary">Add Status</button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<!-- Edit Modal -->
<div id="edit-modal" class="ss-modal-overlay">
    <div class="ss-modal">
        <div class="ss-modal-header">
            <h2>Edit Item</h2>
            <button type="button" class="ss-modal-close" onclick="document.getElementById('edit-modal').classList.remove('is-open')">×</button>
        </div>
        <form method="POST" class="ss-modal-form">
            <?= csrf_field(); ?>
            <input type="hidden" name="action" value="update_value">
            <input type="hidden" name="value_id" id="edit-value-id">
            <div class="ss-form-group">
                <label for="edit-label">Display Label <span class="ss-required">*</span></label>
                <input type="text" id="edit-label" name="label" required maxlength="128">
            </div>
            <?php if ($activeCategory === 'facility_status'): ?>
            <div class="ss-form-check">
                <label class="ss-checkbox-label">
                    <input type="checkbox" name="blocks_booking" id="edit-blocks-booking" value="1">
                    <span class="ss-checkbox-custom"></span>
                    <span>Blocks new bookings when assigned to a facility</span>
                </label>
            </div>
            <?php endif; ?>
            <div class="ss-form-check">
                <label class="ss-checkbox-label">
                    <input type="checkbox" name="is_active" id="edit-is-active" value="1">
                    <span class="ss-checkbox-custom"></span>
                    <span>Active (shown in dropdowns)</span>
                </label>
            </div>
            <div class="ss-modal-actions">
                <button type="button" class="ss-btn-secondary" onclick="document.getElementById('edit-modal').classList.remove('is-open')">Cancel</button>
                <button type="submit" class="ss-btn-primary">Save Changes</button>
            </div>
        </form>
    </div>
</div>

<!-- Delete Modal -->
<div id="delete-modal" class="ss-modal-overlay">
    <div class="ss-modal ss-modal-small">
        <div class="ss-modal-header">
            <h2>Confirm Delete</h2>
            <button type="button" class="ss-modal-close" onclick="document.getElementById('delete-modal').classList.remove('is-open')">×</button>
        </div>
        <div class="ss-modal-body">
            <p>Are you sure you want to delete <strong id="delete-item-name"></strong>? This action cannot be undone.</p>
        </div>
        <form method="POST" class="ss-modal-actions">
            <?= csrf_field(); ?>
            <input type="hidden" name="action" value="delete_value">
            <input type="hidden" name="value_id" id="delete-value-id">
            <button type="button" class="ss-btn-secondary" onclick="document.getElementById('delete-modal').classList.remove('is-open')">Cancel</button>
            <button type="submit" class="ss-btn-danger">Delete</button>
        </form>
    </div>
</div>

<script>
function openEditFromRow(btn) {
    openEditModal(
        btn.dataset.editId,
        btn.dataset.editLabel || '',
        btn.dataset.editSlug || '',
        btn.dataset.editActive === '1',
        btn.dataset.editBlocks === '1'
    );
}

function confirmDeleteFromRow(btn) {
    confirmDelete(btn.dataset.deleteId, btn.dataset.deleteLabel || '');
}

function openEditModal(id, label, slug, isActive, blocksBooking) {
    document.getElementById('edit-value-id').value = id;
    document.getElementById('edit-label').value = label;
    document.getElementById('edit-is-active').checked = isActive;
    const blocksBookingEl = document.getElementById('edit-blocks-booking');
    if (blocksBookingEl) {
        blocksBookingEl.checked = blocksBooking;
    }
    document.getElementById('edit-modal').classList.add('is-open');
}

function confirmDelete(id, name) {
    document.getElementById('delete-value-id').value = id;
    document.getElementById('delete-item-name').textContent = name;
    document.getElementById('delete-modal').classList.add('is-open');
}

function filterTable() {
    const searchInput = document.getElementById('ss-search');
    if (!searchInput) return;
    const search = searchInput.value.toLowerCase();
    const rows = document.querySelectorAll('#ss-values-table tbody tr');
    rows.forEach(row => {
        const label = row.getAttribute('data-label') || '';
        row.style.display = label.includes(search) ? '' : 'none';
    });
}

document.querySelectorAll('[data-cimm-sync]').forEach(function(btn) {
    btn.addEventListener('click', function() {
        const url = btn.getAttribute('data-sync-url');
        if (!url) return;
        const original = btn.textContent;
        btn.disabled = true;
        btn.textContent = 'Syncing…';
        fetch(url, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Accept': 'application/json', 'X-Requested-With': 'FRS-CIMM-Sync' }
        })
        .then(function(r) { return r.json().then(function(data) { return { ok: r.ok, data: data }; }); })
        .then(function(result) {
            if (result.ok && result.data && result.data.success) {
                window.location.href = window.location.pathname + '?category=integrations';
                return;
            }
            alert((result.data && (result.data.message || result.data.error)) || 'Sync failed.');
            btn.disabled = false;
            btn.textContent = original;
        })
        .catch(function() {
            alert('Sync request failed. Check server logs or cron configuration.');
            btn.disabled = false;
            btn.textContent = original;
        });
    });
});

// Close modals on overlay click
document.querySelectorAll('.ss-modal-overlay').forEach(modal => {
    modal.addEventListener('click', function(e) {
        if (e.target === this) {
            this.classList.remove('is-open');
        }
    });
});
</script>
<?php endif; ?>

<style>
/* Alert & Notice */
.ss-alert { padding: 1rem 1.25rem; border-radius: 12px; margin-bottom: 1.5rem; font-size: 0.9rem; }
.ss-alert-success { background: #d1fae5; color: #065f46; border: 1px solid #6ee7b7; }
.ss-alert-error { background: #fee2e2; color: #991b1b; border: 1px solid #fca5a5; }
.ss-notice { padding: 1.25rem; background: #fef3c7; border: 1px solid #fde68a; color: #92400e; border-radius: 12px; }

/* Layout */
.ss-layout { display: grid; grid-template-columns: 260px 1fr; gap: 2rem; align-items: start; min-height: calc(100vh - 200px); }

/* Sidebar */
.ss-sidebar { position: sticky; top: 1rem; }
.ss-sidebar-header { margin-bottom: 1.5rem; }
.ss-sidebar-header h2 { margin: 0 0 0.5rem; font-size: 1.25rem; color: #1e293b; }
.ss-sidebar-subtitle { margin: 0; font-size: 0.85rem; color: #64748b; }
.ss-cat-nav { display: flex; flex-direction: column; gap: 0.5rem; }
.ss-cat-link { display: flex; align-items: center; gap: 0.75rem; padding: 0.85rem 1rem; border-radius: 10px; text-decoration: none; color: #475569; background: #f8fafc; border: 1px solid transparent; transition: all 0.2s ease; }
.ss-cat-link:hover { background: #f1f5f9; color: #1e293b; }
.ss-cat-link.is-active { background: linear-gradient(135deg, #3b82f6 0%, #2563eb 100%); color: white; border-color: transparent; box-shadow: 0 4px 12px rgba(59, 130, 246, 0.3); }
.ss-cat-icon { font-size: 1.1rem; }
.ss-cat-name { flex: 1; font-weight: 500; }
.ss-cat-arrow { font-size: 0.9rem; opacity: 0.7; }
.ss-sidebar-footer { margin-top: 2rem; padding-top: 1.5rem; border-top: 1px solid #e2e8f0; }
.ss-sidebar-note { margin: 0; font-size: 0.8rem; color: #64748b; line-height: 1.6; }

/* Main Content */
.ss-main { }
.ss-page-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 2rem; padding-bottom: 1.5rem; border-bottom: 2px solid #e2e8f0; }
.ss-page-title h1 { margin: 0 0 0.5rem; font-size: 1.75rem; color: #1e293b; }
.ss-page-description { margin: 0; font-size: 0.95rem; color: #64748b; }

/* Buttons */
.ss-btn-primary { display: inline-flex; align-items: center; gap: 0.5rem; padding: 0.75rem 1.5rem; background: linear-gradient(135deg, #3b82f6 0%, #2563eb 100%); color: white; border: none; border-radius: 10px; font-size: 0.9rem; font-weight: 600; cursor: pointer; transition: all 0.2s ease; box-shadow: 0 4px 12px rgba(59, 130, 246, 0.3); }
.ss-btn-primary:hover { transform: translateY(-2px); box-shadow: 0 6px 16px rgba(59, 130, 246, 0.4); }
.ss-btn-large { padding: 1rem 2rem; font-size: 1rem; }
.ss-btn-secondary { padding: 0.75rem 1.5rem; background: #f1f5f9; color: #475569; border: 1px solid #e2e8f0; border-radius: 10px; font-size: 0.9rem; font-weight: 600; cursor: pointer; transition: all 0.2s ease; }
.ss-btn-secondary:hover { background: #e2e8f0; }
.ss-btn-danger { padding: 0.75rem 1.5rem; background: linear-gradient(135deg, #ef4444 0%, #dc2626 100%); color: white; border: none; border-radius: 10px; font-size: 0.9rem; font-weight: 600; cursor: pointer; transition: all 0.2s ease; }
.ss-btn-danger:hover { transform: translateY(-2px); box-shadow: 0 6px 16px rgba(239, 68, 68, 0.4); }
.ss-btn-icon { font-size: 1.1rem; }

/* Stats Grid */
.ss-stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 1.5rem; margin-bottom: 2rem; }
.ss-stat-card { display: flex; align-items: center; gap: 1rem; padding: 1.5rem; background: linear-gradient(135deg, #ffffff 0%, #f8fafc 100%); border: 1px solid #e2e8f0; border-radius: 16px; box-shadow: 0 2px 8px rgba(0, 0, 0, 0.04); transition: all 0.2s ease; }
.ss-stat-card:hover { transform: translateY(-2px); box-shadow: 0 4px 16px rgba(0, 0, 0, 0.08); }
.ss-stat-icon { font-size: 2rem; display: flex; align-items: center; justify-content: center; }
.ss-stat-value { font-size: 2rem; font-weight: 700; color: #1e293b; line-height: 1; }
.ss-stat-label { font-size: 0.85rem; color: #64748b; margin-top: 0.25rem; }

/* Table Card */
.ss-table-card { background: white; border: 1px solid #e2e8f0; border-radius: 16px; overflow: hidden; box-shadow: 0 2px 8px rgba(0, 0, 0, 0.04); }
.ss-table-header { display: flex; justify-content: space-between; align-items: center; padding: 1.5rem; border-bottom: 1px solid #e2e8f0; background: #f8fafc; }
.ss-table-header h2 { margin: 0; font-size: 1.1rem; color: #1e293b; }
.ss-table-actions { }
.ss-search-input { padding: 0.6rem 1rem; border: 1px solid #e2e8f0; border-radius: 8px; font-size: 0.9rem; width: 250px; transition: all 0.2s ease; }
.ss-search-input:focus { outline: none; border-color: #3b82f6; box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1); }
.ss-table-container { overflow-x: auto; }
.ss-table { width: 100%; border-collapse: collapse; }
.ss-table thead { background: #f8fafc; }
.ss-table th { padding: 1rem 1.25rem; text-align: left; font-size: 0.8rem; font-weight: 600; color: #64748b; text-transform: uppercase; letter-spacing: 0.05em; }
.ss-table td { padding: 1rem 1.25rem; border-bottom: 1px solid #f1f5f9; }
.ss-table-row:hover { background: #f8fafc; }
.ss-cell-content { display: flex; align-items: center; gap: 0.5rem; }
.ss-cell-label { font-weight: 500; color: #1e293b; }
.ss-cell-code { background: #f1f5f9; padding: 0.25rem 0.5rem; border-radius: 6px; font-size: 0.8rem; color: #64748b; font-family: monospace; }
.ss-badge-small { font-size: 0.7rem; font-weight: 600; padding: 0.15rem 0.4rem; border-radius: 4px; }
.ss-badge-system { background: #dbeafe; color: #1d4ed8; }
.ss-status-badge { display: inline-block; padding: 0.35rem 0.75rem; border-radius: 20px; font-size: 0.8rem; font-weight: 600; }
.ss-status-active { background: #d1fae5; color: #065f46; }
.ss-status-inactive { background: #f1f5f9; color: #64748b; }
.ss-bool-badge { display: inline-block; padding: 0.35rem 0.75rem; border-radius: 20px; font-size: 0.8rem; font-weight: 600; }
.ss-bool-yes { background: #d1fae5; color: #065f46; }
.ss-bool-no { background: #fee2e2; color: #991b1b; }
.ss-usage-badge { background: #fef3c7; color: #92400e; padding: 0.35rem 0.75rem; border-radius: 20px; font-size: 0.8rem; font-weight: 600; }
.ss-usage-none { color: #cbd5e1; }
.ss-action-buttons { display: flex; gap: 0.5rem; }
.ss-btn-icon-btn { width: 36px; height: 36px; display: flex; align-items: center; justify-content: center; border: 1px solid #e2e8f0; border-radius: 8px; background: white; cursor: pointer; transition: all 0.2s ease; font-size: 1rem; }
.ss-btn-icon-btn:hover { border-color: #3b82f6; background: #f8fafc; transform: translateY(-2px); }
.ss-btn-delete:hover { border-color: #ef4444; background: #fef2f2; }

/* Empty State */
.ss-empty-state { padding: 4rem 2rem; text-align: center; }
.ss-empty-icon { font-size: 4rem; margin-bottom: 1rem; display: flex; align-items: center; justify-content: center; color: #cbd5e1; }
.ss-empty-state h3 { margin: 0 0 0.5rem; font-size: 1.25rem; color: #1e293b; }
.ss-empty-state p { margin: 0 1.5rem 1.5rem; color: #64748b; }

/* Modal */
.ss-modal-overlay { position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0, 0, 0, 0.5); display: none; align-items: center; justify-content: center; z-index: 9999; padding: 1rem; backdrop-filter: blur(4px); }
.ss-modal-overlay.is-open { display: flex; }
.ss-modal { background: white; border-radius: 16px; box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3); max-width: 500px; width: 100%; max-height: 90vh; overflow-y: auto; }
.ss-modal-small { max-width: 400px; }
.ss-modal-header { display: flex; justify-content: space-between; align-items: center; padding: 1.5rem; border-bottom: 1px solid #e2e8f0; }
.ss-modal-header h2 { margin: 0; font-size: 1.25rem; color: #1e293b; }
.ss-modal-close { width: 32px; height: 32px; display: flex; align-items: center; justify-content: center; border: none; background: #f1f5f9; border-radius: 8px; cursor: pointer; font-size: 1.25rem; color: #64748b; transition: all 0.2s ease; }
.ss-modal-close:hover { background: #e2e8f0; color: #1e293b; }
.ss-modal-form { padding: 1.5rem; }
.ss-modal-body { padding: 1.5rem; }
.ss-modal-body p { margin: 0; color: #475569; line-height: 1.6; }
.ss-modal-actions { display: flex; justify-content: flex-end; gap: 0.75rem; padding: 1.5rem; border-top: 1px solid #e2e8f0; }
.ss-form-group { margin-bottom: 1.25rem; }
.ss-form-group label { display: block; margin-bottom: 0.5rem; font-size: 0.9rem; font-weight: 600; color: #374151; }
.ss-form-group input[type="text"] { width: 100%; padding: 0.75rem 1rem; border: 1px solid #e2e8f0; border-radius: 8px; font-size: 0.95rem; transition: all 0.2s ease; }
.ss-form-group input[type="text"]:focus { outline: none; border-color: #3b82f6; box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1); }
.ss-hint { display: block; margin-top: 0.4rem; font-size: 0.8rem; color: #64748b; }
.ss-required { color: #ef4444; }
.ss-form-check { margin-bottom: 1rem; }
.ss-checkbox-label { display: flex; align-items: center; gap: 0.75rem; cursor: pointer; font-size: 0.9rem; color: #374151; }
.ss-checkbox-label input[type="checkbox"] { display: none; }
.ss-checkbox-custom { width: 20px; height: 20px; border: 2px solid #e2e8f0; border-radius: 6px; display: flex; align-items: center; justify-content: center; transition: all 0.2s ease; }
.ss-checkbox-label input:checked + .ss-checkbox-custom { background: #3b82f6; border-color: #3b82f6; }
.ss-checkbox-label input:checked + .ss-checkbox-custom::after { content: '✓'; color: white; font-size: 0.8rem; font-weight: bold; }

/* Dark Mode */
html[data-theme="dark"] .ss-sidebar-header h2 { color: var(--text-primary, #f1f5f9); }
html[data-theme="dark"] .ss-sidebar-subtitle { color: var(--text-secondary, #94a3b8); }
html[data-theme="dark"] .ss-cat-link { background: var(--bg-tertiary, #1e293b); color: var(--text-secondary, #cbd5e1); border-color: var(--border-color, #334155); }
html[data-theme="dark"] .ss-cat-link:hover { background: #334155; color: var(--text-primary, #f1f5f9); }
html[data-theme="dark"] .ss-cat-link.is-active { background: linear-gradient(135deg, #3b82f6 0%, #2563eb 100%); color: white; }
html[data-theme="dark"] .ss-sidebar-footer { border-color: var(--border-color, #334155); }
html[data-theme="dark"] .ss-sidebar-note { color: var(--text-secondary, #94a3b8); }
html[data-theme="dark"] .ss-page-header { border-color: var(--border-color, #334155); }
html[data-theme="dark"] .ss-page-title h1 { color: var(--text-primary, #f1f5f9); }
html[data-theme="dark"] .ss-page-description { color: var(--text-secondary, #94a3b8); }
html[data-theme="dark"] .ss-stat-card { background: var(--card-bg, #1e293b); border-color: var(--border-color, #334155); }
html[data-theme="dark"] .ss-stat-value { color: var(--text-primary, #f1f5f9); }
html[data-theme="dark"] .ss-stat-label { color: var(--text-secondary, #94a3b8); }
html[data-theme="dark"] .ss-table-card { background: var(--card-bg, #1e293b); border-color: var(--border-color, #334155); }
html[data-theme="dark"] .ss-table-header { background: var(--bg-tertiary, #0f172a); border-color: var(--border-color, #334155); }
html[data-theme="dark"] .ss-table-header h2 { color: var(--text-primary, #f1f5f9); }
html[data-theme="dark"] .ss-search-input { background: var(--input-bg, #0f172a); border-color: var(--border-color, #334155); color: var(--text-primary, #f1f5f9); }
html[data-theme="dark"] .ss-search-input:focus { border-color: #3b82f6; }
html[data-theme="dark"] .ss-table thead { background: var(--bg-tertiary, #0f172a); }
html[data-theme="dark"] .ss-table th { color: var(--text-secondary, #94a3b8); }
html[data-theme="dark"] .ss-table td { border-color: var(--border-color, #1e293b); }
html[data-theme="dark"] .ss-table-row:hover { background: var(--bg-tertiary, #0f172a); }
html[data-theme="dark"] .ss-cell-label { color: var(--text-primary, #f1f5f9); }
html[data-theme="dark"] .ss-cell-code { background: #334155; color: #94a3b8; }
html[data-theme="dark"] .ss-btn-icon-btn { background: var(--card-bg, #1e293b); border-color: var(--border-color, #334155); }
html[data-theme="dark"] .ss-btn-icon-btn:hover { border-color: #3b82f6; background: #334155; }
html[data-theme="dark"] .ss-empty-state h3 { color: var(--text-primary, #f1f5f9); }
html[data-theme="dark"] .ss-empty-state p { color: var(--text-secondary, #94a3b8); }
html[data-theme="dark"] .ss-empty-icon { color: #475569; }
html[data-theme="dark"] .ss-modal { background: var(--card-bg, #1e293b); }
html[data-theme="dark"] .ss-modal-header { border-color: var(--border-color, #334155); }
html[data-theme="dark"] .ss-modal-header h2 { color: var(--text-primary, #f1f5f9); }
html[data-theme="dark"] .ss-modal-close { background: var(--bg-tertiary, #0f172a); color: var(--text-secondary, #94a3b8); }
html[data-theme="dark"] .ss-modal-close:hover { background: #334155; color: var(--text-primary, #f1f5f9); }
html[data-theme="dark"] .ss-modal-body p { color: var(--text-secondary, #cbd5e1); }
html[data-theme="dark"] .ss-modal-actions { border-color: var(--border-color, #334155); }
html[data-theme="dark"] .ss-form-group label { color: var(--text-primary, #f1f5f9); }
html[data-theme="dark"] .ss-form-group input[type="text"] { background: var(--input-bg, #0f172a); border-color: var(--border-color, #334155); color: var(--text-primary, #f1f5f9); }
html[data-theme="dark"] .ss-form-group input[type="text"]:focus { border-color: #3b82f6; }
html[data-theme="dark"] .ss-hint { color: var(--text-secondary, #94a3b8); }
html[data-theme="dark"] .ss-checkbox-label { color: var(--text-primary, #f1f5f9); }
html[data-theme="dark"] .ss-checkbox-custom { border-color: var(--border-color, #334155); }
html[data-theme="dark"] .ss-checkbox-label input:checked + .ss-checkbox-custom { background: #3b82f6; border-color: #3b82f6; }

/* Responsive */
@media (max-width: 1024px) {
    .ss-layout { grid-template-columns: 220px 1fr; }
}

@media (max-width: 768px) {
    .ss-layout { grid-template-columns: 1fr; }
    .ss-sidebar { position: static; }
    .ss-page-header { flex-direction: column; align-items: flex-start; gap: 1rem; }
    .ss-stats-grid { grid-template-columns: 1fr; }
    .ss-table { font-size: 0.85rem; }
    .ss-table th, .ss-table td { padding: 0.75rem 1rem; }
    .ss-search-input { width: 100%; }
    .ss-modal { margin: 1rem; max-height: calc(100vh - 2rem); }
}

/* Role Permissions Grid */
.ss-permissions-card { background: white; border: 1px solid #e2e8f0; border-radius: 16px; overflow: hidden; box-shadow: 0 2px 8px rgba(0, 0, 0, 0.04); }
.ss-permissions-header { padding: 1.5rem; border-bottom: 1px solid #e2e8f0; background: #f8fafc; }
.ss-permissions-header h2 { margin: 0 0 0.5rem; font-size: 1.25rem; color: #1e293b; }
.ss-permissions-subtitle { margin: 0; font-size: 0.9rem; color: #64748b; }
.ss-permissions-table-wrapper { overflow-x: auto; }
.ss-permissions-table { width: 100%; border-collapse: collapse; min-width: 800px; }
.ss-permissions-table thead { background: #f8fafc; }
.ss-permissions-table th { padding: 1rem 1.25rem; text-align: left; font-size: 0.8rem; font-weight: 600; color: #64748b; text-transform: uppercase; letter-spacing: 0.05em; border-bottom: 1px solid #e2e8f0; }
.ss-perm-role-col { min-width: 150px; position: sticky; left: 0; background: #f8fafc; z-index: 10; }
.ss-perm-action-col { min-width: 200px; }
.ss-permissions-table td { padding: 1rem 1.25rem; border-bottom: 1px solid #f1f5f9; }
.ss-perm-role-cell { background: #f8fafc; font-weight: 600; color: #1e293b; border-right: 1px solid #e2e8f0; }
.ss-perm-action-cell { vertical-align: top; }
.ss-perm-form { margin: 0; }
.ss-perm-checks { display: flex; flex-direction: column; gap: 0.5rem; }
.ss-perm-check { display: flex; align-items: center; gap: 0.5rem; cursor: pointer; font-size: 0.85rem; color: #475569; margin: 0; }
.ss-perm-check input[type="checkbox"] { width: 16px; height: 16px; cursor: pointer; }
.ss-perm-check input[type="checkbox"]:checked + span { font-weight: 600; color: #1e293b; }
.ss-permissions-legend { padding: 1rem 1.5rem; background: #f8fafc; border-top: 1px solid #e2e8f0; display: flex; gap: 2rem; flex-wrap: wrap; }
.ss-legend-item { display: flex; align-items: center; gap: 0.5rem; font-size: 0.85rem; color: #64748b; }
.ss-legend-dot { width: 12px; height: 12px; border-radius: 50%; }
.ss-legend-admin { background: #3b82f6; }
.ss-legend-staff { background: #f59e0b; }
.ss-legend-resident { background: #10b981; }

html[data-theme="dark"] .ss-permissions-card { background: var(--card-bg, #1e293b); border-color: var(--border-color, #334155); }
html[data-theme="dark"] .ss-permissions-header { background: var(--bg-tertiary, #0f172a); border-color: var(--border-color, #334155); }
html[data-theme="dark"] .ss-permissions-header h2 { color: var(--text-primary, #f1f5f9); }
html[data-theme="dark"] .ss-permissions-subtitle { color: var(--text-secondary, #94a3b8); }
html[data-theme="dark"] .ss-permissions-table thead { background: var(--bg-tertiary, #0f172a); }
html[data-theme="dark"] .ss-permissions-table th { color: var(--text-secondary, #94a3b8); border-color: var(--border-color, #334155); }
html[data-theme="dark"] .ss-permissions-table td { border-color: var(--border-color, #1e293b); }
html[data-theme="dark"] .ss-perm-role-col { background: var(--bg-tertiary, #0f172a); border-color: var(--border-color, #334155); }
html[data-theme="dark"] .ss-perm-role-cell { color: var(--text-primary, #f1f5f9); }
html[data-theme="dark"] .ss-perm-check { color: var(--text-secondary, #cbd5e1); }
html[data-theme="dark"] .ss-perm-check input[type="checkbox"]:checked + span { color: var(--text-primary, #f1f5f9); }
html[data-theme="dark"] .ss-permissions-legend { background: var(--bg-tertiary, #0f172a); border-color: var(--border-color, #334155); }
html[data-theme="dark"] .ss-legend-item { color: var(--text-secondary, #94a3b8); }

.ss-integrations-grid { display:grid; gap:1rem; }
.ss-integration-card { background:#fff; border:1px solid #e2e8f0; border-radius:16px; padding:1.25rem; box-shadow:0 2px 8px rgba(0,0,0,0.04); }
.ss-integration-head { display:flex; justify-content:space-between; gap:1rem; align-items:flex-start; margin-bottom:0.75rem; }
.ss-integration-head h2 { margin:0 0 0.35rem; font-size:1.15rem; }
.ss-integration-note { margin:0 0 0.75rem; padding:0.65rem 0.75rem; background:#fef3c7; color:#92400e; border-radius:8px; font-size:0.85rem; }
.ss-integration-meta { display:flex; justify-content:space-between; gap:0.75rem; flex-wrap:wrap; font-size:0.85rem; color:#64748b; margin-bottom:0.75rem; }
.ss-integration-meta a { color:#2563eb; text-decoration:none; font-weight:600; }
.ss-integration-metrics { display:flex; flex-wrap:wrap; gap:0.65rem 1rem; font-size:0.82rem; color:#475569; margin-bottom:0.75rem; }
.ss-integration-errors { background:#fee2e2; color:#991b1b; border-radius:8px; padding:0.65rem 0.75rem; font-size:0.82rem; margin-bottom:0.75rem; }
.ss-integration-actions { display:flex; align-items:center; gap:0.75rem; flex-wrap:wrap; }
html[data-theme="dark"] .ss-integration-card { background:var(--card-bg,#1e293b); border-color:var(--border-color,#334155); }
html[data-theme="dark"] .ss-integration-head h2 { color:var(--text-primary,#f1f5f9); }
html[data-theme="dark"] .ss-integration-metrics { color:var(--text-secondary,#cbd5e1); }
</style>
<?php
$content = ob_get_clean();
include __DIR__ . '/../../layouts/dashboard_layout.php';
