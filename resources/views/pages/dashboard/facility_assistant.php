<!DOCTYPE html>
<html>
<head>
<title>LGU Facility Assistant</title>

<style>
body {
    font-family: Arial, sans-serif;
    background: #f4f6f8;
}
.chatbox {
    width: 420px;
    margin: 30px auto;
    background: #fff;
    border-radius: 12px;
    box-shadow: 0 8px 20px rgba(0,0,0,.08);
    overflow: hidden;
}
.header {
    background: #1f3a5f;
    color: #fff;
    padding: 14px;
    font-weight: bold;
}
.messages {
    height: 360px;
    padding: 12px;
    overflow-y: auto;
    background: #f9fafb;
}
.bot {
    margin-bottom: 10px;
}
.bubble {
    background: #eef2f7;
    padding: 10px 12px;
    border-radius: 10px;
    font-size: 14px;
    line-height: 1.45;
}
.controls {
    padding: 10px;
    display: flex;
    gap: 8px;
    flex-wrap: wrap;
}
.controls button {
    border-radius: 20px;
    padding: 6px 12px;
    border: 1px solid #1f3a5f;
    background: #fff;
    cursor: pointer;
}
.controls button:hover {
    background: #1f3a5f;
    color: #fff;
}
.date-picker {
    padding: 8px;
    display: none;
}
.date-picker input {
    width: 100%;
    padding: 6px;
}
.booked {
    color: #b23030;
}
.available {
    color: #0d7a43;
}
</style>
</head>

<body>

<div class="chatbox">
    <div class="header">🏛️ LGU Facility Assistant</div>

    <div class="messages" id="chat">
        <div class="bot">
            <div class="bubble">
                Hello! 👋<br>
                Please select a date below to view facility availability.
            </div>
        </div>
    </div>

    <div class="controls">
        <button onclick="loadDate('today')">📅 Today</button>
        <button onclick="loadDate('tomorrow')">📅 Tomorrow</button>
        <button onclick="showPicker()">🗓 Pick a Date</button>
    </div>

    <div class="date-picker" id="picker">
        <input type="date" onchange="loadCustomDate(this)">
    </div>
</div>

<script>
const chat = document.getElementById('chat');
const API_URL = 'api/availability_api.php';
let loadingBubble = null;

function addBot(html) {
    const el = document.createElement('div');
    el.className = 'bot';
    el.innerHTML = `<div class="bubble">${html}</div>`;
    chat.appendChild(el);
    chat.scrollTop = chat.scrollHeight;
}

function showLoading(date) {
    if (loadingBubble) loadingBubble.remove();
    loadingBubble = document.createElement('div');
    loadingBubble.className = 'bot';
    loadingBubble.innerHTML = `<div class="bubble">📅 Checking availability for <b>${date}</b>...</div>`;
    chat.appendChild(loadingBubble);
    chat.scrollTop = chat.scrollHeight;
}

function loadDate(type) {
    let d = new Date();
    if (type === 'tomorrow') d.setDate(d.getDate() + 1);
    fetchAvailability(d.toISOString().slice(0,10));
}

function showPicker() {
    document.getElementById('picker').style.display = 'block';
}

function loadCustomDate(el) {
    if (el.value) fetchAvailability(el.value);
}

function fetchAvailability(date) {
    showLoading(date);

    fetch(`${API_URL}?date=${date}`)
        .then(r => r.json())
        .then(data => {
            if (loadingBubble) loadingBubble.remove();
            renderAvailability(data);
        })
        .catch(() => {
            if (loadingBubble) loadingBubble.remove();
            addBot('❌ Failed to load availability.');
        });
}

function renderAvailability(data) {
    let html = `<b>Facility Availability</b><br><small>${data.date}</small><br><br>`;

    data.facilities.forEach(f => {
        html += `<b>${f.facility_name}</b><br>`;

        if (f.status !== 'available') {
            html += `❌ ${f.status}<br><br>`;
            return;
        }

        f.timeline.forEach(t => {
            if (t.type === 'booked') {
                html += `<span class="booked">❌ ${t.range} (Booked)</span><br>`;
            }
            if (t.type === 'available') {
                html += `<span class="available">✅ ${t.range}</span><br>`;
            }
        });

        html += `<br>`;
    });

    addBot(html);
}
</script>

</body>
</html>
