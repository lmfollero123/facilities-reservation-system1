<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../../../config/app.php';
$isLoggedIn = $_SESSION['user_authenticated'] ?? false;
if (!$isLoggedIn) {
    header('Location: ' . base_path() . '/login');
    exit;
}

// Redirect old full paths to clean URLs
$currentPath = $_SERVER['REQUEST_URI'] ?? '';
if (strpos($currentPath, '/resources/views/pages/dashboard/') !== false) {
    // Map old file paths to clean URLs
    $oldPathMap = [
        'index.php' => '/dashboard',
        'book_facility.php' => '/dashboard/book-facility',
        'my_reservations.php' => '/dashboard/my-reservations',
        'ai_scheduling.php' => '/dashboard/ai-scheduling',
        'reservations_manage.php' => '/dashboard/reservations-manage',
        'announcements_manage.php' => '/dashboard/announcements-manage',
        'facility_management.php' => '/dashboard/facility-management',
        'maintenance_integration.php' => '/dashboard/maintenance-integration',
        'infrastructure_projects_integration.php' => '/dashboard/infrastructure-projects',
        'utilities_integration.php' => '/dashboard/utilities-integration',
        'reports.php' => '/dashboard/reports',
        'user_management.php' => '/dashboard/user-management',
        'document_management.php' => '/dashboard/document-management',
        'contact_info_manage.php' => '/dashboard/contact-info',
        'audit_trail.php' => '/dashboard/audit-trail',
        'profile.php' => '/dashboard/profile',
        'calendar.php' => '/dashboard/calendar',
        'notifications.php' => '/dashboard/notifications',
    ];
    
    foreach ($oldPathMap as $oldFile => $cleanUrl) {
        if (strpos($currentPath, $oldFile) !== false) {
            $redirectUrl = base_path() . $cleanUrl;
            // Preserve query string if present
            if (strpos($currentPath, '?') !== false) {
                $queryString = substr($currentPath, strpos($currentPath, '?'));
                $redirectUrl .= $queryString;
            }
            header('Location: ' . $redirectUrl, true, 301);
            exit;
        }
    }
}

$pageTitle = $pageTitle ?? 'LGU Dashboard';
$userName = $_SESSION['name'] ?? 'User';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <!-- Mobile-first viewport meta tag with proper scaling -->
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0, user-scalable=yes, viewport-fit=cover">
    <title><?= htmlspecialchars($pageTitle); ?></title>
    <?php $cssVersion = '9.0'; // Cache-busting: Update when CSS changes are deployed ?>
    <link rel="stylesheet" href="<?= base_path(); ?>/public/css/style.css?v=<?= $cssVersion; ?>">
    <!-- Bootstrap Icons -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.5.0/font/bootstrap-icons.css" rel="stylesheet" />
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
</head>
<body class="dashboard">
<!-- Loading Overlay -->
<div class="loading-overlay" id="loadingOverlay">
    <div class="loading-spinner">
        <div class="spinner"></div>
        <div class="loading-text">Loading...</div>
    </div>
</div>

<?php include __DIR__ . '/../components/sidebar_dashboard.php'; ?>
<div class="dashboard-main">
    <?php include __DIR__ . '/../components/navbar_dashboard.php'; ?>
    <section class="dashboard-content">
        <?= $content ?? ''; ?>
    </section>
</div>

<!-- Global AI Assistant (floating, dashboard-only) -->
<button
    type="button"
    class="chatbot-fab"
    id="chatbotWidgetFab"
    aria-label="Open AI Assistant"
    title="Ask the AI Assistant"
>
    <span class="chatbot-fab-icon">🤖</span>
</button>

<div class="chatbot-panel" id="chatbotWidgetPanel" aria-hidden="true">
    <div class="chatbot-panel-inner">
        <header class="chatbot-panel-header">
            <div class="chatbot-header-main">
                <div class="chatbot-avatar">
                    🤖
                </div>
                <div class="chatbot-header-text">
                    <h3>LGU AI Assistant</h3>
                    <p>Hi <?= htmlspecialchars($userName); ?>, how can I help you today?</p>
                </div>
            </div>
            <button type="button" class="chatbot-close-btn" id="chatbotWidgetCloseBtn" aria-label="Close AI Assistant">
                ✕
            </button>
        </header>

        <div class="chatbot-messages" id="chatbotWidgetMessages">
            <div class="message bot-message">
                <div class="message-avatar">🤖</div>
                <div class="message-body">
                    <div class="message-content">
                        <p>Hello <?= htmlspecialchars($userName); ?>! I'm your AI assistant. I can help you with:</p>
                        <ul>
                            <li>Finding available facilities</li>
                            <li>Understanding booking policies</li>
                            <li>Checking reservation status</li>
                            <li>Answering FAQs</li>
                            <li>Guiding you through the booking process</li>
                        </ul>
                        <p class="message-note">
                            <strong>Note:</strong> AI model integration is in progress. For now, I provide helpful predefined responses.
                        </p>
                    </div>
                    <small class="message-meta">Just now</small>
                </div>
            </div>
        </div>

        <footer class="chatbot-input-area">
            <form id="chatbotWidgetForm" autocomplete="off">
                <div class="chatbot-input-wrapper">
                    <textarea
                        id="chatbotWidgetInput"
                        placeholder="Type your message here..."
                        rows="1"
                    ></textarea>
                </div>
                <button type="submit" class="btn-primary chatbot-send-btn" id="chatbotWidgetSendBtn">
                    <span>Send</span>
                </button>
            </form>
            <div class="chatbot-quick-actions">
                <button type="button" class="chatbot-quick-btn" data-action="available-facilities">
                    📋 Available Facilities
                </button>
                <button type="button" class="chatbot-quick-btn" data-action="booking-policy">
                    📖 Booking Policy
                </button>
                <button type="button" class="chatbot-quick-btn" data-action="my-reservations">
                    📅 My Reservations
                </button>
                <button type="button" class="chatbot-quick-btn" data-action="help">
                    ❓ Help
                </button>
            </div>
        </footer>
    </div>
</div>

<div class="modal-confirm" id="confirmModal" aria-hidden="true">
    <div class="modal-dialog" role="dialog" aria-modal="true">
        <h3>Confirm Action</h3>
        <p class="confirm-message">Are you sure?</p>
        <div class="modal-actions">
            <button type="button" class="btn-outline" data-confirm-cancel>Cancel</button>
            <button type="button" class="btn-primary" data-confirm-accept>Yes, continue</button>
        </div>
    </div>
</div>

<script>
    window.APP_BASE_PATH = "<?= base_path(); ?>";
</script>
<script src="<?= base_path(); ?>/public/js/main.js"></script>
<script>
(function () {
    const modal = document.getElementById('confirmModal');
    if (!modal) {
        return;
    }
    const messageEl = modal.querySelector('.confirm-message');
    const cancelBtn = modal.querySelector('[data-confirm-cancel]');
    const acceptBtn = modal.querySelector('[data-confirm-accept]');
    let pendingAction = null;

    document.addEventListener('click', function (event) {
        const trigger = event.target.closest('.confirm-action');
        if (!trigger) {
            return;
        }
        if (trigger.dataset.skipConfirm === 'true') {
            trigger.dataset.skipConfirm = '';
            return;
        }
        event.preventDefault();
        pendingAction = trigger;
        messageEl.textContent = trigger.dataset.message || 'Are you sure?';
        modal.classList.add('open');
    });

    function closeModal() {
        modal.classList.remove('open');
    }

    cancelBtn.addEventListener('click', function () {
        pendingAction = null;
        closeModal();
    });

    acceptBtn.addEventListener('click', function () {
        if (!pendingAction) {
            closeModal();
            return;
        }
        const actionEl = pendingAction;
        pendingAction = null;
        closeModal();

        if (actionEl.dataset.facility && typeof editFacility === 'function') {
            editFacility(actionEl.dataset.facility);
            return;
        }

        actionEl.dataset.skipConfirm = 'true';
        if (actionEl.tagName === 'A' && actionEl.href) {
            window.location.href = actionEl.href;
        } else if (typeof actionEl.click === 'function') {
            actionEl.click();
        }
    });
})();
</script>
<script>
// AI Assistant (floating chatbot) - dashboard-only
document.addEventListener('DOMContentLoaded', function () {
    const fab = document.getElementById('chatbotWidgetFab');
    const panel = document.getElementById('chatbotWidgetPanel');
    const closeBtn = document.getElementById('chatbotWidgetCloseBtn');
    const form = document.getElementById('chatbotWidgetForm');
    const input = document.getElementById('chatbotWidgetInput');
    const messagesContainer = document.getElementById('chatbotWidgetMessages');
    const sendBtn = document.getElementById('chatbotWidgetSendBtn');

    if (!fab || !panel || !form || !input || !messagesContainer || !sendBtn) {
        return;
    }

    function openChat() {
        panel.classList.add('open');
        panel.setAttribute('aria-hidden', 'false');
        // Scroll to bottom and focus input after opening
        setTimeout(() => {
            scrollToBottom();
            input.focus();
        }, 200);
    }

    function closeChat() {
        panel.classList.remove('open');
        panel.setAttribute('aria-hidden', 'true');
    }

    fab.addEventListener('click', function () {
        if (panel.classList.contains('open')) {
            closeChat();
        } else {
            openChat();
        }
    });

    closeBtn.addEventListener('click', function () {
        closeChat();
    });

    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && panel.classList.contains('open')) {
            closeChat();
        }
    });

    // Quick action buttons (scoped to the widget panel only)
    panel.querySelectorAll('.chatbot-quick-btn').forEach(function (btn) {
        btn.addEventListener('click', function () {
            const action = this.getAttribute('data-action');
            const messages = {
                'available-facilities': 'What facilities are available for booking?',
                'booking-policy': 'What are the booking policies and rules?',
                'my-reservations': 'Show me my reservations',
                'help': 'I need help with the reservation system'
            };
            if (messages[action]) {
                input.value = messages[action];
                form.dispatchEvent(new Event('submit'));
            }
        });
    });

    // Auto-resize textarea
    input.addEventListener('input', function () {
        this.style.height = 'auto';
        this.style.height = Math.min(this.scrollHeight, 120) + 'px';
    });

    // Handle Enter key: Send on Enter, new line on Shift+Enter
    input.addEventListener('keydown', function (e) {
        if (e.key === 'Enter' && !e.shiftKey) {
            e.preventDefault();
            form.dispatchEvent(new Event('submit'));
        }
        // Shift+Enter allows new line (default behavior)
    });

    form.addEventListener('submit', function (e) {
        e.preventDefault();

        const message = input.value.trim();
        if (!message) return;

        // Clear input but maintain minimum height
        input.value = '';
        input.style.height = '38px'; // Ensure it stays visible (matches min-height from CSS)

        addMessage(message, 'user');

        const typingId = showTypingIndicator();
        const sendBtn = document.getElementById('chatbotWidgetSendBtn');
        
        // Disable send button while waiting for response
        if (sendBtn) {
            sendBtn.disabled = true;
        }

        // Call real chatbot API
        const formData = new URLSearchParams();
        formData.append('message', message);

        fetch('<?= base_path(); ?>/resources/views/pages/dashboard/ai_chatbot.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded'
            },
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            removeTypingIndicator(typingId);
            const responseText = data.reply || 'I apologize, but I couldn\'t process your request. Please try again.';
            addMessage(responseText, 'bot');
            // Refocus input after bot responds
            setTimeout(function() {
                if (input && document.body.contains(input)) {
                    input.focus();
                }
            }, 150);
        })
        .catch(error => {
            console.error('Chatbot API error:', error);
            removeTypingIndicator(typingId);
            addMessage('I apologize, but I\'m having trouble connecting right now. Please try again later.', 'bot');
        })
        .finally(() => {
            // Re-enable send button
            if (sendBtn) {
                sendBtn.disabled = false;
            }
        });
    });

    function addMessage(text, type) {
        const wrapper = document.createElement('div');
        wrapper.className = 'message ' + (type === 'user' ? 'user-message' : 'bot-message');

        if (type === 'user') {
            wrapper.innerHTML = '' +
                '<div class="message-body user-body">' +
                '  <div class="message-content">' +
                '    <p>' + escapeHtml(text) + '</p>' +
                '  </div>' +
                '  <small class="message-meta">' + formatTime(new Date()) + '</small>' +
                '</div>';
        } else {
            wrapper.innerHTML = '' +
                '<div class="message-avatar">🤖</div>' +
                '<div class="message-body">' +
                '  <div class="message-content">' +
                '    <p>' + escapeHtml(text).replace(/\n/g, '<br>') + '</p>' +
                '  </div>' +
                '  <small class="message-meta">' + formatTime(new Date()) + '</small>' +
                '</div>';
        }

        messagesContainer.appendChild(wrapper);
        // Scroll after DOM update - use multiple attempts to ensure it works
        setTimeout(scrollToBottom, 50);
        setTimeout(scrollToBottom, 200);
    }

    function showTypingIndicator() {
        const id = 'typing-' + Date.now();
        const wrapper = document.createElement('div');
        wrapper.className = 'message bot-message typing';
        wrapper.id = id;
        wrapper.innerHTML = '' +
            '<div class="message-avatar">🤖</div>' +
            '<div class="message-body">' +
            '  <div class="message-content typing-dots">' +
            '    <span></span><span></span><span></span>' +
            '  </div>' +
            '</div>';
        messagesContainer.appendChild(wrapper);
        // Scroll after DOM update - use multiple attempts to ensure it works
        setTimeout(scrollToBottom, 50);
        setTimeout(scrollToBottom, 200);
        return id;
    }

    function removeTypingIndicator(id) {
        const el = document.getElementById(id);
        if (el) el.remove();
    }

    function scrollToBottom() {
        if (!messagesContainer) return;
        // Use requestAnimationFrame to ensure DOM is updated before scrolling
        requestAnimationFrame(function() {
            if (messagesContainer) {
                messagesContainer.scrollTop = messagesContainer.scrollHeight;
            }
        });
        // Double-check after a short delay
        setTimeout(function() {
            if (messagesContainer) {
                messagesContainer.scrollTop = messagesContainer.scrollHeight;
            }
        }, 10);
    }

    function escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    function formatTime(date) {
        return date.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
    }

    function getMockResponse(message) {
        const lower = message.toLowerCase();

        if (lower.includes('facility') || lower.includes('available')) {
            return (
                'I can help you find available facilities! Here are some options:\n\n' +
                '• Community Convention Hall\n' +
                '• Municipal Sports Complex\n' +
                '• People\'s Park Amphitheater\n\n' +
                'You can browse all facilities and check their availability on the "Book a Facility" page. ' +
                'All facilities are provided free of charge for Barangay Culiat residents.\n\n' +
                'Would you like me to help you check availability for a specific date?'
            );
        }

        if (lower.includes('policy') || lower.includes('rule') || lower.includes('booking')) {
            return (
                'Here are the key booking policies:\n\n' +
                '📋 Reservation Limits:\n' +
                '• Maximum 3 active reservations (pending + approved) within 30 days\n' +
                '• Bookings allowed up to 60 days in advance\n' +
                '• Maximum 1 booking per user per day\n\n' +
                '📅 Rescheduling:\n' +
                '• Allowed up to 3 days before the event\n' +
                '• Only one reschedule per reservation\n' +
                '• Approved reservations require re-approval after rescheduling\n\n' +
                '💰 Cost:\n' +
                '• All facilities are completely free for residents\n\n' +
                'Need more details about any specific policy?'
            );
        }

        if (lower.includes('reservation') || lower.includes('my booking')) {
            return (
                'To view your reservations:\n\n' +
                '1. Go to "My Reservations" in the sidebar\n' +
                '2. You\'ll see all your bookings with their current status\n' +
                '3. You can reschedule approved reservations (if allowed)\n\n' +
                'Reservation statuses:\n' +
                '• Pending - Waiting for admin approval\n' +
                '• Approved - Confirmed and ready\n' +
                '• Denied - Request was declined\n' +
                '• Cancelled - Reservation was cancelled\n\n' +
                'Would you like help with a specific reservation?'
            );
        }

        if (lower.includes('help') || lower.includes('how')) {
            return (
                'I\'m here to help! Here\'s what I can assist you with:\n\n' +
                '✅ Finding and booking facilities\n' +
                '✅ Understanding booking policies\n' +
                '✅ Checking reservation status\n' +
                '✅ Rescheduling reservations\n' +
                '✅ Answering FAQs\n\n' +
                'How to book a facility:\n' +
                '1. Click "Book a Facility" in the sidebar\n' +
                '2. Select a facility, date, and time slot\n' +
                '3. Fill in the purpose and submit\n' +
                '4. Wait for admin approval\n\n' +
                'What would you like to know more about?'
            );
        }

        return (
            'Thanks for your message! I understand you\'re asking about: "' + message + '"\n\n' +
            'Currently, I\'m running in demo mode. Once the AI model is integrated, I\'ll be able to provide more detailed ' +
            'and personalized responses.\n\n' +
            'For now, I can help you with:\n' +
            '• Finding available facilities\n' +
            '• Understanding booking policies\n' +
            '• Checking your reservations\n' +
            '• General FAQs\n\n' +
            'Try asking about facilities, booking policies, or your reservations!'
        );
    }
});
</script>
</body>
</html>


