<?php
/**
 * Announcements Archive Page
 * Modern public-facing announcements listing with filters, search, and pagination
 */
$useTailwind = true;
require_once __DIR__ . '/../../../../config/app.php';
require_once __DIR__ . '/../../../../config/database.php';

$pageTitle = 'Announcements | Barangay Culiat Public Facilities Reservation System';
$pdo = db();
$base = base_path();

// Get filter and sort parameters
$sort = $_GET['sort'] ?? 'newest';
$search = trim($_GET['search'] ?? '');
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 12;
$offset = ($page - 1) * $perPage;

// Build query
$baseQuery = '
    SELECT id, title, message, type, link, image_path, created_at 
    FROM notifications 
    WHERE user_id IS NULL
';

// Add search condition
if (!empty($search)) {
    $baseQuery .= " AND (title LIKE :search OR message LIKE :search)";
}

// Add sorting
switch ($sort) {
    case 'oldest':
        $baseQuery .= ' ORDER BY created_at ASC';
        break;
    default:
        $baseQuery .= ' ORDER BY created_at DESC';
}

// Build count query
$countQuery = preg_replace('/SELECT.*FROM/', 'SELECT COUNT(*) as total FROM', $baseQuery);
$countQuery = preg_replace('/ ORDER BY.*$/', '', $countQuery);

$countStmt = $pdo->prepare($countQuery);
if (!empty($search)) {
    $countStmt->execute(['search' => "%{$search}%"]);
} else {
    $countStmt->execute();
}
$totalAnnouncements = $countStmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0;
$totalPages = ceil($totalAnnouncements / $perPage) ?: 1;

// Add pagination
$baseQuery .= ' LIMIT :limit OFFSET :offset';

// Execute query
$stmt = $pdo->prepare($baseQuery);
$stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
if (!empty($search)) {
    $stmt->bindValue(':search', "%{$search}%", PDO::PARAM_STR);
}
$stmt->execute();
$announcements = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Function to categorize announcements
function getAnnouncementCategory($title, $message) {
    $titleLower = strtolower($title);
    $messageLower = strtolower($message);
    $combined = $titleLower . ' ' . $messageLower;
    
    $categoryConfig = [
        'emergency' => ['color' => '#dc2626', 'bgColor' => '#fee2e2'],
        'event' => ['color' => '#2563eb', 'bgColor' => '#eff6ff'],
        'health' => ['color' => '#059669', 'bgColor' => '#ecfdf5'],
        'deadline' => ['color' => '#d97706', 'bgColor' => '#fef3c7'],
        'advisory' => ['color' => '#0891b2', 'bgColor' => '#ecf0f1'],
        'general' => ['color' => '#6b7280', 'bgColor' => '#f3f4f6'],
    ];
    
    $patterns = [
        'emergency' => '/emergency|urgent|alert|critical|disaster|calamity/i',
        'event' => '/event|activity|program|ceremony|schedule|gathering|celebration/i',
        'health' => '/health|medical|covid|vaccine|sanitation|disease|clinic|doctor/i',
        'deadline' => '/deadline|due date|submit by|application closes|final date/i',
        'advisory' => '/advisory|notice|attention|remember|reminder|please note/i',
    ];
    
    foreach ($patterns as $category => $pattern) {
        if (preg_match($pattern, $combined)) {
            return array_merge(['type' => $category], $categoryConfig[$category]);
        }
    }
    
    return array_merge(['type' => 'general'], $categoryConfig['general']);
}

$pageHeaderIcon = 'bi-megaphone';
$pageHeaderTitle = 'Barangay Announcements';
$pageHeaderTagline = 'Stay updated with our latest barangay events, programs, and notices. Your community, your stories.';
ob_start();
?>

<?php include __DIR__ . '/../../components/page_header.php'; ?>
<div class="announcements-container public-fade-in">
    <div class="container px-4 px-lg-5">
        <!-- Search Bar -->
        <div class="search-section">
            <form method="GET" class="search-form">
                <div class="search-input-wrapper">
                    <i class="bi bi-search"></i>
                    <input type="text" name="search" placeholder="Search announcements..." value="<?= htmlspecialchars($search); ?>" class="search-input">
                </div>
                <?php if (!empty($search)): ?>
                    <a href="<?= $base; ?>/announcements" class="btn-clear-search">Clear</a>
                <?php endif; ?>
            </form>
        </div>

        <!-- Sort Options -->
        <div class="controls-section">
            <div class="sort-wrapper">
                <label for="sort-select">Sort by:</label>
                <select id="sort-select" name="sort" onchange="updateSort(this.value)" class="sort-select">
                    <option value="newest" <?= $sort === 'newest' ? 'selected' : ''; ?>>Newest First</option>
                    <option value="oldest" <?= $sort === 'oldest' ? 'selected' : ''; ?>>Oldest First</option>
                </select>
            </div>
            <div class="results-count">
                Showing <?= !empty($announcements) ? (($page - 1) * $perPage) + 1 : 0; ?>–<?= min($page * $perPage, $totalAnnouncements); ?> of <?= $totalAnnouncements; ?> announcements
            </div>
        </div>

        <!-- Announcements Grid -->
        <div class="announcements-grid page-content-animate">
            <?php if (!empty($announcements)): ?>
                <?php foreach ($announcements as $item):
                    $category = getAnnouncementCategory($item['title'] ?? '', $item['message'] ?? '');
                    $dateFormatted = date('M d, Y', strtotime($item['created_at']));
                    $messageSummary = htmlspecialchars(mb_strimwidth($item['message'] ?? '', 0, 200, '…'));
                    $hasLink = !empty($item['link']);
                    $hasImage = !empty($item['image_path']);
                ?>
                    <article class="announcement-card">
                        <!-- Category Badge -->
                        <div class="badge-wrapper" style="background-color: <?= $category['bgColor']; ?>;">
                            <span class="badge" style="color: <?= $category['color']; ?>;">
                                <?= ucfirst($category['type']); ?>
                            </span>
                        </div>

                        <!-- Featured Image -->
                        <?php if ($hasImage): ?>
                            <div class="card-image">
                                <img src="<?= htmlspecialchars($base . $item['image_path']); ?>" alt="<?= htmlspecialchars($item['title'] ?? 'Announcement'); ?>">
                            </div>
                        <?php else: ?>
                            <div class="card-image card-image-placeholder">
                                <i class="bi bi-megaphone"></i>
                            </div>
                        <?php endif; ?>

                        <!-- Card Body -->
                        <div class="card-body">
                            <div class="card-header">
                                <h2 class="card-title"><?= htmlspecialchars($item['title'] ?? 'Announcement'); ?></h2>
                                <time class="card-date"><?= $dateFormatted; ?></time>
                            </div>

                            <p class="card-text"><?= $messageSummary; ?></p>

                            <!-- Actions -->
                            <div class="card-actions">
                                <?php if ($hasLink): ?>
                                    <a href="<?= htmlspecialchars($item['link']); ?>" class="btn-read-more" target="_blank">
                                        Learn More <i class="bi bi-arrow-right"></i>
                                    </a>
                                <?php endif; ?>
                            </div>
                        </div>
                    </article>
                <?php endforeach; ?>
            <?php else: ?>
                <!-- Empty State -->
                <div class="empty-state">
                    <div class="empty-icon">
                        <i class="bi bi-inbox"></i>
                    </div>
                    <h3>No Announcements Found</h3>
                    <p>
                        <?php if (!empty($search)): ?>
                            We couldn't find any announcements matching "<strong><?= htmlspecialchars($search); ?></strong>"
                            <br><br>
                            <a href="<?= $base; ?>/announcements" class="btn-reset">View All Announcements</a>
                        <?php else: ?>
                            There are currently no announcements to display. Check back soon!
                        <?php endif; ?>
                    </p>
                </div>
            <?php endif; ?>
        </div>

        <!-- Pagination -->
        <?php if ($totalPages > 1 && !empty($announcements)): ?>
            <nav class="pagination-wrapper" aria-label="Pagination">
                <ul class="pagination">
                    <?php if ($page > 1): ?>
                        <li><a href="?sort=<?= htmlspecialchars($sort); ?>&search=<?= htmlspecialchars($search); ?>&page=1" class="page-link"><i class="bi bi-chevron-double-left"></i></a></li>
                        <li><a href="?sort=<?= htmlspecialchars($sort); ?>&search=<?= htmlspecialchars($search); ?>&page=<?= $page - 1; ?>" class="page-link"><i class="bi bi-chevron-left"></i></a></li>
                    <?php endif; ?>

                    <li class="page-info">
                        <span>Page <strong><?= $page; ?></strong> of <strong><?= $totalPages; ?></strong></span>
                    </li>

                    <?php if ($page < $totalPages): ?>
                        <li><a href="?sort=<?= htmlspecialchars($sort); ?>&search=<?= htmlspecialchars($search); ?>&page=<?= $page + 1; ?>" class="page-link"><i class="bi bi-chevron-right"></i></a></li>
                        <li><a href="?sort=<?= htmlspecialchars($sort); ?>&search=<?= htmlspecialchars($search); ?>&page=<?= $totalPages; ?>" class="page-link"><i class="bi bi-chevron-double-right"></i></a></li>
                    <?php endif; ?>
                </ul>
            </nav>
        <?php endif; ?>
    </div>
</div>

<style>
/* ========== Announcements Page Styling ========== */

/* Hero Section */
.announcements-hero {
    background: linear-gradient(135deg, #6384d2 0%, #285ccd 100%);
    padding: 4rem 1rem;
    color: white;
    text-align: center;
}

@media (min-width: 768px) {
    .announcements-hero {
        padding: 5rem 1rem;
    }
}

.announcements-hero h1 {
    font-size: 2.5rem;
    font-weight: 700;
    margin: 0 0 0.5rem 0;
}

.announcements-hero p {
    font-size: 1.1rem;
    color: rgba(255, 255, 255, 0.95);
    margin: 0;
}

/* Main Container */
.announcements-container {
    background: #f9fafb;
    padding: 3rem 0;
    min-height: 60vh;
}

/* Search Section */
.search-section {
    margin-bottom: 2.5rem;
}

.search-form {
    display: flex;
    gap: 1rem;
    align-items: center;
    max-width: 600px;
    margin: 0 auto;
}

.search-input-wrapper {
    display: flex;
    align-items: center;
    flex: 1;
    gap: 0.75rem;
    background: white;
    border: 2px solid #e5e7eb;
    border-radius: 8px;
    padding: 0 1rem;
    transition: all 0.2s ease;
}

.search-input-wrapper:focus-within {
    border-color: #6384d2;
    box-shadow: 0 0 0 3px rgba(99, 132, 210, 0.1);
}

.search-input {
    flex: 1;
    border: none;
    background: transparent;
    padding: 0.75rem 0;
    font-family: 'Poppins', sans-serif;
    font-size: 1rem;
    color: #1f2937;
}

.search-input::placeholder {
    color: #9ca3af;
}

.search-input:focus {
    outline: none;
}

.btn-clear-search {
    padding: 0.625rem 1rem;
    background: white;
    border: 2px solid #e5e7eb;
    border-radius: 6px;
    color: #4b5563;
    font-weight: 600;
    text-decoration: none;
    cursor: pointer;
    transition: all 0.2s ease;
}

.btn-clear-search:hover {
    border-color: #6384d2;
    color: #6384d2;
}

@media (max-width: 480px) {
    .search-form {
        flex-wrap: wrap;
    }
    
    .btn-clear-search {
        width: 100%;
    }
}

/* Controls Section */
.controls-section {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 2rem;
    margin-bottom: 2rem;
    flex-wrap: wrap;
}

.sort-wrapper {
    display: flex;
    align-items: center;
    gap: 0.75rem;
}

.sort-wrapper label {
    font-weight: 600;
    color: #4b5563;
    white-space: nowrap;
}

.sort-select {
    padding: 0.625rem 0.875rem;
    border: 2px solid #e5e7eb;
    border-radius: 6px;
    background: white;
    color: #4b5563;
    font-family: 'Poppins', sans-serif;
    font-weight: 500;
    cursor: pointer;
    transition: all 0.2s ease;
}

.sort-select:focus {
    outline: none;
    border-color: #6384d2;
    box-shadow: 0 0 0 3px rgba(99, 132, 210, 0.1);
}

.results-count {
    color: #6b7280;
    font-size: 0.95rem;
    white-space: nowrap;
}

@media (max-width: 768px) {
    .controls-section {
        justify-content: space-between;
        gap: 1rem;
    }
    
    .results-count {
        width: 100%;
        order: 3;
        font-size: 0.85rem;
    }
}

/* Announcements Grid */
.announcements-grid {
    display: grid;
    grid-template-columns: 1fr;
    gap: 1.5rem;
    margin-bottom: 3rem;
}

@media (min-width: 481px) {
    .announcements-grid {
        grid-template-columns: repeat(2, 1fr);
        gap: 1.75rem;
    }
}

@media (min-width: 1025px) {
    .announcements-grid {
        grid-template-columns: repeat(3, 1fr);
        gap: 2rem;
    }
}

/* Announcement Card */
.announcement-card {
    background: white;
    border-radius: 12px;
    overflow: hidden;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
    transition: all 0.3s ease;
    display: flex;
    flex-direction: column;
    position: relative;
}

.announcement-card:hover {
    transform: translateY(-4px);
    box-shadow: 0 12px 24px rgba(0, 0, 0, 0.12);
}

/* Badge */
.badge-wrapper {
    padding: 0.75rem 1rem;
    display: flex;
    align-items: center;
    gap: 0.5rem;
}

.badge {
    font-size: 0.75rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

/* Card Image */
.card-image {
    width: 100%;
    height: 200px;
    background: #f3f4f6;
    overflow: hidden;
}

.card-image img {
    width: 100%;
    height: 100%;
    object-fit: cover;
    display: block;
}

.card-image-placeholder {
    display: flex;
    align-items: center;
    justify-content: center;
    color: #d1d5db;
    font-size: 3rem;
}

/* Card Body */
.card-body {
    padding: 1.5rem;
    flex: 1;
    display: flex;
    flex-direction: column;
}

.card-header {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    gap: 1rem;
    margin-bottom: 1rem;
}

.card-title {
    font-size: 1.25rem;
    font-weight: 700;
    color: #1f2937;
    margin: 0;
    line-height: 1.4;
    display: -webkit-box;
    -webkit-line-clamp: 2;
    line-clamp: 2;
    -webkit-box-orient: vertical;
    overflow: hidden;
    flex: 1;
}

.card-date {
    font-size: 0.875rem;
    color: #9ca3af;
    white-space: nowrap;
}

.card-text {
    font-size: 0.9375rem;
    color: #4b5563;
    margin: 0 0 1rem 0;
    line-height: 1.6;
    flex: 1;
    display: -webkit-box;
    -webkit-line-clamp: 3;
    line-clamp: 3;
    -webkit-box-orient: vertical;
    overflow: hidden;
}

/* Card Actions */
.card-actions {
    margin-top: auto;
    padding-top: 1rem;
    border-top: 1px solid #f3f4f6;
}

.btn-read-more {
    display: inline-flex;
    align-items: center;
    gap: 0.5rem;
    color: #2563eb;
    font-weight: 600;
    font-size: 0.9375rem;
    text-decoration: none;
    transition: all 0.2s ease;
}

.btn-read-more:hover {
    color: #1e40af;
    gap: 0.75rem;
}

.btn-read-more i {
    transition: transform 0.2s ease;
}

/* Empty State */
.empty-state {
    text-align: center;
    padding: 4rem 1rem;
    color: #6b7280;
    grid-column: 1 / -1;
}

.empty-icon {
    font-size: 4rem;
    color: #d1d5db;
    margin-bottom: 1.5rem;
}

.empty-state h3 {
    font-size: 1.5rem;
    color: #1f2937;
    margin: 0 0 0.75rem 0;
}

.empty-state p {
    font-size: 1rem;
    margin: 0;
    color: #6b7280;
    line-height: 1.6;
}

.btn-reset {
    display: inline-block;
    margin-top: 1.5rem;
    padding: 0.75rem 1.5rem;
    background: #6384d2;
    color: white;
    text-decoration: none;
    border-radius: 6px;
    font-weight: 600;
    transition: all 0.2s ease;
}

.btn-reset:hover {
    background: #285ccd;
    transform: translateY(-2px);
}

/* Pagination */
.pagination-wrapper {
    display: flex;
    justify-content: center;
    margin-top: 3rem;
}

.pagination {
    display: flex;
    list-style: none;
    gap: 0.5rem;
    align-items: center;
    margin: 0;
    padding: 0;
    flex-wrap: wrap;
}

.page-link {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 40px;
    height: 40px;
    border-radius: 6px;
    border: 1px solid #e5e7eb;
    background: white;
    color: #4b5563;
    text-decoration: none;
    font-weight: 600;
    transition: all 0.2s ease;
}

.page-link:hover {
    border-color: #6384d2;
    background: #eff6ff;
    color: #6384d2;
}

.page-info {
    padding: 0.5rem 1rem;
    color: #6b7280;
    font-size: 0.95rem;
}

@media (max-width: 480px) {
    .pagination {
        gap: 0.25rem;
    }
    
    .page-link {
        width: 36px;
        height: 36px;
        font-size: 0.9rem;
    }
    
    .announcements-hero {
        padding: 3rem 1rem;
    }
    
    .announcements-hero h1 {
        font-size: 1.75rem;
    }
    
    .announcements-hero p {
        font-size: 0.95rem;
    }
    
    .announcements-container {
        padding: 2rem 0;
    }
    
    .card-image {
        height: 160px;
    }
    
    .card-body {
        padding: 1.25rem;
    }
}
</style>

<script>
function updateSort(value) {
    const search = new URLSearchParams(window.location.search).get('search') || '';
    let url = '<?= $base; ?>/announcements?sort=' + value;
    if (search) {
        url += '&search=' + encodeURIComponent(search);
    }
    window.location.href = url;
}
</script>

<?php
$content = ob_get_clean();
include __DIR__ . '/../../layouts/guest_layout.php';
?>
