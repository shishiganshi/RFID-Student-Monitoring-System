document.addEventListener('DOMContentLoaded', function() {
    // Only the real-time clock is needed now.
    // All statistics and logs are loaded directly by PHP.
    initializeClock();
});

/**
 * Updates the date and time display on the dashboard every second.
 */
function initializeClock() {
    const dateTimeElement = document.getElementById('currentDateTime');
    
    if (!dateTimeElement) {
        console.error("Dashboard Error: The element with ID 'currentDateTime' was not found.");
        return;
    }

    const updateTime = () => {
        const now = new Date();
        const options = { 
            weekday: 'long', 
            year: 'numeric', 
            month: 'long', 
            day: 'numeric',
            hour: '2-digit',
            minute: '2-digit',
            second: '2-digit'
        };
        dateTimeElement.textContent = now.toLocaleDateString('en-US', options);
    };

    updateTime(); // Run once immediately
    setInterval(updateTime, 1000); // Update every second
}