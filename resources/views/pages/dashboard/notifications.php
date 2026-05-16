<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../../../../config/app.php';

if (!($_SESSION['user_authenticated'] ?? false)) {
    header('Location: ' . base_path() . '/login');
    exit;
}

require_once __DIR__ . '/../../../../config/database.php';
require_once __DIR__ . '/../../../../config/notifications.php';
$pdo = db();
$pageTitle = 'Notifications | LGU Facilities Reservation';
$userId = $_SESSION['user_id'] ?? null;
$unreadCount = getUnreadNotificationCount((int)$userId);

// Handle mark as read
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['mark_read'])) {
    if (!frs_csrf_ok()) {
        header('Location: ' . base_path() . '/dashboard/notifications?error=csrf');
        exit;
    }
    $notifId = (int)($_POST['notification_id'] ?? 0);
    if ($notifId > 0) {
        $stmt = $pdo->prepare(
            'UPDATE notifications 
             SET is_read = TRUE 
             WHERE id = :id AND (user_id = :user_id OR user_id IS NULL)'
        );
        $stmt->execute([
            'id' => $notifId,
            'user_id' => $userId,
        ]);
        header('Location: ' . base_path() . '/dashboard/notifications');
        exit;
    }
}

// Pagination
$perPage = 20;
$page = max(1, (int)($_GET['page'] ?? 1));
$offset = ($page - 1) * $perPage;

// Get notifications
$stmt = $pdo->prepare(
    'SELECT id, type, title, message, link, is_read, created_at
     FROM notifications
     WHERE user_id = :user_id OR user_id IS NULL
     ORDER BY created_at DESC
     LIMIT :limit OFFSET :offset'
);
$stmt->bindValue(':user_id', $userId, PDO::PARAM_INT);
$stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$notifications = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get total count
$countStmt = $pdo->prepare(
    'SELECT COUNT(*) FROM notifications WHERE user_id = :user_id OR user_id IS NULL'
);
$countStmt->execute(['user_id' => $userId]);
$totalNotifications = (int)$countStmt->fetchColumn();
$totalPages = max(1, (int)ceil($totalNotifications / $perPage));

function formatTimeAgo($dateString) {
    $date = new DateTime($dateString);
    $now = new DateTime();
    $diff = $now->diff($date);
    
    if ($diff->days > 7) {
        return $date->format('M d, Y');
    } elseif ($diff->days > 0) {
        return $diff->days . ' day' . ($diff->days > 1 ? 's' : '') . ' ago';
    } elseif ($diff->h > 0) {
        return $diff->h . ' hour' . ($diff->h > 1 ? 's' : '') . ' ago';
    } elseif ($diff->i > 0) {
        return $diff->i . ' minute' . ($diff->i > 1 ? 's' : '') . ' ago';
    } else {
        return 'Just now';
    }
}

ob_start();
?>
<div class="page-header">
    <div class="breadcrumb">
        <span>Inbox</span><span class="sep">/</span><span>Notifications</span>
    </div>
    <h1>Notifications</h1>
    <small>Centralized alerts for approvals, reminders, and system advisories.</small>
</div>

<div class="booking-wrapper">
    <section class="booking-card">
        <div class="notif-page-header">
            <h2>All Notifications</h2>
            <?php if ($unreadCount > 0): ?>
                <button type="button" class="btn-outline notif-mark-all-page-btn" id="notifPageMarkAllBtn" title="Mark all as read">
                    Mark all as read
                </button>
            <?php endif; ?>
        </div>
        <?php if (empty($notifications)): ?>
            <div style="text-align: center; padding: 3rem; color: #8b95b5;">
                <p style="font-size: 1.1rem; margin-bottom: 0.5rem;">No notifications</p>
                <small>You're all caught up!</small>
            </div>
        <?php else: ?>
            <div class="notif-list" style="gap: 0.75rem;">
                <?php foreach ($notifications as $note): ?>
                    <div class="notif-item <?= $note['is_read'] ? '' : 'unread'; ?>" style="padding: 1rem;">
                        <div style="display: flex; justify-content: space-between; align-items: start; gap: 1rem;">
                            <div style="flex: 1;">
                                <strong style="display: block; margin-bottom: 0.5rem; color: var(--gov-blue-dark);">
                                    <?= htmlspecialchars($note['title']); ?>
                                </strong>
                                <p style="margin: 0 0 0.5rem; color: #5b6888; font-size: 0.9rem;">
                                    <?= htmlspecialchars($note['message']); ?>
                                </p>
                                <time style="font-size: 0.8rem; color: #8b95b5;">
                                    <?= formatTimeAgo($note['created_at']); ?>
                                </time>
                            </div>
                            <div style="display: flex; gap: 0.5rem; align-items: start;">
                                <span class="status-badge <?= $note['is_read'] ? 'status-approved' : 'status-pending'; ?>" style="white-space: nowrap;">
                                    <?= $note['is_read'] ? 'Read' : 'New'; ?>
                                </span>
                                <?php if (!$note['is_read']): ?>
                                    <button 
                                        type="button" 
                                        class="btn-outline mark-read-btn" 
                                        style="padding: 0.25rem 0.5rem; font-size: 0.8rem;" 
                                        data-notif-id="<?= $note['id']; ?>"
                                        onclick="markNotificationAsRead(this, <?= $note['id']; ?>)"
                                    >
                                        Mark Read
                                    </button>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php if ($note['link']): ?>
                            <a href="<?= htmlspecialchars($note['link']); ?>" style="display: inline-block; margin-top: 0.5rem; color: var(--gov-blue); font-size: 0.85rem; text-decoration: none;">
                                View Details →
                            </a>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
            
            <?php if ($totalPages > 1): ?>
                <div style="margin-top: 1.5rem; display: flex; justify-content: center; gap: 0.5rem; flex-wrap: wrap;">
                    <?php if ($page > 1): ?>
                        <a href="?page=<?= $page - 1; ?>" class="btn-outline">← Previous</a>
                    <?php endif; ?>
                    <span style="padding: 0.5rem 1rem; color: #5b6888;">
                        Page <?= $page; ?> of <?= $totalPages; ?>
                    </span>
                    <?php if ($page < $totalPages): ?>
                        <a href="?page=<?= $page + 1; ?>" class="btn-outline">Next →</a>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </section>

    <aside class="booking-card">
        <h2>Notification Types</h2>
        <ul class="audit-list">
            <li><strong>Booking Updates</strong> — approvals, denials, and schedule changes.</li>
            <li><strong>Reminders</strong> — upcoming reservations and document deadlines.</li>
            <li><strong>System Notices</strong> — maintenance windows and policy changes.</li>
        </ul>
    </aside>
</div>
<script>
// Function to mark notification as read via AJAX and update UI + badge
function markNotificationAsRead(button, notifId) {
    // Disable button
    button.disabled = true;
    button.textContent = 'Marking...';
    
    const markReadUrl = (typeof frsUrl === 'function' ? frsUrl : (p) => (window.APP_BASE_PATH || '') + p)('/dashboard/notifications-api?action=mark_read&id=' + notifId);
    const markReadHeaders = typeof frsPostHeaders === 'function' ? frsPostHeaders() : { 'Content-Type': 'application/x-www-form-urlencoded' };
    let markReadBody = typeof frsPostBody === 'function' ? frsPostBody().toString() : '';
    if (!markReadBody && window.CSRF_TOKEN_NAME && window.CSRF_TOKEN) {
        markReadBody = window.CSRF_TOKEN_NAME + '=' + encodeURIComponent(window.CSRF_TOKEN);
    }

    fetch(markReadUrl, {
        method: 'POST',
        headers: markReadHeaders,
        body: markReadBody
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            // Update the notification item UI
            const notifItem = button.closest('.notif-item');
            if (notifItem) {
                notifItem.classList.remove('unread');
                notifItem.classList.add('read');
                
                // Update status badge
                const statusBadge = notifItem.querySelector('.status-badge');
                if (statusBadge) {
                    statusBadge.className = 'status-badge status-approved';
                    statusBadge.textContent = 'Read';
                }
                
                // Remove the "Mark Read" button
                button.remove();
            }
            
            // Update badge count in navbar
            updateNotificationBadge();
        } else {
            // Re-enable button on error
            button.disabled = false;
            button.textContent = 'Mark Read';
            alert('Failed to mark notification as read. Please try again.');
        }
    })
    .catch(error => {
        console.error('Error marking notification as read:', error);
        button.disabled = false;
        button.textContent = 'Mark Read';
        alert('An error occurred. Please try again.');
    });
}

function applyAllReadStateOnPage() {
    document.querySelectorAll('.notif-item.unread').forEach(function (notifItem) {
        notifItem.classList.remove('unread');
        notifItem.classList.add('read');
        const statusBadge = notifItem.querySelector('.status-badge');
        if (statusBadge) {
            statusBadge.className = 'status-badge status-approved';
            statusBadge.textContent = 'Read';
        }
        const markBtn = notifItem.querySelector('.mark-read-btn');
        if (markBtn) {
            markBtn.remove();
        }
    });
    const pageMarkAllBtn = document.getElementById('notifPageMarkAllBtn');
    if (pageMarkAllBtn) {
        pageMarkAllBtn.style.display = 'none';
    }
}

function markAllNotificationsOnPage() {
    const btn = document.getElementById('notifPageMarkAllBtn');
    if (btn) {
        btn.disabled = true;
        btn.textContent = 'Marking...';
    }
    const request = (typeof frsMarkAllNotificationsRead === 'function')
        ? frsMarkAllNotificationsRead()
        : fetch((window.APP_BASE_PATH || '') + '/dashboard/notifications-api?action=mark_all_read', { method: 'POST' }).then(r => r.json());

    request
        .then(function (data) {
            if (data && data.success) {
                applyAllReadStateOnPage();
                updateNotificationBadge();
            } else {
                alert('Failed to mark all notifications as read. Please try again.');
                if (btn) {
                    btn.disabled = false;
                    btn.textContent = 'Mark all as read';
                }
            }
        })
        .catch(function (error) {
            console.error('Error marking all notifications as read:', error);
            alert('An error occurred. Please try again.');
            if (btn) {
                btn.disabled = false;
                btn.textContent = 'Mark all as read';
            }
        });
}

// Function to update notification badge count
function updateNotificationBadge() {
    fetch((window.APP_BASE_PATH || '') + '/dashboard/notifications-api?action=count')
        .then(response => response.json())
        .then(data => {
            if (data.success !== undefined) {
                const badge = document.querySelector('.notif-dot');
                const unreadCount = data.count || 0;
                const pageMarkAllBtn = document.getElementById('notifPageMarkAllBtn');
                const headerMarkAllBtn = document.getElementById('notifMarkAllBtn');
                
                if (unreadCount > 0) {
                    if (badge) {
                        badge.textContent = unreadCount > 9 ? '9+' : unreadCount.toString();
                        badge.style.display = '';
                    } else {
                        const bell = document.querySelector('.notif-bell');
                        if (bell) {
                            const newBadge = document.createElement('span');
                            newBadge.className = 'notif-dot';
                            newBadge.textContent = unreadCount > 9 ? '9+' : unreadCount.toString();
                            bell.appendChild(newBadge);
                        }
                    }
                    if (pageMarkAllBtn) {
                        pageMarkAllBtn.style.display = '';
                    }
                    if (headerMarkAllBtn) {
                        headerMarkAllBtn.style.display = '';
                    }
                } else {
                    if (badge) {
                        badge.style.display = 'none';
                    }
                    if (pageMarkAllBtn) {
                        pageMarkAllBtn.style.display = 'none';
                    }
                    if (headerMarkAllBtn) {
                        headerMarkAllBtn.style.display = 'none';
                    }
                }
            }
        })
        .catch(error => {
            console.error('Error updating badge:', error);
        });
}

// Update badge count when page loads (in case it changed)
document.addEventListener('DOMContentLoaded', function() {
    setTimeout(function() {
        updateNotificationBadge();
    }, 100);

    const pageMarkAllBtn = document.getElementById('notifPageMarkAllBtn');
    if (pageMarkAllBtn) {
        pageMarkAllBtn.addEventListener('click', markAllNotificationsOnPage);
    }
});
</script>
<?php
$content = ob_get_clean();
include __DIR__ . '/../../layouts/dashboard_layout.php';




