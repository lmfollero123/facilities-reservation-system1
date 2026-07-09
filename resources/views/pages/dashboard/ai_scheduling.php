<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../../../../config/app.php';
require_once __DIR__ . '/../../../../config/permissions.php';

$role = $_SESSION['role'] ?? 'Resident';
if (!($_SESSION['user_authenticated'] ?? false) || !frs_can_read($role, 'ai_tools')) {
    header('Location: ' . base_path() . '/dashboard');
    exit;
}

require_once __DIR__ . '/../../../../config/database.php';
require_once __DIR__ . '/../../../../services/RecommendationService.php';

$pdo = db();
$userRole = $_SESSION['role'] ?? 'Resident';
$userId = $_SESSION['user_id'] ?? 0;
$pageTitle = 'Personalized Recommendations | LGU Facilities Reservation';

// Initialize Recommendation Service
$recommendationService = new RecommendationService($pdo);

// Get personalized recommendations
$recommendations = [];
if ($userId > 0) {
    $recommendations = $recommendationService->getPersonalizedRecommendations($userId);
}


ob_start();
?>
<style>
.ai-scheduler-container {
    max-width: 1400px;
    margin: 0 auto;
    padding: 0;
}

.ai-page-header {
    margin-bottom: 2rem;
    opacity: 0;
    transform: translateY(-20px);
}

.ai-page-title {
    font-size: 2rem;
    font-weight: 800;
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    -webkit-background-clip: text;
    -webkit-text-fill-color: transparent;
    background-clip: text;
    margin: 0 0 0.5rem;
}

.ai-page-description {
    color: #64748b;
    font-size: 1rem;
    margin: 0;
}

/* Welcome Section */
.ai-welcome-section {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    border-radius: 20px;
    padding: 2rem;
    margin-bottom: 2rem;
    color: white;
    opacity: 0;
    transform: translateY(-20px);
}

.ai-welcome-content {
    display: flex;
    align-items: center;
    gap: 1.5rem;
}

.ai-welcome-icon {
    font-size: 3rem;
}

.ai-welcome-text h2 {
    margin: 0 0 0.5rem;
    font-size: 1.5rem;
    font-weight: 700;
}

.ai-welcome-text p {
    margin: 0;
    opacity: 0.9;
}

/* Recommendations Grid */
.ai-recommendations-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(350px, 1fr));
    gap: 1.5rem;
    margin-bottom: 2rem;
}

.ai-recommendation-card {
    background: white;
    border: 1px solid #e2e8f0;
    border-radius: 16px;
    padding: 1.5rem;
    box-shadow: 0 4px 6px rgba(0, 0, 0, 0.04);
    transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
    opacity: 0;
    transform: translateY(30px);
    position: relative;
    overflow: hidden;
}

.ai-recommendation-card::before {
    content: '';
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    height: 4px;
    background: linear-gradient(90deg, #667eea 0%, #764ba2 100%);
    transform: scaleX(0);
    transform-origin: left;
    transition: transform 0.3s ease;
}

.ai-recommendation-card:hover::before {
    transform: scaleX(1);
}

.ai-recommendation-card:hover {
    transform: translateY(-8px);
    box-shadow: 0 20px 40px rgba(102, 126, 234, 0.15);
    border-color: #667eea;
}

.ai-rec-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 1rem;
}

.ai-rec-facility-name {
    font-size: 1.25rem;
    font-weight: 700;
    color: #1e293b;
    margin: 0;
}

.ai-rec-score {
    display: inline-flex;
    align-items: center;
    gap: 0.35rem;
    padding: 0.4rem 0.8rem;
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
    border-radius: 20px;
    font-size: 0.85rem;
    font-weight: 700;
}

.ai-rec-reasons {
    margin-bottom: 1.25rem;
}

.ai-rec-reason {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    padding: 0.5rem 0;
    font-size: 0.9rem;
    color: #475569;
}

.ai-rec-reason-icon {
    color: #16a34a;
    font-size: 1rem;
}

.ai-rec-details {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 0.75rem;
    margin-bottom: 1.25rem;
}

.ai-rec-detail {
    background: #f8fafc;
    padding: 0.75rem;
    border-radius: 8px;
}

.ai-rec-detail-label {
    font-size: 0.75rem;
    font-weight: 600;
    color: #64748b;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    margin-bottom: 0.25rem;
}

.ai-rec-detail-value {
    font-size: 0.9rem;
    font-weight: 600;
    color: #1e293b;
}

.ai-rec-action {
    width: 100%;
    padding: 0.875rem 1.5rem;
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
    border: none;
    border-radius: 10px;
    font-size: 0.95rem;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.3s ease;
    text-decoration: none;
    display: inline-block;
    text-align: center;
}

.ai-rec-action:hover {
    transform: translateY(-2px);
    box-shadow: 0 8px 20px rgba(102, 126, 234, 0.3);
}

.ai-rec-fallback-badge {
    display: inline-flex;
    align-items: center;
    gap: 0.35rem;
    padding: 0.25rem 0.75rem;
    background: #fef3c7;
    color: #92400e;
    border-radius: 20px;
    font-size: 0.75rem;
    font-weight: 600;
    margin-bottom: 0.75rem;
}

/* Empty State */
.ai-empty-state {
    text-align: center;
    padding: 4rem 2rem;
    color: #64748b;
    opacity: 0;
    transform: translateY(20px);
}

.ai-empty-icon {
    font-size: 4rem;
    margin-bottom: 1.5rem;
}

.ai-empty-title {
    font-size: 1.25rem;
    font-weight: 700;
    color: #1e293b;
    margin: 0 0 0.5rem;
}

.ai-empty-text {
    font-size: 0.95rem;
    margin: 0;
}

.ai-empty-action {
    margin-top: 1.5rem;
}

.ai-empty-action-btn {
    padding: 0.875rem 1.5rem;
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
    border: none;
    border-radius: 10px;
    font-size: 0.95rem;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.3s ease;
    text-decoration: none;
    display: inline-block;
}

.ai-empty-action-btn:hover {
    transform: translateY(-2px);
    box-shadow: 0 8px 20px rgba(102, 126, 234, 0.3);
}

/* Calendar Heatmap */
.ai-calendar-section {
    background: white;
    border: 1px solid #e2e8f0;
    border-radius: 16px;
    overflow: hidden;
    margin-bottom: 1.5rem;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.04);
}

.ai-calendar-header {
    padding: 1.25rem 1.5rem;
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    border-bottom: 1px solid #e2e8f0;
    display: flex;
    align-items: center;
    justify-content: space-between;
}

.ai-calendar-header h3 {
    margin: 0;
    font-size: 1.25rem;
    font-weight: 700;
    color: white;
    display: flex;
    align-items: center;
    gap: 0.5rem;
}

.ai-calendar-nav {
    display: flex;
    gap: 0.5rem;
}

.ai-calendar-nav-btn {
    padding: 0.5rem 0.75rem;
    background: rgba(255, 255, 255, 0.2);
    color: white;
    border: none;
    border-radius: 6px;
    font-size: 0.85rem;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.2s ease;
}

.ai-calendar-nav-btn:hover {
    background: rgba(255, 255, 255, 0.3);
}

.ai-calendar-body {
    padding: 1.5rem;
}

.ai-calendar-grid {
    display: grid;
    grid-template-columns: repeat(7, 1fr);
    gap: 0.5rem;
}

.ai-calendar-day-header {
    text-align: center;
    font-size: 0.85rem;
    font-weight: 600;
    color: #64748b;
    padding: 0.5rem;
}

.ai-calendar-day {
    aspect-ratio: 1;
    border-radius: 8px;
    padding: 0.5rem;
    cursor: pointer;
    transition: all 0.2s ease;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    border: 1px solid transparent;
}

.ai-calendar-day:hover {
    transform: scale(1.05);
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
    z-index: 1;
}

.ai-calendar-day.past {
    opacity: 0.4;
    cursor: not-allowed;
}

.ai-calendar-day.low {
    background: #dcfce7;
    border-color: #86efac;
}

.ai-calendar-day.medium {
    background: #fef3c7;
    border-color: #fcd34d;
}

.ai-calendar-day.high {
    background: #fed7aa;
    border-color: #fdba74;
}

.ai-calendar-day.very-high {
    background: #fee2e2;
    border-color: #fca5a5;
}

.ai-calendar-day-date {
    font-size: 0.9rem;
    font-weight: 700;
    color: #1e293b;
    margin-bottom: 0.25rem;
}

.ai-calendar-day-score {
    font-size: 0.75rem;
    font-weight: 600;
    color: #64748b;
}

.ai-calendar-day.today {
    border: 2px solid #3b82f6;
    box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.2);
}

/* Legend */
.ai-calendar-legend {
    display: flex;
    gap: 1rem;
    justify-content: center;
    margin-top: 1rem;
    padding-top: 1rem;
    border-top: 1px solid #e2e8f0;
}

.ai-legend-item {
    display: flex;
    align-items: center;
    gap: 0.35rem;
    font-size: 0.85rem;
    color: #64748b;
}

.ai-legend-color {
    width: 16px;
    height: 16px;
    border-radius: 4px;
}

.ai-legend-color.low { background: #dcfce7; }
.ai-legend-color.medium { background: #fef3c7; }
.ai-legend-color.high { background: #fed7aa; }
.ai-legend-color.very-high { background: #fee2e2; }

.ai-alerts-section {
    background: linear-gradient(135deg, #fee2e2 0%, #fecaca 100%);
    border: 1px solid #dc2626;
    border-radius: 16px;
    padding: 1.5rem;
    margin-bottom: 2rem;
}

.ai-alerts-header {
    display: flex;
    align-items: center;
    gap: 0.75rem;
    margin-bottom: 1rem;
}

.ai-alerts-header h3 {
    margin: 0;
    font-size: 1.25rem;
    font-weight: 700;
    color: #dc2626;
}

.ai-alerts-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
    gap: 1rem;
}

.ai-alert-card {
    background: white;
    border: 1px solid #fecaca;
    border-radius: 12px;
    padding: 1rem;
    box-shadow: 0 2px 4px rgba(220, 38, 38, 0.1);
}

.ai-alert-facility {
    font-size: 0.85rem;
    font-weight: 600;
    color: #64748b;
    margin-bottom: 0.25rem;
}

.ai-alert-datetime {
    font-size: 1rem;
    font-weight: 700;
    color: #dc2626;
    margin-bottom: 0.5rem;
}

.ai-alert-score {
    display: inline-flex;
    align-items: center;
    gap: 0.35rem;
    padding: 0.25rem 0.75rem;
    background: #fee2e2;
    color: #dc2626;
    border-radius: 20px;
    font-size: 0.75rem;
    font-weight: 600;
}

/* Day Detail Modal */
.ai-day-modal {
    display: none;
    position: fixed;
    inset: 0;
    z-index: 1000;
    background: rgba(0, 0, 0, 0.5);
    backdrop-filter: blur(4px);
    align-items: center;
    justify-content: center;
    padding: 1rem;
}

.ai-day-modal.show {
    display: flex;
}

.ai-day-modal-content {
    background: white;
    border-radius: 16px;
    max-width: 600px;
    width: 100%;
    max-height: 80vh;
    overflow-y: auto;
    box-shadow: 0 20px 50px rgba(0, 0, 0, 0.25);
}

.ai-day-modal-header {
    padding: 1.5rem;
    border-bottom: 1px solid #e2e8f0;
    display: flex;
    align-items: center;
    justify-content: space-between;
}

.ai-day-modal-header h3 {
    margin: 0;
    font-size: 1.25rem;
    font-weight: 700;
    color: #1e293b;
}

.ai-day-modal-close {
    background: none;
    border: none;
    font-size: 1.5rem;
    color: #64748b;
    cursor: pointer;
    padding: 0.25rem;
    line-height: 1;
}

.ai-day-modal-close:hover {
    color: #1e293b;
}

.ai-day-modal-body {
    padding: 1.5rem;
}

.ai-day-slots-list {
    display: flex;
    flex-direction: column;
    gap: 0.75rem;
}

.ai-day-slot-item {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 1rem;
    background: #f8fafc;
    border: 1px solid #e2e8f0;
    border-radius: 8px;
    transition: all 0.2s ease;
}

.ai-day-slot-item:hover {
    border-color: #3b82f6;
    transform: translateX(4px);
}

.ai-day-slot-time {
    font-size: 1rem;
    font-weight: 700;
    color: #1e293b;
}

.ai-day-slot-score {
    display: inline-flex;
    align-items: center;
    gap: 0.35rem;
    padding: 0.25rem 0.75rem;
    border-radius: 20px;
    font-size: 0.75rem;
    font-weight: 600;
}

.ai-day-slot-score.low { background: #dcfce7; color: #166534; }
.ai-day-slot-score.medium { background: #fef3c7; color: #92400e; }
.ai-day-slot-score.high { background: #fed7aa; color: #9a3412; }
.ai-day-slot-score.very-high { background: #fee2e2; color: #dc2626; }

.ai-day-slot-book {
    padding: 0.5rem 1rem;
    background: linear-gradient(135deg, #3b82f6 0%, #2563eb 100%);
    color: white;
    border: none;
    border-radius: 8px;
    font-size: 0.85rem;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.2s ease;
    text-decoration: none;
}

.ai-day-slot-book:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(59, 130, 246, 0.3);
}

.ai-empty-state {
    text-align: center;
    padding: 3rem 2rem;
    color: #64748b;
}

.ai-empty-icon {
    font-size: 3rem;
    margin-bottom: 1rem;
    color: #cbd5e1;
}

.ai-forecast-section {
    background: white;
    border: 1px solid #e2e8f0;
    border-radius: 16px;
    overflow: hidden;
    margin-bottom: 1.5rem;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.04);
}

.ai-forecast-header {
    padding: 1.25rem 1.5rem;
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    border-bottom: 1px solid #e2e8f0;
    display: flex;
    align-items: center;
    justify-content: space-between;
}

.ai-forecast-header h3 {
    margin: 0;
    font-size: 1.25rem;
    font-weight: 700;
    color: white;
    display: flex;
    align-items: center;
    gap: 0.5rem;
}

.ai-forecast-body {
    padding: 1.5rem;
}

.ai-forecast-day {
    margin-bottom: 2rem;
}

.ai-forecast-day:last-child {
    margin-bottom: 0;
}

.ai-forecast-date {
    font-size: 1rem;
    font-weight: 600;
    color: #1e293b;
    margin-bottom: 1rem;
    display: flex;
    align-items: center;
    gap: 0.5rem;
}

.ai-forecast-slots {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 1rem;
}

.ai-forecast-slot {
    background: #f8fafc;
    border: 1px solid #e2e8f0;
    border-radius: 12px;
    padding: 1rem;
    transition: all 0.2s ease;
}

.ai-forecast-slot:hover {
    border-color: #3b82f6;
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(59, 130, 246, 0.15);
}

.ai-forecast-slot-time {
    font-size: 1rem;
    font-weight: 700;
    color: #1e293b;
    margin-bottom: 0.5rem;
}

.ai-forecast-slot-score {
    display: inline-flex;
    align-items: center;
    gap: 0.35rem;
    padding: 0.25rem 0.75rem;
    border-radius: 20px;
    font-size: 0.75rem;
    font-weight: 600;
    margin-bottom: 0.5rem;
}

.ai-forecast-slot-score.low {
    background: #dcfce7;
    color: #166534;
}

.ai-forecast-slot-score.medium {
    background: #fef3c7;
    color: #92400e;
}

.ai-forecast-slot-score.high {
    background: #fed7aa;
    color: #9a3412;
}

.ai-forecast-slot-score.very-high {
    background: #fee2e2;
    color: #dc2626;
}

.ai-forecast-slot-book {
    width: 100%;
    padding: 0.5rem 1rem;
    background: linear-gradient(135deg, #3b82f6 0%, #2563eb 100%);
    color: white;
    border: none;
    border-radius: 8px;
    font-size: 0.85rem;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.2s ease;
    text-decoration: none;
    display: inline-block;
    text-align: center;
}

.ai-forecast-slot-book:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(59, 130, 246, 0.3);
}

.ai-empty-state {
    text-align: center;
    padding: 3rem 2rem;
    color: #64748b;
}

.ai-empty-icon {
    font-size: 3rem;
    margin-bottom: 1rem;
    color: #cbd5e1;
}

/* Dark mode */
html[data-theme="dark"] .ai-page-title {
    color: #f1f5f9;
}

html[data-theme="dark"] .ai-page-description {
    color: #94a3b8;
}

html[data-theme="dark"] .ai-filters-bar {
    background: #1e293b;
    border-color: #334155;
}

html[data-theme="dark"] .ai-filter-label {
    color: #cbd5e1;
}

html[data-theme="dark"] .ai-filter-select {
    background: #0f172a;
    border-color: #334155;
    color: #f1f5f9;
}

html[data-theme="dark"] .ai-filter-select:focus {
    border-color: #3b82f6;
}

html[data-theme="dark"] .ai-stats-bar {
    color: #94a3b8;
}

html[data-theme="dark"] .ai-facility-card {
    background: #1e293b;
    border-color: #334155;
}

html[data-theme="dark"] .ai-facility-header {
    background: linear-gradient(135deg, #1e293b 0%, #0f172a 100%);
    border-color: #334155;
}

html[data-theme="dark"] .ai-facility-title {
    color: #f1f5f9;
}

html[data-theme="dark"] .ai-facility-badge {
    background: #1e40af;
    color: #dbeafe;
}

html[data-theme="dark"] .ai-slot-card {
    background: #1e293b;
    border-color: #334155;
}

html[data-theme="dark"] .ai-slot-card:hover {
    border-color: #3b82f6;
}

html[data-theme="dark"] .ai-slot-day {
    color: #94a3b8;
}

html[data-theme="dark"] .ai-slot-time {
    color: #f1f5f9;
}

html[data-theme="dark"] .ai-slot-insight {
    color: #94a3b8;
}

html[data-theme="dark"] .ai-holidays-card {
    background: #1e293b;
    border-color: #334155;
}

html[data-theme="dark"] .ai-holidays-header {
    background: linear-gradient(135deg, #78350f 0%, #451a03 100%);
    border-color: #7c2d12;
}

html[data-theme="dark"] .ai-holidays-header h3 {
    color: #fde68a;
}

html[data-theme="dark"] .ai-holidays-table th {
    background: #0f172a;
    color: #94a3b8;
    border-color: #334155;
}

html[data-theme="dark"] .ai-holidays-table td {
    color: #f1f5f9;
    border-color: #334155;
}

html[data-theme="dark"] .ai-empty-icon {
    color: #475569;
}
</style>

<div class="ai-scheduler-container">
    <div class="ai-page-header">
        <h1 class="ai-page-title">Personalized Recommendations</h1>
        <p class="ai-page-description">AI-powered suggestions based on your reservation history and preferences</p>
    </div>

    <!-- Welcome Section -->
    <div class="ai-welcome-section">
        <div class="ai-welcome-content">
            <div class="ai-welcome-icon"><i class="bi bi-stars"></i></div>
            <div class="ai-welcome-text">
                <h2>Recommended for You</h2>
                <p>Based on your reservation history, we've found these personalized suggestions to make booking faster and easier.</p>
            </div>
        </div>
    </div>

    <!-- Recommendations Grid -->
    <?php if (!empty($recommendations)): ?>
        <div class="ai-recommendations-grid">
            <?php foreach ($recommendations as $index => $rec): ?>
                <div class="ai-recommendation-card" data-index="<?= $index; ?>">
                    <?php if (isset($rec['is_fallback']) && $rec['is_fallback']): ?>
                        <div class="ai-rec-fallback-badge">
                            <i class="bi bi-lightbulb"></i> Popular Choice
                        </div>
                    <?php endif; ?>
                    
                    <div class="ai-rec-header">
                        <h3 class="ai-rec-facility-name"><?= htmlspecialchars($rec['facility_name']); ?></h3>
                        <span class="ai-rec-score">
                            <i class="bi bi-star-fill"></i> <?= $rec['score']; ?>%
                        </span>
                    </div>
                    
                    <div class="ai-rec-reasons">
                        <?php foreach ($rec['reasons'] as $reason): ?>
                            <div class="ai-rec-reason">
                                <span class="ai-rec-reason-icon">✓</span>
                                <span><?= htmlspecialchars($reason); ?></span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    
                    <div class="ai-rec-details">
                        <div class="ai-rec-detail">
                            <div class="ai-rec-detail-label">Suggested Date</div>
                            <div class="ai-rec-detail-value"><?= date('M d, Y', strtotime($rec['suggested_date'])); ?></div>
                        </div>
                        <div class="ai-rec-detail">
                            <div class="ai-rec-detail-label">Suggested Time</div>
                            <div class="ai-rec-detail-value"><?= htmlspecialchars($rec['suggested_time']); ?></div>
                        </div>
                        <div class="ai-rec-detail">
                            <div class="ai-rec-detail-label">Duration</div>
                            <div class="ai-rec-detail-value"><?= $rec['suggested_duration']; ?> hours</div>
                        </div>
                        <div class="ai-rec-detail">
                            <div class="ai-rec-detail-label">Attendees</div>
                            <div class="ai-rec-detail-value"><?= $rec['suggested_attendees']; ?></div>
                        </div>
                    </div>
                    
                    <a class="ai-rec-action" href="<?= base_path(); ?>/dashboard/book-facility?facility_id=<?= $rec['facility_id']; ?>&reservation_date=<?= $rec['suggested_date']; ?>&time_slot=<?= urlencode($rec['suggested_time']); ?>">
                        <i class="bi bi-calendar-plus"></i> Book This Reservation
                    </a>
                </div>
            <?php endforeach; ?>
        </div>
    <?php else: ?>
        <div class="ai-empty-state">
            <div class="ai-empty-icon"><i class="bi bi-inbox"></i></div>
            <h3 class="ai-empty-title">No Recommendations Yet</h3>
            <p class="ai-empty-text">Start making reservations to get personalized suggestions based on your preferences.</p>
            <div class="ai-empty-action">
                <a class="ai-empty-action-btn" href="<?= base_path(); ?>/dashboard/book-facility">
                    <i class="bi bi-plus-circle"></i> Make Your First Reservation
                </a>
            </div>
        </div>
    <?php endif; ?>
</div>

<script src="https://cdnjs.cloudflare.com/ajax/libs/gsap/3.12.2/gsap.min.js"></script>
<script>
// GSAP Animations
document.addEventListener('DOMContentLoaded', function() {
    // Animate page header
    gsap.to('.ai-page-header', {
        opacity: 1,
        y: 0,
        duration: 0.8,
        ease: 'power3.out'
    });
    
    // Animate welcome section
    gsap.to('.ai-welcome-section', {
        opacity: 1,
        y: 0,
        duration: 0.8,
        delay: 0.2,
        ease: 'power3.out'
    });
    
    // Animate recommendation cards with stagger
    gsap.to('.ai-recommendation-card', {
        opacity: 1,
        y: 0,
        duration: 0.6,
        stagger: 0.15,
        delay: 0.4,
        ease: 'back.out(1.7)'
    });
    
    // Animate empty state
    gsap.to('.ai-empty-state', {
        opacity: 1,
        y: 0,
        duration: 0.8,
        delay: 0.3,
        ease: 'power3.out'
    });
});
</script>

<?php
$content = ob_get_clean();
include __DIR__ . '/../../layouts/dashboard_layout.php';




