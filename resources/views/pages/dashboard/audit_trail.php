<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../../../../config/app.php';

if (!($_SESSION['user_authenticated'] ?? false)) {
    header('Location: ' . base_path() . '/resources/views/pages/auth/login.php');
    exit;
}

require_once __DIR__ . '/../../../../config/database.php';
$pdo = db();
$pageTitle = 'Audit Trail | LGU Facilities Reservation';

// Get filter parameters
$filterModule = $_GET['module'] ?? '';
$filterUser = $_GET['user'] ?? '';
$filterDateFrom = $_GET['date_from'] ?? '';
$filterDateTo = $_GET['date_to'] ?? '';

// Build query with filters
$whereConditions = [];
$params = [];

if ($filterModule && $filterModule !== 'all') {
    $whereConditions[] = 'a.module = :module';
    $params['module'] = $filterModule;
}

if ($filterUser && $filterUser !== 'all') {
    $whereConditions[] = 'a.user_id = :user_id';
    $params['user_id'] = (int)$filterUser;
}

if ($filterDateFrom) {
    $whereConditions[] = 'DATE(a.created_at) >= :date_from';
    $params['date_from'] = $filterDateFrom;
}

if ($filterDateTo) {
    $whereConditions[] = 'DATE(a.created_at) <= :date_to';
    $params['date_to'] = $filterDateTo;
}

$whereClause = !empty($whereConditions) ? 'WHERE ' . implode(' AND ', $whereConditions) : '';

// Pagination
$perPage = 10;
$page = max(1, (int)($_GET['page'] ?? 1));
$offset = ($page - 1) * $perPage;

// Count total entries
$countSql = 'SELECT COUNT(*) FROM audit_log a ' . $whereClause;
$countStmt = $pdo->prepare($countSql);
$countStmt->execute($params);
$totalRows = (int)$countStmt->fetchColumn();
$totalPages = max(1, (int)ceil($totalRows / $perPage));

// Fetch audit entries
$sql = 'SELECT a.id, a.action, a.module, a.details, a.created_at, 
               u.name AS user_name, u.email AS user_email
        FROM audit_log a
        LEFT JOIN users u ON a.user_id = u.id
        ' . $whereClause . '
        ORDER BY a.created_at DESC
        LIMIT :limit OFFSET :offset';
        
$stmt = $pdo->prepare($sql);
foreach ($params as $key => $value) {
    $stmt->bindValue(':' . $key, $value);
}
$stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$entries = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get unique modules for filter
$modulesStmt = $pdo->query('SELECT DISTINCT module FROM audit_log ORDER BY module');
$modules = $modulesStmt->fetchAll(PDO::FETCH_COLUMN);

// Get users for filter (Admin/Staff only)
$usersStmt = $pdo->query('SELECT id, name FROM users WHERE role IN ("Admin", "Staff") ORDER BY name');
$users = $usersStmt->fetchAll(PDO::FETCH_ASSOC);

ob_start();
?>
<div class="page-header">
    <div class="breadcrumb">
        <span>Governance</span><span class="sep">/</span><span>Audit Trail</span>
    </div>
    <h1>Audit Trail</h1>
    <small>Trace key actions across reservations, facilities, payments, and user accounts.</small>
</div>

<div class="booking-wrapper">
    <section class="booking-card">
        <h2>Activity Log</h2>
        
        <form method="GET" class="booking-form" style="margin-bottom: 1.5rem; display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 1rem;">
            <label>
                Module
                <select name="module" onchange="this.form.submit()">
                    <option value="all" <?= $filterModule === '' || $filterModule === 'all' ? 'selected' : ''; ?>>All Modules</option>
                    <?php foreach ($modules as $module): ?>
                        <option value="<?= htmlspecialchars($module); ?>" <?= $filterModule === $module ? 'selected' : ''; ?>>
                            <?= htmlspecialchars($module); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
            
            <label>
                User
                <select name="user" onchange="this.form.submit()">
                    <option value="all" <?= $filterUser === '' || $filterUser === 'all' ? 'selected' : ''; ?>>All Users</option>
                    <?php foreach ($users as $user): ?>
                        <option value="<?= $user['id']; ?>" <?= $filterUser == $user['id'] ? 'selected' : ''; ?>>
                            <?= htmlspecialchars($user['name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
            
            <label>
                Date From
                <input type="date" name="date_from" value="<?= htmlspecialchars($filterDateFrom); ?>" onchange="this.form.submit()">
            </label>
            
            <label>
                Date To
                <input type="date" name="date_to" value="<?= htmlspecialchars($filterDateTo); ?>" onchange="this.form.submit()">
            </label>
            
            <?php if ($filterModule !== '' || $filterUser !== '' || $filterDateFrom !== '' || $filterDateTo !== ''): ?>
                <label>
                    <a href="?" class="btn-outline" style="display:block; text-align:center; padding:0.5rem; text-decoration:none;">Clear Filters</a>
                </label>
            <?php endif; ?>
        </form>
        
        <?php if (empty($entries)): ?>
            <p>No audit entries found matching the selected filters.</p>
        <?php else: ?>
            <table class="table">
                <thead>
                <tr>
                    <th>Date &amp; Time</th>
                    <th>User</th>
                    <th>Action</th>
                    <th>Module</th>
                    <th>Details</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($entries as $row): ?>
                    <tr>
                        <td><?= date('M d, Y H:i', strtotime($row['created_at'])); ?></td>
                        <td><?= $row['user_name'] ? htmlspecialchars($row['user_name']) : '<em>System</em>'; ?></td>
                        <td><?= htmlspecialchars($row['action']); ?></td>
                        <td><?= htmlspecialchars($row['module']); ?></td>
                        <td><?= $row['details'] ? htmlspecialchars($row['details']) : '-'; ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            
            <?php if ($totalPages > 1): ?>
                <div class="pagination" style="margin-top:1rem;">
                    <?php if ($page > 1): ?>
                        <a href="?page=<?= $page - 1; ?>&module=<?= htmlspecialchars($filterModule); ?>&user=<?= htmlspecialchars($filterUser); ?>&date_from=<?= htmlspecialchars($filterDateFrom); ?>&date_to=<?= htmlspecialchars($filterDateTo); ?>">&larr; Prev</a>
                    <?php endif; ?>
                    <span class="current">Page <?= $page; ?> of <?= $totalPages; ?></span>
                    <?php if ($page < $totalPages): ?>
                        <a href="?page=<?= $page + 1; ?>&module=<?= htmlspecialchars($filterModule); ?>&user=<?= htmlspecialchars($filterUser); ?>&date_from=<?= htmlspecialchars($filterDateFrom); ?>&date_to=<?= htmlspecialchars($filterDateTo); ?>">Next &rarr;</a>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </section>

    <aside class="booking-card">
        <h2>Scope of Tracking</h2>
        <ul class="audit-list">
            <li>Reservation lifecycle changes (create, approve/deny, cancel).</li>
            <li>Facility updates (capacity, availability, maintenance flags).</li>
            <li>Payment recordings and verification status changes.</li>
            <li>User account approvals, role changes, and locks.</li>
            <li>Notification dispatches and system advisories.</li>
        </ul>
        
        <div style="margin-top: 1.5rem; padding-top: 1.5rem; border-top: 1px solid #dfe3ef;">
            <h3 style="font-size: 0.95rem; margin-bottom: 0.5rem;">Statistics</h3>
            <ul class="audit-list" style="margin:0;">
                <?php
                $totalStmt = $pdo->query('SELECT COUNT(*) FROM audit_log');
                $totalEntries = (int)$totalStmt->fetchColumn();
                
                $todayStmt = $pdo->query('SELECT COUNT(*) FROM audit_log WHERE DATE(created_at) = CURDATE()');
                $todayEntries = (int)$todayStmt->fetchColumn();
                ?>
                <li><strong><?= $totalEntries; ?></strong> total entries logged</li>
                <li><strong><?= $todayEntries; ?></strong> entries today</li>
            </ul>
        </div>
    </aside>
</div>
<?php
$content = ob_get_clean();
include __DIR__ . '/../../layouts/dashboard_layout.php';
