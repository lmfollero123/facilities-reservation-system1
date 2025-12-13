document.addEventListener("DOMContentLoaded", () => {
    const navToggle = document.querySelector(".nav-toggle");
    const mobileMenu = document.querySelector(".guest-nav-mobile");
    const sidebarToggle = document.querySelector("[data-sidebar-toggle]");
    const sidebar = document.querySelector(".sidebar");
    const sidebarClose = document.querySelector("[data-sidebar-close]");
    // Create / fetch backdrop for mobile sidebar
    let sidebarBackdrop = document.querySelector(".sidebar-backdrop");
    if (!sidebarBackdrop) {
        sidebarBackdrop = document.createElement("div");
        sidebarBackdrop.className = "sidebar-backdrop";
        document.body.appendChild(sidebarBackdrop);
    }

    const closeSidebar = () => {
        if (!sidebar) return;
        sidebar.classList.add("collapsed");
        sidebarToggle?.setAttribute("aria-expanded", "false");
        sidebarBackdrop.classList.remove("active");
    };

    const openSidebar = () => {
        if (!sidebar) return;
        sidebar.classList.remove("collapsed");
        sidebarToggle?.setAttribute("aria-expanded", "true");
        sidebarBackdrop.classList.add("active");
    };

    const syncSidebarInitial = () => {
        if (!sidebar) return;
        if (window.innerWidth <= 960) {
            closeSidebar();
        } else {
            sidebar.classList.remove("collapsed");
            sidebarBackdrop.classList.remove("active");
            sidebarToggle?.setAttribute("aria-expanded", "true");
        }
    };

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
        sidebarToggle.addEventListener("click", (e) => {
            e.stopPropagation();
            const isCollapsed = sidebar.classList.contains("collapsed");
            if (isCollapsed) {
                openSidebar();
            } else {
                closeSidebar();
            }
        });
    }

    if (sidebarClose) {
        sidebarClose.addEventListener("click", (e) => {
            e.stopPropagation();
            closeSidebar();
        });
    }

    // Close sidebar when clicking the backdrop
    sidebarBackdrop.addEventListener("click", () => {
        closeSidebar();
    });

    // Close when clicking anywhere outside sidebar while open
    document.addEventListener("click", (event) => {
        if (!sidebar) return;
        const clickInsideSidebar = sidebar.contains(event.target);
        const clickToggle = sidebarToggle?.contains(event.target);
        const isOpen = !sidebar.classList.contains("collapsed");
        if (isOpen && !clickInsideSidebar && !clickToggle && window.innerWidth <= 960) {
            closeSidebar();
        }
    });

    // Ensure correct state on load and resize
    syncSidebarInitial();
    window.addEventListener("resize", syncSidebarInitial);

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

    // Collapsible helper with localStorage persistence (shared)
    (function() {
        const STORAGE_KEY = 'collapse-state-dashboard';
        let state = {};
        try { state = JSON.parse(localStorage.getItem(STORAGE_KEY) || '{}'); } catch (e) { state = {}; }
        function save() { localStorage.setItem(STORAGE_KEY, JSON.stringify(state)); }
        function initCollapsibles() {
            // Skip if disabled by page-specific handler
            if (window.DISABLE_GLOBAL_COLLAPSIBLE) {
                return;
            }
            
            document.querySelectorAll('.collapsible-header').forEach(header => {
                const targetId = header.getAttribute('data-collapse-target');
                if (!targetId) return;
                
                const body = document.getElementById(targetId);
                if (!body) {
                    console.warn('Collapsible target not found:', targetId);
                    return;
                }
                
                // Check if it's a sidebar collapsible (uses data-collapsed attribute)
                const isSidebarCollapsible = body.hasAttribute('data-collapsed');
                
                if (isSidebarCollapsible) {
                    // Sidebar collapsible - use data-collapsed attribute
                    const chevron = header.querySelector('.chevron-icon');
                    const savedState = state[targetId];
                    const shouldBeCollapsed = savedState === true;
                    
                    // Initialize state - ensure it's set correctly
                    if (shouldBeCollapsed) {
                        body.setAttribute('data-collapsed', 'true');
                        header.setAttribute('data-collapsed', 'true');
                        if (chevron) chevron.style.transform = 'rotate(-90deg)';
                    } else {
                        body.setAttribute('data-collapsed', 'false');
                        header.setAttribute('data-collapsed', 'false');
                        if (chevron) chevron.style.transform = 'rotate(0deg)';
                    }
                    
                    // Add click handler - use once to prevent duplicates, then re-attach if needed
                    header.style.cursor = 'pointer';
                    
                    // Remove existing listener if any (by cloning)
                    const handler = (e) => {
                        e.preventDefault();
                        e.stopPropagation();
                        
                        const currentState = body.getAttribute('data-collapsed') === 'true';
                        const newState = !currentState;
                        
                        // Update attributes immediately
                        body.setAttribute('data-collapsed', newState ? 'true' : 'false');
                        header.setAttribute('data-collapsed', newState ? 'true' : 'false');
                        
                        // Update state and save
                        state[targetId] = newState;
                        save();
                        
                        // Update chevron
                        if (chevron) {
                            chevron.style.transform = newState ? 'rotate(-90deg)' : 'rotate(0deg)';
                        }
                        
                        // Force reflow to ensure CSS applies
                        void body.offsetHeight;
                    };
                    
                    header.addEventListener('click', handler);
                } else {
                    // Regular collapsible - use is-collapsed class
                    const chevron = header.querySelector('.chevron');
                    if (state[targetId]) {
                        body.classList.add('is-collapsed');
                        if (chevron) chevron.style.transform = 'rotate(-90deg)';
                    }
                    header.addEventListener('click', () => {
                        const isCollapsed = body.classList.toggle('is-collapsed');
                        if (chevron) chevron.style.transform = isCollapsed ? 'rotate(-90deg)' : 'rotate(0deg)';
                        state[targetId] = isCollapsed;
                        save();
                    });
                }
            });
        }
        // Initialize on DOM ready
        function runInit() {
            initCollapsibles();
        }
        
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', runInit);
        } else {
            // DOM is already ready
            runInit();
        }
        
        // Also try after a short delay to ensure sidebar is rendered (fallback)
        setTimeout(runInit, 100);
        setTimeout(runInit, 500);
    })();

    // Generic confirmation handler for critical actions
    document.addEventListener('click', function(e) {
        const trigger = e.target.closest('.confirm-action');
        if (!trigger) return;
        const msg = trigger.getAttribute('data-message') || 'Are you sure you want to continue?';
        if (!window.confirm(msg)) {
            e.preventDefault();
            e.stopPropagation();
        }
    });

    // AI Conflict Detection - Real-time checking on booking form
    // Disabled when page defines window.DISABLE_CONFLICT_CHECK (booking page uses its own handler)
    if (!window.DISABLE_CONFLICT_CHECK) {
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


