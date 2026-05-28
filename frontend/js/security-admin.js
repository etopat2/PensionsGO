// 
// security-admin.js
// Security module for admin dashboard with inactivity detection
// 
class AdminSecurity {
    constructor() {
        this.inactivityTimeout = 10 * 60 * 1000; // 10 minutes
        this.warningTimeout = 1 * 60 * 1000; // 1 minute warning
        this.reauthTimeout = 30 * 1000; // 30 seconds to re-authenticate
        this.lastActivity = Date.now();
        this.isLocked = false;
        this.securityManager = null;
        
        this.init();
    }

    async init() {
        // Initialize basic security features - handle missing security.js
        try {
            // Always use the shared security controls in main.js.
            // This avoids duplicate listeners and legacy dev-tools detectors.
            this.setupFallbackSecurity();
        } catch (error) {
            console.warn('SecurityManager initialization failed, using fallback:', error);
            this.setupFallbackSecurity();
        }

        await this.setupInactivityMonitoring();
        this.setupActivityTracking();
    }

    // Fallback security setup
    setupFallbackSecurity() {
        // The shared client security controls in main.js already enforce
        // settings-based restrictions for context menu, clipboard, drag,
        // text selection, and developer-tools shortcuts.
        // Do not attach the old fallback listeners here because they use a
        // noisy console trap that causes false positives inside the admin UI.
    }

    // Basic context menu prevention
    preventContextMenu() {
        document.addEventListener('contextmenu', (e) => {
            e.preventDefault();
            return false;
        });
    }

    // Basic dev tools prevention
    preventDevTools() {
        // Deprecated. Developer-tools protection is handled centrally in
        // main.js through settings-aware keyboard shortcut blocking.
    }

    // ... rest of your existing security-admin.js code remains the same ...
    // Setup inactivity monitoring
    async setupInactivityMonitoring() {
        // Check inactivity periodically
        setInterval(() => {
            this.checkInactivity();
        }, 30000); // Check every 30 seconds

        // Initial check
        setTimeout(() => {
            this.checkInactivity();
        }, 5000);
    }

    // Setup activity tracking
    setupActivityTracking() {
        const activityEvents = [
            'mousemove', 'mousedown', 'click', 'scroll',
            'keydown', 'keyup', 'keypress',
            'touchstart', 'touchmove', 'touchend',
            'focus', 'blur', 'input', 'change'
        ];

        activityEvents.forEach(event => {
            document.addEventListener(event, () => {
                this.updateLastActivity();
                
                // If locked and user is active, show re-auth modal immediately
                if (this.isLocked) {
                    this.showReauthModal();
                }
            }, { passive: true });
        });

        // Also track visibility changes
        document.addEventListener('visibilitychange', () => {
            if (!document.hidden) {
                this.updateLastActivity();
                this.checkInactivity();
            }
        });

        // Track page focus
        window.addEventListener('focus', () => {
            this.updateLastActivity();
            this.checkInactivity();
        });
    }

    // Update last activity timestamp
    updateLastActivity() {
        this.lastActivity = Date.now();
        
        // Hide any warning if user becomes active
        if (this.inactivityWarning) {
            this.hideInactivityWarning();
        }
    }

    // Check for inactivity
    checkInactivity() {
        if (this.isLocked) return;

        const inactiveTime = Date.now() - this.lastActivity;
        
        if (inactiveTime >= this.inactivityTimeout) {
            // Time to lock the dashboard
            this.lockAdminDashboard();
        } else if (inactiveTime >= (this.inactivityTimeout - this.warningTimeout)) {
            // Show warning before locking
            this.showInactivityWarning(this.inactivityTimeout - inactiveTime);
        }
    }

    // Show inactivity warning
    showInactivityWarning(timeRemaining) {
        if (this.inactivityWarning) return;

        const seconds = Math.ceil(timeRemaining / 1000);
        const minutes = Math.ceil(seconds / 60);

        this.inactivityWarning = document.createElement('div');
        this.inactivityWarning.className = 'inactivity-warning';
        this.inactivityWarning.innerHTML = `
            <div class="warning-content">
                <div class="warning-icon">⏰</div>
                <h3>Session About to Expire</h3>
                <p>Your admin session will expire due to inactivity in ${minutes} minute${minutes !== 1 ? 's' : ''}.</p>
                <p>Please perform any action to continue your session.</p>
                <div class="warning-actions">
                    <button id="continueSession" class="warning-btn primary">Continue Session</button>
                    <button id="lockNow" class="warning-btn secondary">Lock Now</button>
                </div>
            </div>
        `;

        document.body.appendChild(this.inactivityWarning);

        // Add event listeners
        document.getElementById('continueSession').addEventListener('click', () => {
            this.updateLastActivity();
            this.hideInactivityWarning();
        });

        document.getElementById('lockNow').addEventListener('click', () => {
            this.lockAdminDashboard();
            this.hideInactivityWarning();
        });

        // Auto-hide if user becomes active
        setTimeout(() => {
            if (this.inactivityWarning && document.body.contains(this.inactivityWarning)) {
                this.hideInactivityWarning();
            }
        }, 10000);
    }

    // Hide inactivity warning
    hideInactivityWarning() {
        if (this.inactivityWarning && document.body.contains(this.inactivityWarning)) {
            this.inactivityWarning.remove();
        }
        this.inactivityWarning = null;
    }

    // Lock admin dashboard
    lockAdminDashboard() {
        this.isLocked = true;
        this.showReauthModal();
        this.logSecurityEvent('admin_dashboard_locked', 'Admin dashboard locked due to inactivity');
    }

    // Show re-authentication modal
    showReauthModal() {
        if (this.reauthModal) return;

        this.reauthModal = document.createElement('div');
        this.reauthModal.className = 'reauth-overlay';
        this.reauthModal.innerHTML = `
            <div class="reauth-modal">
                <div class="reauth-header">
                    <div class="reauth-icon">🔒</div>
                    <h2>Admin Re-authentication Required</h2>
                </div>
                <div class="reauth-body">
                    <p>Your admin session has been locked due to inactivity.</p>
                    <p>Please verify your admin credentials to continue.</p>
                    
                    <form id="reauthForm" class="reauth-form">
                        <div class="form-group">
                            <label for="reauthPassword">Admin Password</label>
                            <input type="password" id="reauthPassword" required 
                                   placeholder="Enter your admin password" class="reauth-input">
                        </div>
                        <div class="form-actions">
                            <button type="submit" class="reauth-btn primary" id="reauthSubmit">
                                <span class="btn-text">Verify & Continue</span>
                                <span class="btn-spinner" style="display: none;">🔄</span>
                            </button>
                            <button type="button" class="reauth-btn secondary" id="reauthLogout">
                                Logout Instead
                            </button>
                        </div>
                    </form>
                    
                    <div class="reauth-timer">
                        <p>Auto logout in: <span id="reauthTimer">30</span> seconds</p>
                    </div>
                </div>
            </div>
        `;

        document.body.appendChild(this.reauthModal);

        // Start countdown timer
        this.startReauthCountdown();

        // Add event listeners
        document.getElementById('reauthForm').addEventListener('submit', (e) => {
            e.preventDefault();
            this.handleReauthSubmit();
        });

        document.getElementById('reauthLogout').addEventListener('click', () => {
            this.terminateSession();
        });

        // Prevent closing the modal
        this.reauthModal.addEventListener('click', (e) => {
            if (e.target === this.reauthModal) {
                e.preventDefault();
                e.stopPropagation();
            }
        });
    }

    // Start re-authentication countdown
    startReauthCountdown() {
        let timeLeft = this.reauthTimeout / 1000;
        const timerElement = document.getElementById('reauthTimer');

        this.countdownInterval = setInterval(() => {
            timeLeft--;
            if (timerElement) {
                timerElement.textContent = timeLeft;
            }

            if (timeLeft <= 0) {
                this.terminateSession();
            }
        }, 1000);
    }

    // Handle re-authentication submit
    async handleReauthSubmit() {
        const passwordInput = document.getElementById('reauthPassword');
        const submitBtn = document.getElementById('reauthSubmit');
        const btnText = submitBtn.querySelector('.btn-text');
        const btnSpinner = submitBtn.querySelector('.btn-spinner');

        const password = passwordInput.value.trim();

        if (!password) {
            this.showReauthError('Please enter your admin password');
            return;
        }

        // Show loading state
        btnText.style.display = 'none';
        btnSpinner.style.display = 'inline-block';
        submitBtn.disabled = true;

        try {
            const isValid = await this.verifyAdminPassword(password);
            
            if (isValid) {
                await this.unlockAdminDashboard();
            } else {
                this.showReauthError('Invalid admin password. Please try again.');
                passwordInput.value = '';
                passwordInput.focus();
            }
        } catch (error) {
            this.showReauthError('Verification failed. Please try again.');
            console.error('Re-authentication error:', error);
        } finally {
            // Reset button state
            btnText.style.display = 'inline-block';
            btnSpinner.style.display = 'none';
            submitBtn.disabled = false;
        }
    }

    // Verify admin password
    async verifyAdminPassword(password) {
        try {
            const response = await fetch('../backend/api/verify_admin_password.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                credentials: 'include',
                body: JSON.stringify({ password: password })
            });

            const data = await response.json();
            return data.success && data.valid;
        } catch (error) {
            console.error('Password verification error:', error);
            return false;
        }
    }

    // Show re-authentication error
    showReauthError(message) {
        // Remove existing error
        const existingError = this.reauthModal.querySelector('.reauth-error');
        if (existingError) {
            existingError.remove();
        }

        const errorElement = document.createElement('div');
        errorElement.className = 'reauth-error';
        errorElement.innerHTML = `
            <div class="error-icon">⚠️</div>
            <p>${message}</p>
        `;

        const reauthBody = this.reauthModal.querySelector('.reauth-body');
        reauthBody.insertBefore(errorElement, reauthBody.querySelector('.reauth-timer'));

        // Auto-remove error after 5 seconds
        setTimeout(() => {
            if (errorElement.parentNode) {
                errorElement.remove();
            }
        }, 5000);
    }

    // Unlock admin dashboard
    async unlockAdminDashboard() {
        this.isLocked = false;
        this.updateLastActivity();
        
        // Clear countdown
        if (this.countdownInterval) {
            clearInterval(this.countdownInterval);
            this.countdownInterval = null;
        }

        // Remove modal
        if (this.reauthModal && document.body.contains(this.reauthModal)) {
            this.reauthModal.remove();
            this.reauthModal = null;
        }

        this.logSecurityEvent('admin_dashboard_unlocked', 'Admin dashboard unlocked after re-authentication');
        
        // Show success message
        this.showSecurityWarning('Admin dashboard unlocked successfully!', 'success');
    }

    // Terminate session and redirect to login
    async terminateSession() {
        this.logSecurityEvent('admin_session_terminated', 'Admin session terminated due to failed re-authentication');
        
        try {
            const csrfToken = window.fetchCsrfToken ? await window.fetchCsrfToken() : '';
            await fetch('../backend/api/logout.php', {
                method: 'POST',
                credentials: 'include',
                headers: window.withDeviceTokenHeaders
                    ? window.withDeviceTokenHeaders({ 'X-CSRF-Token': csrfToken })
                    : { 'X-CSRF-Token': csrfToken }
            });
        } catch (error) {
            console.error('Logout error:', error);
        } finally {
            // Clear all local storage
            sessionStorage.clear();
            localStorage.removeItem('loggedInUser');
            localStorage.removeItem('userRole');
            
            // Redirect to login
            window.location.href = 'login.html?reason=admin_timeout';
        }
    }

    // Log security events
    async logSecurityEvent(eventType, description) {
        try {
            // If security manager exists, use it
            if (this.securityManager && this.securityManager.logSecurityEvent) {
                await this.securityManager.logSecurityEvent(eventType, description);
            } else {
                // Fallback logging
                console.log(`Security Event: ${eventType} - ${description}`);
                await fetch('../backend/api/log_security_event.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify({ eventType, description })
                }).catch(err => console.error('Security logging failed:', err));
            }
        } catch (error) {
            console.error('Failed to log security event:', error);
        }
    }

    // Show security warning (override from security manager)
    showSecurityWarning(message, type = 'error') {
        const warning = document.createElement('div');
        warning.style.cssText = `
            position: fixed;
            top: 20px;
            right: 20px;
            background: ${type === 'success' ? 'var(--success-color, #28a745)' : 'var(--error-color, #dc3545)'};
            color: white;
            padding: 1rem 1.5rem;
            border-radius: 6px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.3);
            z-index: 10000;
            max-width: 300px;
            font-size: 0.9rem;
        `;
        warning.textContent = message;
        document.body.appendChild(warning);

        setTimeout(() => {
            if (document.body.contains(warning)) {
                warning.remove();
            }
        }, 5000);
    }

    // Set custom timeout durations
    setTimeouts({ inactivity, warning, reauth }) {
        if (inactivity) this.inactivityTimeout = inactivity;
        if (warning) this.warningTimeout = warning;
        if (reauth) this.reauthTimeout = reauth;
    }

    // Manually lock the dashboard
    manualLock() {
        this.lockAdminDashboard();
    }

    // Clean up
    destroy() {
        if (this.countdownInterval) {
            clearInterval(this.countdownInterval);
        }
        if (this.securityManager) {
            this.securityManager.destroy();
        }
        this.hideInactivityWarning();
        
        if (this.reauthModal && document.body.contains(this.reauthModal)) {
            this.reauthModal.remove();
        }
    }
}

// Initialize admin security when DOM is loaded
document.addEventListener('DOMContentLoaded', () => {
    window.adminSecurity = new AdminSecurity();
});

// Export for module usage
if (typeof module !== 'undefined' && module.exports) {
    module.exports = AdminSecurity;
}
