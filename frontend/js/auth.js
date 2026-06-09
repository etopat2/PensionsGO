// 
// frontend/js/auth.js
// 
// Handles user authentication via Email OR Phone Number
// - Validates flexible phone input (international + local Uganda formats)
// - Displays modals for feedback
// - Manages session and localStorage
// - Redirects user based on role or return URL
// - Single device login with confirmation modal
// - Error handling for server compatibility
// 
const DEVICE_TOKEN_STORAGE_KEY = "pensionsgo_device_token";
const HOSTED_SESSION_ID_STORAGE_KEY = "pensionsgo_hosted_session_id";
const HOSTED_SESSION_USER_STORAGE_KEY = "pensionsgo_hosted_session_user";
const HOSTED_SESSION_VERIFIED_AT_STORAGE_KEY = "pensionsgo_hosted_session_verified_at";

function setHostedSessionClientCookies(sessionId, userId) {
  const sid = String(sessionId || "").trim();
  const uid = String(userId || "").trim();
  if (!/^[a-f0-9]{64}$/i.test(sid) || !uid) return;
  const secure = window.location.protocol === "https:" ? "; Secure" : "";
  document.cookie = `PENSION_APP_CLIENT_SID=${encodeURIComponent(sid)}; Path=/; SameSite=Lax${secure}`;
  document.cookie = `PENSION_APP_CLIENT_UID=${encodeURIComponent(uid)}; Path=/; SameSite=Lax${secure}`;
}

function isPrivateIpv4Address(hostname) {
  const match = String(hostname || "").match(/^(\d+)\.(\d+)\.(\d+)\.(\d+)$/);
  if (!match) return false;
  const a = Number(match[1]);
  const b = Number(match[2]);
  return a === 10
    || a === 127
    || (a === 192 && b === 168)
    || (a === 172 && b >= 16 && b <= 31);
}

function isLocalAppServerContext() {
  const hostname = String(window.location.hostname || "").trim().toLowerCase();
  if (!hostname) return false;
  return hostname === "localhost"
    || hostname === "::1"
    || hostname.endsWith(".localhost")
    || hostname.endsWith(".local")
    || isPrivateIpv4Address(hostname);
}

function getConnectivityFailureMessage() {
  if (isLocalAppServerContext()) {
    return "Unable to reach the local app server. Please confirm XAMPP/Apache is running and that you opened the app through the local server address.";
  }
  return "Network connection failed. Please check your internet connection.";
}

function createSecureDeviceToken() {
  if (window.crypto?.getRandomValues) {
    const bytes = new Uint8Array(32);
    window.crypto.getRandomValues(bytes);
    return Array.from(bytes, (byte) => byte.toString(16).padStart(2, "0")).join("");
  }

  return Array.from({ length: 64 }, () => Math.floor(Math.random() * 16).toString(16)).join("");
}

function getPersistentDeviceToken() {
  const existingToken = (localStorage.getItem(DEVICE_TOKEN_STORAGE_KEY) || "").trim().toLowerCase();
  if (/^[a-f0-9]{64}$/.test(existingToken)) {
    return existingToken;
  }

  const deviceToken = createSecureDeviceToken();
  localStorage.setItem(DEVICE_TOKEN_STORAGE_KEY, deviceToken);
  return deviceToken;
}

function showLogoutResultModalFromQuery() {
  const url = new URL(window.location.href);
  const logoutState = (url.searchParams.get("logout") || "").trim().toLowerCase();
  if (!logoutState) return;

  if (logoutState === "success") {
    showLoginModal("success", "You have been logged out successfully.", "Logout Complete");
  } else if (logoutState === "error") {
    showLoginModal("info", "Your local session was cleared. Please log in again.", "Logout Completed Locally");
  }

  url.searchParams.delete("logout");
  url.searchParams.delete("t");
  window.history.replaceState({}, document.title, url.toString());
}

let authCsrfTokenCache = "";

async function readJsonApiResponse(response, fallbackMessage = "Server returned invalid response format") {
  if (!response) {
    throw new Error("No response received from server.");
  }

  const responseText = await response.text();
  if (!responseText) {
    throw new Error("Server returned empty response.");
  }

  try {
    return JSON.parse(responseText);
  } catch (parseError) {
    console.error("Failed to parse API JSON:", parseError);
    console.error("Raw API response:", responseText);

    let errorMessage = fallbackMessage;
    if (responseText.includes("Fatal error") || responseText.includes("Parse error")) {
      const match = responseText.match(/(Fatal error|Parse error):[^<]+/i);
      if (match) {
        errorMessage = "Server error: " + match[0];
      }
    } else {
      const htmlTitle = responseText.match(/<title[^>]*>([^<]+)<\/title>/i);
      const messageMatch = responseText.match(/(message|error)[\"']?\s*[:=]\s*[\"']([^\"']+)/i);
      if (messageMatch?.[2]) {
        errorMessage = messageMatch[2];
      } else if (htmlTitle?.[1]) {
        errorMessage = htmlTitle[1].trim();
      }
    }

    throw new Error(errorMessage);
  }
}

async function getAuthCsrfToken(forceRefresh = false) {
  if (!forceRefresh && authCsrfTokenCache) {
    return authCsrfTokenCache;
  }

  const response = await fetch("../backend/api/get_csrf_token.php", {
    credentials: "include",
    cache: "no-store",
    headers: {
      Accept: "application/json",
      "X-Requested-With": "XMLHttpRequest",
    },
  });

  if (!response.ok) {
    throw new Error(`CSRF token request failed with status ${response.status}`);
  }

  const data = await readJsonApiResponse(response, "Unable to initialize request security token.");
  if (!data.success || !data.token) {
    throw new Error(data.message || "Unable to initialize request security token.");
  }

  authCsrfTokenCache = String(data.token);
  return authCsrfTokenCache;
}

document.addEventListener("DOMContentLoaded", () => {
  const form = document.getElementById("loginForm");
  if (!form) return;

  const normalizePhone = (value) => {
    const input = String(value || "").trim().replace(/[\s().-]/g, "");
    if (!input) return null;
    if (/^00[1-9]\d{7,14}$/.test(input)) return `+${input.slice(2)}`;
    if (/^\+[1-9]\d{7,14}$/.test(input)) return input;
    if (/^0\d{9}$/.test(input)) return `+256${input.slice(1)}`;
    if (/^[1-9]\d{7,14}$/.test(input)) return `+${input}`;
    return null;
  };

  
  // Get optional return URL (if redirected after session expiry)
  const urlParams = new URLSearchParams(window.location.search);
  const returnUrl = urlParams.get("return");
  showLogoutResultModalFromQuery();

  // Attach form submission handler
  form.addEventListener("submit", async (e) => {
    e.preventDefault();

    const username = document.getElementById("email").value.trim();
    const password = document.getElementById("password").value.trim();

    // 
    // 1️⃣ Basic Input Validation
    // 
    if (!username || !password) {
      showLoginModal("error", "Please enter your email/phone number and password.");
      return;
    }

    // 
    // 2️⃣ Input Format Validation
    // 
    const isEmail = /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(username);
    const normalizedPhone = normalizePhone(username);
    const isPhone = !isEmail && Boolean(normalizedPhone);

    if (!isEmail && !isPhone) {
      showLoginModal("error", "Enter a valid email or phone number (e.g., +256700123456, 0770123456, 0312123456, 0800123456).");
      return;
    }

    if (!navigator.onLine && !isLocalAppServerContext()) {
      showLoginModal("info", "You appear to be offline. Connect to the network that serves this app, then try logging in again.", "Connection Required");
      return;
    }

    // Show loading modal
    showLoginModal("loading", "Processing... please wait");

    let response = null; // Declare response variable at a higher scope
    const controller = new AbortController();
    const loginTimeoutMs = 30000;
    const timeoutId = setTimeout(() => controller.abort(), loginTimeoutMs);

    try {
      // 
      // 3️⃣ Prepare Data & Send Login Request
      // 
      const formData = new FormData();
      formData.append("email", isPhone ? normalizedPhone : username);
      formData.append("password", password);
      formData.append("device_token", getPersistentDeviceToken());

      response = await fetch("../backend/api/login.php", {
        method: "POST",
        body: formData,
        credentials: "include",
        cache: "no-store",
        signal: controller.signal,
      headers: {
        'Accept': 'application/json',
        'X-Device-Token': getPersistentDeviceToken()
      }
      });

      // 
      // 4️⃣ Enhanced Response Handling
      // 
      // First, check if we got any response at all
      if (!response) {
        throw new Error('No response received from server');
      }

      // Get response text first for debugging
      const responseText = await response.text();
      console.log('Raw response:', responseText.substring(0, 500));

      // Try to parse as JSON
      let json;
      try {
        json = JSON.parse(responseText);
      } catch (parseError) {
        console.error('Failed to parse JSON:', parseError);
        console.error('Response text was:', responseText);
        
        // Try to extract error message from HTML if possible
        let errorMessage = 'Server returned invalid response format';
        if (responseText.includes('Fatal error') || responseText.includes('Parse error')) {
          const match = responseText.match(/(Fatal error|Parse error):[^<]+/i);
          if (match) {
            errorMessage = 'Server error: ' + match[0];
          }
        } else if (responseText.includes('message') || responseText.includes('error')) {
          const match = responseText.match(/(message|error)["']?\s*[:=]\s*["']([^"']+)/i);
          if (match && match[2]) {
            errorMessage = match[2];
          }
        }
        
        throw new Error(errorMessage);
      }

      // 
      // 5️⃣ Handle Login Response
      // 
      if (json.success) {
        handleLoginSuccess(json, returnUrl);
      } else {
        const serverMessage = json.message || "Invalid login credentials.";
        if (/pensioner login is currently disabled/i.test(serverMessage)) {
          showLoginModal("info", serverMessage, "Pensioner Login Disabled");
        } else {
          showLoginModal("error", serverMessage);
        }
      }
    } catch (err) {
      console.error("Login request failed:", err);
      
      // Get response text for debugging
      let responseText = '';
      try {
        if (response) {
          responseText = await response.text();
          console.log("Response text:", responseText.substring(0, 200));
        }
      } catch (textError) {
        console.log("Could not get response text:", textError);
      }
      
      // Enhanced error messages for common issues
      let userMessage = "Login failed";
      
      if (err.name === 'AbortError') {
        userMessage = `Login timed out after ${Math.ceil(loginTimeoutMs / 1000)} seconds. Please try again.`;
      } else if (err.message.includes('Unexpected end of JSON input')) {
        userMessage = "Server returned empty response. Please try again.";
      } else if (err.message.includes('SyntaxError') || err.message.includes('Failed to execute')) {
        userMessage = "Server configuration error. Please contact support.";
      } else if (err.message.includes('Failed to fetch') || err.message.includes('NetworkError')) {
        userMessage = getConnectivityFailureMessage();
      } else if (err.message.includes('500')) {
        userMessage = "Server error (500). Please try again later.";
      } else if (err.message.includes('403')) {
        userMessage = "Access denied. Please try again or contact support.";
      } else {
        userMessage = "Login failed: " + err.message;
      }

      if (/pensioner login is currently disabled/i.test(userMessage)) {
        showLoginModal("info", userMessage, "Pensioner Login Disabled");
      } else {
        showLoginModal("error", userMessage);
      }
    } finally {
      clearTimeout(timeoutId);
    }
  });
});

function handleLoginSuccess(json, returnUrl) {
  const sessionSettings = {
    session_timeout: json.sessionTimeout || 1800,
    grace_period: json.gracePeriod || 5,
    allow_multiple_devices: json.allowMultipleDevices || false
  };

  localStorage.setItem('sessionSettings', JSON.stringify(sessionSettings));

  if (json.hasExistingSession && !sessionSettings.allow_multiple_devices) {
    showSingleDeviceConfirmationModal(json, returnUrl);
  } else {
    completeLogin(json, returnUrl, sessionSettings);
  }
}

// 
// Single Device Confirmation Modal //
function showSingleDeviceConfirmationModal(loginData, returnUrl) {
  // Remove existing modal if present
  const existing = document.getElementById("loginModal");
  if (existing) existing.remove();

  const modal = document.createElement("div");
  modal.id = "loginModal";
  modal.className = "auth-modal-overlay login-confirm-modal";

  modal.innerHTML = `
    <div class="auth-modal">
      <div class="auth-modal-header"><h3>Multiple Devices Detected</h3></div>
      <div class="auth-modal-body">
        <div class="login-warning-icon">⚠️</div>
        <p>Your account is already active on another device.</p>
        <p><strong>Do you want to log out from all other devices and continue here?</strong></p>
        <p class="modal-note">This will immediately log out any other active sessions.</p>
        <div class="session-settings-info">
          <p><small>Session timeout: ${Math.floor((loginData.sessionTimeout || 1800) / 60)} minutes</small></p>
          <p><small>Grace period: ${loginData.gracePeriod || 5} minutes</small></p>
        </div>
      </div>
      <div class="auth-modal-footer dual-buttons">
        <button id="cancelLogin" class="auth-btn auth-btn-secondary">Cancel</button>
        <button id="confirmSingleDevice" class="auth-btn auth-btn-primary">Yes, Log Out Others</button>
      </div>
    </div>
  `;

  document.body.appendChild(modal);

  // Handle button clicks
  document.getElementById("cancelLogin").addEventListener("click", () => {
    modal.remove();
    showLoginModal("info", "Login cancelled. You can only be logged in on one device at a time.");
  });

  document.getElementById("confirmSingleDevice").addEventListener("click", async () => {
    modal.remove();
    await terminateOtherSessions(loginData, returnUrl);
  });

  // Close on overlay click
  modal.addEventListener("click", (e) => {
    if (e.target === modal) {
      modal.remove();
      showLoginModal("info", "Login cancelled. You can only be logged in on one device at a time.");
    }
  });
}

// 
// Terminate Other Sessions //
async function terminateOtherSessions(loginData, returnUrl) {
  showLoginModal("loading", "Logging out other devices...");

  try {
    const csrfToken = await getAuthCsrfToken();
    const response = await fetch("../backend/api/terminate_other_sessions.php", {
      method: "POST",
      credentials: "include",
      cache: "no-store",
      headers: {
        'Accept': 'application/json',
        'Content-Type': 'application/json',
        'X-Device-Token': getPersistentDeviceToken(),
        'X-CSRF-Token': csrfToken
      },
      body: JSON.stringify({
        action: 'terminate_other_sessions',
        userId: loginData.userId
      })
    });

    // Response handling
    const contentType = response.headers.get('content-type');
    
    if (!contentType || !contentType.includes('application/json')) {
      console.error('Unexpected content type from terminate sessions:', contentType || '(missing)');
    }

    const result = await readJsonApiResponse(response, 'Server returned invalid response format');

    if (result.success) {
      showLoginModal("success", `Successfully logged out ${result.terminatedCount || 1} other device(s). Redirecting...`);
      completeLogin(loginData, returnUrl);
    } else {
      throw new Error(result.message || "Failed to terminate other sessions");
    }
  } catch (err) {
    console.error("Failed to terminate other sessions:", err);
    
    // Error messages
    let userMessage = "Failed to log out other devices. ";
    
    if (err.message.includes('Server error') || err.message.includes('Fatal error')) {
      userMessage += "Server configuration error. Please contact support.";
    } else if (err.message.includes('invalid response format')) {
      userMessage += "Server returned unexpected response.";
    } else {
      userMessage += err.message;
    }
    
    showLoginModal("error", userMessage);
  }
}

// 
// Complete Login Process //
function completeLogin(loginData, returnUrl = null, sessionSettings = null) {
  const existingSuccessModal = document.querySelector('.login-success-modal');
  if (!existingSuccessModal) {
    showLoginModal("success", "Login successful. Redirecting...");
  }

  // Save session info
  sessionStorage.setItem("isLoggedIn", "true");
  sessionStorage.setItem("userName", loginData.userName || "");
  sessionStorage.setItem("userRole", loginData.userRole || "");
  sessionStorage.setItem("userRoleEffective", loginData.userRoleEffective || "");
  sessionStorage.setItem("userId", loginData.userId || "");
  sessionStorage.setItem("phoneNo", loginData.phoneNo || "");
  sessionStorage.setItem("userPhoto", loginData.userPhoto || "");
  sessionStorage.setItem("lastActivity", Date.now().toString());
  sessionStorage.setItem("sessionTimeout", loginData.sessionTimeout || 1800);
  sessionStorage.setItem("gracePeriod", loginData.gracePeriod || 5);
  sessionStorage.setItem("pensionsgo_tab_auth_verified", "true");
  sessionStorage.removeItem("pensionsgo_public_session_allowance");
  if (loginData.sessionId && loginData.userId) {
    localStorage.setItem(HOSTED_SESSION_ID_STORAGE_KEY, String(loginData.sessionId));
    localStorage.setItem(HOSTED_SESSION_USER_STORAGE_KEY, String(loginData.userId));
    localStorage.setItem(HOSTED_SESSION_VERIFIED_AT_STORAGE_KEY, Date.now().toString());
    setHostedSessionClientCookies(loginData.sessionId, loginData.userId);
  }

  // Store persistent data
  const userData = {
    name: loginData.userName || "",
    role: loginData.userRole || "",
    effectiveRole: loginData.userRoleEffective || loginData.userRole || "",
    id: loginData.userId || "",
    photo: loginData.userPhoto || "images/default-user.png",
    phone: loginData.phoneNo || "",
    sessionTimeout: loginData.sessionTimeout || 1800,
    gracePeriod: loginData.gracePeriod || 5
  };
  localStorage.setItem("loggedInUser", JSON.stringify(userData));
  localStorage.setItem("userRole", loginData.userRole || "");
  localStorage.setItem("userRoleEffective", loginData.userRoleEffective || "");
  
  // Store session settings if provided
  if (sessionSettings) {
    localStorage.setItem("sessionSettings", JSON.stringify(sessionSettings));
  }

  if (window.PwaUpdateManager?.check) {
    window.PwaUpdateManager.check({ reason: 'login' });
  } else {
    window.__pendingPwaUpdateCheck = { reason: 'login' };
    try {
      document.dispatchEvent(new CustomEvent('pwa:check-update', { detail: { reason: 'login' } }));
    } catch (_error) {}
  }

  // Determine redirect URL
  const redirectUrl = getSafeRedirectUrl(loginData.userRole, returnUrl, loginData.userRoleEffective || "");
  console.log(`🔄 Redirecting ${loginData.userRole} to: ${redirectUrl}`);

  setTimeout(() => {
    window.location.href = redirectUrl;
  }, 2000);
}

// 
// LOGIN MODAL FEEDBACK HANDLER (Enhanced)
// 
function showLoginModal(type, message, customTitle = "") {
  // Remove existing modal if present
  const existing = document.getElementById("loginModal");
  if (existing) existing.remove();

  const modal = document.createElement("div");
  modal.id = "loginModal";
  modal.className = `auth-modal-overlay login-${type}-modal`;

  let modalContent = "";

  if (type === "loading") {
    modalContent = `
      <div class="auth-modal">
        <div class="auth-modal-body">
          <div class="auth-spinner"></div>
          <p>${message}</p>
        </div>
      </div>
    `;
  } else if (type === "success") {
    modalContent = `
      <div class="auth-modal">
        <div class="auth-modal-header"><h3>${customTitle || "Success"}</h3></div>
        <div class="auth-modal-body">
          <div class="login-success-icon">✓</div>
          <p>${message}</p>
        </div>
        <div class="auth-modal-footer">
          <button id="closeLoginModal" class="auth-btn auth-btn-primary">Continue</button>
        </div>
      </div>
    `;
  } else if (type === "error") {
    modalContent = `
      <div class="auth-modal">
        <div class="auth-modal-header"><h3>${customTitle || "Error"}</h3></div>
        <div class="auth-modal-body">
          <div class="login-error-icon">✗</div>
          <p>${message}</p>
        </div>
        <div class="auth-modal-footer">
          <button id="closeLoginModal" class="auth-btn auth-btn-secondary">Close</button>
        </div>
      </div>
    `;
  } else if (type === "info") {
    modalContent = `
      <div class="auth-modal">
        <div class="auth-modal-header"><h3>${customTitle || "Information"}</h3></div>
        <div class="auth-modal-body">
          <div class="login-info-icon">ℹ️</div>
          <p>${message}</p>
        </div>
        <div class="auth-modal-footer">
          <button id="closeLoginModal" class="auth-btn auth-btn-secondary">Close</button>
        </div>
      </div>
    `;
  }

  modal.innerHTML = modalContent;
  document.body.appendChild(modal);

  // Handle modal close for non-loading modals
  if (type === "error" || type === "info" || type === "success") {
    document.getElementById("closeLoginModal")?.addEventListener("click", () => modal.remove());
    modal.addEventListener("click", (e) => {
      if (e.target === modal) modal.remove();
    });
  }

  if (type === "success") {
    setTimeout(() => {
      if (document.body.contains(modal)) {
        modal.remove();
      }
    }, 2400);
  }
}

// 
// Safe Role-based Redirection Logic //
function getSafeRedirectUrl(userRole, requestedUrl = "", userRoleEffective = "") {
  const role = (userRole || "").toLowerCase();
  const accessRole = (userRoleEffective || localStorage.getItem("userRoleEffective") || role).toLowerCase();
  const dashboardFirstRoles = new Set([
    "user",
    "oc_pen",
    "dep_oc",
    "deputy_oc",
    "deputy_oc_pen",
    "deputy_oc_pension"
  ]);

  const roleLandingPages = {
    super_admin: "dashboard.html",
    admin: "dashboard.html",
    clerk: "pension_file_registry.html",
    pensioner: "pensioner_board.html",
    user: "dashboard.html",
    oc_pen: "dashboard.html",
    dep_oc: "dashboard.html",
    deputy_oc: "dashboard.html",
    deputy_oc_pen: "dashboard.html",
    deputy_oc_pension: "dashboard.html",
    writeup_officer: "tasks.html",
    file_creator: "tasks.html",
    data_entry: "tasks.html",
    assessor: "tasks.html",
    auditor: "tasks.html",
    approver: "tasks.html",
  };

  const defaultLandingPage = "dashboard.html";
  const safeLandingPage = roleLandingPages[accessRole] || roleLandingPages[role] || defaultLandingPage;

  if (dashboardFirstRoles.has(accessRole) || dashboardFirstRoles.has(role)) {
    return safeLandingPage;
  }

  if (!requestedUrl) return safeLandingPage;

  const pageName = requestedUrl.split("/").pop().split("?")[0];
  return isUrlAccessibleForRole(pageName, accessRole) ? requestedUrl : safeLandingPage;
}

// 
// Role-based Access Validation //
function isUrlAccessibleForRole(pageName, userRole) {
  const sharedToolsPages = ["benefits_calculator.html","podcast.html","document_viewer.html"];
  const normalizedRole = String(userRole || '').toLowerCase().trim();
  if (pageName === 'dashboard.html' && normalizedRole && !['user', 'pensioner'].includes(normalizedRole)) {
    return true;
  }
  const roleAccessRules = {
    super_admin: () => true,
    admin: () => true,
    clerk: (p) =>
      ["file_registry.html","pension_file_registry.html","staff_due.html","add_staff.html","edit_staff.html","view_staff.html","tasks.html","file_tracking.html","application_status.html","profile.html","edit_user.html","messages.html","reports.html","dashboard.html"].concat(sharedToolsPages).includes(p),
    oc_pen: (p) =>
      ["pension_file_registry.html","staff_due.html","add_staff.html","view_staff.html","tasks.html","file_tracking.html","application_status.html","profile.html","edit_user.html","messages.html","reports.html","dashboard.html"].concat(sharedToolsPages).includes(p),
    dep_oc: (p) =>
      ["pension_file_registry.html","staff_due.html","add_staff.html","view_staff.html","tasks.html","file_tracking.html","application_status.html","profile.html","edit_user.html","messages.html","reports.html","dashboard.html"].concat(sharedToolsPages).includes(p),
    deputy_oc: (p) =>
      ["pension_file_registry.html","staff_due.html","add_staff.html","view_staff.html","tasks.html","file_tracking.html","application_status.html","profile.html","edit_user.html","messages.html","reports.html","dashboard.html"].concat(sharedToolsPages).includes(p),
    deputy_oc_pen: (p) =>
      ["pension_file_registry.html","staff_due.html","add_staff.html","view_staff.html","tasks.html","file_tracking.html","application_status.html","profile.html","edit_user.html","messages.html","reports.html","dashboard.html"].concat(sharedToolsPages).includes(p),
    deputy_oc_pension: (p) =>
      ["pension_file_registry.html","staff_due.html","add_staff.html","view_staff.html","tasks.html","file_tracking.html","application_status.html","profile.html","edit_user.html","messages.html","reports.html","dashboard.html"].concat(sharedToolsPages).includes(p),
    writeup_officer: (p) =>
      ["pension_file_registry.html","staff_due.html","add_staff.html","edit_staff.html","view_staff.html","tasks.html","file_tracking.html","application_status.html","profile.html","edit_user.html","messages.html","reports.html","dashboard.html"].concat(sharedToolsPages).includes(p),
    file_creator: (p) =>
      ["pension_file_registry.html","tasks.html","file_tracking.html","application_status.html","profile.html","edit_user.html","messages.html","dashboard.html"].concat(sharedToolsPages).includes(p),
    data_entry: (p) =>
      ["file_registry.html","pension_file_registry.html","tasks.html","staff_due.html","add_staff.html","edit_staff.html","view_staff.html","file_tracking.html","application_status.html","profile.html","edit_user.html","messages.html","dashboard.html"].concat(sharedToolsPages).includes(p),
    assessor: (p) => ["pension_file_registry.html","tasks.html","file_tracking.html","application_status.html","profile.html","edit_user.html","messages.html","dashboard.html"].concat(sharedToolsPages).includes(p),
    auditor: (p) => ["pension_file_registry.html","tasks.html","file_tracking.html","application_status.html","profile.html","edit_user.html","messages.html","dashboard.html"].concat(sharedToolsPages).includes(p),
    approver: (p) => ["pension_file_registry.html","tasks.html","file_tracking.html","application_status.html","profile.html","edit_user.html","messages.html","dashboard.html"].concat(sharedToolsPages).includes(p),
    pensioner: (p) =>
      ["pensioner_board.html","pensioner_lookup.html","profile.html","edit_user.html"].concat(sharedToolsPages).includes(p),
    user: (p) => ["dashboard.html","pension_file_registry.html","application_status.html","profile.html","edit_user.html","faq.html","about.html"].concat(sharedToolsPages).includes(p),
  };

  const accessRule = roleAccessRules[userRole] || (() => false);
  return accessRule(pageName);
}

// Backward compatibility
function getRedirectUrlByRole(userRole) {
  return getSafeRedirectUrl(userRole);
}

// Export for testing
if (typeof module !== 'undefined' && module.exports) {
  module.exports = {
    getSafeRedirectUrl,
    isUrlAccessibleForRole,
    completeLogin
  };
}

