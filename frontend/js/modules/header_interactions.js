/**
 * 
 * header_interactions.js - Enhanced with SessionManager Integration
 * 
 * Complete Replacement
 * 
 */

// Global state management
let openDropdown = null;
let isProcessingClick = false;
let sessionManager = null;

// 
// Main Initialization //
export function initHeaderInteractions() {
  console.log("[init] Initializing enhanced header interactions");

  setTimeout(() => {
    initializeHeaderInteractions();
  }, 500);
}

function initializeHeaderInteractions() {
  console.log("[init] Starting enhanced header interactions");

  // Get session manager from global scope
  sessionManager = window.sessionManager || null;
  
  let isMobile = window.innerWidth <= 768;
  window.addEventListener("resize", () => {
    isMobile = window.innerWidth <= 768;
  });

  initializeEventDelegation();
  initializeDesktopHover();
  initializeUserProfileDisplay();
  initializeMenuVisibility();
  initializeDynamicCounts();
  initializeMenuLinks();
  initializeSessionInfoDisplay();

  window.addEventListener("userLoggedOut", handleUserLoggedOut);

  console.log("[ok] Enhanced header interactions initialized successfully");
}

// 
// Enhanced Logout Handler
// 
window.logoutUser = async function logoutUser() {
  try {
    console.log("[init] Initiating enhanced logout process...");
    
    // Show confirmation modal
    const confirmation = await showEnhancedLogoutConfirmation();
    const confirmed = typeof confirmation === 'object' ? confirmation.confirmed : confirmation;
    if (!confirmed) return;

    const options = (typeof confirmation === 'object' ? confirmation.options : null) || window.lastLogoutOptions || {};
    const clearLocalData = options.clearLocalData !== false;
    
    // Get CSRF token
    const csrfToken = typeof window.fetchCsrfToken === 'function'
      ? await window.fetchCsrfToken()
      : (document.querySelector('meta[name="csrf-token"]')?.content || '');
    
    // Use global performEnhancedLogout if available
    if (window.performEnhancedLogout) {
      const result = await window.performEnhancedLogout('user_initiated', 'User clicked logout', {
        clearLocalData,
        logoutAllDevices: false
      });
      
      if (result.success) {
        console.log("[ok] Enhanced logout successful");
        window.location.replace(`login.html?logout=success&t=${Date.now()}`);
      } else {
        throw new Error(result.message);
      }
    } else {
      // Fallback to original logout
      const response = await fetch("../backend/api/logout.php", {
        method: "POST",
        credentials: "include",
        headers: (window.withDeviceTokenHeaders ? window.withDeviceTokenHeaders({ 
          "Content-Type": "application/json",
          "X-CSRF-Token": csrfToken || ""
        }) : {
          "Content-Type": "application/json",
          "X-CSRF-Token": csrfToken || ""
        }),
        body: JSON.stringify({
          logout_type: 'user_initiated',
          logout_reason: 'User clicked logout button',
          csrf_token: csrfToken
        })
      });

      let data;
      try {
        data = await response.json();
      } catch (e) {
        console.warn("Logout: Non-JSON response, fallback redirect.");
        data = { success: true };
      }

      localStorage.removeItem("loggedInUser");
      localStorage.removeItem("userRole");
      localStorage.removeItem("userRoleEffective");
      localStorage.removeItem("sessionSettings");
      sessionStorage.clear();
      window.dispatchEvent(new Event("userLoggedOut"));

      console.log("[ok] Logout successful:", data.message || "Session ended");
      window.location.replace(`login.html?logout=success&t=${Date.now()}`);
    }
  } catch (err) {
    console.error("[error] Logout error:", err);
    window.location.replace(`login.html?logout=error&t=${Date.now()}`);
  }
};

// Enhanced logout confirmation
async function showEnhancedLogoutConfirmation() {
  return new Promise((resolve) => {
    const modal = document.createElement('div');
    modal.className = 'auth-modal-overlay';
    modal.setAttribute('role', 'dialog');
    modal.setAttribute('aria-modal', 'true');
    modal.setAttribute('aria-labelledby', 'headerLogoutModalTitle');
    modal.innerHTML = `
      <div class="auth-modal">
        <div class="auth-modal-header">
          <h3 id="headerLogoutModalTitle">Confirm Logout</h3>
        </div>
        <div class="auth-modal-body">
          <p>Are you sure you want to log out?</p>
          <div class="logout-options">
            <label>
              <input type="checkbox" id="clearLocalData" checked>
              Clear local data (recommended)
            </label>
          </div>
        </div>
        <div class="auth-modal-footer logout-actions">
          <button class="auth-btn auth-btn-danger" id="confirmLogout">Yes, Logout</button>
          <button class="auth-btn auth-btn-secondary" id="cancelLogout">Cancel</button>
        </div>
      </div>
    `;
    
    document.body.appendChild(modal);
    document.body.classList.add('modal-open');
    requestAnimationFrame(() => {
      modal.querySelector('#confirmLogout')?.focus();
    });
    
    const cancelBtn = modal.querySelector('#cancelLogout');
    const confirmBtn = modal.querySelector('#confirmLogout');
    
    cancelBtn.addEventListener('click', () => {
      modal.remove();
      document.body.classList.remove('modal-open');
      window.lastLogoutOptions = null;
      resolve({ confirmed: false, options: {} });
    });
    
    confirmBtn.addEventListener('click', () => {
      const clearLocalData = modal.querySelector('#clearLocalData')?.checked ?? true;

      window.lastLogoutOptions = {
        clearLocalData,
        logoutAllSessions: false
      };
      
      modal.remove();
      document.body.classList.remove('modal-open');
      resolve({ confirmed: true, options: window.lastLogoutOptions });
    });
    
    // Close on overlay click
    modal.addEventListener('click', (e) => {
      if (e.target === modal) {
        modal.remove();
        document.body.classList.remove('modal-open');
        window.lastLogoutOptions = null;
        resolve({ confirmed: false, options: {} });
      }
    });
  });
}

// 
// Event Delegation - Enhanced
// 
function initializeEventDelegation() {
  let lastTouchTime = 0;
  const TOUCH_DELAY = 300;

  document.addEventListener("click", handleUnifiedInteraction);
  document.addEventListener("touchend", handleUnifiedInteraction, { passive: false });

  document.addEventListener("click", handleOutsideClick);
  document.addEventListener("touchend", handleOutsideTouch, { passive: false });
  document.addEventListener("keydown", handleEscapeKey);

  function handleUnifiedInteraction(e) {
    if (isProcessingClick) return;

    if (e.type === "touchend") {
      const currentTime = Date.now();
      if (currentTime - lastTouchTime < TOUCH_DELAY) return;
      lastTouchTime = currentTime;
    }

    const menuToggle = document.getElementById("menuToggle");
    const dropdownMenu = document.getElementById("dropdownMenu");
    const userProfile = document.getElementById("userProfile");
    const profileMenu = document.getElementById("profileDropdownMenu");
    const profileToggle = document.getElementById("profileDropdownToggle");
    const profilePicture = document.getElementById("profilePicture");

    // Check if click is on profile dropdown menu items
    const profileMenuItem = e.target.closest && e.target.closest('#profileDropdownMenu a');
    if (profileMenuItem) {
      e.preventDefault();
      e.stopPropagation();
      handleProfileMenuItemClick(profileMenuItem);
      return;
    }

    if (menuToggle && (e.target === menuToggle || menuToggle.contains(e.target))) {
      e.preventDefault();
      e.stopPropagation();
      isProcessingClick = true;
      toggleMainMenu();
      setTimeout(() => (isProcessingClick = false), 300);
      return;
    }

    const profileElements = [userProfile, profileToggle, profilePicture].filter(Boolean);
    const isProfileInteraction = profileElements.some(
      (el) => e.target === el || el.contains(e.target)
    );

    if (isProfileInteraction) {
      e.preventDefault();
      e.stopPropagation();
      isProcessingClick = true;
      toggleProfileDropdown();
      setTimeout(() => (isProcessingClick = false), 300);
      return;
    }
  }
}

// 
// Enhanced Profile Dropdown Menu Item Handler
// 
function handleProfileMenuItemClick(menuItem) {
  if (!menuItem) return;
  
  console.log("[info] Profile dropdown menu item clicked:", menuItem.textContent.trim());
  const storedUser = getStoredUserData();
  const normalizedRole = normalizeEquivalentRole((
    sessionStorage.getItem("userRoleEffective") ||
    storedUser.effectiveRole ||
    storedUser.roleEffective ||
    localStorage.getItem("userRoleEffective") ||
    storedUser.role ||
    localStorage.getItem("userRole") ||
    ""
  ).toLowerCase());
  
  if (menuItem.id === "logoutBtn") {
    closeProfileMenu();
    setTimeout(() => window.logoutUser(), 150);
  } else if (menuItem.dataset && menuItem.dataset.hardRefresh === "true") {
    closeProfileMenu();
    if (typeof window.performHardRefresh === "function") {
      window.performHardRefresh();
    }
  } else {
    let targetHref = menuItem.getAttribute("href") || menuItem.href || "";
    if (menuItem.id === "profileDashboardLink" && normalizedRole === "pensioner") {
      targetHref = "pensioner_lookup.html";
    }

    if (!targetHref || targetHref === "#" || targetHref === "javascript:void(0)") {
      closeProfileMenu();
      return;
    }

    // Update session activity before navigation
    if (sessionManager && typeof sessionManager.updateSessionActivity === 'function') {
      sessionManager.updateSessionActivity();
    }
    
    closeProfileMenu();
    
    setTimeout(() => {
      window.location.assign(targetHref);
    }, 200);
  }
}

// 
// Outside Click / Escape - Enhanced
// 
function handleOutsideClick(e) {
  const menuToggle = document.getElementById("menuToggle");
  const dropdownMenu = document.getElementById("dropdownMenu");
  const userProfile = document.getElementById("userProfile");
  const profileMenu = document.getElementById("profileDropdownMenu");

  if (!dropdownMenu || !profileMenu) return;

  const isProfileMenuItem = e.target.closest && e.target.closest('#profileDropdownMenu a');
  if (isProfileMenuItem) return;

  const isOutsideMainMenu =
    !menuToggle.contains(e.target) && !dropdownMenu.contains(e.target);
  const isOutsideProfileMenu =
    !userProfile.contains(e.target) && !profileMenu.contains(e.target);

  if (isOutsideMainMenu && dropdownMenu.classList.contains("visible")) closeMainMenu();
  if (isOutsideProfileMenu && profileMenu.classList.contains("visible")) closeProfileMenu();
}

function handleOutsideTouch(e) {
  const menuToggle = document.getElementById("menuToggle");
  const dropdownMenu = document.getElementById("dropdownMenu");
  const userProfile = document.getElementById("userProfile");
  const profileMenu = document.getElementById("profileDropdownMenu");

  if (!dropdownMenu || !profileMenu) return;

  const isProfileMenuItem = e.target.closest && e.target.closest('#profileDropdownMenu a');
  if (isProfileMenuItem) {
    e.preventDefault();
    return;
  }

  const isOutsideMainMenu =
    !menuToggle.contains(e.target) && !dropdownMenu.contains(e.target);
  const isOutsideProfileMenu =
    !userProfile.contains(e.target) && !profileMenu.contains(e.target);

  if (isOutsideMainMenu && dropdownMenu.classList.contains("visible")) {
    e.preventDefault();
    closeMainMenu();
  }

  if (isOutsideProfileMenu && profileMenu.classList.contains("visible")) {
    e.preventDefault();
    closeProfileMenu();
  }
}

function handleEscapeKey(e) {
  if (e.key === "Escape") {
    closeMainMenu();
    closeProfileMenu();
  }
}

// 
// Menu Toggles
// 
function toggleMainMenu() {
  const dropdownMenu = document.getElementById("dropdownMenu");
  const profileMenu = document.getElementById("profileDropdownMenu");
  if (!dropdownMenu) return;

  if (profileMenu && profileMenu.classList.contains("visible")) closeProfileMenu();
  if (dropdownMenu.classList.contains("visible")) closeMainMenu();
  else openMainMenu();
}

function toggleProfileDropdown() {
  const dropdownMenu = document.getElementById("dropdownMenu");
  const profileMenu = document.getElementById("profileDropdownMenu");
  if (!profileMenu) return;

  if (dropdownMenu && dropdownMenu.classList.contains("visible")) closeMainMenu();
  if (profileMenu.classList.contains("visible")) closeProfileMenu();
  else openProfileMenu();
}

function openMainMenu() {
  const dropdownMenu = document.getElementById("dropdownMenu");
  if (!dropdownMenu) return;
  dropdownMenu.classList.add("visible");
  dropdownMenu.classList.remove("hidden");
  openDropdown = "menu";
  console.log("[ok] Main menu opened");
}

function closeMainMenu() {
  const dropdownMenu = document.getElementById("dropdownMenu");
  if (!dropdownMenu) return;
  dropdownMenu.classList.remove("visible");
  dropdownMenu.classList.add("hidden");
  if (openDropdown === "menu") openDropdown = null;
  console.log("[ok] Main menu closed");
}

function openProfileMenu() {
  const profileMenu = document.getElementById("profileDropdownMenu");
  if (!profileMenu) return;
  profileMenu.classList.add("visible");
  profileMenu.classList.remove("hidden");
  openDropdown = "profile";
  console.log("[ok] Profile menu opened");
}

function closeProfileMenu() {
  const profileMenu = document.getElementById("profileDropdownMenu");
  if (!profileMenu) return;
  profileMenu.classList.remove("visible");
  profileMenu.classList.add("hidden");
  if (openDropdown === "profile") openDropdown = null;
  console.log("[ok] Profile menu closed");
}

// 
// Desktop Hover
// 
function initializeDesktopHover() {
  if (window.innerWidth > 768) {
    const menuToggle = document.getElementById("menuToggle");
    const dropdownMenu = document.getElementById("dropdownMenu");
    const userProfile = document.getElementById("userProfile");
    const profileMenu = document.getElementById("profileDropdownMenu");

    let mainMenuTimeout;
    let profileMenuTimeout;

    if (menuToggle && dropdownMenu) {
      menuToggle.addEventListener("mouseenter", () => {
        clearTimeout(mainMenuTimeout);
        openMainMenu();
      });
      dropdownMenu.addEventListener("mouseenter", () => clearTimeout(mainMenuTimeout));
      menuToggle.addEventListener("mouseleave", () => {
        mainMenuTimeout = setTimeout(() => {
          if (!dropdownMenu.matches(":hover")) closeMainMenu();
        }, 150);
      });
      dropdownMenu.addEventListener("mouseleave", () => {
        mainMenuTimeout = setTimeout(closeMainMenu, 150);
      });
    }

    if (userProfile && profileMenu) {
      userProfile.addEventListener("mouseenter", () => {
        clearTimeout(profileMenuTimeout);
        openProfileMenu();
      });
      profileMenu.addEventListener("mouseenter", () => clearTimeout(profileMenuTimeout));
      userProfile.addEventListener("mouseleave", () => {
        profileMenuTimeout = setTimeout(() => {
          if (!profileMenu.matches(":hover")) closeProfileMenu();
        }, 150);
      });
      profileMenu.addEventListener("mouseleave", () => {
        profileMenuTimeout = setTimeout(closeProfileMenu, 150);
      });
    }
    console.log("[ok] Desktop hover behavior initialized");
  }
}
setTimeout(initializeDesktopHover, 1000);

// 
// Enhanced Profile Display with Session Info
// 
function initializeUserProfileDisplay() {
  updateUserProfile();
  initializeSessionInfoDisplay();
}

export function updateUserProfile() {
  const userData = getStoredUserData();
  const profileName = document.getElementById("profileName");
  const profilePicture = document.getElementById("profilePicture");
  const photoValue = getUserPhotoValue(userData);

  if (profileName) {
    profileName.textContent = userData.name || sessionStorage.getItem("userName") || "User";
  }
  if (profilePicture) {
    const imgSrc = resolveImagePath(photoValue);
    profilePicture.src = imgSrc;
    profilePicture.onerror = () => {
      profilePicture.onerror = null;
      profilePicture.src = getDefaultProfileImage();
    };
  }
}

// Initialize session info display
function initializeSessionInfoDisplay() {
  const sessionInfoElement = document.getElementById('sessionInfo');
  if (!sessionInfoElement) return;
  
  // Update session info periodically
  setInterval(() => {
    updateSessionInfoDisplay();
  }, 60000); // Update every minute
  
  // Initial update
  updateSessionInfoDisplay();
}

function updateSessionInfoDisplay() {
  const sessionInfoElement = document.getElementById('sessionInfo');
  if (!sessionInfoElement) return;
  
  const lastActivity = sessionStorage.getItem('lastActivity');
  const sessionTimeout = sessionStorage.getItem('sessionTimeout') || 1800;
  
  if (lastActivity) {
    const elapsed = Math.floor((Date.now() - parseInt(lastActivity)) / 1000);
    const remaining = Math.max(0, sessionTimeout - elapsed);
    const minutes = Math.floor(remaining / 60);
    const seconds = remaining % 60;
    
    sessionInfoElement.innerHTML = `
      <div class="session-info">
        <small>Session expires in: ${minutes}:${seconds.toString().padStart(2, '0')}</small>
      </div>
    `;
  }
}

function resolveImagePath(imagePath) {
  if (!imagePath || typeof imagePath !== "string") return getDefaultProfileImage();

  const normalized = imagePath.trim();
  if (!normalized) return getDefaultProfileImage();

  if (normalized.startsWith("http://") || normalized.startsWith("https://") || normalized.startsWith("data:")) {
    return normalized;
  }

  if (normalized.includes("backend/api/get_image.php")) {
    return normalized;
  }

  if (normalized === "images/default-user.png" || normalized.endsWith("/default-user.png")) {
    return getDefaultProfileImage();
  }

  // Handle plain filenames and known relative upload paths consistently.
  const filename = normalized.split(/[\\/]/).pop();
  if (!filename) return getDefaultProfileImage();

  return `../backend/api/get_image.php?file=${encodeURIComponent(filename)}&type=profile`;
}

function getDefaultProfileImage() {
  return "../backend/api/get_image.php?file=default-user.png&type=profile";
}

function getStoredUserData() {
  try {
    return JSON.parse(localStorage.getItem("loggedInUser") || "{}");
  } catch (error) {
    console.warn("Could not parse loggedInUser from localStorage:", error);
    return {};
  }
}

function getUserPhotoValue(userData) {
  if (!userData || typeof userData !== "object") return "";
  return (
    userData.photo ||
    userData.userPhoto ||
    userData.user_photo ||
    sessionStorage.getItem("userPhoto") ||
    ""
  );
}

// 
// Role-Based Menu Visibility
// 
function initializeMenuVisibility() {
  updateMenuVisibility();
}

export function updateMenuVisibility() {
  const userData = JSON.parse(localStorage.getItem("loggedInUser") || "{}");
  const role = (
    sessionStorage.getItem("userRole") ||
    userData.role ||
    localStorage.getItem("userRole") ||
    ""
  ).toLowerCase();
  const effectiveRole = (
    sessionStorage.getItem("userRoleEffective") ||
    userData.effectiveRole ||
    userData.roleEffective ||
    localStorage.getItem("userRoleEffective") ||
    ""
  ).toLowerCase();
  console.log("Menu visibility for:", role, "effective:", effectiveRole);

  const normalizedRole = normalizeEquivalentRole(effectiveRole || role);
  const menuPageMap = {
    myTasksMenuItem: "tasks.html",
    messagesMenuItem: "messages.html",
    settingsMenuItem: "admin_dashboard.html",
    usersMenuItem: "users.html",
    staffDueMenuItem: "staff_due.html",
    pensionRegistryMenuItem: "pension_file_registry.html",
    applicationStatusMenuItem: "application_status.html",
    fileTrackingMenuItem: "file_tracking.html",
    claimFormMenuItem: "claim_form.html",
    claimsMenuItem: "claims.html",
    budgetMenuItem: "budgeting.html",
    benefitsCalculatorMenuItem: "benefits_calculator.html",
    podcastMenuItem: "podcast.html"
  };

  Object.entries(menuPageMap).forEach(([itemId, pageName]) => {
    setMenuItemVisibility(itemId, isHeaderPageAccessibleForRole(pageName, normalizedRole));
  });

  setMenuItemVisibility("profileViewMenuItem", true);
  setMenuItemVisibility("profileEditMenuItem", true);
  setMenuItemVisibility(
    "profileDashboardMenuItem",
    normalizedRole === "pensioner" || isHeaderPageAccessibleForRole("dashboard.html", normalizedRole)
  );
  setMenuItemVisibility("feedbackMenuItem", true);
  setMenuItemVisibility("termsMenuItem", true);

  const dashboardHref = getRoleDashboardHref(normalizedRole);
  const dashboardLink = document.getElementById("profileDashboardLink");
  const dashboardMenuLink = document.querySelector("#dashboardMenuItem a");
  if (dashboardLink) {
    dashboardLink.setAttribute("href", normalizedRole === "pensioner" ? "pensioner_lookup.html" : dashboardHref);
    dashboardLink.textContent = normalizedRole === "pensioner" ? "Find Pensioners" : "Dashboard";
  }
  const dashboardAllowed = isHeaderPageAccessibleForRole("dashboard.html", normalizedRole);
  setMenuItemVisibility("dashboardMenuItem", dashboardAllowed);
  if (dashboardMenuLink) {
    dashboardMenuLink.setAttribute("href", dashboardHref);
  }
}

function setMenuItemVisibility(itemId, isVisible) {
  const element = document.getElementById(itemId);
  if (!element) return;
  element.style.display = isVisible ? "" : "none";
}

function normalizeEquivalentRole(role) {
  const normalized = (role || "").toLowerCase();
  if (normalized === "super_admin") {
    return "admin";
  }
  return ["dep_oc", "deputy_oc", "deputy_oc_pen", "deputy_oc_pension"].includes(normalized)
    ? "oc_pen"
    : normalized;
}

function getRoleDashboardHref(role) {
  if (role === "pensioner") return "pensioner_board.html";
  return "dashboard.html";
}

function isHeaderPageAccessibleForRole(pageName, role) {
  const page = String(pageName || "").trim().toLowerCase();
  const normalizedRole = normalizeEquivalentRole(role);
  if (page === "dashboard.html" && normalizedRole && !["user", "pensioner"].includes(normalizedRole)) {
    return true;
  }
  const claimsPages = ["claim_form.html", "claims.html", "budgeting.html"];
  const sharedToolsPages = ["benefits_calculator.html", "podcast.html", "document_viewer.html"];
  const rules = {
    super_admin: () => true,
    admin: () => true,
    clerk: (p) => ["file_registry.html", "pension_file_registry.html", "staff_due.html", "add_staff.html", "edit_staff.html", "view_staff.html", "tasks.html", "file_tracking.html", "application_status.html", "profile.html", "messages.html", "reports.html", "dashboard.html", "users.html", "admin_dashboard.html"].concat(claimsPages, sharedToolsPages).includes(p),
    oc_pen: (p) => ["pension_file_registry.html", "staff_due.html", "add_staff.html", "view_staff.html", "tasks.html", "file_tracking.html", "application_status.html", "profile.html", "messages.html", "reports.html", "dashboard.html"].concat(claimsPages, sharedToolsPages).includes(p),
    writeup_officer: (p) => ["pension_file_registry.html", "staff_due.html", "add_staff.html", "edit_staff.html", "view_staff.html", "tasks.html", "file_tracking.html", "application_status.html", "profile.html", "messages.html", "reports.html", "dashboard.html"].concat(claimsPages, sharedToolsPages).includes(p),
    file_creator: (p) => ["pension_file_registry.html", "tasks.html", "file_tracking.html", "application_status.html", "profile.html", "messages.html", "dashboard.html"].concat(claimsPages, sharedToolsPages).includes(p),
    data_entry: (p) => ["file_registry.html", "pension_file_registry.html", "tasks.html", "staff_due.html", "add_staff.html", "edit_staff.html", "view_staff.html", "file_tracking.html", "application_status.html", "profile.html", "messages.html", "dashboard.html"].concat(claimsPages, sharedToolsPages).includes(p),
    assessor: (p) => ["pension_file_registry.html", "tasks.html", "file_tracking.html", "application_status.html", "profile.html", "messages.html", "dashboard.html"].concat(claimsPages, sharedToolsPages).includes(p),
    auditor: (p) => ["pension_file_registry.html", "tasks.html", "file_tracking.html", "application_status.html", "profile.html", "messages.html", "dashboard.html"].concat(claimsPages, sharedToolsPages).includes(p),
    approver: (p) => ["pension_file_registry.html", "tasks.html", "file_tracking.html", "application_status.html", "profile.html", "messages.html", "dashboard.html"].concat(claimsPages, sharedToolsPages).includes(p),
    pensioner: (p) => ["pensioner_board.html", "pensioner_lookup.html", "profile.html", "edit_user.html"].concat(sharedToolsPages).includes(p),
    user: (p) => ["dashboard.html", "pension_file_registry.html", "application_status.html", "profile.html", "faq.html", "about.html"].concat(claimsPages, sharedToolsPages).includes(p)
  };

  return (rules[normalizedRole] || (() => false))(page);
}

// 
// Dynamic Counts (messages/tasks)
// 
function initializeDynamicCounts() {
  loadUnreadMessageCount();
  loadTaskCount();
  setInterval(loadUnreadMessageCount, 120000);
  setInterval(loadTaskCount, 120000);
}

async function loadUnreadMessageCount() {
  try {
    const response = await fetch("../backend/api/get_unread_count.php", {
      credentials: "include",
    });
    const data = await response.json();

    if (data.success) {
      const messageBubble = document.querySelector(".message-bubble");
      if (messageBubble) {
        const total = data.unread_count;
        if (total > 0) {
          messageBubble.textContent = total > 99 ? "99+" : total;
          messageBubble.classList.remove("hidden");
        } else {
          messageBubble.classList.add("hidden");
        }
      }
    }
  } catch (error) {
    console.error("Error loading unread count:", error);
  }
}

function loadTaskCount() {
  fetch("../backend/api/get_task_count.php", {
    credentials: "include"
  })
    .then((response) => response.json())
    .then((data) => {
      if (!data.success) return;
      const taskBubble = document.querySelector(".task-bubble");
      if (!taskBubble) return;
      const total = Number(data.taskCount) || 0;
      if (total > 0) {
        taskBubble.textContent = total > 99 ? "99+" : total;
        taskBubble.classList.remove("hidden");
      } else {
        taskBubble.classList.add("hidden");
      }
    })
    .catch((error) => {
      console.error("Error loading task count:", error);
    });
}

// 
// Menu Links & Logout Modal - Enhanced
// 
function initializeMenuLinks() {
  const dropdownMenu = document.getElementById("dropdownMenu");
  if (dropdownMenu && !dropdownMenu.dataset.publicSessionGuardBound) {
    dropdownMenu.dataset.publicSessionGuardBound = "true";
    dropdownMenu.addEventListener("click", (event) => {
      const link = event.target.closest("a[href]");
      if (!link) return;

      try {
        const targetUrl = new URL(link.getAttribute("href"), window.location.href);
        const targetPage = (targetUrl.pathname.split("/").pop() || "").toLowerCase();
        if (["feedback.html", "terms.html"].includes(targetPage)) {
          if (typeof window.rememberLastSecurePage === "function") {
            window.rememberLastSecurePage(window.location.href);
          }
          if (typeof window.rememberAuthenticatedPublicAllowance === "function") {
            window.rememberAuthenticatedPublicAllowance(targetPage);
          }
        }
      } catch (_error) {
        // Ignore malformed navigation targets.
      }
    });
  }

  console.log("[ok] Profile dropdown menu links configured via event delegation");
}

// 
// Logged Out UI Reset & Public API
// 
function handleUserLoggedOut() {
  const profileName = document.getElementById("profileName");
  const profilePicture = document.getElementById("profilePicture");
  const sessionInfo = document.getElementById("sessionInfo");
  
  if (profileName) profileName.textContent = "User";
  if (profilePicture) profilePicture.src = getDefaultProfileImage();
  if (sessionInfo) sessionInfo.innerHTML = "";
  
  closeMainMenu();
  closeProfileMenu();
  console.log("[info] User logged out - UI reset");
}

export function refreshHeaderData() {
  updateUserProfile();
  updateMenuVisibility();
  loadUnreadMessageCount();
  loadTaskCount();
  updateSessionInfoDisplay();
  console.log("[info] Header data refreshed");
}

export function clearUserData() {
  localStorage.removeItem("loggedInUser");
  localStorage.removeItem("userRole");
  localStorage.removeItem("userRoleEffective");
  localStorage.removeItem("sessionSettings");
  sessionStorage.clear();
  handleUserLoggedOut();
  console.log("[cleanup] All user data cleared");
}

// Export for testing
if (typeof module !== 'undefined' && module.exports) {
  module.exports = {
    initHeaderInteractions,
    updateUserProfile,
    refreshHeaderData,
    clearUserData
  };
}


