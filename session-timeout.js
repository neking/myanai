/**
 * POS Session Timeout Manager
 * Auto-logout inactive sessions after 30 minutes
 * Inactivity = no mouse/keyboard/touch events
 */

(function() {
    const CONFIG = {
        TIMEOUT_MINUTES: 30,
        WARNING_MINUTES: 5,  // Show warning this many minutes before timeout
        CHECK_INTERVAL_SECONDS: 30,
    };

    let lastActivityTime = Date.now();
    let timeoutTimer = null;
    let warningShown = false;

    /**
     * Track user activity
     */
    function recordActivity() {
        lastActivityTime = Date.now();
        warningShown = false;  // Reset warning when activity detected
    }

    /**
     * Check for session timeout
     */
    function checkSessionTimeout() {
        const elapsed = (Date.now() - lastActivityTime) / 1000;  // seconds
        const timeoutSeconds = CONFIG.TIMEOUT_MINUTES * 60;
        const warningSeconds = CONFIG.WARNING_MINUTES * 60;

        // Show warning if approaching timeout
        if (elapsed > (timeoutSeconds - warningSeconds) && elapsed < timeoutSeconds && !warningShown) {
            showTimeoutWarning();
            warningShown = true;
            return;
        }

        // Logout if timeout reached
        if (elapsed > timeoutSeconds) {
            performSessionLogout();
        }
    }

    /**
     * Show timeout warning modal
     */
    function showTimeoutWarning() {
        // Check if warning already exists
        if (document.getElementById('session-timeout-warning')) {
            return;
        }

        const modal = document.createElement('div');
        modal.id = 'session-timeout-warning';
        modal.style.cssText = `
            position: fixed;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            z-index: 99999;
            background: white;
            border-radius: 12px;
            box-shadow: 0 10px 40px rgba(0,0,0,.3);
            padding: 2rem;
            text-align: center;
            max-width: 400px;
        `;

        modal.innerHTML = `
            <div style="margin-bottom: 1rem;">
                <p style="font-size: 1.2rem; font-weight: 600; color: #d97706; margin: 0;">
                    ⏱️ Session Timeout Warning
                </p>
            </div>
            <div style="margin-bottom: 1.5rem; color: #666; font-size: 0.95rem;">
                <p style="margin: 0.5rem 0;">
                    Your session will expire in <strong id="warning-countdown">5 minutes</strong> due to inactivity.
                </p>
                <p style="margin: 0.5rem 0; font-size: 0.85rem; color: #999;">
                    Move your mouse or tap the screen to stay connected.
                </p>
            </div>
            <div style="display: flex; gap: 1rem;">
                <button id="logout-btn" style="
                    flex: 1;
                    padding: 0.7rem 1.5rem;
                    background: #dc2626;
                    color: white;
                    border: none;
                    border-radius: 8px;
                    cursor: pointer;
                    font-weight: 600;
                ">
                    Logout Now
                </button>
                <button id="stay-btn" style="
                    flex: 1;
                    padding: 0.7rem 1.5rem;
                    background: #10b981;
                    color: white;
                    border: none;
                    border-radius: 8px;
                    cursor: pointer;
                    font-weight: 600;
                ">
                    Stay Connected
                </button>
            </div>
        `;

        document.body.appendChild(modal);

        // Event handlers
        document.getElementById('logout-btn').onclick = performSessionLogout;
        document.getElementById('stay-btn').onclick = function() {
            recordActivity();
            modal.remove();
        };

        // Update countdown
        const startTime = Date.now();
        const countdownInterval = setInterval(() => {
            const elapsed = (Date.now() - startTime) / 1000;
            const remaining = Math.max(0, CONFIG.WARNING_MINUTES * 60 - elapsed);
            const minutes = Math.floor(remaining / 60);
            const seconds = Math.floor(remaining % 60);
            
            const countdown = document.getElementById('warning-countdown');
            if (countdown) {
                countdown.textContent = `${minutes}m ${seconds}s`;
            }

            if (remaining <= 0) {
                clearInterval(countdownInterval);
                performSessionLogout();
            }
        }, 1000);
    }

    /**
     * Perform session logout
     */
    function performSessionLogout() {
        // Remove the warning if it exists
        const warning = document.getElementById('session-timeout-warning');
        if (warning) {
            warning.remove();
        }

        // Show logout message
        const message = document.createElement('div');
        message.style.cssText = `
            position: fixed;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            z-index: 99999;
            background: white;
            border-radius: 12px;
            box-shadow: 0 10px 40px rgba(0,0,0,.3);
            padding: 2rem;
            text-align: center;
        `;
        message.innerHTML = `
            <p style="font-size: 1.1rem; font-weight: 600; color: #1f2937; margin: 1rem 0;">
                Session Expired
            </p>
            <p style="color: #6b7280; font-size: 0.9rem; margin: 0 0 1.5rem 0;">
                Please login again.
            </p>
        `;
        document.body.appendChild(message);

        // Redirect to login after 2 seconds
        setTimeout(() => {
            window.location.href = window.location.origin + window.location.pathname.split('/')[0] + '/';
        }, 2000);
    }

    /**
     * Initialize session timeout tracking
     */
    function init() {
        // Track user activities
        const events = ['mousedown', 'keydown', 'touch', 'touchstart', 'scroll', 'click'];
        events.forEach(event => {
            document.addEventListener(event, recordActivity, true);  // Use capture phase
        });

        // Check for timeout periodically
        timeoutTimer = setInterval(checkSessionTimeout, CONFIG.CHECK_INTERVAL_SECONDS * 1000);

        console.log('✓ POS Session timeout tracking enabled (' + CONFIG.TIMEOUT_MINUTES + ' min inactivity)');
    }

    /**
     * Cleanup on page unload
     */
    window.addEventListener('unload', function() {
        if (timeoutTimer) {
            clearInterval(timeoutTimer);
        }
    });

    // Initialize when DOM is ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
