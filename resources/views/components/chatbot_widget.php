<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
$isLoggedIn = $_SESSION['user_authenticated'] ?? false;
$userName = $_SESSION['name'] ?? 'User';
$userId = $_SESSION['user_id'] ?? null;
?>
<!-- Floating Chat Button -->
<button type="button" id="chatbot-toggle" class="chatbot-toggle" aria-label="Open AI Assistant">
    <svg width="24" height="24" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
        <path d="M21 11.5C21.0034 12.8199 20.6951 14.1219 20.1 15.3C19.3944 16.7118 18.3098 17.8992 16.9674 18.7293C15.6251 19.5594 14.0782 19.9994 12.5 20C11.1801 20.0035 9.87812 19.6951 8.7 19.1L3 21L4.9 15.3C4.30493 14.1219 3.99656 12.8199 4 11.5C4.00061 9.92179 4.44061 8.37488 5.27072 7.03258C6.10083 5.69028 7.28825 4.6056 8.7 3.90003C9.87812 3.30496 11.1801 2.99659 12.5 3.00003H13C15.0843 3.11502 17.053 3.99479 18.5291 5.47089C20.0052 6.94699 20.885 8.91568 21 11V11.5Z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
    </svg>
    <span class="chatbot-badge" id="chatbot-badge" style="display: none;">1</span>
</button>

<!-- Chat Widget -->
<div id="chatbot-widget" class="chatbot-widget" style="display: none;">
    <!-- Chat Header -->
    <div class="chatbot-header">
        <div style="display: flex; align-items: center; gap: 0.75rem;">
            <div style="width: 40px; height: 40px; background: rgba(255,255,255,0.2); border-radius: 50%; display: flex; align-items: center; justify-content: center; color: white; font-size: 1.2rem;">
                🤖
            </div>
            <div style="flex: 1;">
                <h3 style="margin: 0; color: white; font-size: 1.1rem; font-weight: 600;">LGU AI Assistant</h3>
                <small style="color: rgba(255,255,255,0.9); font-size: 0.85rem;">Ready to help with your questions</small>
            </div>
            <div style="width: 8px; height: 8px; background: #4ade80; border-radius: 50%; animation: pulse 2s infinite;"></div>
        </div>
        <button type="button" id="chatbot-close" class="chatbot-close" aria-label="Close chat">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                <path d="M18 6L6 18M6 6L18 18" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
            </svg>
        </button>
    </div>

    <!-- Chat Messages Area -->
    <div class="chatbot-messages" id="chatbot-messages">
        <!-- Welcome Message -->
        <div class="message bot-message">
            <div style="width: 32px; height: 32px; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); border-radius: 50%; display: flex; align-items: center; justify-content: center; color: white; font-size: 0.9rem; flex-shrink: 0;">
                🤖
            </div>
            <div style="flex: 1;">
                <div style="background: white; padding: 0.85rem 1rem; border-radius: 12px; box-shadow: 0 2px 8px rgba(0,0,0,0.08); max-width: 85%;">
                    <p style="margin: 0; color: #1b1b1f; line-height: 1.6;">
                        Hello <?= htmlspecialchars($userName); ?>! 👋 I'm your AI assistant. I can help you with:
                    </p>
                    <ul style="margin: 0.75rem 0 0 1.5rem; padding: 0; color: #4b5563; line-height: 1.8;">
                        <li>Finding available facilities</li>
                        <li>Understanding booking policies</li>
                        <li>Checking reservation status</li>
                        <li>Answering FAQs</li>
                        <li>Guiding you through the booking process</li>
                    </ul>
                    <p style="margin: 0.75rem 0 0; color: #6b7280; font-size: 0.9rem; font-style: italic;">
                        <strong>Note:</strong> AI model integration is in progress. For now, I can provide basic responses.
                    </p>
                </div>
                <small style="display: block; margin-top: 0.5rem; color: #8b95b5; font-size: 0.75rem;">Just now</small>
            </div>
        </div>
    </div>

    <!-- Chat Input Area -->
    <div class="chatbot-input-area">
        <form id="chatbot-form" style="display: flex; gap: 0.75rem; align-items: flex-end;">
            <div style="flex: 1; position: relative;">
                <textarea 
                    id="chatbot-input" 
                    placeholder="Type your message here..." 
                    rows="1"
                    style="width: 100%; padding: 0.75rem 1rem; border: 2px solid #e0e6ed; border-radius: 24px; font-size: 0.95rem; font-family: inherit; resize: none; min-height: 44px; max-height: 120px; transition: border-color 0.2s ease;"
                ></textarea>
            </div>
            <button 
                type="submit" 
                class="btn-primary" 
                id="chatbot-send-btn"
                style="padding: 0.75rem 1.5rem; border-radius: 24px; min-width: 100px; display: flex; align-items: center; justify-content: center; gap: 0.5rem; font-weight: 600;"
            >
                <span>Send</span>
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                    <path d="M22 2L11 13M22 2L15 22L11 13M22 2L2 9L11 13" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                </svg>
            </button>
        </form>
        <div style="margin-top: 0.75rem; display: flex; gap: 0.5rem; flex-wrap: wrap;">
            <button type="button" class="quick-action-btn" data-action="available-facilities" style="padding: 0.4rem 0.75rem; background: #f1f5f9; border: 1px solid #e0e6ed; border-radius: 16px; font-size: 0.85rem; color: #475569; cursor: pointer; transition: all 0.2s ease;">
                📋 Available Facilities
            </button>
            <button type="button" class="quick-action-btn" data-action="booking-policy" style="padding: 0.4rem 0.75rem; background: #f1f5f9; border: 1px solid #e0e6ed; border-radius: 16px; font-size: 0.85rem; color: #475569; cursor: pointer; transition: all 0.2s ease;">
                📖 Booking Policy
            </button>
            <button type="button" class="quick-action-btn" data-action="my-reservations" style="padding: 0.4rem 0.75rem; background: #f1f5f9; border: 1px solid #e0e6ed; border-radius: 16px; font-size: 0.85rem; color: #475569; cursor: pointer; transition: all 0.2s ease;">
                📅 My Reservations
            </button>
            <button type="button" class="quick-action-btn" data-action="help" style="padding: 0.4rem 0.75rem; background: #f1f5f9; border: 1px solid #e0e6ed; border-radius: 16px; font-size: 0.85rem; color: #475569; cursor: pointer; transition: all 0.2s ease;">
                ❓ Help
            </button>
        </div>
    </div>
</div>

<style>
/* Floating Chat Button */
.chatbot-toggle {
    position: fixed;
    bottom: 24px;
    right: 24px;
    width: 60px;
    height: 60px;
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    border: none;
    border-radius: 50%;
    color: white;
    cursor: pointer;
    box-shadow: 0 4px 20px rgba(102, 126, 234, 0.4);
    display: flex;
    align-items: center;
    justify-content: center;
    z-index: 9998;
    transition: all 0.3s ease;
}

.chatbot-toggle:hover {
    transform: scale(1.1);
    box-shadow: 0 6px 25px rgba(102, 126, 234, 0.6);
}

.chatbot-toggle:active {
    transform: scale(0.95);
}

.chatbot-badge {
    position: absolute;
    top: -4px;
    right: -4px;
    background: #ef4444;
    color: white;
    border-radius: 50%;
    width: 24px;
    height: 24px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 0.75rem;
    font-weight: 600;
    border: 2px solid white;
}

/* Chat Widget */
.chatbot-widget {
    position: fixed;
    bottom: 100px;
    right: 24px;
    width: 380px;
    max-width: calc(100vw - 48px);
    height: 600px;
    max-height: calc(100vh - 140px);
    background: white;
    border-radius: 16px;
    box-shadow: 0 8px 32px rgba(0, 0, 0, 0.2);
    display: flex;
    flex-direction: column;
    z-index: 9999;
    overflow: hidden;
}

.chatbot-header {
    padding: 1.25rem 1.5rem;
    border-bottom: 1px solid #e0e6ed;
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    border-radius: 16px 16px 0 0;
    position: relative;
}

.chatbot-close {
    position: absolute;
    top: 1rem;
    right: 1rem;
    background: rgba(255, 255, 255, 0.2);
    border: none;
    border-radius: 50%;
    width: 32px;
    height: 32px;
    color: white;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    transition: all 0.2s ease;
}

.chatbot-close:hover {
    background: rgba(255, 255, 255, 0.3);
    transform: rotate(90deg);
}

.chatbot-messages {
    flex: 1;
    overflow-y: auto;
    padding: 1.5rem;
    display: flex;
    flex-direction: column;
    gap: 1rem;
    background: #f8f9fa;
}

.chatbot-messages::-webkit-scrollbar {
    width: 6px;
}

.chatbot-messages::-webkit-scrollbar-track {
    background: #f1f5f9;
    border-radius: 3px;
}

.chatbot-messages::-webkit-scrollbar-thumb {
    background: #cbd5e1;
    border-radius: 3px;
}

.chatbot-messages::-webkit-scrollbar-thumb:hover {
    background: #94a3b8;
}

.chatbot-input-area {
    padding: 1.25rem 1.5rem;
    border-top: 1px solid #e0e6ed;
    background: white;
    border-radius: 0 0 16px 16px;
}

.quick-action-btn:hover {
    background: #e2e8f0 !important;
    border-color: #cbd5e1 !important;
    transform: translateY(-1px);
}

.message {
    display: flex;
    gap: 0.75rem;
    align-items: flex-start;
    animation: fadeIn 0.3s ease;
}

@keyframes fadeIn {
    from {
        opacity: 0;
        transform: translateY(10px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}

@keyframes pulse {
    0%, 100% { opacity: 1; }
    50% { opacity: 0.5; }
}

.user-message {
    flex-direction: row-reverse;
}

.user-message > div:first-child {
    order: 2;
}

.user-message > div:last-child {
    order: 1;
}

.user-message .message-content {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%) !important;
    color: white !important;
    margin-left: auto;
}

#chatbot-input:focus {
    outline: none;
    border-color: #667eea;
    box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
}

/* Mobile Responsive */
@media (max-width: 768px) {
    .chatbot-widget {
        width: calc(100vw - 32px);
        right: 16px;
        bottom: 90px;
        height: calc(100vh - 120px);
        max-height: calc(100vh - 120px);
    }
    
    .chatbot-toggle {
        bottom: 20px;
        right: 20px;
        width: 56px;
        height: 56px;
    }
}

/* Typing Animation */
@keyframes typing {
    0%, 60%, 100% { transform: translateY(0); opacity: 0.7; }
    30% { transform: translateY(-10px); opacity: 1; }
}
</style>

<script>
(function() {
    const toggle = document.getElementById('chatbot-toggle');
    const widget = document.getElementById('chatbot-widget');
    const closeBtn = document.getElementById('chatbot-close');
    const form = document.getElementById('chatbot-form');
    const input = document.getElementById('chatbot-input');
    const messagesContainer = document.getElementById('chatbot-messages');
    const sendBtn = document.getElementById('chatbot-send-btn');
    
    let isOpen = false;
    
    // Toggle widget
    toggle.addEventListener('click', function() {
        if (!isOpen) {
            widget.style.display = 'flex';
            isOpen = true;
            input.focus();
        } else {
            widget.style.display = 'none';
            isOpen = false;
        }
    });
    
    // Close widget
    closeBtn.addEventListener('click', function() {
        widget.style.display = 'none';
        isOpen = false;
    });
    
    // Auto-resize textarea
    input.addEventListener('input', function() {
        this.style.height = 'auto';
        this.style.height = Math.min(this.scrollHeight, 120) + 'px';
    });
    
    // Quick action buttons
    document.querySelectorAll('.quick-action-btn').forEach(btn => {
        btn.addEventListener('click', function() {
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
    
    // Form submission
    form.addEventListener('submit', function(e) {
        e.preventDefault();
        
        const message = input.value.trim();
        if (!message) return;
        
        // Clear input
        input.value = '';
        input.style.height = 'auto';
        
        // Add user message
        addMessage(message, 'user');
        
        // Show typing indicator
        const typingId = showTypingIndicator();
        
        // Simulate AI response (replace with actual API call later)
        setTimeout(() => {
            removeTypingIndicator(typingId);
            const response = getMockResponse(message);
            addMessage(response, 'bot');
        }, 1000 + Math.random() * 1000);
    });
    
    function addMessage(text, type) {
        const messageDiv = document.createElement('div');
        messageDiv.className = `message ${type}-message`;
        messageDiv.style.cssText = 'display: flex; gap: 0.75rem; align-items: flex-start;';
        
        if (type === 'user') {
            messageDiv.style.flexDirection = 'row-reverse';
            messageDiv.innerHTML = `
                <div style="flex: 1; display: flex; flex-direction: column; align-items: flex-end;">
                    <div class="message-content" style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; padding: 0.85rem 1rem; border-radius: 12px; box-shadow: 0 2px 8px rgba(0,0,0,0.08); max-width: 85%;">
                        <p style="margin: 0; line-height: 1.6; white-space: pre-wrap;">${escapeHtml(text)}</p>
                    </div>
                    <small style="display: block; margin-top: 0.5rem; color: #8b95b5; font-size: 0.75rem;">${new Date().toLocaleTimeString([], {hour: '2-digit', minute:'2-digit'})}</small>
                </div>
            `;
        } else {
            messageDiv.innerHTML = `
                <div style="width: 32px; height: 32px; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); border-radius: 50%; display: flex; align-items: center; justify-content: center; color: white; font-size: 0.9rem; flex-shrink: 0;">
                    🤖
                </div>
                <div style="flex: 1;">
                    <div class="message-content" style="background: white; padding: 0.85rem 1rem; border-radius: 12px; box-shadow: 0 2px 8px rgba(0,0,0,0.08); max-width: 85%;">
                        <p style="margin: 0; color: #1b1b1f; line-height: 1.6; white-space: pre-wrap;">${escapeHtml(text)}</p>
                    </div>
                    <small style="display: block; margin-top: 0.5rem; color: #8b95b5; font-size: 0.75rem;">${new Date().toLocaleTimeString([], {hour: '2-digit', minute:'2-digit'})}</small>
                </div>
            `;
        }
        
        messagesContainer.appendChild(messageDiv);
        scrollToBottom();
    }
    
    function showTypingIndicator() {
        const typingDiv = document.createElement('div');
        typingDiv.className = 'message bot-message typing-indicator';
        typingDiv.id = 'typing-' + Date.now();
        typingDiv.style.cssText = 'display: flex; gap: 0.75rem; align-items: flex-start;';
        typingDiv.innerHTML = `
            <div style="width: 32px; height: 32px; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); border-radius: 50%; display: flex; align-items: center; justify-content: center; color: white; font-size: 0.9rem; flex-shrink: 0;">
                🤖
            </div>
            <div style="flex: 1;">
                <div style="background: white; padding: 0.85rem 1rem; border-radius: 12px; box-shadow: 0 2px 8px rgba(0,0,0,0.08); max-width: 85px;">
                    <div style="display: flex; gap: 4px;">
                        <span style="width: 8px; height: 8px; background: #cbd5e1; border-radius: 50%; animation: typing 1.4s infinite;"></span>
                        <span style="width: 8px; height: 8px; background: #cbd5e1; border-radius: 50%; animation: typing 1.4s infinite 0.2s;"></span>
                        <span style="width: 8px; height: 8px; background: #cbd5e1; border-radius: 50%; animation: typing 1.4s infinite 0.4s;"></span>
                    </div>
                </div>
            </div>
        `;
        messagesContainer.appendChild(typingDiv);
        scrollToBottom();
        return typingDiv.id;
    }
    
    function removeTypingIndicator(id) {
        const typing = document.getElementById(id);
        if (typing) typing.remove();
    }
    
    function scrollToBottom() {
        messagesContainer.scrollTop = messagesContainer.scrollHeight;
    }
    
    function escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }
    
    function getMockResponse(message) {
        const lowerMessage = message.toLowerCase();
        
        // Mock responses (replace with actual AI API call later)
        if (lowerMessage.includes('facility') || lowerMessage.includes('available')) {
            return `I can help you find available facilities! Here are some options:\n\n` +
                   `• Community Convention Hall\n` +
                   `• Municipal Sports Complex\n` +
                   `• People's Park Amphitheater\n\n` +
                   `You can browse all facilities and check their availability on the "Book a Facility" page. ` +
                   `All facilities are provided free of charge for Barangay Culiat residents.\n\n` +
                   `Would you like me to help you check availability for a specific date?`;
        }
        
        if (lowerMessage.includes('policy') || lowerMessage.includes('rule') || lowerMessage.includes('booking')) {
            return `Here are the key booking policies:\n\n` +
                   `📋 **Reservation Limits:**\n` +
                   `• Maximum 3 active reservations (pending + approved) within 30 days\n` +
                   `• Bookings allowed up to 60 days in advance\n` +
                   `• Maximum 1 booking per user per day\n\n` +
                   `📅 **Rescheduling:**\n` +
                   `• Allowed up to 3 days before the event\n` +
                   `• Only one reschedule per reservation\n` +
                   `• Approved reservations require re-approval after rescheduling\n\n` +
                   `💰 **Cost:**\n` +
                   `• All facilities are completely free for residents\n\n` +
                   `Need more details about any specific policy?`;
        }
        
        if (lowerMessage.includes('reservation') || lowerMessage.includes('my booking')) {
            return `To view your reservations:\n\n` +
                   `1. Go to "My Reservations" in the sidebar\n` +
                   `2. You'll see all your bookings with their current status\n` +
                   `3. You can reschedule approved reservations (if allowed)\n\n` +
                   `Your reservations can have these statuses:\n` +
                   `• **Pending** - Waiting for admin approval\n` +
                   `• **Approved** - Confirmed and ready\n` +
                   `• **Denied** - Request was declined\n` +
                   `• **Cancelled** - Reservation was cancelled\n\n` +
                   `Would you like help with a specific reservation?`;
        }
        
        if (lowerMessage.includes('help') || lowerMessage.includes('how')) {
            return `I'm here to help! Here's what I can assist you with:\n\n` +
                   `✅ Finding and booking facilities\n` +
                   `✅ Understanding booking policies\n` +
                   `✅ Checking reservation status\n` +
                   `✅ Rescheduling reservations\n` +
                   `✅ Answering FAQs\n\n` +
                   `**How to book a facility:**\n` +
                   `1. Click "Book a Facility" in the sidebar\n` +
                   `2. Select a facility, date, and time slot\n` +
                   `3. Fill in the purpose and submit\n` +
                   `4. Wait for admin approval\n\n` +
                   `What would you like to know more about?`;
        }
        
        // Default response
        return `Thank you for your message! I understand you're asking about: "${message}"\n\n` +
               `Currently, I'm running in demo mode. Once the AI model is integrated, I'll be able to provide more detailed and personalized responses.\n\n` +
               `For now, I can help you with:\n` +
               `• Finding available facilities\n` +
               `• Understanding booking policies\n` +
               `• Checking your reservations\n` +
               `• General FAQs\n\n` +
               `Try asking about facilities, booking policies, or your reservations!`;
    }
})();
</script>

