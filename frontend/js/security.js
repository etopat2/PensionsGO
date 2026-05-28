// 
// security.js
// Reusable security module for protected pages
// 
class SecurityManager {
    constructor(options = {}) {
        this.options = {
            preventContextMenu: true,
            preventTextSelection: true,
            preventDragDrop: true,
            preventDevTools: true,
            detectDevTools: false, // CHANGED: Default to false to prevent loops
            securityLevel: 'high',
            ...options
        };

        this.securityEnabled = true;
        this.devToolsCheckInterval = null;
        this.devToolsDetected = false;
        this.init();
    }

    init() {
        console.log('🔒 SecurityManager initialized');
        
        if (this.options.preventContextMenu) {
            this.preventContextMenu();
        }
        if (this.options.preventTextSelection) {
            this.preventTextSelection();
        }
        if (this.options.preventDragDrop) {
            this.preventDragDrop();
        }
        if (this.options.preventDevTools) {
            this.preventDevTools();
        }
        // Automatic developer-tools detection is intentionally disabled.
        // Browser chrome size heuristics and debugger timing checks create false positives.
        // Shortcut blocking is handled centrally in main.js.
        this.options.detectDevTools = false;
    }

    // Prevent right-click context menu
    preventContextMenu() {
        document.addEventListener('contextmenu', (e) => {
            e.preventDefault();
            this.logSecurityEvent('context_menu_prevented', 'Right-click context menu blocked');
            return false;
        });
    }

    // Prevent text selection
    preventTextSelection() {
        document.addEventListener('selectstart', (e) => {
            e.preventDefault();
            return false;
        });
    }

    // Prevent drag and drop
    preventDragDrop() {
        document.addEventListener('dragstart', (e) => {
            e.preventDefault();
            return false;
        });

        document.addEventListener('drop', (e) => {
            e.preventDefault();
            return false;
        });
    }

    // Prevent developer tools keyboard shortcuts
    preventDevTools() {
        document.addEventListener('keydown', (e) => {
            const blockedCombinations = [
                e.key === 'F12',
                (e.ctrlKey && e.shiftKey && e.key === 'I'),
                (e.ctrlKey && e.shiftKey && e.key === 'J'),
                (e.ctrlKey && e.key === 'u'),
                (e.ctrlKey && e.shiftKey && e.key === 'C'),
                (e.metaKey && e.altKey && e.key === 'i') // Mac
            ];

            if (blockedCombinations.some(condition => condition)) {
                e.preventDefault();
                this.logSecurityEvent('dev_tools_blocked', 'Developer tools access attempted');
                this.showSecurityWarning('Developer tools are disabled for security reasons.');
                return false;
            }
        });
    }

    // Automatic developer-tools detection is disabled.
    // These heuristics are noisy and cannot be treated as a security boundary.
    detectDevTools() {
        this.stopDevToolsDetection();
        this.devToolsDetected = false;
    }

    // Stop dev tools detection
    stopDevToolsDetection() {
        if (this.devToolsCheckInterval) {
            clearInterval(this.devToolsCheckInterval);
            this.devToolsCheckInterval = null;
        }
    }

    // Handle security violations
    handleSecurityViolation() {
        this.logSecurityEvent('security_violation', 'Potential security violation detected');
        this.showSecurityWarning('Security violation detected. This incident will be logged.');
    }

    // Show security warning
    showSecurityWarning(message) {
        const lowered = String(message || '').toLowerCase();
        if (lowered.includes('developer tools detected') || lowered.includes('security violation detected')) {
            return;
        }
        const warning = document.createElement('div');
        warning.style.cssText = `
            position: fixed;
            top: 20px;
            right: 20px;
            background: var(--error-color, #dc3545);
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

    // SAFE VERSION: Log security events
    async logSecurityEvent(eventType, description) {
        try {
            // Don't log the same event repeatedly
            if (this.lastEvent === `${eventType}-${description}` && Date.now() - this.lastEventTime < 5000) {
                return;
            }
            
            this.lastEvent = `${eventType}-${description}`;
            this.lastEventTime = Date.now();

            console.log(`🔒 Security Event: ${eventType} - ${description}`);
            
            // Try to log to backend, but don't cause errors if it fails
            await fetch('../backend/api/log_security_event.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    event_type: eventType,
                    description: description,
                    user_agent: navigator.userAgent,
                    timestamp: new Date().toISOString()
                })
            }).catch(error => {
                // Silently handle errors - don't log to console to avoid loops
                console.log('Security logging endpoint not available');
            });
        } catch (error) {
            // Don't log errors to avoid infinite loops
            console.log('Security event logging failed silently');
        }
    }

    // Enable/disable security features
    setSecurityLevel(level) {
        this.options.securityLevel = level;
        
        switch (level) {
            case 'high':
                this.securityEnabled = true;
                this.options.detectDevTools = false; // CHANGED: Keep false even for high
                break;
            case 'medium':
                this.securityEnabled = true;
                this.options.detectDevTools = false;
                break;
            case 'low':
                this.securityEnabled = false;
                this.options.detectDevTools = false;
                break;
        }
    }

    // Clean up event listeners
    destroy() {
        this.stopDevToolsDetection();
        console.log('🔒 SecurityManager destroyed');
    }
}

// Initialize security when DOM is loaded for general protection
document.addEventListener('DOMContentLoaded', () => {
    // Initialize basic security for all pages
    window.basicSecurity = new SecurityManager({
        preventContextMenu: true,
        preventTextSelection: false, // Allow text selection for general use
        preventDragDrop: true,
        preventDevTools: true,
        detectDevTools: false, // CHANGED: Keep disabled to prevent loops
        securityLevel: 'medium'
    });
});

// Export for module usage
if (typeof module !== 'undefined' && module.exports) {
    module.exports = SecurityManager;
}
