document.addEventListener("DOMContentLoaded", () => {
    const navToggle = document.querySelector(".nav-toggle");
    const mobileMenu = document.querySelector(".guest-nav-mobile");
    const sidebarToggle = document.querySelector("[data-sidebar-toggle]");
    const sidebar = document.querySelector(".sidebar");

    if (navToggle && mobileMenu) {
        navToggle.addEventListener("click", () => {
            mobileMenu.classList.toggle("active");
            navToggle.setAttribute(
                "aria-expanded",
                mobileMenu.classList.contains("active")
            );
        });
    }

    if (sidebarToggle && sidebar) {
        sidebarToggle.addEventListener("click", () => {
            sidebar.classList.toggle("collapsed");
            sidebarToggle.setAttribute(
                "aria-expanded",
                !sidebar.classList.contains("collapsed")
            );
        });
    }

    const notifToggle = document.querySelector("[data-toggle='notif-panel']");
    const notifPanel = document.getElementById("notifPanel");
    const notifPanelContent = document.getElementById("notifPanelContent");
    
    if (notifToggle && notifPanel && notifPanelContent) {
        let notificationsLoaded = false;
        
        function loadNotifications() {
            if (notificationsLoaded) return;
            notificationsLoaded = true;
            
            notifPanelContent.innerHTML = '<div class="notif-loading">Loading notifications...</div>';
            
            fetch((window.APP_BASE_PATH || '') + '/resources/views/pages/dashboard/notifications_api.php?action=list&limit=10')
                .then(response => response.json())
                .then(data => {
                    if (data.success && data.notifications) {
                        renderNotifications(data.notifications);
                    } else {
                        notifPanelContent.innerHTML = '<div class="notif-empty">No notifications</div>';
                    }
                })
                .catch(error => {
                    console.error('Error loading notifications:', error);
                    notifPanelContent.innerHTML = '<div class="notif-empty">Error loading notifications</div>';
                });
        }
        
        function renderNotifications(notifications) {
            if (notifications.length === 0) {
                notifPanelContent.innerHTML = '<div class="notif-empty">No notifications</div>';
                return;
            }
            
            const html = notifications.map(notif => {
                const timeAgo = formatTimeAgo(notif.created_at);
                const unreadClass = notif.is_read ? '' : 'unread';
                const link = notif.link ? `onclick="window.location.href='${notif.link}'"` : '';
                return `
                    <div class="notif-item ${unreadClass}" data-notif-id="${notif.id}" ${link}>
                        <strong>${escapeHtml(notif.title)}</strong>
                        <p>${escapeHtml(notif.message)}</p>
                        <time>${timeAgo}</time>
                    </div>
                `;
            }).join('');
            
            notifPanelContent.innerHTML = `<div class="notif-list">${html}</div>`;
            
            // Add click handlers for marking as read
            notifPanelContent.querySelectorAll('.notif-item').forEach(item => {
                item.addEventListener('click', function() {
                    const notifId = this.dataset.notifId;
                    if (!this.classList.contains('read')) {
                        markAsRead(notifId);
                        this.classList.remove('unread');
                        this.classList.add('read');
                    }
                });
            });
        }
        
        function markAsRead(notifId) {
            fetch((window.APP_BASE_PATH || '') + '/resources/views/pages/dashboard/notifications_api.php?action=mark_read&id=' + notifId, {
                method: 'POST'
            }).catch(error => console.error('Error marking as read:', error));
        }
        
        function formatTimeAgo(dateString) {
            const date = new Date(dateString);
            const now = new Date();
            const diffMs = now - date;
            const diffMins = Math.floor(diffMs / 60000);
            const diffHours = Math.floor(diffMs / 3600000);
            const diffDays = Math.floor(diffMs / 86400000);
            
            if (diffMins < 1) return 'Just now';
            if (diffMins < 60) return diffMins + ' minute' + (diffMins > 1 ? 's' : '') + ' ago';
            if (diffHours < 24) return diffHours + ' hour' + (diffHours > 1 ? 's' : '') + ' ago';
            if (diffDays < 7) return diffDays + ' day' + (diffDays > 1 ? 's' : '') + ' ago';
            return date.toLocaleDateString();
        }
        
        function escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }
        
        notifToggle.addEventListener("click", (event) => {
            event.preventDefault();
            event.stopPropagation();
            notifPanel.classList.toggle("open");
            if (notifPanel.classList.contains("open")) {
                loadNotifications();
            }
        });

        document.addEventListener("click", (event) => {
            if (
                notifPanel.classList.contains("open") &&
                !notifPanel.contains(event.target) &&
                !event.target.closest("[data-toggle='notif-panel']")
            ) {
                notifPanel.classList.remove("open");
            }
        });
    }

    // AI Conflict Detection - Real-time checking on booking form
    const facilitySelect = document.getElementById('facility-select');
    const reservationDate = document.getElementById('reservation-date');
    const timeSlot = document.getElementById('time-slot');
    const conflictWarning = document.getElementById('conflict-warning');
    const conflictMessage = document.getElementById('conflict-message');
    const conflictAlternatives = document.getElementById('conflict-alternatives');
    const alternativesList = document.getElementById('alternatives-list');

    function checkConflict() {
        const facilityId = facilitySelect?.value;
        const date = reservationDate?.value;
        const slot = timeSlot?.value;

        if (!facilityId || !date || !slot) {
            if (conflictWarning) conflictWarning.style.display = 'none';
            return;
        }

        // Check conflict via API
        const basePath = window.APP_BASE_PATH || '';
        fetch(basePath + '/resources/views/pages/dashboard/ai_conflict_check.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: `facility_id=${facilityId}&date=${date}&time_slot=${encodeURIComponent(slot)}`
        })
        .then(response => response.json())
        .then(data => {
            if (!conflictWarning) return;

            if (data.has_conflict) {
                conflictWarning.style.display = 'block';
                conflictWarning.style.background = '#fdecee';
                conflictWarning.style.borderColor = '#b23030';
                conflictMessage.textContent = data.message || 'This time slot is already booked.';
                conflictMessage.style.color = '#b23030';

                if (data.alternatives && data.alternatives.length > 0) {
                    conflictAlternatives.style.display = 'block';
                    alternativesList.innerHTML = data.alternatives
                        .filter(alt => alt.available)
                        .map(alt => `<li><strong>${alt.time_slot}</strong> - ${alt.recommendation}</li>`)
                        .join('');
                } else {
                    conflictAlternatives.style.display = 'none';
                }
            } else if (data.risk_score > 70) {
                conflictWarning.style.display = 'block';
                conflictWarning.style.background = '#fff4e5';
                conflictWarning.style.borderColor = '#ffc107';
                conflictMessage.textContent = `High demand period detected (Risk Score: ${data.risk_score}%). Consider booking well in advance.`;
                conflictMessage.style.color = '#856404';
                conflictAlternatives.style.display = 'none';
            } else {
                conflictWarning.style.display = 'none';
            }
        })
        .catch(error => {
            console.error('Error checking conflict:', error);
        });
    }

    if (facilitySelect && reservationDate && timeSlot) {
        facilitySelect.addEventListener('change', checkConflict);
        reservationDate.addEventListener('change', checkConflict);
        timeSlot.addEventListener('change', checkConflict);
    }

    // AI Facility Recommendations - Real-time as user types
    const purposeInput = document.getElementById('purpose-input');
    const aiRecommendations = document.getElementById('ai-recommendations');
    const recommendationsList = document.getElementById('recommendations-list');
    let recommendationTimeout = null;

    function loadRecommendations() {
        const purpose = purposeInput?.value.trim();
        
        if (!purpose || purpose.length < 3) {
            if (aiRecommendations) aiRecommendations.style.display = 'none';
            return;
        }

        // Debounce: wait 500ms after user stops typing
        clearTimeout(recommendationTimeout);
        recommendationTimeout = setTimeout(() => {
            const basePath = window.APP_BASE_PATH || '';
            fetch(basePath + '/resources/views/pages/dashboard/ai_recommendations_api.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: `purpose=${encodeURIComponent(purpose)}`
            })
            .then(response => response.json())
            .then(data => {
                if (!aiRecommendations || !recommendationsList) return;

                if (data.success && data.recommendations && data.recommendations.length > 0) {
                    aiRecommendations.style.display = 'block';
                    
                    function escapeHtml(text) {
                        if (!text) return '';
                        const div = document.createElement('div');
                        div.textContent = text;
                        return div.innerHTML;
                    }
                    
                    const html = '<ul style="margin:0; padding-left:1.25rem; list-style:none;">' +
                        data.recommendations.map(rec => {
                            const reasons = rec.reasons && rec.reasons.length > 0 
                                ? '<br><small style="opacity:0.7;">' + escapeHtml(Array.isArray(rec.reasons) ? rec.reasons.slice(0, 2).join(', ') : rec.reasons) + '</small>'
                                : '';
                            const distanceInfo = rec.distance ? ` <span style="color:#2563eb; font-weight:600;">• ${escapeHtml(rec.distance)} away</span>` : '';
                            return `<li style="margin-bottom:0.5rem; cursor:pointer; padding:0.5rem; border-radius:4px; transition:background 0.2s;" onmouseover="this.style.background='rgba(13,122,67,0.1)'" onmouseout="this.style.background='transparent'" onclick="document.getElementById('facility-select').value='${rec.facility_id}'; document.getElementById('facility-select').dispatchEvent(new Event('change'));">
                                <strong>${escapeHtml(rec.name)}</strong> 
                                <span style="opacity:0.8;">(${rec.match_score}% match)</span>${distanceInfo}
                                ${reasons}
                            </li>`;
                        }).join('') +
                        '</ul>';
                    
                    recommendationsList.innerHTML = html;
                } else {
                    aiRecommendations.style.display = 'none';
                }
            })
            .catch(error => {
                console.error('Error loading recommendations:', error);
            });
        }, 500);
    }

    if (purposeInput) {
        purposeInput.addEventListener('input', loadRecommendations);
        purposeInput.addEventListener('paste', () => {
            setTimeout(loadRecommendations, 100);
        });
    }
});


