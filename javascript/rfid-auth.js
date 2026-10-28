// --- DOM Element References ---
const rfidStatus = document.getElementById('rfidStatus');
const adminForm = document.getElementById('adminForm');
const connectionStatus = document.getElementById('connectionStatus');
const serverIp = document.getElementById('serverIp');
const esp32IpElement = document.getElementById('esp32Ip');
const lastScanElement = document.getElementById('lastScan');
const sessionIdElement = document.getElementById('sessionId');

// --- Configuration Constants ---
const POLL_INTERVAL = 1000; // Poll every 1 second
const MAX_RETRY_COUNT = 3;  // Show admin override after 3 failed connections
let pollingInterval = null;
let errorCount = 0;

/**
 * Main polling function to check the server for RFID login status.
 * It periodically sends a GET request to verify-rfid.php.
 */
async function pollRFIDStatus() {
    try {
        // Update UI to show it's trying to connect
        connectionStatus.textContent = 'Connecting...';
        connectionStatus.style.color = '#FFA500'; // Orange

        const response = await fetch('../php/verify-rfid.php', {
            method: 'GET',
            headers: {
                'Cache-Control': 'no-cache',
                'Pragma': 'no-cache',
                'Accept': 'application/json'
            },
            credentials: 'include' // Important for sending/receiving session cookies
        });

        if (!response.ok) {
            throw new Error(`HTTP error! Status: ${response.status}`);
        }

        const data = await response.json();
        console.log('RFID Poll Response:', data); // For debugging

        // Reset error count on successful connection
        errorCount = 0;

        // Update the network debug panel with the latest data from the server
        if (data.esp32_ip) {
            esp32IpElement.textContent = data.esp32_ip;
            connectionStatus.textContent = 'Connected';
            connectionStatus.style.color = '#4CAF50'; // Green
        } else {
            esp32IpElement.textContent = 'Not Connected';
            connectionStatus.textContent = 'Waiting for ESP32...';
            connectionStatus.style.color = '#FFA500'; // Orange
        }
        
        if (data.session_id) {
            sessionIdElement.textContent = data.session_id;
        }

        // Check if the server responded with a successful login
        if (data.success && data.is_superuser) {
            rfidStatus.textContent = 'Access granted! Redirecting...';
            rfidStatus.style.color = '#4CAF50';
            clearInterval(pollingInterval); // Stop polling
            // IMPORTANT: Make sure this path is correct for your file structure
            window.location.href = 'dashboard.php'; 
        } else {
            // Otherwise, display the waiting message from the server
            rfidStatus.textContent = data.message || 'Waiting for card scan...';
        }

    } catch (error) {
        console.error('Polling error:', error);
        connectionStatus.textContent = 'Connection Error';
        connectionStatus.style.color = '#FF0000'; // Red
        rfidStatus.textContent = 'Connection error - retrying...';

        // After several connection failures, show the admin override form
        errorCount++;
        if (errorCount >= MAX_RETRY_COUNT) {
            showAdminOverride();
        }
    }
}

/**
 * Makes the admin override form visible.
 */
function showAdminOverride() {
    if (adminForm) {
        adminForm.style.display = 'block';
    }
}

/**
 * Handles the admin login form submission.
 * @param {Event} event The form submission event.
 */
async function loginUser(event) {
    event.preventDefault(); // Prevent the form from reloading the page
    const adminKey = document.getElementById('adminKey').value;

    try {
        const response = await fetch('../php/admin-login.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify({ adminKey: adminKey })
        });

        const data = await response.json();
        if (data.success) {
            // On successful admin login, redirect to the dashboard
            // IMPORTANT: Make sure this path is correct for your file structure
            window.location.href = 'dashboard.php';
        } else {
            rfidStatus.textContent = data.message || 'Invalid admin key';
            rfidStatus.style.color = '#FF0000';
        }
    } catch (error) {
        console.error('Admin login error:', error);
        rfidStatus.textContent = 'Admin login failed due to a network error.';
    }
}

/**
 * Starts the polling process.
 */
function startPolling() {
    // Run once immediately, then start the interval
    pollRFIDStatus();
    pollingInterval = setInterval(pollRFIDStatus, POLL_INTERVAL);
}

// --- Script Execution ---

// When the page is fully loaded, start polling for the RFID status.
document.addEventListener('DOMContentLoaded', startPolling);

// Clean up the interval when the user navigates away from the page.
window.addEventListener('unload', () => {
    if (pollingInterval) {
        clearInterval(pollingInterval);
    }
});