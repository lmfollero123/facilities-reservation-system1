<?php
require_once __DIR__ . '/../../../config/app.php';
$base = base_path();
?>
<!-- Facility Assistant Chatbot -->
<div id="facilityAssistantWidget" class="facility-assistant-widget">
    <!-- Chatbot Toggle Button -->
    <button id="assistantToggle" class="assistant-toggle-btn" aria-label="Open Facility Assistant" title="Check Facility Availability">
        <i class="bi bi-chat-dots-fill"></i>
        <span class="assistant-toggle-text">Assistant</span>
    </button>

    <!-- Chatbot Container -->
    <div id="assistantContainer" class="assistant-container" style="display: none;">
        <div class="assistant-header">
            <div class="assistant-header-content">
                <i class="bi bi-building"></i>
                <span>LGU Facility Assistant</span>
            </div>
            <button id="assistantClose" class="assistant-close-btn" aria-label="Close Assistant">
                <i class="bi bi-x-lg"></i>
            </button>
        </div>

        <div class="assistant-messages" id="assistantChat">
            <div class="assistant-message bot">
                <div class="assistant-bubble">
                    Hello! 👋<br>
                    I can help you check facility availability. Select a date below to get started.
                </div>
            </div>
        </div>

        <div class="assistant-controls">
            <button class="assistant-btn" onclick="assistantLoadDate('today')">
                <i class="bi bi-calendar-day"></i> Today
            </button>
            <button class="assistant-btn" onclick="assistantLoadDate('tomorrow')">
                <i class="bi bi-calendar-check"></i> Tomorrow
            </button>
            <button class="assistant-btn" onclick="assistantShowPicker()">
                <i class="bi bi-calendar-event"></i> Pick Date
            </button>
        </div>

        <div class="assistant-date-picker" id="assistantPicker" style="display: none;">
            <input type="date" id="assistantDateInput" onchange="assistantLoadCustomDate(this)">
        </div>
    </div>
</div>

<script>
(function() {
    'use strict';
    
    const chat = document.getElementById('assistantChat');
    const API_URL = '<?= $base; ?>/api/public/availability';
    let loadingBubble = null;
    let isOpen = false;

    // Toggle chatbot
    const toggleBtn = document.getElementById('assistantToggle');
    const container = document.getElementById('assistantContainer');
    const closeBtn = document.getElementById('assistantClose');

    toggleBtn.addEventListener('click', function() {
        isOpen = !isOpen;
        container.style.display = isOpen ? 'flex' : 'none';
        toggleBtn.classList.toggle('active', isOpen);
        
        // Focus on date input if picker is visible
        if (isOpen && document.getElementById('assistantPicker').style.display === 'block') {
            setTimeout(() => document.getElementById('assistantDateInput').focus(), 100);
        }
    });

    closeBtn.addEventListener('click', function() {
        isOpen = false;
        container.style.display = 'none';
        toggleBtn.classList.remove('active');
    });

    // Close on outside click
    document.addEventListener('click', function(e) {
        if (isOpen && !container.contains(e.target) && !toggleBtn.contains(e.target)) {
            isOpen = false;
            container.style.display = 'none';
            toggleBtn.classList.remove('active');
        }
    });

    // Prevent closing when clicking inside
    container.addEventListener('click', function(e) {
        e.stopPropagation();
    });

    function addBotMessage(html) {
        const el = document.createElement('div');
        el.className = 'assistant-message bot';
        el.innerHTML = `<div class="assistant-bubble">${html}</div>`;
        chat.appendChild(el);
        chat.scrollTop = chat.scrollHeight;
    }

    function showLoading(date) {
        if (loadingBubble) loadingBubble.remove();
        loadingBubble = document.createElement('div');
        loadingBubble.className = 'assistant-message bot';
        loadingBubble.innerHTML = `<div class="assistant-bubble">📅 Checking availability for <b>${date}</b>...</div>`;
        chat.appendChild(loadingBubble);
        chat.scrollTop = chat.scrollHeight;
    }

    window.assistantLoadDate = function(type) {
        let d = new Date();
        if (type === 'tomorrow') d.setDate(d.getDate() + 1);
        assistantFetchAvailability(d.toISOString().slice(0,10));
    };

    window.assistantShowPicker = function() {
        const picker = document.getElementById('assistantPicker');
        picker.style.display = picker.style.display === 'none' ? 'block' : 'none';
        if (picker.style.display === 'block') {
            setTimeout(() => document.getElementById('assistantDateInput').focus(), 100);
        }
    };

    window.assistantLoadCustomDate = function(el) {
        if (el.value) {
            assistantFetchAvailability(el.value);
            document.getElementById('assistantPicker').style.display = 'none';
        }
    };

    function assistantFetchAvailability(date) {
        showLoading(date);

        fetch(`${API_URL}?date=${date}`)
            .then(r => {
                const contentType = r.headers.get('content-type');
                if (!contentType || !contentType.includes('application/json')) {
                    return r.text().then(text => {
                        console.error('Non-JSON response:', text.substring(0, 200));
                        throw new Error('Server returned non-JSON response');
                    });
                }
                if (!r.ok) {
                    return r.json().then(data => {
                        throw new Error(data.error || 'Network error');
                    });
                }
                return r.json();
            })
            .then(data => {
                if (loadingBubble) loadingBubble.remove();
                if (data.error) {
                    addBotMessage(`❌ ${data.error}`);
                } else {
                    renderAvailability(data);
                }
            })
            .catch(err => {
                if (loadingBubble) loadingBubble.remove();
                const errorMsg = err.message || 'Failed to load availability. Please try again later.';
                addBotMessage(`❌ ${errorMsg}`);
                console.error('Availability fetch error:', err);
            });
    }

    function renderAvailability(data) {
        const dateFormatted = new Date(data.date + 'T00:00:00').toLocaleDateString('en-US', { 
            weekday: 'long', 
            year: 'numeric', 
            month: 'long', 
            day: 'numeric' 
        });
        
        let html = `<b>📅 Facility Availability</b><br><small style="color: #6b7280;">${dateFormatted}</small><br><br>`;

        if (!data.facilities || data.facilities.length === 0) {
            html += 'No facilities found.';
            addBotMessage(html);
            return;
        }

        data.facilities.forEach(f => {
            html += `<div style="margin-bottom: 12px;"><b>${f.facility_name}</b><br>`;

            if (f.status !== 'available') {
                html += `<span style="color: #dc2626;">❌ ${f.status.charAt(0).toUpperCase() + f.status.slice(1)}</span><br></div>`;
                return;
            }

            if (!f.timeline || f.timeline.length === 0) {
                html += `<span style="color: #059669;">✅ All day available</span><br></div>`;
                return;
            }

            f.timeline.forEach(t => {
                if (t.type === 'booked') {
                    html += `<span style="color: #dc2626; display: block; margin: 2px 0;">❌ ${t.range} (Booked)</span>`;
                } else if (t.type === 'available') {
                    html += `<span style="color: #059669; display: block; margin: 2px 0;">✅ ${t.range}</span>`;
                }
            });

            html += `</div>`;
        });

        addBotMessage(html);
    }
})();
</script>
