<?php
// Path: portal/pages/guard_view.php
date_default_timezone_set('Asia/Manila');
session_start();

// --- Authentication Check ---
if (!isset($_SESSION['user_id'])) {
    header('Location: ../html/login.html');
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Live Security Monitor</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/boxicons@latest/css/boxicons.min.css" rel="stylesheet">
    <style>
        :root {
            --primary-color: #1a73e8;
            --secondary-color: #f8f9fa;
            --accent-color: #0d47a1;
            --text-color: #202124;
            --text-light: #f8f9fa;
        }

        html, body {
            height: 100%;
            margin: 0;
            padding: 0;
            background-color: var(--secondary-color);
            font-family: 'Segoe UI', Arial, sans-serif;
            overflow: hidden; 
        }

        .monitor-wrapper {
            display: flex;
            flex-direction: column;
            height: 100%;
            width: 100%;
        }

        .monitor-header {
            background: linear-gradient(90deg, var(--accent-color) 0%, var(--primary-color) 100%);
            color: var(--text-light);
            padding: 1rem 2rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
            box-shadow: 0 4px 12px rgba(0,0,0,0.1);
            z-index: 10;
        }
        .monitor-header h2 {
            font-size: 1.8rem;
            margin: 0;
            font-weight: 600;
        }
        .monitor-header #currentDateTime {
            font-size: 1.3rem;
            font-weight: 500;
            text-align: right;
        }
        
        .monitor-content {
            flex-grow: 1;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 2rem;
        }

        #live-monitoring-panel {
            background-color: #fff;
            border-radius: 16px;
            box-shadow: 0 8px 30px rgba(0,0,0,0.12);
            overflow: hidden;
            transition: all 0.3s ease;
            border: 4px solid var(--primary-color); 
            padding: 2rem;
            width: 100%;
            max-width: 1300px; 
            min-height: 450px; 
        }
        #live-monitoring-panel.is-denied { border-color: #dc3545; }
        #live-monitoring-panel.is-granted { border-color: #198754; } 
        
        #live-monitoring-panel .live-photo {
            width: 300px; 
            height: 300px; 
            object-fit: cover;
            border-radius: 16px; 
            border: 5px solid #eee;
        }
        #live-monitoring-panel .live-placeholder {
            width: 300px; 
            height: 300px; 
            border-radius: 16px; 
            background-color: #e9ecef; 
            display: flex;
            align-items: center; 
            justify-content: center;
            color: #b0b8c1;
            font-size: 10rem; 
            border: 5px solid #eee;
        }
        
        .info-column {
            display: flex;
            flex-direction: column;
            justify-content: center; 
            align-items: center; 
            height: 100%;
            text-align: center; 
        }
        .info-column h2 { /* Name */
            font-size: 2.8rem;
            font-weight: 700;
            margin-bottom: 0.5rem;
            line-height: 1.2;
        }
        .info-column p { /* Department */
            font-size: 1.5rem;
            color: #6c757d;
            margin-bottom: 1.5rem;
        }
        .info-column .status-badge { /* Status Badge */
            font-size: 2rem;
            padding: 0.75rem 1.5rem;
            font-weight: 600;
        }
        .info-column.idle-state {
            align-items: center;
        }
        .idle-icon {
            font-size: 10rem;
            color: var(--primary-color);
        }
        .idle-text {
            font-size: 2.5rem;
            font-weight: 600;
            color: var(--primary-color);
        }

        .capture-column {
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
            text-align: center;
        }
        .capture-column h3 {
            font-size: 1.25rem;
            font-weight: 600;
            color: #6c757d;
            margin-top: 1rem;
        }

        #live-panel-content.row {
            align-items: center;
            min-height: 350px; 
        }

        /* --- [NEW] Reject Button --- */
        .reject-button-container {
            display: none; /* Hidden by default */
            position: absolute;
            bottom: 2.5rem;
            left: 50%;
            transform: translateX(-50%);
            z-index: 100;
        }
        #reject-button {
            font-size: 1.5rem;
            font-weight: 600;
            padding: 0.75rem 3rem;
            border-radius: 50px;
        }
    </style>
</head>
<body>

    <div class="monitor-wrapper">
        <header class="monitor-header">
            <h2><i class='bx bxs-shield-check'></i> CMU LIVE MONITOR</h2>
            <div id="currentDateTime">Loading date and time...</div>
        </header>

        <main class="monitor-content">
            <div id="live-monitoring-panel">
                <div class="row w-100 align-items-center" id="live-panel-content">
                    </div>
            </div>
        </main>
        
        <div class="reject-button-container" id="reject-container">
            <button class="btn btn-danger btn-lg" id="reject-button">
                <i class='bx bxs-error-alt me-2'></i> REJECT
            </button>
        </div>

    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    
    <script>
        // --- Element References ---
        const livePanel = document.getElementById('live-monitoring-panel');
        const livePanelContent = document.getElementById('live-panel-content');
        const dateTimeElement = document.getElementById('currentDateTime');
        const rejectContainer = document.getElementById('reject-container');
        const rejectButton = document.getElementById('reject-button');
        
        // --- State Variables ---
        let lastEventTimestamp = 0;
        let currentPanelState = ''; 
        let currentLogId = null; // [NEW] Store the current log ID
        const POLL_LIVE_INTERVAL = 2000; 

        // --- Date/Time Function ---
        function updateDateTime() {
            const now = new Date();
            const options = { weekday: 'long', year: 'numeric', month: 'long', day: 'numeric', hour: '2-digit', minute: '2-digit', second: '2-digit' };
            if (dateTimeElement) {
                dateTimeElement.textContent = now.toLocaleDateString('en-US', options);
            }
        }

        // --- Main Polling Function ---
        async function checkLiveEvent() {
            try {
                const response = await fetch('../php/get_live_event.php?t=' + Date.now());
                if (!response.ok) return;
                
                const event = await response.json();

                // --- CASE 1: Event is ACTIVE (Scan just happened) ---
                if (event && event.timestamp) {
                    if (event.timestamp > lastEventTimestamp) {
                        lastEventTimestamp = event.timestamp;
                        currentLogId = event.log_id; // [NEW] Save the log ID
                        renderScanState(event); 
                        currentPanelState = 'scan';
                    } 
                    else if (currentPanelState === 'scan' && event.captured_image_path) {
                        const imgSpinner = document.getElementById('capture-spinner');
                        if (imgSpinner) {
                            renderScanState(event); // Re-render to show image
                        }
                    }
                } 
                // --- CASE 2: Event is INACTIVE (null) ---
                else {
                    if (currentPanelState !== 'idle') {
                        renderIdleState();
                        currentPanelState = 'idle';
                        currentLogId = null; // [NEW] Clear the log ID
                    }
                }
            } catch (error) {
                console.error("Live event poll error:", error);
            }
        }

        // --- Renders the "Idle" state in the panel ---
        function renderIdleState() {
            livePanel.classList.remove('is-denied', 'is-granted');
            livePanel.style.borderColor = 'var(--primary-color)'; 
            rejectContainer.style.display = 'none'; // [NEW] Hide reject button
            
            const idleHtml = `
                <div class="col-12 text-center info-column idle-state">
                    <i class='bx bx-time-five idle-icon'></i>
                    <h1 class="idle-text mt-3">Waiting for scan...</h1>
                </div>
            `;
            livePanelContent.innerHTML = idleHtml;
        }

        // --- Renders the "Granted" or "Denied" state in the panel ---
        function renderScanState(event) {
            const student = event.student_data;
            const action = event.action;

            // --- 1. Registered Photo (Left) ---
            let photoHtml = '';
            let photoUrl = student.photo_path ? buildFullPhotoUrl(student.photo_path) : null; 
            if (photoUrl) {
                photoHtml = `<img src="${photoUrl}?t=${Date.now()}" alt="Registered Photo" class="live-photo">`;
            } else {
                let icon = (action === 'denied') ? 'bx-x-circle' : 'bx-user';
                photoHtml = `<div class="live-placeholder"><i class='bx ${icon}'></i></div>`;
            }

            // --- 2. Details (Middle) ---
            let badgeClass = 'bg-secondary';
            let badgeText = action;
            if (action === 'login') { badgeClass = 'bg-success'; badgeText = 'Logged In'; }
            else if (action === 'logout') { badgeClass = 'bg-info'; badgeText = 'Logged Out'; }
            else if (action === 'denied') { badgeClass = 'bg-danger'; badgeText = 'Access Denied'; }
            
            const detailsHtml = `
                <h2 id="live-name">${student.full_name || 'Unknown'}</h2>
                <p id="live-department">${student.department || 'N/A'}</p>
                <span class="badge ${badgeClass} status-badge" id="live-status-badge">${badgeText}</span>
            `;

            // --- 3. Captured Image (Right) ---
            let capturedHtml = '';
            if (event.captured_image_path) {
                capturedHtml = `
                    <img src="${buildFullPhotoUrl(event.captured_image_path)}?t=${Date.now()}" alt="Captured Image" class="live-photo">
                `;
            } else {
                capturedHtml = `
                    <div class="live-placeholder" id="capture-spinner">
                        <div class="spinner-border text-primary" style="width: 5rem; height: 5rem;" role="status">
                            <span class="visually-hidden">Loading...</span>
                        </div>
                    </div>
                `;
            }
            
            livePanelContent.innerHTML = `
                <div class="col-md-4 text-center info-column">
                    ${photoHtml}
                    <h3 class="mt-3">Registered Photo</h3>
                </div>
                <div class="col-md-4 text-center info-column">
                    ${detailsHtml}
                </div>
                <div class="col-md-4 text-center capture-column">
                    ${capturedHtml}
                    <h3 class="mt-3">Captured Image</h3>
                </div>
            `;
            
            // Update panel border
            livePanel.classList.toggle('is-denied', action === 'denied');
            livePanel.classList.toggle('is-granted', action !== 'denied');
            livePanel.style.borderColor = ''; 
            
            // [NEW] Show reject button only for GRANTED logs (prevents double-denial)
            if (action === 'login' || action === 'logout') {
                 rejectContainer.style.display = 'block';
            } else {
                 rejectContainer.style.display = 'none';
            }
        }
        
        // --- [NEW] Function to handle the REJECT button click ---
        async function handleReject() {
            if (!currentLogId) {
                alert("Error: No log is active to reject.");
                return;
            }

            rejectButton.disabled = true;
            rejectButton.innerHTML = "REJECTING...";
            
            try {
                const formData = new FormData();
                formData.append('log_id', currentLogId);
                
                const response = await fetch('../php/reject_log.php', {
                    method: 'POST',
                    body: formData
                });
                
                const result = await response.json();
                
                if (result.success) {
                    // Success! Manually update the panel to show "Fraudulent"
                    const statusBadge = document.getElementById('live-status-badge');
                    if(statusBadge) {
                        statusBadge.className = 'badge bg-danger status-badge';
                        statusBadge.textContent = 'REJECTED';
                    }
                    livePanel.classList.add('is-denied');
                    livePanel.classList.remove('is-granted');
                } else {
                    alert("Error: " + result.message);
                }

            } catch (error) {
                console.error('Reject Error:', error);
                alert("A network error occurred while rejecting the log.");
            }
            
            // Hide the button after click (whether success or fail)
            rejectButton.disabled = false;
            rejectButton.innerHTML = "<i class='bx bxs-error-alt me-2'></i> REJECT";
            rejectContainer.style.display = 'none';
        }

        // --- Helper Functions ---
        function buildFullPhotoUrl(path) { 
            return '../' + path; // Path relative to /pages/
        }

        // --- Start Everything ---
        document.addEventListener('DOMContentLoaded', () => {
            updateDateTime(); 
            setInterval(updateDateTime, 1000); 
            
            checkLiveEvent(); 
            setInterval(checkLiveEvent, POLL_LIVE_INTERVAL); 
            
            // [NEW] Attach click listener to reject button
            rejectButton.addEventListener('click', handleReject);
        });
    </script>
</body>
</html>