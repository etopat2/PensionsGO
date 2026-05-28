/**
 * 
 * logout.js - With SessionManager Integration
 * 
 * Complete Replacement
 * 
 */

export function initLogout(options = {}) {
  console.log("Logout module initializing...");
  
  const logoutUrl = options.logoutUrl || computeLogoutUrl();

  // Wait for header to be fully loaded
  setTimeout(() => {
    const logoutBtn = document.getElementById('logoutBtn');
    console.log("Looking for logout button:", logoutBtn);
    
    if (!logoutBtn) {
      console.warn("Logout button not found");
      return;
    }

    // Remove any existing event listeners by cloning
    const newLogoutBtn = logoutBtn.cloneNode(true);
    logoutBtn.parentNode.replaceChild(newLogoutBtn, logoutBtn);

    newLogoutBtn.addEventListener('click', function(e) {
      e.preventDefault();
      e.stopPropagation();
      console.log("Logout button clicked");
      showLogoutModal(logoutUrl);
    });

    console.log("Logout button event listener attached");

  }, 200);

  // Fix Edit Profile menu item
  fixEditProfileMenuItem();
}

/* Helper Functions */

function computeLogoutUrl() {
  try {
    const path = window.location.pathname;
    if (path.includes('/frontend/')) {
      const base = path.split('/frontend/')[0];
      return `${window.location.origin}${base}/backend/api/logout.php`;
    }
    return '../backend/api/logout.php';
  } catch (err) {
    return '../backend/api/logout.php';
  }
}

function getLogoutDeviceHeaders(headers = {}) {
  const merged = { ...headers };
  const token = (typeof window.getPersistentDeviceToken === 'function')
    ? window.getPersistentDeviceToken()
    : (localStorage.getItem('pensionsgo_device_token') || '').trim().toLowerCase();
  if (/^[a-f0-9]{64}$/.test(token)) {
    merged['X-Device-Token'] = token;
  }
  return merged;
}

async function resolveLogoutCsrfToken() {
  if (typeof window.fetchCsrfToken === 'function') {
    return window.fetchCsrfToken();
  }

  const token = document.querySelector('meta[name="csrf-token"]')?.content?.trim();
  return token || '';
}

function showLogoutModal(logoutUrl) {
  console.log("Showing logout modal");
  
  // Remove existing modal if present
  const existing = document.getElementById('logoutModal');
  if (existing) existing.remove();

  // Get CSRF token
  let csrfToken = '';
  
  // Get session info for display
  const sessionStart = sessionStorage.getItem('sessionStart');
  const sessionDuration = sessionStart ? 
    Math.floor((Date.now() - parseInt(sessionStart)) / 60000) : 'Unknown';

  const modal = document.createElement('div');
  modal.id = 'logoutModal';
  modal.className = 'auth-modal-overlay';
  modal.setAttribute('role', 'dialog');
  modal.setAttribute('aria-modal', 'true');
  modal.setAttribute('aria-labelledby', 'logoutModalTitle');
  modal.innerHTML = `
    <div class="auth-modal">
      <div class="auth-modal-header">
        <h3 id="logoutModalTitle">Confirm Logout</h3>
      </div>
      <div class="auth-modal-body">
        <p>Are you sure you want to log out of PensionsGo?</p>
        ${sessionDuration !== 'Unknown' ? `<p><strong>Session Duration:</strong> ${sessionDuration} minutes</p>` : ''}
        <div class="logout-options">
          <label>
            <input type="checkbox" id="clearLocalData" checked />
            Clear local data (recommended)
          </label>
        </div>
      </div>
      <div class="auth-modal-footer logout-actions">
        <button id="confirmLogout" class="auth-btn auth-btn-danger">Yes, Logout</button>
        <button id="cancelLogout" class="auth-btn auth-btn-secondary">Cancel</button>
      </div>
    </div>
  `;
  document.body.appendChild(modal);
  document.body.classList.add('modal-open');
  requestAnimationFrame(() => {
    modal.querySelector('#confirmLogout')?.focus();
  });

  // Add event listeners to modal buttons
  document.getElementById('cancelLogout').addEventListener('click', function(e) {
    e.stopPropagation();
    modal.remove();
    document.body.classList.remove('modal-open');
  });

  document.getElementById('confirmLogout').addEventListener('click', function(e) {
    e.stopPropagation();
    
    const clearLocalData = document.getElementById('clearLocalData').checked;
    
    modal.remove();
    document.body.classList.remove('modal-open');
    executeEnhancedLogout(logoutUrl, csrfToken, {
      logoutAllDevices: false,
      clearLocalData: clearLocalData
    });
  });

  // Close modal when clicking outside
  modal.addEventListener('click', function(e) {
    if (e.target === modal) {
      modal.remove();
      document.body.classList.remove('modal-open');
    }
  });

  // Close modal on escape key
  const handleEscape = (e) => {
    if (e.key === 'Escape') {
      modal.remove();
      document.body.classList.remove('modal-open');
      document.removeEventListener('keydown', handleEscape);
    }
  };
  document.addEventListener('keydown', handleEscape);
}

// Logout execution
async function executeEnhancedLogout(logoutUrl, csrfToken, options = {}) {
  console.log("Executing logout...");
  
  // Show overlay while request executes
  const overlay = document.createElement('div');
  overlay.className = 'auth-overlay';
  overlay.innerHTML = `
    <div class="auth-spinner"></div>
    <p>Logging out...</p>
    ${options.logoutAllDevices ? '<p><small>Logging out from all devices</small></p>' : ''}
  `;
  document.body.appendChild(overlay);

  try {
    csrfToken = await resolveLogoutCsrfToken();
    // First, clear client-side data if requested
    if (options.clearLocalData) {
      clearAllUserData();
    }
    
    // Prepare logout data
    const formData = new URLSearchParams();
    formData.append('logout_type', 'user_initiated');
    formData.append('logout_reason', 'User clicked logout button');
    
    if (csrfToken) {
      formData.append('csrf_token', csrfToken);
    }
    
    if (options.logoutAllDevices) {
      formData.append('logout_all_devices', 'true');
    }

    console.log("Calling logout URL:", logoutUrl);
    const response = await fetch(logoutUrl, {
      method: 'POST',
      headers: getLogoutDeviceHeaders({
        'Content-Type': 'application/x-www-form-urlencoded',
        'X-CSRF-Token': csrfToken || ''
      }),
      body: formData.toString(),
      credentials: 'include'
    });

    const result = await response.json();
    console.log("Logout response:", result);
    
    if (result.success) {
      console.log('Logout successful:', result.message);
      
      // Clear any remaining client-side data
      if (options.clearLocalData) {
        clearAllUserData();
      }
      
      // Broadcast logout to other tabs
      localStorage.setItem('session_logout_event', JSON.stringify({
        tabId: `tab_${Date.now()}`,
        timestamp: Date.now(),
        reason: 'user_logout'
      }));
      
      // Redirect to login page with cache-busting
      setTimeout(() => {
        window.location.replace('login.html?logout=success&t=' + Date.now());
      }, 500);
      
    } else {
      throw new Error(result.message || 'Logout failed');
    }

  } catch (err) {
    console.error('Logout error:', err);
    
    // Even if server fails, clear client data and redirect
    if (options.clearLocalData) {
      clearAllUserData();
    }
    
    // Broadcast logout to other tabs
    localStorage.setItem('session_logout_event', JSON.stringify({
      tabId: `tab_${Date.now()}`,
      timestamp: Date.now(),
      reason: 'user_logout_error'
    }));
    
    // Show user-friendly error but still redirect
    setTimeout(() => {
      window.location.replace('login.html?logout=error&t=' + Date.now());
    }, 500);
    
  } finally {
    // Remove overlay after a minimum display time for better UX
    setTimeout(() => {
      if (document.body.contains(overlay)) {
        overlay.remove();
      }
    }, 1000);
  }
}

// Function to clear all user data consistently
function clearAllUserData() {
  try {
    console.log("Clearing all user data...");
    
    // Clear user-specific localStorage
    localStorage.removeItem('loggedInUser');
    localStorage.removeItem('userRole');
    localStorage.removeItem('userRoleEffective');
    localStorage.removeItem('pensionsgo_seen_broadcasts');
    localStorage.removeItem('lastVisitedPage');
    localStorage.removeItem('sessionSettings');
    
    // Clear all sessionStorage
    sessionStorage.clear();
    
    // Clear any form data or temporary storage
    if (typeof window.clearUserData === 'function') {
      window.clearUserData();
    }
    
    // Dispatch event for other modules to clean up
    window.dispatchEvent(new CustomEvent('userLoggedOut'));
    
    console.log('All user data cleared from client storage');
    
  } catch (error) {
    console.error('Error clearing user data:', error);
  }
}

// Fix Edit Profile menu item
function fixEditProfileMenuItem() {
  const editProfileLink = document.querySelector('a[href="edit_user.html"]');
  if (editProfileLink) {
    const newLink = editProfileLink.cloneNode(true);
    editProfileLink.parentNode.replaceChild(newLink, editProfileLink);
    
    newLink.addEventListener('click', function(e) {
      e.preventDefault();
      e.stopPropagation();
      
      const currentUser = JSON.parse(localStorage.getItem('loggedInUser') || '{}');
      const currentUserId = currentUser.id;
      
      if (currentUserId) {
        window.location.href = `edit_user.html?user_id=${currentUserId}`;
      } else {
        appAlert('Unable to determine user ID. Please login again.');
        window.location.href = 'login.html';
      }
    });
  }
}

// Make clearAllUserData available globally
if (typeof window !== 'undefined') {
  window.clearAllUserData = clearAllUserData;
}

// Add event listener for other modules to react to logout
if (typeof window !== 'undefined') {
  window.addEventListener('userLoggedOut', () => {
    console.log('User logged out event received');
  });
}

// Global logout function
if (typeof window !== 'undefined') {
  window.performEnhancedLogout = async function(logoutType = 'user_initiated', reason = 'User action', options = {}) {
    const logoutUrl = computeLogoutUrl();
    const csrfToken = await resolveLogoutCsrfToken();
    
    try {
      const formData = new URLSearchParams();
      formData.append('logout_type', logoutType);
      formData.append('logout_reason', reason);
      
      if (csrfToken) {
        formData.append('csrf_token', csrfToken);
      }
      
      if (options.logoutAllDevices) {
        formData.append('logout_all_devices', 'true');
      }

      const response = await fetch(logoutUrl, {
        method: 'POST',
        headers: getLogoutDeviceHeaders({
          'Content-Type': 'application/x-www-form-urlencoded',
          'X-CSRF-Token': csrfToken || ''
        }),
        body: formData.toString(),
        credentials: 'include'
      });

      const result = await response.json();
      
      if (result.success) {
        if (options.clearLocalData !== false) {
          clearAllUserData();
        }
        
        // Broadcast logout to other tabs
        localStorage.setItem('session_logout_event', JSON.stringify({
          tabId: `tab_${Date.now()}`,
          timestamp: Date.now(),
          reason: logoutType
        }));
        
        return { success: true, message: result.message };
      } else {
        throw new Error(result.message);
      }
    } catch (error) {
      console.error('Logout failed:', error);
      
      if (options.clearLocalData !== false) {
        clearAllUserData();
      }
      
      localStorage.setItem('session_logout_event', JSON.stringify({
        tabId: `tab_${Date.now()}`,
        timestamp: Date.now(),
        reason: 'logout_error'
      }));
      
      return { success: false, message: error.message };
    }
  };
}


