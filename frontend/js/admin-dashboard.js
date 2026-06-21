// 
// admin-dashboard.js
// Admin dashboard functionality and backend integration
// 
class AdminDashboard {
    constructor() {
        this.currentSection = 'dashboard';
        this.currentData = {};
        this.isAdmin = false;
        this.adminReauthVerifiedAt = 0;
        this.adminReauthWindowSeconds = 0;
        this.adminStepUpCacheKey = 'pensionsgo_admin_step_up_verified';
        this.roleLabelMap = {};
        this.availableRoles = [];
        this.adminSearchIndex = this.buildAdminSearchIndex();
        this.activeAdminSearchResults = [];
        this.pendingSearchTarget = null;
        this.roleSettingsState = null;
        this.appSettingsCache = null;
        this.appSettingsRequest = null;
        this.appSettingsFetchedAt = 0;
        this.importDataState = {
            datasets: [],
            runs: [],
            activeDatasetKey: null,
            lastReport: null
        };
        this.activeSoundPreview = null;
        this.init();
    }

    async init() {
        try {
            await this.verifyAdminAccess();
            if (!this.isAdmin) {
                return;
            }
            const entryVerified = await this.requireAdminDashboardEntryVerification();
            if (!entryVerified) {
                return;
            }
            this.revealAdminDashboard();
            await this.initializeDashboard();
            Promise.allSettled([
                this.syncRoleCacheFromApi(),
                this.applyPublicSettings()
            ]);
            this.setupEventListeners();
            this.loadInitialData();
            this.addResizeListener();
            
            // Initialize mobile after header loads
            this.initializeMobileAfterHeader();

            // Debug info
            this.debugDashboard();
            this.debugSubmenus();
            
            document.getElementById('adminDashboard').classList.add('loaded');
            
        } catch (error) {
            console.error('Admin dashboard initialization failed:', error);
            this.showErrorState('Failed to initialize admin dashboard. Please refresh the page.');
        }
    }

    // Debug dashboard
    debugDashboard() {
        console.group('Admin Dashboard Debug Info');
        console.log('Current User Role:', localStorage.getItem('userRole'));
        console.log('Logged In User:', localStorage.getItem('loggedInUser'));
        console.log('Is Admin Verified:', this.isAdmin);
        console.log('Current Section:', this.currentSection);
        
        // Check required elements
        const requiredElements = [
            'adminDashboard', 'securityOverlay', 'adminAccessDenied',
            'adminSidebar', 'contentBody'
        ];
        
        requiredElements.forEach(id => {
            const element = document.getElementById(id);
            console.log(`Element ${id}:`, element ? 'Found' : 'Missing');
        });
        
        console.groupEnd();
    }

    // Debug method to check if submenus are working
    debugSubmenus() {
        console.group('Submenu Debug Info');
        
        const submenuParents = document.querySelectorAll('.has-submenu');
        console.log('Submenu parents found:', submenuParents.length);
        
        submenuParents.forEach((parent, index) => {
            const link = parent.querySelector('.nav-link');
            const submenu = parent.querySelector('.submenu');
            const arrow = parent.querySelector('.nav-arrow');
            
            console.log(`Submenu ${index + 1}:`, {
                hasLink: !!link,
                hasSubmenu: !!submenu,
                hasArrow: !!arrow,
                submenuItems: submenu ? submenu.querySelectorAll('.submenu-link').length : 0,
                isOpen: parent.classList.contains('open')
            });
        });
        
        console.groupEnd();
    }

    // Verify admin access
    async verifyAdminAccess() {
        try {
            const response = await fetch('../backend/api/verify_admin.php', {
                credentials: 'include'
            });
            const data = await response.json();

            if (data.success && data.is_admin) {
                this.isAdmin = true;
                this.rememberServerAdminReauth(data);
                this.updateAdminInfo(data);
            } else {
                this.showAccessDenied();
            }
        } catch (error) {
            console.error('Admin verification failed:', error);
            this.showAccessDenied();
        }
    }

    revealAdminDashboard() {
        const securityOverlay = document.getElementById('securityOverlay');
        const dashboard = document.getElementById('adminDashboard');
        if (securityOverlay) {
            securityOverlay.style.display = 'none';
        }
        if (dashboard) {
            dashboard.style.display = 'block';
        }
    }

    rememberServerAdminReauth(data = {}) {
        const verifiedAt = Number(data.admin_reauth_verified_at || data.verified_at || 0);
        const windowSeconds = Number(data.admin_reauth_window_seconds || data.reauth_window_seconds || 0);
        if (windowSeconds > 0) {
            this.adminReauthWindowSeconds = windowSeconds;
        }
        if (verifiedAt > 0) {
            this.adminReauthVerifiedAt = verifiedAt;
        }
        if (data.admin_reauth_verified || data.valid) {
            this.markAdminStepUpFresh(verifiedAt || Math.floor(Date.now() / 1000), windowSeconds || this.adminReauthWindowSeconds || 600);
        }
    }

    getAdminStepUpCache() {
        try {
            return JSON.parse(sessionStorage.getItem(this.adminStepUpCacheKey) || '{}') || {};
        } catch (_error) {
            return {};
        }
    }

    hasFreshAdminStepUp() {
        const now = Math.floor(Date.now() / 1000);
        const serverWindow = Math.max(0, Number(this.adminReauthWindowSeconds || 0));
        if (this.adminReauthVerifiedAt > 0 && serverWindow > 0 && now - this.adminReauthVerifiedAt <= serverWindow) {
            return true;
        }
        const cached = this.getAdminStepUpCache();
        const expiresAt = Number(cached.expiresAt || 0);
        return expiresAt > now;
    }

    markAdminStepUpFresh(verifiedAt = Math.floor(Date.now() / 1000), windowSeconds = 600) {
        const safeWindow = Math.max(60, Math.min(3600, Number(windowSeconds || this.adminReauthWindowSeconds || 600)));
        const issuedAt = Number(verifiedAt || Math.floor(Date.now() / 1000));
        this.adminReauthVerifiedAt = issuedAt;
        this.adminReauthWindowSeconds = safeWindow;
        try {
            sessionStorage.setItem(this.adminStepUpCacheKey, JSON.stringify({
                verifiedAt: issuedAt,
                expiresAt: issuedAt + safeWindow
            }));
        } catch (_error) {}
    }

    clearAdminStepUpCache() {
        this.adminReauthVerifiedAt = 0;
        try {
            sessionStorage.removeItem(this.adminStepUpCacheKey);
        } catch (_error) {}
    }

    isSensitiveSettingsSection(section) {
        return new Set([
            'system-settings',
            'app-settings',
            'security-settings',
            'access-control',
            'role-settings',
            'notification-settings',
            'live-chat-settings',
            'podcast-settings',
            'title-settings',
            'bank-settings',
            'unit-settings',
            'prison-district-settings',
            'prison-region-settings',
            'political-district-settings',
            'faq-settings',
            'terms-settings'
        ]).has(String(section || '').trim().toLowerCase());
    }

    async requireSettingsSectionReauth(section, actionVerb = 'open') {
        if (!this.isSensitiveSettingsSection(section)) {
            return true;
        }
        return await this.promptForAdminReauth(`${actionVerb} ${this.getSectionTitle(section)}`, { force: true });
    }

    async requireAdminDashboardEntryVerification() {
        if (this.hasFreshAdminStepUp()) {
            return true;
        }
        const verified = await this.promptForAdminReauth('open the Admin Console');
        if (verified) {
            return true;
        }
        window.location.href = this.getSafeRedirectUrl();
        return false;
    }

    // Update admin information in sidebar
    updateAdminInfo(adminData) {
        const currentUser = JSON.parse(localStorage.getItem('loggedInUser') || '{}');

        const adminName = document.getElementById('adminName');
        if (adminName) {
            adminName.textContent = currentUser.name || 'Administrator';
        }

        const adminRole = document.getElementById('adminRole');
        if (adminRole) {
            adminRole.textContent = adminData?.is_super_admin ? 'Super Administrator' : 'Administrator';
        }

        // Enhanced avatar handling with correct paths
        const adminAvatar = document.getElementById('adminAvatar');
        const avatarImg = adminAvatar?.querySelector('img');
        if (!adminAvatar || !avatarImg) {
            return;
        }
        
        if (currentUser.photo && currentUser.photo !== 'images/default-user.png') {
            // Clean the photo path and create correct URLs
            const cleanPhotoPath = this.cleanImagePath(currentUser.photo);
            const imagePaths = this.getImagePaths(cleanPhotoPath);
            
            this.loadImageWithFallbacks(avatarImg, imagePaths);
        } else {
            // Use the corrected default user image path
            avatarImg.src = '../backend/uploads/profiles/default-user.png';
        }
    }

    // Clean image path by removing any "../" and normalizing
    cleanImagePath(photoPath) {
        if (!photoPath) return '';
        
        // Remove any "../" prefixes and normalize the path
        let cleanPath = photoPath.replace(/\.\.\//g, '');
        
        // Strip backend/uploads prefixes to get the filename
        cleanPath = cleanPath.replace(/^backend\/uploads\/profiles\//, '');
        cleanPath = cleanPath.replace(/^uploads\/profiles\//, '');
        
        // If it starts with "uploads/", remove that too since we'll reconstruct the path
        cleanPath = cleanPath.replace(/^uploads\//, '');
        
        // If it starts with "profiles/", just get the filename
        if (cleanPath.startsWith('profiles/')) {
            cleanPath = cleanPath.replace('profiles/', '');
        }
        
        return cleanPath;
    }

    // Get correct image paths for fallback loading
    getImagePaths(filename) {
        if (!filename) return [];
        
        return [
            // Direct backend path
            `../backend/uploads/profiles/${filename}`,
            // API endpoint with proper parameters
            `../backend/api/get_image.php?file=${encodeURIComponent(filename)}&type=profile`,
            // Alternative API endpoint format
            `../backend/api/get_image.php?filename=${encodeURIComponent(filename)}`,
            // Default fallback
            '../backend/uploads/profiles/default-user.png'
        ];
    }

    // Helper method to handle image loading with fallbacks
    loadImageWithFallbacks(imgElement, paths, index = 0) {
        if (index >= paths.length) {
            console.warn('All image fallbacks failed');
            imgElement.src = '../backend/uploads/profiles/default-user.png';
            return;
        }
        
        const testImage = new Image();
        testImage.onload = () => {
            console.log('Profile image loaded:', paths[index]);
            imgElement.src = paths[index];
        };
        testImage.onerror = () => {
            console.log('Image failed:', paths[index]);
            this.loadImageWithFallbacks(imgElement, paths, index + 1);
        };
        testImage.src = paths[index];
    }

    // Initialize dashboard
    async initializeDashboard() {
        this.setupNavigation();
    }

    async applyPublicSettings() {
        try {
            const response = await fetch('../backend/api/get_public_settings.php', {
                credentials: 'include',
                cache: 'no-store',
                headers: { 'Accept': 'application/json' }
            });
            if (!response.ok) return;

            const data = await response.json();
            if (!data.success || !data.settings) return;

            const appName = (data.settings.app_name || '').trim();
            if (!appName) return;

            if (document.title.includes('PensionsGo')) {
                document.title = document.title.replace(/PensionsGo/g, appName);
            }

            const brandTitle = document.querySelector('.admin-brand h2');
            if (brandTitle) {
                brandTitle.textContent = `${appName} Admin Console`;
            }
        } catch (error) {
            console.warn('Unable to apply public settings:', error.message);
        }
    }

    // Setup event listeners
    setupEventListeners() {
        this.initializeAdminSearchDropdown();

        // Refresh button
        document.getElementById('refreshData').addEventListener('click', () => {
            this.refreshCurrentSection();
        });

        // Search functionality
        const adminSearchInput = document.getElementById('adminSearch');
        adminSearchInput?.addEventListener('input', (e) => {
            this.handleSearch(e.target.value);
        });
        adminSearchInput?.addEventListener('focus', (e) => {
            if ((e.target.value || '').trim().length >= 2) {
                this.handleSearch(e.target.value);
            }
        });
        adminSearchInput?.addEventListener('keydown', (e) => {
            if (e.key === 'Escape') {
                this.hideAdminSearchResults();
                e.target.blur();
                return;
            }

            if (e.key === 'Enter' && this.activeAdminSearchResults.length) {
                e.preventDefault();
                this.openAdminSearchResult(this.activeAdminSearchResults[0]);
            }
        });

        document.addEventListener('click', (event) => {
            const searchWrap = event.target.closest('.admin-search');
            if (!searchWrap) {
                this.hideAdminSearchResults();
            }
        });

        window.addEventListener('resize', () => {
            this.positionAdminSearchResults();
        });

        window.addEventListener('scroll', () => {
            this.positionAdminSearchResults();
        }, true);

        // Enhanced security verification
        const verifyAdminBtn = document.getElementById('verifyAdmin');
        if (verifyAdminBtn) {
            verifyAdminBtn.addEventListener('click', async () => {
                await this.handleSecurityVerification();
            });
        }

        const cancelAccessBtn = document.getElementById('cancelAccess');
        if (cancelAccessBtn) {
            cancelAccessBtn.addEventListener('click', () => {
                window.location.href = this.getSafeRedirectUrl();
            });
        }

        // Access denied actions
        document.getElementById('goToDashboard')?.addEventListener('click', () => {
            window.location.href = 'dashboard.html';
        });

        document.getElementById('logoutFromDenied')?.addEventListener('click', () => {
            this.performLogout();
        });
    }

    initializeAdminSearchDropdown() {
        const container = document.getElementById('adminSearchResults');
        const input = document.getElementById('adminSearch');

        if (input) {
            input.value = '';

            const unlockSearchInput = () => {
                input.removeAttribute('readonly');
            };

            input.addEventListener('focus', unlockSearchInput, { once: true });
            input.addEventListener('pointerdown', unlockSearchInput, { once: true });
        }

        if (!container || container.dataset.portalReady === '1') {
            return;
        }

        container.dataset.portalReady = '1';
        document.body.appendChild(container);
        this.positionAdminSearchResults();
    }

    positionAdminSearchResults() {
        const container = document.getElementById('adminSearchResults');
        const input = document.getElementById('adminSearch');
        if (!container || !input || container.hidden) {
            return;
        }

        const rect = input.getBoundingClientRect();
        const viewportPadding = 12;
        const availableWidth = window.innerWidth - (viewportPadding * 2);
        const desiredWidth = Math.min(Math.max(rect.width, 320), 440, availableWidth);
        const left = Math.max(viewportPadding, Math.min(rect.left, window.innerWidth - desiredWidth - viewportPadding));
        const top = Math.min(rect.bottom + 10, window.innerHeight - viewportPadding);
        const maxHeight = Math.max(220, window.innerHeight - top - viewportPadding);

        container.style.left = `${left}px`;
        container.style.top = `${top}px`;
        container.style.width = `${desiredWidth}px`;
        container.style.maxHeight = `${maxHeight}px`;
    }

    // Enhanced security verification
    async handleSecurityVerification() {
        try {
            const verifyBtn = document.getElementById('verifyAdmin');
            const btnText = verifyBtn?.querySelector('.btn-text');
            const btnSpinner = verifyBtn?.querySelector('.btn-spinner');
            
            if (btnText && btnSpinner) {
                btnText.style.display = 'none';
                btnSpinner.style.display = 'inline-block';
            }
            if (verifyBtn) {
                verifyBtn.disabled = true;
            }

            const verified = await this.promptForAdminReauth('open the Admin Console');
            if (verified) {
                this.revealAdminDashboard();
                await this.initializeDashboard();
            } else {
                window.location.href = this.getSafeRedirectUrl();
            }
        } catch (error) {
            console.error('Security verification failed:', error);
            this.showAccessDenied();
        } finally {
            const verifyBtn = document.getElementById('verifyAdmin');
            const btnText = verifyBtn?.querySelector('.btn-text');
            const btnSpinner = verifyBtn?.querySelector('.btn-spinner');
            if (btnText && btnSpinner) {
                btnText.style.display = '';
                btnSpinner.style.display = 'none';
            }
            if (verifyBtn) {
                verifyBtn.disabled = false;
            }
        }
    }

    // Get safe redirect URL
    getSafeRedirectUrl() {
        const userRole = localStorage.getItem('userRole') || 'user';
        const rolePages = {
            'super_admin': 'dashboard.html',
            'admin': 'dashboard.html',
            'clerk': 'pension_file_registry.html',
            'pensioner': 'pensioner_board.html',
            'user': 'dashboard.html'
        };
        return rolePages[userRole] || 'dashboard.html';
    }

    // Setup navigation - FIXED VERSION with mobile support
    setupNavigation() {
        // Setup mobile menu first
        this.setupMobileMenu();
        
        // Main navigation links (non-submenu items)
        document.querySelectorAll('.nav-link').forEach(link => {
            // Only attach click handlers to non-submenu parent links
            const isSubmenuParent = link.parentElement.classList.contains('has-submenu');
            
            if (!isSubmenuParent) {
                link.addEventListener('click', (e) => {
                    e.preventDefault();
                    const section = link.getAttribute('data-section');
                    this.navigateToSection(section);
                    
                    // Update active state
                    document.querySelectorAll('.nav-link').forEach(l => l.classList.remove('active'));
                    document.querySelectorAll('.submenu-link').forEach(l => l.classList.remove('active'));
                    link.classList.add('active');
                    
                    // Close mobile sidebar after navigation
                    this.closeMobileSidebar();
                });
            }
        });

        // Submenu links
        document.querySelectorAll('.submenu-link').forEach(link => {
            link.addEventListener('click', (e) => {
                e.preventDefault();
                const section = link.getAttribute('data-section');
                this.navigateToSection(section);
                
                // Update active states
                document.querySelectorAll('.nav-link').forEach(l => l.classList.remove('active'));
                document.querySelectorAll('.submenu-link').forEach(l => l.classList.remove('active'));
                link.classList.add('active');
                
                // Highlight parent menu item
                const parentMenu = link.closest('.has-submenu');
                if (parentMenu) {
                    const parentLink = parentMenu.querySelector('.nav-link');
                    if (parentLink) {
                        parentLink.classList.add('active');
                    }
                }
                
                // Close mobile sidebar after navigation
                this.closeMobileSidebar();
            });
        });

        // Submenu toggle - FIXED VERSION
        document.querySelectorAll('.has-submenu > .nav-link').forEach(link => {
            link.addEventListener('click', (e) => {
                e.preventDefault();
                e.stopPropagation();
                
                const parent = link.parentElement;
                
                // Close other open submenus
                document.querySelectorAll('.has-submenu').forEach(otherParent => {
                    if (otherParent !== parent) {
                        otherParent.classList.remove('open');
                    }
                });
                
                // Toggle current submenu
                parent.classList.toggle('open');
            });
        });

        // Close submenus when clicking outside (desktop only)
        document.addEventListener('click', (e) => {
            if (window.innerWidth > 992 && !e.target.closest('.has-submenu')) {
                document.querySelectorAll('.has-submenu').forEach(parent => {
                    parent.classList.remove('open');
                });
            }
        });

        console.log('Navigation setup complete');
    }

    // Setup mobile menu functionality
    setupMobileMenu() {
        // Only setup mobile menu if we're on mobile
        if (window.innerWidth > 992) return;
        
        // Check if mobile toggle already exists
        if (document.querySelector('.mobile-menu-toggle')) {
            return;
        }
        
        // Create mobile toggle button
        const mobileToggle = document.createElement('button');
        mobileToggle.className = 'mobile-menu-toggle';
        mobileToggle.innerHTML = '\u203A'; // Right arrow when closed
        mobileToggle.setAttribute('aria-label', 'Toggle navigation menu');
        mobileToggle.setAttribute('title', 'Open Menu');
        
        // Create overlay - ONLY covers area outside sidebar
        const overlay = document.createElement('div');
        overlay.className = 'mobile-overlay';
        
        // Add close button to sidebar
        const sidebar = document.getElementById('adminSidebar');
        const closeButton = document.createElement('button');
        closeButton.className = 'sidebar-close';
        closeButton.innerHTML = '&times;';
        closeButton.setAttribute('aria-label', 'Close menu');
        
        // Insert close button at the beginning of sidebar
        if (sidebar) {
            sidebar.insertBefore(closeButton, sidebar.firstChild);
            
            // Add elements to DOM
            document.body.appendChild(mobileToggle);
            document.body.appendChild(overlay);
            
            // Calculate header height for proper positioning
            this.updateMobileTogglePosition();
            
            // Toggle sidebar
            mobileToggle.addEventListener('click', (e) => {
                e.stopPropagation();
                const isOpen = sidebar.classList.contains('mobile-open');
                if (isOpen) {
                    this.closeMobileSidebar();
                } else {
                    this.openMobileSidebar();
                }
            });
            
            // Close sidebar with close button
            closeButton.addEventListener('click', () => {
                this.closeMobileSidebar();
            });
            
            // Close sidebar when clicking on overlay (outside sidebar)
            overlay.addEventListener('click', (e) => {
                this.closeMobileSidebar();
            });
            
            // Close sidebar with Escape key
            document.addEventListener('keydown', (e) => {
                if (e.key === 'Escape') {
                    this.closeMobileSidebar();
                }
            });
            
            // Update position on resize
            window.addEventListener('resize', () => {
                this.updateMobileTogglePosition();
                if (window.innerWidth > 992) {
                    this.closeMobileSidebar();
                }
            });
        }
    }

    
    // Update mobile toggle position based on header height
    updateMobileTogglePosition() {
        const mobileToggle = document.querySelector('.mobile-menu-toggle');
        const header = document.getElementById('mainHeader');
        const footer = document.querySelector('footer');
        const root = document.documentElement;
        const sidebar = document.getElementById('adminSidebar');
        const overlay = document.querySelector('.mobile-overlay');
        
        if (mobileToggle && header) {
            const headerHeight = Math.max(0, Math.round(header.getBoundingClientRect().height));
            const footerHeight = footer ? Math.max(0, Math.round(footer.getBoundingClientRect().height)) : 0;
            root.style.setProperty('--admin-mobile-header-height', `${headerHeight}px`);
            root.style.setProperty('--admin-mobile-footer-height', `${footerHeight}px`);
            mobileToggle.style.top = `${headerHeight + 10}px`; // 10px below header

            if (window.innerWidth <= 992 && sidebar) {
                const viewportHeight = window.innerHeight || document.documentElement.clientHeight || 0;
                const sidebarHeight = Math.max(0, viewportHeight - headerHeight - footerHeight);
                sidebar.style.top = `${headerHeight}px`;
                sidebar.style.height = `${sidebarHeight}px`;
                if (overlay) {
                    overlay.style.top = `${headerHeight}px`;
                    overlay.style.height = `${sidebarHeight}px`;
                }
            }
        }
    }

    // Prevent header interference with mobile menu
    preventHeaderInterference() {
        const header = document.getElementById('mainHeader');
        if (header) {
            // Prevent header clicks from closing mobile menu
            header.addEventListener('click', (e) => {
                if (document.querySelector('.admin-sidebar.mobile-open')) {
                    e.stopPropagation();
                }
            });
            
            // Ensure header dropdowns work properly
            const dropdownToggles = header.querySelectorAll('#profileDropdownToggle, #menuToggle');
            dropdownToggles.forEach(toggle => {
                toggle.addEventListener('click', (e) => {
                    if (document.querySelector('.admin-sidebar.mobile-open')) {
                        this.closeMobileSidebar();
                    }
                });
            });
        }
    }

    // Open mobile sidebar
    openMobileSidebar() {
        const sidebar = document.getElementById('adminSidebar');
        const overlay = document.querySelector('.mobile-overlay');
        const body = document.body;
        
        if (sidebar && overlay) {
            this.updateMobileTogglePosition();
            sidebar.classList.add('mobile-open');
            overlay.classList.add('active');
            body.classList.add('sidebar-open');
            
            // Update toggle button symbol to left arrow
            const toggle = document.querySelector('.mobile-menu-toggle');
            if (toggle) {
                toggle.innerHTML = '\u2039'; // Left arrow when open
                toggle.setAttribute('title', 'Close Menu');
            }
        }
    }

    closeMobileSidebar() {
        const sidebar = document.getElementById('adminSidebar');
        const overlay = document.querySelector('.mobile-overlay');
        const body = document.body;
        
        if (sidebar && overlay) {
            sidebar.classList.remove('mobile-open');
            overlay.classList.remove('active');
            body.classList.remove('sidebar-open');
            
            // Update toggle button symbol to right arrow
            const toggle = document.querySelector('.mobile-menu-toggle');
            if (toggle) {
                toggle.innerHTML = '\u203A'; // Right arrow when closed
                toggle.setAttribute('title', 'Open Menu');
            }
        }
    }

    // Close header dropdowns when opening mobile sidebar
    closeHeaderDropdowns() {
        // Close profile dropdown
        const profileDropdown = document.getElementById('profileDropdownMenu');
        if (profileDropdown) {
            profileDropdown.classList.add('hidden');
        }
        
        // Close main dropdown menu
        const dropdownMenu = document.getElementById('dropdownMenu');
        if (dropdownMenu) {
            dropdownMenu.classList.add('hidden');
        }
    }

    // Navigate to section
    async navigateToSection(section) {
        section = String(section || '').trim().toLowerCase();
        if (!section) {
            section = 'dashboard';
        }

        const settingsVerified = await this.requireSettingsSectionReauth(section, 'open');
        if (!settingsVerified) {
            return;
        }

        // Close mobile sidebar when navigating (only on mobile)
        if (window.innerWidth <= 992) {
            this.closeMobileSidebar();
        }
        
        // Update active states
        document.querySelectorAll('.nav-link').forEach(link => {
            link.classList.remove('active');
        });
        document.querySelectorAll('.submenu-link').forEach(link => {
            link.classList.remove('active');
        });

        // Set active link
        const activeLink = document.querySelector(`[data-section="${section}"]`);
        if (activeLink) {
            activeLink.classList.add('active');
            
            // Open parent submenu if applicable
            const submenuParent = activeLink.closest('.has-submenu');
            if (submenuParent) {
                submenuParent.classList.add('open');
            }
        }

        // Update breadcrumb
        document.getElementById('currentSection').textContent = 
            this.getSectionTitle(section);

        this.currentSection = section;
        await this.loadSectionContent(section);
    }

    // Get section title
    getSectionTitle(section) {
        const titles = {
            'dashboard': 'Dashboard Overview',
            'user-management': 'User Management',
            'system-settings': 'System Settings',
            'app-settings': 'App Settings',
            'security-settings': 'Security Settings',
            'access-control': 'Access Control',
            'role-settings': 'Role Governance',
            'notification-settings': 'Notification Settings',
            'live-chat-settings': 'Live Chat Settings',
            'public-chat-support': 'Public Chat Support',
            'public-chat-agents': 'Public Chat Agents',
            'public-chat-settings': 'Public Chat Settings',
            'public-chat-reports': 'Public Chat Reports',
            'public-chat-audit': 'Public Chat Audit Logs',
            'notification-queue': 'Notification Queue',
            'podcast-settings': 'Podcast Library',
            'title-settings': 'Title Settings',
            'bank-settings': 'Bank Settings',
            'unit-settings': 'Prison Units',
            'prison-district-settings': 'Prison Districts',
            'prison-region-settings': 'Prison Regions',
            'political-district-settings': 'Political Districts',
            'faq-settings': 'FAQ Knowledge Base',
            'terms-settings': 'Terms of Use',
            'data-management': 'Data Management',
            'data-backup': 'Backup & Restore',
            'data-export': 'Export Data',
            'data-import': 'Import Data',
            'data-cleanup': 'Data Cleanup',
            'activity-logs': 'Activity Logs',
            'chat-oversight': 'Chat Oversight',
            'user-logs': 'User Activity Logs',
            'workflow-logs': 'Workflow Reports',
            'task-logs': 'Task Delegation',
            'system-logs': 'System Logs',
            'analysis-reporting': 'Analysis & Reporting',
            'audit-trail': 'Audit Trail',
            'system-health': 'System Health',
            'storage-management': 'Storage Management',
            'storage-overview': 'Storage Overview',
            'message-storage': 'Message Storage Management',
            'attachment-storage': 'Attachment Storage',
            'document-storage': 'Document Storage',
            'storage-cleanup': 'Storage Cleanup Tools'
        };
        return titles[section] || section;
    }

    // Load section content
    async loadSectionContent(section) {
        section = String(section || '').trim().toLowerCase();
        if (!section) {
            section = 'dashboard';
        }

        const contentBody = document.getElementById('contentBody');
        
        // Show loading state
        contentBody.innerHTML = `
            <div class="loading-state">
                <div class="loading-spinner"></div>
                <p>Loading ${this.getSectionTitle(section)}...</p>
            </div>
        `;

        try {
            let content = '';
            
            switch (section) {
                case 'dashboard':
                    content = await this.loadDashboardContent();
                    break;
                case 'user-management':
                    content = await this.loadUserManagementContent();
                    break;
                case 'system-settings':
                    content = await this.loadAppSettingsContent();
                    break;
                case 'app-settings':
                    content = await this.loadAppSettingsContent();
                    break;
                case 'security-settings':
                    content = await this.loadSecuritySettingsContent();
                    break;
                case 'access-control':
                    content = await this.loadAccessControlContent();
                    break;
                case 'role-settings':
                    content = await this.loadRoleSettingsContent();
                    break;
                case 'notification-settings':
                    content = await this.loadNotificationSettingsContent();
                    break;
                case 'live-chat-settings':
                    content = await this.loadLiveChatSettingsContent();
                    break;
                case 'public-chat-support':
                case 'public-live-chat':
                case 'public-chat-console':
                case 'public-chat-queue':
                case 'public-chat-active':
                case 'public-chat-assigned':
                case 'public-chat-offline':
                case 'public-chat-tickets':
                case 'public-chat-escalations':
                case 'public-chat-canned':
                case 'public-chat-agents':
                case 'public-chat-reports':
                case 'public-chat-audit':
                    content = await this.loadPublicChatSupportContent();
                    break;
                case 'public-chat-settings':
                    content = await this.loadLiveChatSettingsContent();
                    break;
                case 'notification-queue':
                    content = await this.loadNotificationQueueContent();
                    break;
                case 'podcast-settings':
                    content = await this.loadPodcastSettingsContent();
                    break;
                case 'title-settings':
                    content = await this.loadTitleSettingsContent();
                    break;
                case 'bank-settings':
                    content = await this.loadBankSettingsContent();
                    break;
                case 'unit-settings':
                    content = await this.loadUnitSettingsContent();
                    break;
                case 'prison-district-settings':
                    content = await this.loadPrisonDistrictSettingsContent();
                    break;
                case 'prison-region-settings':
                    content = await this.loadPrisonRegionSettingsContent();
                    break;
                case 'political-district-settings':
                    content = await this.loadPoliticalDistrictSettingsContent();
                    break;
                case 'faq-settings':
                    content = await this.loadFaqSettingsContent();
                    break;
                case 'terms-settings':
                    content = await this.loadTermsSettingsContent();
                    break;
                case 'data-backup':
                    content = await this.loadDataBackupContent();
                    break;
                case 'data-export':
                    content = await this.loadDataExportContent();
                    break;
                case 'data-import':
                    content = await this.loadDataImportContent();
                    break;
                case 'data-cleanup':
                    content = await this.loadDataCleanupContent();
                    break;
                case 'message-storage':
                    content = await this.loadMessageStorageContent();
                    break;
                case 'attachment-storage':
                    content = await this.loadAttachmentStorageContent();
                    break;
                case 'document-storage':
                    content = await this.loadDocumentStorageContent();
                    break;
                case 'chat-oversight':
                    content = await this.loadChatOversightContent();
                    break;
                case 'user-logs':
                    content = await this.loadUserLogsContent();
                    break;
                case 'audit-trail':
                    content = await this.loadAuditTrailContent();
                    break;
                case 'system-health':
                    content = await this.loadSystemHealthContent();
                    break;
                // Add more cases for other sections
                default:
                    content = this.loadDefaultContent(section);
            }

            contentBody.innerHTML = content;
            this.initializeSectionScripts(section);
        } catch (error) {
            console.error(`Error loading section ${section}:`, error);
            contentBody.innerHTML = this.loadErrorContent(section, error);
        }
    }

    // Load dashboard content
    async loadDashboardContent() {
        const stats = await this.loadDashboardStats();
        
        return `
            <div class="dashboard-content">
                <div class="stats-grid">
                    <div class="stat-card large">
                        <div class="stat-icon">&#128101;</div>
                        <div class="stat-info">
                            <span class="stat-value" id="totalUsersStat">${stats.totalUsers || 0}</span>
                            <span class="stat-label">Total Users</span>
                        </div>
                    </div>
                    <div class="stat-card large">
                        <div class="stat-icon">&#128221;</div>
                        <div class="stat-info">
                            <span class="stat-value" id="todayLogsStat">${stats.todayLogs || 0}</span>
                            <span class="stat-label">Today's Logs</span>
                        </div>
                    </div>
                    <div class="stat-card large">
                        <div class="stat-icon">&#9889;</div>
                        <div class="stat-info">
                            <span class="stat-value" id="activeSessionsStat">${stats.activeSessions || 0}</span>
                            <span class="stat-label">Active Sessions</span>
                        </div>
                    </div>
                    <div class="stat-card large">
                        <div class="stat-icon">&#128737;</div>
                        <div class="stat-info">
                            <span class="stat-value" id="failedLoginsStat">${stats.failedLogins || 0}</span>
                            <span class="stat-label">Failed Logins (Week)</span>
                        </div>
                    </div>
                </div>

                <div class="dashboard-widgets">
                    <div class="widget">
                        <h3>Recent Activity</h3>
                        <div id="recentActivityList" class="activity-list">
                            Loading recent activity...
                        </div>
                    </div>
                    <div class="widget">
                        <h3>System Health</h3>
                        <div id="systemHealthStatus" class="health-status">
                            Loading system health...
                        </div>
                    </div>
                </div>
            </div>
        `;
    }

    // Load user logs content
    async loadUserLogsContent() {
        return `
            <div class="user-logs-content">
                <div class="content-toolbar">
                    <div class="filters">
                        <select id="activityTypeFilter" class="filter-select">
                            <option value="">All Activity Types</option>
                            <option value="login">Login</option>
                            <option value="logout">Logout</option>
                            <option value="session_expiry">Session Expiry</option>
                            <option value="device_conflict">Device Conflict</option>
                            <option value="device_conflict_detected">Device Conflict Detected</option>
                            <option value="device_conflict_resolved">Device Conflict Resolved</option>
                            <option value="multiple_sessions_terminated">Multiple Sessions Terminated</option>
                            <option value="login_failed">Failed Login</option>
                            <option value="session_cleanup">Session Cleanup</option>
                            <option value="session_termination_failed">Session Termination Failed</option>
                            <option value="auto_logout">Auto Logout</option>
                        </select>
                        <input type="date" id="dateFromFilter" class="filter-input" placeholder="From Date">
                        <input type="date" id="dateToFilter" class="filter-input" placeholder="To Date">
                        <button id="applyFilters" class="filter-btn">Apply Filters</button>
                        <button id="clearFilters" class="filter-btn secondary">Clear</button>
                    </div>
                </div>
                <div class="logs-table-container">
                    <table class="logs-table">
                        <thead>
                            <tr>
                                <th>User</th>
                                <th>Activity</th>
                                <th>IP Address</th>
                                <th>Location</th>
                                <th>Device</th>
                                <th>Duration</th>
                                <th>Timestamp</th>
                                <th>Details</th>
                            </tr>
                        </thead>
                        <tbody id="logsTableBody">
                            <tr><td colspan="8">Loading logs...</td></tr>
                        </tbody>
                    </table>
                    <div class="pagination" id="logsPagination">
                        <!-- Pagination will be loaded here -->
                    </div>
                </div>
            </div>
        `;
    }

    // Load title settings content
    async loadTitleSettingsContent() {
        return `
            <div class="settings-content">
                <div class="settings-header">
                    <div>
                        <h2 class="section-title">Title Settings</h2>
                        <p class="section-subtitle">Maintain official staff titles by category and level to power accurate workflows.</p>
                    </div>
                    <div class="settings-actions">
                        <button class="action-btn secondary" id="refreshTitlesBtn" type="button">Refresh</button>
                        <button class="action-btn" id="addTitleBtn" type="button">Add Title</button>
                    </div>
                </div>

                <div class="user-summary-grid">
                    <div class="user-summary-card">
                        <div class="user-summary-icon">&#128203;</div>
                        <div>
                            <div class="user-summary-value" id="titleSummaryTotal">0</div>
                            <div class="user-summary-label">Total Titles</div>
                        </div>
                    </div>
                    <div class="user-summary-card">
                        <div class="user-summary-icon">&#128203;</div>
                        <div>
                            <div class="user-summary-value" id="titleSummaryUniformed">0</div>
                            <div class="user-summary-label">Uniformed</div>
                        </div>
                    </div>
                    <div class="user-summary-card">
                        <div class="user-summary-icon">&#128203;</div>
                        <div>
                            <div class="user-summary-value" id="titleSummaryNonUniformed">0</div>
                            <div class="user-summary-label">Non-Uniformed</div>
                        </div>
                    </div>
                    <div class="user-summary-card">
                        <div class="user-summary-icon">&#128203;</div>
                        <div>
                            <div class="user-summary-value" id="titleSummaryActive">0</div>
                            <div class="user-summary-label">Active Titles</div>
                        </div>
                    </div>
                </div>

                <div class="settings-toolbar">
                    <div class="user-search">
                        <input type="text" id="titleSearchInput" placeholder="Search titles">
                        <span class="search-icon">&#128269;</span>
                    </div>
                    <div class="user-filter">
                        <select id="titleCategoryFilter">
                            <option value="">All Categories</option>
                            <option value="uniformed">Uniformed</option>
                            <option value="non_uniformed">Non-Uniformed</option>
                        </select>
                    </div>
                    <div class="user-filter">
                        <select id="titleLevelFilter">
                            <option value="">All Levels</option>
                            <option value="junior">Junior</option>
                            <option value="senior">Senior</option>
                        </select>
                    </div>
                    <div class="user-filter">
                        <select id="titleStatusFilter">
                            <option value="">All Status</option>
                            <option value="active">Active</option>
                            <option value="inactive">Inactive</option>
                        </select>
                    </div>
                    <button class="action-btn secondary" id="clearTitleFilters" type="button">Clear Filters</button>
                </div>

                <div class="settings-table-container">
                    <table class="settings-table">
                        <thead>
                            <tr>
                                <th>Title</th>
                                <th>Category</th>
                                <th>Level</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody id="titleSettingsTableBody">
                            <tr><td colspan="5"><div class="table-loading">Loading titles...</div></td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        `;
    }

    async loadBankSettingsContent() {
        return `
            <div class="settings-content">
                <div class="settings-header">
                    <div>
                        <h2 class="section-title">Bank Settings</h2>
                        <p class="section-subtitle">Maintain the official banking catalogue used by registry and pension processing forms.</p>
                    </div>
                    <div class="settings-actions">
                        <button class="action-btn secondary" id="refreshBanksBtn" type="button">Refresh</button>
                        <button class="action-btn" id="addBankBtn" type="button">Add Bank</button>
                    </div>
                </div>

                <div class="user-summary-grid">
                    <div class="user-summary-card">
                        <div class="user-summary-icon">&#127974;</div>
                        <div>
                            <div class="user-summary-value" id="bankSummaryTotal">0</div>
                            <div class="user-summary-label">Total Banks</div>
                        </div>
                    </div>
                    <div class="user-summary-card">
                        <div class="user-summary-icon">&#9989;</div>
                        <div>
                            <div class="user-summary-value" id="bankSummaryActive">0</div>
                            <div class="user-summary-label">Active Banks</div>
                        </div>
                    </div>
                    <div class="user-summary-card">
                        <div class="user-summary-icon">&#128683;</div>
                        <div>
                            <div class="user-summary-value" id="bankSummaryInactive">0</div>
                            <div class="user-summary-label">Inactive Banks</div>
                        </div>
                    </div>
                    <div class="user-summary-card">
                        <div class="user-summary-icon">&#35;</div>
                        <div>
                            <div class="user-summary-value" id="bankSummaryCodes">0</div>
                            <div class="user-summary-label">Banks with Codes</div>
                        </div>
                    </div>
                </div>

                <div class="settings-toolbar">
                    <div class="user-search">
                        <input type="text" id="bankSearchInput" placeholder="Search banks, short names, or codes">
                        <span class="search-icon">&#128269;</span>
                    </div>
                    <div class="user-filter">
                        <select id="bankStatusFilter">
                            <option value="">All Status</option>
                            <option value="active">Active</option>
                            <option value="inactive">Inactive</option>
                        </select>
                    </div>
                    <button class="action-btn secondary" id="clearBankFilters" type="button">Clear Filters</button>
                </div>

                <div class="settings-table-container">
                    <table class="settings-table">
                        <thead>
                            <tr>
                                <th>Bank Name</th>
                                <th>Short Name</th>
                                <th>Code</th>
                                <th>Display Order</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody id="bankSettingsTableBody">
                            <tr><td colspan="6"><div class="table-loading">Loading banks...</div></td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        `;
    }

    // Load unit settings content
    async loadUnitSettingsContent() {
        return `
            <div class="settings-content">
                <div class="settings-header">
                    <div>
                        <h2 class="section-title">Prison Units</h2>
                        <p class="section-subtitle">Manage prison units and their associated districts and regions.</p>
                    </div>
                    <div class="settings-actions">
                        <button class="action-btn secondary" id="refreshUnitsBtn" type="button">Refresh</button>
                        <button class="action-btn" id="addUnitBtn" type="button">Add Unit</button>
                    </div>
                </div>

                <div class="user-summary-grid">
                    <div class="user-summary-card">
                        <div class="user-summary-icon">&#128203;</div>
                        <div>
                            <div class="user-summary-value" id="unitSummaryTotal">0</div>
                            <div class="user-summary-label">Total Units</div>
                        </div>
                    </div>
                    <div class="user-summary-card">
                        <div class="user-summary-icon">&#128203;</div>
                        <div>
                            <div class="user-summary-value" id="unitSummaryDistricts">0</div>
                            <div class="user-summary-label">Prison Districts</div>
                        </div>
                    </div>
                    <div class="user-summary-card">
                        <div class="user-summary-icon">&#128203;</div>
                        <div>
                            <div class="user-summary-value" id="unitSummaryRegions">0</div>
                            <div class="user-summary-label">Prison Regions</div>
                        </div>
                    </div>
                    <div class="user-summary-card">
                        <div class="user-summary-icon">&#128203;</div>
                        <div>
                            <div class="user-summary-value" id="unitSummaryPoliticalDistricts">0</div>
                            <div class="user-summary-label">Political Districts</div>
                        </div>
                    </div>
                </div>

                <div class="settings-toolbar">
                    <div class="user-search">
                        <input type="text" id="unitSearchInput" placeholder="Search units, districts, regions">
                        <span class="search-icon">&#128269;</span>
                    </div>
                    <div class="user-filter">
                        <select id="unitRegionFilter">
                            <option value="">All Prison Regions</option>
                        </select>
                    </div>
                    <div class="user-filter">
                        <select id="unitDistrictFilter">
                            <option value="">All Prison Districts</option>
                        </select>
                    </div>
                    <button class="action-btn secondary" id="clearUnitFilters" type="button">Clear Filters</button>
                </div>

                <div class="settings-table-container">
                    <table class="settings-table">
                        <thead>
                            <tr>
                                <th>Unit</th>
                                <th>Prison District</th>
                                <th>Prison Region</th>
                                <th>Political District</th>
                                <th>Political Region</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody id="unitSettingsTableBody">
                            <tr><td colspan="6"><div class="table-loading">Loading units...</div></td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        `;
    }

    async loadPrisonDistrictSettingsContent() {
        return `
            <div class="settings-content">
                <div class="settings-header">
                    <div>
                        <h2 class="section-title">Prison Districts</h2>
                        <p class="section-subtitle">Maintain the list of prison districts used across unit records.</p>
                    </div>
                    <div class="settings-actions">
                        <button class="action-btn secondary" id="refreshPrisonDistrictsBtn" type="button">Refresh</button>
                        <button class="action-btn" id="addPrisonDistrictBtn" type="button">Add District</button>
                    </div>
                </div>

                <div class="user-summary-grid">
                    <div class="user-summary-card">
                        <div class="user-summary-icon">&#128203;</div>
                        <div>
                            <div class="user-summary-value" id="prisonDistrictSummaryTotal">0</div>
                            <div class="user-summary-label">Total Districts</div>
                        </div>
                    </div>
                </div>

                <div class="settings-toolbar">
                    <div class="user-search">
                        <input type="text" id="prisonDistrictSearchInput" placeholder="Search prison districts">
                        <span class="search-icon">&#128269;</span>
                    </div>
                    <button class="action-btn secondary" id="clearPrisonDistrictFilters" type="button">Clear Filters</button>
                </div>

                <div class="settings-table-container">
                    <table class="settings-table">
                        <thead>
                            <tr>
                                <th>District</th>
                                <th>Region</th>
                                <th>Units</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody id="prisonDistrictTableBody">
                            <tr><td colspan="4"><div class="table-loading">Loading districts...</div></td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        `;
    }

    async loadPrisonRegionSettingsContent() {
        return `
            <div class="settings-content">
                <div class="settings-header">
                    <div>
                        <h2 class="section-title">Prison Regions</h2>
                        <p class="section-subtitle">Manage prison regions used when classifying units and districts.</p>
                    </div>
                    <div class="settings-actions">
                        <button class="action-btn secondary" id="refreshPrisonRegionsBtn" type="button">Refresh</button>
                        <button class="action-btn" id="addPrisonRegionBtn" type="button">Add Region</button>
                    </div>
                </div>

                <div class="user-summary-grid">
                    <div class="user-summary-card">
                        <div class="user-summary-icon">&#128203;</div>
                        <div>
                            <div class="user-summary-value" id="prisonRegionSummaryTotal">0</div>
                            <div class="user-summary-label">Total Regions</div>
                        </div>
                    </div>
                </div>

                <div class="settings-toolbar">
                    <div class="user-search">
                        <input type="text" id="prisonRegionSearchInput" placeholder="Search prison regions">
                        <span class="search-icon">&#128269;</span>
                    </div>
                    <button class="action-btn secondary" id="clearPrisonRegionFilters" type="button">Clear Filters</button>
                </div>

                <div class="settings-table-container">
                    <table class="settings-table">
                        <thead>
                            <tr>
                                <th>Region</th>
                                <th>Units</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody id="prisonRegionTableBody">
                            <tr><td colspan="3"><div class="table-loading">Loading regions...</div></td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        `;
    }

    async loadPoliticalDistrictSettingsContent() {
        return `
            <div class="settings-content">
                <div class="settings-header">
                    <div>
                        <h2 class="section-title">Political Districts</h2>
                        <p class="section-subtitle">Manage political districts and their regions for address selection.</p>
                    </div>
                    <div class="settings-actions">
                        <button class="action-btn secondary" id="refreshPoliticalDistrictsBtn" type="button">Refresh</button>
                        <button class="action-btn" id="addPoliticalDistrictBtn" type="button">Add District</button>
                    </div>
                </div>

                <div class="user-summary-grid">
                    <div class="user-summary-card">
                        <div class="user-summary-icon">&#128203;</div>
                        <div>
                            <div class="user-summary-value" id="politicalDistrictSummaryTotal">0</div>
                            <div class="user-summary-label">Total Districts</div>
                        </div>
                    </div>
                    <div class="user-summary-card">
                        <div class="user-summary-icon">&#128203;</div>
                        <div>
                            <div class="user-summary-value" id="politicalDistrictSummaryRegions">0</div>
                            <div class="user-summary-label">Regions</div>
                        </div>
                    </div>
                </div>

                <div class="settings-toolbar">
                    <div class="user-search">
                        <input type="text" id="politicalDistrictSearchInput" placeholder="Search political districts">
                        <span class="search-icon">&#128269;</span>
                    </div>
                    <div class="user-filter">
                        <select id="politicalDistrictRegionFilter">
                            <option value="">All Regions</option>
                            <option value="Northern">Northern</option>
                            <option value="Eastern">Eastern</option>
                            <option value="Central">Central</option>
                            <option value="Western">Western</option>
                        </select>
                    </div>
                    <button class="action-btn secondary" id="clearPoliticalDistrictFilters" type="button">Clear Filters</button>
                </div>

                <div class="settings-table-container">
                    <table class="settings-table">
                        <thead>
                            <tr>
                                <th>District</th>
                                <th>Region</th>
                                <th>Units</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody id="politicalDistrictTableBody">
                            <tr><td colspan="4"><div class="table-loading">Loading districts...</div></td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        `;
    }

    // Load FAQ settings content
    async loadFaqSettingsContent() {
        return `
            <div class="settings-content">
                <div class="settings-header">
                    <div>
                        <h2 class="section-title">FAQ Knowledge Base</h2>
                        <p class="section-subtitle">Manage questions, answers, and visibility for the public FAQ guidance.</p>
                    </div>
                    <div class="settings-actions">
                        <button class="action-btn secondary" id="refreshFaqBtn" type="button">Refresh</button>
                        <button class="action-btn" id="addFaqBtn" type="button">Add FAQ</button>
                    </div>
                </div>

                <div class="user-summary-grid">
                    <div class="user-summary-card">
                        <div class="user-summary-icon">&#128203;</div>
                        <div>
                            <div class="user-summary-value" id="faqSummaryTotal">0</div>
                            <div class="user-summary-label">Total Entries</div>
                        </div>
                    </div>
                    <div class="user-summary-card">
                        <div class="user-summary-icon">&#128204;</div>
                        <div>
                            <div class="user-summary-value" id="faqSummaryActive">0</div>
                            <div class="user-summary-label">Active</div>
                        </div>
                    </div>
                    <div class="user-summary-card">
                        <div class="user-summary-icon">&#11088;</div>
                        <div>
                            <div class="user-summary-value" id="faqSummaryFeatured">0</div>
                            <div class="user-summary-label">Featured</div>
                        </div>
                    </div>
                    <div class="user-summary-card">
                        <div class="user-summary-icon">&#128214;</div>
                        <div>
                            <div class="user-summary-value" id="faqSummaryCategories">0</div>
                            <div class="user-summary-label">Topics</div>
                        </div>
                    </div>
                </div>

                <div class="settings-toolbar">
                    <div class="user-search">
                        <input type="text" id="faqSearchInput" placeholder="Search FAQ entries">
                        <span class="search-icon">&#128269;</span>
                    </div>
                    <div class="user-filter">
                        <select id="faqCategoryFilter">
                            <option value="">All Topics</option>
                            <option value="applications">Applications</option>
                            <option value="benefits">Benefits</option>
                            <option value="registry">Registry & Tracking</option>
                            <option value="claims">Claims & Payroll</option>
                            <option value="pensioners">Pensioner Access</option>
                            <option value="security">Security & Access</option>
                        </select>
                    </div>
                    <div class="user-filter">
                        <select id="faqStatusFilter">
                            <option value="">All Status</option>
                            <option value="active">Active</option>
                            <option value="inactive">Inactive</option>
                        </select>
                    </div>
                    <div class="user-filter">
                        <select id="faqFeaturedFilter">
                            <option value="">All Visibility</option>
                            <option value="featured">Featured</option>
                            <option value="standard">Standard</option>
                        </select>
                    </div>
                    <button class="action-btn secondary" id="clearFaqFilters" type="button">Clear Filters</button>
                </div>

                <div class="settings-table-container">
                    <table class="settings-table">
                        <thead>
                            <tr>
                                <th>Question</th>
                                <th>Topic</th>
                                <th>Audience</th>
                                <th>Status</th>
                                <th>Featured</th>
                                <th>Updated</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody id="faqSettingsTableBody">
                            <tr><td colspan="7"><div class="table-loading">Loading FAQs...</div></td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        `;
    }

    // Load terms settings content
    async loadTermsSettingsContent() {
        return `
            <div class="settings-content">
                <div class="settings-header">
                    <div>
                        <h2 class="section-title">Operational Terms</h2>
                        <p class="section-subtitle">Maintain the clauses displayed in the Detailed Terms section of the Terms of Use page.</p>
                    </div>
                    <div class="settings-actions">
                        <button class="action-btn secondary" id="refreshTermsBtn" type="button">Refresh</button>
                        <button class="action-btn" id="addTermsBtn" type="button">Add Clause</button>
                    </div>
                </div>

                <div class="user-summary-grid">
                    <div class="user-summary-card">
                        <div class="user-summary-icon">&#128203;</div>
                        <div>
                            <div class="user-summary-value" id="termsSummaryTotal">0</div>
                            <div class="user-summary-label">Total Clauses</div>
                        </div>
                    </div>
                    <div class="user-summary-card">
                        <div class="user-summary-icon">&#128204;</div>
                        <div>
                            <div class="user-summary-value" id="termsSummaryActive">0</div>
                            <div class="user-summary-label">Active</div>
                        </div>
                    </div>
                    <div class="user-summary-card">
                        <div class="user-summary-icon">&#128197;</div>
                        <div>
                            <div class="user-summary-value" id="termsSummaryUpdated">--</div>
                            <div class="user-summary-label">Last Updated</div>
                        </div>
                    </div>
                    <div class="user-summary-card">
                        <div class="user-summary-icon">&#128214;</div>
                        <div>
                            <div class="user-summary-value" id="termsSummaryTopics">0</div>
                            <div class="user-summary-label">Topic Groups</div>
                        </div>
                    </div>
                </div>

                <div class="settings-toolbar">
                    <div class="user-search">
                        <input type="text" id="termsSearchInput" placeholder="Search clauses">
                        <span class="search-icon">&#128269;</span>
                    </div>
                    <div class="user-filter">
                        <select id="termsTopicFilter">
                            <option value="">All Topics</option>
                            <option value="accounts">Accounts</option>
                            <option value="operations">Operations</option>
                            <option value="data">Data & Records</option>
                            <option value="security">Security</option>
                            <option value="content">Content</option>
                        </select>
                    </div>
                    <div class="user-filter">
                        <select id="termsStatusFilter">
                            <option value="">All Status</option>
                            <option value="active">Active</option>
                            <option value="inactive">Inactive</option>
                        </select>
                    </div>
                    <button class="action-btn secondary" id="clearTermsFilters" type="button">Clear Filters</button>
                </div>

                <div class="settings-table-container">
                    <table class="settings-table">
                        <thead>
                            <tr>
                                <th>Clause Title</th>
                                <th>Topics</th>
                                <th>Status</th>
                                <th>Updated</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody id="termsSettingsTableBody">
                            <tr><td colspan="5"><div class="table-loading">Loading clauses...</div></td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        `;
    }

    // Load app settings content
    async loadAppSettingsContent() {
        return `
            <div class="settings-content">
                <div class="settings-header">
                    <div>
                        <h2 class="section-title">App Settings</h2>
                        <p class="section-subtitle">Configure application-wide preferences, session policies, and operational defaults.</p>
                    </div>
                    <div class="settings-actions">
                        <span class="settings-status" id="appSettingsStatus">Ready</span>
                        <button class="action-btn secondary" id="resetAppSettingsBtn" type="button">Reset Changes</button>
                        <button class="action-btn" id="saveAppSettingsBtn" type="button">Save Settings</button>
                    </div>
                </div>

                <form id="appSettingsForm" class="settings-grid">
                    <section class="settings-card">
                        <div class="settings-card-header">
                            <h3>Brand & Identity</h3>
                            <p>Control the public-facing application details.</p>
                        </div>
                        <div class="settings-fields">
                            <label class="settings-field">
                                <span>Application Name</span>
                                <input type="text" name="app_name" placeholder="PensionsGo">
                            </label>
                            <label class="settings-field">
                                <span>Tagline</span>
                                <input type="text" name="app_tagline" placeholder="Unified pension administration">
                            </label>
                            <label class="settings-field">
                                <span>Support Email</span>
                                <input type="email" name="support_email" placeholder="support@pension.go.ug">
                                <small class="field-help">Used in the public footer and feedback routing.</small>
                            </label>
                            <label class="settings-field">
                                <span>Support Phone</span>
                                <input type="text" name="support_phone" placeholder="+2567...">
                                <small class="field-help">Displayed in the public footer contact section.</small>
                            </label>
                            <label class="settings-field">
                                <span>Default User Role</span>
                                <select name="default_user_role">
                                    <option value="">Loading roles...</option>
                                </select>
                            </label>
                            <label class="settings-field">
                                <span>Login Banner Message</span>
                                <textarea name="login_banner" rows="3" placeholder="Optional message shown on the login screen."></textarea>
                                <small class="field-help">Use this for service updates or maintenance notices.</small>
                            </label>
                            <div class="settings-toggle">
                                <div>
                                    <div class="toggle-title">Maintenance Mode</div>
                                    <div class="toggle-subtitle">Restrict access to administrators only.</div>
                                </div>
                                <label class="switch">
                                    <input type="checkbox" name="maintenance_mode">
                                    <span class="slider"></span>
                                </label>
                            </div>
                        </div>
                    </section>

                    <section class="settings-card">
                        <div class="settings-card-header app-version-card-head">
                            <div>
                                <h3>Version Management</h3>
                                <p>Manage the internal release identifiers used by the footer badge, PWA update flow, and build tracking.</p>
                            </div>
                            <span class="settings-status" id="appVersionSettingsStatus">Loading...</span>
                        </div>
                        <div class="settings-note">
                            The display version is visible in the app footer. The build, schema, cache version, and fingerprint values support release traceability and installed-app refresh behavior.
                        </div>
                        <div class="settings-fields">
                            <label class="settings-field">
                                <span>Display Version</span>
                                <input type="text" name="app_version_display_version" placeholder="1.0.0">
                                <small class="field-help">Shown in the app footer and other visible version badges.</small>
                            </label>
                            <label class="settings-field">
                                <span>Internal Version</span>
                                <input type="text" name="app_version_version" placeholder="1.0.0">
                                <small class="field-help">Primary release version used by the runtime and cache versioning logic.</small>
                            </label>
                            <label class="settings-field">
                                <span>Release Channel</span>
                                <input type="text" name="app_version_channel" list="appVersionChannelList" placeholder="stable">
                                <datalist id="appVersionChannelList">
                                    <option value="stable"></option>
                                    <option value="beta"></option>
                                    <option value="rc"></option>
                                    <option value="dev"></option>
                                    <option value="hotfix"></option>
                                </datalist>
                            </label>
                            <label class="settings-field">
                                <span>Build Identifier</span>
                                <input type="text" name="app_version_build" placeholder="20260421.1">
                                <small class="field-help">Recommended format: YYYYMMDD.revision.</small>
                            </label>
                            <label class="settings-field">
                                <span>Release Date</span>
                                <input type="date" name="app_version_release_date">
                            </label>
                            <label class="settings-field">
                                <span>Schema Version</span>
                                <input type="text" name="app_version_schema_version" placeholder="5.2.1">
                                <small class="field-help">Use this when data or cache assumptions change between releases.</small>
                            </label>
                        </div>
                        <div class="app-version-actions">
                            <button class="action-btn secondary" id="refreshAppVersionBtn" type="button">Refresh Runtime</button>
                            <button class="action-btn secondary" id="appVersionUseTodayBtn" type="button">Use Today</button>
                            <button class="action-btn secondary" id="appVersionAutoBuildBtn" type="button">Auto Build</button>
                            <button class="action-btn secondary" id="resetAppVersionBtn" type="button">Reset Version</button>
                            <button class="action-btn" id="saveAppVersionBtn" type="button">Save Version</button>
                        </div>
                        <div class="app-version-summary-grid">
                            <article class="app-version-summary-card">
                                <span class="app-version-summary-label">Effective Footer Label</span>
                                <strong id="appVersionEffectiveLabel">--</strong>
                                <small>What users see in the footer badge.</small>
                            </article>
                            <article class="app-version-summary-card">
                                <span class="app-version-summary-label">Cache Version</span>
                                <strong id="appVersionCacheVersion">--</strong>
                                <small>PWA update key built from version, build, and fingerprint.</small>
                            </article>
                            <article class="app-version-summary-card">
                                <span class="app-version-summary-label">Build Fingerprint</span>
                                <strong id="appVersionBuildFingerprint">--</strong>
                                <small>Derived from the newest tracked frontend/backend asset change.</small>
                            </article>
                            <article class="app-version-summary-card">
                                <span class="app-version-summary-label">Manifest File</span>
                                <strong id="appVersionManifestFile">app_version.json</strong>
                                <small id="appVersionManifestMeta">Waiting for manifest details...</small>
                            </article>
                        </div>
                    </section>

                    <section class="settings-card">
                        <div class="settings-card-header">
                            <h3>Public Footer</h3>
                            <p>Manage the contact block, social links, and developer credits shown on public pages.</p>
                        </div>
                        <div class="settings-fields">
                            <label class="settings-field">
                                <span>Organisation Name</span>
                                <input type="text" name="public_footer_org_name" placeholder="Uganda Prisons Service Headquarters">
                            </label>
                            <label class="settings-field">
                                <span>Organisation Address</span>
                                <textarea name="public_footer_address" rows="2" placeholder="P.O. Box 7182, Kampala (U)"></textarea>
                                <small class="field-help">Use line breaks for multiple address lines.</small>
                            </label>
                            <label class="settings-field">
                                <span>Technical Support Email</span>
                                <input type="email" name="public_footer_tech_support_email" placeholder="support-tech@pension.go.ug">
                            </label>
                            <label class="settings-field">
                                <span>Facebook Link</span>
                                <input type="url" name="public_footer_social_facebook" placeholder="https://facebook.com/...">
                            </label>
                            <label class="settings-field">
                                <span>Twitter/X Link</span>
                                <input type="url" name="public_footer_social_twitter" placeholder="https://x.com/...">
                            </label>
                            <label class="settings-field">
                                <span>Instagram Link</span>
                                <input type="url" name="public_footer_social_instagram" placeholder="https://instagram.com/...">
                            </label>
                            <label class="settings-field">
                                <span>LinkedIn Link</span>
                                <input type="url" name="public_footer_social_linkedin" placeholder="https://linkedin.com/company/...">
                            </label>
                            <label class="settings-field">
                                <span>Developer Name</span>
                                <input type="text" name="public_footer_developer_name" placeholder="Developer Name">
                            </label>
                            <label class="settings-field">
                                <span>Developer Email</span>
                                <input type="email" name="public_footer_developer_email" placeholder="developer@pension.go.ug">
                            </label>
                            <label class="settings-field">
                                <span>Developer Phone</span>
                                <input type="text" name="public_footer_developer_phone" placeholder="+2567...">
                            </label>
                        </div>
                    </section>

                    <section class="settings-card">
                        <div class="settings-card-header">
                            <h3>Localization</h3>
                            <p>Set the default formatting for time and currency.</p>
                        </div>
                        <div class="settings-fields">
                            <label class="settings-field">
                                <span>Timezone</span>
                                <input type="text" name="timezone" list="timezoneList" placeholder="Africa/Kampala">
                                <datalist id="timezoneList">
                                    <option value="Africa/Kampala"></option>
                                    <option value="UTC"></option>
                                    <option value="Africa/Nairobi"></option>
                                    <option value="Africa/Lagos"></option>
                                    <option value="Europe/London"></option>
                                </datalist>
                            </label>
                            <label class="settings-field">
                                <span>Date Format</span>
                                <select name="date_format">
                                    <option value="YYYY-MM-DD">YYYY-MM-DD</option>
                                    <option value="DD/MM/YYYY">DD/MM/YYYY</option>
                                    <option value="MM/DD/YYYY">MM/DD/YYYY</option>
                                </select>
                            </label>
                            <label class="settings-field">
                                <span>Time Format</span>
                                <select name="time_format">
                                    <option value="24h">24 Hour</option>
                                    <option value="12h">12 Hour</option>
                                </select>
                            </label>
                            <label class="settings-field">
                                <span>Currency</span>
                                <input type="text" name="currency" placeholder="UGX">
                            </label>
                        </div>
                    </section>

                    <section class="settings-card">
                        <div class="settings-card-header">
                            <h3>Operations</h3>
                            <p>Control retention and system notifications.</p>
                        </div>
                        <div class="settings-fields">
                            <label class="settings-field">
                                <span>Log Retention (days)</span>
                                <input type="number" name="log_retention_days" min="7" max="365" step="1">
                            </label>
                            <div class="settings-toggle">
                                <div>
                                    <div class="toggle-title">Enable Notifications</div>
                                    <div class="toggle-subtitle">Allow system-wide alerts to be sent.</div>
                                </div>
                                <label class="switch">
                                    <input type="checkbox" name="enable_notifications">
                                    <span class="slider"></span>
                                </label>
                            </div>
                        </div>
                    </section>

                    <section class="settings-card">
                        <div class="settings-card-header">
                            <h3>Pensioner Portal</h3>
                            <p>Control pensioner access to the portal and what they can see on their personal dashboard.</p>
                        </div>
                        <div class="settings-fields">
                            <div class="settings-toggle">
                                <div>
                                    <div class="toggle-title">Allow Pensioner Login</div>
                                    <div class="toggle-subtitle">Disable pensioner sign-in when the portal needs to be temporarily closed for support, migration, or compliance work.</div>
                                </div>
                                <label class="switch">
                                    <input type="checkbox" name="pensioner_login_enabled">
                                    <span class="slider"></span>
                                </label>
                            </div>
                            <div class="settings-toggle">
                                <div>
                                    <div class="toggle-title">Show Claims Summary</div>
                                    <div class="toggle-subtitle">Display arrears and outstanding claims specific to the logged-in pensioner.</div>
                                </div>
                                <label class="switch">
                                    <input type="checkbox" name="pensioner_dashboard_enable_claims">
                                    <span class="slider"></span>
                                </label>
                            </div>
                            <div class="settings-toggle">
                                <div>
                                    <div class="toggle-title">Show Document Overview</div>
                                    <div class="toggle-subtitle">Expose document counts and indexed record references without revealing staff-only workflows.</div>
                                </div>
                                <label class="switch">
                                    <input type="checkbox" name="pensioner_dashboard_enable_documents">
                                    <span class="slider"></span>
                                </label>
                            </div>
                            <div class="settings-toggle">
                                <div>
                                    <div class="toggle-title">Show Status Guidance</div>
                                    <div class="toggle-subtitle">Display plain-language explanations for payroll, life certificate, and account status messages.</div>
                                </div>
                                <label class="switch">
                                    <input type="checkbox" name="pensioner_dashboard_enable_status_explanations">
                                    <span class="slider"></span>
                                </label>
                            </div>
                            <div class="settings-toggle">
                                <div>
                                    <div class="toggle-title">Log Pensioner Dashboard Views</div>
                                    <div class="toggle-subtitle">Record when a pensioner opens the portal to support audit and support follow-up.</div>
                                </div>
                                <label class="switch">
                                    <input type="checkbox" name="pensioner_dashboard_enable_activity_log">
                                    <span class="slider"></span>
                                </label>
                            </div>
                            <div class="settings-toggle">
                                <div>
                                    <div class="toggle-title">Enable Pensioner Lookup</div>
                                    <div class="toggle-subtitle">Allow pensioners to search for fellow pensioners who have shared their contact details for reconnection and welfare follow-up.</div>
                                </div>
                                <label class="switch">
                                    <input type="checkbox" name="pensioner_lookup_enabled">
                                    <span class="slider"></span>
                                </label>
                            </div>
                            <div class="settings-toggle">
                                <div>
                                    <div class="toggle-title">Require Consent Before Listing</div>
                                    <div class="toggle-subtitle">Only show pensioners in the lookup directory when they have explicitly enabled visibility from their own portal.</div>
                                </div>
                                <label class="switch">
                                    <input type="checkbox" name="pensioner_lookup_require_consent">
                                    <span class="slider"></span>
                                </label>
                            </div>
                            <div class="settings-toggle">
                                <div>
                                    <div class="toggle-title">Log Pensioner Lookup Activity</div>
                                    <div class="toggle-subtitle">Record when pensioners search the directory or change their own visibility preference.</div>
                                </div>
                                <label class="switch">
                                    <input type="checkbox" name="pensioner_lookup_log_activity">
                                    <span class="slider"></span>
                                </label>
                            </div>
                        </div>
                    </section>

                    <section class="settings-card">
                        <div class="settings-card-header">
                            <h3>Staff Accounts</h3>
                            <p>Control staff access for all non-admin staff accounts at once.</p>
                        </div>
                        <div class="settings-fields">
                            <div class="settings-toggle">
                                <div>
                                    <div class="toggle-title">Allow Staff Login</div>
                                    <div class="toggle-subtitle">Disable sign-in for all staff accounts except administrators and the super administrator during support, migration, or controlled maintenance work.</div>
                                </div>
                                <label class="switch">
                                    <input type="checkbox" name="staff_login_enabled">
                                    <span class="slider"></span>
                                </label>
                            </div>
                        </div>
                    </section>

                    <section class="settings-card">
                        <div class="settings-card-header">
                            <h3>Feedback Governance</h3>
                            <p>Control who can submit feedback, how fast it should be handled, and whether the dashboard inbox can assign and export cases.</p>
                        </div>
                        <div class="settings-fields">
                            <div class="settings-toggle">
                                <div>
                                    <div class="toggle-title">Allow Public Feedback</div>
                                    <div class="toggle-subtitle">Permit non-logged-in visitors to submit service feedback from the public page.</div>
                                </div>
                                <label class="switch">
                                    <input type="checkbox" name="feedback_public_enabled">
                                    <span class="slider"></span>
                                </label>
                            </div>
                            <div class="settings-toggle">
                                <div>
                                    <div class="toggle-title">Allow Staff Feedback</div>
                                    <div class="toggle-subtitle">Permit logged-in staff to submit feedback through the same channel for platform and service issues.</div>
                                </div>
                                <label class="switch">
                                    <input type="checkbox" name="feedback_staff_enabled">
                                    <span class="slider"></span>
                                </label>
                            </div>
                            <div class="settings-toggle">
                                <div>
                                    <div class="toggle-title">Allow Pensioner Feedback</div>
                                    <div class="toggle-subtitle">Permit pensioners to submit support, complaints, and service clarifications from the feedback page.</div>
                                </div>
                                <label class="switch">
                                    <input type="checkbox" name="feedback_pensioner_enabled">
                                    <span class="slider"></span>
                                </label>
                            </div>
                            <div class="settings-toggle">
                                <div>
                                    <div class="toggle-title">Email New Feedback Alerts</div>
                                    <div class="toggle-subtitle">Queue support-email notifications whenever a new feedback record is submitted.</div>
                                </div>
                                <label class="switch">
                                    <input type="checkbox" name="feedback_email_notifications_enabled">
                                    <span class="slider"></span>
                                </label>
                            </div>
                            <div class="settings-toggle">
                                <div>
                                    <div class="toggle-title">Allow Feedback Assignment</div>
                                    <div class="toggle-subtitle">Let feedback managers assign submissions to a named officer inside the dashboard feedback inbox.</div>
                                </div>
                                <label class="switch">
                                    <input type="checkbox" name="feedback_allow_assignment">
                                    <span class="slider"></span>
                                </label>
                            </div>
                            <div class="settings-toggle">
                                <div>
                                    <div class="toggle-title">Allow Feedback Export</div>
                                    <div class="toggle-subtitle">Enable governed XLSX, PDF, and CSV exports for the feedback dashboard workspace.</div>
                                </div>
                                <label class="switch">
                                    <input type="checkbox" name="feedback_allow_export">
                                    <span class="slider"></span>
                                </label>
                            </div>
                            <label class="settings-field">
                                <span>Feedback Response SLA (days)</span>
                                <input type="number" name="feedback_response_sla_days" min="1" max="60" step="1">
                                <small class="field-help">Open feedback older than this threshold is treated as overdue in dashboard analytics.</small>
                            </label>
                        </div>
                    </section>
                </form>
            </div>
        `;
    }

    // Load security settings content
    async loadSecuritySettingsContent() {
        return `
            <div class="settings-content security-settings">
                <div class="settings-header">
                    <div>
                        <h2 class="section-title">Security Settings</h2>
                        <p class="section-subtitle">Define authentication rules, access safeguards, and security alerting for the platform.</p>
                    </div>
                    <div class="settings-actions">
                        <span class="settings-status" id="securitySettingsStatus">Ready</span>
                        <button class="action-btn secondary" id="resetSecuritySettingsBtn" type="button">Reset Changes</button>
                        <button class="action-btn" id="saveSecuritySettingsBtn" type="button">Save Settings</button>
                    </div>
                </div>

                <div class="settings-note">
                    Changes apply to new sessions immediately. Existing sessions may require re-login for some policies to take effect.
                </div>

                <form id="securitySettingsForm" class="settings-grid">
                    <section class="settings-card">
                        <div class="settings-card-header">
                            <h3>Authentication Policy</h3>
                            <p>Require stronger logins and control repeated failed attempts.</p>
                        </div>
                        <div class="settings-fields">
                            <label class="settings-field">
                                <span>Password Minimum Length</span>
                                <input type="number" name="password_min_length" min="6" max="24" step="1">
                            </label>
                            <div class="settings-toggle">
                                <div>
                                    <div class="toggle-title">Require Uppercase Letters</div>
                                    <div class="toggle-subtitle">At least one uppercase character.</div>
                                </div>
                                <label class="switch">
                                    <input type="checkbox" name="password_require_uppercase">
                                    <span class="slider"></span>
                                </label>
                            </div>
                            <div class="settings-toggle">
                                <div>
                                    <div class="toggle-title">Require Lowercase Letters</div>
                                    <div class="toggle-subtitle">At least one lowercase character.</div>
                                </div>
                                <label class="switch">
                                    <input type="checkbox" name="password_require_lowercase">
                                    <span class="slider"></span>
                                </label>
                            </div>
                            <div class="settings-toggle">
                                <div>
                                    <div class="toggle-title">Require Numbers</div>
                                    <div class="toggle-subtitle">At least one numeric character.</div>
                                </div>
                                <label class="switch">
                                    <input type="checkbox" name="password_require_number">
                                    <span class="slider"></span>
                                </label>
                            </div>
                            <div class="settings-toggle">
                                <div>
                                    <div class="toggle-title">Require Special Characters</div>
                                    <div class="toggle-subtitle">At least one symbol (e.g., !@#).</div>
                                </div>
                                <label class="switch">
                                    <input type="checkbox" name="password_require_special">
                                    <span class="slider"></span>
                                </label>
                            </div>
                            <label class="settings-field">
                                <span>Password Expiry (days)</span>
                                <input type="number" name="password_expiry_days" min="0" max="365" step="1" placeholder="0 = never expire">
                            </label>
                            <label class="settings-field">
                                <span>Login Attempt Limit</span>
                                <input type="number" name="login_attempt_limit" min="3" max="20" step="1">
                            </label>
                            <label class="settings-field">
                                <span>Lockout Duration (minutes)</span>
                                <input type="number" name="lockout_minutes" min="1" max="120" step="1">
                            </label>
                        </div>
                    </section>

                    <section class="settings-card">
                        <div class="settings-card-header">
                            <h3>Session Security</h3>
                            <p>Control session duration and concurrent access behavior.</p>
                        </div>
                        <div class="settings-fields">
                            <label class="settings-field">
                                <span>Session Timeout (minutes)</span>
                                <input type="number" name="session_timeout_minutes" min="5" max="720" step="1">
                            </label>
                            <label class="settings-field">
                                <span>Idle Warning (minutes)</span>
                                <input type="number" name="session_idle_warning_minutes" min="1" max="60" step="1">
                            </label>
                            <label class="settings-field">
                                <span>Grace Period (minutes)</span>
                                <input type="number" name="grace_period_minutes" min="1" max="60" step="1">
                            </label>
                            <label class="settings-field">
                                <span>Task Due Window (business days)</span>
                                <input type="number" name="task_due_business_days" min="1" max="60" step="1">
                            </label>
                            <label class="settings-field">
                                <span>Task Grace Window (business days)</span>
                                <input type="number" name="task_grace_business_days" min="0" max="30" step="1">
                            </label>
                            <div class="settings-toggle">
                                <div>
                                    <div class="toggle-title">Enable Task Alert Engine</div>
                                    <div class="toggle-subtitle">Generate alerts for due-soon, overdue, and stalled tasks.</div>
                                </div>
                                <label class="switch">
                                    <input type="checkbox" name="task_alerts_enabled">
                                    <span class="slider"></span>
                                </label>
                            </div>
                            <label class="settings-field">
                                <span>Due Soon Alert Window (hours)</span>
                                <input type="number" name="task_alert_due_soon_hours" min="1" max="168" step="1">
                            </label>
                            <label class="settings-field">
                                <span>Stalled Task Window (hours)</span>
                                <input type="number" name="task_alert_stalled_hours" min="6" max="720" step="1">
                            </label>
                            <label class="settings-field">
                                <span>Critical Escalation (hours overdue)</span>
                                <input type="number" name="task_alert_escalation_hours" min="1" max="720" step="1">
                            </label>
                            <label class="settings-field">
                                <span>Payroll Reconcile Debounce (seconds)</span>
                                <input type="number" name="payroll_reconcile_debounce_seconds" min="15" max="900" step="1" placeholder="60">
                            </label>
                            <label class="settings-field">
                                <span>Max Concurrent Sessions</span>
                                <input type="number" name="max_concurrent_sessions" min="1" max="10" step="1">
                            </label>
                            <div class="settings-toggle">
                                <div>
                                    <div class="toggle-title">Skip Weekends For Task Due Dates</div>
                                    <div class="toggle-subtitle">Saturday and Sunday do not count as task due days.</div>
                                </div>
                                <label class="switch">
                                    <input type="checkbox" name="task_skip_weekends">
                                    <span class="slider"></span>
                                </label>
                            </div>
                            <div class="settings-toggle">
                                <div>
                                    <div class="toggle-title">Skip Uganda Public Holidays</div>
                                    <div class="toggle-subtitle">Official Uganda holidays do not count as task due days.</div>
                                </div>
                                <label class="switch">
                                    <input type="checkbox" name="task_skip_ug_holidays">
                                    <span class="slider"></span>
                                </label>
                            </div>
                            <div class="settings-toggle">
                                <div>
                                    <div class="toggle-title">Allow Multiple Devices</div>
                                    <div class="toggle-subtitle">Enable multi-device sessions per user.</div>
                                </div>
                                <label class="switch">
                                    <input type="checkbox" name="allow_multiple_devices">
                                    <span class="slider"></span>
                                </label>
                            </div>
                            <div class="settings-toggle">
                                <div>
                                    <div class="toggle-title">Auto Logout on Conflict</div>
                                    <div class="toggle-subtitle">Automatically end older sessions.</div>
                                </div>
                                <label class="switch">
                                    <input type="checkbox" name="auto_logout_on_conflict">
                                    <span class="slider"></span>
                                </label>
                            </div>
                        </div>
                    </section>

                    <section class="settings-card">
                        <div class="settings-card-header">
                            <h3>Security Alerts</h3>
                            <p>Decide where alerts and security notifications are sent.</p>
                        </div>
                        <div class="settings-fields">
                            <label class="settings-field">
                                <span>Alert Email</span>
                                <input type="email" name="security_alert_email" placeholder="security@pensions.go.ug">
                            </label>
                            <label class="settings-field">
                                <span>Alert SMS Contact</span>
                                <input type="text" name="security_alert_sms" placeholder="+2567...">
                            </label>
                            <div class="settings-toggle">
                                <div>
                                    <div class="toggle-title">Enable Activity Logs</div>
                                    <div class="toggle-subtitle">Record authentication and session events.</div>
                                </div>
                                <label class="switch">
                                    <input type="checkbox" name="enable_activity_logs">
                                    <span class="slider"></span>
                                </label>
                            </div>
                            <div class="settings-toggle">
                                <div>
                                    <div class="toggle-title">Enable Audit Logs</div>
                                    <div class="toggle-subtitle">Track administrative and data changes.</div>
                                </div>
                                <label class="switch">
                                    <input type="checkbox" name="enable_audit_logs">
                                    <span class="slider"></span>
                                </label>
                            </div>
                            <div class="settings-toggle">
                                <div>
                                    <div class="toggle-title">Notify Task Alerts</div>
                                    <div class="toggle-subtitle">Queue task-delay alert notifications for assigned users and admins.</div>
                                </div>
                                <label class="switch">
                                    <input type="checkbox" name="notify_task_alerts_enabled">
                                    <span class="slider"></span>
                                </label>
                            </div>
                        </div>
                    </section>

                    <section class="settings-card">
                        <div class="settings-card-header">
                            <h3>Client-Side Protection</h3>
                            <p>Control browser-side restrictions for authenticated pages. These are deterrents, not substitutes for backend authorization.</p>
                        </div>
                        <div class="settings-fields">
                            <div class="settings-toggle">
                                <div>
                                    <div class="toggle-title">Block Developer Tools</div>
                                    <div class="toggle-subtitle">Prevent common developer-tools shortcuts such as F12 and Ctrl+Shift+I/J/C on authenticated pages.</div>
                                </div>
                                <label class="switch">
                                    <input type="checkbox" name="security_block_developer_tools">
                                    <span class="slider"></span>
                                </label>
                            </div>
                            <div class="settings-toggle">
                                <div>
                                    <div class="toggle-title">Block Right Click</div>
                                    <div class="toggle-subtitle">Disable the context menu on authenticated screens.</div>
                                </div>
                                <label class="switch">
                                    <input type="checkbox" name="security_block_context_menu">
                                    <span class="slider"></span>
                                </label>
                            </div>
                            <div class="settings-toggle">
                                <div>
                                    <div class="toggle-title">Block Copy</div>
                                    <div class="toggle-subtitle">Disable keyboard and browser copy actions for protected pages.</div>
                                </div>
                                <label class="switch">
                                    <input type="checkbox" name="security_block_copy">
                                    <span class="slider"></span>
                                </label>
                            </div>
                            <div class="settings-toggle">
                                <div>
                                    <div class="toggle-title">Block Cut</div>
                                    <div class="toggle-subtitle">Prevent cut operations on protected pages.</div>
                                </div>
                                <label class="switch">
                                    <input type="checkbox" name="security_block_cut">
                                    <span class="slider"></span>
                                </label>
                            </div>
                            <div class="settings-toggle">
                                <div>
                                    <div class="toggle-title">Block Paste</div>
                                    <div class="toggle-subtitle">Prevent paste actions on protected pages.</div>
                                </div>
                                <label class="switch">
                                    <input type="checkbox" name="security_block_paste">
                                    <span class="slider"></span>
                                </label>
                            </div>
                            <div class="settings-toggle">
                                <div>
                                    <div class="toggle-title">Block Text Selection</div>
                                    <div class="toggle-subtitle">Disable manual text highlighting on authenticated pages.</div>
                                </div>
                                <label class="switch">
                                    <input type="checkbox" name="security_block_text_selection">
                                    <span class="slider"></span>
                                </label>
                            </div>
                            <div class="settings-toggle">
                                <div>
                                    <div class="toggle-title">Block Drag & Drop</div>
                                    <div class="toggle-subtitle">Prevent dragging text or assets out of protected views.</div>
                                </div>
                                <label class="switch">
                                    <input type="checkbox" name="security_block_drag">
                                    <span class="slider"></span>
                                </label>
                            </div>
                        </div>
                    </section>

                    <section class="settings-card">
                        <div class="settings-card-header">
                            <h3>Request Hardening</h3>
                            <p>Protect authenticated requests against forgery and cap heavy uploads before they reach import or restore routines.</p>
                        </div>
                        <div class="settings-fields">
                            <div class="settings-toggle">
                                <div>
                                    <div class="toggle-title">Enforce CSRF Tokens</div>
                                    <div class="toggle-subtitle">Require a valid anti-forgery token on authenticated POST, PUT, PATCH, and DELETE requests.</div>
                                </div>
                                <label class="switch">
                                    <input type="checkbox" name="security_enforce_csrf">
                                    <span class="slider"></span>
                                </label>
                            </div>
                            <div class="settings-toggle">
                                <div>
                                    <div class="toggle-title">Validate Request Origin</div>
                                    <div class="toggle-subtitle">Reject authenticated write requests coming from an unexpected Origin header.</div>
                                </div>
                                <label class="switch">
                                    <input type="checkbox" name="security_validate_origin">
                                    <span class="slider"></span>
                                </label>
                            </div>
                            <label class="settings-field">
                                <span>Allowed External Origins</span>
                                <textarea name="security_allowed_origins" rows="3" placeholder="https://admin.example.go.ug, https://portal.example.go.ug"></textarea>
                                <small class="field-hint">Optional comma- or line-separated allowlist for trusted external origins. Leave blank to allow only the current origin.</small>
                            </label>
                            <label class="settings-field">
                                <span>Admin Re-auth Window (Minutes)</span>
                                <input type="number" name="security_admin_reauth_window_minutes" min="1" max="120" step="1">
                            </label>
                            <label class="settings-field">
                                <span>Maximum Upload Size (MB)</span>
                                <input type="number" name="security_max_upload_size_mb" min="1" max="512" step="1">
                            </label>
                            <label class="settings-field">
                                <span>Maximum ZIP/XLSX Expanded Size (MB)</span>
                                <input type="number" name="security_max_zip_uncompressed_mb" min="4" max="1024" step="1">
                            </label>
                            <label class="settings-field">
                                <span>Maximum Import Rows</span>
                                <input type="number" name="security_max_import_rows" min="100" max="100000" step="100">
                            </label>
                            <label class="settings-field">
                                <span>Maximum ZIP Entries</span>
                                <input type="number" name="security_max_zip_entries" min="100" max="100000" step="100">
                            </label>
                        </div>
                    </section>
                </form>
            </div>
        `;
    }

    async loadAccessControlContent() {
        return `
            <div class="settings-content access-control-settings">
                <div class="settings-header">
                    <div>
                        <h2 class="section-title">Access Control</h2>
                        <p class="section-subtitle">Grant or restrict user-level permissions for sensitive operations across registry, payroll, and workflow tools.</p>
                    </div>
                    <div class="settings-actions">
                        <span class="settings-status" id="permissionSettingsStatus">Ready</span>
                        <button class="action-btn secondary" id="openPensionerPasswordToolBtn" type="button">Pensioner Passwords</button>
                        <button class="action-btn secondary" id="refreshAccessControlBtn" type="button">Refresh</button>
                        <button class="action-btn secondary" id="resetAccessControlBtn" type="button">Reset</button>
                        <button class="action-btn" id="saveAccessControlBtn" type="button" disabled>Save Permissions</button>
                    </div>
                </div>

                <div class="settings-note">
                    Defaults come from user role. Use overrides to allow or deny a permission for a specific user. Changes apply immediately after save.
                </div>

                <div class="settings-grid access-control-grid">
                    <section class="settings-card access-control-card">
                        <div class="settings-card-header">
                            <h3>User Selection</h3>
                            <p>Choose a user account to view and update override permissions.</p>
                        </div>
                        <div class="settings-fields">
                            <label class="settings-field">
                                <span>User Account</span>
                                <select id="accessControlUserSelect">
                                    <option value="">Loading users...</option>
                                </select>
                            </label>
                            <div class="settings-summary" id="accessControlUserMeta">
                                <div class="summary-row">
                                    <span class="summary-label">Selected User</span>
                                    <span class="summary-value">Loading...</span>
                                </div>
                            </div>
                            <div class="settings-summary">
                                <div class="summary-row">
                                    <span class="summary-label">Override Modes</span>
                                    <span class="summary-value">Default = role policy, Allow = force permit, Deny = force block</span>
                                </div>
                            </div>
                        </div>
                    </section>

                    <section class="settings-card access-control-card access-control-matrix-card">
                        <div class="settings-card-header">
                            <h3>Permission Matrix</h3>
                            <p>Assign per-user overrides for restricted tasks and key data sources.</p>
                        </div>
                        <div class="settings-table-container access-control-table-container">
                            <table class="settings-table access-control-table">
                                <thead>
                                    <tr>
                                        <th>Permission</th>
                                        <th>Default Roles</th>
                                        <th>Override</th>
                                        <th>Effective</th>
                                        <th>Notes</th>
                                    </tr>
                                </thead>
                                <tbody id="accessControlPermissionTableBody">
                                    <tr>
                                        <td colspan="5">
                                            <div class="table-loading">Loading permissions...</div>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </section>
                </div>

                <div class="admin-modal-overlay" id="pensionerPasswordModalOverlay" style="display: none;">
                    <div class="admin-modal pensioner-password-modal">
                        <div class="admin-modal-header">
                            <h3>Pensioner Password Management</h3>
                            <button class="admin-modal-close" id="closePensionerPasswordModalBtn" type="button">&times;</button>
                        </div>
                        <div class="admin-modal-body">
                            <p class="modal-hint">Search and select a pensioner account, then reset to default password or apply a custom password.</p>
                            <div class="form-grid">
                                <div class="form-field form-span">
                                    <label for="pensionerAccountSearchInput">Pensioner Account</label>
                                    <input type="text" id="pensionerAccountSearchInput" list="pensionerAccountOptions" placeholder="Type pensioner name..." autocomplete="off">
                                    <datalist id="pensionerAccountOptions"></datalist>
                                    <small class="field-help" id="pensionerAccountFilterHint">Tip: focus the dropdown and type to filter by name.</small>
                                </div>
                                <div class="form-field form-span pensioner-duplicate-picker" id="pensionerDuplicateDisambiguation" style="display: none;">
                                    <label for="pensionerDuplicateSelect">Multiple accounts have this name</label>
                                    <select id="pensionerDuplicateSelect">
                                        <option value="">Select exact account...</option>
                                    </select>
                                    <small class="field-help">Choose the exact account to continue.</small>
                                </div>
                                <div class="form-field form-span">
                                    <div class="settings-summary" id="pensionerAccountSelectedMeta">
                                        <div class="summary-row">
                                            <span class="summary-label">Selected Account</span>
                                            <span class="summary-value">None selected</span>
                                        </div>
                                    </div>
                                </div>
                                <div class="form-field form-span">
                                    <label class="pensioner-password-toggle">
                                        <input type="checkbox" id="pensionerUseCustomPasswordToggle">
                                        <span>Use custom password</span>
                                    </label>
                                    <small class="field-help">Leave unchecked to reset password to default: <strong>Pensioner123</strong>.</small>
                                </div>
                                <div class="form-field form-span" id="pensionerCustomPasswordField" style="display: none;">
                                    <label for="pensionerCustomPasswordInput">Custom Password</label>
                                    <input type="password" id="pensionerCustomPasswordInput" placeholder="Enter custom password">
                                </div>
                            </div>
                        </div>
                        <div class="admin-modal-footer">
                            <button class="action-btn secondary" id="cancelPensionerPasswordBtn" type="button">Cancel</button>
                            <button class="action-btn" id="applyPensionerPasswordBtn" type="button">Apply Password Update</button>
                        </div>
                    </div>
                </div>
            </div>
        `;
    }

    async initializeAccessControlSettings() {
        this.permissionSettingsState = {
            users: [],
            selectedUserId: '',
            selectedUser: null,
            catalog: [],
            permissions: [],
            isDirty: false
        };
        this.pensionerPasswordState = {
            accounts: [],
            filterQuery: '',
            duplicateMatches: [],
            selectedUserId: '',
            selectedAccount: null
        };

        const userSelect = document.getElementById('accessControlUserSelect');
        const saveBtn = document.getElementById('saveAccessControlBtn');
        const refreshBtn = document.getElementById('refreshAccessControlBtn');
        const resetBtn = document.getElementById('resetAccessControlBtn');
        const openPensionerToolBtn = document.getElementById('openPensionerPasswordToolBtn');
        const closePensionerToolBtn = document.getElementById('closePensionerPasswordModalBtn');
        const cancelPensionerToolBtn = document.getElementById('cancelPensionerPasswordBtn');
        const applyPensionerToolBtn = document.getElementById('applyPensionerPasswordBtn');
        const pensionerSearchInput = document.getElementById('pensionerAccountSearchInput');
        const pensionerDuplicateSelect = document.getElementById('pensionerDuplicateSelect');
        const customPasswordToggle = document.getElementById('pensionerUseCustomPasswordToggle');
        const overlay = this.resolvePensionerPasswordModalOverlay();

        if (userSelect) {
            userSelect.addEventListener('change', () => {
                this.loadAccessControlData(userSelect.value || '');
            });
        }

        if (saveBtn) {
            saveBtn.addEventListener('click', () => this.saveAccessControlSettings());
        }

        if (refreshBtn) {
            refreshBtn.addEventListener('click', () => {
                const selectedUserId = document.getElementById('accessControlUserSelect')?.value || '';
                this.loadAccessControlData(selectedUserId);
            });
        }

        if (resetBtn) {
            resetBtn.addEventListener('click', () => {
                const selectedUserId = document.getElementById('accessControlUserSelect')?.value || '';
                this.loadAccessControlData(selectedUserId);
            });
        }

        if (openPensionerToolBtn) {
            openPensionerToolBtn.addEventListener('click', () => this.openPensionerPasswordModal());
        }
        if (closePensionerToolBtn) {
            closePensionerToolBtn.addEventListener('click', () => this.closePensionerPasswordModal());
        }
        if (cancelPensionerToolBtn) {
            cancelPensionerToolBtn.addEventListener('click', () => this.closePensionerPasswordModal());
        }
        if (applyPensionerToolBtn) {
            applyPensionerToolBtn.addEventListener('click', () => this.applyPensionerPasswordUpdate());
        }
        if (pensionerSearchInput) {
            pensionerSearchInput.addEventListener('input', () => {
                this.handlePensionerPasswordAccountInput(pensionerSearchInput.value || '');
            });
            pensionerSearchInput.addEventListener('change', () => {
                this.resolvePensionerPasswordAccountFromInput(pensionerSearchInput.value || '', true);
            });
            pensionerSearchInput.addEventListener('focus', () => {
                this.renderPensionerPasswordAccountOptions(String(pensionerSearchInput.value || ''));
                this.updatePensionerAccountFilterHint();
            });
        }
        if (pensionerDuplicateSelect) {
            pensionerDuplicateSelect.addEventListener('change', () => {
                const selectedUserId = pensionerDuplicateSelect.value || '';
                if (selectedUserId) {
                    this.selectPensionerPasswordAccount(selectedUserId);
                    this.renderPensionerDuplicateDisambiguation([]);
                }
            });
        }
        if (customPasswordToggle) {
            customPasswordToggle.addEventListener('change', () => this.togglePensionerCustomPasswordField());
        }
        if (overlay) {
            overlay.addEventListener('click', (event) => {
                if (event.target === overlay) {
                    this.closePensionerPasswordModal();
                }
            });
        }

        await this.loadAccessControlData('');
    }

    async loadAccessControlData(selectedUserId = '') {
        const tableBody = document.getElementById('accessControlPermissionTableBody');
        if (!tableBody) return;

        const query = selectedUserId
            ? `?user_id=${encodeURIComponent(selectedUserId)}`
            : '';

        try {
            this.updateSettingsStatus('permission', 'Loading...', 'info');
            tableBody.innerHTML = `
                <tr>
                    <td colspan="5">
                        <div class="table-loading">Loading permissions...</div>
                    </td>
                </tr>
            `;

            const response = await fetch(`../backend/api/get_user_permissions_admin.php${query}`, {
                credentials: 'include',
                cache: 'no-store'
            });
            const data = await this.safeJson(response, { success: false });

            if (!response.ok || !data.success) {
                this.updateSettingsStatus('permission', 'Load failed', 'error');
                this.showNotification(data.message || 'Unable to load access control settings.', 'error');
                const saveBtn = document.getElementById('saveAccessControlBtn');
                if (saveBtn) saveBtn.disabled = true;
                tableBody.innerHTML = `
                    <tr>
                        <td colspan="5">
                            <div class="table-empty">Unable to load permissions.</div>
                        </td>
                    </tr>
                `;
                return;
            }

            this.permissionSettingsState = {
                users: Array.isArray(data.users) ? data.users : [],
                selectedUserId: data.selected_user_id || '',
                selectedUser: data.selected_user || null,
                catalog: Array.isArray(data.catalog) ? data.catalog : [],
                permissions: Array.isArray(data.permissions) ? data.permissions : [],
                isDirty: false
            };
            this.updateRoleCaches(data.role_labels || {}, []);

            this.renderAccessControlData();
            this.updateSettingsStatus('permission', 'Up to date', 'success');
        } catch (error) {
            console.error('Load access control settings error:', error);
            this.updateSettingsStatus('permission', 'Load failed', 'error');
            this.showNotification('Unable to load access control settings.', 'error');
            const saveBtn = document.getElementById('saveAccessControlBtn');
            if (saveBtn) saveBtn.disabled = true;
            tableBody.innerHTML = `
                <tr>
                    <td colspan="5">
                        <div class="table-empty">Unable to load permissions.</div>
                    </td>
                </tr>
            `;
        }
    }

    renderAccessControlData() {
        const state = this.permissionSettingsState || {};
        const users = Array.isArray(state.users)
            ? state.users.filter((user) => String(user?.userRole || '').toLowerCase() !== 'pensioner')
            : [];
        const permissions = Array.isArray(state.permissions) ? state.permissions : [];
        const catalog = Array.isArray(state.catalog) ? state.catalog : [];
        const catalogMap = new Map(catalog.map((entry) => [entry.key, entry]));

        const userSelect = document.getElementById('accessControlUserSelect');
        const userMeta = document.getElementById('accessControlUserMeta');
        const tableBody = document.getElementById('accessControlPermissionTableBody');
        const saveBtn = document.getElementById('saveAccessControlBtn');

        if (userSelect) {
            if (!users.length) {
                userSelect.innerHTML = '<option value="">No users available</option>';
            } else {
                userSelect.innerHTML = users.map((user) => {
                    const userName = this.escapeHtml(user.userName || 'Unnamed User');
                    const userEmail = this.escapeHtml(user.userEmail || 'No email');
                    const roleLabel = this.escapeHtml(this.formatRoleLabel(user.userRole || 'user'));
                    const value = this.escapeHtml(user.userId || '');
                    return `<option value="${value}">${userName} (${roleLabel}) - ${userEmail}</option>`;
                }).join('');
            }

            if (state.selectedUserId) {
                userSelect.value = state.selectedUserId;
            }
        }

        if (userMeta) {
            if (state.selectedUser) {
                userMeta.innerHTML = `
                    <div class="summary-row">
                        <span class="summary-label">Selected User</span>
                        <span class="summary-value">${this.escapeHtml(state.selectedUser.userName || 'Unknown')}</span>
                    </div>
                    <div class="summary-row">
                        <span class="summary-label">Role</span>
                        <span class="summary-value">${this.escapeHtml(this.formatRoleLabel(state.selectedUser.userRole || 'user'))}</span>
                    </div>
                    <div class="summary-row">
                        <span class="summary-label">Email</span>
                        <span class="summary-value">${this.escapeHtml(state.selectedUser.userEmail || 'No email')}</span>
                    </div>
                `;
            } else {
                userMeta.innerHTML = `
                    <div class="summary-row">
                        <span class="summary-label">Selected User</span>
                        <span class="summary-value">No users available</span>
                    </div>
                `;
            }
        }

        if (!tableBody) return;

        if (!state.selectedUser || permissions.length === 0) {
            tableBody.innerHTML = `
                <tr>
                    <td colspan="5">
                        <div class="table-empty">No permission rows found for the selected user.</div>
                    </td>
                </tr>
            `;
            if (saveBtn) saveBtn.disabled = true;
            return;
        }

        tableBody.innerHTML = permissions.map((row) => {
            const key = row.key || '';
            const catalogMeta = catalogMap.get(key) || {};
            const label = row.label || catalogMeta.label || key;
            const description = row.description || catalogMeta.description || '';
            const defaultRoles = Array.isArray(catalogMeta.default_roles) ? catalogMeta.default_roles : [];
            const defaultsText = defaultRoles.length
                ? defaultRoles.map((role) => this.formatRoleLabel(role)).join(', ')
                : 'No default roles';
            const mode = ['default', 'allow', 'deny'].includes(row.mode) ? row.mode : 'default';
            const defaultAllowed = Boolean(row.default_allowed);
            const effectiveAllowed = this.computeAccessControlEffectiveAllowed(mode, defaultAllowed);
            const effectiveClass = effectiveAllowed ? 'allowed' : 'denied';
            const effectiveLabel = effectiveAllowed ? 'Allowed' : 'Denied';
            const noteValue = this.escapeHtml(row.notes || '');
            const updatedAt = row.updated_at
                ? `<div class="access-control-updated">Updated: ${this.escapeHtml(row.updated_at)}</div>`
                : '';

            return `
                <tr data-permission-key="${this.escapeHtml(key)}" data-default-allowed="${defaultAllowed ? '1' : '0'}">
                    <td>
                        <div class="access-control-permission">
                            <strong>${this.escapeHtml(label)}</strong>
                            <small>${this.escapeHtml(description)}</small>
                            ${updatedAt}
                        </div>
                    </td>
                    <td>
                        <span class="access-control-defaults">${this.escapeHtml(defaultsText)}</span>
                    </td>
                    <td>
                        <select class="access-control-mode" data-permission-key="${this.escapeHtml(key)}">
                            <option value="default" ${mode === 'default' ? 'selected' : ''}>Default</option>
                            <option value="allow" ${mode === 'allow' ? 'selected' : ''}>Allow</option>
                            <option value="deny" ${mode === 'deny' ? 'selected' : ''}>Deny</option>
                        </select>
                    </td>
                    <td>
                        <span class="access-control-effective ${effectiveClass}" data-effective-for="${this.escapeHtml(key)}">${effectiveLabel}</span>
                    </td>
                    <td>
                        <input
                            type="text"
                            class="access-control-notes"
                            data-note-for="${this.escapeHtml(key)}"
                            maxlength="300"
                            value="${noteValue}"
                            placeholder="Optional note"
                        >
                    </td>
                </tr>
            `;
        }).join('');

        this.bindAccessControlRows();
        if (saveBtn) saveBtn.disabled = false;
    }

    bindAccessControlRows() {
        const modeSelects = document.querySelectorAll('.access-control-mode');
        const noteInputs = document.querySelectorAll('.access-control-notes');
        const saveBtn = document.getElementById('saveAccessControlBtn');
        const updateDirty = () => {
            if (saveBtn) {
                saveBtn.disabled = false;
            }
            if (this.permissionSettingsState) {
                this.permissionSettingsState.isDirty = true;
            }
            this.updateSettingsStatus('permission', 'Unsaved changes', 'info');
        };

        modeSelects.forEach((select) => {
            select.addEventListener('change', () => {
                const row = select.closest('tr');
                const defaultAllowed = row?.getAttribute('data-default-allowed') === '1';
                const effective = this.computeAccessControlEffectiveAllowed(select.value, defaultAllowed);
                const effectiveEl = row?.querySelector('.access-control-effective');
                if (effectiveEl) {
                    effectiveEl.textContent = effective ? 'Allowed' : 'Denied';
                    effectiveEl.classList.toggle('allowed', effective);
                    effectiveEl.classList.toggle('denied', !effective);
                }
                updateDirty();
            });
        });

        noteInputs.forEach((input) => {
            input.addEventListener('input', () => {
                updateDirty();
            });
        });
    }

    computeAccessControlEffectiveAllowed(mode, defaultAllowed) {
        if (mode === 'allow') return true;
        if (mode === 'deny') return false;
        return Boolean(defaultAllowed);
    }

    async saveAccessControlSettings() {
        const state = this.permissionSettingsState || {};
        const selectedUserId = state.selectedUserId || document.getElementById('accessControlUserSelect')?.value || '';
        if (!selectedUserId) {
            this.showNotification('Select a user to save permission overrides.', 'info');
            return;
        }

        const rows = Array.from(document.querySelectorAll('#accessControlPermissionTableBody tr[data-permission-key]'));
        if (!rows.length) {
            this.showNotification('No permission rows available to save.', 'info');
            return;
        }

        const permissions = {};
        rows.forEach((row) => {
            const key = row.getAttribute('data-permission-key') || '';
            if (!key) return;
            const mode = row.querySelector('.access-control-mode')?.value || 'default';
            const notes = row.querySelector('.access-control-notes')?.value || '';
            permissions[key] = {
                mode,
                notes: String(notes).trim()
            };
        });

        try {
            this.updateSettingsStatus('permission', 'Saving...', 'info');
            const response = await fetch('../backend/api/update_user_permissions_admin.php', {
                method: 'POST',
                credentials: 'include',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    user_id: selectedUserId,
                    permissions
                })
            });
            const data = await this.safeJson(response, { success: false });
            if (!response.ok || !data.success) {
                this.updateSettingsStatus('permission', 'Save failed', 'error');
                this.showNotification(data.message || 'Unable to save permission settings.', 'error');
                return;
            }

            this.showNotification(data.message || 'Access control settings updated.', 'success');
            this.updateSettingsStatus('permission', 'Saved', 'success');
            await this.loadAccessControlData(selectedUserId);
        } catch (error) {
            console.error('Save access control settings error:', error);
            this.updateSettingsStatus('permission', 'Save failed', 'error');
            this.showNotification('Unable to save permission settings.', 'error');
        }
    }

    resolvePensionerPasswordModalOverlay() {
        const overlays = Array.from(document.querySelectorAll('#pensionerPasswordModalOverlay'));
        if (!overlays.length) {
            return null;
        }

        const contentBody = document.getElementById('contentBody');
        const preferredOverlay = overlays.find((node) => contentBody && contentBody.contains(node));
        const overlay = preferredOverlay || overlays[0];

        // Keep a single instance to avoid stale duplicated overlays after section reloads.
        overlays.forEach((node) => {
            if (node !== overlay) {
                node.remove();
            }
        });

        // Mount at body level so fixed positioning is not constrained by admin content containers.
        if (overlay.parentElement !== document.body) {
            document.body.appendChild(overlay);
        }

        overlay.style.position = 'fixed';
        overlay.style.inset = '0';
        overlay.style.zIndex = '3001';

        return overlay;
    }

    async openPensionerPasswordModal() {
        const overlay = this.resolvePensionerPasswordModalOverlay();
        if (!overlay) return;

        overlay.style.display = 'flex';
        document.body.classList.add('modal-open');
        await this.loadPensionerPasswordAccounts();
        this.togglePensionerCustomPasswordField();
        this.updatePensionerAccountFilterHint();
    }

    closePensionerPasswordModal() {
        const overlay = this.resolvePensionerPasswordModalOverlay();
        if (overlay) {
            overlay.style.display = 'none';
        }
        document.body.classList.remove('modal-open');
    }

    async loadPensionerPasswordAccounts() {
        const accountInput = document.getElementById('pensionerAccountSearchInput');
        const accountOptions = document.getElementById('pensionerAccountOptions');
        const selectedMeta = document.getElementById('pensionerAccountSelectedMeta');

        if (accountInput) {
            accountInput.value = '';
            accountInput.placeholder = 'Loading pensioner accounts...';
            accountInput.disabled = true;
        }
        if (accountOptions) {
            accountOptions.innerHTML = '';
        }

        try {
            const response = await fetch('../backend/api/get_pensioner_accounts_admin.php', {
                credentials: 'include',
                cache: 'no-store'
            });
            const data = await this.safeJson(response, { success: false, accounts: [] });
            if (!response.ok || !data.success) {
                throw new Error(data.message || 'Unable to load pensioner accounts.');
            }

            this.pensionerPasswordState = {
                accounts: Array.isArray(data.accounts) ? data.accounts : [],
                filterQuery: '',
                duplicateMatches: [],
                selectedUserId: '',
                selectedAccount: null
            };

            this.renderPensionerPasswordAccountOptions('');
            this.renderPensionerDuplicateDisambiguation([]);
            this.renderPensionerPasswordSelectedMeta();
            this.updatePensionerAccountFilterHint();
        } catch (error) {
            console.error('Load pensioner accounts error:', error);
            this.showNotification(error.message || 'Unable to load pensioner accounts.', 'error');
            if (accountInput) {
                accountInput.value = '';
                accountInput.placeholder = 'Unable to load pensioner accounts';
                accountInput.disabled = true;
            }
            if (accountOptions) {
                accountOptions.innerHTML = '';
            }
            if (selectedMeta) {
                selectedMeta.innerHTML = `
                    <div class="summary-row">
                        <span class="summary-label">Selected Account</span>
                        <span class="summary-value">None selected</span>
                    </div>
                `;
            }
            this.renderPensionerDuplicateDisambiguation([]);
        }
    }

    renderPensionerPasswordAccountOptions(filterPhrase = '') {
        const state = this.pensionerPasswordState || {};
        const accountInput = document.getElementById('pensionerAccountSearchInput');
        const optionsList = document.getElementById('pensionerAccountOptions');
        if (!accountInput || !optionsList) return;

        const accounts = Array.isArray(state.accounts) ? state.accounts : [];
        const query = String(filterPhrase || '').trim().toLowerCase();
        const filteredAccounts = query
            ? accounts.filter((account) => String(account.userName || '').toLowerCase().includes(query))
            : accounts;
        const uniqueNames = Array.from(
            new Set(
                filteredAccounts
                    .map((account) => String(account.userName || '').trim())
                    .filter((name) => name !== '')
            )
        );

        optionsList.innerHTML = uniqueNames.map((name) => {
            const safeName = this.escapeHtml(name);
            return `<option value="${safeName}"></option>`;
        }).join('');

        accountInput.disabled = accounts.length === 0;
        accountInput.placeholder = accounts.length
            ? 'Type pensioner name...'
            : 'No pensioner accounts found';
    }

    renderPensionerDuplicateDisambiguation(matches = []) {
        const container = document.getElementById('pensionerDuplicateDisambiguation');
        const duplicateSelect = document.getElementById('pensionerDuplicateSelect');
        if (!container || !duplicateSelect) return;

        const items = Array.isArray(matches) ? matches : [];
        if (!items.length) {
            container.style.display = 'none';
            duplicateSelect.innerHTML = '<option value="">Select exact account...</option>';
            duplicateSelect.value = '';
            return;
        }

        container.style.display = 'flex';
        duplicateSelect.innerHTML = ['<option value="">Select exact account...</option>']
            .concat(items.map((account) => {
                const label = [
                    String(account.userName || 'Unknown'),
                    String(account.userEmail || 'No email'),
                    String(account.phoneNo || 'No phone')
                ].join(' - ');
                return `<option value="${this.escapeHtml(String(account.userId || ''))}">${this.escapeHtml(label)}</option>`;
            }))
            .join('');
    }

    handlePensionerPasswordAccountInput(rawValue) {
        const typedName = String(rawValue || '');
        const state = this.pensionerPasswordState || {};
        this.pensionerPasswordState = {
            ...state,
            filterQuery: typedName,
            duplicateMatches: []
        };

        this.renderPensionerPasswordAccountOptions(typedName);
        this.resolvePensionerPasswordAccountFromInput(typedName, false);
        this.updatePensionerAccountFilterHint();
    }

    resolvePensionerPasswordAccountFromInput(rawValue, strict) {
        const typedName = String(rawValue || '').trim();
        const normalizedName = typedName.toLowerCase();
        const state = this.pensionerPasswordState || {};
        const allAccounts = Array.isArray(state.accounts) ? state.accounts : [];

        if (!typedName) {
            this.pensionerPasswordState = {
                ...state,
                duplicateMatches: [],
                selectedUserId: '',
                selectedAccount: null
            };
            this.renderPensionerDuplicateDisambiguation([]);
            this.renderPensionerPasswordSelectedMeta();
            return;
        }

        const exactMatches = allAccounts.filter((account) => String(account.userName || '').trim().toLowerCase() === normalizedName);
        if (exactMatches.length === 1) {
            this.renderPensionerDuplicateDisambiguation([]);
            this.selectPensionerPasswordAccount(exactMatches[0].userId || '');
            return;
        }

        if (exactMatches.length > 1) {
            this.pensionerPasswordState = {
                ...state,
                duplicateMatches: exactMatches,
                selectedUserId: '',
                selectedAccount: null
            };
            this.renderPensionerDuplicateDisambiguation(exactMatches);
            this.renderPensionerPasswordSelectedMeta();
            return;
        }

        const currentSelected = state.selectedAccount || null;
        const currentNameMatches = currentSelected
            && String(currentSelected.userName || '').trim().toLowerCase() === normalizedName;
        if (currentNameMatches) {
            return;
        }

        if (strict || exactMatches.length === 0) {
            this.pensionerPasswordState = {
                ...state,
                duplicateMatches: [],
                selectedUserId: '',
                selectedAccount: null
            };
            this.renderPensionerDuplicateDisambiguation([]);
            this.renderPensionerPasswordSelectedMeta();
        }
    }

    updatePensionerAccountFilterHint() {
        const hint = document.getElementById('pensionerAccountFilterHint');
        if (!hint) return;
        const duplicateCount = Number(this.pensionerPasswordState?.duplicateMatches?.length || 0);
        if (duplicateCount > 1) {
            hint.textContent = `${duplicateCount} matching accounts found. Use the selector below to choose one.`;
            return;
        }
        const query = String(this.pensionerPasswordState?.filterQuery || '').trim();
        hint.textContent = query
            ? `Filter: "${query}"`
            : 'Tip: type pensioner name and pick from the dropdown list.';
    }

    selectPensionerPasswordAccount(userId) {
        const state = this.pensionerPasswordState || {};
        const allAccounts = Array.isArray(state.accounts) ? state.accounts : [];
        const selected = allAccounts.find((account) => account.userId === userId) || null;

        this.pensionerPasswordState = {
            ...state,
            filterQuery: selected ? String(selected.userName || '') : String(state.filterQuery || ''),
            duplicateMatches: [],
            selectedUserId: selected ? selected.userId : '',
            selectedAccount: selected
        };

        const accountInput = document.getElementById('pensionerAccountSearchInput');
        if (accountInput) {
            accountInput.value = selected ? String(selected.userName || '') : '';
        }

        this.renderPensionerPasswordSelectedMeta();
        this.renderPensionerDuplicateDisambiguation([]);
        this.updatePensionerAccountFilterHint();
    }

    renderPensionerPasswordSelectedMeta() {
        const selectedMeta = document.getElementById('pensionerAccountSelectedMeta');
        const state = this.pensionerPasswordState || {};
        const selected = state.selectedAccount || null;
        if (!selectedMeta) return;

        if (!selected) {
            selectedMeta.innerHTML = `
                <div class="summary-row">
                    <span class="summary-label">Selected Account</span>
                    <span class="summary-value">None selected</span>
                </div>
            `;
            return;
        }

        selectedMeta.innerHTML = `
            <div class="summary-row">
                <span class="summary-label">Selected Account</span>
                <span class="summary-value">${this.escapeHtml(selected.userName || 'Unknown')}</span>
            </div>
            <div class="summary-row">
                <span class="summary-label">Email</span>
                <span class="summary-value">${this.escapeHtml(selected.userEmail || 'No email')}</span>
            </div>
            <div class="summary-row">
                <span class="summary-label">Phone</span>
                <span class="summary-value">${this.escapeHtml(selected.phoneNo || 'No phone')}</span>
            </div>
        `;
    }

    togglePensionerCustomPasswordField() {
        const customToggle = document.getElementById('pensionerUseCustomPasswordToggle');
        const customField = document.getElementById('pensionerCustomPasswordField');
        const customInput = document.getElementById('pensionerCustomPasswordInput');
        const useCustom = Boolean(customToggle?.checked);
        if (customField) {
            customField.style.display = useCustom ? 'flex' : 'none';
        }
        if (!useCustom && customInput) {
            customInput.value = '';
        }
    }

    async applyPensionerPasswordUpdate() {
        const state = this.pensionerPasswordState || {};
        const selectedUserId = state.selectedUserId || '';
        const customToggle = document.getElementById('pensionerUseCustomPasswordToggle');
        const customInput = document.getElementById('pensionerCustomPasswordInput');
        const useCustom = Boolean(customToggle?.checked);
        const customPassword = String(customInput?.value || '').trim();

        if (!selectedUserId) {
            this.showNotification('Select a pensioner account first.', 'info');
            return;
        }

        if (useCustom && customPassword === '') {
            this.showNotification('Enter a custom password before saving.', 'error');
            return;
        }

        try {
            const response = await fetch('../backend/api/update_pensioner_password_admin.php', {
                method: 'POST',
                credentials: 'include',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    user_id: selectedUserId,
                    use_custom_password: useCustom,
                    custom_password: useCustom ? customPassword : ''
                })
            });
            const data = await this.safeJson(response, { success: false });
            if (!response.ok || !data.success) {
                this.showNotification(data.message || 'Unable to update pensioner password.', 'error');
                return;
            }

            this.showNotification(data.message || 'Pensioner password updated successfully.', 'success');
            this.closePensionerPasswordModal();
        } catch (error) {
            console.error('Update pensioner password error:', error);
            this.showNotification('Unable to update pensioner password.', 'error');
        }
    }

    async loadRoleSettingsContent() {
        return `
            <div class="settings-content role-settings-content">
                <div class="settings-header">
                    <div>
                        <h2 class="section-title">Role Governance</h2>
                        <p class="section-subtitle">Create roles, manage role labels, and configure default permission behavior per role.</p>
                    </div>
                    <div class="settings-actions">
                        <span class="settings-status" id="roleSettingsStatus">Ready</span>
                        <button class="action-btn secondary" id="refreshRoleSettingsBtn" type="button">Refresh</button>
                        <button class="action-btn secondary" id="resetRoleSettingsBtn" type="button">Reset</button>
                        <button class="action-btn" id="saveRolePermissionSettingsBtn" type="button" disabled>Save Permission Matrix</button>
                    </div>
                </div>

                <div class="settings-note">
                    Use role labels for user-facing naming and use permission overrides to change default role behavior without touching individual user overrides.
                </div>

                <div class="settings-grid role-governance-grid">
                    <section class="settings-card role-definition-card">
                        <div class="settings-card-header">
                            <h3>Role Definition</h3>
                            <p>Create a new role or update role label and availability.</p>
                        </div>
                        <div class="settings-fields">
                            <label class="settings-field">
                                <span>Role Selection</span>
                                <select id="roleSettingsRoleSelect">
                                    <option value="">Loading roles...</option>
                                </select>
                            </label>

                            <label class="settings-field">
                                <span>Clone Privileges From</span>
                                <select id="roleCloneFromSelect">
                                    <option value="">No clone (start with defaults)</option>
                                </select>
                                <small class="field-help">Optional for both new and existing roles. Saving applies cloned privileges immediately.</small>
                            </label>

                            <div class="settings-toolbar">
                                <button class="action-btn secondary" id="newRoleBtn" type="button">New Role</button>
                                <button class="action-btn" id="saveRoleDefinitionBtn" type="button">Save Role</button>
                                <button class="action-btn danger" id="deleteRoleDefinitionBtn" type="button" disabled>Delete Role</button>
                            </div>

                            <label class="settings-field">
                                <span>Role Key</span>
                                <input type="text" id="roleKeyInput" maxlength="50" placeholder="e.g. records_officer">
                                <small class="field-help">Lowercase letters, numbers, and underscores only. Key cannot be changed after creation.</small>
                            </label>

                            <label class="settings-field">
                                <span>Role UI Label</span>
                                <input type="text" id="roleLabelInput" maxlength="100" placeholder="e.g. Records Officer">
                            </label>

                            <label class="settings-field">
                                <span>Description</span>
                                <textarea id="roleDescriptionInput" rows="3" maxlength="500" placeholder="Describe what this role can do."></textarea>
                            </label>

                            <div class="settings-toggle">
                                <div>
                                    <div class="toggle-title">Role Active</div>
                                    <div class="toggle-subtitle">Inactive roles are hidden from role assignment selectors.</div>
                                </div>
                                <label class="switch">
                                    <input type="checkbox" id="roleActiveInput">
                                    <span class="slider"></span>
                                </label>
                            </div>

                            <div class="settings-summary" id="roleSettingsMeta">
                                <div class="summary-row">
                                    <span class="summary-label">Selected Role</span>
                                    <span class="summary-value">Loading...</span>
                                </div>
                            </div>
                        </div>
                    </section>

                    <section class="settings-card role-permission-card">
                        <div class="settings-card-header role-permission-header">
                            <div>
                                <h3>Role Permission Matrix</h3>
                                <p>Override catalog defaults for this role. Default mode follows base policy defined by the system.</p>
                            </div>
                            <div class="card-actions">
                                <button class="action-btn secondary" id="openRolePermissionPickerBtn" type="button">Manage Operations</button>
                            </div>
                        </div>
                        <div class="settings-table-container role-permission-table-container">
                            <table class="settings-table access-control-table">
                                <thead>
                                    <tr>
                                        <th>Permission</th>
                                        <th>Default Roles</th>
                                        <th>Role Mode</th>
                                        <th>Effective</th>
                                        <th>Notes</th>
                                    </tr>
                                </thead>
                                <tbody id="rolePermissionTableBody">
                                    <tr>
                                        <td colspan="5">
                                            <div class="table-loading">Loading permissions...</div>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </section>
                </div>
                <div class="admin-modal-overlay" id="rolePermissionPickerModal" aria-hidden="true" style="display: none;">
                    <div class="admin-modal role-permission-modal" role="dialog" aria-modal="true" aria-labelledby="rolePermissionPickerTitle">
                        <div class="admin-modal-header">
                            <div>
                                <h3 id="rolePermissionPickerTitle">Role Operations</h3>
                                <p id="rolePermissionPickerSubtitle">Select which operations this role can perform.</p>
                            </div>
                            <button class="admin-modal-close" id="closeRolePermissionPickerBtn" type="button">&times;</button>
                        </div>
                        <div class="admin-modal-body">
                            <div class="role-permission-toolbar">
                                <input type="text" id="rolePermissionSearchInput" placeholder="Search operations or permissions">
                                <div class="role-permission-bulk">
                                    <button class="action-btn secondary" id="rolePermissionResetBtn" type="button">Reset</button>
                                    <button class="action-btn secondary" id="rolePermissionAllowAllBtn" type="button">Allow All</button>
                                    <button class="action-btn secondary" id="rolePermissionDenyAllBtn" type="button">Deny All</button>
                                </div>
                            </div>
                            <div class="role-permission-list" id="rolePermissionList">
                                <div class="table-loading">Loading operations...</div>
                            </div>
                        </div>
                        <div class="admin-modal-footer">
                            <button class="action-btn secondary" id="rolePermissionCancelBtn" type="button">Cancel</button>
                            <button class="action-btn" id="rolePermissionApplyBtn" type="button">Apply to Matrix</button>
                        </div>
                    </div>
                </div>
            </div>
        `;
    }

    async initializeRoleSettings() {
        this.roleSettingsState = {
            roles: [],
            selectedRoleKey: '',
            selectedRole: null,
            catalog: [],
            permissions: [],
            cloneFromRoleKey: '',
            isDirty: false,
            createMode: false
        };

        const roleSelect = document.getElementById('roleSettingsRoleSelect');
        const roleCloneFromSelect = document.getElementById('roleCloneFromSelect');
        const newRoleBtn = document.getElementById('newRoleBtn');
        const saveRoleBtn = document.getElementById('saveRoleDefinitionBtn');
        const deleteRoleBtn = document.getElementById('deleteRoleDefinitionBtn');
        const savePermsBtn = document.getElementById('saveRolePermissionSettingsBtn');
        const refreshBtn = document.getElementById('refreshRoleSettingsBtn');
        const resetBtn = document.getElementById('resetRoleSettingsBtn');
        const openPickerBtn = document.getElementById('openRolePermissionPickerBtn');
        const pickerModal = document.getElementById('rolePermissionPickerModal');
        const pickerCloseBtn = document.getElementById('closeRolePermissionPickerBtn');
        const pickerCancelBtn = document.getElementById('rolePermissionCancelBtn');
        const pickerApplyBtn = document.getElementById('rolePermissionApplyBtn');
        const pickerSearchInput = document.getElementById('rolePermissionSearchInput');
        const pickerAllowAllBtn = document.getElementById('rolePermissionAllowAllBtn');
        const pickerDenyAllBtn = document.getElementById('rolePermissionDenyAllBtn');
        const pickerResetBtn = document.getElementById('rolePermissionResetBtn');

        if (roleSelect) {
            roleSelect.addEventListener('change', () => {
                const roleKey = roleSelect.value || '';
                this.loadRoleSettingsData(roleKey);
            });
        }

        if (roleCloneFromSelect) {
            roleCloneFromSelect.addEventListener('change', () => {
                if (!this.roleSettingsState) return;
                this.roleSettingsState.cloneFromRoleKey = String(roleCloneFromSelect.value || '').trim().toLowerCase();
            });
        }

        if (newRoleBtn) {
            newRoleBtn.addEventListener('click', () => this.prepareNewRoleForm());
        }

        if (saveRoleBtn) {
            saveRoleBtn.addEventListener('click', () => this.saveRoleDefinition());
        }

        if (deleteRoleBtn) {
            deleteRoleBtn.addEventListener('click', () => this.confirmRoleDelete());
        }

        if (savePermsBtn) {
            savePermsBtn.addEventListener('click', () => this.saveRolePermissionSettings());
        }

        if (refreshBtn) {
            refreshBtn.addEventListener('click', () => {
                const selectedRole = document.getElementById('roleSettingsRoleSelect')?.value || '';
                this.loadRoleSettingsData(selectedRole);
            });
        }

        if (resetBtn) {
            resetBtn.addEventListener('click', () => {
                const selectedRole = document.getElementById('roleSettingsRoleSelect')?.value || '';
                this.loadRoleSettingsData(selectedRole);
            });
        }

        this.rolePermissionPickerState = {
            filter: '',
            selections: new Map()
        };

        if (openPickerBtn) {
            openPickerBtn.addEventListener('click', () => this.openRolePermissionPicker());
        }
        if (pickerCloseBtn) {
            pickerCloseBtn.addEventListener('click', () => this.closeRolePermissionPicker());
        }
        if (pickerCancelBtn) {
            pickerCancelBtn.addEventListener('click', () => this.closeRolePermissionPicker());
        }
        if (pickerModal) {
            pickerModal.addEventListener('click', (event) => {
                if (event.target === pickerModal) {
                    this.closeRolePermissionPicker();
                }
            });
        }
        if (pickerApplyBtn) {
            pickerApplyBtn.addEventListener('click', () => this.applyRolePermissionPickerChanges());
        }
        if (pickerSearchInput) {
            pickerSearchInput.addEventListener('input', () => {
                if (!this.rolePermissionPickerState) return;
                this.rolePermissionPickerState.filter = String(pickerSearchInput.value || '').trim().toLowerCase();
                this.renderRolePermissionPickerList();
            });
        }
        if (pickerAllowAllBtn) {
            pickerAllowAllBtn.addEventListener('click', () => this.bulkUpdateRolePermissionPicker('allow'));
        }
        if (pickerDenyAllBtn) {
            pickerDenyAllBtn.addEventListener('click', () => this.bulkUpdateRolePermissionPicker('deny'));
        }
        if (pickerResetBtn) {
            pickerResetBtn.addEventListener('click', () => this.bulkUpdateRolePermissionPicker('default'));
        }

        await this.loadRoleSettingsData('');
    }

    async loadRoleSettingsData(roleKey = '') {
        const tableBody = document.getElementById('rolePermissionTableBody');
        if (tableBody) {
            tableBody.innerHTML = `
                <tr>
                    <td colspan="5">
                        <div class="table-loading">Loading role settings...</div>
                    </td>
                </tr>
            `;
        }

        try {
            this.updateSettingsStatus('role', 'Loading...', 'info');
            const query = roleKey ? `?role_key=${encodeURIComponent(roleKey)}` : '';
            const response = await fetch(`../backend/api/get_role_settings_admin.php${query}`, {
                credentials: 'include',
                cache: 'no-store'
            });
            const data = await this.safeJson(response, { success: false });

            if (!response.ok || !data.success) {
                this.updateSettingsStatus('role', 'Load failed', 'error');
                this.showNotification(data.message || 'Unable to load role settings.', 'error');
                if (tableBody) {
                    tableBody.innerHTML = `
                        <tr>
                            <td colspan="5">
                                <div class="table-empty">Unable to load role settings.</div>
                            </td>
                        </tr>
                    `;
                }
                return;
            }

            this.updateRoleCaches(data.role_labels || {}, data.roles || []);
            this.populateUserRoleFilterOptions();
            this.roleSettingsState = {
                roles: Array.isArray(data.roles) ? data.roles : [],
                selectedRoleKey: data.selected_role_key || '',
                selectedRole: data.selected_role || null,
                catalog: Array.isArray(data.catalog) ? data.catalog : [],
                permissions: Array.isArray(data.permissions) ? data.permissions : [],
                cloneFromRoleKey: '',
                isDirty: false,
                createMode: false
            };

            this.renderRoleSettingsData();
            this.updateSettingsStatus('role', 'Up to date', 'success');
        } catch (error) {
            console.error('Load role settings error:', error);
            this.updateSettingsStatus('role', 'Load failed', 'error');
            this.showNotification('Unable to load role settings.', 'error');
            if (tableBody) {
                tableBody.innerHTML = `
                    <tr>
                        <td colspan="5">
                            <div class="table-empty">Unable to load role settings.</div>
                        </td>
                    </tr>
                `;
            }
        }
    }

    renderRoleSettingsData() {
        const state = this.roleSettingsState || {};
        const roles = Array.isArray(state.roles) ? state.roles : [];
        const permissions = Array.isArray(state.permissions) ? state.permissions : [];
        const catalog = Array.isArray(state.catalog) ? state.catalog : [];
        const catalogMap = new Map(catalog.map((entry) => [entry.key, entry]));

        const roleSelect = document.getElementById('roleSettingsRoleSelect');
        const roleMeta = document.getElementById('roleSettingsMeta');
        const tableBody = document.getElementById('rolePermissionTableBody');
        const savePermsBtn = document.getElementById('saveRolePermissionSettingsBtn');
        const roleKeyInput = document.getElementById('roleKeyInput');
        const roleLabelInput = document.getElementById('roleLabelInput');
        const roleDescriptionInput = document.getElementById('roleDescriptionInput');
        const roleActiveInput = document.getElementById('roleActiveInput');
        const roleCloneFromSelect = document.getElementById('roleCloneFromSelect');
        const deleteRoleBtn = document.getElementById('deleteRoleDefinitionBtn');

        if (roleSelect) {
            if (!roles.length) {
                roleSelect.innerHTML = '<option value="">No roles available</option>';
            } else {
                const roleOptions = roles.map((role) => {
                    const value = this.escapeHtml(role.role_key || '');
                    const label = this.escapeHtml(role.role_label || this.formatRoleLabel(role.role_key || ''));
                    const inactiveSuffix = role.is_active ? '' : ' (Inactive)';
                    return `<option value="${value}">${label}${inactiveSuffix}</option>`;
                }).join('');
                roleSelect.innerHTML = `<option value="">Select role...</option>${roleOptions}`;
            }

            if (state.selectedRoleKey) {
                roleSelect.value = state.selectedRoleKey;
            }
        }

        if (roleCloneFromSelect) {
            if (!roles.length) {
                roleCloneFromSelect.innerHTML = '<option value="">No available source roles</option>';
            } else {
                const cloneOptions = roles.map((role) => {
                    const value = this.escapeHtml(role.role_key || '');
                    const label = this.escapeHtml(role.role_label || this.formatRoleLabel(role.role_key || ''));
                    return `<option value="${value}">${label}</option>`;
                }).join('');
                roleCloneFromSelect.innerHTML = `<option value="">No clone (start with defaults)</option>${cloneOptions}`;
            }

            const selectedCloneRole = String(state.cloneFromRoleKey || '').trim().toLowerCase();
            if (selectedCloneRole) {
                roleCloneFromSelect.value = selectedCloneRole;
            } else {
                roleCloneFromSelect.value = '';
            }

            if (state.selectedRoleKey) {
                const sameRoleOption = roleCloneFromSelect.querySelector(`option[value="${state.selectedRoleKey}"]`);
                if (sameRoleOption) {
                    sameRoleOption.disabled = true;
                }
            }
        }

        if (state.selectedRole && !state.createMode) {
            if (roleKeyInput) roleKeyInput.value = state.selectedRole.role_key || '';
            if (roleLabelInput) roleLabelInput.value = state.selectedRole.role_label || '';
            if (roleDescriptionInput) roleDescriptionInput.value = state.selectedRole.role_description || '';
            if (roleActiveInput) roleActiveInput.checked = Boolean(state.selectedRole.is_active);
            this.setRoleFormMode(false, Boolean(state.selectedRole.is_system));
        }

        if (deleteRoleBtn) {
            const canDelete = Boolean(state.selectedRole && !state.createMode && !state.selectedRole.is_system);
            deleteRoleBtn.disabled = !canDelete;
        }

        if (roleMeta) {
            if (state.createMode) {
                roleMeta.innerHTML = `
                    <div class="summary-row">
                        <span class="summary-label">Selected Role</span>
                        <span class="summary-value">Creating new role</span>
                    </div>
                `;
            } else if (state.selectedRole) {
                roleMeta.innerHTML = `
                    <div class="summary-row">
                        <span class="summary-label">Selected Role</span>
                        <span class="summary-value">${this.escapeHtml(state.selectedRole.role_label || 'Unknown')}</span>
                    </div>
                    <div class="summary-row">
                        <span class="summary-label">Role Key</span>
                        <span class="summary-value">${this.escapeHtml(state.selectedRole.role_key || 'N/A')}</span>
                    </div>
                    <div class="summary-row">
                        <span class="summary-label">Type</span>
                        <span class="summary-value">${state.selectedRole.is_system ? 'System Role' : 'Custom Role'}</span>
                    </div>
                `;
            } else {
                roleMeta.innerHTML = `
                    <div class="summary-row">
                        <span class="summary-label">Selected Role</span>
                        <span class="summary-value">No role selected</span>
                    </div>
                `;
            }
        }

        if (!tableBody) return;
        if (!state.selectedRole || state.createMode || !permissions.length) {
            tableBody.innerHTML = `
                <tr>
                    <td colspan="5">
                        <div class="table-empty">${state.createMode ? 'Save the new role to configure permission matrix.' : 'No permission rows found.'}</div>
                    </td>
                </tr>
            `;
            if (savePermsBtn) savePermsBtn.disabled = true;
            return;
        }

        tableBody.innerHTML = permissions.map((row) => {
            const key = row.key || '';
            const catalogMeta = catalogMap.get(key) || {};
            const label = row.label || catalogMeta.label || key;
            const description = row.description || catalogMeta.description || '';
            const defaultRoles = Array.isArray(catalogMeta.default_roles) ? catalogMeta.default_roles : [];
            const defaultsText = defaultRoles.length
                ? defaultRoles.map((role) => this.formatRoleLabel(role)).join(', ')
                : 'No default roles';
            const mode = ['default', 'allow', 'deny'].includes(row.mode) ? row.mode : 'default';
            const defaultAllowed = Boolean(row.default_allowed);
            const effectiveAllowed = this.computeAccessControlEffectiveAllowed(mode, defaultAllowed);
            const effectiveClass = effectiveAllowed ? 'allowed' : 'denied';
            const effectiveLabel = effectiveAllowed ? 'Allowed' : 'Denied';
            const noteValue = this.escapeHtml(row.notes || '');
            const updatedAt = row.updated_at
                ? `<div class="access-control-updated">Updated: ${this.escapeHtml(row.updated_at)}</div>`
                : '';

            return `
                <tr data-permission-key="${this.escapeHtml(key)}" data-default-allowed="${defaultAllowed ? '1' : '0'}">
                    <td>
                        <div class="access-control-permission">
                            <strong>${this.escapeHtml(label)}</strong>
                            <small>${this.escapeHtml(description)}</small>
                            ${updatedAt}
                        </div>
                    </td>
                    <td>
                        <span class="access-control-defaults">${this.escapeHtml(defaultsText)}</span>
                    </td>
                    <td>
                        <select class="role-access-mode" data-permission-key="${this.escapeHtml(key)}">
                            <option value="default" ${mode === 'default' ? 'selected' : ''}>Default</option>
                            <option value="allow" ${mode === 'allow' ? 'selected' : ''}>Allow</option>
                            <option value="deny" ${mode === 'deny' ? 'selected' : ''}>Deny</option>
                        </select>
                    </td>
                    <td>
                        <span class="access-control-effective ${effectiveClass}" data-effective-for="${this.escapeHtml(key)}">${effectiveLabel}</span>
                    </td>
                    <td>
                        <input
                            type="text"
                            class="role-access-notes"
                            data-note-for="${this.escapeHtml(key)}"
                            maxlength="300"
                            value="${noteValue}"
                            placeholder="Optional note"
                        >
                    </td>
                </tr>
            `;
        }).join('');

        this.bindRolePermissionRows();
        if (savePermsBtn) savePermsBtn.disabled = false;

        const pickerModal = document.getElementById('rolePermissionPickerModal');
        if (pickerModal && pickerModal.style.display !== 'none') {
            this.renderRolePermissionPickerList();
        }
    }

    bindRolePermissionRows() {
        const modeSelects = document.querySelectorAll('.role-access-mode');
        const noteInputs = document.querySelectorAll('.role-access-notes');
        const savePermsBtn = document.getElementById('saveRolePermissionSettingsBtn');
        const markDirty = () => {
            if (savePermsBtn) {
                savePermsBtn.disabled = false;
            }
            if (this.roleSettingsState) {
                this.roleSettingsState.isDirty = true;
            }
            this.updateSettingsStatus('role', 'Unsaved changes', 'info');
        };

        modeSelects.forEach((select) => {
            select.addEventListener('change', () => {
                const row = select.closest('tr');
                const defaultAllowed = row?.getAttribute('data-default-allowed') === '1';
                const effective = this.computeAccessControlEffectiveAllowed(select.value, defaultAllowed);
                const effectiveEl = row?.querySelector('.access-control-effective');
                if (effectiveEl) {
                    effectiveEl.textContent = effective ? 'Allowed' : 'Denied';
                    effectiveEl.classList.toggle('allowed', effective);
                    effectiveEl.classList.toggle('denied', !effective);
                }
                markDirty();
            });
        });

        noteInputs.forEach((input) => {
            input.addEventListener('input', () => markDirty());
        });
    }

    openRolePermissionPicker() {
        const modal = document.getElementById('rolePermissionPickerModal');
        if (!modal) return;

        if (!this.roleSettingsState || !this.roleSettingsState.selectedRole || this.roleSettingsState.createMode) {
            this.showNotification('Select a role to manage operations.', 'info');
            return;
        }

        const permissions = Array.isArray(this.roleSettingsState.permissions) ? this.roleSettingsState.permissions : [];
        if (!this.rolePermissionPickerState) {
            this.rolePermissionPickerState = { filter: '', selections: new Map() };
        }
        this.rolePermissionPickerState.filter = '';
        this.rolePermissionPickerState.selections = new Map();
        permissions.forEach((row) => {
            if (row?.key) {
                this.rolePermissionPickerState.selections.set(row.key, row.mode || 'default');
            }
        });

        const searchInput = document.getElementById('rolePermissionSearchInput');
        if (searchInput) {
            searchInput.value = '';
        }

        const subtitle = document.getElementById('rolePermissionPickerSubtitle');
        if (subtitle) {
            const roleLabel = this.roleSettingsState.selectedRole?.role_label || this.formatRoleLabel(this.roleSettingsState.selectedRole?.role_key || '');
            subtitle.textContent = roleLabel
                ? `Select which operations ${roleLabel} can perform.`
                : 'Select which operations this role can perform.';
        }

        this.renderRolePermissionPickerList();
        modal.style.display = 'flex';
        modal.setAttribute('aria-hidden', 'false');
        document.body.classList.add('modal-open');
    }

    closeRolePermissionPicker() {
        const modal = document.getElementById('rolePermissionPickerModal');
        if (!modal) return;
        modal.style.display = 'none';
        modal.setAttribute('aria-hidden', 'true');
        document.body.classList.remove('modal-open');
    }

    renderRolePermissionPickerList() {
        const container = document.getElementById('rolePermissionList');
        if (!container) return;

        const state = this.roleSettingsState || {};
        const permissions = Array.isArray(state.permissions) ? state.permissions : [];
        const catalogMap = new Map((Array.isArray(state.catalog) ? state.catalog : []).map((entry) => [entry.key, entry]));
        if (!permissions.length) {
            container.innerHTML = '<div class="table-empty">No operations available for this role.</div>';
            return;
        }

        const filter = String(this.rolePermissionPickerState?.filter || '').toLowerCase().trim();
        const rows = permissions.filter((row) => {
            if (!filter) return true;
            const label = String(row.label || '').toLowerCase();
            const description = String(row.description || '').toLowerCase();
            const key = String(row.key || '').toLowerCase();
            return label.includes(filter) || description.includes(filter) || key.includes(filter);
        });

        if (!rows.length) {
            container.innerHTML = '<div class="table-empty">No operations match the current search.</div>';
            return;
        }

        container.innerHTML = rows.map((row) => {
            const key = String(row.key || '');
            const selected = this.rolePermissionPickerState?.selections?.get(key) || row.mode || 'default';
            const catalogEntry = catalogMap.get(key) || {};
            const defaultRoles = Array.isArray(catalogEntry.default_roles) ? catalogEntry.default_roles : [];
            const defaultRoleLabels = defaultRoles.length
                ? defaultRoles.map((role) => this.formatRoleLabel(role)).join(', ')
                : 'No default roles';
            return `
                <div class="role-permission-item" data-permission-key="${this.escapeHtml(key)}">
                    <div class="role-permission-copy">
                        <strong>${this.escapeHtml(row.label || key)}</strong>
                        <span>${this.escapeHtml(row.description || '')}</span>
                        <small>Default roles: ${this.escapeHtml(defaultRoleLabels)}</small>
                    </div>
                    <div class="role-permission-control">
                        <select class="role-permission-mode" data-permission-key="${this.escapeHtml(key)}">
                            <option value="default" ${selected === 'default' ? 'selected' : ''}>Default</option>
                            <option value="allow" ${selected === 'allow' ? 'selected' : ''}>Allow</option>
                            <option value="deny" ${selected === 'deny' ? 'selected' : ''}>Deny</option>
                        </select>
                    </div>
                </div>
            `;
        }).join('');

        container.querySelectorAll('.role-permission-mode').forEach((select) => {
            select.addEventListener('change', () => {
                const key = String(select.getAttribute('data-permission-key') || '');
                if (!key || !this.rolePermissionPickerState?.selections) return;
                this.rolePermissionPickerState.selections.set(key, select.value);
            });
        });
    }

    bulkUpdateRolePermissionPicker(mode) {
        if (!this.rolePermissionPickerState?.selections) return;
        const state = this.roleSettingsState || {};
        const permissions = Array.isArray(state.permissions) ? state.permissions : [];
        permissions.forEach((row) => {
            if (row?.key) {
                this.rolePermissionPickerState.selections.set(row.key, mode);
            }
        });
        this.renderRolePermissionPickerList();
    }

    applyRolePermissionPickerChanges() {
        if (!this.rolePermissionPickerState?.selections) {
            this.closeRolePermissionPicker();
            return;
        }

        const selections = this.rolePermissionPickerState.selections;
        const rows = document.querySelectorAll('#rolePermissionTableBody tr[data-permission-key]');
        rows.forEach((row) => {
            const key = row.getAttribute('data-permission-key') || '';
            if (!key || !selections.has(key)) return;
            const mode = selections.get(key) || 'default';
            const select = row.querySelector('.role-access-mode');
            if (select) {
                select.value = mode;
                select.dispatchEvent(new Event('change'));
            }
        });

        this.closeRolePermissionPicker();
        if (typeof this.showNotification === 'function') {
            this.showNotification('Role operations updated. Review the matrix and save when ready.', 'success');
        }
    }

    prepareNewRoleForm() {
        if (!this.roleSettingsState) return;
        this.roleSettingsState.createMode = true;
        this.roleSettingsState.selectedRole = null;
        this.roleSettingsState.selectedRoleKey = '';
        this.roleSettingsState.cloneFromRoleKey = '';

        const roleSelect = document.getElementById('roleSettingsRoleSelect');
        const roleKeyInput = document.getElementById('roleKeyInput');
        const roleLabelInput = document.getElementById('roleLabelInput');
        const roleDescriptionInput = document.getElementById('roleDescriptionInput');
        const roleActiveInput = document.getElementById('roleActiveInput');
        const roleCloneFromSelect = document.getElementById('roleCloneFromSelect');
        const deleteRoleBtn = document.getElementById('deleteRoleDefinitionBtn');
        const tableBody = document.getElementById('rolePermissionTableBody');
        const savePermsBtn = document.getElementById('saveRolePermissionSettingsBtn');

        if (roleSelect) roleSelect.value = '';
        if (roleKeyInput) roleKeyInput.value = '';
        if (roleLabelInput) roleLabelInput.value = '';
        if (roleDescriptionInput) roleDescriptionInput.value = '';
        if (roleActiveInput) roleActiveInput.checked = true;
        if (roleCloneFromSelect) roleCloneFromSelect.value = '';
        if (deleteRoleBtn) deleteRoleBtn.disabled = true;
        this.setRoleFormMode(true, false);

        if (tableBody) {
            tableBody.innerHTML = `
                <tr>
                    <td colspan="5">
                        <div class="table-empty">Save the new role to configure permission matrix.</div>
                    </td>
                </tr>
            `;
        }
        if (savePermsBtn) savePermsBtn.disabled = true;
        this.renderRoleSettingsData();
    }

    setRoleFormMode(createMode, isSystemRole = false) {
        const roleKeyInput = document.getElementById('roleKeyInput');
        const roleActiveInput = document.getElementById('roleActiveInput');
        const roleCloneFromSelect = document.getElementById('roleCloneFromSelect');
        const hasSelectedRole = Boolean((this.roleSettingsState && this.roleSettingsState.selectedRoleKey) || '');
        if (roleKeyInput) {
            roleKeyInput.readOnly = !createMode;
            roleKeyInput.classList.toggle('read-only', !createMode);
        }
        if (roleActiveInput) {
            roleActiveInput.disabled = !createMode && isSystemRole;
        }
        if (roleCloneFromSelect) {
            roleCloneFromSelect.disabled = !createMode && !hasSelectedRole;
        }
    }

    async saveRoleDefinition() {
        const roleKeyInput = document.getElementById('roleKeyInput');
        const roleLabelInput = document.getElementById('roleLabelInput');
        const roleDescriptionInput = document.getElementById('roleDescriptionInput');
        const roleActiveInput = document.getElementById('roleActiveInput');

        const roleKey = String(roleKeyInput?.value || '').trim().toLowerCase();
        const roleLabel = String(roleLabelInput?.value || '').trim();
        const roleDescription = String(roleDescriptionInput?.value || '').trim();
        const isActive = Boolean(roleActiveInput?.checked);
        const cloneFromRole = String(document.getElementById('roleCloneFromSelect')?.value || '').trim().toLowerCase();
        if (!roleKey || !roleLabel) {
            this.showNotification('Role key and role label are required.', 'error');
            return;
        }

        const state = this.roleSettingsState || {};
        const roleExists = Array.isArray(state.roles) && state.roles.some((role) => String(role.role_key || '') === roleKey);
        const action = (state.createMode || !roleExists) ? 'create' : 'update';

        try {
            this.updateSettingsStatus('role', 'Saving role...', 'info');
            const response = await fetch('../backend/api/save_role_admin.php', {
                method: 'POST',
                credentials: 'include',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    action,
                    role_key: roleKey,
                    role_label: roleLabel,
                    role_description: roleDescription,
                    is_active: isActive,
                    clone_from_role: cloneFromRole
                })
            });
            const data = await this.safeJson(response, { success: false });
            if (!response.ok || !data.success) {
                this.updateSettingsStatus('role', 'Save failed', 'error');
                this.showNotification(data.message || 'Unable to save role.', 'error');
                return;
            }

            this.showNotification(data.message || 'Role saved successfully.', 'success');
            this.updateSettingsStatus('role', 'Saved', 'success');
            await this.loadRoleSettingsData(data.role?.role_key || roleKey);
            await this.syncRoleCacheFromApi();
        } catch (error) {
            console.error('Save role definition error:', error);
            this.updateSettingsStatus('role', 'Save failed', 'error');
            this.showNotification('Unable to save role.', 'error');
        }
    }

    confirmRoleDelete() {
        const state = this.roleSettingsState || {};
        const selectedRole = state.selectedRole || null;
        const roleKey = String(selectedRole?.role_key || state.selectedRoleKey || '').trim().toLowerCase();

        if (!roleKey || !selectedRole) {
            this.showNotification('Select a role to delete.', 'info');
            return;
        }

        if (selectedRole.is_system) {
            this.showNotification('System roles cannot be deleted.', 'error');
            return;
        }

        const existing = document.querySelector('.admin-confirm-overlay');
        if (existing) existing.remove();

        const overlay = document.createElement('div');
        overlay.className = 'admin-confirm-overlay';
        overlay.innerHTML = `
            <div class="admin-confirm-modal">
                <h3>Delete Role</h3>
                <p>Are you sure you want to delete <strong>${this.escapeHtml(selectedRole.role_label || roleKey)}</strong>? This action cannot be undone.</p>
                <div class="admin-modal-footer">
                    <button class="action-btn secondary" data-action="cancel">Cancel</button>
                    <button class="action-btn danger" data-action="confirm">Delete Role</button>
                </div>
            </div>
        `;

        document.body.appendChild(overlay);

        const close = () => overlay.remove();
        overlay.querySelector('[data-action="cancel"]').addEventListener('click', close);
        overlay.querySelector('[data-action="confirm"]').addEventListener('click', async () => {
            await this.deleteRoleDefinition(roleKey);
            close();
        });
        overlay.addEventListener('click', (event) => {
            if (event.target === overlay) close();
        });
    }

    async deleteRoleDefinition(roleKey) {
        const normalizedRoleKey = String(roleKey || '').trim().toLowerCase();
        if (!normalizedRoleKey) {
            this.showNotification('Select a role to delete.', 'info');
            return;
        }

        try {
            this.updateSettingsStatus('role', 'Deleting role...', 'info');
            const response = await fetch('../backend/api/save_role_admin.php', {
                method: 'POST',
                credentials: 'include',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    action: 'delete',
                    role_key: normalizedRoleKey
                })
            });
            const data = await this.safeJson(response, { success: false });
            if (!response.ok || !data.success) {
                this.updateSettingsStatus('role', 'Delete failed', 'error');
                this.showNotification(data.message || 'Unable to delete role.', 'error');
                return;
            }

            this.showNotification(data.message || 'Role deleted successfully.', 'success');
            this.updateSettingsStatus('role', 'Deleted', 'success');
            await this.loadRoleSettingsData('');
            await this.syncRoleCacheFromApi();
        } catch (error) {
            console.error('Delete role error:', error);
            this.updateSettingsStatus('role', 'Delete failed', 'error');
            this.showNotification('Unable to delete role.', 'error');
        }
    }

    async saveRolePermissionSettings() {
        const state = this.roleSettingsState || {};
        const roleKey = state.selectedRoleKey || document.getElementById('roleSettingsRoleSelect')?.value || '';
        if (!roleKey) {
            this.showNotification('Select a role to save permissions.', 'info');
            return;
        }

        const rows = Array.from(document.querySelectorAll('#rolePermissionTableBody tr[data-permission-key]'));
        if (!rows.length) {
            this.showNotification('No role permission rows available to save.', 'info');
            return;
        }

        const permissions = {};
        rows.forEach((row) => {
            const key = row.getAttribute('data-permission-key') || '';
            if (!key) return;
            const mode = row.querySelector('.role-access-mode')?.value || 'default';
            const notes = String(row.querySelector('.role-access-notes')?.value || '').trim();
            permissions[key] = { mode, notes };
        });

        try {
            this.updateSettingsStatus('role', 'Saving permissions...', 'info');
            const response = await fetch('../backend/api/update_role_permissions_admin.php', {
                method: 'POST',
                credentials: 'include',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    role_key: roleKey,
                    permissions
                })
            });
            const data = await this.safeJson(response, { success: false });
            if (!response.ok || !data.success) {
                this.updateSettingsStatus('role', 'Save failed', 'error');
                this.showNotification(data.message || 'Unable to save role permissions.', 'error');
                return;
            }

            this.showNotification(data.message || 'Role permissions updated.', 'success');
            this.updateSettingsStatus('role', 'Saved', 'success');
            await this.loadRoleSettingsData(roleKey);
        } catch (error) {
            console.error('Save role permissions error:', error);
            this.updateSettingsStatus('role', 'Save failed', 'error');
            this.showNotification('Unable to save role permissions.', 'error');
        }
    }

    // Load notification queue content
    async loadNotificationQueueContent() {
        return `
            <div class="notification-queue-content user-logs-content">
                <div class="content-toolbar">
                    <div class="filters">
                        <select id="queueStatusFilter" class="filter-select">
                            <option value="">All Statuses</option>
                            <option value="queued">Queued</option>
                            <option value="sent">Sent</option>
                            <option value="failed">Failed</option>
                        </select>
                        <select id="queueChannelFilter" class="filter-select">
                            <option value="">All Channels</option>
                            <option value="email">Email</option>
                            <option value="sms">SMS</option>
                            <option value="push">Push</option>
                        </select>
                        <input type="text" id="queueSearchFilter" class="filter-input" placeholder="Search recipient or subject">
                        <button id="applyQueueFilters" class="filter-btn">Apply Filters</button>
                        <button id="clearQueueFilters" class="filter-btn secondary">Clear</button>
                        <button id="clearFilteredQueueBtn" class="filter-btn secondary">Clear Filtered</button>
                        <button id="emptyNotificationQueueBtn" class="filter-btn danger">Empty Queue</button>
                    </div>
                </div>
                <div class="queue-summary" id="notificationQueueSummary">
                    <span class="queue-chip">Total: <strong>0</strong></span>
                    <span class="queue-chip queued">Queued: <strong>0</strong></span>
                    <span class="queue-chip sent">Sent: <strong>0</strong></span>
                    <span class="queue-chip failed">Failed: <strong>0</strong></span>
                </div>
                <div class="logs-table-container">
                    <table class="logs-table compact-table">
                        <thead>
                            <tr>
                                <th>Status</th>
                                <th>Channel</th>
                                <th>Recipient</th>
                                <th>Subject</th>
                                <th class="hide-on-mobile">Message</th>
                                <th>Timestamp</th>
                            </tr>
                        </thead>
                        <tbody id="notificationQueueTableBody">
                            <tr><td colspan="6">Loading queue...</td></tr>
                        </tbody>
                    </table>
                    <div class="pagination" id="notificationQueuePagination"></div>
                </div>
            </div>
        `;
    }

    // Load audit trail content
    async loadAuditTrailContent() {
        return `
            <div class="audit-trail-content user-logs-content">
                <div class="content-toolbar">
                    <div class="filters">
                        <input type="text" id="auditActionFilter" class="filter-input" placeholder="Action (e.g. user_created)">
                        <select id="auditRoleFilter" class="filter-select">
                            <option value="">All Roles</option>
                        </select>
                        <input type="text" id="auditActorFilter" class="filter-input" placeholder="Actor name">
                        <input type="date" id="auditDateFromFilter" class="filter-input" placeholder="From Date">
                        <input type="date" id="auditDateToFilter" class="filter-input" placeholder="To Date">
                        <button id="applyAuditFilters" class="filter-btn">Apply Filters</button>
                        <button id="clearAuditFilters" class="filter-btn secondary">Clear</button>
                    </div>
                </div>
                <div class="logs-table-container">
                    <table class="logs-table compact-table">
                        <thead>
                            <tr>
                                <th>Actor</th>
                                <th>Role</th>
                                <th>Action</th>
                                <th class="hide-on-mobile">Entity</th>
                                <th>Timestamp</th>
                                <th class="hide-on-mobile">Details</th>
                            </tr>
                        </thead>
                        <tbody id="auditLogsTableBody">
                            <tr><td colspan="6">Loading audit logs...</td></tr>
                        </tbody>
                    </table>
                    <div class="pagination" id="auditLogsPagination"></div>
                </div>
            </div>
        `;
    }

    // Load notification settings content
    async loadNotificationSettingsContent() {
        return `
            <div class="settings-content notification-settings">
                <div class="settings-header">
                    <div>
                        <h2 class="section-title">Notification Settings</h2>
                        <p class="section-subtitle">Control alert channels, delivery rules, and administrator digests.</p>
                    </div>
                    <div class="settings-actions">
                        <span class="settings-status" id="notificationSettingsStatus">Ready</span>
                        <button class="action-btn secondary" id="resetNotificationSettingsBtn" type="button">Reset Changes</button>
                        <button class="action-btn" id="saveNotificationSettingsBtn" type="button">Save Settings</button>
                    </div>
                </div>

                <form id="notificationSettingsForm" class="settings-grid">
                    <section class="settings-card">
                        <div class="settings-card-header">
                            <h3>Delivery Channels</h3>
                            <p>Pick which channels are active for system messaging.</p>
                        </div>
                        <div class="settings-fields">
                            <div class="settings-toggle">
                                <div>
                                    <div class="toggle-title">Email Notifications</div>
                                    <div class="toggle-subtitle">Send alerts and updates by email.</div>
                                </div>
                                <label class="switch">
                                    <input type="checkbox" name="notify_email_enabled">
                                    <span class="slider"></span>
                                </label>
                            </div>
                            <div class="settings-toggle">
                                <div>
                                    <div class="toggle-title">SMS Notifications</div>
                                    <div class="toggle-subtitle">Send critical alerts via SMS gateway.</div>
                                </div>
                                <label class="switch">
                                    <input type="checkbox" name="notify_sms_enabled">
                                    <span class="slider"></span>
                                </label>
                            </div>
                            <div class="settings-toggle">
                                <div>
                                    <div class="toggle-title">Push Notifications</div>
                                    <div class="toggle-subtitle">Push alerts to web/mobile clients.</div>
                                </div>
                                <label class="switch">
                                    <input type="checkbox" name="notify_push_enabled">
                                    <span class="slider"></span>
                                </label>
                            </div>
                        </div>
                    </section>

                    <section class="settings-card">
                        <div class="settings-card-header">
                            <h3>Sender Identity</h3>
                            <p>Customize sender details for outbound notifications.</p>
                        </div>
                        <div class="settings-fields">
                            <label class="settings-field">
                                <span>Sender Name</span>
                                <input type="text" name="notify_sender_name" placeholder="PensionsGo Notifications">
                            </label>
                            <label class="settings-field">
                                <span>Sender Email</span>
                                <input type="email" name="notify_sender_email" placeholder="no-reply@yourdomain.com">
                            </label>
                            <label class="settings-field">
                                <span>Test Recipient Email</span>
                                <input type="email" name="notify_test_recipient" placeholder="admin@yourdomain.com">
                                <small class="field-help">Use this address when sending test alerts.</small>
                            </label>
                        </div>
                    </section>

                    <section class="settings-card">
                        <div class="settings-card-header">
                            <h3>Delivery Rules</h3>
                            <p>Define when and how notifications are dispatched.</p>
                        </div>
                        <div class="settings-fields">
                            <div class="settings-toggle">
                                <div>
                                    <div class="toggle-title">System Alerts</div>
                                    <div class="toggle-subtitle">Critical system health and security alerts.</div>
                                </div>
                                <label class="switch">
                                    <input type="checkbox" name="notify_system_alerts_enabled">
                                    <span class="slider"></span>
                                </label>
                            </div>
                            <div class="settings-toggle">
                                <div>
                                    <div class="toggle-title">User Activity Alerts</div>
                                    <div class="toggle-subtitle">Logins, logouts, and access events.</div>
                                </div>
                                <label class="switch">
                                    <input type="checkbox" name="notify_user_activity_enabled">
                                    <span class="slider"></span>
                                </label>
                            </div>
                            <div class="settings-toggle">
                                <div>
                                    <div class="toggle-title">Broadcast Messages</div>
                                    <div class="toggle-subtitle">Notify recipients when broadcasts are sent.</div>
                                </div>
                                <label class="switch">
                                    <input type="checkbox" name="notify_broadcast_enabled">
                                    <span class="slider"></span>
                                </label>
                            </div>
                            <label class="settings-field">
                                <span>Quiet Hours Start</span>
                                <input type="time" name="notify_quiet_hours_start">
                            </label>
                            <label class="settings-field">
                                <span>Quiet Hours End</span>
                                <input type="time" name="notify_quiet_hours_end">
                            </label>
                        </div>
                    </section>

                    <section class="settings-card">
                        <div class="settings-card-header">
                            <h3>Broadcast Experience</h3>
                            <p>Manage the in-app sound, browser alerts, and sound library used for broadcast notifications.</p>
                        </div>
                        <div class="settings-fields">
                            <div class="settings-toggle">
                                <div>
                                    <div class="toggle-title">Broadcast Sound</div>
                                    <div class="toggle-subtitle">Play an audible alert when a new broadcast arrives in the app.</div>
                                </div>
                                <label class="switch">
                                    <input type="checkbox" name="notify_broadcast_sound_enabled" id="notifyBroadcastSoundEnabled">
                                    <span class="slider"></span>
                                </label>
                            </div>
                            <div class="settings-toggle">
                                <div>
                                    <div class="toggle-title">Browser Alerts</div>
                                    <div class="toggle-subtitle">Allow desktop/browser notifications for broadcasts on supported devices.</div>
                                </div>
                                <label class="switch">
                                    <input type="checkbox" name="notify_broadcast_desktop_enabled" id="notifyBroadcastDesktopEnabled">
                                    <span class="slider"></span>
                                </label>
                            </div>
                            <div class="settings-toggle">
                                <div>
                                    <div class="toggle-title">Only Alert When Hidden</div>
                                    <div class="toggle-subtitle">Keep browser alerts for background tabs and installed app windows to avoid duplicate noise.</div>
                                </div>
                                <label class="switch">
                                    <input type="checkbox" name="notify_broadcast_desktop_hidden_only" id="notifyBroadcastDesktopHiddenOnly">
                                    <span class="slider"></span>
                                </label>
                            </div>
                            <label class="settings-field">
                                <span>Notification Sound</span>
                                <select name="notify_broadcast_sound_path" id="notificationSoundPicker">
                                    <option value="audio/notification.mp3">Classic Alert (MP3)</option>
                                </select>
                                <small class="field-help" id="notificationSoundSelectionMeta">Choose the sound that should play for new broadcasts.</small>
                            </label>
                            <label class="settings-field">
                                <span>Sound Volume</span>
                                <div class="notification-sound-range">
                                    <input type="range" name="notify_broadcast_sound_volume" id="notificationSoundVolume" min="0" max="100" step="1">
                                    <output id="notificationSoundVolumeValue">85%</output>
                                </div>
                                <small class="field-help">Applies to in-app broadcast sound playback on supported browsers.</small>
                            </label>
                            <label class="settings-field">
                                <span>Repeat Sound</span>
                                <select name="notify_broadcast_sound_repeat_count" id="notificationSoundRepeatCount">
                                    <option value="1">Once</option>
                                    <option value="2">Twice</option>
                                    <option value="3">Three times</option>
                                    <option value="4">Four times</option>
                                    <option value="5">Five times</option>
                                </select>
                                <small class="field-help">Useful for high-importance broadcast environments without making the alert continuous.</small>
                            </label>
                            <div class="notification-sound-toolbar">
                                <button class="action-btn secondary" id="previewNotificationSoundBtn" type="button">Preview Sound</button>
                                <button class="action-btn secondary" id="requestNotificationPermissionBtn" type="button">Allow Browser Alerts</button>
                                <button class="action-btn secondary" id="testBrowserNotificationBtn" type="button">Test Browser Alert</button>
                            </div>
                            <div class="settings-note notification-permission-note">
                                <strong>Browser alert permission</strong>
                                <span id="notificationPermissionStatus">Checking browser support on this device...</span>
                            </div>
                            <div class="notification-sound-manager">
                                <div>
                                    <strong>Sound Library</strong>
                                    <small class="field-help" id="notificationSoundUploadStatus">Supported formats: MP3, WAV, OGG, and M4A. Maximum file size: 5 MB.</small>
                                </div>
                                <div class="notification-sound-toolbar">
                                    <button class="action-btn secondary" id="openNotificationSoundUploadModalBtn" type="button">Upload New Sound</button>
                                    <button class="action-btn secondary" id="openNotificationSoundLibraryModalBtn" type="button">Manage Sound List</button>
                                </div>
                            </div>
                        </div>
                    </section>

                    <section class="settings-card live-call-sound-card">
                        <div class="settings-card-header">
                            <h3>Live Chat Call Sounds</h3>
                            <p>Control the ringing experience for incoming and outgoing staff audio/video calls.</p>
                        </div>
                        <div class="settings-fields">
                            <div class="settings-toggle">
                                <div>
                                    <div class="toggle-title">Incoming Call Sound</div>
                                    <div class="toggle-subtitle">Play a repeating ringtone when another staff member calls this user.</div>
                                </div>
                                <label class="switch">
                                    <input type="checkbox" name="live_call_incoming_sound_enabled" id="liveCallIncomingSoundEnabled">
                                    <span class="slider"></span>
                                </label>
                            </div>
                            <div class="settings-toggle">
                                <div>
                                    <div class="toggle-title">Outgoing Ringback Sound</div>
                                    <div class="toggle-subtitle">Play a ringback tone while waiting for the recipient to answer.</div>
                                </div>
                                <label class="switch">
                                    <input type="checkbox" name="live_call_outgoing_sound_enabled" id="liveCallOutgoingSoundEnabled">
                                    <span class="slider"></span>
                                </label>
                            </div>
                            <div class="settings-toggle">
                                <div>
                                    <div class="toggle-title">Incoming Browser Alerts</div>
                                    <div class="toggle-subtitle">Show an operating-system/browser notification when calls arrive in the background.</div>
                                </div>
                                <label class="switch">
                                    <input type="checkbox" name="live_call_desktop_alerts_enabled" id="liveCallDesktopAlertsEnabled">
                                    <span class="slider"></span>
                                </label>
                            </div>
                            <label class="settings-field">
                                <span>Incoming Ringtone</span>
                                <select name="live_call_incoming_sound_path" id="liveCallIncomingSoundPicker">
                                    <option value="audio/notification.mp3">Classic Alert (MP3)</option>
                                </select>
                                <small class="field-help" id="liveCallIncomingSoundMeta">Choose the ringtone used for incoming calls.</small>
                            </label>
                            <label class="settings-field">
                                <span>Outgoing Ringback Tone</span>
                                <select name="live_call_outgoing_sound_path" id="liveCallOutgoingSoundPicker">
                                    <option value="audio/notification.mp3">Classic Alert (MP3)</option>
                                </select>
                                <small class="field-help" id="liveCallOutgoingSoundMeta">Choose the tone used while a call is ringing.</small>
                            </label>
                            <label class="settings-field">
                                <span>Incoming Volume</span>
                                <div class="notification-sound-range">
                                    <input type="range" name="live_call_incoming_sound_volume" id="liveCallIncomingSoundVolume" min="0" max="100" step="1">
                                    <output id="liveCallIncomingSoundVolumeValue">85%</output>
                                </div>
                                <small class="field-help">Applies to incoming voice and video call ringing.</small>
                            </label>
                            <label class="settings-field">
                                <span>Outgoing Volume</span>
                                <div class="notification-sound-range">
                                    <input type="range" name="live_call_outgoing_sound_volume" id="liveCallOutgoingSoundVolume" min="0" max="100" step="1">
                                    <output id="liveCallOutgoingSoundVolumeValue">55%</output>
                                </div>
                                <small class="field-help">Applies only to the caller while waiting for an answer.</small>
                            </label>
                            <label class="settings-field">
                                <span>Incoming Repeat Limit</span>
                                <select name="live_call_incoming_sound_repeat_count" id="liveCallIncomingRepeatCount">
                                    <option value="0">Until answered or rejected</option>
                                    <option value="1">Once</option>
                                    <option value="2">Twice</option>
                                    <option value="3">Three times</option>
                                    <option value="5">Five times</option>
                                    <option value="10">Ten times</option>
                                </select>
                                <small class="field-help">Use continuous ringing for active operators; use a limit for quieter environments.</small>
                            </label>
                            <label class="settings-field">
                                <span>Outgoing Repeat Limit</span>
                                <select name="live_call_outgoing_sound_repeat_count" id="liveCallOutgoingRepeatCount">
                                    <option value="0">Until answered or ended</option>
                                    <option value="1">Once</option>
                                    <option value="2">Twice</option>
                                    <option value="3">Three times</option>
                                    <option value="5">Five times</option>
                                    <option value="10">Ten times</option>
                                </select>
                                <small class="field-help">Controls the ringback tone heard by the caller.</small>
                            </label>
                            <label class="settings-field">
                                <span>Unanswered Call Timeout (Seconds)</span>
                                <input type="number" name="live_call_ringing_timeout_seconds" id="liveCallRingingTimeoutSeconds" min="10" max="300" step="5">
                                <small class="field-help">Automatically ends unanswered incoming and outgoing calls after this ringing period.</small>
                            </label>
                            <div class="notification-sound-toolbar">
                                <button class="action-btn secondary" id="previewIncomingCallSoundBtn" type="button">Preview Incoming</button>
                                <button class="action-btn secondary" id="previewOutgoingCallSoundBtn" type="button">Preview Outgoing</button>
                                <button class="action-btn secondary" id="requestCallNotificationPermissionBtn" type="button">Allow Call Alerts</button>
                            </div>
                            <div class="settings-note notification-permission-note">
                                <strong>Call alert permission</strong>
                                <span id="liveCallNotificationPermissionStatus">Checking browser support on this device...</span>
                            </div>
                        </div>
                    </section>

                    <section class="settings-card live-message-sound-card">
                        <div class="settings-card-header">
                            <h3>Live Chat Message Sounds</h3>
                            <p>Control new-message tones, unread badges, and browser alerts for staff live chat.</p>
                        </div>
                        <div class="settings-fields">
                            <div class="settings-toggle">
                                <div>
                                    <div class="toggle-title">New Message Sound</div>
                                    <div class="toggle-subtitle">Play a short alert when a new live chat message arrives.</div>
                                </div>
                                <label class="switch">
                                    <input type="checkbox" name="live_message_sound_enabled" id="liveMessageSoundEnabled">
                                    <span class="slider"></span>
                                </label>
                            </div>
                            <div class="settings-toggle">
                                <div>
                                    <div class="toggle-title">In-App Message Alerts</div>
                                    <div class="toggle-subtitle">Show a styled PensionsGo alert card when a new live chat message arrives.</div>
                                </div>
                                <label class="switch">
                                    <input type="checkbox" name="live_message_desktop_alerts_enabled" id="liveMessageDesktopAlertsEnabled">
                                    <span class="slider"></span>
                                </label>
                            </div>
                            <label class="settings-field">
                                <span>Message Alert Sound</span>
                                <select name="live_message_sound_path" id="liveMessageSoundPicker">
                                    <option value="audio/notification.mp3">Classic Alert (MP3)</option>
                                </select>
                                <small class="field-help" id="liveMessageSoundMeta">Choose the tone used for new live chat messages.</small>
                            </label>
                            <label class="settings-field">
                                <span>Message Volume</span>
                                <div class="notification-sound-range">
                                    <input type="range" name="live_message_sound_volume" id="liveMessageSoundVolume" min="0" max="100" step="1">
                                    <output id="liveMessageSoundVolumeValue">70%</output>
                                </div>
                                <small class="field-help">Applies to incoming text, voice, poll, and attachment messages.</small>
                            </label>
                            <label class="settings-field">
                                <span>Repeat Message Sound</span>
                                <select name="live_message_sound_repeat_count" id="liveMessageRepeatCount">
                                    <option value="1">Once</option>
                                    <option value="2">Twice</option>
                                    <option value="3">Three times</option>
                                    <option value="4">Four times</option>
                                    <option value="5">Five times</option>
                                </select>
                                <small class="field-help">Unread badges remain visible until the user opens and reads the conversation.</small>
                            </label>
                            <div class="notification-sound-toolbar">
                                <button class="action-btn secondary" id="previewLiveMessageSoundBtn" type="button">Preview Message Sound</button>
                            </div>
                        </div>
                    </section>

                    <section class="settings-card">
                        <div class="settings-card-header">
                            <h3>Admin Digest</h3>
                            <p>Configure a daily summary sent to administrators.</p>
                        </div>
                        <div class="settings-fields">
                            <div class="settings-toggle">
                                <div>
                                    <div class="toggle-title">Enable Daily Digest <span class="settings-pill planned">Planned</span></div>
                                    <div class="toggle-subtitle">Send a daily operational summary.</div>
                                </div>
                                <label class="switch">
                                    <input type="checkbox" name="notify_admin_digest_enabled">
                                    <span class="slider"></span>
                                </label>
                            </div>
                            <label class="settings-field">
                                <span>Digest Delivery Time</span>
                                <input type="time" name="notify_digest_time">
                            </label>
                        </div>
                    </section>

                    <section class="settings-card">
                        <div class="settings-card-header">
                            <h3>Queue Worker</h3>
                            <p>Control how queued email notifications are processed and retried.</p>
                        </div>
                        <div class="settings-fields">
                            <div class="settings-toggle">
                                <div>
                                    <div class="toggle-title">Queue Worker Enabled</div>
                                    <div class="toggle-subtitle">Allow the app or CLI worker to deliver queued emails.</div>
                                </div>
                                <label class="switch">
                                    <input type="checkbox" name="notify_queue_worker_enabled">
                                    <span class="slider"></span>
                                </label>
                            </div>
                            <div class="settings-toggle">
                                <div>
                                    <div class="toggle-title">Process On Request</div>
                                    <div class="toggle-subtitle">Run a lightweight worker during normal requests when new email is queued.</div>
                                </div>
                                <label class="switch">
                                    <input type="checkbox" name="notify_queue_process_on_request">
                                    <span class="slider"></span>
                                </label>
                            </div>
                            <label class="settings-field">
                                <span>Batch Size</span>
                                <input type="number" name="notify_queue_batch_size" min="1" max="100" step="1">
                            </label>
                            <label class="settings-field">
                                <span>Retry Limit</span>
                                <input type="number" name="notify_queue_retry_limit" min="1" max="20" step="1">
                            </label>
                            <label class="settings-field">
                                <span>Retry Delay (Minutes)</span>
                                <input type="number" name="notify_queue_retry_delay_minutes" min="1" max="1440" step="1">
                            </label>
                            <label class="settings-field">
                                <span>Minimum Run Interval (Seconds)</span>
                                <input type="number" name="notify_queue_min_interval_seconds" min="5" max="3600" step="1">
                            </label>
                        </div>
                    </section>
                </form>
            </div>
        `;
    }

    // Load message storage content
    async loadMessageStorageContent() {
        return `
            <div class="settings-content storage-settings">
                <div class="settings-header">
                    <div>
                        <h2 class="section-title">Message Storage</h2>
                        <p class="section-subtitle">Control message retention, archival rules, and storage quotas.</p>
                    </div>
                    <div class="settings-actions">
                        <span class="settings-status" id="messageStorageStatus">Ready</span>
                        <button class="action-btn secondary" id="resetMessageStorageBtn" type="button">Reset Changes</button>
                        <button class="action-btn" id="saveMessageStorageBtn" type="button">Save Settings</button>
                    </div>
                </div>

                <form id="messageStorageForm" class="settings-grid">
                    <section class="settings-card">
                        <div class="settings-card-header">
                            <h3>Retention Policy</h3>
                            <p>Define how long messages are kept before archival or purge.</p>
                        </div>
                        <div class="settings-fields">
                            <label class="settings-field">
                                <span>Retention Period (days)</span>
                                <input type="number" name="message_retention_days" min="30" max="3650" step="1">
                            </label>
                            <label class="settings-field">
                                <span>Auto-Archive After (days)</span>
                                <input type="number" name="message_archive_after_days" min="7" max="3650" step="1">
                            </label>
                            <div class="settings-toggle">
                                <div>
                                    <div class="toggle-title">Soft Delete Enabled <span class="settings-pill planned">Planned</span></div>
                                    <div class="toggle-subtitle">Allow recovery of deleted messages during retention.</div>
                                </div>
                                <label class="switch">
                                    <input type="checkbox" name="message_allow_soft_delete">
                                    <span class="slider"></span>
                                </label>
                            </div>
                        </div>
                    </section>

                    <section class="settings-card">
                        <div class="settings-card-header">
                            <h3>Storage Capacity</h3>
                            <p>Protect storage from unbounded message growth.</p>
                        </div>
                        <div class="settings-fields">
                            <label class="settings-field">
                                <span>Message Storage Quota (MB)</span>
                                <input type="number" name="message_storage_quota_mb" min="256" max="10240" step="64">
                            </label>
                            <div class="settings-toggle">
                                <div>
                                    <div class="toggle-title">Enable Compression</div>
                                    <div class="toggle-subtitle">Compress archived messages to save space.</div>
                                </div>
                                <label class="switch">
                                    <input type="checkbox" name="message_compress_enabled">
                                    <span class="slider"></span>
                                </label>
                            </div>
                            <div class="settings-toggle">
                                <div>
                                    <div class="toggle-title">Enable Backup Snapshot <span class="settings-pill planned">Planned</span></div>
                                    <div class="toggle-subtitle">Create daily backups of message storage.</div>
                                </div>
                                <label class="switch">
                                    <input type="checkbox" name="message_backup_enabled">
                                    <span class="slider"></span>
                                </label>
                            </div>
                        </div>
                    </section>
                </form>
            </div>
        `;
    }

    // Load attachment storage content
    async loadAttachmentStorageContent() {
        return `
            <div class="settings-content storage-settings">
                <div class="settings-header">
                    <div>
                        <h2 class="section-title">Attachment Storage</h2>
                        <p class="section-subtitle">Define file limits, allowed types, and safety checks for attachments.</p>
                    </div>
                    <div class="settings-actions">
                        <span class="settings-status" id="attachmentStorageStatus">Ready</span>
                        <button class="action-btn secondary" id="resetAttachmentStorageBtn" type="button">Reset Changes</button>
                        <button class="action-btn" id="saveAttachmentStorageBtn" type="button">Save Settings</button>
                    </div>
                </div>

                <form id="attachmentStorageForm" class="settings-grid">
                    <section class="settings-card">
                        <div class="settings-card-header">
                            <h3>File Controls</h3>
                            <p>Limit file types and sizes accepted by the system.</p>
                        </div>
                        <div class="settings-fields">
                            <label class="settings-field">
                                <span>Max File Size (MB)</span>
                                <input type="number" name="attachment_max_size_mb" min="1" max="100" step="1">
                            </label>
                            <label class="settings-field">
                                <span>Allowed File Types</span>
                                <textarea name="attachment_allowed_types" rows="3" placeholder="pdf,jpg,jpeg,png,docx,xlsx"></textarea>
                                <small class="field-help">Comma separated list of extensions.</small>
                            </label>
                            <div class="settings-toggle">
                                <div>
                                    <div class="toggle-title">Virus Scan Required <span class="settings-pill planned">Planned</span></div>
                                    <div class="toggle-subtitle">Scan attachments before storing.</div>
                                </div>
                                <label class="switch">
                                    <input type="checkbox" name="attachment_scan_enabled">
                                    <span class="slider"></span>
                                </label>
                            </div>
                        </div>
                    </section>

                    <section class="settings-card">
                        <div class="settings-card-header">
                            <h3>Retention & Optimization</h3>
                            <p>Manage how long attachments are retained and optimized.</p>
                        </div>
                        <div class="settings-fields">
                            <label class="settings-field">
                                <span>Retention Period (days)</span>
                                <input type="number" name="attachment_retention_days" min="30" max="3650" step="1">
                            </label>
                            <div class="settings-toggle">
                                <div>
                                    <div class="toggle-title">Enable Deduplication</div>
                                    <div class="toggle-subtitle">Avoid storing duplicate files.</div>
                                </div>
                                <label class="switch">
                                    <input type="checkbox" name="attachment_dedupe_enabled">
                                    <span class="slider"></span>
                                </label>
                            </div>
                            <div class="settings-toggle">
                                <div>
                                    <div class="toggle-title">Enable Compression</div>
                                    <div class="toggle-subtitle">Compress large files automatically.</div>
                                </div>
                                <label class="switch">
                                    <input type="checkbox" name="attachment_compress_enabled">
                                    <span class="slider"></span>
                                </label>
                            </div>
                        </div>
                    </section>

                </form>
            </div>
        `;
    }

    // Load document storage content
    async loadDocumentStorageContent() {
        return `
            <div class="settings-content storage-settings">
                <div class="settings-header">
                    <div>
                        <h2 class="section-title">Document Storage</h2>
                        <p class="section-subtitle">Plan and govern storage for scanned pensioner records and official files.</p>
                    </div>
                    <div class="settings-actions">
                        <span class="settings-status status-info">Planned</span>
                    </div>
                </div>

                <div class="settings-grid">
                    <section class="settings-card">
                        <div class="settings-card-header">
                            <h3>Document Registry (Planned)</h3>
                            <p>Centralize scanned documents linked to file registry entries.</p>
                        </div>
                        <div class="settings-fields">
                            <div class="settings-summary">
                                <div class="summary-row">
                                    <span class="summary-label">Current Handling</span>
                                    <span class="summary-value">Use Attachments for now</span>
                                </div>
                                <div class="summary-row">
                                    <span class="summary-label">Next Capabilities</span>
                                    <span class="summary-value">Folder structure, retention, indexing, and audit trails</span>
                                </div>
                                <div class="summary-row">
                                    <span class="summary-label">Security Focus</span>
                                    <span class="summary-value">Encryption at rest, access scopes, tamper evidence</span>
                                </div>
                            </div>
                        </div>
                    </section>

                    <section class="settings-card">
                        <div class="settings-card-header">
                            <h3>Data Preparation</h3>
                            <p>Set the foundation for high-volume document ingestion.</p>
                        </div>
                        <div class="settings-fields">
                            <div class="settings-summary">
                                <div class="summary-row">
                                    <span class="summary-label">Recommended Setup</span>
                                    <span class="summary-value">Dedicated storage bucket + backup policy</span>
                                </div>
                                <div class="summary-row">
                                    <span class="summary-label">Metadata Required</span>
                                    <span class="summary-value">Reg No, Supplier No, Document Type, Capture Date</span>
                                </div>
                                <div class="summary-row">
                                    <span class="summary-label">Operational Notes</span>
                                    <span class="summary-value">Batch uploads, OCR pipeline, and indexing workflow</span>
                                </div>
                            </div>
                        </div>
                    </section>
                </div>
            </div>
        `;
    }

    initializeDocumentStorageSettings() {
        // Placeholder for future document storage management logic
    }

    // Load user management content
    async loadUserManagementContent() {
        return `
            <div class="user-management-content">
                <div class="user-management-header">
                    <div>
                        <h2 class="section-title">User Management</h2>
                        <p class="section-subtitle">Manage accounts, roles, and access controls for all system users.</p>
                    </div>
                    <div class="user-management-actions">
                        <button class="action-btn secondary" id="viewLoggedInUsersBtn" type="button">
                            <span class="action-icon">&#128101;</span>
                            View Logged-in Users
                        </button>
                        <button class="action-btn secondary" id="exportUsersBtn">
                            <span class="action-icon">&#128295;</span>
                            Export Users
                        </button>
                        <button class="action-btn" id="addUserBtn">
                            <span class="action-icon">&#128295;</span>
                            Add User
                        </button>
                    </div>
                </div>

                <div class="user-summary-grid">
                    <div class="user-summary-card">
                        <div class="user-summary-icon">&#128203;</div>
                        <div>
                            <div class="user-summary-value" id="userSummaryTotal">0</div>
                            <div class="user-summary-label">Total Users</div>
                        </div>
                    </div>
                    <div class="user-summary-card">
                        <div class="user-summary-icon">&#128203;</div>
                        <div>
                            <div class="user-summary-value" id="userSummaryAdmins">0</div>
                            <div class="user-summary-label">Administrators</div>
                        </div>
                    </div>
                    <div class="user-summary-card">
                        <div class="user-summary-icon">&#128203;</div>
                        <div>
                            <div class="user-summary-value" id="userSummaryStaff">0</div>
                            <div class="user-summary-label">Staff Accounts</div>
                        </div>
                    </div>
                    <div class="user-summary-card">
                        <div class="user-summary-icon">&#128203;</div>
                        <div>
                            <div class="user-summary-value" id="userSummaryPensioners">0</div>
                            <div class="user-summary-label">Pensioners</div>
                        </div>
                    </div>
                </div>

                <div class="bulk-actions-bar" id="bulkActionsBar">
                    <div class="bulk-actions-info">
                        <strong id="bulkSelectedCount">0</strong> selected
                        <span class="bulk-selected-summary" id="bulkSelectedSummary">0 of 0 filtered</span>
                    </div>
                    <div class="bulk-actions-buttons">
                        <button class="action-btn secondary" id="selectAllFilteredBtn" type="button">
                            Select All Filtered
                        </button>
                        <button class="action-btn danger" id="bulkDeleteUsersBtn">
                            <span class="action-icon">&#128295;</span>
                            Delete Selected
                        </button>
                    </div>
                </div>

                <div class="user-filters">
                    <div class="user-search">
                        <input type="text" id="userManagementSearch" placeholder="Search by name, email, phone, or role">
                        <span class="search-icon">&#128269;</span>
                    </div>
                    <div class="user-filter">
                        <select id="userRoleFilter">
                            <option value="">All Roles</option>
                        </select>
                    </div>
                    <div class="user-filter">
                        <select id="userAccountTypeFilter">
                            <option value="">All Accounts</option>
                            <option value="staff">Staff Only</option>
                            <option value="pensioner">Pensioners Only</option>
                        </select>
                    </div>
                    <button class="filter-btn secondary" id="clearUserFilters">Clear Filters</button>
                </div>

                <div class="users-table-container">
                    <table class="users-table">
                        <thead>
                            <tr>
                                <th class="table-checkbox">
                                    <input type="checkbox" id="selectAllUsers">
                                </th>
                                <th>User</th>
                                <th>Contact</th>
                                <th>Role</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody id="userManagementTableBody">
                            <tr>
                                <td colspan="6">
                                    <div class="table-loading">Loading users...</div>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        `;
    }

    // Load system health content
    async loadSystemHealthContent() {
        return `
            <div class="system-health-content">
                <div class="system-health-header">
                    <div>
                        <h2 class="section-title">System Health Diagnostics</h2>
                        <p class="section-subtitle">Real-time diagnostics for platform reliability, resource usage, and operational risk.</p>
                    </div>
                    <button class="action-btn secondary" id="refreshSystemHealthBtn" type="button">Refresh Health</button>
                </div>

                <div id="systemHealthOverview" class="system-health-overview">
                    <div class="health-banner health-loading">
                        <div class="health-banner-status">Loading</div>
                        <div class="health-banner-message">Collecting diagnostics...</div>
                    </div>
                </div>

                <div class="system-health-grid">
                    <div class="health-panel">
                        <h3>Resource Utilization</h3>
                        <div id="systemHealthMetrics" class="health-metric-list">
                            <div class="widget-empty">Loading metrics...</div>
                        </div>
                    </div>
                    <div class="health-panel">
                        <h3>Runtime Checks</h3>
                        <div id="systemHealthChecks" class="health-check-list">
                            <div class="widget-empty">Loading checks...</div>
                        </div>
                    </div>
                </div>

                <div class="system-health-grid system-health-grid-secondary">
                    <div class="health-panel">
                        <h3>Diagnostics Summary</h3>
                        <div id="systemHealthSummary" class="health-summary-grid">
                            <div class="widget-empty">Summary cards will appear after diagnostics load.</div>
                        </div>
                    </div>
                    <div class="health-panel">
                        <h3>Operational Notes</h3>
                        <div id="systemHealthNotes" class="health-notes">
                            <div class="widget-empty">Health commentary will appear after diagnostics load.</div>
                        </div>
                    </div>
                </div>

                <div class="health-panel">
                    <div class="health-panel-heading">
                        <div>
                            <h3>Active Alerts & Fixes</h3>
                            <p>Review subsystem-specific incidents, their causes, and the recommended recovery path.</p>
                        </div>
                    </div>
                    <div id="systemHealthAlerts" class="health-alert-list">
                        <div class="widget-empty">Active alerts will appear after diagnostics load.</div>
                    </div>
                </div>
            </div>
        `;
    }
    // Load dashboard statistics
    async loadDashboardStats() {
        try {
            const [usersResponse, logsResponse, activeSessionsResponse] = await Promise.all([
                fetch('../backend/api/get_users_summary.php', { credentials: 'include', cache: 'no-store' }).catch(() => ({ ok: false })),
                fetch('../backend/api/get_logs_summary.php', { credentials: 'include', cache: 'no-store' }).catch(() => ({ ok: false })),
                fetch('../backend/api/get_active_sessions.php', { credentials: 'include', cache: 'no-store' }).catch(() => ({ ok: false }))
            ]);

            const usersData = await this.safeJson(usersResponse);
            const logsData = await this.safeJson(logsResponse);
            const activeSessionsData = await this.safeJson(activeSessionsResponse);

            const activeSessionsValue = Number(activeSessionsData.active_sessions);
            const activeSessionsFromApi = Number.isFinite(activeSessionsValue)
                ? activeSessionsValue
                : null;
            const activeSessions = activeSessionsFromApi !== null
                ? activeSessionsFromApi
                : (logsData.success ? (logsData.summary?.active_sessions_current ?? logsData.summary?.active_users_today ?? 0) : 0);

            return {
                totalUsers: usersData.success ? usersData.total_users : 0,
                todayLogs: logsData.success ? (logsData.summary?.today_logs || 0) : 0,
                activeSessions,
                failedLogins: logsData.success ? (logsData.summary?.failed_logins_week || 0) : 0
            };

        } catch (error) {
            console.error('Error loading dashboard stats:', error);
            return {
                totalUsers: 0,
                todayLogs: 0,
                activeSessions: 0,
                failedLogins: 0
            };
        }
    }

    // Update welcome stats (call this only during initial load)
    async updateWelcomeStats() {
        try {
            const [usersResponse, logsResponse, activeSessionsResponse] = await Promise.all([
                fetch('../backend/api/get_users_summary.php', { credentials: 'include', cache: 'no-store' }),
                fetch('../backend/api/get_logs_summary.php', { credentials: 'include', cache: 'no-store' }),
                fetch('../backend/api/get_active_sessions.php', { credentials: 'include', cache: 'no-store' })
            ]);

            const usersData = await this.safeJson(usersResponse);
            const logsData = await this.safeJson(logsResponse);
            const activeSessionsData = await this.safeJson(activeSessionsResponse);

            const updateElement = (elementId, value) => {
                const element = document.getElementById(elementId);
                if (element) element.textContent = value;
            };

            if (usersData.success) {
                updateElement('welcomeUserCount', usersData.total_users || 0);
                updateElement('userCountBadge', usersData.total_users || 0);
            }

            if (logsData.success) {
                const logBadgeCount = logsData.summary?.total_logs_all ?? logsData.summary?.total_logs ?? logsData.summary?.today_logs ?? 0;
                updateElement('welcomeLogCount', logsData.summary?.today_logs || 0);
                updateElement('logCountBadge', logBadgeCount);
            }

            const activeSessionsValue = Number(activeSessionsData.active_sessions);
            const activeSessionsFromApi = Number.isFinite(activeSessionsValue)
                ? activeSessionsValue
                : null;
            const activeSessions = activeSessionsFromApi !== null
                ? activeSessionsFromApi
                : (logsData.success
                    ? (logsData.summary?.active_sessions_current ?? logsData.summary?.active_users_today ?? 0)
                    : 0);
            updateElement('welcomeActiveSessions', activeSessions);

        } catch (error) {
            console.error('Error updating welcome stats:', error);
        }
    }

    async fetchLoggedInUsers() {
        const response = await fetch('../backend/api/get_active_sessions.php?include=list', {
            credentials: 'include',
            cache: 'no-store',
            headers: { 'Accept': 'application/json' }
        });
        const data = await this.safeJson(response, { success: false, sessions: [] });
        if (!data.success) {
            throw new Error(data.message || 'Unable to load logged-in users.');
        }
        return {
            activeSessions: Number(data.active_sessions || 0),
            activeUsers: Number(data.active_users || 0),
            generatedAt: data.generated_at || new Date().toISOString(),
            sessions: Array.isArray(data.sessions) ? data.sessions : []
        };
    }

    async openLoggedInUsersModal() {
        document.querySelector('.admin-modal-overlay.logged-in-users-overlay')?.remove();

        const overlay = document.createElement('div');
        overlay.className = 'admin-modal-overlay logged-in-users-overlay';
        overlay.innerHTML = `
            <div class="admin-modal admin-modal-wide logged-in-users-modal" role="dialog" aria-modal="true" aria-labelledby="loggedInUsersModalTitle">
                <div class="admin-modal-header">
                    <div>
                        <h3 id="loggedInUsersModalTitle">Logged-in Users</h3>
                        <p class="modal-subtitle">Live active sessions across user accounts, devices, and browsers.</p>
                    </div>
                    <button class="admin-modal-close" type="button" aria-label="Close">&times;</button>
                </div>
                <div class="admin-modal-body">
                    <div class="logged-in-users-toolbar">
                        <div class="logged-in-users-summary" id="loggedInUsersSummary">
                            <span>Loading active sessions...</span>
                        </div>
                        <label class="logged-in-users-search">
                            <span>Search</span>
                            <input type="search" id="loggedInUsersSearch" placeholder="Name, email, role, IP, device..." autocomplete="off">
                        </label>
                    </div>
                    <div id="loggedInUsersModalContent" class="logged-in-users-content">
                        <div class="table-loading">Loading logged-in users...</div>
                    </div>
                </div>
                <div class="admin-modal-footer">
                    <button class="action-btn secondary" type="button" data-action="refresh">Refresh</button>
                    <button class="action-btn secondary" type="button" data-action="export">Export CSV</button>
                    <button class="action-btn secondary" type="button" data-action="print">Print</button>
                    <button class="action-btn" type="button" data-action="close">Close</button>
                </div>
            </div>
        `;

        document.body.appendChild(overlay);
        document.body.classList.add('modal-open');

        const close = () => {
            overlay.remove();
            document.body.classList.remove('modal-open');
        };
        const refresh = async () => {
            const content = overlay.querySelector('#loggedInUsersModalContent');
            const summary = overlay.querySelector('#loggedInUsersSummary');
            if (content) content.innerHTML = '<div class="table-loading">Loading logged-in users...</div>';
            if (summary) summary.innerHTML = '<span>Refreshing...</span>';
            try {
                this.loggedInUsersSnapshot = await this.fetchLoggedInUsers();
                this.renderLoggedInUsersModal(overlay);
            } catch (error) {
                if (content) {
                    content.innerHTML = `<div class="settings-empty-state"><p>${this.escapeHtml(error.message || 'Unable to load logged-in users.')}</p></div>`;
                }
                if (summary) summary.innerHTML = '<span>Unable to load active sessions</span>';
                this.showNotification(error.message || 'Unable to load logged-in users.', 'error');
            }
        };

        overlay.querySelector('.admin-modal-close')?.addEventListener('click', close);
        overlay.querySelector('[data-action="close"]')?.addEventListener('click', close);
        overlay.querySelector('[data-action="refresh"]')?.addEventListener('click', refresh);
        overlay.querySelector('[data-action="export"]')?.addEventListener('click', () => this.exportLoggedInUsersCsv());
        overlay.querySelector('[data-action="print"]')?.addEventListener('click', () => this.printLoggedInUsers());
        overlay.querySelector('#loggedInUsersSearch')?.addEventListener('input', () => this.renderLoggedInUsersModal(overlay));
        overlay.addEventListener('click', (event) => {
            if (event.target === overlay) close();
        });

        await refresh();
    }

    renderLoggedInUsersModal(overlay) {
        const snapshot = this.loggedInUsersSnapshot || { activeSessions: 0, activeUsers: 0, sessions: [], generatedAt: '' };
        const query = String(overlay.querySelector('#loggedInUsersSearch')?.value || '').toLowerCase().trim();
        const sessions = (snapshot.sessions || []).filter((session) => {
            if (!query) return true;
            const haystack = [
                session.user_name,
                session.user_email,
                session.phone_no,
                this.formatRoleLabel(session.user_role),
                session.user_role,
                session.ip_address,
                session.physical_location,
                session.location_city,
                session.location_region,
                session.location_country,
                session.device_type,
                session.session_type,
                session.user_agent
            ].join(' ').toLowerCase();
            return haystack.includes(query);
        });

        const summary = overlay.querySelector('#loggedInUsersSummary');
        if (summary) {
            summary.innerHTML = `
                <span><strong>${this.escapeHtml(String(snapshot.activeUsers || 0))}</strong> users</span>
                <span><strong>${this.escapeHtml(String(snapshot.activeSessions || 0))}</strong> active sessions</span>
                <span>Updated ${this.escapeHtml(this.formatAdminDateTime(snapshot.generatedAt))}</span>
            `;
        }

        const rows = sessions.map((session, index) => `
            <tr>
                <td>${index + 1}</td>
                <td>
                    <strong>${this.escapeHtml(session.user_name || 'Unknown User')}</strong>
                    <small>${this.escapeHtml(session.user_email || session.phone_no || 'No contact recorded')}</small>
                </td>
                <td><span class="role-badge role-${this.escapeHtml(session.user_role || 'user')}">${this.escapeHtml(this.formatRoleLabel(session.user_role || 'user'))}</span></td>
                <td>
                    <strong>${this.escapeHtml(session.device_type || 'Unknown')}</strong>
                    <small>${this.escapeHtml(String(session.session_type || 'web').toUpperCase())} ${session.is_current_session ? '- Current session' : ''}</small>
                </td>
                <td>
                    <strong>${this.escapeHtml(session.ip_address || 'N/A')}</strong>
                    <small>Device ...${this.escapeHtml(session.device_id_tail || 'N/A')}</small>
                </td>
                <td>${this.escapeHtml(this.formatAdminDateTime(session.login_time))}</td>
                <td>
                    <strong>${this.escapeHtml(session.physical_location || 'Unknown Location')}</strong>
                    <small>${this.escapeHtml(session.ip_address || 'N/A')}</small>
                </td>
                <td>${this.escapeHtml(this.formatSessionDuration(session.session_age_seconds || 0))}</td>
            </tr>
        `).join('');

        const content = overlay.querySelector('#loggedInUsersModalContent');
        if (!content) return;
        content.innerHTML = `
            <div class="settings-table-container logged-in-users-table-wrap">
                <table class="settings-table logged-in-users-table">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>User</th>
                            <th>Role</th>
                            <th>Device</th>
                            <th>Network</th>
                            <th>Login Time</th>
                            <th>Physical Location</th>
                            <th>Session Age</th>
                        </tr>
                    </thead>
                    <tbody>
                        ${rows || `<tr><td colspan="8"><div class="settings-empty-state"><p>No active sessions match the current filter.</p></div></td></tr>`}
                    </tbody>
                </table>
            </div>
        `;
    }

    exportLoggedInUsersCsv() {
        const snapshot = this.loggedInUsersSnapshot || { sessions: [] };
        const sessions = Array.isArray(snapshot.sessions) ? snapshot.sessions : [];
        if (!sessions.length) {
            this.showNotification('No logged-in users are available to export.', 'info');
            return;
        }

        const headers = ['User Name', 'Email', 'Phone', 'Role', 'Device Type', 'Session Type', 'IP Address', 'Physical Location', 'Login Time', 'Session Age', 'Current Session'];
        const csvRows = [headers, ...sessions.map((session) => [
            session.user_name || '',
            session.user_email || '',
            session.phone_no || '',
            this.formatRoleLabel(session.user_role || 'user'),
            session.device_type || '',
            session.session_type || '',
            session.ip_address || '',
            session.physical_location || '',
            session.login_time || '',
            this.formatSessionDuration(session.session_age_seconds || 0),
            session.is_current_session ? 'Yes' : 'No'
        ])];

        const csv = csvRows.map((row) => row.map((value) => {
            const text = String(value ?? '');
            return /[",\r\n]/.test(text) ? `"${text.replace(/"/g, '""')}"` : text;
        }).join(',')).join('\r\n');

        const blob = new Blob([csv], { type: 'text/csv;charset=utf-8' });
        this.downloadBlob(blob, `logged-in-users-${new Date().toISOString().slice(0, 10)}.csv`);
        this.showNotification('Logged-in users exported successfully.', 'success');
    }

    printLoggedInUsers() {
        const snapshot = this.loggedInUsersSnapshot || { sessions: [], activeUsers: 0, activeSessions: 0, generatedAt: '' };
        const sessions = Array.isArray(snapshot.sessions) ? snapshot.sessions : [];
        const generatedLabel = this.formatAdminDateTime(snapshot.generatedAt);
        const reportTitle = 'PensionsGo Logged-in Users Session Report';
        const reportFooter = `${reportTitle} - Generated ${generatedLabel} - Admin Console security session audit`;
        const rows = sessions.map((session, index) => `
            <tr>
                <td>${index + 1}</td>
                <td>${this.escapeHtml(session.user_name || 'Unknown User')}</td>
                <td>${this.escapeHtml(session.user_email || session.phone_no || '')}</td>
                <td>${this.escapeHtml(this.formatRoleLabel(session.user_role || 'user'))}</td>
                <td>${this.escapeHtml(session.device_type || 'Unknown')}</td>
                <td>${this.escapeHtml(session.ip_address || 'N/A')}</td>
                <td>${this.escapeHtml(session.physical_location || 'Unknown Location')}</td>
                <td>${this.escapeHtml(this.formatAdminDateTime(session.login_time))}</td>
                <td>${this.escapeHtml(this.formatSessionDuration(session.session_age_seconds || 0))}</td>
            </tr>
        `).join('');

        const reportUrl = `admin_dashboard.html?print=logged-in-users-session-report&generated=${encodeURIComponent(new Date().toISOString().slice(0, 10))}`;
        const printWindow = window.open(reportUrl, '_blank', 'width=1100,height=760');
        if (!printWindow) {
            this.showNotification('Allow popups to print logged-in users.', 'error');
            return;
        }

        const writePrintDocument = () => {
            printWindow.document.open();
            printWindow.document.write(`
            <!doctype html>
            <html>
            <head>
                <title>${this.escapeHtml(reportTitle)}</title>
                <style>
                    @page { margin: 18mm 12mm 20mm; }
                    body { font-family: Arial, sans-serif; color: #1f2933; margin: 24px; padding-bottom: 42px; }
                    h1 { margin: 0 0 4px; color: #23395d; }
                    .meta { margin: 0 0 18px; color: #566; font-size: 13px; }
                    table { width: 100%; border-collapse: collapse; font-size: 12px; }
                    th, td { border: 1px solid #ccd3dc; padding: 7px 8px; text-align: left; vertical-align: top; }
                    th { background: #eef3f8; color: #23395d; }
                    tr:nth-child(even) td { background: #f8fafc; }
                    .print-footer {
                        position: fixed;
                        left: 24px;
                        right: 24px;
                        bottom: 12px;
                        border-top: 1px solid #ccd3dc;
                        color: #566;
                        font-size: 11px;
                        padding-top: 8px;
                        display: flex;
                        justify-content: space-between;
                        gap: 12px;
                    }
                </style>
            </head>
            <body>
                <h1>${this.escapeHtml(reportTitle)}</h1>
                <p class="meta">${this.escapeHtml(String(snapshot.activeUsers || 0))} users, ${this.escapeHtml(String(snapshot.activeSessions || 0))} sessions. Generated ${this.escapeHtml(generatedLabel)}.</p>
                <table>
                    <thead>
                        <tr><th>#</th><th>User</th><th>Contact</th><th>Role</th><th>Device</th><th>IP Address</th><th>Physical Location</th><th>Login Time</th><th>Session Age</th></tr>
                    </thead>
                    <tbody>${rows || '<tr><td colspan="9">No active sessions.</td></tr>'}</tbody>
                </table>
                <footer class="print-footer">
                    <span>${this.escapeHtml(reportFooter)}</span>
                    <span>Source: User Management - Logged-in Users</span>
                </footer>
            </body>
            </html>
            `);
            printWindow.document.close();
            printWindow.focus();
            setTimeout(() => printWindow.print(), 250);
        };

        setTimeout(writePrintDocument, 100);
    }

    formatSessionDuration(seconds) {
        const total = Math.max(0, Number(seconds) || 0);
        const days = Math.floor(total / 86400);
        const hours = Math.floor((total % 86400) / 3600);
        const minutes = Math.floor((total % 3600) / 60);
        if (days > 0) return `${days}d ${hours}h`;
        if (hours > 0) return `${hours}h ${minutes}m`;
        if (minutes > 0) return `${minutes}m`;
        return `${Math.floor(total % 60)}s`;
    }

    // Show access denied
    showAccessDenied() {
        document.getElementById('adminAccessDenied').style.display = 'flex';
    }

    // Show error state
    showErrorState(message) {
        const contentBody = document.getElementById('contentBody');
        if (contentBody) {
            contentBody.innerHTML = this.loadErrorContent('dashboard', new Error(message));
        }
    }

    // Perform logout
    async performLogout() {
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
            sessionStorage.clear();
            localStorage.removeItem('loggedInUser');
            localStorage.removeItem('userRole');
            window.location.href = 'login.html';
        }
    }

    // Refresh current section
    async refreshCurrentSection() {
        const section = String(this.currentSection || '').trim().toLowerCase();
        const settingsVerified = await this.requireSettingsSectionReauth(section, 'refresh');
        if (!settingsVerified) {
            return;
        }
        await this.loadSectionContent(section);
    }

    // Export current data
    async exportCurrentData() {
        let exportBtn;
        let originalText;

        try {
            // Show loading state
            exportBtn = document.getElementById('exportData');
            if (exportBtn) {
                originalText = exportBtn.innerHTML;
                exportBtn.innerHTML = '<span class="loading-spinner"></span> Exporting...';
                exportBtn.disabled = true;
            }

            let exportUrl;
            let filename;

            switch (this.currentSection) {
                case 'user-logs':
                    exportUrl = '../backend/api/exports/export_user_logs.php';
                    filename = `user-logs-${new Date().toISOString().split('T')[0]}.csv`;
                    break;
                
                case 'dashboard':
                    exportUrl = '../backend/api/exports/export_dashboard_data.php';
                    filename = `dashboard-data-${new Date().toISOString().split('T')[0]}.csv`;
                    break;
                
                case 'system-health':
                    exportUrl = '../backend/api/exports/export_system_health.php';
                    filename = `system-health-${new Date().toISOString().split('T')[0]}.csv`;
                    break;
                
                default:
                    throw new Error(`Export not available for ${this.currentSection}`);
            }

            // Get current filters for user logs
            const params = new URLSearchParams();
            if (this.currentSection === 'user-logs') {
                const activityType = document.getElementById('activityTypeFilter')?.value || '';
                const dateFrom = document.getElementById('dateFromFilter')?.value || '';
                const dateTo = document.getElementById('dateToFilter')?.value || '';
                
                if (activityType) params.append('activity_type', activityType);
                if (dateFrom) params.append('date_from', dateFrom);
                if (dateTo) params.append('date_to', dateTo);
            }

            const response = await fetch(`${exportUrl}?${params}`, {
                method: 'GET',
                credentials: 'include',
                headers: {
                    'Accept': 'application/json, text/csv',
                    'X-Requested-With': 'XMLHttpRequest'
                }
            });

            if (!response.ok) {
                throw new Error(`Export failed: ${response.status} ${response.statusText}`);
            }

            // Check if response is CSV or JSON
            const contentType = response.headers.get('content-type');
            
            if (contentType && contentType.includes('application/json')) {
                // Handle JSON response (error or success message)
                const data = await response.json();
                if (!data.success) {
                    throw new Error(data.message || 'Export failed');
                }
                
                // If JSON contains download URL, trigger download
                if (data.download_url) {
                    this.triggerDownload(data.download_url, filename);
                    this.showNotification('Export completed successfully!', 'success');
                } else {
                    this.showNotification(data.message || 'Export completed', 'success');
                }
            } else {
                // Handle CSV response directly
                const blob = await response.blob();
                this.downloadBlob(blob, filename);
                this.showNotification('Export completed successfully!', 'success');
            }

        } catch (error) {
            console.error('Export error:', error);
            this.showNotification(`Export failed: ${error.message}`, 'error');
        } finally {
            // Restore button state
            if (exportBtn) {
                exportBtn.innerHTML = originalText || '<span class="action-icon">&#128295;</span> Export Data';
                exportBtn.disabled = false;
            }
        }
    }

    // Helper method to trigger download
    triggerDownload(url, filename) {
        const link = document.createElement('a');
        link.href = url;
        link.download = filename;
        link.style.display = 'none';
        document.body.appendChild(link);
        link.click();
        document.body.removeChild(link);
    }

    // Helper method to download blob
    downloadBlob(blob, filename) {
        const url = window.URL.createObjectURL(blob);
        this.triggerDownload(url, filename);
        // Clean up
        setTimeout(() => window.URL.revokeObjectURL(url), 100);
    }

    // Enhanced showNotification method with export-specific styling
    showNotification(message, type = 'info') {
        // Create notification element
        const notification = document.createElement('div');
        notification.className = `admin-notification admin-notification-${type}`;
        notification.innerHTML = `
            <div class="notification-content">
                <span class="notification-icon">${this.getNotificationIcon(type)}</span>
                <span class="notification-message">${message}</span>
                <button class="notification-close">&times;</button>
            </div>
        `;

        // Add to page
        document.body.appendChild(notification);

        // Add close functionality
        notification.querySelector('.notification-close').addEventListener('click', () => {
            notification.remove();
        });

        // Auto-remove after appropriate time
        const duration = type === 'success' ? 3000 : 5000;
        setTimeout(() => {
            if (notification.parentNode) {
                notification.remove();
            }
        }, duration);
    }

    buildAdminSearchIndex() {
        const entry = (section, title, description, keywords = '') => ({
            id: `${section}:${title}`.toLowerCase().replace(/[^a-z0-9]+/g, '-'),
            section,
            sectionTitle: this.getSectionTitle(section),
            title,
            description,
            keywords
        });

        return [
            entry('app-settings', 'App Settings', 'Application identity, localisation, operational defaults, and public portal governance.', 'app name timezone support email landing page pensioner portal feedback'),
            entry('app-settings', 'Brand & Identity', 'Application name, branding assets, colours, and public identity.', 'brand identity logo app name organisation'),
            entry('app-settings', 'Localization', 'Date, time, locale, and formatting preferences.', 'timezone locale currency language date time'),
            entry('app-settings', 'Operations', 'Core defaults for application behaviour and operational cadence.', 'operations defaults workflow records pagination'),
            entry('app-settings', 'Pensioner Portal', 'Pensioner login, portal access, and pensioner-facing controls.', 'pensioner login portal access dashboard account'),
            entry('app-settings', 'Feedback Governance', 'Feedback submission availability and governance defaults.', 'feedback support submission governance audience'),

            entry('security-settings', 'Security Settings', 'Authentication, session hardening, browser restrictions, and request safeguards.', 'security session password developer tools csrf origin upload zip'),
            entry('security-settings', 'Authentication Policy', 'Password rules, login thresholds, and authentication controls.', 'password login attempts lockout authentication'),
            entry('security-settings', 'Session Security', 'Session timeout, re-authentication, device, and cookie controls.', 'session timeout re-authentication device token logout'),
            entry('user-management', 'Logged-in Users', 'Review active users and sessions, then export or print the live session list.', 'logged in users active sessions export print device ip physical location'),
            entry('security-settings', 'Security Alerts', 'Security alert email routing and high-risk notifications.', 'security alert email notifications'),
            entry('security-settings', 'Client-Side Protection', 'Developer tools, right click, copy, paste, selection, and browser restrictions.', 'developer tools right click copy paste selection protect'),
            entry('security-settings', 'Request Hardening', 'CSRF, origin validation, upload size limits, and ZIP limits.', 'csrf origin upload size zip import rows'),

            entry('access-control', 'Access Control', 'User access review, permissions, and pensioner password operations.', 'user selection permissions access matrix'),
            entry('access-control', 'User Selection', 'Search and choose users for access review and overrides.', 'user selection account review'),
            entry('access-control', 'Permission Matrix', 'Granular page and action permissions for users.', 'permission matrix roles pages actions'),
            entry('access-control', 'Pensioner Password Management', 'Reset or govern pensioner account passwords.', 'pensioner password reset credentials'),

            entry('role-settings', 'Role Governance', 'Role definitions, permission matrix, and custom role controls.', 'roles permissions custom role governance'),
            entry('role-settings', 'Role Definition', 'Create, edit, and maintain role metadata.', 'role definition name description'),
            entry('role-settings', 'Role Permission Matrix', 'Assign permissions to each role.', 'role permission matrix access'),

            entry('notification-settings', 'Notification Settings', 'Alert delivery, sender identity, digest rules, and queue worker controls.', 'notifications smtp sender digest queue email'),
            entry('notification-settings', 'Delivery Channels', 'Enable email, in-app, and operational notification channels.', 'delivery channels email in-app sms'),
            entry('notification-settings', 'Sender Identity', 'Outgoing email sender name and address.', 'sender identity from address from name'),
            entry('notification-settings', 'Delivery Rules', 'Notification timing, escalation, and recipient rules.', 'delivery rules alerts recipients retries'),
            entry('notification-settings', 'Live Chat Call Sounds', 'Incoming call ringtone, outgoing ringback tone, call notification permission, volume, and repeat limits.', 'live chat call sounds incoming outgoing ringtone ringback audio video call alerts'),
            entry('live-chat-settings', 'Live Chat Settings', 'Enable chat capabilities, calls, groups, attachments, receipts, drafts, polling cadence, and admin oversight behavior.', 'live chat settings groups calls attachments receipts drafts polling oversight archive'),
            entry('live-chat-settings', 'Live Chat Performance', 'Tune message, receipt, call, and signal polling intervals for the live chat experience.', 'live chat performance polling interval realtime receipts signals'),
            entry('notification-settings', 'Admin Digest', 'Daily digest recipient, schedule, and coverage controls.', 'daily digest recipient time queue'),
            entry('notification-settings', 'Queue Worker', 'Notification queue worker cadence, retries, and processing controls.', 'queue worker process queue retry batch'),
            entry('notification-queue', 'Notification Queue', 'Review, process, clear, and troubleshoot queued notifications.', 'notification queue sent failed clear process'),

            entry('podcast-settings', 'Podcast Library', 'Podcast audience rules, video management, and playback governance.', 'podcast youtube public staff pensioner videos'),
            entry('title-settings', 'Title Settings', 'Manage staff and pensioner title definitions.', 'titles rank salutation'),
            entry('bank-settings', 'Bank Settings', 'Manage bank catalogue values used in registry and pension records.', 'banks bank names bank codes banking catalogue'),
            entry('unit-settings', 'Prison Units', 'Manage prison units and institutional locations.', 'units station prison location'),
            entry('prison-district-settings', 'Prison Districts', 'Manage prison district reference data.', 'prison districts locations'),
            entry('prison-region-settings', 'Prison Regions', 'Manage prison region reference data.', 'prison regions locations'),
            entry('political-district-settings', 'Political Districts', 'Manage political districts used for address selection.', 'political districts regions address'),
            entry('faq-settings', 'FAQ Knowledge Base', 'Manage the questions and answers shown on the public FAQ page.', 'faq knowledge base questions answers guidance'),
            entry('terms-settings', 'Terms of Use', 'Maintain operational clauses shown in the Detailed Terms section.', 'terms of use clauses operational'),

            entry('storage-overview', 'Storage Overview', 'Managed storage footprint, thresholds, and backup posture.', 'storage overview thresholds backup retention cleanup'),
            entry('storage-overview', 'Capacity Thresholds', 'Warning and critical storage thresholds.', 'capacity threshold warning critical storage'),
            entry('storage-overview', 'Backup Posture', 'Backup retention, last backup posture, and backup-before-delete controls.', 'backup posture retention last backup delete'),
            entry('message-storage', 'Message Storage Management', 'Message retention, storage limits, soft delete, and snapshots.', 'message storage retention soft delete backup snapshot'),
            entry('attachment-storage', 'Attachment Storage', 'Attachment controls, retention, optimization, and virus scanning.', 'attachment storage virus scan file size controls'),
            entry('document-storage', 'Document Storage', 'Document registry, preview, naming scheme, classification, and dedupe.', 'document storage preview naming classification dedupe'),
            entry('document-storage', 'Document Governance', 'Document type mix, preview controls, and registry-linking governance.', 'document governance preview types registry linking'),
            entry('storage-cleanup', 'Storage Cleanup Tools', 'Retention windows and cleanup previews for operational data.', 'storage cleanup orphan documents exports backups sessions'),
            entry('storage-cleanup', 'Cleanup Candidates', 'Preview removable sessions, exports, and orphan documents.', 'cleanup candidates preview sessions exports orphan'),

            entry('chat-oversight', 'Chat Oversight', 'Read-only review of direct peer conversations and live chat groups for administrative audit.', 'chat oversight messages direct group read only transcript audit'),
            entry('chat-oversight', 'Chat Transcripts', 'Filter conversations, inspect message history, deleted badges, reactions, attachments, and read receipts.', 'chat transcript message history reactions attachments receipts deleted'),
            entry('workflow-logs', 'Workflow Reports', 'Retention, export, and capture rules for workflow reporting.', 'workflow reports retention comments assignment export verification escalation submitted applications'),
            entry('task-logs', 'Task Delegation', 'Delegation evidence, reason capture, escalation, and export controls.', 'task delegation escalation reason export'),
            entry('task-logs', 'Delegation by Role', 'Role-based delegation distribution insights.', 'delegation by role workload'),
            entry('system-logs', 'System Logs', 'System event retention, capture categories, and severity threshold.', 'system logs retention level warnings errors integrations'),

            entry('analysis-reporting', 'Analysis & Reporting', 'Analytical cadence, dashboards, exports, and digest delivery.', 'analysis reporting digest analytics snapshots forecast kpi'),
            entry('analysis-reporting', 'Analytics Digest Operations', 'Preview, queue, and review analytical digest runs.', 'analytics digest preview queue recipient frequency')
        ];
    }

    normalizeSearchText(value) {
        return String(value || '')
            .toLowerCase()
            .replace(/[^a-z0-9]+/g, ' ')
            .trim();
    }

    handleSearch(query) {
        const normalizedQuery = this.normalizeSearchText(query);
        if (normalizedQuery.length < 2) {
            this.activeAdminSearchResults = [];
            this.hideAdminSearchResults();
            return;
        }

        const results = this.adminSearchIndex
            .map((item) => {
                const title = this.normalizeSearchText(item.title);
                const description = this.normalizeSearchText(item.description);
                const keywords = this.normalizeSearchText(item.keywords);
                const haystack = `${title} ${description} ${keywords}`.trim();
                const terms = normalizedQuery.split(' ').filter(Boolean);
                const matchedTerms = terms.filter((term) => haystack.includes(term));
                const hasPhraseMatch = haystack.includes(normalizedQuery);

                if (!matchedTerms.length && !hasPhraseMatch) {
                    return null;
                }

                let score = 0;
                if (hasPhraseMatch) score += 120;
                if (title.startsWith(normalizedQuery)) score += 80;
                if (title.includes(normalizedQuery)) score += 50;
                if (description.includes(normalizedQuery)) score += 30;
                if (keywords.includes(normalizedQuery)) score += 25;
                score += matchedTerms.length * 12;

                return { ...item, score };
            })
            .filter(Boolean)
            .sort((left, right) => right.score - left.score)
            .slice(0, 8);

        this.activeAdminSearchResults = results;
        this.renderAdminSearchResults(results, normalizedQuery);
    }

    renderAdminSearchResults(results, query) {
        const container = document.getElementById('adminSearchResults');
        if (!container) {
            return;
        }

        if (!results.length) {
            container.innerHTML = `
                <div class="admin-search-empty">
                    <strong>No matching settings found</strong>
                    <span>Try a broader term like backup, session, digest, export, or virus scan.</span>
                </div>
            `;
            container.hidden = false;
            this.positionAdminSearchResults();
            return;
        }

        container.innerHTML = results.map((result) => `
            <button type="button" class="admin-search-result" data-search-result-id="${this.escapeHtml(result.id)}">
                <span class="admin-search-result-section">${this.escapeHtml(result.sectionTitle)}</span>
                <strong class="admin-search-result-title">${this.escapeHtml(result.title)}</strong>
                <span class="admin-search-result-description">${this.escapeHtml(result.description)}</span>
            </button>
        `).join('');

        container.querySelectorAll('.admin-search-result').forEach((button) => {
            button.addEventListener('click', async () => {
                const selected = results.find((item) => item.id === button.dataset.searchResultId);
                if (selected) {
                    await this.openAdminSearchResult(selected, query);
                }
            });
        });

        container.hidden = false;
        this.positionAdminSearchResults();
    }

    hideAdminSearchResults() {
        const container = document.getElementById('adminSearchResults');
        this.activeAdminSearchResults = [];
        if (!container) {
            return;
        }
        container.hidden = true;
        container.innerHTML = '';
        container.style.left = '';
        container.style.top = '';
        container.style.width = '';
        container.style.maxHeight = '';
    }

    async openAdminSearchResult(result, query = '') {
        this.pendingSearchTarget = {
            section: String(result.section || '').trim().toLowerCase(),
            query: query || result.title,
            title: result.title,
            description: result.description,
            keywords: result.keywords
        };

        const input = document.getElementById('adminSearch');
        if (input) {
            input.value = result.title;
        }

        this.hideAdminSearchResults();
        await this.navigateToSection(result.section);
    }

    clearSettingsSearchHighlights() {
        document.querySelectorAll('.settings-search-hit').forEach((element) => {
            element.classList.remove('settings-search-hit');
        });
    }

    findSettingsSearchTarget(searchTarget) {
        const contentBody = document.getElementById('contentBody');
        if (!contentBody || !searchTarget) {
            return null;
        }

        const phraseCandidates = [
            searchTarget.query,
            searchTarget.title,
            searchTarget.description
        ]
            .map((value) => this.normalizeSearchText(value))
            .filter(Boolean);

        const termCandidates = new Set();
        phraseCandidates.forEach((phrase) => {
            phrase.split(' ').filter(Boolean).forEach((term) => termCandidates.add(term));
        });
        this.normalizeSearchText(searchTarget.keywords).split(' ').filter(Boolean).forEach((term) => termCandidates.add(term));

        const selectors = [
            '.settings-field span',
            '.toggle-title',
            '.toggle-subtitle',
            '.settings-card-header h3',
            '.settings-card-header h4',
            '.section-card-header h2',
            '.section-card-header h3',
            '.settings-group-header h4',
            '.section-title',
            '.section-subtitle',
            '.settings-note',
            '.runtime-toolbar .action-btn',
            '.definition-list dt',
            '.definition-list dd',
            '.settings-status-badge'
        ];

        let bestNode = null;
        let bestScore = 0;

        contentBody.querySelectorAll(selectors.join(',')).forEach((node) => {
            const text = this.normalizeSearchText(node.textContent);
            if (!text) {
                return;
            }

            let score = 0;
            phraseCandidates.forEach((phrase) => {
                if (!phrase) return;
                if (text === phrase) {
                    score += 180;
                } else if (text.includes(phrase)) {
                    score += 100;
                }
            });

            termCandidates.forEach((term) => {
                if (text.includes(term)) {
                    score += 12;
                }
            });

            if (score > bestScore) {
                bestScore = score;
                bestNode = node.closest('.settings-field, .settings-toggle, .settings-card, .runtime-settings-card, .section-card-header, .settings-note') || node;
            }
        });

        if (bestNode) {
            return bestNode;
        }

        return contentBody.querySelector('.section-card-header, .settings-card, .section-title');
    }

    async applyPendingSearchTarget(section) {
        const target = this.pendingSearchTarget;
        const key = String(section || this.currentSection || '').trim().toLowerCase();
        if (!target || target.section !== key) {
            return;
        }

        await new Promise((resolve) => requestAnimationFrame(() => requestAnimationFrame(resolve)));
        this.clearSettingsSearchHighlights();

        const match = this.findSettingsSearchTarget(target);
        if (match) {
            match.classList.add('settings-search-hit');
            match.scrollIntoView({ behavior: 'smooth', block: 'center', inline: 'nearest' });
        }

        this.pendingSearchTarget = null;
    }

    // Load initial data
    loadInitialData() {
        // Load any initial data needed
        this.updateSystemStatus();
        this.updateLogCounters();
        if (!this.systemStatusTimer) {
            this.systemStatusTimer = setInterval(() => {
                this.updateSystemStatus();
            }, 5000);
        }
        if (!this.logCountersTimer) {
            this.logCountersTimer = setInterval(() => {
                this.updateLogCounters();
            }, 5000);
        }
    }

    async safeJson(response, fallback = { success: false }) {
        if (!response || !response.ok) {
            return fallback;
        }
        try {
            return await response.json();
        } catch (error) {
            console.warn('Failed to parse JSON response:', error);
            return fallback;
        }
    }

    // Update live counters in sidebar, welcome area, and dashboard cards
    async updateLogCounters() {
        try {
            const [usersResponse, logsResponse, activeSessionsResponse] = await Promise.all([
                fetch('../backend/api/get_users_summary.php', { credentials: 'include', cache: 'no-store' }).catch(() => ({ ok: false })),
                fetch('../backend/api/get_logs_summary.php', { credentials: 'include', cache: 'no-store' }).catch(() => ({ ok: false })),
                fetch('../backend/api/get_active_sessions.php', { credentials: 'include', cache: 'no-store' }).catch(() => ({ ok: false }))
            ]);

            const usersData = await this.safeJson(usersResponse);
            const logsData = await this.safeJson(logsResponse);
            const activeSessionsData = await this.safeJson(activeSessionsResponse);

            const totalUsers = usersData.success ? (usersData.total_users || 0) : 0;
            const todayLogs = logsData.success ? (logsData.summary?.today_logs || 0) : 0;
            const logBadgeCount = logsData.success
                ? (logsData.summary?.total_logs_all ?? logsData.summary?.total_logs ?? todayLogs)
                : 0;
            const activeSessionsValue = Number(activeSessionsData.active_sessions);
            const activeSessionsFromApi = Number.isFinite(activeSessionsValue)
                ? activeSessionsValue
                : null;
            const activeSessions = activeSessionsFromApi !== null
                ? activeSessionsFromApi
                : (logsData.success
                    ? (logsData.summary?.active_sessions_current ?? logsData.summary?.active_users_today ?? 0)
                    : 0);
            const activeUsersValue = Number(activeSessionsData.active_users);
            const activeUsers = Number.isFinite(activeUsersValue)
                ? activeUsersValue
                : activeSessions;
            const failedLogins = logsData.success ? (logsData.summary?.failed_logins_week || 0) : 0;

            const logBadge = document.getElementById('logCountBadge');
            if (logBadge) {
                logBadge.textContent = logBadgeCount;
            }

            const welcomeLogCount = document.getElementById('welcomeLogCount');
            if (welcomeLogCount) {
                welcomeLogCount.textContent = todayLogs;
            }

            const welcomeActiveSessions = document.getElementById('welcomeActiveSessions');
            if (welcomeActiveSessions) {
                welcomeActiveSessions.textContent = activeSessions;
            }

            const activeUsersCount = document.getElementById('activeUsersCount');
            if (activeUsersCount) {
                activeUsersCount.textContent = activeUsers;
            }

            const userCountBadge = document.getElementById('userCountBadge');
            if (userCountBadge) {
                userCountBadge.textContent = totalUsers;
            }

            const welcomeUserCount = document.getElementById('welcomeUserCount');
            if (welcomeUserCount) {
                welcomeUserCount.textContent = totalUsers;
            }

            const totalUsersStat = document.getElementById('totalUsersStat');
            if (totalUsersStat) {
                totalUsersStat.textContent = totalUsers;
            }

            const todayLogsStat = document.getElementById('todayLogsStat');
            if (todayLogsStat) {
                todayLogsStat.textContent = todayLogs;
            }

            const activeSessionsStat = document.getElementById('activeSessionsStat');
            if (activeSessionsStat) {
                activeSessionsStat.textContent = activeSessions;
            }

            const failedLoginsStat = document.getElementById('failedLoginsStat');
            if (failedLoginsStat) {
                failedLoginsStat.textContent = failedLogins;
            }
        } catch (error) {
            console.error('Error updating log counters:', error);
        }
    }

    // Update system status
    async updateSystemStatus() {
        try {
            // Update last backup time, active users, etc.
            const response = await fetch('../backend/api/get_system_status.php', {
                credentials: 'include'
            });
            
            if (response.ok) {
                const data = await response.json();
                if (data.success) {
                    // Update system health indicator
                    const healthIndicator = document.getElementById('systemHealthIndicator');
                    if (healthIndicator && data.systemHealth) {
                        healthIndicator.className = 'health-indicator';
                        healthIndicator.classList.add(data.systemHealth.status);
                        healthIndicator.textContent = "\u25CF";
                        healthIndicator.title = data.systemHealth.message;
                    }
                }
            } else if (response.status === 404) {
                // API endpoint doesn't exist yet
                console.warn('System status API not available yet');
                this.handleMissingAPI('get_system_status.php');
            }
        } catch (error) {
            console.error('Error updating system status:', error);
            this.handleSystemStatusError();
        }
    }

    // Handle missing APIs gracefully
    handleMissingAPI(apiName) {
        console.log(`API ${apiName} not available - using default values`);

        const healthIndicator = document.getElementById('systemHealthIndicator');
        if (healthIndicator) {
            healthIndicator.className = 'health-indicator';
            healthIndicator.classList.add('healthy');
            healthIndicator.textContent = "\u25CF";
            healthIndicator.title = 'System status monitoring not available';
        }
    }

    // Handle system status errors
    handleSystemStatusError() {
        const healthIndicator = document.getElementById('systemHealthIndicator');
        if (healthIndicator) {
            healthIndicator.className = 'health-indicator';
            healthIndicator.classList.add('warning');
            healthIndicator.textContent = "\u25CF";
            healthIndicator.title = 'Unable to determine system status';
        }
    }

    // Initialize section-specific scripts
    initializeSectionScripts(section) {
        section = String(section || '').trim().toLowerCase();
        switch (section) {
            case 'dashboard':
                this.initializeDashboardWidgets();
                break;
            case 'user-management':
                this.initializeUserManagement();
                break;
            case 'system-settings':
                this.initializeAppSettings();
                break;
            case 'app-settings':
                this.initializeAppSettings();
                break;
            case 'security-settings':
                this.initializeSecuritySettings();
                break;
            case 'access-control':
                this.initializeAccessControlSettings();
                break;
            case 'role-settings':
                this.initializeRoleSettings();
                break;
            case 'notification-settings':
                this.initializeNotificationSettings();
                break;
            case 'live-chat-settings':
                this.initializeLiveChatSettings();
                break;
            case 'public-chat-support':
            case 'public-live-chat':
            case 'public-chat-console':
            case 'public-chat-queue':
            case 'public-chat-active':
            case 'public-chat-assigned':
            case 'public-chat-offline':
            case 'public-chat-tickets':
            case 'public-chat-escalations':
            case 'public-chat-canned':
            case 'public-chat-agents':
            case 'public-chat-reports':
            case 'public-chat-audit':
                this.initializePublicChatSupport();
                break;
            case 'public-chat-settings':
                this.initializeLiveChatSettings();
                break;
            case 'notification-queue':
                this.initializeNotificationQueue();
                break;
            case 'podcast-settings':
                this.initializePodcastSettings();
                break;
            case 'title-settings':
                this.initializeTitleSettings();
                break;
            case 'bank-settings':
                this.initializeBankSettings();
                break;
            case 'unit-settings':
                this.initializeUnitSettings();
                break;
            case 'prison-district-settings':
                this.initializePrisonDistrictSettings();
                break;
            case 'prison-region-settings':
                this.initializePrisonRegionSettings();
                break;
            case 'political-district-settings':
                this.initializePoliticalDistrictSettings();
                break;
            case 'faq-settings':
                this.initializeFaqSettings();
                break;
            case 'terms-settings':
                this.initializeTermsSettings();
                break;
            case 'data-backup':
                this.initializeDataBackup();
                break;
            case 'data-export':
                this.initializeDataExport();
                break;
            case 'data-import':
                this.initializeDataImport();
                break;
            case 'data-cleanup':
                this.initializeDataCleanup();
                break;
            case 'message-storage':
                this.initializeMessageStorageSettings();
                break;
            case 'attachment-storage':
                this.initializeAttachmentStorageSettings();
                break;
            case 'document-storage':
                this.initializeDocumentStorageSettings();
                break;
            case 'chat-oversight':
                this.initializeChatOversight();
                break;
            case 'user-logs':
                this.initializeUserLogs();
                break;
            case 'audit-trail':
                this.initializeAuditTrail();
                break;
            case 'system-health':
                this.initializeSystemHealth();
                break;
            // Add more cases for other sections
        }
    }

    // Initialize user logs functionality
    async initializeUserLogs() {
        await this.loadUserLogs();
        
        // Setup filter event listeners
        document.getElementById('applyFilters').addEventListener('click', () => {
            this.loadUserLogs();
        });

        document.getElementById('clearFilters').addEventListener('click', () => {
            document.getElementById('activityTypeFilter').value = '';
            document.getElementById('dateFromFilter').value = '';
            document.getElementById('dateToFilter').value = '';
            this.loadUserLogs();
        });
    }

    // Initialize notification queue
    async initializeNotificationQueue() {
        await this.loadNotificationQueue();

        document.getElementById('applyQueueFilters')?.addEventListener('click', () => {
            this.loadNotificationQueue();
        });

        document.getElementById('clearQueueFilters')?.addEventListener('click', () => {
            const statusFilter = document.getElementById('queueStatusFilter');
            const channelFilter = document.getElementById('queueChannelFilter');
            const searchFilter = document.getElementById('queueSearchFilter');
            if (statusFilter) statusFilter.value = '';
            if (channelFilter) channelFilter.value = '';
            if (searchFilter) searchFilter.value = '';
            this.loadNotificationQueue();
        });

        document.getElementById('clearFilteredQueueBtn')?.addEventListener('click', () => {
            this.clearNotificationQueue('filtered');
        });

        document.getElementById('emptyNotificationQueueBtn')?.addEventListener('click', () => {
            this.clearNotificationQueue('all');
        });
    }

    async clearNotificationQueue(scope = 'filtered') {
        const status = document.getElementById('queueStatusFilter')?.value || '';
        const channel = document.getElementById('queueChannelFilter')?.value || '';
        const search = document.getElementById('queueSearchFilter')?.value || '';
        const isAll = scope === 'all';
        const confirmMessage = isAll
            ? 'Empty the entire notification queue, including queued and sent items?'
            : 'Clear the notification queue items that match the current filters?';

        const confirmed = typeof window.appConfirm === 'function'
            ? await window.appConfirm(confirmMessage, {
                title: isAll ? 'Empty Notification Queue' : 'Clear Notification Queue',
                confirmText: isAll ? 'Empty Queue' : 'Clear Filtered',
                cancelText: 'Cancel'
            })
            : false;
        if (!confirmed) {
            return;
        }

        try {
            const response = await this.performSensitiveAdminRequest('../backend/api/clear_notification_queue.php', {
                method: 'POST',
                credentials: 'include',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest'
                },
                body: JSON.stringify({ scope, status, channel, search })
            }, isAll ? 'empty the notification queue' : 'clear filtered notification queue records');
            const data = response.__adminPayloadAttached || await response.json();
            if (!response.ok || !data.success) {
                throw new Error(data.message || 'Unable to clear notification queue.');
            }
            this.showNotification(data.message || 'Notification queue cleared.', 'success');
            await this.loadNotificationQueue();
        } catch (error) {
            this.showNotification(error.message || 'Unable to clear notification queue.', 'error');
        }
    }

    // Initialize audit trail
    async initializeAuditTrail() {
        await this.populateAuditRoleFilterOptions();
        await this.loadAuditLogs();

        document.getElementById('applyAuditFilters')?.addEventListener('click', () => {
            this.loadAuditLogs();
        });

        document.getElementById('clearAuditFilters')?.addEventListener('click', () => {
            const actionFilter = document.getElementById('auditActionFilter');
            const roleFilter = document.getElementById('auditRoleFilter');
            const actorFilter = document.getElementById('auditActorFilter');
            const dateFrom = document.getElementById('auditDateFromFilter');
            const dateTo = document.getElementById('auditDateToFilter');
            if (actionFilter) actionFilter.value = '';
            if (roleFilter) roleFilter.value = '';
            if (actorFilter) actorFilter.value = '';
            if (dateFrom) dateFrom.value = '';
            if (dateTo) dateTo.value = '';
            this.loadAuditLogs();
        });
    }

    // Initialize user management
    async initializeUserManagement() {
        this.userManagementUsers = [];
        this.filteredUserManagementUsers = [];
        this.selectedUserIds = new Set();

        await this.loadUserManagementUsers();

        const searchInput = document.getElementById('userManagementSearch');
        if (searchInput) {
            searchInput.addEventListener('input', () => this.applyUserManagementFilters());
        }

        const roleFilter = document.getElementById('userRoleFilter');
        const accountTypeFilter = document.getElementById('userAccountTypeFilter');
        if (roleFilter) {
            roleFilter.addEventListener('change', () => this.applyUserManagementFilters());
        }
        if (accountTypeFilter) {
            accountTypeFilter.addEventListener('change', () => this.applyUserManagementFilters());
        }

        const clearFilters = document.getElementById('clearUserFilters');
        if (clearFilters) {
            clearFilters.addEventListener('click', () => {
                if (searchInput) searchInput.value = '';
                if (roleFilter) roleFilter.value = '';
                if (accountTypeFilter) accountTypeFilter.value = '';
                this.applyUserManagementFilters();
            });
        }

        const addUserBtn = document.getElementById('addUserBtn');
        if (addUserBtn) {
            addUserBtn.addEventListener('click', () => this.openUserModal('add'));
        }

        const exportUsersBtn = document.getElementById('exportUsersBtn');
        if (exportUsersBtn) {
            exportUsersBtn.addEventListener('click', () => this.exportUsers());
        }

        const loggedInUsersBtn = document.getElementById('viewLoggedInUsersBtn');
        if (loggedInUsersBtn) {
            loggedInUsersBtn.addEventListener('click', () => this.openLoggedInUsersModal());
        }

        const bulkDeleteBtn = document.getElementById('bulkDeleteUsersBtn');
        if (bulkDeleteBtn) {
            bulkDeleteBtn.addEventListener('click', () => this.confirmBulkDelete());
        }

        const selectAllFilteredBtn = document.getElementById('selectAllFilteredBtn');
        if (selectAllFilteredBtn) {
            selectAllFilteredBtn.addEventListener('click', () => {
                this.filteredUserManagementUsers.forEach(user => this.selectedUserIds.add(user.userId));
                this.renderUserManagementTable();
            });
        }
    }

    // Initialize title settings
    async initializeTitleSettings() {
        this.titleSettings = { titles: [], filtered: [] };
        await this.loadTitleSettings();

        const searchInput = document.getElementById('titleSearchInput');
        const categoryFilter = document.getElementById('titleCategoryFilter');
        const levelFilter = document.getElementById('titleLevelFilter');
        const statusFilter = document.getElementById('titleStatusFilter');
        const clearBtn = document.getElementById('clearTitleFilters');
        const addBtn = document.getElementById('addTitleBtn');
        const refreshBtn = document.getElementById('refreshTitlesBtn');

        if (searchInput) {
            searchInput.addEventListener('input', () => this.applyTitleFilters());
        }
        if (categoryFilter) {
            categoryFilter.addEventListener('change', () => this.applyTitleFilters());
        }
        if (levelFilter) {
            levelFilter.addEventListener('change', () => this.applyTitleFilters());
        }
        if (statusFilter) {
            statusFilter.addEventListener('change', () => this.applyTitleFilters());
        }
        if (clearBtn) {
            clearBtn.addEventListener('click', () => {
                if (searchInput) searchInput.value = '';
                if (categoryFilter) categoryFilter.value = '';
                if (levelFilter) levelFilter.value = '';
                if (statusFilter) statusFilter.value = '';
                this.applyTitleFilters();
            });
        }
        if (addBtn) {
            addBtn.addEventListener('click', () => this.openTitleModal('add'));
        }
        if (refreshBtn) {
            refreshBtn.addEventListener('click', () => this.loadTitleSettings());
        }

        const tableBody = document.getElementById('titleSettingsTableBody');
        if (tableBody) {
            tableBody.addEventListener('click', (event) => {
                const button = event.target.closest('button[data-action]');
                if (!button) return;
                const titleId = parseInt(button.getAttribute('data-id'), 10);
                const title = this.titleSettings.titles.find(item => item.title_id === titleId);
                if (!title) return;
                const action = button.getAttribute('data-action');
                if (action === 'edit') {
                    this.openTitleModal('edit', title);
                } else if (action === 'toggle') {
                    this.toggleTitleActive(title);
                } else if (action === 'delete') {
                    this.confirmTitleDelete(title);
                }
            });
        }
    }

    async initializeBankSettings() {
        this.bankSettings = { banks: [], filtered: [] };
        await this.loadBankSettings();

        const searchInput = document.getElementById('bankSearchInput');
        const statusFilter = document.getElementById('bankStatusFilter');
        const clearBtn = document.getElementById('clearBankFilters');
        const addBtn = document.getElementById('addBankBtn');
        const refreshBtn = document.getElementById('refreshBanksBtn');

        if (searchInput) {
            searchInput.addEventListener('input', () => this.applyBankFilters());
        }
        if (statusFilter) {
            statusFilter.addEventListener('change', () => this.applyBankFilters());
        }
        if (clearBtn) {
            clearBtn.addEventListener('click', () => {
                if (searchInput) searchInput.value = '';
                if (statusFilter) statusFilter.value = '';
                this.applyBankFilters();
            });
        }
        if (addBtn) {
            addBtn.addEventListener('click', () => this.openBankModal('add'));
        }
        if (refreshBtn) {
            refreshBtn.addEventListener('click', () => this.loadBankSettings());
        }

        const tableBody = document.getElementById('bankSettingsTableBody');
        if (tableBody) {
            tableBody.addEventListener('click', (event) => {
                const button = event.target.closest('button[data-action]');
                if (!button) return;
                const bankId = parseInt(button.getAttribute('data-id'), 10);
                const bank = this.bankSettings.banks.find((item) => item.bank_id === bankId);
                if (!bank) return;
                const action = button.getAttribute('data-action');
                if (action === 'edit') {
                    this.openBankModal('edit', bank);
                } else if (action === 'toggle') {
                    this.toggleBankActive(bank);
                } else if (action === 'delete') {
                    this.confirmBankDelete(bank);
                }
            });
        }
    }

    // Initialize unit settings
    async initializeUnitSettings() {
        this.unitSettings = { units: [], filtered: [] };
        await this.loadUnitSettings();

        const searchInput = document.getElementById('unitSearchInput');
        const regionFilter = document.getElementById('unitRegionFilter');
        const districtFilter = document.getElementById('unitDistrictFilter');
        const clearBtn = document.getElementById('clearUnitFilters');
        const addBtn = document.getElementById('addUnitBtn');
        const refreshBtn = document.getElementById('refreshUnitsBtn');

        if (searchInput) {
            searchInput.addEventListener('input', () => this.applyUnitFilters());
        }
        if (regionFilter) {
            regionFilter.addEventListener('change', () => this.applyUnitFilters());
        }
        if (districtFilter) {
            districtFilter.addEventListener('change', () => this.applyUnitFilters());
        }
        if (clearBtn) {
            clearBtn.addEventListener('click', () => {
                if (searchInput) searchInput.value = '';
                if (regionFilter) regionFilter.value = '';
                if (districtFilter) districtFilter.value = '';
                this.applyUnitFilters();
            });
        }
        if (addBtn) {
            addBtn.addEventListener('click', () => this.openUnitModal('add'));
        }
        if (refreshBtn) {
            refreshBtn.addEventListener('click', () => this.loadUnitSettings());
        }

        const tableBody = document.getElementById('unitSettingsTableBody');
        if (tableBody) {
            tableBody.addEventListener('click', (event) => {
                const button = event.target.closest('button[data-action]');
                if (!button) return;
                const unitId = parseInt(button.getAttribute('data-id'), 10);
                const unit = this.unitSettings.units.find(item => item.Id === unitId);
                if (!unit) return;
                const action = button.getAttribute('data-action');
                if (action === 'edit') {
                    this.openUnitModal('edit', unit);
                } else if (action === 'delete') {
                    this.confirmUnitDelete(unit);
                }
            });
        }
    }

    async initializePrisonDistrictSettings() {
        this.prisonDistrictSettings = { districts: [], filtered: [] };
        await this.loadPrisonDistrictSettings();

        const searchInput = document.getElementById('prisonDistrictSearchInput');
        const clearBtn = document.getElementById('clearPrisonDistrictFilters');
        const addBtn = document.getElementById('addPrisonDistrictBtn');
        const refreshBtn = document.getElementById('refreshPrisonDistrictsBtn');

        if (searchInput) {
            searchInput.addEventListener('input', () => this.applyPrisonDistrictFilters());
        }
        if (clearBtn) {
            clearBtn.addEventListener('click', () => {
                if (searchInput) searchInput.value = '';
                this.applyPrisonDistrictFilters();
            });
        }
        if (addBtn) {
            addBtn.addEventListener('click', () => this.openPrisonDistrictModal('add'));
        }
        if (refreshBtn) {
            refreshBtn.addEventListener('click', () => this.loadPrisonDistrictSettings());
        }

        const tableBody = document.getElementById('prisonDistrictTableBody');
        if (tableBody) {
            tableBody.addEventListener('click', (event) => {
                const countLink = event.target.closest('.unit-count-link');
                if (countLink && !countLink.disabled) {
                    this.openUnitFilterModal(
                        countLink.dataset.filterType,
                        countLink.dataset.filterValue,
                        countLink.dataset.filterLabel
                    );
                    return;
                }
                const button = event.target.closest('button[data-action]');
                if (!button) return;
                const districtId = parseInt(button.getAttribute('data-id'), 10);
                const district = this.prisonDistrictSettings.districts.find(item => item.district_id === districtId);
                if (!district) return;
                const action = button.getAttribute('data-action');
                if (action === 'edit') {
                    this.openPrisonDistrictModal('edit', district);
                } else if (action === 'delete') {
                    this.confirmPrisonDistrictDelete(district);
                }
            });
        }
    }

    async initializePrisonRegionSettings() {
        this.prisonRegionSettings = { regions: [], filtered: [] };
        await this.loadPrisonRegionSettings();

        const searchInput = document.getElementById('prisonRegionSearchInput');
        const clearBtn = document.getElementById('clearPrisonRegionFilters');
        const addBtn = document.getElementById('addPrisonRegionBtn');
        const refreshBtn = document.getElementById('refreshPrisonRegionsBtn');

        if (searchInput) {
            searchInput.addEventListener('input', () => this.applyPrisonRegionFilters());
        }
        if (clearBtn) {
            clearBtn.addEventListener('click', () => {
                if (searchInput) searchInput.value = '';
                this.applyPrisonRegionFilters();
            });
        }
        if (addBtn) {
            addBtn.addEventListener('click', () => this.openPrisonRegionModal('add'));
        }
        if (refreshBtn) {
            refreshBtn.addEventListener('click', () => this.loadPrisonRegionSettings());
        }

        const tableBody = document.getElementById('prisonRegionTableBody');
        if (tableBody) {
            tableBody.addEventListener('click', (event) => {
                const countLink = event.target.closest('.unit-count-link');
                if (countLink && !countLink.disabled) {
                    this.openUnitFilterModal(
                        countLink.dataset.filterType,
                        countLink.dataset.filterValue,
                        countLink.dataset.filterLabel
                    );
                    return;
                }
                const button = event.target.closest('button[data-action]');
                if (!button) return;
                const regionId = parseInt(button.getAttribute('data-id'), 10);
                const region = this.prisonRegionSettings.regions.find(item => item.region_id === regionId);
                if (!region) return;
                const action = button.getAttribute('data-action');
                if (action === 'edit') {
                    this.openPrisonRegionModal('edit', region);
                } else if (action === 'delete') {
                    this.confirmPrisonRegionDelete(region);
                }
            });
        }
    }

    async initializePoliticalDistrictSettings() {
        this.politicalDistrictSettings = { districts: [], filtered: [] };
        await this.loadPoliticalDistrictSettings();

        const searchInput = document.getElementById('politicalDistrictSearchInput');
        const regionFilter = document.getElementById('politicalDistrictRegionFilter');
        const clearBtn = document.getElementById('clearPoliticalDistrictFilters');
        const addBtn = document.getElementById('addPoliticalDistrictBtn');
        const refreshBtn = document.getElementById('refreshPoliticalDistrictsBtn');

        if (searchInput) {
            searchInput.addEventListener('input', () => this.applyPoliticalDistrictFilters());
        }
        if (regionFilter) {
            regionFilter.addEventListener('change', () => this.applyPoliticalDistrictFilters());
        }
        if (clearBtn) {
            clearBtn.addEventListener('click', () => {
                if (searchInput) searchInput.value = '';
                if (regionFilter) regionFilter.value = '';
                this.applyPoliticalDistrictFilters();
            });
        }
        if (addBtn) {
            addBtn.addEventListener('click', () => this.openPoliticalDistrictModal('add'));
        }
        if (refreshBtn) {
            refreshBtn.addEventListener('click', () => this.loadPoliticalDistrictSettings());
        }

        const tableBody = document.getElementById('politicalDistrictTableBody');
        if (tableBody) {
            tableBody.addEventListener('click', (event) => {
                const countLink = event.target.closest('.unit-count-link');
                if (countLink && !countLink.disabled) {
                    this.openUnitFilterModal(
                        countLink.dataset.filterType,
                        countLink.dataset.filterValue,
                        countLink.dataset.filterLabel
                    );
                    return;
                }
                const button = event.target.closest('button[data-action]');
                if (!button) return;
                const districtId = parseInt(button.getAttribute('data-id'), 10);
                const district = this.politicalDistrictSettings.districts.find(item => item.pol_id === districtId);
                if (!district) return;
                const action = button.getAttribute('data-action');
                if (action === 'edit') {
                    this.openPoliticalDistrictModal('edit', district);
                } else if (action === 'delete') {
                    this.confirmPoliticalDistrictDelete(district);
                }
            });
        }
    }

    // Initialize FAQ settings
    async initializeFaqSettings() {
        this.faqSettings = { entries: [], filtered: [] };
        await this.loadFaqSettings();

        const searchInput = document.getElementById('faqSearchInput');
        const categoryFilter = document.getElementById('faqCategoryFilter');
        const statusFilter = document.getElementById('faqStatusFilter');
        const featuredFilter = document.getElementById('faqFeaturedFilter');
        const clearBtn = document.getElementById('clearFaqFilters');
        const addBtn = document.getElementById('addFaqBtn');
        const refreshBtn = document.getElementById('refreshFaqBtn');

        if (searchInput) {
            searchInput.addEventListener('input', () => this.applyFaqFilters());
        }
        if (categoryFilter) {
            categoryFilter.addEventListener('change', () => this.applyFaqFilters());
        }
        if (statusFilter) {
            statusFilter.addEventListener('change', () => this.applyFaqFilters());
        }
        if (featuredFilter) {
            featuredFilter.addEventListener('change', () => this.applyFaqFilters());
        }
        if (clearBtn) {
            clearBtn.addEventListener('click', () => {
                if (searchInput) searchInput.value = '';
                if (categoryFilter) categoryFilter.value = '';
                if (statusFilter) statusFilter.value = '';
                if (featuredFilter) featuredFilter.value = '';
                this.applyFaqFilters();
            });
        }
        if (addBtn) {
            addBtn.addEventListener('click', () => this.openFaqModal('add'));
        }
        if (refreshBtn) {
            refreshBtn.addEventListener('click', () => this.loadFaqSettings());
        }

        const tableBody = document.getElementById('faqSettingsTableBody');
        if (tableBody) {
            tableBody.addEventListener('click', (event) => {
                const button = event.target.closest('button[data-action]');
                if (!button) return;
                const faqId = parseInt(button.getAttribute('data-id'), 10);
                const entry = this.faqSettings.entries.find(item => item.faq_id === faqId);
                if (!entry) return;
                const action = button.getAttribute('data-action');
                if (action === 'edit') {
                    this.openFaqModal('edit', entry);
                } else if (action === 'toggle') {
                    this.toggleFaqActive(entry);
                } else if (action === 'feature') {
                    this.toggleFaqFeatured(entry);
                } else if (action === 'delete') {
                    this.confirmFaqDelete(entry);
                }
            });
        }
    }

    // Initialize terms settings
    async initializeTermsSettings() {
        this.termsSettings = { clauses: [], filtered: [] };
        await this.loadTermsSettings();

        const searchInput = document.getElementById('termsSearchInput');
        const topicFilter = document.getElementById('termsTopicFilter');
        const statusFilter = document.getElementById('termsStatusFilter');
        const clearBtn = document.getElementById('clearTermsFilters');
        const addBtn = document.getElementById('addTermsBtn');
        const refreshBtn = document.getElementById('refreshTermsBtn');

        if (searchInput) {
            searchInput.addEventListener('input', () => this.applyTermsFilters());
        }
        if (topicFilter) {
            topicFilter.addEventListener('change', () => this.applyTermsFilters());
        }
        if (statusFilter) {
            statusFilter.addEventListener('change', () => this.applyTermsFilters());
        }
        if (clearBtn) {
            clearBtn.addEventListener('click', () => {
                if (searchInput) searchInput.value = '';
                if (topicFilter) topicFilter.value = '';
                if (statusFilter) statusFilter.value = '';
                this.applyTermsFilters();
            });
        }
        if (addBtn) {
            addBtn.addEventListener('click', () => this.openTermsModal('add'));
        }
        if (refreshBtn) {
            refreshBtn.addEventListener('click', () => this.loadTermsSettings());
        }

        const tableBody = document.getElementById('termsSettingsTableBody');
        if (tableBody) {
            tableBody.addEventListener('click', (event) => {
                const button = event.target.closest('button[data-action]');
                if (!button) return;
                const clauseId = parseInt(button.getAttribute('data-id'), 10);
                const clause = this.termsSettings.clauses.find(item => item.clause_id === clauseId);
                if (!clause) return;
                const action = button.getAttribute('data-action');
                if (action === 'edit') {
                    this.openTermsModal('edit', clause);
                } else if (action === 'toggle') {
                    this.toggleTermsActive(clause);
                } else if (action === 'delete') {
                    this.confirmTermsDelete(clause);
                }
            });
        }
    }

    // Initialize app settings
    async initializeAppSettings() {
        const form = document.getElementById('appSettingsForm');
        if (!form) return;

        const saveBtn = document.getElementById('saveAppSettingsBtn');
        if (saveBtn) {
            saveBtn.addEventListener('click', () => this.saveAppSettings());
        }

        const resetBtn = document.getElementById('resetAppSettingsBtn');
        if (resetBtn) {
            resetBtn.addEventListener('click', () => this.loadAppSettings(true));
        }

        const saveVersionBtn = document.getElementById('saveAppVersionBtn');
        if (saveVersionBtn) {
            saveVersionBtn.addEventListener('click', () => this.saveVersionSettings());
        }

        const resetVersionBtn = document.getElementById('resetAppVersionBtn');
        if (resetVersionBtn) {
            resetVersionBtn.addEventListener('click', () => this.loadVersionSettings(true));
        }

        const refreshVersionBtn = document.getElementById('refreshAppVersionBtn');
        if (refreshVersionBtn) {
            refreshVersionBtn.addEventListener('click', () => this.loadVersionSettings(true));
        }

        const useTodayBtn = document.getElementById('appVersionUseTodayBtn');
        if (useTodayBtn) {
            useTodayBtn.addEventListener('click', () => this.fillAppVersionReleaseDateToday());
        }

        const autoBuildBtn = document.getElementById('appVersionAutoBuildBtn');
        if (autoBuildBtn) {
            autoBuildBtn.addEventListener('click', () => this.fillAppVersionBuildValue());
        }

        form.querySelectorAll('[name^="app_version_"]').forEach((field) => {
            field.addEventListener('input', () => this.updateSettingsStatus('appVersion', 'Edited', 'info'));
            field.addEventListener('change', () => this.updateSettingsStatus('appVersion', 'Edited', 'info'));
        });

        await this.populateDefaultUserRoleSelect();
        await Promise.all([
            this.loadAppSettings(),
            this.loadVersionSettings()
        ]);
    }

    // Initialize security settings
    async initializeSecuritySettings() {
        const form = document.getElementById('securitySettingsForm');
        if (!form) return;

        const saveBtn = document.getElementById('saveSecuritySettingsBtn');
        if (saveBtn) {
            saveBtn.addEventListener('click', () => this.saveSecuritySettings());
        }

        const resetBtn = document.getElementById('resetSecuritySettingsBtn');
        if (resetBtn) {
            resetBtn.addEventListener('click', () => this.loadSecuritySettings(true));
        }

        await this.loadSecuritySettings();
    }

    // Initialize notification settings
    async initializeNotificationSettings() {
        const form = document.getElementById('notificationSettingsForm');
        if (!form) return;

        const saveBtn = document.getElementById('saveNotificationSettingsBtn');
        if (saveBtn) {
            saveBtn.addEventListener('click', () => this.saveNotificationSettings());
        }

        const resetBtn = document.getElementById('resetNotificationSettingsBtn');
        if (resetBtn) {
            resetBtn.addEventListener('click', () => this.loadNotificationSettings(true));
        }

        await this.loadNotificationSettings();
    }

    async initializeLiveChatSettings() {
        const form = document.getElementById('liveChatSettingsForm');
        if (!form) return;
        document.getElementById('saveLiveChatSettingsBtn')?.addEventListener('click', () => this.saveLiveChatSettings());
        document.getElementById('resetLiveChatSettingsBtn')?.addEventListener('click', () => this.loadLiveChatSettings(true));
        await this.loadLiveChatSettings();
    }

    // Initialize message storage settings
    async initializeMessageStorageSettings() {
        const form = document.getElementById('messageStorageForm');
        if (!form) return;

        const saveBtn = document.getElementById('saveMessageStorageBtn');
        if (saveBtn) {
            saveBtn.addEventListener('click', () => this.saveMessageStorageSettings());
        }

        const resetBtn = document.getElementById('resetMessageStorageBtn');
        if (resetBtn) {
            resetBtn.addEventListener('click', () => this.loadMessageStorageSettings(true));
        }

        await this.loadMessageStorageSettings();
    }

    // Initialize attachment storage settings
    async initializeAttachmentStorageSettings() {
        const form = document.getElementById('attachmentStorageForm');
        if (!form) return;

        const saveBtn = document.getElementById('saveAttachmentStorageBtn');
        if (saveBtn) {
            saveBtn.addEventListener('click', () => this.saveAttachmentStorageSettings());
        }

        const resetBtn = document.getElementById('resetAttachmentStorageBtn');
        if (resetBtn) {
            resetBtn.addEventListener('click', () => this.loadAttachmentStorageSettings(true));
        }

        await this.loadAttachmentStorageSettings();
    }

    invalidateAppSettingsCache() {
        this.appSettingsCache = null;
        this.appSettingsRequest = null;
        this.appSettingsFetchedAt = 0;
    }

    primeAppSettingsCache(settings = null) {
        if (!settings || typeof settings !== 'object') {
            this.invalidateAppSettingsCache();
            return;
        }
        this.appSettingsCache = { success: true, settings };
        this.appSettingsFetchedAt = Date.now();
    }

    async fetchAppSettingsBundle(forceRefresh = false) {
        const cacheIsFresh = this.appSettingsCache && (Date.now() - this.appSettingsFetchedAt) < 45000;
        if (!forceRefresh && cacheIsFresh) {
            return this.appSettingsCache;
        }

        if (!forceRefresh && this.appSettingsRequest) {
            return this.appSettingsRequest;
        }

        this.appSettingsRequest = (async () => {
            const response = await this.performSensitiveAdminRequest('../backend/api/get_app_settings.php', {
                credentials: 'include',
                cache: 'no-store'
            }, 'load application settings');
            const data = response.__adminPayloadAttached || await this.safeJson(response, { success: false });
            if (!data.success) {
                throw new Error(data.message || 'Unable to load app settings.');
            }

            this.appSettingsCache = data;
            this.appSettingsFetchedAt = Date.now();
            return data;
        })();

        try {
            return await this.appSettingsRequest;
        } finally {
            this.appSettingsRequest = null;
        }
    }

    async loadAppSettings(showNotification = false) {
        const form = document.getElementById('appSettingsForm');
        if (!form) return;

        try {
            this.updateSettingsStatus('app', 'Loading...', 'info');
            const data = await this.fetchAppSettingsBundle(showNotification);

            this.applyAppSettingsToForm(data.settings || {});
            this.updateSettingsStatus('app', 'Up to date', 'success');
            if (showNotification) {
                this.showNotification('Changes reverted to last saved settings.', 'info');
            }
        } catch (error) {
            console.error('Load app settings error:', error);
            this.updateSettingsStatus('app', 'Failed to load', 'error');
            this.showNotification('Unable to load app settings.', 'error');
        }
    }

    async loadSecuritySettings(showNotification = false) {
        const form = document.getElementById('securitySettingsForm');
        if (!form) return;

        try {
            this.updateSettingsStatus('security', 'Loading...', 'info');
            const data = await this.fetchAppSettingsBundle(showNotification);

            this.applySettingsToForm(form, data.settings || {});
            this.updateSettingsStatus('security', 'Up to date', 'success');
            if (showNotification) {
                this.showNotification('Changes reverted to last saved settings.', 'info');
            }
        } catch (error) {
            console.error('Load security settings error:', error);
            this.updateSettingsStatus('security', 'Failed to load', 'error');
            this.showNotification('Unable to load security settings.', 'error');
        }
    }

    async loadNotificationSettings(showNotification = false) {
        const form = document.getElementById('notificationSettingsForm');
        if (!form) return;

        try {
            this.updateSettingsStatus('notification', 'Loading...', 'info');
            const data = await this.fetchAppSettingsBundle(showNotification);

            this.applySettingsToForm(form, data.settings || {});
            await this.loadNotificationSoundLibrary({
                selectedPath: data.settings?.notify_broadcast_sound_path || '',
                incomingCallPath: data.settings?.live_call_incoming_sound_path || '',
                outgoingCallPath: data.settings?.live_call_outgoing_sound_path || ''
            });
            this.syncNotificationSoundRangeValue();
            this.refreshNotificationSoundControls();
            this.updateNotificationPermissionStatus();
            this.updateSettingsStatus('notification', 'Up to date', 'success');
            if (showNotification) {
                this.showNotification('Changes reverted to last saved settings.', 'info');
            }
        } catch (error) {
            console.error('Load notification settings error:', error);
            this.updateSettingsStatus('notification', 'Failed to load', 'error');
            this.showNotification('Unable to load notification settings.', 'error');
        }
    }

    async loadMessageStorageSettings(showNotification = false) {
        const form = document.getElementById('messageStorageForm');
        if (!form) return;

        try {
            this.updateSettingsStatus('message', 'Loading...', 'info');
            const data = await this.fetchAppSettingsBundle(showNotification);

            this.applySettingsToForm(form, data.settings || {});
            this.updateSettingsStatus('message', 'Up to date', 'success');
            if (showNotification) {
                this.showNotification('Changes reverted to last saved settings.', 'info');
            }
        } catch (error) {
            console.error('Load message storage settings error:', error);
            this.updateSettingsStatus('message', 'Failed to load', 'error');
            this.showNotification('Unable to load message storage settings.', 'error');
        }
    }

    async loadAttachmentStorageSettings(showNotification = false) {
        const form = document.getElementById('attachmentStorageForm');
        if (!form) return;

        try {
            this.updateSettingsStatus('attachment', 'Loading...', 'info');
            const data = await this.fetchAppSettingsBundle(showNotification);

            this.applySettingsToForm(form, data.settings || {});
            this.updateSettingsStatus('attachment', 'Up to date', 'success');
            if (showNotification) {
                this.showNotification('Changes reverted to last saved settings.', 'info');
            }
        } catch (error) {
            console.error('Load attachment settings error:', error);
            this.updateSettingsStatus('attachment', 'Failed to load', 'error');
            this.showNotification('Unable to load attachment settings.', 'error');
        }
    }

    applyAppSettingsToForm(settings) {
        const form = document.getElementById('appSettingsForm');
        if (!form) return;

        this.applySettingsToForm(form, settings);
    }

    fillAppVersionReleaseDateToday() {
        const field = document.querySelector('#appSettingsForm [name="app_version_release_date"]');
        if (!field) return;

        const now = new Date();
        const year = now.getFullYear();
        const month = String(now.getMonth() + 1).padStart(2, '0');
        const day = String(now.getDate()).padStart(2, '0');
        field.value = `${year}-${month}-${day}`;
        this.updateSettingsStatus('appVersion', 'Edited', 'info');
    }

    fillAppVersionBuildValue() {
        const field = document.querySelector('#appSettingsForm [name="app_version_build"]');
        if (!field) return;

        const now = new Date();
        const year = now.getFullYear();
        const month = String(now.getMonth() + 1).padStart(2, '0');
        const day = String(now.getDate()).padStart(2, '0');
        field.value = `${year}${month}${day}.1`;
        this.updateSettingsStatus('appVersion', 'Edited', 'info');
    }

    async loadVersionSettings(showNotification = false) {
        const form = document.getElementById('appSettingsForm');
        if (!form) return;

        try {
            this.updateSettingsStatus('appVersion', 'Loading...', 'info');
            const response = await fetch('../backend/api/get_version_manifest.php', {
                credentials: 'include',
                cache: 'no-store'
            });
            const data = await this.safeJson(response, { success: false });
            if (!data.success) {
                this.updateSettingsStatus('appVersion', 'Failed to load', 'error');
                this.showNotification(data.message || 'Unable to load application version settings.', 'error');
                return;
            }

            this.applyVersionSettingsToForm(data.manifest || {}, data.version || {}, data.meta || {});
            this.updateSettingsStatus('appVersion', 'Up to date', 'success');
            if (showNotification) {
                this.showNotification('Version settings reverted to the saved manifest.', 'info');
            }
        } catch (error) {
            console.error('Load version settings error:', error);
            this.updateSettingsStatus('appVersion', 'Failed to load', 'error');
            this.showNotification('Unable to load application version settings.', 'error');
        }
    }

    applyVersionSettingsToForm(manifest, versionInfo = {}, meta = {}) {
        const form = document.getElementById('appSettingsForm');
        if (!form) return;

        this.applySettingsToForm(form, {
            app_version_display_version: manifest.display_version || versionInfo.display_version || manifest.version || versionInfo.version || '',
            app_version_version: manifest.version || versionInfo.version || '',
            app_version_channel: manifest.channel || versionInfo.channel || '',
            app_version_build: manifest.build || versionInfo.build || '',
            app_version_release_date: manifest.release_date || versionInfo.release_date || '',
            app_version_schema_version: manifest.schema_version || versionInfo.schema_version || ''
        });

        this.renderAppVersionSummary(versionInfo, meta);
    }

    formatAppVersionLabel(versionInfo) {
        const displayVersion = String(versionInfo?.display_version || versionInfo?.version || '--').trim() || '--';
        const build = String(versionInfo?.build || '').trim();
        return build ? `${displayVersion} - build ${build}` : displayVersion;
    }

    formatAppVersionDate(value) {
        const raw = String(value || '').trim();
        if (!raw) return '--';
        const parsed = new Date(raw);
        if (Number.isNaN(parsed.getTime())) {
            return raw;
        }
        return parsed.toLocaleString();
    }

    renderAppVersionSummary(versionInfo = {}, meta = {}) {
        const summaryMap = {
            appVersionEffectiveLabel: this.formatAppVersionLabel(versionInfo),
            appVersionCacheVersion: String(versionInfo?.cache_version || '--').trim() || '--',
            appVersionBuildFingerprint: String(versionInfo?.build_fingerprint || '--').trim() || '--',
            appVersionManifestFile: String(meta?.manifest_file || 'app_version.json').trim() || 'app_version.json'
        };

        Object.entries(summaryMap).forEach(([id, value]) => {
            const node = document.getElementById(id);
            if (node) {
                node.textContent = value;
            }
        });

        const manifestMetaNode = document.getElementById('appVersionManifestMeta');
        if (manifestMetaNode) {
            const bits = [];
            bits.push(meta?.manifest_writable === false ? 'Read-only manifest' : 'Writable manifest');
            if (meta?.manifest_updated_at) {
                bits.push(`Updated ${this.formatAppVersionDate(meta.manifest_updated_at)}`);
            }
            manifestMetaNode.textContent = bits.join(' • ');
        }

        const saveVersionBtn = document.getElementById('saveAppVersionBtn');
        if (saveVersionBtn) {
            saveVersionBtn.disabled = meta?.manifest_writable === false;
        }

        this.syncVisibleAppVersionBadge(versionInfo);
    }

    syncVisibleAppVersionBadge(versionInfo = {}) {
        const displayVersion = String(versionInfo?.display_version || versionInfo?.version || '').trim();
        const build = String(versionInfo?.build || '').trim();
        const channel = String(versionInfo?.channel || '').trim();
        const schemaVersion = String(versionInfo?.schema_version || '').trim();

        if (displayVersion) localStorage.setItem('pwaAppVersion', displayVersion);
        if (build) localStorage.setItem('pwaAppBuildId', build);
        if (channel) localStorage.setItem('pwaAppChannel', channel);
        if (schemaVersion) localStorage.setItem('pwaAppSchemaVersion', schemaVersion);

        const badge = document.getElementById('footerBuildBadge');
        const valueNode = document.getElementById('footerBuildVersion');
        if (badge && valueNode) {
            valueNode.textContent = this.formatAppVersionLabel(versionInfo);
            badge.classList.remove('hidden');
            const tooltipBits = [];
            if (channel) tooltipBits.push(`Channel: ${channel}`);
            if (schemaVersion) tooltipBits.push(`Schema: ${schemaVersion}`);
            if (versionInfo?.release_date) tooltipBits.push(`Release: ${versionInfo.release_date}`);
            badge.title = tooltipBits.join(' | ');
        }
    }

    applySettingsToForm(form, settings) {
        Object.entries(settings).forEach(([key, value]) => {
            const field = form.querySelector(`[name="${key}"]`);
            if (!field) return;

            if (field.type === 'checkbox') {
                field.checked = Boolean(value);
            } else if (field.tagName === 'SELECT' || field.tagName === 'TEXTAREA' || field.tagName === 'INPUT') {
                field.value = value ?? '';
            }
        });
    }

    getAppSettingsPayload() {
        const form = document.getElementById('appSettingsForm');
        if (!form) return null;

        const getValue = (name) => form.querySelector(`[name="${name}"]`);
        const getString = (name) => getValue(name)?.value?.trim() || '';
        const getNumber = (name) => {
            const raw = getValue(name)?.value;
            return raw === '' || raw === null || raw === undefined ? null : Number(raw);
        };
        const getBool = (name) => Boolean(getValue(name)?.checked);

        return {
            app_name: getString('app_name'),
            app_tagline: getString('app_tagline'),
            support_email: getString('support_email'),
            support_phone: getString('support_phone'),
            public_footer_org_name: getString('public_footer_org_name'),
            public_footer_address: getString('public_footer_address'),
            public_footer_tech_support_email: getString('public_footer_tech_support_email'),
            public_footer_social_facebook: getString('public_footer_social_facebook'),
            public_footer_social_twitter: getString('public_footer_social_twitter'),
            public_footer_social_instagram: getString('public_footer_social_instagram'),
            public_footer_social_linkedin: getString('public_footer_social_linkedin'),
            public_footer_developer_name: getString('public_footer_developer_name'),
            public_footer_developer_email: getString('public_footer_developer_email'),
            public_footer_developer_phone: getString('public_footer_developer_phone'),
            default_user_role: getString('default_user_role'),
            login_banner: getString('login_banner'),
            maintenance_mode: getBool('maintenance_mode'),
            timezone: getString('timezone'),
            date_format: getString('date_format'),
            time_format: getString('time_format'),
            currency: getString('currency'),
            log_retention_days: getNumber('log_retention_days'),
            enable_notifications: getBool('enable_notifications'),
            staff_login_enabled: getBool('staff_login_enabled'),
            pensioner_login_enabled: getBool('pensioner_login_enabled'),
            pensioner_dashboard_enable_claims: getBool('pensioner_dashboard_enable_claims'),
            pensioner_dashboard_enable_documents: getBool('pensioner_dashboard_enable_documents'),
            pensioner_dashboard_enable_status_explanations: getBool('pensioner_dashboard_enable_status_explanations'),
            pensioner_dashboard_enable_activity_log: getBool('pensioner_dashboard_enable_activity_log'),
            pensioner_lookup_enabled: getBool('pensioner_lookup_enabled'),
            pensioner_lookup_require_consent: getBool('pensioner_lookup_require_consent'),
            pensioner_lookup_log_activity: getBool('pensioner_lookup_log_activity')
        };
    }

    getVersionSettingsPayload() {
        const form = document.getElementById('appSettingsForm');
        if (!form) return null;

        const getValue = (name) => form.querySelector(`[name="${name}"]`);
        const getString = (name) => getValue(name)?.value?.trim() || '';

        return {
            display_version: getString('app_version_display_version'),
            version: getString('app_version_version'),
            channel: getString('app_version_channel'),
            build: getString('app_version_build'),
            release_date: getString('app_version_release_date'),
            schema_version: getString('app_version_schema_version')
        };
    }

    getSecuritySettingsPayload() {
        const form = document.getElementById('securitySettingsForm');
        if (!form) return null;

        const getValue = (name) => form.querySelector(`[name="${name}"]`);
        const getString = (name) => getValue(name)?.value?.trim() || '';
        const getNumber = (name) => {
            const raw = getValue(name)?.value;
            return raw === '' || raw === null || raw === undefined ? null : Number(raw);
        };
        const getBool = (name) => Boolean(getValue(name)?.checked);

        return {
            password_min_length: getNumber('password_min_length'),
            password_require_uppercase: getBool('password_require_uppercase'),
            password_require_lowercase: getBool('password_require_lowercase'),
            password_require_number: getBool('password_require_number'),
            password_require_special: getBool('password_require_special'),
            password_expiry_days: getNumber('password_expiry_days'),
            login_attempt_limit: getNumber('login_attempt_limit'),
            lockout_minutes: getNumber('lockout_minutes'),
            session_timeout_minutes: getNumber('session_timeout_minutes'),
            session_idle_warning_minutes: getNumber('session_idle_warning_minutes'),
            grace_period_minutes: getNumber('grace_period_minutes'),
            task_due_business_days: getNumber('task_due_business_days'),
            task_grace_business_days: getNumber('task_grace_business_days'),
            task_alert_due_soon_hours: getNumber('task_alert_due_soon_hours'),
            task_alert_stalled_hours: getNumber('task_alert_stalled_hours'),
            task_alert_escalation_hours: getNumber('task_alert_escalation_hours'),
            payroll_reconcile_debounce_seconds: getNumber('payroll_reconcile_debounce_seconds'),
            task_skip_weekends: getBool('task_skip_weekends'),
            task_skip_ug_holidays: getBool('task_skip_ug_holidays'),
            task_alerts_enabled: getBool('task_alerts_enabled'),
            max_concurrent_sessions: getNumber('max_concurrent_sessions'),
            allow_multiple_devices: getBool('allow_multiple_devices'),
            auto_logout_on_conflict: getBool('auto_logout_on_conflict'),
            security_alert_email: getString('security_alert_email'),
            security_alert_sms: getString('security_alert_sms'),
            enable_activity_logs: getBool('enable_activity_logs'),
            enable_audit_logs: getBool('enable_audit_logs'),
            notify_task_alerts_enabled: getBool('notify_task_alerts_enabled'),
            security_block_developer_tools: getBool('security_block_developer_tools'),
            security_block_context_menu: getBool('security_block_context_menu'),
            security_block_copy: getBool('security_block_copy'),
            security_block_cut: getBool('security_block_cut'),
            security_block_paste: getBool('security_block_paste'),
            security_block_text_selection: getBool('security_block_text_selection'),
            security_block_drag: getBool('security_block_drag'),
            security_enforce_csrf: getBool('security_enforce_csrf'),
            security_validate_origin: getBool('security_validate_origin'),
            security_allowed_origins: getString('security_allowed_origins'),
            security_admin_reauth_window_minutes: getNumber('security_admin_reauth_window_minutes'),
            security_max_upload_size_mb: getNumber('security_max_upload_size_mb'),
            security_max_zip_uncompressed_mb: getNumber('security_max_zip_uncompressed_mb'),
            security_max_import_rows: getNumber('security_max_import_rows'),
            security_max_zip_entries: getNumber('security_max_zip_entries')
        };
    }

    getNotificationSettingsPayload() {
        const form = document.getElementById('notificationSettingsForm');
        if (!form) return null;

        const getValue = (name) => form.querySelector(`[name="${name}"]`);
        const getString = (name) => getValue(name)?.value?.trim() || '';
        const getBool = (name) => Boolean(getValue(name)?.checked);

        return {
            notify_email_enabled: getBool('notify_email_enabled'),
            notify_sms_enabled: getBool('notify_sms_enabled'),
            notify_push_enabled: getBool('notify_push_enabled'),
            notify_sender_name: getString('notify_sender_name'),
            notify_sender_email: getString('notify_sender_email'),
            notify_test_recipient: getString('notify_test_recipient'),
            notify_system_alerts_enabled: getBool('notify_system_alerts_enabled'),
            notify_user_activity_enabled: getBool('notify_user_activity_enabled'),
            notify_broadcast_enabled: getBool('notify_broadcast_enabled'),
            notify_broadcast_sound_enabled: getBool('notify_broadcast_sound_enabled'),
            notify_broadcast_sound_path: getString('notify_broadcast_sound_path'),
            notify_broadcast_sound_volume: Number(getValue('notify_broadcast_sound_volume')?.value || 85),
            notify_broadcast_sound_repeat_count: Number(getValue('notify_broadcast_sound_repeat_count')?.value || 1),
            notify_broadcast_desktop_enabled: getBool('notify_broadcast_desktop_enabled'),
            notify_broadcast_desktop_hidden_only: getBool('notify_broadcast_desktop_hidden_only'),
            live_call_incoming_sound_enabled: getBool('live_call_incoming_sound_enabled'),
            live_call_outgoing_sound_enabled: getBool('live_call_outgoing_sound_enabled'),
            live_call_desktop_alerts_enabled: getBool('live_call_desktop_alerts_enabled'),
            live_call_incoming_sound_path: getString('live_call_incoming_sound_path'),
            live_call_outgoing_sound_path: getString('live_call_outgoing_sound_path'),
            live_call_incoming_sound_volume: Number(getValue('live_call_incoming_sound_volume')?.value || 85),
            live_call_outgoing_sound_volume: Number(getValue('live_call_outgoing_sound_volume')?.value || 55),
            live_call_incoming_sound_repeat_count: Number(getValue('live_call_incoming_sound_repeat_count')?.value || 0),
            live_call_outgoing_sound_repeat_count: Number(getValue('live_call_outgoing_sound_repeat_count')?.value || 0),
            live_call_ringing_timeout_seconds: Number(getValue('live_call_ringing_timeout_seconds')?.value || 45),
            live_message_sound_enabled: getBool('live_message_sound_enabled'),
            live_message_desktop_alerts_enabled: getBool('live_message_desktop_alerts_enabled'),
            live_message_sound_path: getString('live_message_sound_path'),
            live_message_sound_volume: Number(getValue('live_message_sound_volume')?.value || 70),
            live_message_sound_repeat_count: Number(getValue('live_message_sound_repeat_count')?.value || 1),
            notify_quiet_hours_start: getString('notify_quiet_hours_start'),
            notify_quiet_hours_end: getString('notify_quiet_hours_end'),
            notify_admin_digest_enabled: getBool('notify_admin_digest_enabled'),
            notify_digest_time: getString('notify_digest_time'),
            notify_queue_worker_enabled: getBool('notify_queue_worker_enabled'),
            notify_queue_process_on_request: getBool('notify_queue_process_on_request'),
            notify_queue_batch_size: Number(getValue('notify_queue_batch_size')?.value || 10),
            notify_queue_retry_limit: Number(getValue('notify_queue_retry_limit')?.value || 3),
            notify_queue_retry_delay_minutes: Number(getValue('notify_queue_retry_delay_minutes')?.value || 10),
            notify_queue_min_interval_seconds: Number(getValue('notify_queue_min_interval_seconds')?.value || 60)
        };
    }

    getLiveChatSettingsPayload() {
        const form = document.getElementById('liveChatSettingsForm');
        if (!form) return null;

        const getValue = (name) => form.querySelector(`[name="${name}"]`);
        const getBool = (name) => Boolean(getValue(name)?.checked);
        const getNumber = (name) => {
            const raw = getValue(name)?.value;
            return raw === '' || raw === null || raw === undefined ? null : Number(raw);
        };

        return {
            live_chat_enabled: getBool('live_chat_enabled'),
            live_chat_group_chats_enabled: getBool('live_chat_group_chats_enabled'),
            live_chat_audio_calls_enabled: getBool('live_chat_audio_calls_enabled'),
            live_chat_video_calls_enabled: getBool('live_chat_video_calls_enabled'),
            live_chat_add_participants_enabled: getBool('live_chat_add_participants_enabled'),
            live_chat_attachments_enabled: getBool('live_chat_attachments_enabled'),
            live_chat_voice_notes_enabled: getBool('live_chat_voice_notes_enabled'),
            live_chat_polls_enabled: getBool('live_chat_polls_enabled'),
            live_chat_typing_presence_enabled: getBool('live_chat_typing_presence_enabled'),
            live_chat_read_receipts_enabled: getBool('live_chat_read_receipts_enabled'),
            live_chat_drafts_enabled: getBool('live_chat_drafts_enabled'),
            live_chat_admin_archive_enabled: getBool('live_chat_admin_archive_enabled'),
            live_chat_admin_delete_enabled: getBool('live_chat_admin_delete_enabled'),
            live_chat_edit_window_minutes: getNumber('live_chat_edit_window_minutes'),
            live_chat_typing_idle_seconds: getNumber('live_chat_typing_idle_seconds'),
            live_chat_message_poll_ms: getNumber('live_chat_message_poll_ms'),
            live_chat_receipt_poll_ms: getNumber('live_chat_receipt_poll_ms'),
            live_chat_call_poll_ms: getNumber('live_chat_call_poll_ms'),
            live_chat_signal_poll_ms: getNumber('live_chat_signal_poll_ms'),
            public_chat_enabled: getBool('public_chat_enabled'),
            public_chat_public_pages_enabled: getBool('public_chat_public_pages_enabled'),
            public_chat_home_enabled: getBool('public_chat_home_enabled'),
            public_chat_about_enabled: getBool('public_chat_about_enabled'),
            public_chat_faq_enabled: getBool('public_chat_faq_enabled'),
            public_chat_podcast_enabled: getBool('public_chat_podcast_enabled'),
            public_chat_feedback_page_enabled: getBool('public_chat_feedback_page_enabled'),
            public_chat_terms_enabled: getBool('public_chat_terms_enabled'),
            public_chat_pensioner_portal_enabled: getBool('public_chat_pensioner_portal_enabled'),
            public_chat_attachments_enabled: getBool('public_chat_attachments_enabled'),
            public_chat_auto_assign_enabled: getBool('public_chat_auto_assign_enabled'),
            public_chat_transcript_enabled: getBool('public_chat_transcript_enabled'),
            public_chat_feedback_enabled: getBool('public_chat_feedback_enabled'),
            public_chat_max_active_chats_per_agent: getNumber('public_chat_max_active_chats_per_agent'),
            public_chat_max_message_length: getNumber('public_chat_max_message_length'),
            public_chat_poll_interval_ms: getNumber('public_chat_poll_interval_ms'),
            public_chat_max_attachment_size_mb: getNumber('public_chat_max_attachment_size_mb'),
            public_chat_rate_limit_start_per_10min: getNumber('public_chat_rate_limit_start_per_10min'),
            public_chat_rate_limit_messages_per_5min: getNumber('public_chat_rate_limit_messages_per_5min'),
            public_chat_working_hours: String(getValue('public_chat_working_hours')?.value || '').trim(),
            public_chat_allowed_attachment_types: String(getValue('public_chat_allowed_attachment_types')?.value || '').trim(),
            public_chat_welcome_text: String(getValue('public_chat_welcome_text')?.value || '').trim(),
            public_chat_consent_text: String(getValue('public_chat_consent_text')?.value || '').trim(),
            public_chat_offline_message: String(getValue('public_chat_offline_message')?.value || '').trim()
        };
    }

    getMessageStoragePayload() {
        const form = document.getElementById('messageStorageForm');
        if (!form) return null;

        const getValue = (name) => form.querySelector(`[name="${name}"]`);
        const getNumber = (name) => {
            const raw = getValue(name)?.value;
            return raw === '' || raw === null || raw === undefined ? null : Number(raw);
        };
        const getBool = (name) => Boolean(getValue(name)?.checked);

        return {
            message_retention_days: getNumber('message_retention_days'),
            message_archive_after_days: getNumber('message_archive_after_days'),
            message_allow_soft_delete: getBool('message_allow_soft_delete'),
            message_storage_quota_mb: getNumber('message_storage_quota_mb'),
            message_compress_enabled: getBool('message_compress_enabled'),
            message_backup_enabled: getBool('message_backup_enabled')
        };
    }

    getAttachmentStoragePayload() {
        const form = document.getElementById('attachmentStorageForm');
        if (!form) return null;

        const getValue = (name) => form.querySelector(`[name="${name}"]`);
        const getNumber = (name) => {
            const raw = getValue(name)?.value;
            return raw === '' || raw === null || raw === undefined ? null : Number(raw);
        };
        const getString = (name) => getValue(name)?.value?.trim() || '';
        const getBool = (name) => Boolean(getValue(name)?.checked);

        return {
            attachment_max_size_mb: getNumber('attachment_max_size_mb'),
            attachment_allowed_types: getString('attachment_allowed_types'),
            attachment_scan_enabled: getBool('attachment_scan_enabled'),
            attachment_retention_days: getNumber('attachment_retention_days'),
            attachment_dedupe_enabled: getBool('attachment_dedupe_enabled'),
            attachment_compress_enabled: getBool('attachment_compress_enabled')
        };
    }

    async saveAppSettings() {
        const payload = this.getAppSettingsPayload();
        if (!payload) return;

        try {
            this.updateSettingsStatus('app', 'Saving...', 'info');
            const response = await this.performSensitiveAdminRequest('../backend/api/update_app_settings.php', {
                method: 'POST',
                credentials: 'include',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload)
            }, 'save application settings');
            const data = response.__adminPayloadAttached || await this.safeJson(response, { success: false });

            if (!data.success) {
                this.updateSettingsStatus('app', 'Save failed', 'error');
                this.showNotification(data.message || 'Unable to save settings.', 'error');
                return;
            }

            this.showNotification('App settings saved successfully.', 'success');
            this.updateSettingsStatus('app', 'Saved', 'success');
            if (data.settings) {
                this.applyAppSettingsToForm(data.settings);
                this.primeAppSettingsCache(data.settings);
            } else {
                this.invalidateAppSettingsCache();
            }
        } catch (error) {
            console.error('Save app settings error:', error);
            this.updateSettingsStatus('app', 'Save failed', 'error');
            this.showNotification('Unable to save settings.', 'error');
        }
    }

    async saveVersionSettings() {
        const payload = this.getVersionSettingsPayload();
        if (!payload) return;
        if (!payload.version) {
            this.updateSettingsStatus('appVersion', 'Validation failed', 'error');
            this.showNotification('Provide the internal version before saving.', 'error');
            return;
        }

        try {
            this.updateSettingsStatus('appVersion', 'Saving...', 'info');
            const response = await fetch('../backend/api/update_app_version.php', {
                method: 'POST',
                credentials: 'include',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload)
            });
            const data = await this.safeJson(response, { success: false });

            if (!data.success) {
                this.updateSettingsStatus('appVersion', 'Save failed', 'error');
                this.showNotification(data.message || 'Unable to save application version.', 'error');
                return;
            }

            this.applyVersionSettingsToForm(data.manifest || payload, data.version || {}, data.meta || {});
            this.showNotification(data.message || 'Application version updated successfully.', 'success');
            this.updateSettingsStatus('appVersion', 'Saved', 'success');
        } catch (error) {
            console.error('Save version settings error:', error);
            this.updateSettingsStatus('appVersion', 'Save failed', 'error');
            this.showNotification('Unable to save application version.', 'error');
        }
    }

    async saveSecuritySettings() {
        const payload = this.getSecuritySettingsPayload();
        if (!payload) return;

        try {
            this.updateSettingsStatus('security', 'Saving...', 'info');
            const response = await this.performSensitiveAdminRequest('../backend/api/update_app_settings.php', {
                method: 'POST',
                credentials: 'include',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload)
            }, 'save security settings');
            const data = response.__adminPayloadAttached || await this.safeJson(response, { success: false });

            if (!data.success) {
                this.updateSettingsStatus('security', 'Save failed', 'error');
                this.showNotification(data.message || 'Unable to save security settings.', 'error');
                return;
            }

            this.showNotification('Security settings saved successfully.', 'success');
            this.updateSettingsStatus('security', 'Saved', 'success');
            if (data.settings) {
                const form = document.getElementById('securitySettingsForm');
                if (form) {
                    this.applySettingsToForm(form, data.settings);
                }
                this.primeAppSettingsCache(data.settings);
            } else {
                this.invalidateAppSettingsCache();
            }
        } catch (error) {
            console.error('Save security settings error:', error);
            this.updateSettingsStatus('security', 'Save failed', 'error');
            this.showNotification('Unable to save security settings.', 'error');
        }
    }

    async saveNotificationSettings() {
        const payload = this.getNotificationSettingsPayload();
        if (!payload) return;

        try {
            this.updateSettingsStatus('notification', 'Saving...', 'info');
            const response = await this.performSensitiveAdminRequest('../backend/api/update_app_settings.php', {
                method: 'POST',
                credentials: 'include',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload)
            }, 'save notification settings');
            const data = response.__adminPayloadAttached || await this.safeJson(response, { success: false });

            if (!data.success) {
                this.updateSettingsStatus('notification', 'Save failed', 'error');
                this.showNotification(data.message || 'Unable to save notification settings.', 'error');
                return;
            }

            this.showNotification('Notification settings saved successfully.', 'success');
            this.updateSettingsStatus('notification', 'Saved', 'success');
            if (data.settings) {
                const form = document.getElementById('notificationSettingsForm');
                if (form) {
                    this.applySettingsToForm(form, data.settings);
                }
                this.primeAppSettingsCache(data.settings);
            } else {
                this.invalidateAppSettingsCache();
            }
        } catch (error) {
            console.error('Save notification settings error:', error);
            this.updateSettingsStatus('notification', 'Save failed', 'error');
            this.showNotification('Unable to save notification settings.', 'error');
        }
    }

    async saveLiveChatSettings() {
        const payload = this.getLiveChatSettingsPayload();
        if (!payload) return;

        try {
            this.updateSettingsStatus('liveChat', 'Saving...', 'info');
            const response = await this.performSensitiveAdminRequest('../backend/api/update_app_settings.php', {
                method: 'POST',
                credentials: 'include',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload)
            }, 'save application settings');
            const data = response.__adminPayloadAttached || await this.safeJson(response, { success: false });

            if (!data.success) {
                this.updateSettingsStatus('liveChat', 'Save failed', 'error');
                this.showNotification(data.message || 'Unable to save live chat settings.', 'error');
                return;
            }

            this.showNotification('Live chat settings saved successfully.', 'success');
            this.updateSettingsStatus('liveChat', 'Saved', 'success');
            if (data.settings) {
                const form = document.getElementById('liveChatSettingsForm');
                if (form) this.applySettingsToForm(form, data.settings);
                this.primeAppSettingsCache(data.settings);
            } else {
                this.invalidateAppSettingsCache();
            }
        } catch (error) {
            console.error('Save live chat settings error:', error);
            this.updateSettingsStatus('liveChat', 'Save failed', 'error');
            this.showNotification('Unable to save live chat settings.', 'error');
        }
    }

    async saveMessageStorageSettings() {
        const payload = this.getMessageStoragePayload();
        if (!payload) return;

        try {
            this.updateSettingsStatus('message', 'Saving...', 'info');
            const response = await this.performSensitiveAdminRequest('../backend/api/update_app_settings.php', {
                method: 'POST',
                credentials: 'include',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload)
            }, 'save security settings');
            const data = response.__adminPayloadAttached || await this.safeJson(response, { success: false });

            if (!data.success) {
                this.updateSettingsStatus('message', 'Save failed', 'error');
                this.showNotification(data.message || 'Unable to save message storage settings.', 'error');
                return;
            }

            this.showNotification('Message storage settings saved successfully.', 'success');
            this.updateSettingsStatus('message', 'Saved', 'success');
            if (data.settings) {
                const form = document.getElementById('messageStorageForm');
                if (form) {
                    this.applySettingsToForm(form, data.settings);
                }
                this.primeAppSettingsCache(data.settings);
            } else {
                this.invalidateAppSettingsCache();
            }
        } catch (error) {
            console.error('Save message storage settings error:', error);
            this.updateSettingsStatus('message', 'Save failed', 'error');
            this.showNotification('Unable to save message storage settings.', 'error');
        }
    }

    async saveAttachmentStorageSettings() {
        const payload = this.getAttachmentStoragePayload();
        if (!payload) return;

        try {
            this.updateSettingsStatus('attachment', 'Saving...', 'info');
            const response = await this.performSensitiveAdminRequest('../backend/api/update_app_settings.php', {
                method: 'POST',
                credentials: 'include',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload)
            }, 'save attachment settings');
            const data = response.__adminPayloadAttached || await this.safeJson(response, { success: false });

            if (!data.success) {
                this.updateSettingsStatus('attachment', 'Save failed', 'error');
                this.showNotification(data.message || 'Unable to save attachment settings.', 'error');
                return;
            }

            this.showNotification('Attachment settings saved successfully.', 'success');
            this.updateSettingsStatus('attachment', 'Saved', 'success');
            if (data.settings) {
                const form = document.getElementById('attachmentStorageForm');
                if (form) {
                    this.applySettingsToForm(form, data.settings);
                }
                this.primeAppSettingsCache(data.settings);
            } else {
                this.invalidateAppSettingsCache();
            }
        } catch (error) {
            console.error('Save attachment settings error:', error);
            this.updateSettingsStatus('attachment', 'Save failed', 'error');
            this.showNotification('Unable to save attachment settings.', 'error');
        }
    }

    updateSettingsStatus(scope, message, type = 'info') {
        const idMap = {
            app: 'appSettingsStatus',
            appVersion: 'appVersionSettingsStatus',
            security: 'securitySettingsStatus',
            permission: 'permissionSettingsStatus',
            role: 'roleSettingsStatus',
            notification: 'notificationSettingsStatus',
            liveChat: 'liveChatSettingsStatus',
            message: 'messageStorageStatus',
            attachment: 'attachmentStorageStatus'
        };
        const id = idMap[scope];
        if (!id) return;
        const el = document.getElementById(id);
        if (!el) return;
        el.textContent = message;
        el.classList.remove('status-info', 'status-success', 'status-error');
        el.classList.add(`status-${type}`);
    }

    async loadUserManagementUsers() {
        const tableBody = document.getElementById('userManagementTableBody');
        if (!tableBody) return;

        tableBody.innerHTML = `
            <tr><td colspan="6"><div class="table-loading">Loading users...</div></td></tr>
        `;

        try {
            const response = await fetch('../backend/api/get_users.php', { credentials: 'include', cache: 'no-store' });
            const data = await this.safeJson(response, { success: false, users: [] });

            if (!data.success) {
                tableBody.innerHTML = `
                    <tr><td colspan="6"><div class="table-empty">Unable to load users.</div></td></tr>
                `;
                return;
            }

            this.userManagementUsers = data.users || [];
            this.updateRoleCaches(data.role_labels || {}, data.roles || []);
            this.populateUserRoleFilterOptions();
            this.applyUserManagementFilters();
        } catch (error) {
            console.error('Error loading users:', error);
            tableBody.innerHTML = `
                <tr><td colspan="6"><div class="table-empty">Failed to load users.</div></td></tr>
            `;
        }
    }

    applyUserManagementFilters() {
        const searchValue = (document.getElementById('userManagementSearch')?.value || '').toLowerCase();
        const roleValue = document.getElementById('userRoleFilter')?.value || '';
        const accountTypeValue = document.getElementById('userAccountTypeFilter')?.value || '';

        this.selectedUserIds.clear();

        this.filteredUserManagementUsers = this.userManagementUsers.filter(user => {
            const matchesRole = roleValue ? user.userRole === roleValue : true;
            const normalizedRole = String(user.userRole || '').toLowerCase();
            const matchesAccountType = accountTypeValue
                ? (accountTypeValue === 'staff' ? normalizedRole !== 'pensioner' : normalizedRole === 'pensioner')
                : true;
            const composite = `${user.userName || ''} ${user.userEmail || ''} ${user.phoneNo || ''} ${user.userRole || ''}`.toLowerCase();
            const matchesSearch = searchValue ? composite.includes(searchValue) : true;
            return matchesRole && matchesAccountType && matchesSearch;
        });

        this.renderUserManagementTable();
        this.updateUserSummaryCards();
        this.updateRoleFilterCounts();
        this.updateBulkActionsBar();
    }

    updateUserSummaryCards() {
        const total = this.userManagementUsers.length;
        const admins = this.userManagementUsers.filter(user => ['admin', 'super_admin'].includes(user.userRole)).length;
        const pensioners = this.userManagementUsers.filter(user => user.userRole === 'pensioner').length;
        const staff = total - admins - pensioners;

        const setValue = (id, value) => {
            const el = document.getElementById(id);
            if (el) el.textContent = value;
        };

        setValue('userSummaryTotal', total);
        setValue('userSummaryAdmins', admins);
        setValue('userSummaryStaff', staff < 0 ? 0 : staff);
        setValue('userSummaryPensioners', pensioners);
    }

    renderUserManagementTable() {
        const tableBody = document.getElementById('userManagementTableBody');
        if (!tableBody) return;

        if (this.filteredUserManagementUsers.length === 0) {
            tableBody.innerHTML = `
                <tr><td colspan="6"><div class="table-empty">No users match the current filters.</div></td></tr>
            `;
            return;
        }

        tableBody.innerHTML = this.filteredUserManagementUsers.map(user => {
            const photo = this.resolveUserPhoto(user.userPhoto);
            const roleLabel = this.formatRoleLabel(user.userRole);
            const isChecked = this.selectedUserIds.has(user.userId) ? 'checked' : '';
            const canManage = this.canManageUserAccount(user);
            const canDelete = this.canDeleteUserAccount(user);
            const canToggleStatus = this.canToggleUserAccountStatus(user);
            const isActive = user.is_active !== false;
            const statusLabel = isActive ? 'Active' : 'Inactive';
            const statusClass = isActive ? 'active' : 'inactive';
            return `
                <tr>
                    <td class="table-checkbox">
                        <input type="checkbox" class="user-select-checkbox" data-user-id="${user.userId}" ${isChecked} ${canDelete ? '' : 'disabled'}>
                    </td>
                    <td>
                        <div class="user-cell">
                            <img class="user-avatar" src="${photo.primary}" data-fallbacks="${photo.fallbacks.join('|')}" alt="${this.escapeHtml(user.userName || 'User')}">
                            <div class="user-meta">
                                <div class="user-display-name">${this.escapeHtml(user.userTitle ? `${user.userTitle} - ${user.userName}` : user.userName || 'Unknown')}</div>
                                <div class="user-email">${this.escapeHtml(user.userEmail || 'No email')}</div>
                            </div>
                        </div>
                    </td>
                    <td>
                        <div class="user-contact">
                            <div>${this.escapeHtml(user.phoneNo || 'N/A')}</div>
                        </div>
                    </td>
                    <td>
                        <span class="role-badge role-${this.escapeHtml(user.userRole || 'user')}">${this.escapeHtml(roleLabel)}</span>
                    </td>
                    <td><span class="status-pill ${statusClass}">${statusLabel}</span></td>
                    <td>
                        <div class="user-actions">
                            <button class="user-action-btn" data-action="edit" data-user-id="${user.userId}" ${canManage ? '' : 'disabled'}>Edit</button>
                            <button class="user-action-btn" data-action="reset" data-user-id="${user.userId}" ${canManage ? '' : 'disabled'}>Reset Password</button>
                            <button class="user-action-btn ${isActive ? 'warning' : ''}" data-action="toggle-status" data-user-id="${user.userId}" ${canToggleStatus ? '' : 'disabled'}>
                                ${isActive ? 'Deactivate' : 'Activate'}
                            </button>
                            <button class="user-action-btn danger" data-action="delete" data-user-id="${user.userId}" ${canDelete ? '' : 'disabled'}>Delete</button>
                        </div>
                    </td>
                </tr>
            `;
        }).join('');

        this.bindUserActionHandlers();
        this.bindUserAvatarFallbacks();
        this.bindUserSelectionHandlers();
        this.updateBulkActionsBar();
    }

    bindUserActionHandlers() {
        document.querySelectorAll('.user-action-btn').forEach(button => {
            button.addEventListener('click', () => {
                const action = button.getAttribute('data-action');
                const userId = button.getAttribute('data-user-id');
                const user = this.userManagementUsers.find(u => u.userId === userId);
                if (!user) return;

                if (action === 'edit') {
                    this.openUserModal('edit', user);
                } else if (action === 'reset') {
                    this.openResetPasswordModal(user);
                } else if (action === 'toggle-status') {
                    this.confirmToggleUserStatus(user);
                } else if (action === 'delete') {
                    this.confirmUserDelete(user);
                }
            });
        });
    }

    bindUserSelectionHandlers() {
        const selectAll = document.getElementById('selectAllUsers');
        const checkboxes = document.querySelectorAll('.user-select-checkbox');

        if (selectAll) {
            const visibleIds = this.filteredUserManagementUsers
                .filter(user => this.canDeleteUserAccount(user))
                .map(user => user.userId);
            const allSelected = visibleIds.length > 0 && visibleIds.every(id => this.selectedUserIds.has(id));
            selectAll.checked = allSelected;
            selectAll.indeterminate = !allSelected && visibleIds.some(id => this.selectedUserIds.has(id));

            selectAll.addEventListener('change', () => {
                if (selectAll.checked) {
                    visibleIds.forEach(id => this.selectedUserIds.add(id));
                } else {
                    visibleIds.forEach(id => this.selectedUserIds.delete(id));
                }
                this.renderUserManagementTable();
            }, { once: true });
        }

        checkboxes.forEach(checkbox => {
            checkbox.addEventListener('change', () => {
                const userId = checkbox.getAttribute('data-user-id');
                if (checkbox.checked) {
                    this.selectedUserIds.add(userId);
                } else {
                    this.selectedUserIds.delete(userId);
                }
                this.updateBulkActionsBar();
            });
        });
    }

    updateBulkActionsBar() {
        const bar = document.getElementById('bulkActionsBar');
        const countEl = document.getElementById('bulkSelectedCount');
        const summaryEl = document.getElementById('bulkSelectedSummary');
        const selectAllFilteredBtn = document.getElementById('selectAllFilteredBtn');
        if (!bar || !countEl) return;

        const count = this.selectedUserIds.size;
        countEl.textContent = count;
        bar.classList.toggle('active', count > 0);

        const filteredCount = this.filteredUserManagementUsers.length;
        if (summaryEl) {
            summaryEl.textContent = `${count} of ${filteredCount} filtered`;
        }

        if (selectAllFilteredBtn) {
            const show = filteredCount > 0 && count < filteredCount;
            selectAllFilteredBtn.style.display = show ? 'inline-flex' : 'none';
        }
    }

    updateRoleFilterCounts() {
        const roleFilter = document.getElementById('userRoleFilter');
        if (!roleFilter) return;

        const counts = this.userManagementUsers.reduce((acc, user) => {
            const role = user.userRole || 'user';
            acc[role] = (acc[role] || 0) + 1;
            return acc;
        }, {});

        Array.from(roleFilter.options).forEach(option => {
            const role = option.value;
            if (!role) {
                option.textContent = 'All Roles';
                return;
            }
            const label = this.formatRoleLabel(role);
            const count = counts[role] || 0;
            option.textContent = `${label} (${count})`;
        });
    }

    async loadTitleSettings() {
        const tableBody = document.getElementById('titleSettingsTableBody');
        if (!tableBody) return;

        tableBody.innerHTML = `
            <tr><td colspan="5"><div class="table-loading">Loading titles...</div></td></tr>
        `;

        try {
            const response = await fetch('../backend/api/get_titles.php', { credentials: 'include', cache: 'no-store' });
            const data = await this.safeJson(response, { success: false, titles: [] });

            if (!data.success) {
                tableBody.innerHTML = `
                    <tr><td colspan="5"><div class="table-empty">Unable to load titles.</div></td></tr>
                `;
                return;
            }

            this.titleSettings.titles = data.titles || [];
            this.applyTitleFilters();
        } catch (error) {
            console.error('Error loading titles:', error);
            tableBody.innerHTML = `
                <tr><td colspan="5"><div class="table-empty">Failed to load titles.</div></td></tr>
            `;
        }
    }

    applyTitleFilters() {
        const searchValue = (document.getElementById('titleSearchInput')?.value || '').toLowerCase();
        const categoryValue = document.getElementById('titleCategoryFilter')?.value || '';
        const levelValue = document.getElementById('titleLevelFilter')?.value || '';
        const statusValue = document.getElementById('titleStatusFilter')?.value || '';

        this.titleSettings.filtered = this.titleSettings.titles.filter(title => {
            const matchesSearch = searchValue
                ? (title.title_name || '').toLowerCase().includes(searchValue)
                : true;
            const matchesCategory = categoryValue ? title.category === categoryValue : true;
            const matchesLevel = levelValue ? title.level === levelValue : true;
            const matchesStatus = statusValue
                ? (statusValue === 'active' ? title.is_active : !title.is_active)
                : true;
            return matchesSearch && matchesCategory && matchesLevel && matchesStatus;
        });

        this.renderTitleTable();
        this.updateTitleSummaryCards();
    }

    updateTitleSummaryCards() {
        const total = this.titleSettings.titles.length;
        const uniformed = this.titleSettings.titles.filter(title => title.category === 'uniformed').length;
        const nonUniformed = this.titleSettings.titles.filter(title => title.category === 'non_uniformed').length;
        const active = this.titleSettings.titles.filter(title => title.is_active).length;

        const setValue = (id, value) => {
            const el = document.getElementById(id);
            if (el) el.textContent = value;
        };

        setValue('titleSummaryTotal', total);
        setValue('titleSummaryUniformed', uniformed);
        setValue('titleSummaryNonUniformed', nonUniformed);
        setValue('titleSummaryActive', active);
    }

    renderTitleTable() {
        const tableBody = document.getElementById('titleSettingsTableBody');
        if (!tableBody) return;

        if (this.titleSettings.filtered.length === 0) {
            tableBody.innerHTML = `
                <tr><td colspan="5"><div class="table-empty">No titles match the current filters.</div></td></tr>
            `;
            return;
        }

        tableBody.innerHTML = this.titleSettings.filtered.map(title => {
            const categoryLabel = this.formatTitleCategory(title.category);
            const levelLabel = this.formatTitleLevel(title.level);
            const statusLabel = title.is_active ? 'Active' : 'Inactive';
            const statusClass = title.is_active ? 'active' : 'inactive';
            return `
                <tr>
                    <td>${this.escapeHtml(title.title_name || 'Untitled')}</td>
                    <td><span class="meta-pill ${this.escapeHtml(title.category)}">${this.escapeHtml(categoryLabel)}</span></td>
                    <td><span class="meta-pill ${this.escapeHtml(title.level)}">${this.escapeHtml(levelLabel)}</span></td>
                    <td><span class="status-pill ${statusClass}">${statusLabel}</span></td>
                    <td>
                        <div class="user-actions">
                            <button class="user-action-btn" data-action="edit" data-id="${title.title_id}">Edit</button>
                            <button class="user-action-btn" data-action="toggle" data-id="${title.title_id}">
                                ${title.is_active ? 'Disable' : 'Enable'}
                            </button>
                            <button class="user-action-btn danger" data-action="delete" data-id="${title.title_id}">Delete</button>
                        </div>
                    </td>
                </tr>
            `;
        }).join('');
    }

    async loadBankSettings() {
        const tableBody = document.getElementById('bankSettingsTableBody');
        if (!tableBody) return;

        tableBody.innerHTML = `
            <tr><td colspan="6"><div class="table-loading">Loading banks...</div></td></tr>
        `;

        try {
            const response = await fetch('../backend/api/get_banks.php', { credentials: 'include', cache: 'no-store' });
            const data = await this.safeJson(response, { success: false, banks: [] });

            if (!data.success) {
                tableBody.innerHTML = `
                    <tr><td colspan="6"><div class="table-empty">Unable to load banks.</div></td></tr>
                `;
                return;
            }

            this.bankSettings.banks = data.banks || [];
            this.applyBankFilters();
        } catch (error) {
            console.error('Error loading banks:', error);
            tableBody.innerHTML = `
                <tr><td colspan="6"><div class="table-empty">Failed to load banks.</div></td></tr>
            `;
        }
    }

    applyBankFilters() {
        const searchValue = (document.getElementById('bankSearchInput')?.value || '').toLowerCase();
        const statusValue = document.getElementById('bankStatusFilter')?.value || '';

        this.bankSettings.filtered = this.bankSettings.banks.filter((bank) => {
            const matchesSearch = searchValue
                ? [bank.bank_name, bank.short_name, bank.bank_code]
                    .map((value) => String(value || '').toLowerCase())
                    .some((value) => value.includes(searchValue))
                : true;
            const matchesStatus = statusValue
                ? (statusValue === 'active' ? bank.is_active : !bank.is_active)
                : true;
            return matchesSearch && matchesStatus;
        });

        this.renderBankTable();
        this.updateBankSummaryCards();
    }

    updateBankSummaryCards() {
        const total = this.bankSettings.banks.length;
        const active = this.bankSettings.banks.filter((bank) => bank.is_active).length;
        const inactive = this.bankSettings.banks.filter((bank) => !bank.is_active).length;
        const withCodes = this.bankSettings.banks.filter((bank) => String(bank.bank_code || '').trim() !== '').length;

        const setValue = (id, value) => {
            const el = document.getElementById(id);
            if (el) el.textContent = value;
        };

        setValue('bankSummaryTotal', total);
        setValue('bankSummaryActive', active);
        setValue('bankSummaryInactive', inactive);
        setValue('bankSummaryCodes', withCodes);
    }

    renderBankTable() {
        const tableBody = document.getElementById('bankSettingsTableBody');
        if (!tableBody) return;

        if (this.bankSettings.filtered.length === 0) {
            tableBody.innerHTML = `
                <tr><td colspan="6"><div class="table-empty">No banks match the current filters.</div></td></tr>
            `;
            return;
        }

        tableBody.innerHTML = this.bankSettings.filtered.map((bank) => {
            const statusLabel = bank.is_active ? 'Active' : 'Inactive';
            const statusClass = bank.is_active ? 'active' : 'inactive';
            return `
                <tr>
                    <td>${this.escapeHtml(bank.bank_name || 'Unnamed bank')}</td>
                    <td>${this.escapeHtml(bank.short_name || '--')}</td>
                    <td>${this.escapeHtml(bank.bank_code || '--')}</td>
                    <td>${this.escapeHtml(String(bank.display_order ?? 0))}</td>
                    <td><span class="status-pill ${statusClass}">${statusLabel}</span></td>
                    <td>
                        <div class="user-actions">
                            <button class="user-action-btn" data-action="edit" data-id="${bank.bank_id}">Edit</button>
                            <button class="user-action-btn" data-action="toggle" data-id="${bank.bank_id}">
                                ${bank.is_active ? 'Disable' : 'Enable'}
                            </button>
                            <button class="user-action-btn danger" data-action="delete" data-id="${bank.bank_id}">Delete</button>
                        </div>
                    </td>
                </tr>
            `;
        }).join('');
    }

    async loadFaqSettings() {
        const tableBody = document.getElementById('faqSettingsTableBody');
        if (!tableBody) return;
        tableBody.innerHTML = `
            <tr><td colspan="7"><div class="table-loading">Loading FAQs...</div></td></tr>
        `;

        try {
            const response = await fetch('../backend/api/get_faq_entries.php?active_only=0', {
                credentials: 'include',
                cache: 'no-store',
                headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
            });
            const data = await response.json().catch(() => ({ success: false }));
            if (!response.ok || !data.success) {
                throw new Error(data.message || 'Unable to load FAQ entries.');
            }

            this.faqSettings.entries = data.entries || [];
            this.applyFaqFilters();
        } catch (error) {
            console.error('Error loading FAQs:', error);
            tableBody.innerHTML = `
                <tr><td colspan="7"><div class="table-empty">Failed to load FAQ entries.</div></td></tr>
            `;
        }
    }

    applyFaqFilters() {
        const searchValue = (document.getElementById('faqSearchInput')?.value || '').toLowerCase();
        const categoryValue = document.getElementById('faqCategoryFilter')?.value || '';
        const statusValue = document.getElementById('faqStatusFilter')?.value || '';
        const featuredValue = document.getElementById('faqFeaturedFilter')?.value || '';

        this.faqSettings.filtered = this.faqSettings.entries.filter(entry => {
            const question = (entry.question || '').toLowerCase();
            const answer = (entry.answer || '').toLowerCase();
            const audience = (entry.audience_label || '').toLowerCase();
            const matchesSearch = searchValue
                ? question.includes(searchValue) || answer.includes(searchValue) || audience.includes(searchValue)
                : true;
            const matchesCategory = categoryValue ? entry.category === categoryValue : true;
            const matchesStatus = statusValue
                ? (statusValue === 'active' ? entry.is_active : !entry.is_active)
                : true;
            const matchesFeatured = featuredValue
                ? (featuredValue === 'featured' ? entry.is_featured : !entry.is_featured)
                : true;
            return matchesSearch && matchesCategory && matchesStatus && matchesFeatured;
        });

        this.renderFaqTable();
        this.updateFaqSummaryCards();
    }

    updateFaqSummaryCards() {
        const total = this.faqSettings.entries.length;
        const active = this.faqSettings.entries.filter(entry => entry.is_active).length;
        const featured = this.faqSettings.entries.filter(entry => entry.is_featured).length;
        const categories = new Set(this.faqSettings.entries.map(entry => entry.category).filter(Boolean)).size;

        const setValue = (id, value) => {
            const el = document.getElementById(id);
            if (el) el.textContent = value;
        };

        setValue('faqSummaryTotal', total);
        setValue('faqSummaryActive', active);
        setValue('faqSummaryFeatured', featured);
        setValue('faqSummaryCategories', categories);
    }

    renderFaqTable() {
        const tableBody = document.getElementById('faqSettingsTableBody');
        if (!tableBody) return;

        if (this.faqSettings.filtered.length === 0) {
            tableBody.innerHTML = `
                <tr><td colspan="7"><div class="table-empty">No FAQ entries match the current filters.</div></td></tr>
            `;
            return;
        }

        const categoryLabels = {
            applications: 'Applications',
            benefits: 'Benefits',
            registry: 'Registry & Tracking',
            claims: 'Claims & Payroll',
            pensioners: 'Pensioner Access',
            security: 'Security & Access'
        };

        tableBody.innerHTML = this.faqSettings.filtered.map(entry => {
            const statusLabel = entry.is_active ? 'Active' : 'Inactive';
            const statusClass = entry.is_active ? 'active' : 'inactive';
            const featuredLabel = entry.is_featured ? 'Featured' : 'Standard';
            const featuredClass = entry.is_featured ? 'active' : 'inactive';
            const updated = entry.updated_at ? new Date(entry.updated_at.replace(' ', 'T')) : null;
            const updatedLabel = updated && !Number.isNaN(updated.getTime())
                ? updated.toLocaleDateString()
                : '--';
            return `
                <tr>
                    <td>${this.escapeHtml(entry.question || 'Untitled')}</td>
                    <td><span class="meta-pill faq-category">${this.escapeHtml(categoryLabels[entry.category] || entry.category || 'General')}</span></td>
                    <td>${this.escapeHtml(entry.audience_label || 'Public guidance')}</td>
                    <td><span class="status-pill ${statusClass}">${statusLabel}</span></td>
                    <td><span class="status-pill ${featuredClass}">${featuredLabel}</span></td>
                    <td>${this.escapeHtml(updatedLabel)}</td>
                    <td>
                        <div class="user-actions">
                            <button class="user-action-btn" data-action="edit" data-id="${entry.faq_id}">Edit</button>
                            <button class="user-action-btn" data-action="toggle" data-id="${entry.faq_id}">
                                ${entry.is_active ? 'Disable' : 'Enable'}
                            </button>
                            <button class="user-action-btn" data-action="feature" data-id="${entry.faq_id}">
                                ${entry.is_featured ? 'Unfeature' : 'Feature'}
                            </button>
                            <button class="user-action-btn danger" data-action="delete" data-id="${entry.faq_id}">Delete</button>
                        </div>
                    </td>
                </tr>
            `;
        }).join('');
    }

    openFaqModal(mode, entry = {}) {
        const existing = document.querySelector('.admin-modal-overlay');
        if (existing) existing.remove();

        const isEdit = mode === 'edit';
        const defaultAudienceLabels = [
            'Pensioners, staff, and supervisors',
            'Public guidance',
            'Public, pensioners, and staff',
            'Pensioners and staff',
            'Operational staff and supervisors',
            'Supervisors and administrators',
            'Pensioners',
            'Operational staff'
        ];
        const labelSet = new Set(defaultAudienceLabels);
        if (this.faqSettings && Array.isArray(this.faqSettings.entries)) {
            this.faqSettings.entries.forEach((item) => {
                const label = String(item?.audience_label || '').trim();
                if (label) {
                    labelSet.add(label);
                }
            });
        }
        let currentAudience = String(entry.audience_label || '').trim();
        if (!currentAudience) {
            currentAudience = defaultAudienceLabels[0];
        }
        if (currentAudience) {
            labelSet.add(currentAudience);
        }
        const extraLabels = Array.from(labelSet).filter(label => !defaultAudienceLabels.includes(label)).sort();
        const audienceOptions = [...defaultAudienceLabels, ...extraLabels].filter(Boolean);
        const modal = document.createElement('div');
        modal.className = 'admin-modal-overlay';
        modal.innerHTML = `
            <div class="admin-modal">
                <div class="admin-modal-header">
                    <h3>${isEdit ? 'Edit FAQ Entry' : 'Add FAQ Entry'}</h3>
                    <button class="admin-modal-close" aria-label="Close">&times;</button>
                </div>
                <form class="admin-modal-body" id="faqEntryForm">
                    <div class="form-grid">
                        <div class="form-field form-span">
                            <label>Question</label>
                            <input type="text" name="question" value="${this.escapeHtml(entry.question || '')}" required placeholder="e.g., How does the pension workflow move?">
                        </div>
                        <div class="form-field form-span">
                            <label>Answer</label>
                            <textarea name="answer" rows="5" required placeholder="Describe the answer clearly.">${this.escapeHtml(entry.answer || '')}</textarea>
                        </div>
                        <div class="form-field form-span">
                            <label>Key Bullets (one per line)</label>
                            <textarea name="bullets" rows="4" placeholder="Optional bullets">${this.escapeHtml(Array.isArray(entry.bullets) ? entry.bullets.join('\\n') : '')}</textarea>
                        </div>
                        <div class="form-field">
                            <label>Topic</label>
                            <select name="category" required>
                                <option value="applications" ${entry.category === 'applications' ? 'selected' : ''}>Applications</option>
                                <option value="benefits" ${entry.category === 'benefits' ? 'selected' : ''}>Benefits</option>
                                <option value="registry" ${entry.category === 'registry' ? 'selected' : ''}>Registry & Tracking</option>
                                <option value="claims" ${entry.category === 'claims' ? 'selected' : ''}>Claims & Payroll</option>
                                <option value="pensioners" ${entry.category === 'pensioners' ? 'selected' : ''}>Pensioner Access</option>
                                <option value="security" ${entry.category === 'security' ? 'selected' : ''}>Security & Access</option>
                            </select>
                        </div>
                        <div class="form-field">
                            <label>Sort Order</label>
                            <input type="number" name="sort_order" min="0" step="1" value="${entry.sort_order ?? 0}">
                          </div>
                          <div class="form-field form-span">
                              <label>Audience Label</label>
                              <select name="audience_label">
                                  <option value="">Select audience</option>
                                  ${audienceOptions.map(label => `
                                      <option value="${this.escapeHtml(label)}" ${label === currentAudience ? 'selected' : ''}>${this.escapeHtml(label)}</option>
                                  `).join('')}
                              </select>
                          </div>
                        <div class="form-field">
                            <label class="form-checkbox">
                                <input type="checkbox" name="is_featured" ${entry.is_featured ? 'checked' : ''}>
                                Feature this FAQ
                            </label>
                        </div>
                        <div class="form-field">
                            <label class="form-checkbox">
                                <input type="checkbox" name="is_active" ${entry.is_active !== false ? 'checked' : ''}>
                                Active
                            </label>
                        </div>
                    </div>
                    <div class="admin-modal-footer">
                        <button type="button" class="action-btn secondary" data-action="cancel">Cancel</button>
                        <button type="submit" class="action-btn">${isEdit ? 'Save Changes' : 'Add FAQ'}</button>
                    </div>
                </form>
            </div>
        `;
        document.body.appendChild(modal);

        const closeModal = () => modal.remove();
        modal.querySelector('.admin-modal-close')?.addEventListener('click', closeModal);
        modal.querySelector('[data-action=\"cancel\"]')?.addEventListener('click', closeModal);
        modal.addEventListener('click', (event) => {
            if (event.target === modal) closeModal();
        });

        modal.querySelector('#faqEntryForm')?.addEventListener('submit', async (event) => {
            event.preventDefault();
            const form = event.target;
            const payload = {
                faq_id: entry.faq_id,
                question: form.question.value.trim(),
                answer: form.answer.value.trim(),
                bullets: form.bullets.value.split(/\\r?\\n/).map(line => line.trim()).filter(Boolean),
                category: form.category.value,
                sort_order: parseInt(form.sort_order.value || '0', 10) || 0,
                audience_label: form.audience_label.value.trim(),
                is_featured: form.is_featured.checked,
                is_active: form.is_active.checked
            };

            const endpoint = isEdit ? '../backend/api/update_faq_entry.php' : '../backend/api/add_faq_entry.php';
            try {
                const response = await this.performSensitiveAdminRequest(endpoint, {
                    method: 'POST',
                    credentials: 'include',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(payload)
                }, isEdit ? 'update the FAQ entry' : 'add the FAQ entry');
                const data = response.__adminPayloadAttached || await response.json();
                if (!response.ok || !data.success) {
                    throw new Error(data.message || 'Unable to save FAQ entry.');
                }
                this.showNotification(data.message || 'FAQ entry saved.', 'success');
                closeModal();
                await this.loadFaqSettings();
            } catch (error) {
                this.showNotification(error.message || 'Unable to save FAQ entry.', 'error');
            }
        });
    }

    async toggleFaqActive(entry) {
        try {
            const response = await this.performSensitiveAdminRequest('../backend/api/update_faq_entry.php', {
                method: 'POST',
                credentials: 'include',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    faq_id: entry.faq_id,
                    question: entry.question,
                    answer: entry.answer,
                    bullets: entry.bullets || [],
                    category: entry.category,
                    audience_label: entry.audience_label,
                    is_featured: entry.is_featured,
                    is_active: !entry.is_active,
                    sort_order: entry.sort_order || 0
                })
            }, entry.is_active ? 'disable the FAQ entry' : 'enable the FAQ entry');
            const data = response.__adminPayloadAttached || await response.json();
            if (!response.ok || !data.success) {
                throw new Error(data.message || 'Unable to update FAQ entry.');
            }
            this.showNotification(data.message || 'FAQ entry updated.', 'success');
            await this.loadFaqSettings();
        } catch (error) {
            this.showNotification(error.message || 'Unable to update FAQ entry.', 'error');
        }
    }

    async toggleFaqFeatured(entry) {
        try {
            const response = await this.performSensitiveAdminRequest('../backend/api/update_faq_entry.php', {
                method: 'POST',
                credentials: 'include',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    faq_id: entry.faq_id,
                    question: entry.question,
                    answer: entry.answer,
                    bullets: entry.bullets || [],
                    category: entry.category,
                    audience_label: entry.audience_label,
                    is_featured: !entry.is_featured,
                    is_active: entry.is_active,
                    sort_order: entry.sort_order || 0
                })
            }, entry.is_featured ? 'remove FAQ featured status' : 'feature the FAQ entry');
            const data = response.__adminPayloadAttached || await response.json();
            if (!response.ok || !data.success) {
                throw new Error(data.message || 'Unable to update FAQ entry.');
            }
            this.showNotification(data.message || 'FAQ entry updated.', 'success');
            await this.loadFaqSettings();
        } catch (error) {
            this.showNotification(error.message || 'Unable to update FAQ entry.', 'error');
        }
    }

    confirmFaqDelete(entry) {
        const existing = document.querySelector('.admin-confirm-overlay');
        if (existing) existing.remove();

        const overlay = document.createElement('div');
        overlay.className = 'admin-confirm-overlay';
        overlay.innerHTML = `
            <div class="admin-confirm-modal">
                <h3>Delete FAQ Entry</h3>
                <p>Delete the FAQ entry titled <strong>${this.escapeHtml(entry.question)}</strong>?</p>
                <div class="admin-modal-footer">
                    <button class="action-btn secondary" data-action="cancel">Cancel</button>
                    <button class="action-btn danger" data-action="confirm">Delete</button>
                </div>
            </div>
        `;
        document.body.appendChild(overlay);

        const closeModal = () => overlay.remove();
        overlay.querySelector('[data-action=\"cancel\"]')?.addEventListener('click', closeModal);
        overlay.addEventListener('click', (event) => {
            if (event.target === overlay) closeModal();
        });

        overlay.querySelector('[data-action=\"confirm\"]')?.addEventListener('click', async () => {
            try {
                const response = await this.performSensitiveAdminRequest('../backend/api/delete_faq_entry.php', {
                    method: 'POST',
                    credentials: 'include',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ faq_id: entry.faq_id })
                }, 'delete the FAQ entry');
                const data = response.__adminPayloadAttached || await response.json();
                if (!response.ok || !data.success) {
                    throw new Error(data.message || 'Unable to delete FAQ entry.');
                }
                this.showNotification(data.message || 'FAQ entry deleted.', 'success');
                closeModal();
                await this.loadFaqSettings();
            } catch (error) {
                this.showNotification(error.message || 'Unable to delete FAQ entry.', 'error');
            }
        });
    }

    async loadTermsSettings() {
        const tableBody = document.getElementById('termsSettingsTableBody');
        if (!tableBody) return;
        tableBody.innerHTML = `
            <tr><td colspan=\"5\"><div class=\"table-loading\">Loading clauses...</div></td></tr>
        `;

        try {
            const response = await fetch('../backend/api/get_terms_clauses.php?active_only=0', {
                credentials: 'include',
                cache: 'no-store',
                headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
            });
            const data = await response.json().catch(() => ({ success: false }));
            if (!response.ok || !data.success) {
                throw new Error(data.message || 'Unable to load clauses.');
            }

            this.termsSettings.clauses = data.clauses || [];
            this.applyTermsFilters();
        } catch (error) {
            console.error('Error loading terms clauses:', error);
            tableBody.innerHTML = `
                <tr><td colspan=\"5\"><div class=\"table-empty\">Failed to load clauses.</div></td></tr>
            `;
        }
    }

    applyTermsFilters() {
        const searchValue = (document.getElementById('termsSearchInput')?.value || '').toLowerCase();
        const topicValue = document.getElementById('termsTopicFilter')?.value || '';
        const statusValue = document.getElementById('termsStatusFilter')?.value || '';

        this.termsSettings.filtered = this.termsSettings.clauses.filter(clause => {
            const title = (clause.title || '').toLowerCase();
            const body = (clause.body || '').toLowerCase();
            const topics = (clause.topics || '').toLowerCase();
            const matchesSearch = searchValue ? (title.includes(searchValue) || body.includes(searchValue) || topics.includes(searchValue)) : true;
            const matchesTopic = topicValue ? topics.includes(topicValue) : true;
            const matchesStatus = statusValue
                ? (statusValue === 'active' ? clause.is_active : !clause.is_active)
                : true;
            return matchesSearch && matchesTopic && matchesStatus;
        });

        this.renderTermsTable();
        this.updateTermsSummaryCards();
    }

    updateTermsSummaryCards() {
        const total = this.termsSettings.clauses.length;
        const active = this.termsSettings.clauses.filter(clause => clause.is_active).length;
        const topicGroups = new Set(this.termsSettings.clauses.map(clause => clause.topics).filter(Boolean)).size;
        let latestDate = null;
        this.termsSettings.clauses.forEach((clause) => {
            if (!clause.updated_at) return;
            const date = new Date(String(clause.updated_at).replace(' ', 'T'));
            if (Number.isNaN(date.getTime())) return;
            if (!latestDate || date > latestDate) {
                latestDate = date;
            }
        });
        const latest = latestDate ? latestDate.toLocaleDateString() : '--';

        const setValue = (id, value) => {
            const el = document.getElementById(id);
            if (el) el.textContent = value;
        };

        setValue('termsSummaryTotal', total);
        setValue('termsSummaryActive', active);
        setValue('termsSummaryUpdated', latest);
        setValue('termsSummaryTopics', topicGroups);
    }

    renderTermsTable() {
        const tableBody = document.getElementById('termsSettingsTableBody');
        if (!tableBody) return;

        if (this.termsSettings.filtered.length === 0) {
            tableBody.innerHTML = `
                <tr><td colspan=\"5\"><div class=\"table-empty\">No clauses match the current filters.</div></td></tr>
            `;
            return;
        }

        tableBody.innerHTML = this.termsSettings.filtered.map(clause => {
            const statusLabel = clause.is_active ? 'Active' : 'Inactive';
            const statusClass = clause.is_active ? 'active' : 'inactive';
            const updated = clause.updated_at ? new Date(clause.updated_at.replace(' ', 'T')) : null;
            const updatedLabel = updated && !Number.isNaN(updated.getTime())
                ? updated.toLocaleDateString()
                : '--';
            return `
                <tr>
                    <td>${this.escapeHtml(clause.title || 'Untitled')}</td>
                    <td>${this.escapeHtml(clause.topics || 'General')}</td>
                    <td><span class=\"status-pill ${statusClass}\">${statusLabel}</span></td>
                    <td>${this.escapeHtml(updatedLabel)}</td>
                    <td>
                        <div class=\"user-actions\">
                            <button class=\"user-action-btn\" data-action=\"edit\" data-id=\"${clause.clause_id}\">Edit</button>
                            <button class=\"user-action-btn\" data-action=\"toggle\" data-id=\"${clause.clause_id}\">
                                ${clause.is_active ? 'Disable' : 'Enable'}
                            </button>
                            <button class=\"user-action-btn danger\" data-action=\"delete\" data-id=\"${clause.clause_id}\">Delete</button>
                        </div>
                    </td>
                </tr>
            `;
        }).join('');
    }

    openTermsModal(mode, clause = {}) {
        const existing = document.querySelector('.admin-modal-overlay');
        if (existing) existing.remove();

        const isEdit = mode === 'edit';
        const topicSet = new Set();
        (this.termsSettings?.clauses || []).forEach((item) => {
            const rawTopics = String(item.topics || '').split(',').map((topic) => topic.trim()).filter(Boolean);
            rawTopics.forEach((topic) => topicSet.add(topic));
        });
        if (!topicSet.size) {
            topicSet.add('operations');
        }
        const selectedTopic = String(clause.topics || '')
            .split(',')
            .map((topic) => topic.trim())
            .filter(Boolean)[0] || 'operations';
        topicSet.add(selectedTopic);
        const topicOptions = Array.from(topicSet).sort((a, b) => a.localeCompare(b)).map((topic) => (
            `<option value="${this.escapeHtml(topic)}"${topic === selectedTopic ? ' selected' : ''}>${this.escapeHtml(topic)}</option>`
        )).join('');

        const modal = document.createElement('div');
        modal.className = 'admin-modal-overlay';
        modal.innerHTML = `
            <div class=\"admin-modal\">
                <div class=\"admin-modal-header\">
                    <h3>${isEdit ? 'Edit Clause' : 'Add Clause'}</h3>
                    <button class=\"admin-modal-close\" aria-label=\"Close\">&times;</button>
                </div>
                <form class=\"admin-modal-body\" id=\"termsClauseForm\">
                    <div class=\"form-grid\">
                        <div class=\"form-field form-span\">
                            <label>Clause Title</label>
                            <input type=\"text\" name=\"title\" value=\"${this.escapeHtml(clause.title || '')}\" required placeholder=\"e.g., Platform scope and purpose\">
                        </div>
                        <div class=\"form-field form-span\">
                            <label>Clause Body</label>
                            <textarea name=\"body\" rows=\"6\" required placeholder=\"Enter the clause text.\">${this.escapeHtml(clause.body || '')}</textarea>
                        </div>
                        <div class=\"form-field\">
                            <label>Topic</label>
                            <select name=\"topics\" required>
                                ${topicOptions}
                            </select>
                        </div>
                        <div class=\"form-field\">
                            <label>Sort Order</label>
                            <input type=\"number\" name=\"sort_order\" min=\"0\" step=\"1\" value=\"${clause.sort_order ?? 0}\">
                        </div>
                        <div class=\"form-field\">
                            <label class=\"form-checkbox\">
                                <input type=\"checkbox\" name=\"is_active\" ${clause.is_active !== false ? 'checked' : ''}>
                                Active
                            </label>
                        </div>
                    </div>
                    <div class=\"admin-modal-footer\">
                        <button type=\"button\" class=\"action-btn secondary\" data-action=\"cancel\">Cancel</button>
                        <button type=\"submit\" class=\"action-btn\">${isEdit ? 'Save Changes' : 'Add Clause'}</button>
                    </div>
                </form>
            </div>
        `;
        document.body.appendChild(modal);

        const closeModal = () => modal.remove();
        modal.querySelector('.admin-modal-close')?.addEventListener('click', closeModal);
        modal.querySelector('[data-action=\"cancel\"]')?.addEventListener('click', closeModal);
        modal.addEventListener('click', (event) => {
            if (event.target === modal) closeModal();
        });

        modal.querySelector('#termsClauseForm')?.addEventListener('submit', async (event) => {
            event.preventDefault();
            const form = event.target;
            const payload = {
                clause_id: clause.clause_id,
                title: form.title.value.trim(),
                body: form.body.value.trim(),
                topics: form.topics.value.trim() || 'operations',
                section_key: 'operational',
                sort_order: parseInt(form.sort_order.value || '0', 10) || 0,
                is_active: form.is_active.checked
            };

            const endpoint = isEdit ? '../backend/api/update_terms_clause.php' : '../backend/api/add_terms_clause.php';
            try {
                const response = await this.performSensitiveAdminRequest(endpoint, {
                    method: 'POST',
                    credentials: 'include',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(payload)
                }, isEdit ? 'update the clause' : 'add the clause');
                const data = response.__adminPayloadAttached || await response.json();
                if (!response.ok || !data.success) {
                    throw new Error(data.message || 'Unable to save clause.');
                }
                this.showNotification(data.message || 'Clause saved.', 'success');
                closeModal();
                await this.loadTermsSettings();
            } catch (error) {
                this.showNotification(error.message || 'Unable to save clause.', 'error');
            }
        });
    }

    async toggleTermsActive(clause) {
        try {
            const response = await this.performSensitiveAdminRequest('../backend/api/update_terms_clause.php', {
                method: 'POST',
                credentials: 'include',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    clause_id: clause.clause_id,
                    title: clause.title,
                    body: clause.body,
                    topics: clause.topics,
                    section_key: clause.section_key || 'operational',
                    sort_order: clause.sort_order || 0,
                    is_active: !clause.is_active
                })
            }, clause.is_active ? 'disable the clause' : 'enable the clause');
            const data = response.__adminPayloadAttached || await response.json();
            if (!response.ok || !data.success) {
                throw new Error(data.message || 'Unable to update clause.');
            }
            this.showNotification(data.message || 'Clause updated.', 'success');
            await this.loadTermsSettings();
        } catch (error) {
            this.showNotification(error.message || 'Unable to update clause.', 'error');
        }
    }

    confirmTermsDelete(clause) {
        const existing = document.querySelector('.admin-confirm-overlay');
        if (existing) existing.remove();

        const overlay = document.createElement('div');
        overlay.className = 'admin-confirm-overlay';
        overlay.innerHTML = `
            <div class=\"admin-confirm-modal\">
                <h3>Delete Clause</h3>
                <p>Delete the clause titled <strong>${this.escapeHtml(clause.title)}</strong>?</p>
                <div class=\"admin-modal-footer\">
                    <button class=\"action-btn secondary\" data-action=\"cancel\">Cancel</button>
                    <button class=\"action-btn danger\" data-action=\"confirm\">Delete</button>
                </div>
            </div>
        `;
        document.body.appendChild(overlay);

        const closeModal = () => overlay.remove();
        overlay.querySelector('[data-action=\"cancel\"]')?.addEventListener('click', closeModal);
        overlay.addEventListener('click', (event) => {
            if (event.target === overlay) closeModal();
        });

        overlay.querySelector('[data-action=\"confirm\"]')?.addEventListener('click', async () => {
            try {
                const response = await this.performSensitiveAdminRequest('../backend/api/delete_terms_clause.php', {
                    method: 'POST',
                    credentials: 'include',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ clause_id: clause.clause_id })
                }, 'delete the clause');
                const data = response.__adminPayloadAttached || await response.json();
                if (!response.ok || !data.success) {
                    throw new Error(data.message || 'Unable to delete clause.');
                }
                this.showNotification(data.message || 'Clause deleted.', 'success');
                closeModal();
                await this.loadTermsSettings();
            } catch (error) {
                this.showNotification(error.message || 'Unable to delete clause.', 'error');
            }
        });
    }

    openTitleModal(mode, title = {}) {
        const existing = document.querySelector('.admin-modal-overlay');
        if (existing) existing.remove();

        const isEdit = mode === 'edit';
        const modal = document.createElement('div');
        modal.className = 'admin-modal-overlay';
        modal.innerHTML = `
            <div class="admin-modal">
                <div class="admin-modal-header">
                    <h3>${isEdit ? 'Edit Title' : 'Add Title'}</h3>
                    <button class="admin-modal-close" aria-label="Close">&times;</button>
                </div>
                <form class="admin-modal-body" id="titleSettingsForm">
                    <div class="form-grid">
                        <div class="form-field form-span">
                            <label>Title Name</label>
                            <input type="text" name="title_name" value="${this.escapeHtml(title.title_name || '')}" required placeholder="e.g., Chief Warder II">
                        </div>
                        <div class="form-field">
                            <label>Category</label>
                            <select name="category" required>
                                <option value="uniformed" ${title.category === 'uniformed' ? 'selected' : ''}>Uniformed</option>
                                <option value="non_uniformed" ${title.category === 'non_uniformed' ? 'selected' : ''}>Non-Uniformed</option>
                            </select>
                        </div>
                        <div class="form-field">
                            <label>Level</label>
                            <select name="level" required>
                                <option value="junior" ${title.level === 'junior' ? 'selected' : ''}>Junior</option>
                                <option value="senior" ${title.level === 'senior' ? 'selected' : ''}>Senior</option>
                            </select>
                        </div>
                        ${!isEdit ? `
                        <div class="form-field form-span">
                            <div class="admin-modal-inline-panel">
                                <div>
                                    <h4>Upload Titles in Bulk</h4>
                                    <p>Open the upload titles modal to import a CSV or XLSX file. The upload modal also includes the official template download.</p>
                                </div>
                                <button type="button" class="action-btn secondary" data-action="open-title-upload">Upload Titles</button>
                            </div>
                        </div>
                        ` : ''}
                        ${isEdit ? `
                        <div class="form-field">
                            <label>Status</label>
                            <select name="is_active">
                                <option value="1" ${title.is_active ? 'selected' : ''}>Active</option>
                                <option value="0" ${!title.is_active ? 'selected' : ''}>Inactive</option>
                            </select>
                        </div>
                        ` : ''}
                    </div>
                    <div class="admin-modal-footer">
                        <button type="button" class="action-btn secondary" data-action="cancel">Cancel</button>
                        <button type="submit" class="action-btn">${isEdit ? 'Save Changes' : 'Add Title'}</button>
                    </div>
                </form>
            </div>
        `;

        document.body.appendChild(modal);

        const closeModal = () => modal.remove();
        modal.querySelector('.admin-modal-close').addEventListener('click', closeModal);
        modal.querySelector('[data-action="cancel"]').addEventListener('click', closeModal);
        modal.addEventListener('click', (event) => {
            if (event.target === modal) closeModal();
        });

        modal.querySelector('[data-action="open-title-upload"]')?.addEventListener('click', async (event) => {
            const button = event.currentTarget;
            const originalLabel = button.textContent;
            button.disabled = true;
            button.textContent = 'Opening...';
            try {
                await this.openTitleImportModal();
            } finally {
                if (button.isConnected) {
                    button.disabled = false;
                    button.textContent = originalLabel;
                }
            }
        });

        modal.querySelector('#titleSettingsForm').addEventListener('submit', (event) => {
            event.preventDefault();
            this.submitTitleForm(mode, event.target, title.title_id);
        });
    }

    async openTitleImportModal() {
        try {
            const hasDataset = (this.importDataState.datasets || []).some((item) => item.key === 'titles');
            if (!hasDataset) {
                await this.fetchDataImportOverview();
            }
            this.openDataImportModal('titles');
        } catch (error) {
            console.error('Unable to open title import modal:', error);
            this.showNotification(error.message || 'Unable to open the titles upload module.', 'error');
        }
    }

    async submitTitleForm(mode, form, titleId) {
        const payload = {
            title_name: form.title_name.value.trim(),
            category: form.category.value,
            level: form.level.value
        };

        if (!payload.title_name) {
            this.showNotification('Title name is required.', 'error');
            return;
        }

        let url = '../backend/api/add_title.php';
        if (mode === 'edit') {
            payload.title_id = titleId;
            payload.is_active = form.is_active ? form.is_active.value === '1' : true;
            url = '../backend/api/update_title.php';
        }

        try {
            const response = await fetch(url, {
                method: 'POST',
                credentials: 'include',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload)
            });

            const data = await this.safeJson(response, { success: false });
            if (!data.success) {
                this.showNotification(data.message || 'Unable to save title.', 'error');
                return;
            }

            this.showNotification(data.message || 'Title saved.', 'success');
            document.querySelector('.admin-modal-overlay')?.remove();
            await this.loadTitleSettings();
        } catch (error) {
            console.error('Title save error:', error);
            this.showNotification('Unable to save title.', 'error');
        }
    }

    async toggleTitleActive(title) {
        const payload = {
            title_id: title.title_id,
            title_name: title.title_name,
            category: title.category,
            level: title.level,
            is_active: !title.is_active
        };

        try {
            const response = await fetch('../backend/api/update_title.php', {
                method: 'POST',
                credentials: 'include',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload)
            });
            const data = await this.safeJson(response, { success: false });
            if (!data.success) {
                this.showNotification(data.message || 'Unable to update title.', 'error');
                return;
            }
            this.showNotification(`Title ${payload.is_active ? 'enabled' : 'disabled'}.`, 'success');
            await this.loadTitleSettings();
        } catch (error) {
            console.error('Toggle title error:', error);
            this.showNotification('Unable to update title.', 'error');
        }
    }

    confirmTitleDelete(title) {
        const existing = document.querySelector('.admin-confirm-overlay');
        if (existing) existing.remove();

        const overlay = document.createElement('div');
        overlay.className = 'admin-confirm-overlay';
        overlay.innerHTML = `
            <div class="admin-confirm-modal">
                <h3>Delete Title</h3>
                <p>Remove <strong>${this.escapeHtml(title.title_name || 'this title')}</strong>? This action cannot be undone.</p>
                <div class="admin-modal-footer">
                    <button class="action-btn secondary" data-action="cancel">Cancel</button>
                    <button class="action-btn danger" data-action="confirm">Delete</button>
                </div>
            </div>
        `;

        document.body.appendChild(overlay);
        const close = () => overlay.remove();
        overlay.querySelector('[data-action="cancel"]').addEventListener('click', close);
        overlay.querySelector('[data-action="confirm"]').addEventListener('click', async () => {
            await this.deleteTitle(title.title_id);
            close();
        });
        overlay.addEventListener('click', (event) => {
            if (event.target === overlay) close();
        });
    }

    async deleteTitle(titleId) {
        try {
            const response = await fetch('../backend/api/delete_title.php', {
                method: 'POST',
                credentials: 'include',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ title_id: titleId })
            });
            const data = await this.safeJson(response, { success: false });
            if (!data.success) {
                this.showNotification(data.message || 'Unable to delete title.', 'error');
                return;
            }
            this.showNotification(data.message || 'Title deleted.', 'success');
            await this.loadTitleSettings();
        } catch (error) {
            console.error('Delete title error:', error);
            this.showNotification('Unable to delete title.', 'error');
        }
    }

    openBankModal(mode, bank = {}) {
        const existing = document.querySelector('.admin-modal-overlay');
        if (existing) existing.remove();

        const isEdit = mode === 'edit';
        const modal = document.createElement('div');
        modal.className = 'admin-modal-overlay';
        modal.innerHTML = `
            <div class="admin-modal">
                <div class="admin-modal-header">
                    <h3>${isEdit ? 'Edit Bank' : 'Add Bank'}</h3>
                    <button class="admin-modal-close" aria-label="Close">&times;</button>
                </div>
                <form class="admin-modal-body" id="bankSettingsForm">
                    <div class="form-grid">
                        <div class="form-field form-span">
                            <label>Bank Name</label>
                            <input type="text" name="bank_name" value="${this.escapeHtml(bank.bank_name || '')}" required placeholder="e.g., Stanbic Bank Uganda Limited">
                        </div>
                        <div class="form-field">
                            <label>Short Name</label>
                            <input type="text" name="short_name" value="${this.escapeHtml(bank.short_name || '')}" placeholder="e.g., Stanbic">
                        </div>
                        <div class="form-field">
                            <label>Bank Code</label>
                            <input type="text" name="bank_code" value="${this.escapeHtml(bank.bank_code || '')}" maxlength="30" placeholder="e.g., STANBIC">
                        </div>
                        <div class="form-field">
                            <label>Display Order</label>
                            <input type="number" name="display_order" value="${this.escapeHtml(String(bank.display_order ?? 0))}" min="0" step="1">
                        </div>
                        ${isEdit ? `
                        <div class="form-field">
                            <label>Status</label>
                            <select name="is_active">
                                <option value="1" ${bank.is_active ? 'selected' : ''}>Active</option>
                                <option value="0" ${!bank.is_active ? 'selected' : ''}>Inactive</option>
                            </select>
                        </div>
                        ` : ''}
                    </div>
                    <div class="admin-modal-footer">
                        <button type="button" class="action-btn secondary" data-action="cancel">Cancel</button>
                        <button type="submit" class="action-btn">${isEdit ? 'Save Changes' : 'Add Bank'}</button>
                    </div>
                </form>
            </div>
        `;

        document.body.appendChild(modal);

        const closeModal = () => modal.remove();
        modal.querySelector('.admin-modal-close').addEventListener('click', closeModal);
        modal.querySelector('[data-action="cancel"]').addEventListener('click', closeModal);
        modal.addEventListener('click', (event) => {
            if (event.target === modal) closeModal();
        });

        modal.querySelector('#bankSettingsForm').addEventListener('submit', (event) => {
            event.preventDefault();
            this.submitBankForm(mode, event.target, bank.bank_id);
        });
    }

    async submitBankForm(mode, form, bankId) {
        const payload = {
            bank_name: form.bank_name.value.trim(),
            short_name: form.short_name.value.trim(),
            bank_code: form.bank_code.value.trim().toUpperCase(),
            display_order: Number.parseInt(form.display_order.value || '0', 10) || 0
        };

        if (!payload.bank_name) {
            this.showNotification('Bank name is required.', 'error');
            return;
        }

        let url = '../backend/api/add_bank.php';
        if (mode === 'edit') {
            payload.bank_id = bankId;
            payload.is_active = form.is_active ? form.is_active.value === '1' : true;
            url = '../backend/api/update_bank.php';
        }

        try {
            const response = await fetch(url, {
                method: 'POST',
                credentials: 'include',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload)
            });

            const data = await this.safeJson(response, { success: false });
            if (!data.success) {
                this.showNotification(data.message || 'Unable to save bank.', 'error');
                return;
            }

            this.showNotification(data.message || 'Bank saved.', 'success');
            document.querySelector('.admin-modal-overlay')?.remove();
            await this.loadBankSettings();
        } catch (error) {
            console.error('Bank save error:', error);
            this.showNotification('Unable to save bank.', 'error');
        }
    }

    async toggleBankActive(bank) {
        const payload = {
            bank_id: bank.bank_id,
            bank_name: bank.bank_name,
            short_name: bank.short_name || '',
            bank_code: bank.bank_code || '',
            display_order: Number(bank.display_order || 0),
            is_active: !bank.is_active
        };

        try {
            const response = await fetch('../backend/api/update_bank.php', {
                method: 'POST',
                credentials: 'include',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload)
            });
            const data = await this.safeJson(response, { success: false });
            if (!data.success) {
                this.showNotification(data.message || 'Unable to update bank.', 'error');
                return;
            }
            this.showNotification(`Bank ${payload.is_active ? 'enabled' : 'disabled'}.`, 'success');
            await this.loadBankSettings();
        } catch (error) {
            console.error('Toggle bank error:', error);
            this.showNotification('Unable to update bank.', 'error');
        }
    }

    confirmBankDelete(bank) {
        const existing = document.querySelector('.admin-confirm-overlay');
        if (existing) existing.remove();

        const overlay = document.createElement('div');
        overlay.className = 'admin-confirm-overlay';
        overlay.innerHTML = `
            <div class="admin-confirm-modal">
                <h3>Delete Bank</h3>
                <p>Remove <strong>${this.escapeHtml(bank.bank_name || 'this bank')}</strong>? This action cannot be undone.</p>
                <div class="admin-modal-footer">
                    <button class="action-btn secondary" data-action="cancel">Cancel</button>
                    <button class="action-btn danger" data-action="confirm">Delete</button>
                </div>
            </div>
        `;

        document.body.appendChild(overlay);
        const close = () => overlay.remove();
        overlay.querySelector('[data-action="cancel"]').addEventListener('click', close);
        overlay.querySelector('[data-action="confirm"]').addEventListener('click', async () => {
            await this.deleteBank(bank.bank_id);
            close();
        });
        overlay.addEventListener('click', (event) => {
            if (event.target === overlay) close();
        });
    }

    async deleteBank(bankId) {
        try {
            const response = await fetch('../backend/api/delete_bank.php', {
                method: 'POST',
                credentials: 'include',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ bank_id: bankId })
            });
            const data = await this.safeJson(response, { success: false });
            if (!data.success) {
                this.showNotification(data.message || 'Unable to delete bank.', 'error');
                return;
            }
            this.showNotification(data.message || 'Bank deleted.', 'success');
            await this.loadBankSettings();
        } catch (error) {
            console.error('Delete bank error:', error);
            this.showNotification('Unable to delete bank.', 'error');
        }
    }

    async loadUnitSettings() {
        const tableBody = document.getElementById('unitSettingsTableBody');
        if (!tableBody) return;

        tableBody.innerHTML = `
            <tr><td colspan="6"><div class="table-loading">Loading units...</div></td></tr>
        `;

        try {
            const response = await fetch('../backend/api/get_priunits_admin.php', { credentials: 'include', cache: 'no-store' });
            const data = await this.safeJson(response, { success: false, units: [] });

            if (!data.success) {
                tableBody.innerHTML = `
                    <tr><td colspan="6"><div class="table-empty">Unable to load units.</div></td></tr>
                `;
                return;
            }

            this.unitSettings.units = data.units || [];
            await this.loadUnitLookups(true);
            this.populateUnitFilters();
            this.applyUnitFilters();
        } catch (error) {
            console.error('Error loading units:', error);
            tableBody.innerHTML = `
                <tr><td colspan="6"><div class="table-empty">Failed to load units.</div></td></tr>
            `;
        }
    }

    populateUnitFilters() {
        const regionFilter = document.getElementById('unitRegionFilter');
        const districtFilter = document.getElementById('unitDistrictFilter');
        if (!regionFilter || !districtFilter) return;

        const currentRegion = regionFilter.value;
        const currentDistrict = districtFilter.value;

        const regions = Array.from(new Set(this.unitSettings.units.map(unit => unit.priRegion).filter(Boolean))).sort();
        const districts = Array.from(new Set(this.unitSettings.units.map(unit => unit.priDistrict).filter(Boolean))).sort();

        regionFilter.innerHTML = `
            <option value="">All Prison Regions</option>
            ${regions.map(region => `<option value="${this.escapeHtml(region)}">${this.escapeHtml(region)}</option>`).join('')}
        `;
        districtFilter.innerHTML = `
            <option value="">All Prison Districts</option>
            ${districts.map(district => `<option value="${this.escapeHtml(district)}">${this.escapeHtml(district)}</option>`).join('')}
        `;

        if (currentRegion) {
            regionFilter.value = regions.includes(currentRegion) ? currentRegion : '';
        }
        if (currentDistrict) {
            districtFilter.value = districts.includes(currentDistrict) ? currentDistrict : '';
        }
    }

    applyUnitFilters() {
        const searchValue = (document.getElementById('unitSearchInput')?.value || '').toLowerCase();
        const regionValue = document.getElementById('unitRegionFilter')?.value || '';
        const districtValue = document.getElementById('unitDistrictFilter')?.value || '';

        this.unitSettings.filtered = this.unitSettings.units.filter(unit => {
            const composite = `${unit.priUnit || ''} ${unit.priDistrict || 'N/A'} ${unit.priRegion || 'N/A'} ${unit.polDistrict || 'N/A'} ${unit.polRegion || 'N/A'}`.toLowerCase();
            const matchesSearch = searchValue ? composite.includes(searchValue) : true;
            const matchesRegion = regionValue ? unit.priRegion === regionValue : true;
            const matchesDistrict = districtValue ? unit.priDistrict === districtValue : true;
            return matchesSearch && matchesRegion && matchesDistrict;
        });

        this.renderUnitTable();
        this.updateUnitSummaryCards();
    }

    updateUnitSummaryCards() {
        const total = this.unitSettings.units.length;
        const districts = new Set(this.unitSettings.units.map(unit => unit.priDistrict).filter(Boolean));
        const regions = new Set(this.unitSettings.units.map(unit => unit.priRegion).filter(Boolean));
        const politicalDistricts = new Set(this.unitSettings.units.map(unit => unit.polDistrict).filter(Boolean));

        const setValue = (id, value) => {
            const el = document.getElementById(id);
            if (el) el.textContent = value;
        };

        setValue('unitSummaryTotal', total);
        setValue('unitSummaryDistricts', districts.size);
        setValue('unitSummaryRegions', regions.size);
        setValue('unitSummaryPoliticalDistricts', politicalDistricts.size);
    }

    renderUnitTable() {
        const tableBody = document.getElementById('unitSettingsTableBody');
        if (!tableBody) return;

        if (this.unitSettings.filtered.length === 0) {
            tableBody.innerHTML = `
                <tr><td colspan="6"><div class="table-empty">No units match the current filters.</div></td></tr>
            `;
            return;
        }

        tableBody.innerHTML = this.unitSettings.filtered.map(unit => {
            return `
                <tr>
                    <td>${this.escapeHtml(unit.priUnit || 'Unknown')}</td>
                    <td>${this.escapeHtml(unit.priDistrict || 'N/A')}</td>
                    <td>${this.escapeHtml(unit.priRegion || 'N/A')}</td>
                    <td>${this.escapeHtml(unit.polDistrict || 'N/A')}</td>
                    <td>${this.escapeHtml(unit.polRegion || 'N/A')}</td>
                    <td>
                        <div class="user-actions">
                            <button class="user-action-btn" data-action="edit" data-id="${unit.Id}">Edit</button>
                            <button class="user-action-btn danger" data-action="delete" data-id="${unit.Id}">Delete</button>
                        </div>
                    </td>
                </tr>
            `;
        }).join('');
    }

    async loadUnitLookups(force = false) {
        if (!this.unitSettings) {
            this.unitSettings = { units: [], filtered: [] };
        }
        if (this.unitSettings.lookupsLoaded && !force) {
            return;
        }

        const empty = { prisonDistricts: [], prisonRegions: [], politicalDistricts: [] };

        try {
            const [districtsRes, regionsRes, politicalRes] = await Promise.all([
                fetch('../backend/api/get_pridistricts_admin.php', { credentials: 'include', cache: 'no-store' }),
                fetch('../backend/api/get_priregions_admin.php', { credentials: 'include', cache: 'no-store' }),
                fetch('../backend/api/get_poldistricts_admin.php', { credentials: 'include', cache: 'no-store' })
            ]);

            const districtsData = await this.safeJson(districtsRes, { success: false, districts: [] });
            const regionsData = await this.safeJson(regionsRes, { success: false, regions: [] });
            const politicalData = await this.safeJson(politicalRes, { success: false, districts: [] });

            this.unitSettings.lookups = {
                prisonDistricts: (districtsData.districts || []).map(d => d.priDistrict).filter(Boolean),
                prisonRegions: (regionsData.regions || []).map(r => r.priRegion).filter(Boolean),
                politicalDistricts: (politicalData.districts || []).map(d => d.polDistrict).filter(Boolean)
            };
            this.unitSettings.lookupsLoaded = true;
        } catch (error) {
            console.warn('Unable to load unit lookups:', error);
            this.unitSettings.lookups = empty;
            this.unitSettings.lookupsLoaded = false;
        }
    }

    async openUnitModal(mode, unit = {}) {
        const existing = document.querySelector('.admin-modal-overlay');
        if (existing) existing.remove();

        const isEdit = mode === 'edit';
        await this.loadUnitLookups();

        const lookupValues = this.unitSettings.lookups || { prisonDistricts: [], prisonRegions: [], politicalDistricts: [] };
        const currentDistrict = unit.priDistrict || '';
        const currentRegion = unit.priRegion || '';
        const currentPoliticalDistrict = unit.polDistrict || '';
        const currentPoliticalRegion = unit.polRegion || '';
        const politicalRegions = ['Northern', 'Eastern', 'Central', 'Western'];

        const buildOptions = (values, currentValue, placeholder) => {
            const cleaned = values.map(value => String(value || '').trim()).filter(Boolean);
            if (currentValue && !cleaned.includes(currentValue)) {
                cleaned.unshift(currentValue);
            }
            const unique = Array.from(new Set(cleaned));
            return `
                <option value="">${placeholder}</option>
                ${unique.map(value => `<option value="${this.escapeHtml(value)}" ${value === currentValue ? 'selected' : ''}>${this.escapeHtml(value)}</option>`).join('')}
            `;
        };

        const politicalRegionOptions = (() => {
            const options = Array.from(new Set([currentPoliticalRegion, ...politicalRegions].filter(Boolean)));
            return `
                <option value="">Select region</option>
                ${options.map(region => `<option value="${this.escapeHtml(region)}" ${region === currentPoliticalRegion ? 'selected' : ''}>${this.escapeHtml(region)}</option>`).join('')}
            `;
        })();
        const modal = document.createElement('div');
        modal.className = 'admin-modal-overlay';
        modal.innerHTML = `
            <div class="admin-modal">
                <div class="admin-modal-header">
                    <h3>${isEdit ? 'Edit Unit' : 'Add Unit'}</h3>
                    <button class="admin-modal-close" aria-label="Close">&times;</button>
                </div>
                <form class="admin-modal-body" id="unitSettingsForm">
                    <div class="form-grid">
                        <div class="form-field form-span">
                            <label>Prison Unit</label>
                            <input type="text" name="priUnit" value="${this.escapeHtml(unit.priUnit || '')}" required placeholder="Unit name">
                        </div>
                        <div class="form-field">
                            <label>Prison District</label>
                            <select name="priDistrict">
                                ${buildOptions(lookupValues.prisonDistricts || [], currentDistrict, 'Select district')}
                            </select>
                        </div>
                        <div class="form-field">
                            <label>Prison Region</label>
                            <select name="priRegion">
                                ${buildOptions(lookupValues.prisonRegions || [], currentRegion, 'Select region')}
                            </select>
                        </div>
                        <div class="form-field">
                            <label>Political District</label>
                            <select name="polDistrict">
                                ${buildOptions(lookupValues.politicalDistricts || [], currentPoliticalDistrict, 'Select district')}
                            </select>
                        </div>
                        <div class="form-field">
                            <label>Political Region</label>
                            <select name="polRegion">
                                ${politicalRegionOptions}
                            </select>
                        </div>
                    </div>
                    <div class="admin-modal-footer">
                        <button type="button" class="action-btn secondary" data-action="cancel">Cancel</button>
                        <button type="submit" class="action-btn">${isEdit ? 'Save Changes' : 'Add Unit'}</button>
                    </div>
                </form>
            </div>
        `;

        document.body.appendChild(modal);

        const closeModal = () => modal.remove();
        modal.querySelector('.admin-modal-close').addEventListener('click', closeModal);
        modal.querySelector('[data-action="cancel"]').addEventListener('click', closeModal);
        modal.addEventListener('click', (event) => {
            if (event.target === modal) closeModal();
        });

        modal.querySelector('#unitSettingsForm').addEventListener('submit', (event) => {
            event.preventDefault();
            this.submitUnitForm(mode, event.target, unit.Id);
        });
    }

    async submitUnitForm(mode, form, unitId) {
        const payload = {
            priUnit: form.priUnit.value.trim(),
            priDistrict: form.priDistrict.value.trim(),
            priRegion: form.priRegion.value.trim(),
            polDistrict: form.polDistrict.value.trim(),
            polRegion: form.polRegion.value.trim()
        };

        if (!payload.priUnit) {
            this.showNotification('Unit name is required.', 'error');
            return;
        }

        let url = '../backend/api/add_priunit.php';
        if (mode === 'edit') {
            payload.Id = unitId;
            url = '../backend/api/update_priunit.php';
        }

        try {
            const response = await fetch(url, {
                method: 'POST',
                credentials: 'include',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload)
            });

            const data = await this.safeJson(response, { success: false });
            if (!data.success) {
                this.showNotification(data.message || 'Unable to save unit.', 'error');
                return;
            }

            this.showNotification(data.message || 'Unit saved.', 'success');
            document.querySelector('.admin-modal-overlay')?.remove();
            await this.loadUnitSettings();
        } catch (error) {
            console.error('Unit save error:', error);
            this.showNotification('Unable to save unit.', 'error');
        }
    }

    confirmUnitDelete(unit) {
        const existing = document.querySelector('.admin-confirm-overlay');
        if (existing) existing.remove();

        const overlay = document.createElement('div');
        overlay.className = 'admin-confirm-overlay';
        overlay.innerHTML = `
            <div class="admin-confirm-modal">
                <h3>Delete Unit</h3>
                <p>Remove <strong>${this.escapeHtml(unit.priUnit || 'this unit')}</strong>? This action cannot be undone.</p>
                <div class="admin-modal-footer">
                    <button class="action-btn secondary" data-action="cancel">Cancel</button>
                    <button class="action-btn danger" data-action="confirm">Delete</button>
                </div>
            </div>
        `;

        document.body.appendChild(overlay);
        const close = () => overlay.remove();
        overlay.querySelector('[data-action="cancel"]').addEventListener('click', close);
        overlay.querySelector('[data-action="confirm"]').addEventListener('click', async () => {
            await this.deleteUnit(unit.Id);
            close();
        });
        overlay.addEventListener('click', (event) => {
            if (event.target === overlay) close();
        });
    }

    async deleteUnit(unitId) {
        try {
            const response = await fetch('../backend/api/delete_priunit.php', {
                method: 'POST',
                credentials: 'include',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ Id: unitId })
            });
            const data = await this.safeJson(response, { success: false });
            if (!data.success) {
                this.showNotification(data.message || 'Unable to delete unit.', 'error');
                return;
            }
            this.showNotification(data.message || 'Unit deleted.', 'success');
            await this.loadUnitSettings();
        } catch (error) {
            console.error('Delete unit error:', error);
            this.showNotification('Unable to delete unit.', 'error');
        }
    }

    async loadPrisonDistrictSettings() {
        const tableBody = document.getElementById('prisonDistrictTableBody');
        if (!tableBody) return;

        tableBody.innerHTML = `
            <tr><td colspan="2"><div class="table-loading">Loading districts...</div></td></tr>
        `;

        try {
            const response = await fetch('../backend/api/get_pridistricts_admin.php', {
                credentials: 'include',
                cache: 'no-store'
            });
            const data = await this.safeJson(response, { success: false, districts: [] });

            if (!data.success) {
                tableBody.innerHTML = `
                    <tr><td colspan="2"><div class="table-empty">Unable to load districts.</div></td></tr>
                `;
                return;
            }

            this.prisonDistrictSettings.districts = data.districts || [];
            this.applyPrisonDistrictFilters();
        } catch (error) {
            console.error('Error loading prison districts:', error);
            tableBody.innerHTML = `
                <tr><td colspan="2"><div class="table-empty">Failed to load districts.</div></td></tr>
            `;
        }
    }

    applyPrisonDistrictFilters() {
        const searchValue = (document.getElementById('prisonDistrictSearchInput')?.value || '').toLowerCase();
        this.prisonDistrictSettings.filtered = this.prisonDistrictSettings.districts.filter((district) => {
            const composite = `${district.priDistrict || ''} ${district.priRegion || ''}`.toLowerCase();
            return searchValue ? composite.includes(searchValue) : true;
        });
        this.renderPrisonDistrictTable();
        this.updatePrisonDistrictSummary();
    }

    updatePrisonDistrictSummary() {
        const total = this.prisonDistrictSettings.districts.length;
        const el = document.getElementById('prisonDistrictSummaryTotal');
        if (el) el.textContent = total;
    }

    renderUnitCountLink(count, filterType, filterValue, label) {
        const total = Number.isFinite(Number(count)) ? Number(count) : 0;
        const safeValue = String(filterValue || '').trim();
        if (!safeValue) {
            return `<span class="muted">${total}</span>`;
        }
        const disabled = total === 0 ? 'disabled' : '';
        const safeLabel = this.escapeHtml(label || safeValue);
        return `
            <button type="button"
                class="unit-count-link"
                data-filter-type="${this.escapeHtml(filterType)}"
                data-filter-value="${this.escapeHtml(safeValue)}"
                data-filter-label="${safeLabel}"
                ${disabled}
            >${total}</button>
        `;
    }

    async fetchUnitsForLookup() {
        if (this.unitSettings && Array.isArray(this.unitSettings.units) && this.unitSettings.units.length) {
            return this.unitSettings.units;
        }

        try {
            const response = await fetch('../backend/api/get_priunits_admin.php', {
                credentials: 'include',
                cache: 'no-store'
            });
            const data = await this.safeJson(response, { success: false, units: [] });
            if (data.success) {
                if (!this.unitSettings) {
                    this.unitSettings = { units: [], filtered: [] };
                }
                this.unitSettings.units = data.units || [];
                return this.unitSettings.units;
            }
        } catch (error) {
            console.warn('Unable to fetch units for lookup:', error);
        }
        return [];
    }

    async openUnitFilterModal(filterType, filterValue, label) {
        const existing = document.querySelector('.admin-modal-overlay');
        if (existing) existing.remove();

        const units = await this.fetchUnitsForLookup();
        const normalizedValue = String(filterValue || '').trim();
        const filtered = units.filter((unit) => String(unit?.[filterType] || '').trim() === normalizedValue);
        const titleLabel = label || `${filterType}: ${normalizedValue}`;

        const modal = document.createElement('div');
        modal.className = 'admin-modal-overlay';
        modal.innerHTML = `
            <div class="admin-modal admin-modal-wide">
                <div class="admin-modal-header">
                    <h3>Prison Units (${filtered.length})</h3>
                    <button class="admin-modal-close" aria-label="Close">&times;</button>
                </div>
                <div class="admin-modal-body">
                    <p class="modal-subtitle">${this.escapeHtml(titleLabel)}</p>
                    <div class="settings-table-container">
                        <table class="settings-table">
                            <thead>
                                <tr>
                                    <th>Unit</th>
                                    <th>Prison District</th>
                                    <th>Prison Region</th>
                                    <th>Political District</th>
                                    <th>Political Region</th>
                                </tr>
                            </thead>
                            <tbody>
                                ${filtered.length ? filtered.map((unit) => `
                                    <tr>
                                        <td>${this.escapeHtml(unit.priUnit || 'Unknown')}</td>
                                        <td>${this.escapeHtml(unit.priDistrict || 'N/A')}</td>
                                        <td>${this.escapeHtml(unit.priRegion || 'N/A')}</td>
                                        <td>${this.escapeHtml(unit.polDistrict || 'N/A')}</td>
                                        <td>${this.escapeHtml(unit.polRegion || 'N/A')}</td>
                                    </tr>
                                `).join('') : `
                                    <tr>
                                        <td colspan="5"><div class="table-empty">No units match this filter.</div></td>
                                    </tr>
                                `}
                            </tbody>
                        </table>
                    </div>
                </div>
                <div class="admin-modal-footer">
                    <button type="button" class="action-btn secondary" data-action="close">Close</button>
                </div>
            </div>
        `;

        document.body.appendChild(modal);
        const closeModal = () => modal.remove();
        modal.querySelector('.admin-modal-close')?.addEventListener('click', closeModal);
        modal.querySelector('[data-action="close"]')?.addEventListener('click', closeModal);
        modal.addEventListener('click', (event) => {
            if (event.target === modal) closeModal();
        });
    }

    renderPrisonDistrictTable() {
        const tableBody = document.getElementById('prisonDistrictTableBody');
        if (!tableBody) return;

        if (this.prisonDistrictSettings.filtered.length === 0) {
            tableBody.innerHTML = `
                <tr><td colspan="4"><div class="table-empty">No districts match the current filters.</div></td></tr>
            `;
            return;
        }

        tableBody.innerHTML = this.prisonDistrictSettings.filtered.map((district) => `
            <tr>
                <td>${this.escapeHtml(district.priDistrict || 'Unknown')}</td>
                <td>${this.escapeHtml(district.priRegion || 'N/A')}</td>
                <td>${this.renderUnitCountLink(district.unit_count, 'priDistrict', district.priDistrict, `Prison District: ${district.priDistrict}`)}</td>
                <td>
                    <div class="user-actions">
                        <button class="user-action-btn" data-action="edit" data-id="${district.district_id}">Edit</button>
                        <button class="user-action-btn danger" data-action="delete" data-id="${district.district_id}" ${district.unit_count > 0 ? 'disabled title="Remove linked units first."' : ''}>Delete</button>
                    </div>
                </td>
            </tr>
        `).join('');
    }

    async fetchPrisonRegionsList() {
        if (this.prisonRegionSettings && Array.isArray(this.prisonRegionSettings.regions) && this.prisonRegionSettings.regions.length) {
            return this.prisonRegionSettings.regions.map(region => region.priRegion).filter(Boolean);
        }

        try {
            const response = await fetch('../backend/api/get_priregions_admin.php', {
                credentials: 'include',
                cache: 'no-store'
            });
            const data = await this.safeJson(response, { success: false, regions: [] });
            if (data.success) {
                return (data.regions || []).map(region => region.priRegion).filter(Boolean);
            }
        } catch (error) {
            console.warn('Unable to load prison regions for dropdown:', error);
        }

        return [];
    }

    async openPrisonDistrictModal(mode, district = {}) {
        const existing = document.querySelector('.admin-modal-overlay');
        if (existing) existing.remove();

        const isEdit = mode === 'edit';
        const regions = await this.fetchPrisonRegionsList();
        const currentRegion = district.priRegion || '';
        const regionOptions = Array.from(new Set([currentRegion, ...regions].filter(Boolean)));

        const modal = document.createElement('div');
        modal.className = 'admin-modal-overlay';
        modal.innerHTML = `
            <div class="admin-modal">
                <div class="admin-modal-header">
                    <h3>${isEdit ? 'Edit Prison District' : 'Add Prison District'}</h3>
                    <button class="admin-modal-close" aria-label="Close">&times;</button>
                </div>
                <form class="admin-modal-body" id="prisonDistrictForm">
                    <div class="form-grid">
                        <div class="form-field form-span">
                            <label>Prison District</label>
                            <input type="text" name="priDistrict" value="${this.escapeHtml(district.priDistrict || '')}" required placeholder="District name">
                        </div>
                        <div class="form-field form-span">
                            <label>Prison Region</label>
                            <select name="priRegion" required>
                                <option value="">Select region</option>
                                ${regionOptions.map(region => `<option value="${this.escapeHtml(region)}" ${region === currentRegion ? 'selected' : ''}>${this.escapeHtml(region)}</option>`).join('')}
                            </select>
                        </div>
                    </div>
                    <div class="admin-modal-footer">
                        <button type="button" class="action-btn secondary" data-action="cancel">Cancel</button>
                        <button type="submit" class="action-btn">${isEdit ? 'Save Changes' : 'Add District'}</button>
                    </div>
                </form>
            </div>
        `;

        document.body.appendChild(modal);

        const closeModal = () => modal.remove();
        modal.querySelector('.admin-modal-close')?.addEventListener('click', closeModal);
        modal.querySelector('[data-action="cancel"]')?.addEventListener('click', closeModal);
        modal.addEventListener('click', (event) => {
            if (event.target === modal) closeModal();
        });

        modal.querySelector('#prisonDistrictForm')?.addEventListener('submit', (event) => {
            event.preventDefault();
            this.submitPrisonDistrictForm(mode, event.target, district.district_id);
        });
    }

    async submitPrisonDistrictForm(mode, form, districtId) {
        const payload = {
            priDistrict: form.priDistrict.value.trim(),
            priRegion: form.priRegion.value.trim()
        };

        if (!payload.priDistrict || !payload.priRegion) {
            this.showNotification('District and region are required.', 'error');
            return;
        }

        let url = '../backend/api/add_pridistrict.php';
        if (mode === 'edit') {
            payload.district_id = districtId;
            url = '../backend/api/update_pridistrict.php';
        }

        try {
            const response = await fetch(url, {
                method: 'POST',
                credentials: 'include',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload)
            });
            const data = await this.safeJson(response, { success: false });
            if (!data.success) {
                this.showNotification(data.message || 'Unable to save district.', 'error');
                return;
            }
            this.showNotification(data.message || 'District saved.', 'success');
            document.querySelector('.admin-modal-overlay')?.remove();
            await this.loadPrisonDistrictSettings();
        } catch (error) {
            console.error('Prison district save error:', error);
            this.showNotification('Unable to save district.', 'error');
        }
    }

    confirmPrisonDistrictDelete(district) {
        const existing = document.querySelector('.admin-confirm-overlay');
        if (existing) existing.remove();

        const overlay = document.createElement('div');
        overlay.className = 'admin-confirm-overlay';
        overlay.innerHTML = `
            <div class="admin-confirm-modal">
                <h3>Delete Prison District</h3>
                <p>Remove <strong>${this.escapeHtml(district.priDistrict || 'this district')}</strong>? This action cannot be undone.</p>
                <div class="admin-modal-footer">
                    <button class="action-btn secondary" data-action="cancel">Cancel</button>
                    <button class="action-btn danger" data-action="confirm">Delete</button>
                </div>
            </div>
        `;

        document.body.appendChild(overlay);
        const close = () => overlay.remove();
        overlay.querySelector('[data-action="cancel"]')?.addEventListener('click', close);
        overlay.querySelector('[data-action="confirm"]')?.addEventListener('click', async () => {
            await this.deletePrisonDistrict(district.district_id);
            close();
        });
        overlay.addEventListener('click', (event) => {
            if (event.target === overlay) close();
        });
    }

    async deletePrisonDistrict(districtId) {
        try {
            const response = await fetch('../backend/api/delete_pridistrict.php', {
                method: 'POST',
                credentials: 'include',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ district_id: districtId })
            });
            const data = await this.safeJson(response, { success: false });
            if (!data.success) {
                this.showNotification(data.message || 'Unable to delete district.', 'error');
                return;
            }
            this.showNotification(data.message || 'District deleted.', 'success');
            await this.loadPrisonDistrictSettings();
        } catch (error) {
            console.error('Delete prison district error:', error);
            this.showNotification('Unable to delete district.', 'error');
        }
    }

    async loadPrisonRegionSettings() {
        const tableBody = document.getElementById('prisonRegionTableBody');
        if (!tableBody) return;

        tableBody.innerHTML = `
            <tr><td colspan="2"><div class="table-loading">Loading regions...</div></td></tr>
        `;

        try {
            const response = await fetch('../backend/api/get_priregions_admin.php', {
                credentials: 'include',
                cache: 'no-store'
            });
            const data = await this.safeJson(response, { success: false, regions: [] });

            if (!data.success) {
                tableBody.innerHTML = `
                    <tr><td colspan="2"><div class="table-empty">Unable to load regions.</div></td></tr>
                `;
                return;
            }

            this.prisonRegionSettings.regions = data.regions || [];
            this.applyPrisonRegionFilters();
        } catch (error) {
            console.error('Error loading prison regions:', error);
            tableBody.innerHTML = `
                <tr><td colspan="2"><div class="table-empty">Failed to load regions.</div></td></tr>
            `;
        }
    }

    applyPrisonRegionFilters() {
        const searchValue = (document.getElementById('prisonRegionSearchInput')?.value || '').toLowerCase();
        this.prisonRegionSettings.filtered = this.prisonRegionSettings.regions.filter((region) => {
            const name = (region.priRegion || '').toLowerCase();
            return searchValue ? name.includes(searchValue) : true;
        });
        this.renderPrisonRegionTable();
        this.updatePrisonRegionSummary();
    }

    updatePrisonRegionSummary() {
        const total = this.prisonRegionSettings.regions.length;
        const el = document.getElementById('prisonRegionSummaryTotal');
        if (el) el.textContent = total;
    }

    renderPrisonRegionTable() {
        const tableBody = document.getElementById('prisonRegionTableBody');
        if (!tableBody) return;

        if (this.prisonRegionSettings.filtered.length === 0) {
            tableBody.innerHTML = `
                <tr><td colspan="3"><div class="table-empty">No regions match the current filters.</div></td></tr>
            `;
            return;
        }

        tableBody.innerHTML = this.prisonRegionSettings.filtered.map((region) => `
            <tr>
                <td>${this.escapeHtml(region.priRegion || 'Unknown')}</td>
                <td>${this.renderUnitCountLink(region.unit_count, 'priRegion', region.priRegion, `Prison Region: ${region.priRegion}`)}</td>
                <td>
                    <div class="user-actions">
                        <button class="user-action-btn" data-action="edit" data-id="${region.region_id}">Edit</button>
                        <button class="user-action-btn danger" data-action="delete" data-id="${region.region_id}" ${region.unit_count > 0 ? 'disabled title="Remove linked units first."' : ''}>Delete</button>
                    </div>
                </td>
            </tr>
        `).join('');
    }

    openPrisonRegionModal(mode, region = {}) {
        const existing = document.querySelector('.admin-modal-overlay');
        if (existing) existing.remove();

        const isEdit = mode === 'edit';
        const modal = document.createElement('div');
        modal.className = 'admin-modal-overlay';
        modal.innerHTML = `
            <div class="admin-modal">
                <div class="admin-modal-header">
                    <h3>${isEdit ? 'Edit Prison Region' : 'Add Prison Region'}</h3>
                    <button class="admin-modal-close" aria-label="Close">&times;</button>
                </div>
                <form class="admin-modal-body" id="prisonRegionForm">
                    <div class="form-grid">
                        <div class="form-field form-span">
                            <label>Prison Region</label>
                            <input type="text" name="priRegion" value="${this.escapeHtml(region.priRegion || '')}" required placeholder="Region name">
                        </div>
                    </div>
                    <div class="admin-modal-footer">
                        <button type="button" class="action-btn secondary" data-action="cancel">Cancel</button>
                        <button type="submit" class="action-btn">${isEdit ? 'Save Changes' : 'Add Region'}</button>
                    </div>
                </form>
            </div>
        `;

        document.body.appendChild(modal);

        const closeModal = () => modal.remove();
        modal.querySelector('.admin-modal-close')?.addEventListener('click', closeModal);
        modal.querySelector('[data-action="cancel"]')?.addEventListener('click', closeModal);
        modal.addEventListener('click', (event) => {
            if (event.target === modal) closeModal();
        });

        modal.querySelector('#prisonRegionForm')?.addEventListener('submit', (event) => {
            event.preventDefault();
            this.submitPrisonRegionForm(mode, event.target, region.region_id);
        });
    }

    async submitPrisonRegionForm(mode, form, regionId) {
        const payload = {
            priRegion: form.priRegion.value.trim()
        };

        if (!payload.priRegion) {
            this.showNotification('Region name is required.', 'error');
            return;
        }

        let url = '../backend/api/add_priregion.php';
        if (mode === 'edit') {
            payload.region_id = regionId;
            url = '../backend/api/update_priregion.php';
        }

        try {
            const response = await fetch(url, {
                method: 'POST',
                credentials: 'include',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload)
            });
            const data = await this.safeJson(response, { success: false });
            if (!data.success) {
                this.showNotification(data.message || 'Unable to save region.', 'error');
                return;
            }
            this.showNotification(data.message || 'Region saved.', 'success');
            document.querySelector('.admin-modal-overlay')?.remove();
            await this.loadPrisonRegionSettings();
        } catch (error) {
            console.error('Prison region save error:', error);
            this.showNotification('Unable to save region.', 'error');
        }
    }

    confirmPrisonRegionDelete(region) {
        const existing = document.querySelector('.admin-confirm-overlay');
        if (existing) existing.remove();

        const overlay = document.createElement('div');
        overlay.className = 'admin-confirm-overlay';
        overlay.innerHTML = `
            <div class="admin-confirm-modal">
                <h3>Delete Prison Region</h3>
                <p>Remove <strong>${this.escapeHtml(region.priRegion || 'this region')}</strong>? This action cannot be undone.</p>
                <div class="admin-modal-footer">
                    <button class="action-btn secondary" data-action="cancel">Cancel</button>
                    <button class="action-btn danger" data-action="confirm">Delete</button>
                </div>
            </div>
        `;

        document.body.appendChild(overlay);
        const close = () => overlay.remove();
        overlay.querySelector('[data-action="cancel"]')?.addEventListener('click', close);
        overlay.querySelector('[data-action="confirm"]')?.addEventListener('click', async () => {
            await this.deletePrisonRegion(region.region_id);
            close();
        });
        overlay.addEventListener('click', (event) => {
            if (event.target === overlay) close();
        });
    }

    async deletePrisonRegion(regionId) {
        try {
            const response = await fetch('../backend/api/delete_priregion.php', {
                method: 'POST',
                credentials: 'include',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ region_id: regionId })
            });
            const data = await this.safeJson(response, { success: false });
            if (!data.success) {
                this.showNotification(data.message || 'Unable to delete region.', 'error');
                return;
            }
            this.showNotification(data.message || 'Region deleted.', 'success');
            await this.loadPrisonRegionSettings();
        } catch (error) {
            console.error('Delete prison region error:', error);
            this.showNotification('Unable to delete region.', 'error');
        }
    }

    async loadPoliticalDistrictSettings() {
        const tableBody = document.getElementById('politicalDistrictTableBody');
        if (!tableBody) return;

        tableBody.innerHTML = `
            <tr><td colspan="3"><div class="table-loading">Loading districts...</div></td></tr>
        `;

        try {
            const response = await fetch('../backend/api/get_poldistricts_admin.php', {
                credentials: 'include',
                cache: 'no-store'
            });
            const data = await this.safeJson(response, { success: false, districts: [] });

            if (!data.success) {
                tableBody.innerHTML = `
                    <tr><td colspan="3"><div class="table-empty">Unable to load districts.</div></td></tr>
                `;
                return;
            }

            this.politicalDistrictSettings.districts = data.districts || [];
            this.populatePoliticalDistrictFilters();
            this.applyPoliticalDistrictFilters();
        } catch (error) {
            console.error('Error loading political districts:', error);
            tableBody.innerHTML = `
                <tr><td colspan="3"><div class="table-empty">Failed to load districts.</div></td></tr>
            `;
        }
    }

    populatePoliticalDistrictFilters() {
        const regionFilter = document.getElementById('politicalDistrictRegionFilter');
        if (!regionFilter) return;
        const current = regionFilter.value;
        const regions = Array.from(new Set(this.politicalDistrictSettings.districts.map(d => d.polRegion).filter(Boolean))).sort();
        const defaultOptions = ['Northern', 'Eastern', 'Central', 'Western'];
        const allRegions = Array.from(new Set([...defaultOptions, ...regions])).filter(Boolean);

        regionFilter.innerHTML = `
            <option value="">All Regions</option>
            ${allRegions.map(region => `<option value="${this.escapeHtml(region)}">${this.escapeHtml(region)}</option>`).join('')}
        `;

        if (current) {
            regionFilter.value = allRegions.includes(current) ? current : '';
        }
    }

    applyPoliticalDistrictFilters() {
        const searchValue = (document.getElementById('politicalDistrictSearchInput')?.value || '').toLowerCase();
        const regionValue = document.getElementById('politicalDistrictRegionFilter')?.value || '';

        this.politicalDistrictSettings.filtered = this.politicalDistrictSettings.districts.filter((district) => {
            const composite = `${district.polDistrict || ''} ${district.polRegion || ''}`.toLowerCase();
            const matchesSearch = searchValue ? composite.includes(searchValue) : true;
            const matchesRegion = regionValue ? district.polRegion === regionValue : true;
            return matchesSearch && matchesRegion;
        });

        this.renderPoliticalDistrictTable();
        this.updatePoliticalDistrictSummary();
    }

    updatePoliticalDistrictSummary() {
        const total = this.politicalDistrictSettings.districts.length;
        const regions = new Set(this.politicalDistrictSettings.districts.map(d => d.polRegion).filter(Boolean));
        const totalEl = document.getElementById('politicalDistrictSummaryTotal');
        const regionEl = document.getElementById('politicalDistrictSummaryRegions');
        if (totalEl) totalEl.textContent = total;
        if (regionEl) regionEl.textContent = regions.size;
    }

    renderPoliticalDistrictTable() {
        const tableBody = document.getElementById('politicalDistrictTableBody');
        if (!tableBody) return;

        if (this.politicalDistrictSettings.filtered.length === 0) {
            tableBody.innerHTML = `
                <tr><td colspan="4"><div class="table-empty">No districts match the current filters.</div></td></tr>
            `;
            return;
        }

        tableBody.innerHTML = this.politicalDistrictSettings.filtered.map((district) => `
            <tr>
                <td>${this.escapeHtml(district.polDistrict || 'Unknown')}</td>
                <td>${this.escapeHtml(district.polRegion || 'N/A')}</td>
                <td>${this.renderUnitCountLink(district.unit_count, 'polDistrict', district.polDistrict, `Political District: ${district.polDistrict}`)}</td>
                <td>
                    <div class="user-actions">
                        <button class="user-action-btn" data-action="edit" data-id="${district.pol_id}">Edit</button>
                        <button class="user-action-btn danger" data-action="delete" data-id="${district.pol_id}" ${district.unit_count > 0 ? 'disabled title="Remove linked units first."' : ''}>Delete</button>
                    </div>
                </td>
            </tr>
        `).join('');
    }

    openPoliticalDistrictModal(mode, district = {}) {
        const existing = document.querySelector('.admin-modal-overlay');
        if (existing) existing.remove();

        const isEdit = mode === 'edit';
        const defaultRegions = ['Northern', 'Eastern', 'Central', 'Western'];
        const currentRegion = district.polRegion || '';
        const regionOptions = Array.from(new Set([currentRegion, ...defaultRegions].filter(Boolean)));

        const modal = document.createElement('div');
        modal.className = 'admin-modal-overlay';
        modal.innerHTML = `
            <div class="admin-modal">
                <div class="admin-modal-header">
                    <h3>${isEdit ? 'Edit Political District' : 'Add Political District'}</h3>
                    <button class="admin-modal-close" aria-label="Close">&times;</button>
                </div>
                <form class="admin-modal-body" id="politicalDistrictForm">
                    <div class="form-grid">
                        <div class="form-field form-span">
                            <label>Political District</label>
                            <input type="text" name="polDistrict" value="${this.escapeHtml(district.polDistrict || '')}" required placeholder="District name">
                        </div>
                        <div class="form-field form-span">
                            <label>Political Region</label>
                            <select name="polRegion" required>
                                <option value="">Select region</option>
                                ${regionOptions.map(region => `<option value="${this.escapeHtml(region)}" ${region === currentRegion ? 'selected' : ''}>${this.escapeHtml(region)}</option>`).join('')}
                            </select>
                        </div>
                    </div>
                    <div class="admin-modal-footer">
                        <button type="button" class="action-btn secondary" data-action="cancel">Cancel</button>
                        <button type="submit" class="action-btn">${isEdit ? 'Save Changes' : 'Add District'}</button>
                    </div>
                </form>
            </div>
        `;

        document.body.appendChild(modal);

        const closeModal = () => modal.remove();
        modal.querySelector('.admin-modal-close')?.addEventListener('click', closeModal);
        modal.querySelector('[data-action="cancel"]')?.addEventListener('click', closeModal);
        modal.addEventListener('click', (event) => {
            if (event.target === modal) closeModal();
        });

        modal.querySelector('#politicalDistrictForm')?.addEventListener('submit', (event) => {
            event.preventDefault();
            this.submitPoliticalDistrictForm(mode, event.target, district.pol_id);
        });
    }

    async submitPoliticalDistrictForm(mode, form, districtId) {
        const payload = {
            polDistrict: form.polDistrict.value.trim(),
            polRegion: form.polRegion.value.trim()
        };

        if (!payload.polDistrict || !payload.polRegion) {
            this.showNotification('District and region are required.', 'error');
            return;
        }

        let url = '../backend/api/add_poldistrict.php';
        if (mode === 'edit') {
            payload.pol_id = districtId;
            url = '../backend/api/update_poldistrict.php';
        }

        try {
            const response = await fetch(url, {
                method: 'POST',
                credentials: 'include',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload)
            });
            const data = await this.safeJson(response, { success: false });
            if (!data.success) {
                this.showNotification(data.message || 'Unable to save political district.', 'error');
                return;
            }
            this.showNotification(data.message || 'Political district saved.', 'success');
            document.querySelector('.admin-modal-overlay')?.remove();
            await this.loadPoliticalDistrictSettings();
        } catch (error) {
            console.error('Political district save error:', error);
            this.showNotification('Unable to save political district.', 'error');
        }
    }

    confirmPoliticalDistrictDelete(district) {
        const existing = document.querySelector('.admin-confirm-overlay');
        if (existing) existing.remove();

        const overlay = document.createElement('div');
        overlay.className = 'admin-confirm-overlay';
        overlay.innerHTML = `
            <div class="admin-confirm-modal">
                <h3>Delete Political District</h3>
                <p>Remove <strong>${this.escapeHtml(district.polDistrict || 'this district')}</strong>? This action cannot be undone.</p>
                <div class="admin-modal-footer">
                    <button class="action-btn secondary" data-action="cancel">Cancel</button>
                    <button class="action-btn danger" data-action="confirm">Delete</button>
                </div>
            </div>
        `;

        document.body.appendChild(overlay);
        const close = () => overlay.remove();
        overlay.querySelector('[data-action="cancel"]')?.addEventListener('click', close);
        overlay.querySelector('[data-action="confirm"]')?.addEventListener('click', async () => {
            await this.deletePoliticalDistrict(district.pol_id);
            close();
        });
        overlay.addEventListener('click', (event) => {
            if (event.target === overlay) close();
        });
    }

    async deletePoliticalDistrict(districtId) {
        try {
            const response = await fetch('../backend/api/delete_poldistrict.php', {
                method: 'POST',
                credentials: 'include',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ pol_id: districtId })
            });
            const data = await this.safeJson(response, { success: false });
            if (!data.success) {
                this.showNotification(data.message || 'Unable to delete political district.', 'error');
                return;
            }
            this.showNotification(data.message || 'Political district deleted.', 'success');
            await this.loadPoliticalDistrictSettings();
        } catch (error) {
            console.error('Delete political district error:', error);
            this.showNotification('Unable to delete political district.', 'error');
        }
    }

    resolveUserPhoto(photoPath) {
        const defaultPhoto = '../backend/uploads/profiles/default-user.png';
        if (!photoPath) {
            return { primary: defaultPhoto, fallbacks: [defaultPhoto, 'images/default-user.png'] };
        }

        if (photoPath.startsWith('http://') || photoPath.startsWith('https://')) {
            return { primary: photoPath, fallbacks: [defaultPhoto, 'images/default-user.png'] };
        }

        const cleanPath = this.cleanImagePath(photoPath);
        const fallbacks = this.getImagePaths(cleanPath);
        return {
            primary: fallbacks[0] || defaultPhoto,
            fallbacks: fallbacks.length ? fallbacks : [defaultPhoto, 'images/default-user.png']
        };
    }

    bindUserAvatarFallbacks() {
        document.querySelectorAll('.user-avatar').forEach(img => {
            img.addEventListener('error', () => {
                const fallbacks = (img.dataset.fallbacks || '').split('|').filter(Boolean);
                if (fallbacks.length <= 1) {
                    img.src = 'images/default-user.png';
                    return;
                }
                const next = fallbacks.shift();
                img.dataset.fallbacks = fallbacks.join('|');
                img.src = next;
            }, { once: true });
        });
    }

    updateRoleCaches(roleLabels = {}, roles = []) {
        if (!this.roleLabelMap || typeof this.roleLabelMap !== 'object') {
            this.roleLabelMap = {};
        }

        if (roleLabels && typeof roleLabels === 'object') {
            Object.entries(roleLabels).forEach(([key, label]) => {
                const normalized = String(key || '').toLowerCase().trim();
                if (!normalized) return;
                this.roleLabelMap[normalized] = String(label || '').trim() || this.formatRoleLabel(normalized);
            });
        }

        if (Array.isArray(roles)) {
            this.availableRoles = roles
                .map((role) => {
                    const key = String(role?.role_key || role?.roleKey || '').toLowerCase().trim();
                    if (!key) return null;
                    const label = String(role?.role_label || role?.roleLabel || this.roleLabelMap[key] || '').trim();
                    const isActive = role?.is_active !== false;
                    return {
                        role_key: key,
                        role_label: label || this.formatRoleLabel(key),
                        role_description: String(role?.role_description || role?.roleDescription || ''),
                        is_active: Boolean(isActive),
                        is_system: Boolean(role?.is_system)
                    };
                })
                .filter(Boolean);

            this.availableRoles.forEach((role) => {
                this.roleLabelMap[role.role_key] = role.role_label;
            });
        }
    }

    async syncRoleCacheFromApi() {
        try {
            const response = await fetch('../backend/api/get_roles.php?active_only=0', {
                credentials: 'include',
                cache: 'no-store'
            });
            const data = await this.safeJson(response, { success: false, roles: [] });
            if (!response.ok || !data.success) return;
            this.updateRoleCaches(data.role_labels || {}, data.roles || []);
            this.populateUserRoleFilterOptions();
        } catch (error) {
            console.warn('Unable to refresh role cache:', error?.message || error);
        }
    }

    populateUserRoleFilterOptions() {
        const roleFilter = document.getElementById('userRoleFilter');
        if (!roleFilter) return;

        const currentValue = roleFilter.value || '';
        const roles = Array.isArray(this.availableRoles) && this.availableRoles.length
            ? this.availableRoles
            : Object.keys(this.roleLabelMap || {}).map((key) => ({
                role_key: key,
                role_label: this.roleLabelMap[key] || this.formatRoleLabel(key),
                is_active: true
            }));

        const options = roles
            .filter((role) => Boolean(role?.role_key))
            .sort((a, b) => String(a.role_label || '').localeCompare(String(b.role_label || '')))
            .map((role) => {
                const roleKey = String(role.role_key || '');
                const roleLabel = this.escapeHtml(String(role.role_label || this.formatRoleLabel(roleKey)));
                return `<option value="${this.escapeHtml(roleKey)}">${roleLabel}</option>`;
            })
            .join('');

        roleFilter.innerHTML = `<option value="">All Roles</option>${options}`;
        if (currentValue) {
            const hasOption = Array.from(roleFilter.options).some((option) => option.value === currentValue);
            if (hasOption) {
                roleFilter.value = currentValue;
            }
        }
    }

    async populateDefaultUserRoleSelect() {
        const select = document.querySelector('#appSettingsForm select[name="default_user_role"]');
        if (!select) return;

        await this.syncRoleCacheFromApi();

        const existingValue = String(select.value || '').trim().toLowerCase();
        const roles = this.getUserRoleOptions();

        if (!roles.length) {
            select.innerHTML = '<option value="user">User</option>';
            return;
        }

        select.innerHTML = roles
            .map((role) => `<option value="${this.escapeHtml(role.role_key)}">${this.escapeHtml(role.role_label)}</option>`)
            .join('');

        if (existingValue) {
            const has = Array.from(select.options).some((option) => option.value === existingValue);
            if (has) {
                select.value = existingValue;
            }
        }
    }

    async populateAuditRoleFilterOptions() {
        const select = document.getElementById('auditRoleFilter');
        if (!select) return;

        await this.syncRoleCacheFromApi();

        const existingValue = String(select.value || '').trim().toLowerCase();
        const roleOptions = this.getUserRoleOptions();
        const dynamicOptions = roleOptions
            .map((role) => `<option value="${this.escapeHtml(role.role_key)}">${this.escapeHtml(role.role_label)}</option>`)
            .join('');

        select.innerHTML = `<option value="">All Roles</option>${dynamicOptions}<option value="system">System</option>`;

        if (existingValue) {
            const has = Array.from(select.options).some((option) => option.value === existingValue);
            if (has) {
                select.value = existingValue;
            }
        }
    }

    getUserRoleOptions(ensureRoleKey = '') {
        const roles = Array.isArray(this.availableRoles) && this.availableRoles.length
            ? this.availableRoles
            : Object.keys(this.roleLabelMap || {}).map((key) => ({
                role_key: key,
                role_label: this.roleLabelMap[key] || this.formatRoleLabel(key),
                is_active: true
            }));

        const normalized = roles
            .filter((role) => Boolean(role?.role_key))
            .map((role) => ({
                role_key: String(role.role_key || '').toLowerCase().trim(),
                role_label: String(role.role_label || this.formatRoleLabel(role.role_key || '')).trim(),
                is_active: role?.is_active !== false
            }))
            .filter((role) => role.role_key !== '' && role.is_active);

        let resolvedRoles = normalized;
        if (!resolvedRoles.length) {
            resolvedRoles = [
                { role_key: 'admin', role_label: 'Administrator' },
                { role_key: 'clerk', role_label: 'Clerk' },
                { role_key: 'oc_pen', role_label: 'OC/Pension' },
                { role_key: 'writeup_officer', role_label: 'Writeup Officer' },
                { role_key: 'file_creator', role_label: 'File Creator' },
                { role_key: 'data_entry', role_label: 'Data Entrant' },
                { role_key: 'assessor', role_label: 'Assessor' },
                { role_key: 'auditor', role_label: 'Auditor' },
                { role_key: 'approver', role_label: 'Approver' },
                { role_key: 'user', role_label: 'User' },
                { role_key: 'pensioner', role_label: 'Pensioner' }
            ];
        }

        const ensureKey = String(ensureRoleKey || '').toLowerCase().trim();
        if (ensureKey && !resolvedRoles.some((role) => role.role_key === ensureKey)) {
            resolvedRoles.push({
                role_key: ensureKey,
                role_label: this.formatRoleLabel(ensureKey),
                is_active: false
            });
        }

        const actor = this.getCurrentAdminUserContext();
        return resolvedRoles
            .filter((role) => {
                const key = String(role.role_key || '').toLowerCase();
                if (key === 'super_admin') return actor.isSuperAdmin || ensureKey === 'super_admin';
                if (key === 'admin') return actor.isSuperAdmin || ensureKey === 'admin';
                return true;
            })
            .sort((a, b) => a.role_label.localeCompare(b.role_label));
    }

    async loadLiveChatSettings(showNotification = false) {
        const form = document.getElementById('liveChatSettingsForm');
        if (!form) return;
        try {
            this.updateSettingsStatus('liveChat', 'Loading...', 'info');
            const data = await this.fetchAppSettingsBundle(showNotification);
            this.applySettingsToForm(form, data.settings || {});
            this.updateSettingsStatus('liveChat', 'Up to date', 'success');
            if (showNotification) this.showNotification('Changes reverted to last saved live chat settings.', 'info');
        } catch (error) {
            console.error('Load live chat settings error:', error);
            this.updateSettingsStatus('liveChat', 'Failed to load', 'error');
            this.showNotification('Unable to load live chat settings.', 'error');
        }
    }

    formatRoleLabel(role) {
        const staticMap = {
            super_admin: 'Super Administrator',
            admin: 'Administrator',
            clerk: 'Clerk',
            oc_pen: 'OC/Pension',
            writeup_officer: 'Writeup Officer',
            file_creator: 'File Creator',
            data_entry: 'Data Entrant',
            assessor: 'Assessor',
            auditor: 'Auditor',
            approver: 'Approver',
            user: 'User',
            pensioner: 'Pensioner'
        };
        const normalized = String(role || '').toLowerCase().trim();
        if (!normalized) {
            return 'User';
        }
        if (this.roleLabelMap && this.roleLabelMap[normalized]) {
            return this.roleLabelMap[normalized];
        }
        return staticMap[normalized] || normalized.replace(/_/g, ' ').replace(/\b\w/g, (m) => m.toUpperCase());
    }

    formatTitleCategory(category) {
        const map = {
            uniformed: 'Uniformed',
            non_uniformed: 'Non-Uniformed'
        };
        return map[category] || category || 'Unknown';
    }

    formatTitleLevel(level) {
        const map = {
            junior: 'Junior',
            senior: 'Senior'
        };
        return map[level] || level || 'Unknown';
    }

    getOfficialUserTitles() {
        return ['Mr.', 'Mrs.', 'Ms.', 'Miss', 'Dr.', 'Prof.', 'Hon.', 'Rev.', 'Fr.', 'Sr.'];
    }

    getCurrentAdminUserContext() {
        const stored = JSON.parse(localStorage.getItem('loggedInUser') || '{}');
        const rawRole = String(
            sessionStorage.getItem('userRole')
            || localStorage.getItem('userRole')
            || stored.role
            || ''
        ).toLowerCase();
        const effectiveRole = String(
            sessionStorage.getItem('userRoleEffective')
            || localStorage.getItem('userRoleEffective')
            || stored.effectiveRole
            || rawRole
            || ''
        ).toLowerCase();
        return {
            id: stored.id || sessionStorage.getItem('userId') || '',
            rawRole,
            effectiveRole,
            isAdmin: effectiveRole === 'admin' || rawRole === 'super_admin',
            isSuperAdmin: rawRole === 'super_admin'
        };
    }

    isAdminAccountRole(role) {
        return ['admin', 'super_admin'].includes(String(role || '').toLowerCase());
    }

    canManageUserAccount(user = {}) {
        const actor = this.getCurrentAdminUserContext();
        if (!actor.isAdmin) return false;
        const targetRole = String(user.userRole || '').toLowerCase();
        if (!this.isAdminAccountRole(targetRole)) return true;
        return actor.isSuperAdmin;
    }

    canDeleteUserAccount(user = {}) {
        const actor = this.getCurrentAdminUserContext();
        if (!this.canManageUserAccount(user)) return false;
        return String(user.userId || '') !== String(actor.id || '');
    }

    canToggleUserAccountStatus(user = {}) {
        const actor = this.getCurrentAdminUserContext();
        if (!actor.isAdmin) return false;
        if (String(user.userId || '') === String(actor.id || '') && user.is_active !== false) return false;
        const targetRole = String(user.userRole || '').toLowerCase();
        if (this.isAdminAccountRole(targetRole)) return actor.isSuperAdmin;
        return true;
    }

    openUserModal(mode, user = {}) {
        if (mode === 'edit' && !this.canManageUserAccount(user)) {
            this.showNotification('Only the super administrator can modify administrator accounts.', 'error');
            return;
        }
        const existing = document.querySelector('.admin-modal-overlay');
        if (existing) existing.remove();

        const isEdit = mode === 'edit';
        const roleOptions = this.getUserRoleOptions(user.userRole || '');
        const titleOptions = this.getOfficialUserTitles();
        const selectedTitle = String(user.userTitle || '').trim();
        const hasSelectedTitle = selectedTitle && titleOptions.includes(selectedTitle);
        const modal = document.createElement('div');
        modal.className = 'admin-modal-overlay';
        modal.innerHTML = `
            <div class="admin-modal">
                <div class="admin-modal-header">
                    <h3>${isEdit ? 'Edit User' : 'Add New User'}</h3>
                    <button class="admin-modal-close" aria-label="Close">&times;</button>
                </div>
                <form class="admin-modal-body" id="userManagementForm">
                    <div class="form-grid">
                        <div class="form-field">
                            <label>Title</label>
                            <select name="userTitle" required>
                                <option value="">Select Title</option>
                                ${titleOptions.map((title) => `
                                    <option value="${this.escapeHtml(title)}" ${selectedTitle === title ? 'selected' : ''}>
                                        ${this.escapeHtml(title)}
                                    </option>
                                `).join('')}
                                ${selectedTitle && !hasSelectedTitle ? `<option value="${this.escapeHtml(selectedTitle)}" selected>${this.escapeHtml(selectedTitle)}</option>` : ''}
                            </select>
                        </div>
                        <div class="form-field">
                            <label>Full Name</label>
                            <input type="text" name="userName" value="${this.escapeHtml(user.userName || '')}" placeholder="Full name" required>
                        </div>
                        <div class="form-field">
                            <label>Email Address</label>
                            <input type="email" name="userEmail" value="${this.escapeHtml(user.userEmail || '')}" placeholder="name@example.com" required>
                        </div>
                        <div class="form-field">
                            <label>Phone Number</label>
                            <input type="tel" name="phoneNo" value="${this.escapeHtml(user.phoneNo || '')}" placeholder="+2567..." required>
                        </div>
                        <div class="form-field">
                            <label>Role</label>
                            <select name="userRole" required>
                                ${roleOptions.map((role) => `
                                    <option value="${this.escapeHtml(role.role_key)}" ${user.userRole === role.role_key ? 'selected' : ''}>
                                        ${this.escapeHtml(role.role_label)}
                                    </option>
                                `).join('')}
                            </select>
                        </div>
                        <div class="form-field">
                            <label>${isEdit ? 'New Password (optional)' : 'Password'}</label>
                            <input type="password" name="${isEdit ? 'newPassword' : 'userPassword'}" ${isEdit ? '' : 'required'} placeholder="At least 6 characters">
                            ${!isEdit ? '<small class="field-help">Must include uppercase, lowercase, and a number.</small>' : ''}
                        </div>
                        <div class="form-field form-span">
                            <label>Profile Photo (optional)</label>
                            <input type="file" name="${isEdit ? 'profilePicture' : 'userPhoto'}" accept="image/*">
                        </div>
                    </div>
                    <div class="admin-modal-footer">
                        <button type="button" class="action-btn secondary" data-action="cancel">Cancel</button>
                        <button type="submit" class="action-btn">${isEdit ? 'Save Changes' : 'Create User'}</button>
                    </div>
                </form>
            </div>
        `;

        document.body.appendChild(modal);

        const closeModal = () => modal.remove();

        modal.querySelector('.admin-modal-close').addEventListener('click', closeModal);
        modal.querySelector('[data-action="cancel"]').addEventListener('click', closeModal);
        modal.addEventListener('click', (e) => {
            if (e.target === modal) closeModal();
        });

        modal.querySelector('#userManagementForm').addEventListener('submit', (e) => {
            e.preventDefault();
            this.submitUserForm(mode, e.target, user.userId);
        });
    }

    async submitUserForm(mode, form, userId) {
        const formData = new FormData(form);
        let url = '';

        if (mode === 'edit') {
            formData.append('userId', userId);
            url = '../backend/api/update_user.php';
        } else {
            url = '../backend/api/register_user.php';
        }

        try {
            const response = await fetch(url, {
                method: 'POST',
                credentials: 'include',
                body: formData
            });

            const data = await this.safeJson(response, { success: false });
            if (!data.success) {
                this.showNotification(data.message || 'Action failed', 'error');
                return;
            }

            this.showNotification(data.message || 'User saved successfully', 'success');
            document.querySelector('.admin-modal-overlay')?.remove();
            await this.loadUserManagementUsers();
        } catch (error) {
            console.error('User form error:', error);
            this.showNotification('Unable to save user. Please try again.', 'error');
        }
    }

    confirmToggleUserStatus(user) {
        if (!this.canToggleUserAccountStatus(user)) {
            this.showNotification('Only the super administrator can activate or deactivate administrator accounts.', 'error');
            return;
        }

        document.querySelector('.admin-confirm-overlay.user-status-confirm-overlay')?.remove();

        const isActive = user.is_active !== false;
        const nextActive = !isActive;
        const actionLabel = nextActive ? 'Activate' : 'Deactivate';
        const userName = user.userName || 'this user';
        const roleLabel = this.formatRoleLabel(user.userRole || 'user');
        const statusCopy = nextActive
            ? 'This user will be able to sign in again, subject to the global staff or pensioner login settings.'
            : 'This user will be blocked from signing in and any active sessions for this account will be ended.';

        const overlay = document.createElement('div');
        overlay.className = 'admin-confirm-overlay user-status-confirm-overlay';
        overlay.innerHTML = `
            <div class="admin-confirm-modal">
                <h3>${actionLabel} User Account</h3>
                <p>
                    Are you sure you want to ${nextActive ? 'activate' : 'deactivate'}
                    <strong>${this.escapeHtml(userName)}</strong>?
                </p>
                <div class="settings-definition-list">
                    <div class="settings-definition-row">
                        <span>Role</span>
                        <strong>${this.escapeHtml(roleLabel)}</strong>
                    </div>
                    <div class="settings-definition-row">
                        <span>Current Status</span>
                        <strong>${isActive ? 'Active' : 'Inactive'}</strong>
                    </div>
                    <div class="settings-definition-row">
                        <span>New Status</span>
                        <strong>${nextActive ? 'Active' : 'Inactive'}</strong>
                    </div>
                </div>
                <p class="modal-hint">${statusCopy}</p>
                <div class="admin-modal-footer">
                    <button class="action-btn secondary" type="button" data-action="cancel">Cancel</button>
                    <button class="action-btn ${nextActive ? '' : 'danger'}" type="button" data-action="confirm">${actionLabel} Account</button>
                </div>
            </div>
        `;

        document.body.appendChild(overlay);

        const close = () => overlay.remove();
        overlay.querySelector('[data-action="cancel"]')?.addEventListener('click', close);
        overlay.querySelector('[data-action="confirm"]')?.addEventListener('click', async (event) => {
            const button = event.currentTarget;
            button.disabled = true;
            button.textContent = nextActive ? 'Activating...' : 'Deactivating...';
            const result = await this.updateUserAccountStatus(user.userId, nextActive);
            if (result?.success) {
                close();
                this.showNotification(result.message || 'Account status updated.', 'success');
            } else {
                button.disabled = false;
                button.textContent = `${actionLabel} Account`;
            }
        });
        overlay.addEventListener('click', (event) => {
            if (event.target === overlay) close();
        });
    }

    async updateUserAccountStatus(userId, isActive) {
        try {
            const response = await this.performSensitiveAdminRequest('../backend/api/update_user_status.php', {
                method: 'POST',
                credentials: 'include',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ userId, is_active: isActive })
            }, isActive ? 'activate user account' : 'deactivate user account');

            const data = response.__adminPayloadAttached || await this.safeJson(response, { success: false });
            if (!response.ok || !data.success) {
                this.showNotification(data.message || 'Unable to update account status.', 'error');
                return { success: false };
            }

            await this.loadUserManagementUsers();
            return {
                success: true,
                message: data.message || 'Account status updated.'
            };
        } catch (error) {
            console.error('User status update error:', error);
            this.showNotification(error.message || 'Unable to update account status.', 'error');
            return { success: false };
        }
    }

    confirmUserDelete(user) {
        if (!this.canDeleteUserAccount(user)) {
            this.showNotification('Only the super administrator can delete administrator accounts.', 'error');
            return;
        }
        const existing = document.querySelector('.admin-confirm-overlay');
        if (existing) existing.remove();

        const overlay = document.createElement('div');
        overlay.className = 'admin-confirm-overlay';
        overlay.innerHTML = `
            <div class="admin-confirm-modal">
                <h3>Delete User</h3>
                <p>Are you sure you want to remove <strong>${this.escapeHtml(user.userName || 'this user')}</strong>? This action cannot be undone.</p>
                <div class="admin-modal-footer">
                    <button class="action-btn secondary" data-action="cancel">Cancel</button>
                    <button class="action-btn danger" data-action="confirm">Delete User</button>
                </div>
            </div>
        `;

        document.body.appendChild(overlay);

        const close = () => overlay.remove();
        overlay.querySelector('[data-action="cancel"]').addEventListener('click', close);
        overlay.querySelector('[data-action="confirm"]').addEventListener('click', async () => {
            await this.deleteUser(user.userId);
            close();
        });
        overlay.addEventListener('click', (e) => {
            if (e.target === overlay) close();
        });
    }

    confirmBulkDelete() {
        if (this.selectedUserIds.size === 0) {
            this.showNotification('Select at least one user to delete.', 'info');
            return;
        }

        const overlay = document.createElement('div');
        overlay.className = 'admin-confirm-overlay';
        overlay.innerHTML = `
            <div class="admin-confirm-modal">
                <h3>Delete Selected Users</h3>
                <p>You are about to delete <strong>${this.selectedUserIds.size}</strong> user account(s). This cannot be undone.</p>
                <label class="confirm-checkbox">
                    <input type="checkbox" id="bulkDeleteAcknowledge">
                    I understand this will permanently delete the selected users.
                </label>
                <div class="admin-modal-footer">
                    <button class="action-btn secondary" data-action="cancel">Cancel</button>
                    <button class="action-btn danger" data-action="confirm" disabled>Delete Selected</button>
                </div>
            </div>
        `;

        document.body.appendChild(overlay);

        const close = () => overlay.remove();
        overlay.querySelector('[data-action="cancel"]').addEventListener('click', close);
        const confirmBtn = overlay.querySelector('[data-action="confirm"]');
        const acknowledge = overlay.querySelector('#bulkDeleteAcknowledge');

        if (acknowledge && confirmBtn) {
            acknowledge.addEventListener('change', () => {
                confirmBtn.disabled = !acknowledge.checked;
            });
        }

        confirmBtn.addEventListener('click', async () => {
            if (confirmBtn.disabled) return;
            await this.bulkDeleteUsers();
            close();
        });
        overlay.addEventListener('click', (e) => {
            if (e.target === overlay) close();
        });
    }

    async bulkDeleteUsers() {
        const ids = Array.from(this.selectedUserIds);
        if (ids.length === 0) return;

        let deleted = 0;
        for (const userId of ids) {
            try {
                await this.deleteUser(userId);
                deleted += 1;
            } catch (error) {
                console.error('Bulk delete error:', error);
            }
        }

        this.selectedUserIds.clear();
        this.updateBulkActionsBar();
        await this.loadUserManagementUsers();

        if (deleted > 0) {
            this.showNotification(`Deleted ${deleted} user(s).`, 'success');
        }
    }

    async deleteUser(userId) {
        try {
            const response = await fetch('../backend/api/delete_user.php', {
                method: 'POST',
                credentials: 'include',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ userId })
            });
            const data = await this.safeJson(response, { success: false });
            if (!data.success) {
                this.showNotification(data.message || 'Failed to delete user', 'error');
                throw new Error(data.message || 'Failed to delete user');
            }
            this.showNotification(data.message || 'User deleted', 'success');
        } catch (error) {
            console.error('Delete user error:', error);
            this.showNotification('Unable to delete user.', 'error');
            throw error;
        }
    }

    openResetPasswordModal(user) {
        if (!this.canManageUserAccount(user)) {
            this.showNotification('Only the super administrator can reset administrator account passwords.', 'error');
            return;
        }
        const existing = document.querySelector('.admin-modal-overlay');
        if (existing) existing.remove();

        const modal = document.createElement('div');
        modal.className = 'admin-modal-overlay';
        modal.innerHTML = `
            <div class="admin-modal">
                <div class="admin-modal-header">
                    <h3>Reset Password</h3>
                    <button class="admin-modal-close" aria-label="Close">&times;</button>
                </div>
                <form class="admin-modal-body" id="resetPasswordForm">
                    <p class="modal-hint">Reset password for <strong>${this.escapeHtml(user.userName || 'User')}</strong>.</p>
                    <div class="form-field">
                        <label>New Password</label>
                        <input type="password" name="newPassword" required placeholder="At least 6 characters">
                        <small class="field-help">Must include uppercase, lowercase, and a number.</small>
                    </div>
                    <div class="reset-actions">
                        <button type="button" class="action-btn secondary" id="generatePasswordBtn">Generate</button>
                    </div>
                    <div class="admin-modal-footer">
                        <button type="button" class="action-btn secondary" data-action="cancel">Cancel</button>
                        <button type="submit" class="action-btn">Reset Password</button>
                    </div>
                </form>
            </div>
        `;

        document.body.appendChild(modal);

        const closeModal = () => modal.remove();
        modal.querySelector('.admin-modal-close').addEventListener('click', closeModal);
        modal.querySelector('[data-action="cancel"]').addEventListener('click', closeModal);
        modal.addEventListener('click', (e) => {
            if (e.target === modal) closeModal();
        });

        modal.querySelector('#generatePasswordBtn').addEventListener('click', () => {
            const generated = this.generateStrongPassword();
            modal.querySelector('input[name="newPassword"]').value = generated;
        });

        modal.querySelector('#resetPasswordForm').addEventListener('submit', async (e) => {
            e.preventDefault();
            await this.resetUserPassword(user, new FormData(e.target).get('newPassword'));
            closeModal();
        });
    }

    generateStrongPassword() {
        const upper = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ';
        const lower = 'abcdefghijklmnopqrstuvwxyz';
        const digits = '0123456789';
        const special = '!@#$%&*';
        const all = upper + lower + digits + special;

        let password = '';
        password += upper[Math.floor(Math.random() * upper.length)];
        password += lower[Math.floor(Math.random() * lower.length)];
        password += digits[Math.floor(Math.random() * digits.length)];
        password += special[Math.floor(Math.random() * special.length)];

        for (let i = 4; i < 10; i += 1) {
            password += all[Math.floor(Math.random() * all.length)];
        }

        return password.split('').sort(() => 0.5 - Math.random()).join('');
    }

    async resetUserPassword(user, newPassword) {
        if (!newPassword || newPassword.length < 6) {
            this.showNotification('Please enter a stronger password.', 'error');
            return;
        }

        const formData = new FormData();
        formData.append('userId', user.userId);
        formData.append('userTitle', user.userTitle || '');
        formData.append('userName', user.userName || '');
        formData.append('userEmail', user.userEmail || '');
        formData.append('phoneNo', user.phoneNo || '');
        formData.append('userRole', user.userRole || '');
        formData.append('newPassword', newPassword);

        try {
            const response = await fetch('../backend/api/update_user.php', {
                method: 'POST',
                credentials: 'include',
                body: formData
            });
            const data = await this.safeJson(response, { success: false });
            if (!data.success) {
                this.showNotification(data.message || 'Failed to reset password', 'error');
                return;
            }
            this.showNotification('Password reset successfully.', 'success');
        } catch (error) {
            console.error('Reset password error:', error);
            this.showNotification('Unable to reset password.', 'error');
        }
    }

    exportUsers() {
        const dataset = (this.filteredUserManagementUsers && this.filteredUserManagementUsers.length > 0)
            ? this.filteredUserManagementUsers
            : this.userManagementUsers;

        if (!dataset || dataset.length === 0) {
            this.showNotification('No users available to export.', 'info');
            return;
        }

        const headers = ['Name', 'Email', 'Phone', 'Role'];
        const rows = dataset.map(user => ([
            user.userName || '',
            user.userEmail || '',
            user.phoneNo || '',
            this.formatRoleLabel(user.userRole)
        ]));

        const csv = [headers, ...rows]
            .map(row => row.map(value => `"${String(value).replace(/"/g, '""')}"`).join(','))
            .join('\n');

        const blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
        const url = URL.createObjectURL(blob);
        const link = document.createElement('a');
        link.href = url;
        link.download = `users-${new Date().toISOString().split('T')[0]}.csv`;
        document.body.appendChild(link);
        link.click();
        document.body.removeChild(link);
        URL.revokeObjectURL(url);
    }

    // Initialize system health
    async initializeSystemHealth() {
        const refreshBtn = document.getElementById('refreshSystemHealthBtn');
        if (refreshBtn) {
            refreshBtn.addEventListener('click', async () => {
                await this.refreshSystemHealthSection(true);
            });
        }

        const content = document.querySelector('.system-health-content');
        if (content && !content.dataset.healthActionsBound) {
            content.dataset.healthActionsBound = '1';
            content.addEventListener('click', async (event) => {
                const actionBtn = event.target.closest('[data-health-action]');
                if (!actionBtn) {
                    return;
                }
                event.preventDefault();
                await this.handleSystemHealthAction(actionBtn);
            });
        }

        await this.refreshSystemHealthSection(false);
    }

    async refreshSystemHealthSection(showFeedback = false, prefetchedPayload = null) {
        const overview = document.getElementById('systemHealthOverview');
        const metrics = document.getElementById('systemHealthMetrics');
        const checks = document.getElementById('systemHealthChecks');
        const summary = document.getElementById('systemHealthSummary');
        const notes = document.getElementById('systemHealthNotes');
        const alerts = document.getElementById('systemHealthAlerts');

        if (!overview || !metrics || !checks || !summary || !notes || !alerts) {
            return;
        }

        try {
            let data = prefetchedPayload;
            if (!data) {
                const response = await fetch('../backend/api/get_system_status.php', {
                    credentials: 'include',
                    cache: 'no-store'
                });
                data = await this.safeJson(response, { success: false });
                if (!response.ok || !data.success) {
                    throw new Error(data.message || 'Unable to load system diagnostics.');
                }
            }

            const status = String(data.systemHealth?.status || 'warning');
            const message = String(data.systemHealth?.message || 'Status unavailable');
            const detail = String(data.systemHealth?.detail || '');
            const diagnostics = data.diagnostics || {};
            const diagnosticSummary = Array.isArray(data.diagnosticSummary) ? data.diagnosticSummary : [];
            const activeAlerts = Array.isArray(data.alerts) ? data.alerts : [];

            const warningCount = Number(diagnostics.warning_count_1h || 0);
            const activeUsers = Number(data.activeUsers || 0);
            const lastBackup = String(data.lastBackup || 'Never');
            const generatedAt = String(diagnostics.generated_at || 'Unknown');
            const databaseConnected = Boolean(diagnostics.database_connected);

            const memoryUsagePercent = Number(diagnostics.memory_usage_percent || 0);
            const memoryUsageMb = Number(diagnostics.memory_usage_mb || 0);
            const memoryLimitMb = diagnostics.memory_limit_mb !== null && diagnostics.memory_limit_mb !== undefined
                ? Number(diagnostics.memory_limit_mb)
                : null;
            const diskUsedPercent = Number(diagnostics.disk_used_percent || 0);
            const diskFreeGb = diagnostics.disk_free_gb !== null && diagnostics.disk_free_gb !== undefined
                ? Number(diagnostics.disk_free_gb)
                : null;

            const statusLabel = this.formatHealthStatusLabel(status);
            overview.innerHTML = `
                <div class="health-banner health-${this.escapeHtml(status)}">
                    <div class="health-banner-status">${this.escapeHtml(statusLabel)}</div>
                    <div class="health-banner-message">${this.escapeHtml(message)}</div>
                    ${detail ? `<div class="health-banner-detail">${this.escapeHtml(detail)}</div>` : ''}
                    <div class="health-banner-meta">
                        <span>Last backup: ${this.escapeHtml(lastBackup)}</span>
                        <span>Active users: ${this.escapeHtml(String(activeUsers))}</span>
                        <span>Warnings (1h): ${this.escapeHtml(String(warningCount))}</span>
                        <span>Snapshot: ${this.escapeHtml(generatedAt)}</span>
                    </div>
                </div>
            `;

            metrics.innerHTML = [
                this.renderHealthMetric('Memory Utilization', memoryUsagePercent, `${memoryUsageMb.toFixed(1)} MB${memoryLimitMb ? ` / ${memoryLimitMb.toFixed(1)} MB` : ''}`),
                this.renderHealthMetric('Disk Utilization', diskUsedPercent, diskFreeGb !== null ? `${diskFreeGb.toFixed(1)} GB free` : 'Disk free space unavailable'),
                this.renderHealthMetric('Warning Pressure', Math.min(100, warningCount * 10), `${warningCount} warning event(s) in last hour`)
            ].join('');

            checks.innerHTML = `
                <div class="health-check-item ${databaseConnected ? 'pass' : 'fail'}">
                    <span class="check-label">Database Connectivity</span>
                    <span class="check-value">${databaseConnected ? 'Connected' : 'Disconnected'}</span>
                </div>
                <div class="health-check-item ${warningCount > 5 ? 'warn' : 'pass'}">
                    <span class="check-label">Operational Log Signal</span>
                    <span class="check-value">${warningCount > 5 ? 'Elevated' : 'Normal'}</span>
                </div>
                <div class="health-check-item ${lastBackup === 'Never' ? 'warn' : 'pass'}">
                    <span class="check-label">Backup Visibility</span>
                    <span class="check-value">${lastBackup === 'Never' ? 'No recorded backup' : 'Tracked'}</span>
                </div>
            `;

            summary.innerHTML = this.renderSystemHealthSummaryCards(diagnosticSummary);
            notes.innerHTML = this.renderSystemHealthNotes(message, activeAlerts, warningCount, memoryUsagePercent, diskUsedPercent);
            alerts.innerHTML = this.renderSystemHealthAlerts(activeAlerts);

            if (showFeedback) {
                this.showNotification('System health diagnostics refreshed.', 'success');
            }
        } catch (error) {
            console.error('System health refresh error:', error);
            overview.innerHTML = `
                <div class="health-banner health-warning">
                    <div class="health-banner-status">Unavailable</div>
                    <div class="health-banner-message">Unable to fetch system diagnostics.</div>
                </div>
            `;
            metrics.innerHTML = '<div class="widget-empty">Metrics unavailable.</div>';
            checks.innerHTML = '<div class="widget-empty">Checks unavailable.</div>';
            summary.innerHTML = '<div class="widget-empty">Summary unavailable.</div>';
            notes.innerHTML = '<div class="widget-empty">Retry using Refresh Health.</div>';
            alerts.innerHTML = '<div class="widget-empty">Alerts unavailable.</div>';
            if (showFeedback) {
                this.showNotification('Unable to refresh system diagnostics.', 'error');
            }
        }
    }

    renderHealthMetric(label, percentValue, detailText) {
        const value = Number.isFinite(percentValue) ? Math.max(0, Math.min(100, percentValue)) : 0;
        const stateClass = value >= 85 ? 'high' : (value >= 70 ? 'medium' : 'low');
        return `
            <div class="health-metric-item">
                <div class="health-metric-head">
                    <span>${this.escapeHtml(label)}</span>
                    <span class="health-metric-value ${stateClass}">${value.toFixed(1)}%</span>
                </div>
                <div class="health-metric-bar">
                    <div class="health-metric-fill ${stateClass}" style="width: ${value.toFixed(1)}%"></div>
                </div>
                <div class="health-metric-detail">${this.escapeHtml(detailText || '')}</div>
            </div>
        `;
    }

    formatHealthStatusLabel(status) {
        const normalized = String(status || '').toLowerCase();
        if (normalized === 'healthy') return 'Healthy';
        if (normalized === 'warning') return 'Warning';
        if (normalized === 'error') return 'Critical';
        return 'Unknown';
    }

    getHealthRecommendation(status, warningCount, memoryUsagePercent, diskUsedPercent, primaryAlert = null) {
        if (primaryAlert && primaryAlert.recommended_fix) {
            return String(primaryAlert.recommended_fix);
        }
        const normalized = String(status || '').toLowerCase();
        if (normalized === 'error') {
            return 'Escalate to administrator immediately and review database availability plus resource saturation.';
        }
        if (normalized === 'warning') {
            if (warningCount > 5) return 'Investigate recurring warnings and clear failed jobs before they affect service.';
            if (memoryUsagePercent >= 75) return 'Review memory-heavy tasks and raise PHP memory limit if sustained.';
            if (diskUsedPercent >= 85) return 'Archive old artifacts and free disk space to avoid service interruption.';
            return 'Monitor signals closely and run targeted cleanup where needed.';
        }
        return 'No immediate action required. Continue periodic monitoring.';
    }

    renderSystemHealthSummaryCards(items = []) {
        if (!Array.isArray(items) || items.length === 0) {
            return '<div class="widget-empty">No summary metrics are available.</div>';
        }

        return items.map((item) => `
            <article class="health-summary-card">
                <div class="health-summary-label">${this.escapeHtml(String(item.label || 'Metric'))}</div>
                <div class="health-summary-value">${this.escapeHtml(String(item.value ?? '0'))}</div>
                <div class="health-summary-detail">${this.escapeHtml(String(item.detail || ''))}</div>
            </article>
        `).join('');
    }

    renderSystemHealthNotes(message, activeAlerts = [], warningCount = 0, memoryUsagePercent = 0, diskUsedPercent = 0) {
        const primaryAlert = Array.isArray(activeAlerts) && activeAlerts.length > 0 ? activeAlerts[0] : null;
        const followUp = this.getHealthRecommendation(
            primaryAlert?.severity || (activeAlerts.length > 0 ? 'warning' : 'healthy'),
            warningCount,
            memoryUsagePercent,
            diskUsedPercent,
            primaryAlert
        );
        const notes = [
            {
                title: 'Diagnosis',
                body: primaryAlert?.title || message || 'All monitored subsystems are currently healthy.'
            },
            {
                title: 'Cause',
                body: primaryAlert?.cause || message || 'No active issue has been identified.'
            },
            {
                title: 'Recommended Fix',
                body: followUp
            }
        ];

        if (primaryAlert?.clears_automatically) {
            notes.push({
                title: 'Clearance',
                body: 'This alert clears automatically when the underlying metric returns to a safe range.'
            });
        } else if (primaryAlert?.actions?.length) {
            notes.push({
                title: 'Recovery Tools',
                body: 'Use the alert actions below after assessing the cause so the resolved incident no longer contributes to system health pressure.'
            });
        }

        return notes.map((note) => `
            <div class="health-note-item">
                <strong>${this.escapeHtml(note.title)}:</strong> ${this.escapeHtml(note.body)}
            </div>
        `).join('');
    }

    renderSystemHealthAlerts(alerts = []) {
        if (!Array.isArray(alerts) || alerts.length === 0) {
            return `
                <div class="widget-empty">
                    <p>No active subsystem alerts were detected.</p>
                </div>
            `;
        }

        return alerts.map((alert) => {
            const severity = String(alert.severity || 'warning').toLowerCase();
            const metricMarkup = Array.isArray(alert.metrics) && alert.metrics.length > 0
                ? `
                    <div class="health-alert-metrics">
                        ${alert.metrics.map((metric) => `
                            <div class="health-alert-metric">
                                <span class="health-alert-metric-label">${this.escapeHtml(String(metric.label || 'Metric'))}</span>
                                <strong>${this.escapeHtml(String(metric.value ?? ''))}</strong>
                            </div>
                        `).join('')}
                    </div>
                `
                : '';
            const messageMarkup = Array.isArray(alert.alert_messages) && alert.alert_messages.length > 0
                ? `
                    <div class="health-alert-block">
                        <div class="health-alert-block-title">Alert Messages</div>
                        <ul class="health-alert-message-list">
                            ${alert.alert_messages.map((entry) => `<li>${this.escapeHtml(String(entry || ''))}</li>`).join('')}
                        </ul>
                    </div>
                `
                : '';
            const stepsMarkup = Array.isArray(alert.recommended_steps) && alert.recommended_steps.length > 0
                ? `
                    <div class="health-alert-block">
                        <div class="health-alert-block-title">Recommended Fix Procedure</div>
                        <ol class="health-alert-step-list">
                            ${alert.recommended_steps.map((step) => `<li>${this.escapeHtml(String(step || ''))}</li>`).join('')}
                        </ol>
                    </div>
                `
                : '';
            const actionsMarkup = Array.isArray(alert.actions) && alert.actions.length > 0
                ? `
                    <div class="health-alert-actions">
                        ${alert.actions.map((action) => `
                            <button
                                type="button"
                                class="action-btn ${this.escapeHtml(String(action.variant || 'secondary'))}"
                                data-health-action="${this.escapeHtml(String(action.action || ''))}"
                                data-alert-key="${this.escapeHtml(String(alert.key || ''))}"
                                data-action-label="${this.escapeHtml(String(action.action_label || 'run the selected diagnostics action'))}"
                                data-confirm-message="${this.escapeHtml(String(action.confirm_message || ''))}"
                            >
                                ${this.escapeHtml(String(action.label || 'Run Action'))}
                            </button>
                        `).join('')}
                    </div>
                `
                : `
                    <div class="health-alert-passive-note">
                        ${alert.clears_automatically
                            ? 'This alert clears automatically after the monitored metric returns to a safe range.'
                            : 'No direct recovery action is available for this alert from the diagnostics console.'}
                    </div>
                `;

            return `
                <article class="health-alert-card severity-${this.escapeHtml(severity)}">
                    <div class="health-alert-head">
                        <div>
                            <div class="health-alert-subsystem">${this.escapeHtml(String(alert.subsystem || 'Subsystem'))}</div>
                            <h4>${this.escapeHtml(String(alert.title || 'Diagnostics alert'))}</h4>
                        </div>
                        <div class="health-alert-meta">
                            <span class="health-alert-severity severity-${this.escapeHtml(severity)}">${this.escapeHtml(this.formatHealthStatusLabel(severity))}</span>
                            <span>${this.escapeHtml(String(alert.last_seen_at || 'Now'))}</span>
                        </div>
                    </div>
                    <p class="health-alert-summary">${this.escapeHtml(String(alert.summary || ''))}</p>
                    <div class="health-alert-block">
                        <div class="health-alert-block-title">Likely Cause</div>
                        <p>${this.escapeHtml(String(alert.cause || 'No cause supplied.'))}</p>
                    </div>
                    ${metricMarkup}
                    ${messageMarkup}
                    ${stepsMarkup}
                    ${actionsMarkup}
                </article>
            `;
        }).join('');
    }

    async handleSystemHealthAction(button) {
        const action = String(button?.dataset?.healthAction || '').trim();
        if (!action) {
            return;
        }

        const actionLabel = String(button.dataset.actionLabel || 'run the diagnostics action').trim();
        const confirmMessage = String(button.dataset.confirmMessage || '').trim();
        if (confirmMessage) {
            const confirmed = await this.confirmSystemHealthAction(
                action,
                actionLabel,
                confirmMessage,
                String(button.textContent || '').trim()
            );
            if (!confirmed) {
                return;
            }
        }

        const alertKey = String(button.dataset.alertKey || '').trim();
        const originalText = button.textContent;
        button.disabled = true;
        button.textContent = 'Working...';

        try {
            const response = await this.performSensitiveAdminRequest('../backend/api/manage_system_health_diagnostics.php', {
                method: 'POST',
                credentials: 'include',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest'
                },
                body: JSON.stringify({ action, alert_key: alertKey })
            }, actionLabel);
            const data = response.__adminPayloadAttached || await this.safeJson(response, { success: false });
            if (!response.ok || !data.success) {
                throw new Error(data.message || 'Unable to complete the diagnostics action.');
            }

            await this.refreshSystemHealthSection(false);
            await this.updateDashboardWidgets();
            this.showNotification(data.message || 'Diagnostics action completed.', 'success');
        } catch (error) {
            this.showNotification(error.message || 'Unable to complete the diagnostics action.', 'error');
        } finally {
            button.disabled = false;
            button.textContent = originalText;
        }
    }

    getSystemHealthActionDialogConfig(action, actionLabel, confirmMessage, buttonLabel) {
        const label = (buttonLabel || actionLabel || 'Confirm Action').trim() || 'Confirm Action';
        const presets = {
            clear_failed_notification_queue: {
                title: 'Clear Failed Emails',
                subtitle: 'Remove the failed delivery rows after review',
                iconMarkup: '&#9993;',
                variant: 'danger',
                confirmLabel: 'Clear Failed Emails',
                notice: 'This action only clears the failed notification email rows tied to the current incident. It does not change transport settings or remove active queue items.',
                points: [
                    { label: 'Review first', text: 'Confirm the failed rows are no longer needed for troubleshooting or audit follow-up.' },
                    { label: 'Scope', text: 'Only the failed email incident rows for this alert will be removed.' },
                    { label: 'After clearing', text: 'If delivery problems continue, new failures will appear again automatically.' }
                ]
            },
            resolve_alert: {
                title: 'Mark Incident Resolved',
                subtitle: 'Close the current diagnostics incident after verification',
                iconMarkup: '&#10003;',
                variant: 'secondary',
                confirmLabel: 'Mark Resolved',
                notice: 'Use this when the underlying issue has been handled or when the listed warning/error events have been reviewed and are no longer actionable.',
                points: [
                    { label: 'Verify the fix', text: 'Confirm the affected workflow, service, or data issue has already been addressed.' },
                    { label: 'What this does', text: 'The current incident is removed from the unresolved diagnostics list.' },
                    { label: 'If it returns', text: 'Fresh warning or error events will raise the alert again automatically.' }
                ]
            }
        };

        const preset = presets[action] || {
            title: label,
            subtitle: 'Review the action details before continuing',
            iconMarkup: '&#9888;',
            variant: 'secondary',
            confirmLabel: label,
            notice: 'This action affects the current system-health incident and should only be run after a quick verification.',
            points: [
                { label: 'Review', text: 'Read the incident details and confirm this is the intended maintenance action.' },
                { label: 'Proceed carefully', text: 'Continue only if you are satisfied with the current health state and next step.' }
            ]
        };

        return {
            ...preset,
            message: confirmMessage || `Are you sure you want to ${actionLabel || label.toLowerCase()}?`
        };
    }

    async confirmSystemHealthAction(action, actionLabel, confirmMessage, buttonLabel) {
        const config = this.getSystemHealthActionDialogConfig(action, actionLabel, confirmMessage, buttonLabel);
        document.getElementById('systemHealthActionModalOverlay')?.remove();

        return new Promise((resolve) => {
            const overlay = document.createElement('div');
            overlay.className = 'admin-modal-overlay';
            overlay.id = 'systemHealthActionModalOverlay';
            overlay.innerHTML = `
                <div class="admin-modal system-health-action-modal ${config.variant === 'danger' ? 'is-danger' : ''}" role="dialog" aria-modal="true" aria-labelledby="systemHealthActionModalTitle">
                    <div class="admin-modal-header">
                        <div>
                            <h3 id="systemHealthActionModalTitle">${this.escapeHtml(config.title)}</h3>
                            <p class="system-health-action-subtitle">${this.escapeHtml(config.subtitle)}</p>
                        </div>
                        <button type="button" class="admin-modal-close" aria-label="Close">&times;</button>
                    </div>
                    <div class="admin-modal-body system-health-action-body">
                        <div class="system-health-action-intro">
                            <div class="system-health-action-icon" aria-hidden="true">${config.iconMarkup}</div>
                            <div class="system-health-action-copy">
                                <p class="system-health-action-message">${this.escapeHtml(config.message)}</p>
                                <div class="system-health-action-notice">${this.escapeHtml(config.notice)}</div>
                            </div>
                        </div>
                        <ul class="system-health-action-points">
                            ${config.points.map((item) => `
                                <li><strong>${this.escapeHtml(item.label)}:</strong> ${this.escapeHtml(item.text)}</li>
                            `).join('')}
                        </ul>
                    </div>
                    <div class="admin-modal-footer">
                        <button type="button" class="action-btn secondary" data-health-action-cancel>Cancel</button>
                        <button type="button" class="action-btn ${config.variant === 'danger' ? 'danger' : ''}" data-health-action-confirm>${this.escapeHtml(config.confirmLabel)}</button>
                    </div>
                </div>
            `;

            document.body.appendChild(overlay);

            let settled = false;
            const confirmButton = overlay.querySelector('[data-health-action-confirm]');
            const onKeyDown = (event) => {
                if (event.key === 'Escape') {
                    event.preventDefault();
                    close(false);
                }
            };
            const close = (confirmed) => {
                if (settled) {
                    return;
                }
                settled = true;
                document.removeEventListener('keydown', onKeyDown);
                overlay.remove();
                resolve(Boolean(confirmed));
            };

            document.addEventListener('keydown', onKeyDown);
            overlay.addEventListener('click', (event) => {
                if (event.target === overlay) {
                    close(false);
                }
            });
            overlay.querySelector('.admin-modal-close')?.addEventListener('click', () => close(false));
            overlay.querySelector('[data-health-action-cancel]')?.addEventListener('click', () => close(false));
            confirmButton?.addEventListener('click', () => close(true));
            confirmButton?.focus();
        });
    }

    // Initialize dashboard widgets
    initializeDashboardWidgets() {
        this.updateDashboardWidgets();
        if (!this.dashboardWidgetsTimer) {
            this.dashboardWidgetsTimer = setInterval(() => {
                this.updateDashboardWidgets();
            }, 15000);
        }
    }

    // Update dashboard widgets (recent activity + system health)
    async updateDashboardWidgets() {
        const recentActivityList = document.getElementById('recentActivityList');
        const systemHealthStatus = document.getElementById('systemHealthStatus');

        if (!recentActivityList && !systemHealthStatus) {
            return;
        }

        try {
            const [recentResponse, healthResponse] = await Promise.all([
                fetch('../backend/api/get_user_logs.php?page=1&limit=5', { credentials: 'include', cache: 'no-store' }).catch(() => ({ ok: false })),
                fetch('../backend/api/get_system_status.php', { credentials: 'include', cache: 'no-store' }).catch(() => ({ ok: false }))
            ]);

            const recentData = await this.safeJson(recentResponse, { success: false, logs: [] });
            const healthData = await this.safeJson(healthResponse);

            if (recentActivityList) {
                if (recentData.success && Array.isArray(recentData.logs) && recentData.logs.length > 0) {
                    recentActivityList.innerHTML = recentData.logs.map((log) => {
                        const rawActivity = log.activity_type || 'default';
                        const activityClass = rawActivity.toString().replace(/[^a-z0-9_-]/gi, '_');
                        const activityLabel = this.formatActivityType(rawActivity);
                        const locationInfo = this.formatLocation(log);
                        const userName = this.escapeHtml(log.user_name || 'System');
                        const createdDate = this.escapeHtml(log.created_date || 'N/A');
                        const details = this.escapeHtml(log.details || 'No details');

                        return `
                            <div class="activity-item">
                                <div class="activity-row">
                                    <span class="activity-badge activity-${activityClass}">${this.escapeHtml(activityLabel)}</span>
                                    <span class="activity-user">${userName}</span>
                                </div>
                                <div class="activity-row activity-meta">
                                    <span class="activity-time">${createdDate}</span>
                                    <span class="activity-location">
                                        ${this.escapeHtml(locationInfo.title)}
                                        ${locationInfo.subtitle ? `&bull; ${this.escapeHtml(locationInfo.subtitle)}` : ''}
                                    </span>
                                </div>
                                ${details ? `<div class="activity-details">${details}</div>` : ''}
                            </div>
                        `;
                    }).join('');
                } else {
                    recentActivityList.innerHTML = `
                        <div class="widget-empty">No recent activity found.</div>
                    `;
                }
            }

            if (systemHealthStatus) {
                if (healthData.success) {
                    const status = healthData.systemHealth?.status || 'healthy';
                    const message = healthData.systemHealth?.message || 'Status unavailable';
                    const lastBackup = healthData.lastBackup || 'Never';
                    const diagnostics = healthData.diagnostics || {};
                    const statusLabel = this.formatHealthStatusLabel(status);
                    const warningCount = Number(diagnostics.warning_count_1h || 0);
                    const activeUsers = Number(healthData.activeUsers || 0);

                    systemHealthStatus.innerHTML = `
                        <div class="health-card health-${this.escapeHtml(status)}">
                            <div class="health-indicator-dot"></div>
                            <div class="health-info">
                                <div class="health-title">${this.escapeHtml(statusLabel)}</div>
                                <div class="health-message">${this.escapeHtml(message)}</div>
                                <div class="health-meta">Last backup: ${this.escapeHtml(lastBackup)}</div>
                                <div class="health-meta">Warnings (1h): ${this.escapeHtml(String(warningCount))} &bull; Active users: ${this.escapeHtml(String(activeUsers))}</div>
                            </div>
                        </div>
                    `;
                } else {
                    systemHealthStatus.innerHTML = `
                        <div class="health-card health-warning">
                            <div class="health-indicator-dot"></div>
                            <div class="health-info">
                                <div class="health-title">Unknown</div>
                                <div class="health-message">Unable to load system health.</div>
                                <div class="health-meta">Refresh to retry.</div>
                            </div>
                        </div>
                    `;
                }
            }
        } catch (error) {
            console.error('Error updating dashboard widgets:', error);
            if (recentActivityList) {
                recentActivityList.innerHTML = `<div class="widget-empty">Unable to load recent activity.</div>`;
            }
            if (systemHealthStatus) {
                systemHealthStatus.innerHTML = `
                    <div class="health-card health-warning">
                        <div class="health-indicator-dot"></div>
                        <div class="health-info">
                            <div class="health-title">Unavailable</div>
                            <div class="health-message">System health check failed.</div>
                        </div>
                    </div>
                `;
            }
        }
    }

    // Load user logs data with better error handling
    async loadUserLogs(page = 1) {
        const activityType = document.getElementById('activityTypeFilter')?.value || '';
        const dateFrom = document.getElementById('dateFromFilter')?.value || '';
        const dateTo = document.getElementById('dateToFilter')?.value || '';

        const params = new URLSearchParams({
            page: page,
            limit: 20,
            ...(activityType && { activity_type: activityType }),
            ...(dateFrom && { date_from: dateFrom }),
            ...(dateTo && { date_to: dateTo })
        });

        try {
            // Show loading state
            const tbody = document.getElementById('logsTableBody');
            if (tbody) {
                tbody.innerHTML = `
                    <tr class="loading">
                        <td colspan="8">
                            <div style="text-align: center; padding: 2rem;">
                                <div class="loading-spinner"></div>
                                <p>Loading logs...</p>
                            </div>
                        </td>
                    </tr>
                `;
            }

            const response = await fetch(`../backend/api/get_user_logs.php?${params}`, {
                credentials: 'include',
                headers: {
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest'
                }
            });
            
            // First check if we got a response at all
            if (!response) {
                throw new Error('No response from server');
            }
            
            // Get the raw text first to debug
            const responseText = await response.text();
            
            // Check if the response is HTML (indicating an error)
            if (responseText.trim().startsWith('<!DOCTYPE') || responseText.includes('<html')) {
                console.error('HTML response received instead of JSON:', responseText.substring(0, 500));
                throw new Error('Server returned HTML instead of JSON. Check for PHP errors.');
            }
            
            // Try to parse as JSON
            let data;
            try {
                data = JSON.parse(responseText);
            } catch (parseError) {
                console.error('JSON Parse Error:', parseError);
                console.error('Response text:', responseText);
                throw new Error('Invalid JSON response from server. Check PHP errors.');
            }
            
            // Check if the response indicates success
            if (!response.ok) {
                throw new Error(data?.message || `HTTP error! status: ${response.status}`);
            }

            if (data.success) {
                this.renderUserLogs(data.logs);
                this.renderPagination(data.pagination);
                this.updateLogCounters();
            } else {
                throw new Error(data.message || 'Failed to load logs');
            }
        } catch (error) {
            console.error('Error loading user logs:', error);
            const tbody = document.getElementById('logsTableBody');
            if (tbody) {
                tbody.innerHTML = `
                    <tr>
                        <td colspan="8">
                            <div style="text-align: center; padding: 2rem; color: var(--error-color);">
                                <div style="font-size: 3rem; margin-bottom: 1rem;">&#128202;</div>
                                <h3 style="margin-bottom: 0.5rem;">Error Loading Logs</h3>
                                <p style="margin-bottom: 1rem;">${this.escapeHtml(error.message)}</p>
                                <div style="display: flex; gap: 0.5rem; justify-content: center; flex-wrap: wrap;">
                                    <button onclick="adminDashboard.loadUserLogs(${page})" 
                                            class="filter-btn">
                                        Try Again
                                    </button>
                                    <button onclick="adminDashboard.debugApiCall()" 
                                            class="filter-btn secondary">
                                        Debug API
                                    </button>
                                </div>
                            </div>
                        </td>
                    </tr>
                `;
            }
        }
    }

    // Load notification queue data
    async loadNotificationQueue(page = 1) {
        const status = document.getElementById('queueStatusFilter')?.value || '';
        const channel = document.getElementById('queueChannelFilter')?.value || '';
        const search = document.getElementById('queueSearchFilter')?.value || '';

        const params = new URLSearchParams({
            page: page,
            limit: 20,
            ...(status && { status }),
            ...(channel && { channel }),
            ...(search && { search })
        });

        try {
            const tbody = document.getElementById('notificationQueueTableBody');
            if (tbody) {
                tbody.innerHTML = `
                    <tr class="loading">
                        <td colspan="6">
                            <div style="text-align: center; padding: 2rem;">
                                <div class="loading-spinner"></div>
                                <p>Loading queue...</p>
                            </div>
                        </td>
                    </tr>
                `;
            }

            const response = await fetch(`../backend/api/get_notification_queue.php?${params}`, {
                credentials: 'include',
                headers: {
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest'
                }
            });

            const responseText = await response.text();
            if (responseText.trim().startsWith('<!DOCTYPE') || responseText.includes('<html')) {
                console.error('HTML response received instead of JSON:', responseText.substring(0, 500));
                throw new Error('Server returned HTML instead of JSON. Check for PHP errors.');
            }

            let data;
            try {
                data = JSON.parse(responseText);
            } catch (parseError) {
                console.error('JSON Parse Error:', parseError);
                console.error('Response text:', responseText);
                throw new Error('Invalid JSON response from server. Check PHP errors.');
            }

            if (!response.ok) {
                throw new Error(data?.message || `HTTP error! status: ${response.status}`);
            }

            if (data.success) {
                this.renderNotificationQueue(data.queue || []);
                this.renderNotificationQueueSummary(data.summary || {});
                this.renderNotificationQueuePagination(data.pagination);
            } else {
                throw new Error(data.message || 'Failed to load notification queue');
            }
        } catch (error) {
            console.error('Error loading notification queue:', error);
            const tbody = document.getElementById('notificationQueueTableBody');
            if (tbody) {
                tbody.innerHTML = `
                    <tr>
                        <td colspan="6">
                            <div style="text-align: center; padding: 2rem; color: var(--error-color);">
                                <div style="font-size: 2.5rem; margin-bottom: 1rem;">X</div>
                                <h3 style="margin-bottom: 0.5rem;">Error Loading Queue</h3>
                                <p style="margin-bottom: 1rem;">${this.escapeHtml(error.message)}</p>
                                <button onclick="adminDashboard.loadNotificationQueue(${page})" class="filter-btn">
                                    Try Again
                                </button>
                            </div>
                        </td>
                    </tr>
                `;
            }
        }
    }

    renderNotificationQueue(queue) {
        const tbody = document.getElementById('notificationQueueTableBody');
        if (!tbody) return;

        if (!queue || queue.length === 0) {
            tbody.innerHTML = `
                <tr>
                    <td colspan="6" class="empty-state">
                        <div style="text-align: center; padding: 2rem; color: var(--muted-color);">
                            <div style="font-size: 2.5rem; margin-bottom: 1rem;">Queue</div>
                            <h3 style="margin-bottom: 0.5rem;">No queued notifications</h3>
                            <p>Nothing is waiting in the notification queue.</p>
                        </div>
                    </td>
                </tr>
            `;
            return;
        }

        tbody.innerHTML = queue.map(item => {
            const statusClass = `queue-status-${this.escapeHtml(item.status || 'queued')}`;
            const channelClass = `queue-channel-${this.escapeHtml(item.channel || 'email')}`;
            return `
                <tr>
                    <td><span class="queue-status ${statusClass}">${this.formatQueueStatus(item.status)}</span></td>
                    <td><span class="queue-channel ${channelClass}">${this.formatQueueChannel(item.channel)}</span></td>
                    <td>
                        <div class="user-info">
                            <strong>${this.escapeHtml(item.recipient || 'Unknown')}</strong>
                            <small>${this.escapeHtml(item.meta_label || '')}</small>
                        </div>
                    </td>
                    <td>${this.escapeHtml(item.subject || 'No subject')}</td>
                    <td class="hide-on-mobile">${this.escapeHtml(this.truncateText(item.message || '', 120))}</td>
                    <td>
                        <span class="timestamp-full">${this.escapeHtml(item.created_date || 'N/A')}</span>
                        <span class="timestamp-mobile" style="display:none;">${this.formatMobileTimestamp(item.created_at)}</span>
                    </td>
                </tr>
            `;
        }).join('');

        this.updateMobileTimestamps();
    }

    renderNotificationQueueSummary(summary) {
        const summaryEl = document.getElementById('notificationQueueSummary');
        if (!summaryEl) return;

        const total = summary.total ?? 0;
        const queued = summary.queued ?? 0;
        const sent = summary.sent ?? 0;
        const failed = summary.failed ?? 0;

        summaryEl.innerHTML = `
            <span class="queue-chip">Total: <strong>${this.escapeHtml(total)}</strong></span>
            <span class="queue-chip queued">Queued: <strong>${this.escapeHtml(queued)}</strong></span>
            <span class="queue-chip sent">Sent: <strong>${this.escapeHtml(sent)}</strong></span>
            <span class="queue-chip failed">Failed: <strong>${this.escapeHtml(failed)}</strong></span>
        `;
    }

    renderNotificationQueuePagination(pagination) {
        const paginationContainer = document.getElementById('notificationQueuePagination');
        if (!paginationContainer || !pagination) return;

        const { page, total_pages } = pagination;
        if (total_pages <= 1) {
            paginationContainer.innerHTML = '';
            return;
        }

        let paginationHTML = '';
        if (page > 1) {
            paginationHTML += `<button class="page-btn" data-page="${page - 1}">Previous</button>`;
        }

        for (let i = 1; i <= total_pages; i++) {
            if (i === page) {
                paginationHTML += `<span class="page-current">${i}</span>`;
            } else if (i === 1 || i === total_pages || (i >= page - 2 && i <= page + 2)) {
                paginationHTML += `<button class="page-btn" data-page="${i}">${i}</button>`;
            } else if (i === page - 3 || i === page + 3) {
                paginationHTML += '<span class="page-ellipsis">...</span>';
            }
        }

        if (page < total_pages) {
            paginationHTML += `<button class="page-btn" data-page="${page + 1}">Next</button>`;
        }

        paginationContainer.innerHTML = paginationHTML;
        paginationContainer.querySelectorAll('.page-btn').forEach(btn => {
            btn.addEventListener('click', () => {
                const pageNum = parseInt(btn.getAttribute('data-page'));
                this.loadNotificationQueue(pageNum);
            });
        });
    }

    // Load audit logs data
    async loadAuditLogs(page = 1) {
        const action = document.getElementById('auditActionFilter')?.value || '';
        const role = document.getElementById('auditRoleFilter')?.value || '';
        const actor = document.getElementById('auditActorFilter')?.value || '';
        const dateFrom = document.getElementById('auditDateFromFilter')?.value || '';
        const dateTo = document.getElementById('auditDateToFilter')?.value || '';

        const params = new URLSearchParams({
            page: page,
            limit: 20,
            ...(action && { action }),
            ...(role && { actor_role: role }),
            ...(actor && { actor }),
            ...(dateFrom && { date_from: dateFrom }),
            ...(dateTo && { date_to: dateTo })
        });

        try {
            const tbody = document.getElementById('auditLogsTableBody');
            if (tbody) {
                tbody.innerHTML = `
                    <tr class="loading">
                        <td colspan="6">
                            <div style="text-align: center; padding: 2rem;">
                                <div class="loading-spinner"></div>
                                <p>Loading audit logs...</p>
                            </div>
                        </td>
                    </tr>
                `;
            }

            const response = await fetch(`../backend/api/get_audit_logs.php?${params}`, {
                credentials: 'include',
                headers: {
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest'
                }
            });

            const responseText = await response.text();
            if (responseText.trim().startsWith('<!DOCTYPE') || responseText.includes('<html')) {
                console.error('HTML response received instead of JSON:', responseText.substring(0, 500));
                throw new Error('Server returned HTML instead of JSON. Check for PHP errors.');
            }

            let data;
            try {
                data = JSON.parse(responseText);
            } catch (parseError) {
                console.error('JSON Parse Error:', parseError);
                console.error('Response text:', responseText);
                throw new Error('Invalid JSON response from server. Check PHP errors.');
            }

            if (!response.ok) {
                throw new Error(data?.message || `HTTP error! status: ${response.status}`);
            }

            if (data.success) {
                this.renderAuditLogs(data.logs || []);
                this.renderAuditPagination(data.pagination);
            } else {
                throw new Error(data.message || 'Failed to load audit logs');
            }
        } catch (error) {
            console.error('Error loading audit logs:', error);
            const tbody = document.getElementById('auditLogsTableBody');
            if (tbody) {
                tbody.innerHTML = `
                    <tr>
                        <td colspan="6">
                            <div style="text-align: center; padding: 2rem; color: var(--error-color);">
                                <div style="font-size: 2.5rem; margin-bottom: 1rem;">X</div>
                                <h3 style="margin-bottom: 0.5rem;">Error Loading Audit Logs</h3>
                                <p style="margin-bottom: 1rem;">${this.escapeHtml(error.message)}</p>
                                <button onclick="adminDashboard.loadAuditLogs(${page})" class="filter-btn">
                                    Try Again
                                </button>
                            </div>
                        </td>
                    </tr>
                `;
            }
        }
    }

    renderAuditLogs(logs) {
        const tbody = document.getElementById('auditLogsTableBody');
        if (!tbody) return;

        if (!logs || logs.length === 0) {
            tbody.innerHTML = `
                <tr>
                    <td colspan="6" class="empty-state">
                        <div style="text-align: center; padding: 2rem; color: var(--muted-color);">
                            <div style="font-size: 2.5rem; margin-bottom: 1rem;">Audit</div>
                            <h3 style="margin-bottom: 0.5rem;">No audit entries</h3>
                            <p>No audit events match your current filters.</p>
                        </div>
                    </td>
                </tr>
            `;
            return;
        }

        tbody.innerHTML = logs.map(log => {
            const actorName = log.actor_name || 'System';
            const actionLabel = this.formatAuditAction(log.action);
            const entityLabel = this.formatAuditEntity(log.entity_type, log.entity_id);
            const details = this.truncateText(log.details || 'No details', 140);
            return `
                <tr>
                    <td>
                        <div class="user-info">
                            <strong>${this.escapeHtml(actorName)}</strong>
                        </div>
                    </td>
                    <td><span class="role-pill">${this.escapeHtml(log.actor_role || 'system')}</span></td>
                    <td><span class="audit-action">${this.escapeHtml(actionLabel)}</span></td>
                    <td class="hide-on-mobile">${this.escapeHtml(entityLabel)}</td>
                    <td>
                        <span class="timestamp-full">${this.escapeHtml(log.created_date || 'N/A')}</span>
                        <span class="timestamp-mobile" style="display:none;">${this.formatMobileTimestamp(log.created_at)}</span>
                    </td>
                    <td class="hide-on-mobile">${this.escapeHtml(details || 'No details')}</td>
                </tr>
            `;
        }).join('');

        this.updateMobileTimestamps();
    }

    renderAuditPagination(pagination) {
        const paginationContainer = document.getElementById('auditLogsPagination');
        if (!paginationContainer || !pagination) return;

        const { page, total_pages } = pagination;
        if (total_pages <= 1) {
            paginationContainer.innerHTML = '';
            return;
        }

        let paginationHTML = '';
        if (page > 1) {
            paginationHTML += `<button class="page-btn" data-page="${page - 1}">Previous</button>`;
        }

        for (let i = 1; i <= total_pages; i++) {
            if (i === page) {
                paginationHTML += `<span class="page-current">${i}</span>`;
            } else if (i === 1 || i === total_pages || (i >= page - 2 && i <= page + 2)) {
                paginationHTML += `<button class="page-btn" data-page="${i}">${i}</button>`;
            } else if (i === page - 3 || i === page + 3) {
                paginationHTML += '<span class="page-ellipsis">...</span>';
            }
        }

        if (page < total_pages) {
            paginationHTML += `<button class="page-btn" data-page="${page + 1}">Next</button>`;
        }

        paginationContainer.innerHTML = paginationHTML;
        paginationContainer.querySelectorAll('.page-btn').forEach(btn => {
            btn.addEventListener('click', () => {
                const pageNum = parseInt(btn.getAttribute('data-page'));
                this.loadAuditLogs(pageNum);
            });
        });
    }

    // Add debug method to test API calls
    debugApiCall() {
        console.group('API Debug Information');
        
        // Test the API endpoint directly
        fetch('../backend/api/get_user_logs.php?page=1&limit=5', {
            credentials: 'include'
        })
        .then(response => response.text())
        .then(text => {
            console.log('Raw API Response:', text);
            try {
                const json = JSON.parse(text);
                console.log('Parsed JSON:', json);
            } catch (e) {
                console.log('JSON Parse Failed - Response is not valid JSON');
            }
        })
        .catch(error => {
            console.error('API Test Failed:', error);
        });
        
        console.groupEnd();
    }

    // Render user logs table with responsive classes
    renderUserLogs(logs) {
        const tbody = document.getElementById('logsTableBody');
        if (!tbody) return;
        
        if (logs.length === 0) {
            tbody.innerHTML = `
                <tr>
                    <td colspan="8" class="empty-state">
                        <div style="text-align: center; padding: 2rem; color: var(--muted-color);">
                            <div style="font-size: 3rem; margin-bottom: 1rem;">&#128202;</div>
                            <h3 style="margin-bottom: 0.5rem;">No logs found</h3>
                            <p>No activity logs match your current filters.</p>
                            <button onclick="adminDashboard.clearFilters()" 
                                    style="margin-top: 1rem; padding: 0.5rem 1rem; background: var(--primary-blue); color: white; border: none; border-radius: 6px; cursor: pointer;">
                                Clear Filters
                            </button>
                        </div>
                    </td>
                </tr>
            `;
            return;
        }

        tbody.innerHTML = logs.map(log => {
            const locationInfo = this.formatLocation(log);
            return `
            <tr>
                <td>
                    <div class="user-info">
                        <strong>${this.escapeHtml(log.user_name || 'Unknown')}</strong>
                        <small>${this.escapeHtml(log.user_role || 'No role')}</small>
                    </div>
                </td>
                <td>
                    <span class="activity-badge activity-${log.activity_type}">
                        ${this.formatActivityType(log.activity_type)}
                    </span>
                </td>
                <td class="hide-on-mobile">${this.escapeHtml(log.ip_address || 'N/A')}</td>
                <td class="location-cell">
                    <div class="location-info">
                        <strong>${this.escapeHtml(locationInfo.title)}</strong>
                        ${locationInfo.subtitle ? `<small>${this.escapeHtml(locationInfo.subtitle)}</small>` : ''}
                    </div>
                </td>
                <td class="hide-on-mobile">${this.escapeHtml(log.device_type || 'Unknown')}</td>
                <td class="hide-on-mobile">${this.escapeHtml(log.duration_formatted || 'N/A')}</td>
                <td>
                    <span class="timestamp-full">${this.escapeHtml(log.created_date || 'N/A')}</span>
                    <span class="timestamp-mobile" style="display: none;">${this.formatMobileTimestamp(log.created_at)}</span>
                </td>
                <td class="hide-on-mobile">${this.escapeHtml(log.details || 'No details')}</td>
            </tr>
        `}).join('');
        
        // Initialize mobile timestamp display
        this.updateMobileTimestamps();
    }

    // Format timestamp for mobile display
    formatMobileTimestamp(timestamp) {
        if (!timestamp) return 'N/A';
        if (window.AppSettingsManager?.formatDateTime) {
            return window.AppSettingsManager.formatDateTime(timestamp, { includeSeconds: false });
        }
        const date = new Date(timestamp);
        return date.toLocaleDateString() + ' ' + date.toLocaleTimeString([], {hour: '2-digit', minute:'2-digit'});
    }

    // Update timestamp display based on screen size
    updateMobileTimestamps() {
        const isMobile = window.innerWidth <= 768;
        document.querySelectorAll('.timestamp-full, .timestamp-mobile').forEach(el => {
            if (el.classList.contains('timestamp-full')) {
                el.style.display = isMobile ? 'none' : 'inline';
            } else {
                el.style.display = isMobile ? 'inline' : 'none';
            }
        });
    }

    // Clear filters method
    clearFilters() {
        document.getElementById('activityTypeFilter').value = '';
        document.getElementById('dateFromFilter').value = '';
        document.getElementById('dateToFilter').value = '';
        this.loadUserLogs();
    }

    // Format activity type for display
    formatActivityType(type) {
        const types = {
            'login': 'Login',
            'logout': 'Logout',
            'session_expiry': 'Session Expired',
            'device_conflict': 'Device Conflict',
            'device_conflict_detected': 'Conflict Detected',
            'device_conflict_resolved': 'Conflict Resolved',
            'multiple_sessions_terminated': 'Sessions Terminated',
            'login_failed': 'Login Failed',
            'session_cleanup': 'Session Cleanup',
            'session_start': 'Session Start',
            'session_started': 'Session Start',
            'session_termination_failed': 'Termination Failed',
            'auto_logout': 'Auto Logout'
        };
        return types[type] || type;
    }

    formatQueueStatus(status) {
        if (!status) return 'Queued';
        return this.titleCase(status);
    }

    formatQueueChannel(channel) {
        if (!channel) return 'Email';
        return channel.toUpperCase();
    }

    formatAuditAction(action) {
        const map = {
            'user_created': 'User Created',
            'user_updated': 'User Updated',
            'user_deleted': 'User Deleted',
            'user_password_reset': 'Password Reset',
            'settings_updated': 'Settings Updated',
            'notification_settings_updated': 'Notification Settings Updated',
            'security_settings_updated': 'Security Settings Updated',
            'message_sent': 'Message Sent',
            'broadcast_sent': 'Broadcast Sent',
            'attachment_deleted': 'Attachment Deleted',
            'storage_policy_updated': 'Storage Policy Updated',
            'registry_contact_updated': 'Registry Contact Updated',
            'pensioner_contact_updated': 'Pensioner Contact Updated',
            'session_cleaned': 'Session Cleanup'
        };
        return map[action] || this.titleCase(action);
    }

    formatAuditEntity(entityType, entityId) {
        if (!entityType && !entityId) return 'System';
        const label = entityType ? this.titleCase(entityType) : 'Entity';
        return entityId ? `${label} #${entityId}` : label;
    }

    titleCase(value) {
        if (!value) return '';
        return value
            .toString()
            .replace(/[_-]+/g, ' ')
            .replace(/\s+/g, ' ')
            .trim()
            .replace(/\b\w/g, (char) => char.toUpperCase());
    }

    truncateText(text, maxLength = 140) {
        if (!text) return '';
        const clean = text.toString();
        if (clean.length <= maxLength) return clean;
        return `${clean.slice(0, maxLength - 3)}...`;
    }

    // Format location for display
    formatLocation(log) {
        const city = log.location_city || '';
        const detail = log.location_detail || '';
        const label = log.location || 'Unknown Location';

        if (city && detail) {
            return { title: city, subtitle: detail };
        }

        if (city) {
            return { title: city, subtitle: '' };
        }

        return { title: label, subtitle: '' };
    }

    // Escape HTML to prevent XSS
    escapeHtml(unsafe) {
        if (unsafe === null || unsafe === undefined) return '';
        return unsafe
            .toString()
            .replace(/&/g, "&amp;")
            .replace(/</g, "&lt;")
            .replace(/>/g, "&gt;")
            .replace(/"/g, "&quot;")
            .replace(/'/g, "&#039;");
    }

    // Render pagination
    renderPagination(pagination) {
        const paginationContainer = document.getElementById('logsPagination');
        if (!paginationContainer) return;
        
        const { page, total_pages } = pagination;

        if (total_pages <= 1) {
            paginationContainer.innerHTML = '';
            return;
        }

        let paginationHTML = '';

        // Previous button
        if (page > 1) {
            paginationHTML += `<button class="page-btn" data-page="${page - 1}">Previous</button>`;
        }

        // Page numbers
        for (let i = 1; i <= total_pages; i++) {
            if (i === page) {
                paginationHTML += `<span class="page-current">${i}</span>`;
            } else if (i === 1 || i === total_pages || (i >= page - 2 && i <= page + 2)) {
                paginationHTML += `<button class="page-btn" data-page="${i}">${i}</button>`;
            } else if (i === page - 3 || i === page + 3) {
                paginationHTML += '<span class="page-ellipsis">...</span>';
            }
        }

        // Next button
        if (page < total_pages) {
            paginationHTML += `<button class="page-btn" data-page="${page + 1}">Next</button>`;
        }

        paginationContainer.innerHTML = paginationHTML;

        // Add event listeners to page buttons
        paginationContainer.querySelectorAll('.page-btn').forEach(btn => {
            btn.addEventListener('click', () => {
                const pageNum = parseInt(btn.getAttribute('data-page'));
                this.loadUserLogs(pageNum);
            });
        });
    }

    // Show notification
    showNotification(message, type = 'info') {
        // Create notification element
        const notification = document.createElement('div');
        notification.className = `admin-notification admin-notification-${type}`;
        notification.innerHTML = `
            <div class="notification-content">
                <span class="notification-icon">${this.getNotificationIcon(type)}</span>
                <span class="notification-message">${message}</span>
                <button class="notification-close">&times;</button>
            </div>
        `;

        // Add to page
        document.body.appendChild(notification);

        // Add close functionality
        notification.querySelector('.notification-close').addEventListener('click', () => {
            notification.remove();
        });

        // Auto-remove after 5 seconds
        setTimeout(() => {
            if (notification.parentNode) {
                notification.remove();
            }
        }, 5000);
    }
    // Get notification icon
    getNotificationIcon(type) {
        const icons = {
            info: '\u2139',
            success: '\u2714',
            warning: '\u26A0',
            error: '\u2716'
        };
        return icons[type] || '\u2139';
    }

    // Default content for unimplemented sections
    loadDefaultContent(section) {
        return `
            <div class="section-placeholder">
                <div class="placeholder-icon">&#9881;</div>
                <h2>${this.getSectionTitle(section)}</h2>
                <p>This section is under development and will be available soon.</p>
                <div class="placeholder-actions">
                    <button onclick="adminDashboard.refreshCurrentSection()" class="action-btn">
                        <span class="action-icon">&#128295;</span>
                        Check for Updates
                    </button>
                </div>
            </div>
        `;
    }

    // Error content
    loadErrorContent(section, error) {
        return `
            <div class="error-state">
                <div class="error-icon">&#9888;</div>
                <h2>Error Loading ${this.getSectionTitle(section)}</h2>
                <p>${this.escapeHtml(error.message)}</p>
                <div class="error-actions">
                    <button onclick="adminDashboard.refreshCurrentSection()" class="action-btn">
                        <span class="action-icon">&#128295;</span>
                        Try Again
                    </button>
                    <button onclick="adminDashboard.navigateToSection('dashboard')" class="action-btn secondary">
                        <span class="action-icon">&#128295;</span>
                        Go to Dashboard
                    </button>
                </div>
            </div>
        `;
    }

    // Add resize listener for responsive updates
    addResizeListener() {
        let resizeTimeout;
        window.addEventListener('resize', () => {
            clearTimeout(resizeTimeout);
            resizeTimeout = setTimeout(() => {
                this.updateMobileTimestamps();
            }, 250);
        });
    }

    // Initialize mobile functionality after header loads
    async initializeMobileAfterHeader() {
        // Wait a bit for header to fully load
        setTimeout(() => {
            this.setupMobileMenu();
            this.updateMobileTogglePosition();
        }, 100);
    }
}

AdminDashboard.prototype.fetchDataImportOverview = async function () {
    const response = await fetch('../backend/api/get_data_import_overview.php', {
        credentials: 'include',
        cache: 'no-store'
    });
    const data = await this.safeJson(response, { success: false, datasets: [], runs: [] });
    if (!response.ok || !data.success) {
        throw new Error(data.message || 'Unable to load data import overview.');
    }

    this.importDataState = {
        ...this.importDataState,
        datasets: Array.isArray(data.datasets) ? data.datasets : [],
        runs: Array.isArray(data.runs) ? data.runs : []
    };

    return this.importDataState;
};

AdminDashboard.prototype.loadDataImportContent = async function () {
    const state = await this.fetchDataImportOverview();
    const runs = state.runs || [];
    const successfulRuns = runs.filter((run) => run.run_status === 'success').length;
    const lastRun = runs[0] || null;

    return `
        <div class="settings-content import-data-content">
            <div class="settings-header">
                <div>
                    <h2 class="section-title">Import Data</h2>
                    <p class="section-subtitle">Bring master data into the platform using controlled templates, dry-run validation, smart blank-field merges, and full audit visibility.</p>
                </div>
                <div class="settings-actions">
                    <button class="action-btn secondary" id="refreshImportDataBtn" type="button">Refresh</button>
                </div>
            </div>

            <div class="import-summary-grid">
                <div class="user-summary-card import-summary-card">
                    <div class="user-summary-icon">&#128190;</div>
                    <div>
                        <div class="user-summary-value">${state.datasets.length}</div>
                        <div class="user-summary-label">Import Packs</div>
                    </div>
                </div>
                <div class="user-summary-card import-summary-card">
                    <div class="user-summary-icon">&#128202;</div>
                    <div>
                        <div class="user-summary-value">${runs.length}</div>
                        <div class="user-summary-label">Recent Runs</div>
                    </div>
                </div>
                <div class="user-summary-card import-summary-card">
                    <div class="user-summary-icon">&#10003;</div>
                    <div>
                        <div class="user-summary-value">${successfulRuns}</div>
                        <div class="user-summary-label">Successful Runs</div>
                    </div>
                </div>
                <div class="user-summary-card import-summary-card">
                    <div class="user-summary-icon">&#9200;</div>
                    <div>
                        <div class="user-summary-value">${lastRun ? this.escapeHtml(lastRun.execution_mode === 'dry_run' ? 'Preview' : 'Applied') : 'None'}</div>
                        <div class="user-summary-label">${lastRun ? `Last Run - ${this.escapeHtml(lastRun.dataset_label || '')}` : 'No import history yet'}</div>
                    </div>
                </div>
            </div>

            <div class="import-guidance-grid">
                <div class="settings-card import-guidance-card">
                    <div class="settings-card-header">
                        <div>
                            <h3>How Smart Import Works</h3>
                            <p>Each import run passes through a dry validation pass before the actual write.</p>
                        </div>
                    </div>
                    <div class="import-guidance-list">
                        <div class="import-guidance-item">
                            <span class="import-guidance-step">1</span>
                            <div>
                                <strong>Download the template</strong>
                                <p>Templates expose the expected field names, formats, and example values for each dataset.</p>
                            </div>
                        </div>
                        <div class="import-guidance-item">
                            <span class="import-guidance-step">2</span>
                            <div>
                                <strong>Run a dry check</strong>
                                <p>The system validates required fields, data types, and match keys before writing anything.</p>
                            </div>
                        </div>
                        <div class="import-guidance-item">
                            <span class="import-guidance-step">3</span>
                            <div>
                                <strong>Apply the import</strong>
                                <p>Exact matches are skipped, blank fields can be enriched, and conflicting populated values are flagged for review.</p>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="settings-card import-guidance-card">
                    <div class="settings-card-header">
                        <div>
                            <h3>Operational Safeguards</h3>
                            <p>Every run is recorded in the audit trail and preserved for later review.</p>
                        </div>
                    </div>
                    <div class="import-policy-grid">
                        <div class="import-policy-pill"><strong>Accepted files:</strong> CSV, XLSX</div>
                        <div class="import-policy-pill"><strong>Merge rule:</strong> Fill blanks only</div>
                        <div class="import-policy-pill"><strong>Duplicates:</strong> Skip exact matches</div>
                        <div class="import-policy-pill"><strong>Conflicts:</strong> Flag for manual review</div>
                    </div>
                </div>
            </div>

            <div class="import-card-grid">
                ${state.datasets.map((dataset) => this.renderImportDatasetCard(dataset)).join('')}
            </div>

            <div class="settings-card import-history-card">
                <div class="settings-card-header">
                    <div>
                        <h3>Import Run History</h3>
                        <p>Review what was imported, who ran it, and which rows were inserted, merged, skipped, or flagged.</p>
                    </div>
                </div>
                <div class="settings-table-container">
                    <table class="settings-table import-history-table">
                        <thead>
                            <tr>
                                <th>Dataset</th>
                                <th>File</th>
                                <th>Mode</th>
                                <th>Status</th>
                                <th>Inserted</th>
                                <th>Merged</th>
                                <th>Flagged</th>
                                <th>Run By</th>
                                <th>Completed</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody id="importHistoryTableBody">
                            ${this.renderImportHistoryRows(runs)}
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    `;
};

AdminDashboard.prototype.renderImportDatasetCard = function (dataset) {
    const rowCount = Number(dataset.row_count || 0);
    return `
        <section class="settings-card import-dataset-card" data-dataset-card="${this.escapeHtml(dataset.key)}">
            <div class="import-card-head">
                <div class="import-card-icon">${dataset.icon || '&#128190;'}</div>
                <div>
                    <h3>${this.escapeHtml(dataset.label || 'Dataset')}</h3>
                    <p>${this.escapeHtml(dataset.description || '')}</p>
                </div>
            </div>
            <div class="import-card-meta">
                <span class="import-meta-chip">Rows in table: <strong>${rowCount}</strong></span>
                <span class="import-meta-chip">Match key: <strong>${this.escapeHtml(dataset.key_label || '')}</strong></span>
                <span class="import-meta-chip">Formats: <strong>${this.escapeHtml((dataset.accepted_formats || []).join(', ').toUpperCase())}</strong></span>
            </div>
            <div class="import-card-requirements">
                ${(dataset.requirements || []).map((item) => `<div class="import-requirement-item">${this.escapeHtml(item)}</div>`).join('')}
            </div>
            <div class="import-card-actions">
                <button class="action-btn secondary" type="button" data-import-template="${this.escapeHtml(dataset.key)}">Download Template</button>
                <button class="action-btn" type="button" data-open-import="${this.escapeHtml(dataset.key)}">Open Import</button>
            </div>
        </section>
    `;
};

AdminDashboard.prototype.renderImportHistoryRows = function (runs) {
    if (!Array.isArray(runs) || !runs.length) {
        return '<tr><td colspan="10"><div class="table-loading">No import runs recorded yet.</div></td></tr>';
    }

    return runs.map((run) => {
        const flagged = Number(run.conflict_rows || 0) + Number(run.invalid_rows || 0) + Number(run.failed_rows || 0);
        return `
            <tr>
                <td>${this.escapeHtml(run.dataset_label || 'Unknown')}</td>
                <td class="import-file-cell">${this.escapeHtml(run.source_file_name || 'Manual run')}</td>
                <td><span class="import-mode-pill ${run.execution_mode === 'dry_run' ? 'preview' : 'apply'}">${this.escapeHtml(run.execution_mode === 'dry_run' ? 'Dry Check' : 'Import')}</span></td>
                <td><span class="import-status-pill ${this.escapeHtml(run.run_status || 'success')}">${this.escapeHtml(this.formatImportRunStatus(run.run_status))}</span></td>
                <td>${Number(run.inserted_rows || 0)}</td>
                <td>${Number(run.merged_rows || 0)}</td>
                <td>${flagged}</td>
                <td>${this.escapeHtml(run.created_by_name || 'System')}</td>
                <td>${this.escapeHtml(run.completed_at || run.started_at || '')}</td>
                <td>
                    <button class="action-btn secondary compact" type="button" data-view-import-run="${Number(run.import_run_id || 0)}">View Report</button>
                </td>
            </tr>
        `;
    }).join('');
};

AdminDashboard.prototype.formatImportRunStatus = function (status) {
    const map = {
        success: 'Clean',
        partial: 'Needs Review',
        failed: 'Failed'
    };
    return map[String(status || '').toLowerCase()] || 'Unknown';
};

AdminDashboard.prototype.getImportDatasetLabel = function (datasetKey, report = null) {
    const dataset = (this.importDataState.datasets || []).find((item) => item.key === datasetKey);
    if (report && report.dataset_label) {
        return String(report.dataset_label);
    }
    if (dataset && dataset.label) {
        return String(dataset.label);
    }
    return String(datasetKey || 'Import')
        .replace(/_/g, ' ')
        .replace(/\b\w/g, (match) => match.toUpperCase());
};

AdminDashboard.prototype.getImportFeedbackType = function (mode, status) {
    const normalized = String(status || '').toLowerCase();
    if (normalized === 'failed') {
        return 'error';
    }
    if (normalized === 'partial') {
        return 'warning';
    }
    return mode === 'dry_run' ? 'info' : 'success';
};

AdminDashboard.prototype.buildImportFeedbackMessage = function (datasetKey, mode, status, report = null, fallbackMessage = '') {
    const label = this.getImportDatasetLabel(datasetKey, report);
    const summary = report && report.summary ? report.summary : {};
    const totalRows = Number(summary.total_rows || 0);
    const insertedRows = Number(summary.inserted_rows || 0);
    const mergedRows = Number(summary.merged_rows || 0);
    const skippedRows = Number(summary.skipped_exact_rows || 0);
    const conflictRows = Number(summary.conflict_rows || 0);
    const invalidRows = Number(summary.invalid_rows || 0) + Number(summary.failed_rows || 0);
    const normalized = String(status || '').toLowerCase();
    const actionLabel = mode === 'dry_run' ? 'dry check' : 'import';

    if (normalized === 'failed') {
        return `${label} ${actionLabel} failed. ${fallbackMessage || 'Review the inline report for details.'}`;
    }

    if (mode === 'dry_run') {
        if (normalized === 'partial') {
            return `${label} dry check completed with review items. ${totalRows} row(s) reviewed, ${conflictRows} conflict(s), ${invalidRows} invalid/failed row(s).`;
        }
        return `${label} dry check completed. ${totalRows} row(s) reviewed and the file is ready for import.`;
    }

    if (normalized === 'partial') {
        return `${label} import completed with review items. ${insertedRows} inserted, ${mergedRows} merged, ${conflictRows} conflict(s), ${invalidRows} invalid/failed row(s).`;
    }

    return `${label} import completed successfully. ${insertedRows} inserted, ${mergedRows} merged, ${skippedRows} skipped exact.`;
};

AdminDashboard.prototype.initializeDataImport = async function () {
    document.getElementById('refreshImportDataBtn')?.addEventListener('click', async () => {
        await this.refreshCurrentSection();
    });

    document.querySelectorAll('[data-import-template]').forEach((button) => {
        button.addEventListener('click', () => {
            const datasetKey = button.getAttribute('data-import-template');
            if (!datasetKey) return;
            window.location.href = `../backend/api/download_import_template.php?dataset=${encodeURIComponent(datasetKey)}`;
        });
    });

    document.querySelectorAll('[data-open-import]').forEach((button) => {
        button.addEventListener('click', () => {
            const datasetKey = button.getAttribute('data-open-import');
            if (!datasetKey) return;
            this.openDataImportModal(datasetKey);
        });
    });

    document.querySelectorAll('[data-view-import-run]').forEach((button) => {
        button.addEventListener('click', () => {
            const runId = Number(button.getAttribute('data-view-import-run') || 0);
            if (!runId) return;
            const run = (this.importDataState.runs || []).find((item) => Number(item.import_run_id) === runId);
            if (!run) return;
            this.openImportRunReportModal(run);
        });
    });
};

AdminDashboard.prototype.openDataImportModal = function (datasetKey) {
    const dataset = (this.importDataState.datasets || []).find((item) => item.key === datasetKey);
    if (!dataset) {
        this.showNotification('Import pack not found.', 'error');
        return;
    }

    this.importDataState.activeDatasetKey = datasetKey;
    document.querySelector('#dataImportModalOverlay')?.remove();

    const overlay = document.createElement('div');
    overlay.className = 'admin-modal-overlay';
    overlay.id = 'dataImportModalOverlay';
    overlay.innerHTML = `
        <div class="admin-modal import-modal">
            <div class="admin-modal-header">
                <div>
                    <h3>${this.escapeHtml(dataset.label)}</h3>
                    <p class="import-modal-subtitle">${this.escapeHtml(dataset.description || '')}</p>
                </div>
                <button class="admin-modal-close" type="button" aria-label="Close">&times;</button>
            </div>
            <form class="admin-modal-body import-modal-body" id="dataImportForm">
                <div class="import-modal-summary">
                    <span class="import-meta-chip">Match key: <strong>${this.escapeHtml(dataset.key_label || '')}</strong></span>
                    <span class="import-meta-chip">Accepted files: <strong>${this.escapeHtml((dataset.accepted_formats || []).join(', ').toUpperCase())}</strong></span>
                    <span class="import-meta-chip">Rows in table: <strong>${Number(dataset.row_count || 0)}</strong></span>
                </div>

                <div class="import-modal-block">
                    <h4>Import Requirements</h4>
                    <div class="import-card-requirements">
                        ${(dataset.requirements || []).map((item) => `<div class="import-requirement-item">${this.escapeHtml(item)}</div>`).join('')}
                    </div>
                </div>

                <div class="import-modal-block">
                    <div class="import-template-bar">
                        <div>
                            <h4>Template & Source File</h4>
                            <p>Download the official template, populate it, then upload the completed file for validation or import.</p>
                        </div>
                        <button class="action-btn secondary" type="button" data-import-template-download="${this.escapeHtml(dataset.key)}">Download CSV Template</button>
                    </div>
                    <div class="form-grid">
                        <div class="form-field form-span">
                            <label>Import File</label>
                            <input type="file" name="import_file" accept=".csv,.xlsx,.xlxl" required>
                            <small class="field-help">Use the template headers exactly as provided. CSV and XLSX are supported.</small>
                        </div>
                    </div>
                </div>

                <div class="import-modal-block import-report-block hidden" id="importReportContainer"></div>
            </form>
            <div class="admin-modal-footer import-modal-footer">
                <button class="action-btn secondary" type="button" data-import-cancel>Cancel</button>
                <button class="action-btn secondary" type="button" data-import-dry-run>Run Dry Check</button>
                <button class="action-btn" type="button" data-import-apply>Import Data</button>
            </div>
        </div>
    `;

    document.body.appendChild(overlay);

    const closeModal = () => overlay.remove();
    overlay.querySelector('.admin-modal-close')?.addEventListener('click', closeModal);
    overlay.querySelector('[data-import-cancel]')?.addEventListener('click', closeModal);
    overlay.addEventListener('click', (event) => {
        if (event.target === overlay) {
            closeModal();
        }
    });

    overlay.querySelector('[data-import-template-download]')?.addEventListener('click', () => {
        window.location.href = `../backend/api/download_import_template.php?dataset=${encodeURIComponent(dataset.key)}`;
    });

    overlay.querySelector('[data-import-dry-run]')?.addEventListener('click', () => {
        this.submitDataImportForm(dataset.key, overlay.querySelector('#dataImportForm'), 'dry_run');
    });

    overlay.querySelector('[data-import-apply]')?.addEventListener('click', () => {
        this.submitDataImportForm(dataset.key, overlay.querySelector('#dataImportForm'), 'import');
    });
};

AdminDashboard.prototype.submitDataImportForm = async function (datasetKey, form, mode) {
    if (!form) return;

    const fileInput = form.querySelector('input[name="import_file"]');
    const reportContainer = form.querySelector('#importReportContainer');
    if (!fileInput || !fileInput.files || !fileInput.files.length) {
        const missingFileMessage = `Select a ${this.getImportDatasetLabel(datasetKey).toLowerCase()} file before continuing.`;
        this.renderImportInlineReport(reportContainer, {
            dataset_label: 'Validation',
            execution_mode: mode,
            summary: { total_rows: 0, inserted_rows: 0, merged_rows: 0, skipped_exact_rows: 0, conflict_rows: 0, invalid_rows: 1, failed_rows: 0 },
            rows: [{ row_number: '-', key_value: '', status: 'invalid', message: 'Select an import file before continuing.', merged_fields: [], conflict_fields: [] }]
        }, 'partial');
        this.showNotification(missingFileMessage, 'error');
        return;
    }

    const payload = new FormData();
    payload.append('dataset', datasetKey);
    payload.append('mode', mode);
    payload.append('import_file', fileInput.files[0]);

    this.renderImportInlineLoading(reportContainer, mode);

    try {
        const requestFn = mode === 'import' ? this.performSensitiveAdminRequest.bind(this) : fetch;
        const response = await requestFn('../backend/api/process_data_import.php', {
            method: 'POST',
            credentials: 'include',
            body: payload
        }, 'apply the selected data import');
        const data = response.__adminPayloadAttached || await this.safeJson(response, { success: false });
        if (!response.ok || !data.success) {
            throw new Error(data.message || 'Import processing failed.');
        }

        this.importDataState.lastReport = data.report || null;
        this.renderImportInlineReport(reportContainer, data.report || null, data.status || 'success', data.message || '');
        const reviewDownloadStarted = this.downloadImportReviewExport(data.review_export);
        this.showNotification(
            this.buildImportFeedbackMessage(datasetKey, mode, data.status || 'success', data.report || null, data.message || '')
              + (reviewDownloadStarted ? ' Review file download started.' : ''),
            this.getImportFeedbackType(mode, data.status || 'success')
        );
        await this.fetchDataImportOverview();
        if (datasetKey === 'titles') {
            await this.loadTitleSettings();
        }
        if (this.currentSection === 'data-import') {
            const contentBody = document.getElementById('contentBody');
            if (contentBody) {
                contentBody.innerHTML = await this.loadDataImportContent();
                this.initializeSectionScripts('data-import');
            }
        }
    } catch (error) {
        console.error('Data import error:', error);
        this.renderImportInlineReport(reportContainer, {
            dataset_label: 'Import Error',
            execution_mode: mode,
            summary: { total_rows: 0, inserted_rows: 0, merged_rows: 0, skipped_exact_rows: 0, conflict_rows: 0, invalid_rows: 0, failed_rows: 1 },
            rows: [{ row_number: '-', key_value: '', status: 'failed', message: error.message || 'Import processing failed.', merged_fields: [], conflict_fields: [] }]
        }, 'failed', error.message || 'Import processing failed.');
        this.showNotification(
            this.buildImportFeedbackMessage(datasetKey, mode, 'failed', null, error.message || 'Import processing failed.'),
            'error'
        );
    }
};

AdminDashboard.prototype.downloadImportReviewExport = function (reviewExport) {
    if (!reviewExport || !reviewExport.content_base64) {
        return false;
    }

    try {
        const binary = window.atob(String(reviewExport.content_base64 || ''));
        const bytes = new Uint8Array(binary.length);
        for (let index = 0; index < binary.length; index += 1) {
            bytes[index] = binary.charCodeAt(index);
        }
        const blob = new Blob([bytes], {
            type: reviewExport.mime || 'text/csv;charset=utf-8;'
        });
        this.downloadBlob(blob, reviewExport.file_name || 'import_review.csv');
        return true;
    } catch (error) {
        console.error('Failed to download review export:', error);
        return false;
    }
};

AdminDashboard.prototype.renderImportInlineLoading = function (container, mode) {
    if (!container) return;
    container.classList.remove('hidden');
    container.innerHTML = `
        <div class="import-report-state">
            <div class="loading-spinner"></div>
            <div>
                <strong>${mode === 'dry_run' ? 'Running dry check...' : 'Applying import...'}</strong>
                <p>This may take a few moments while the system validates and compares incoming rows.</p>
            </div>
        </div>
    `;
};

AdminDashboard.prototype.renderImportInlineReport = function (container, report, status = 'success', message = '') {
    if (!container || !report) return;
    container.classList.remove('hidden');
    const summary = report.summary || {};
    const rows = Array.isArray(report.rows) ? report.rows : [];
    const statusClass = this.escapeHtml(String(status || 'success'));

    container.innerHTML = `
        <div class="import-report-panel">
            <div class="import-report-head">
                <div>
                    <h4>${this.escapeHtml(report.dataset_label || 'Import Report')}</h4>
                    <p>${this.escapeHtml(message || (report.execution_mode === 'dry_run' ? 'Dry check completed.' : 'Import completed.'))}</p>
                </div>
                <span class="import-status-pill ${statusClass}">${this.escapeHtml(this.formatImportRunStatus(status))}</span>
            </div>
            <div class="import-report-summary-grid">
                <div class="import-report-metric"><span>Total Rows</span><strong>${Number(summary.total_rows || 0)}</strong></div>
                <div class="import-report-metric"><span>Inserted</span><strong>${Number(summary.inserted_rows || 0)}</strong></div>
                <div class="import-report-metric"><span>Merged</span><strong>${Number(summary.merged_rows || 0)}</strong></div>
                <div class="import-report-metric"><span>Skipped Exact</span><strong>${Number(summary.skipped_exact_rows || 0)}</strong></div>
                <div class="import-report-metric"><span>Conflicts</span><strong>${Number(summary.conflict_rows || 0)}</strong></div>
                <div class="import-report-metric"><span>Invalid/Failed</span><strong>${Number(summary.invalid_rows || 0) + Number(summary.failed_rows || 0)}</strong></div>
            </div>
            <div class="import-field-table-wrap">
                <table class="settings-table import-report-table">
                    <thead>
                        <tr>
                            <th>Row</th>
                            <th>Key</th>
                            <th>Status</th>
                            <th>Message</th>
                            <th>Merged Fields</th>
                            <th>Conflict Fields</th>
                        </tr>
                    </thead>
                    <tbody>
                        ${rows.length ? rows.map((row) => `
                            <tr>
                                <td>${this.escapeHtml(String(row.row_number ?? ''))}</td>
                                <td>${this.escapeHtml(String(row.key_value ?? ''))}</td>
                                <td><span class="import-status-pill ${this.escapeHtml(String(row.status || 'success'))}">${this.escapeHtml(String(row.status || ''))}</span></td>
                                <td>${this.escapeHtml(String(row.message || ''))}</td>
                                <td>${this.escapeHtml((row.merged_fields || []).join(', ') || '-') }</td>
                                <td>${this.escapeHtml((row.conflict_fields || []).join(', ') || '-') }</td>
                            </tr>
                        `).join('') : '<tr><td colspan="6"><div class="table-loading">No row-level issues were returned.</div></td></tr>'}
                    </tbody>
                </table>
            </div>
        </div>
    `;
};

AdminDashboard.prototype.openImportRunReportModal = function (run) {
    document.querySelector('#importRunReportOverlay')?.remove();
    const overlay = document.createElement('div');
    overlay.className = 'admin-modal-overlay';
    overlay.id = 'importRunReportOverlay';
    overlay.innerHTML = `
        <div class="admin-modal import-report-modal">
            <div class="admin-modal-header">
                <div>
                    <h3>${this.escapeHtml(run.dataset_label || 'Import Report')}</h3>
                    <p class="import-modal-subtitle">${this.escapeHtml(run.source_file_name || 'Import run')}</p>
                </div>
                <button class="admin-modal-close" type="button" aria-label="Close">&times;</button>
            </div>
            <div class="admin-modal-body import-modal-body">
                <div class="import-modal-summary">
                    <span class="import-meta-chip">Mode: <strong>${this.escapeHtml(run.execution_mode === 'dry_run' ? 'Dry Check' : 'Import')}</strong></span>
                    <span class="import-meta-chip">Status: <strong>${this.escapeHtml(this.formatImportRunStatus(run.run_status))}</strong></span>
                    <span class="import-meta-chip">Run By: <strong>${this.escapeHtml(run.created_by_name || 'System')}</strong></span>
                    <span class="import-meta-chip">Completed: <strong>${this.escapeHtml(run.completed_at || run.started_at || '')}</strong></span>
                </div>
                <div id="importRunReportContent"></div>
            </div>
            <div class="admin-modal-footer">
                <button class="action-btn secondary" type="button" data-close-import-report>Close</button>
            </div>
        </div>
    `;
    document.body.appendChild(overlay);
    this.renderImportInlineReport(overlay.querySelector('#importRunReportContent'), run.report || {
        dataset_label: run.dataset_label || 'Import Report',
        execution_mode: run.execution_mode || 'import',
        summary: {
            total_rows: run.total_rows || 0,
            inserted_rows: run.inserted_rows || 0,
            merged_rows: run.merged_rows || 0,
            skipped_exact_rows: run.skipped_exact_rows || 0,
            conflict_rows: run.conflict_rows || 0,
            invalid_rows: run.invalid_rows || 0,
            failed_rows: run.failed_rows || 0
        },
        rows: []
    }, run.run_status || 'success');

    const close = () => overlay.remove();
    overlay.querySelector('.admin-modal-close')?.addEventListener('click', close);
    overlay.querySelector('[data-close-import-report]')?.addEventListener('click', close);
    overlay.addEventListener('click', (event) => {
        if (event.target === overlay) close();
    });
};

AdminDashboard.prototype.fetchRegistryBoxAllocationSummary = async function () {
    const response = await fetch('../backend/api/get_registry_box_allocation_summary.php', {
        credentials: 'include',
        cache: 'no-store'
    });
    const data = await response.json();
    if (!response.ok || !data.success) {
        throw new Error(data.message || 'Unable to load registry box allocation summary.');
    }
    return data.summary || {};
};

AdminDashboard.prototype.fetchDataManagementOverview = async function () {
    const response = await fetch('../backend/api/get_data_management_overview.php', {
        credentials: 'include',
        cache: 'no-store'
    });
    const data = await response.json();
    if (!response.ok || !data.success) {
        throw new Error(data.message || 'Unable to load data management overview.');
    }
    return data.overview || {};
};

AdminDashboard.prototype.formatBytes = function (bytes) {
    const value = Number(bytes || 0);
    if (!Number.isFinite(value) || value <= 0) {
        return '0 B';
    }
    const units = ['B', 'KB', 'MB', 'GB', 'TB'];
    let size = value;
    let unitIndex = 0;
    while (size >= 1024 && unitIndex < units.length - 1) {
        size /= 1024;
        unitIndex += 1;
    }
    return `${size.toFixed(size >= 10 || unitIndex === 0 ? 0 : 1)} ${units[unitIndex]}`;
};

AdminDashboard.prototype.loadDataBackupContent = async function () {
    const overview = await this.fetchDataManagementOverview();
    const runs = Array.isArray(overview.backup_runs) ? overview.backup_runs : [];
    const lastBackup = runs[0] || null;
    return `
        <div class="settings-content data-backup-content">
            <div class="settings-header">
                <div>
                    <h2 class="section-title">Backup & Restore</h2>
                    <p class="section-subtitle">Create controlled system backups, restore approved archives, and maintain a verifiable recovery trail.</p>
                </div>
                <div class="settings-actions">
                    <button class="action-btn secondary" id="refreshDataBackupBtn" type="button">Refresh</button>
                </div>
            </div>

            <div class="cleanup-summary-grid">
                <div class="user-summary-card cleanup-summary-card">
                    <div class="user-summary-icon">&#128190;</div>
                    <div>
                        <div class="user-summary-value">${runs.length}</div>
                        <div class="user-summary-label">Recorded Backups</div>
                    </div>
                </div>
                <div class="user-summary-card cleanup-summary-card">
                    <div class="user-summary-icon">&#9200;</div>
                    <div>
                        <div class="user-summary-value">${lastBackup ? this.escapeHtml(String(lastBackup.backup_type || 'manual')) : 'None'}</div>
                        <div class="user-summary-label">${lastBackup ? `Last backup - ${this.escapeHtml(String(lastBackup.backup_time || ''))}` : 'No backups yet'}</div>
                    </div>
                </div>
                <div class="user-summary-card cleanup-summary-card">
                    <div class="user-summary-icon">&#128194;</div>
                    <div>
                        <div class="user-summary-value">${this.escapeHtml(String(overview.paths?.backup_path || 'backend/backups'))}</div>
                        <div class="user-summary-label">Storage Path</div>
                    </div>
                </div>
                <div class="user-summary-card cleanup-summary-card">
                    <div class="user-summary-icon">&#9851;</div>
                    <div>
                        <div class="user-summary-value">${Number(overview.settings?.backup_retention_days || 90)}</div>
                        <div class="user-summary-label">Retention (Days)</div>
                    </div>
                </div>
            </div>

            <div class="cleanup-card-grid">
                <section class="settings-card cleanup-tool-card">
                    <div class="settings-card-header">
                        <div>
                            <h3>Create Backup</h3>
                            <p>Generate a manual recovery point with the database payload and optional uploaded files archive.</p>
                        </div>
                    </div>
                    <div class="cleanup-policy-grid">
                        <div class="import-policy-pill"><strong>Modes:</strong> Full system, database only, uploads only</div>
                        <div class="import-policy-pill"><strong>Format:</strong> ZIP archive with metadata</div>
                        <div class="import-policy-pill"><strong>Audit:</strong> Logged to backup history</div>
                    </div>
                    <div class="cleanup-card-actions">
                        <button class="action-btn" type="button" id="openCreateBackupBtn">Create Backup</button>
                    </div>
                </section>

                <section class="settings-card cleanup-tool-card">
                    <div class="settings-card-header">
                        <div>
                            <h3>Restore Backup</h3>
                            <p>Restore a generated backup archive or upload a valid backup package for supervised recovery.</p>
                        </div>
                    </div>
                    <div class="cleanup-warning-panel">
                        <div class="cleanup-warning-title">High impact action</div>
                        <p>Restoring a backup replaces current database content with the backup payload. Use this only when you intentionally want to roll the system state back.</p>
                    </div>
                    <div class="cleanup-card-actions">
                        <button class="action-btn secondary" type="button" id="openRestoreBackupBtn">Restore Backup</button>
                    </div>
                </section>
            </div>

            <section class="settings-card cleanup-box-table-card">
                <div class="settings-card-header">
                    <div>
                        <h3>Backup History</h3>
                        <p>Every backup and restore operation is logged here for audit and controlled recovery.</p>
                    </div>
                </div>
                <div class="settings-table-container">
                    <table class="settings-table cleanup-box-table">
                        <thead>
                            <tr>
                                <th>Label</th>
                                <th>Scope</th>
                                <th>Status</th>
                                <th>Size</th>
                                <th>Created By</th>
                                <th>Backup Time</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            ${runs.length ? runs.map((run) => `
                                <tr>
                                    <td>${this.escapeHtml(String(run.backup_label || run.file_name || 'Backup'))}</td>
                                    <td>${this.escapeHtml(String(run.backup_scope || 'full_system'))}</td>
                                    <td><span class="import-status-pill ${this.escapeHtml(String(run.status || 'success'))}">${this.escapeHtml(String(run.status || 'success'))}</span></td>
                                    <td>${this.escapeHtml(String(this.formatBytes(Number(run.file_size_bytes || 0))))}</td>
                                    <td>${this.escapeHtml(String(run.created_by_name || 'System'))}</td>
                                    <td>${this.escapeHtml(String(run.backup_time || ''))}</td>
                                    <td>
                                        <div class="cleanup-card-actions">
                                            ${run.file_name ? `<button class="action-btn secondary" type="button" data-download-backup="${this.escapeHtml(String(run.file_name))}">Download</button>` : ''}
                                            <button class="action-btn secondary" type="button" data-restore-backup="${this.escapeHtml(String(run.file_name || ''))}">Restore</button>
                                        </div>
                                    </td>
                                </tr>
                            `).join('') : '<tr><td colspan="7"><div class="table-loading">No backup history recorded yet.</div></td></tr>'}
                        </tbody>
                    </table>
                </div>
            </section>
        </div>
    `;
};

AdminDashboard.prototype.loadDataExportContent = async function () {
    const overview = await this.fetchDataManagementOverview();
    const runs = Array.isArray(overview.export_runs) ? overview.export_runs : [];
    const datasets = Array.isArray(overview.export_datasets) ? overview.export_datasets : [];
    return `
        <div class="settings-content data-export-content">
            <div class="settings-header">
                <div>
                    <h2 class="section-title">Export Data</h2>
                    <p class="section-subtitle">Generate controlled operational extracts for governance, reporting, and external analysis without touching live tables.</p>
                </div>
                <div class="settings-actions">
                    <button class="action-btn secondary" id="refreshDataExportBtn" type="button">Refresh</button>
                </div>
            </div>

            <div class="cleanup-summary-grid">
                <div class="user-summary-card cleanup-summary-card">
                    <div class="user-summary-icon">&#128229;</div>
                    <div>
                        <div class="user-summary-value">${datasets.length}</div>
                        <div class="user-summary-label">Export Packs</div>
                    </div>
                </div>
                <div class="user-summary-card cleanup-summary-card">
                    <div class="user-summary-icon">&#128202;</div>
                    <div>
                        <div class="user-summary-value">${runs.length}</div>
                        <div class="user-summary-label">Recent Exports</div>
                    </div>
                </div>
                <div class="user-summary-card cleanup-summary-card">
                    <div class="user-summary-icon">&#128194;</div>
                    <div>
                        <div class="user-summary-value">${this.escapeHtml(String(overview.paths?.export_path || 'backend/exports/data'))}</div>
                        <div class="user-summary-label">Storage Path</div>
                    </div>
                </div>
                <div class="user-summary-card cleanup-summary-card">
                    <div class="user-summary-icon">&#9851;</div>
                    <div>
                        <div class="user-summary-value">${Number(overview.settings?.export_retention_days || 90)}</div>
                        <div class="user-summary-label">Retention (Days)</div>
                    </div>
                </div>
            </div>

            <div class="import-card-grid">
                ${datasets.map((dataset) => `
                    <section class="settings-card import-dataset-card">
                        <div class="import-card-head">
                            <div class="import-card-icon">&#128229;</div>
                            <div>
                                <h3>${this.escapeHtml(String(dataset.label || 'Dataset'))}</h3>
                                <p>${this.escapeHtml(String(dataset.description || ''))}</p>
                            </div>
                        </div>
                        <div class="cleanup-card-actions">
                            <button class="action-btn" type="button" data-open-export="${this.escapeHtml(String(dataset.key || ''))}">Export</button>
                        </div>
                    </section>
                `).join('')}
            </div>

            <section class="settings-card cleanup-box-table-card">
                <div class="settings-card-header">
                    <div>
                        <h3>Export History</h3>
                        <p>Recent export jobs, their formats, and downloadable artifacts.</p>
                    </div>
                </div>
                <div class="settings-table-container">
                    <table class="settings-table cleanup-box-table">
                        <thead>
                            <tr>
                                <th>Dataset</th>
                                <th>Format</th>
                                <th>Status</th>
                                <th>Size</th>
                                <th>Created By</th>
                                <th>Created At</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            ${runs.length ? runs.map((run) => `
                                <tr>
                                    <td>${this.escapeHtml(String(run.dataset_label || 'Export'))}</td>
                                    <td>${this.escapeHtml(String((run.export_format || '').toUpperCase()))}</td>
                                    <td><span class="import-status-pill ${this.escapeHtml(String(run.status || 'success'))}">${this.escapeHtml(String(run.status || 'success'))}</span></td>
                                    <td>${this.escapeHtml(String(this.formatBytes(Number(run.file_size_bytes || 0))))}</td>
                                    <td>${this.escapeHtml(String(run.created_by_name || 'System'))}</td>
                                    <td>${this.escapeHtml(String(run.created_at || ''))}</td>
                                    <td>${run.file_name ? `<button class="action-btn secondary" type="button" data-download-export="${this.escapeHtml(String(run.file_name))}">Download</button>` : ''}</td>
                                </tr>
                            `).join('') : '<tr><td colspan="7"><div class="table-loading">No export jobs recorded yet.</div></td></tr>'}
                        </tbody>
                    </table>
                </div>
            </section>
        </div>
    `;
};

AdminDashboard.prototype.initializeDataBackup = function () {
    document.getElementById('refreshDataBackupBtn')?.addEventListener('click', async () => {
        await this.refreshCurrentSection();
    });
    document.getElementById('openCreateBackupBtn')?.addEventListener('click', () => this.openCreateBackupModal());
    document.getElementById('openRestoreBackupBtn')?.addEventListener('click', () => this.openRestoreBackupModal());
    document.querySelectorAll('[data-download-backup]').forEach((button) => {
        button.addEventListener('click', () => {
            const file = button.getAttribute('data-download-backup');
            if (file) {
                window.location.href = `../backend/api/download_data_artifact.php?type=backup&file=${encodeURIComponent(file)}`;
            }
        });
    });
    document.querySelectorAll('[data-restore-backup]').forEach((button) => {
        button.addEventListener('click', () => {
            const file = button.getAttribute('data-restore-backup');
            this.openRestoreBackupModal(file || '');
        });
    });
};

AdminDashboard.prototype.initializeDataExport = function () {
    document.getElementById('refreshDataExportBtn')?.addEventListener('click', async () => {
        await this.refreshCurrentSection();
    });
    document.querySelectorAll('[data-open-export]').forEach((button) => {
        button.addEventListener('click', () => {
            const datasetKey = button.getAttribute('data-open-export');
            if (datasetKey) {
                this.openDataExportModal(datasetKey);
            }
        });
    });
    document.querySelectorAll('[data-download-export]').forEach((button) => {
        button.addEventListener('click', () => {
            const file = button.getAttribute('data-download-export');
            if (file) {
                window.location.href = `../backend/api/download_data_artifact.php?type=export&file=${encodeURIComponent(file)}`;
            }
        });
    });
};

AdminDashboard.prototype.openCreateBackupModal = function () {
    const overlay = document.createElement('div');
    overlay.className = 'admin-modal-overlay';
    overlay.innerHTML = `
        <div class="admin-modal cleanup-modal">
            <div class="admin-modal-header">
                <div>
                    <h3>Create Backup</h3>
                    <p class="import-modal-subtitle">Generate a recoverable archive with database payload and optional uploaded files.</p>
                </div>
                <button class="admin-modal-close" type="button" aria-label="Close">&times;</button>
            </div>
            <form class="admin-modal-body cleanup-modal-body" id="createBackupForm">
                <div class="form-grid">
                    <div class="form-field">
                        <label>Backup Label</label>
                        <input type="text" name="backup_label" placeholder="Month-end governance backup">
                    </div>
                    <div class="form-field">
                        <label>Backup Scope</label>
                        <select name="backup_scope">
                            <option value="full_system">Full System</option>
                            <option value="database_only">Database Only</option>
                            <option value="uploads_only">Uploads Only</option>
                        </select>
                    </div>
                    <label class="confirm-checkbox form-span">
                        <input type="checkbox" name="include_uploads" checked>
                        <span>Include uploaded files (documents, payroll files, message attachments, profile photos).</span>
                    </label>
                </div>
                <div class="cleanup-warning-panel">
                    <div class="cleanup-warning-title">Archive content</div>
                    <p>The backup is written to the secure backup store and recorded in the audit trail with checksum, size, scope, and operator identity.</p>
                </div>
            </form>
            <div class="admin-modal-footer">
                <button class="action-btn secondary" type="button" data-close-modal>Cancel</button>
                <button class="action-btn" type="button" id="submitCreateBackupBtn">Create Backup</button>
            </div>
        </div>
    `;
    document.body.appendChild(overlay);

    const close = () => overlay.remove();
    overlay.querySelector('.admin-modal-close')?.addEventListener('click', close);
    overlay.querySelector('[data-close-modal]')?.addEventListener('click', close);
    overlay.addEventListener('click', (event) => {
        if (event.target === overlay) close();
    });

    overlay.querySelector('#submitCreateBackupBtn')?.addEventListener('click', async () => {
        const form = overlay.querySelector('#createBackupForm');
        const payload = {
            backup_label: form.backup_label.value.trim(),
            backup_scope: form.backup_scope.value,
            include_uploads: form.include_uploads.checked
        };
        const button = overlay.querySelector('#submitCreateBackupBtn');
        button.disabled = true;
        button.textContent = 'Creating...';
        try {
            const response = await this.performSensitiveAdminRequest('../backend/api/create_system_backup.php', {
                method: 'POST',
                credentials: 'include',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload)
            }, 'create a system backup');
            const data = response.__adminPayloadAttached || await response.json();
            if (!response.ok || !data.success) {
                throw new Error(data.message || 'Unable to create backup.');
            }
            this.showNotification(data.message || 'Backup created successfully.', 'success');
            close();
            if (data.backup?.download_url) {
                window.location.href = data.backup.download_url;
            }
            await this.refreshCurrentSection();
        } catch (error) {
            button.disabled = false;
            button.textContent = 'Create Backup';
            this.showNotification(error.message || 'Unable to create backup.', 'error');
        }
    });
};

AdminDashboard.prototype.openRestoreBackupModal = async function (selectedFile = '') {
    let overview;
    try {
        overview = await this.fetchDataManagementOverview();
    } catch (error) {
        this.showNotification(error.message || 'Unable to load backup history.', 'error');
        return;
    }
    const backups = Array.isArray(overview.backup_runs) ? overview.backup_runs : [];
    const overlay = document.createElement('div');
    overlay.className = 'admin-modal-overlay';
    overlay.innerHTML = `
        <div class="admin-modal cleanup-modal">
            <div class="admin-modal-header">
                <div>
                    <h3>Restore Backup</h3>
                    <p class="import-modal-subtitle">Restore an existing backup archive or upload a compatible package for supervised recovery.</p>
                </div>
                <button class="admin-modal-close" type="button" aria-label="Close">&times;</button>
            </div>
            <form class="admin-modal-body cleanup-modal-body" id="restoreBackupForm">
                <div class="form-grid">
                    <div class="form-field form-span">
                        <label>Existing Backup</label>
                        <select name="backup_file_name">
                            <option value="">Choose a recorded backup</option>
                            ${backups.map((backup) => `
                                <option value="${this.escapeHtml(String(backup.file_name || ''))}" ${selectedFile && String(backup.file_name || '') === selectedFile ? 'selected' : ''}>
                                    ${this.escapeHtml(String(backup.backup_label || backup.file_name || 'Backup'))}
                                </option>
                            `).join('')}
                        </select>
                    </div>
                    <div class="form-field form-span">
                        <label>Or Upload Backup Archive</label>
                        <input type="file" name="restore_file" accept=".zip">
                    </div>
                    <label class="confirm-checkbox form-span">
                        <input type="checkbox" name="restore_files">
                        <span>Restore uploaded files from the archive in addition to the database payload.</span>
                    </label>
                    <label class="confirm-checkbox form-span">
                        <input type="checkbox" name="confirm_restore">
                        <span>I understand that restore will replace current database content with the selected backup state.</span>
                    </label>
                </div>
                <div class="cleanup-warning-panel">
                    <div class="cleanup-warning-title">Recovery safeguard</div>
                    <p>Run this only when you intentionally need to revert the application state. The action is fully audited.</p>
                </div>
            </form>
            <div class="admin-modal-footer">
                <button class="action-btn secondary" type="button" data-close-modal>Cancel</button>
                <button class="action-btn" type="button" id="submitRestoreBackupBtn">Restore Backup</button>
            </div>
        </div>
    `;
    document.body.appendChild(overlay);

    const close = () => overlay.remove();
    overlay.querySelector('.admin-modal-close')?.addEventListener('click', close);
    overlay.querySelector('[data-close-modal]')?.addEventListener('click', close);
    overlay.addEventListener('click', (event) => {
        if (event.target === overlay) close();
    });

    overlay.querySelector('#submitRestoreBackupBtn')?.addEventListener('click', async () => {
        const form = overlay.querySelector('#restoreBackupForm');
        if (!form.confirm_restore.checked) {
            this.showNotification('Confirm the restore action before proceeding.', 'warning');
            return;
        }
        const button = overlay.querySelector('#submitRestoreBackupBtn');
        button.disabled = true;
        button.textContent = 'Restoring...';
        try {
            const formData = new FormData();
            if (form.backup_file_name.value) {
                formData.append('backup_file_name', form.backup_file_name.value);
            }
            if (form.restore_file.files[0]) {
                formData.append('restore_file', form.restore_file.files[0]);
            }
            if (form.restore_files.checked) {
                formData.append('restore_files', '1');
            }
            const response = await this.performSensitiveAdminRequest('../backend/api/restore_system_backup.php', {
                method: 'POST',
                credentials: 'include',
                body: formData
            }, 'restore a backup');
            const data = response.__adminPayloadAttached || await response.json();
            if (!response.ok || !data.success) {
                throw new Error(data.message || 'Unable to restore backup.');
            }
            this.showNotification(data.message || 'Backup restored successfully.', 'success');
            close();
            await this.refreshCurrentSection();
        } catch (error) {
            button.disabled = false;
            button.textContent = 'Restore Backup';
            this.showNotification(error.message || 'Unable to restore backup.', 'error');
        }
    });
};

AdminDashboard.prototype.getExportFieldLabel = function (fieldKey) {
    const custom = {
        user_title: 'Title',
        user_name: 'Name',
        role_key: 'Role',
        account_status: 'Account Status',
        file_number: 'File Number',
        computer_number: 'Computer Number',
        supplier_number: 'Supplier Number',
        full_name: 'Full Name',
        living_status: 'Living Status',
        life_certificate_status: 'Life Certificate',
        date_of_birth: 'Date of Birth',
        date_of_enlistment: 'Date of Enlistment',
        retirement_date: 'Date of Retirement',
        retirement_type: 'Retirement Label',
        tin_number: 'TIN',
        nin_number: 'NIN',
        phone_number: 'Phone Number',
        email_address: 'Email Address',
        postal_address: 'Address',
        next_of_kin: 'Next of Kin',
        next_of_kin_contact: 'Next of Kin Contact',
        bank_name: 'Bank Name',
        bank_account: 'Bank Account',
        bank_branch: 'Bank Branch',
        monthly_salary: 'Monthly Salary',
        length_of_service_months: 'Length of Service (Months)',
        annual_salary: 'Annual Salary',
        reduced_pension: 'Reduced Pension',
        full_pension: 'Full Pension',
        commuted_gratuity: 'Commuted Gratuity',
        payroll_status: 'Payroll Status',
        pay_type: 'Pay Type',
        date_on_15_years: 'Date On 15 Years',
        period_to_15_years: 'Period To 15 Years',
        period_from_15_years: 'Period From 15 Years',
        availability_status: 'Availability',
        availability_reason: 'Availability Reason',
        document_count: 'Document Count',
        uploaded_documents: 'Uploaded Documents',
        recorded_at: 'Recorded At'
    };

    const pretty = String(fieldKey || '')
        .split('_')
        .filter(Boolean)
        .map((segment) => segment.charAt(0).toUpperCase() + segment.slice(1))
        .join(' ');

    return custom[fieldKey] || pretty;
};

AdminDashboard.prototype.openDataExportModal = async function (datasetKey) {
    let overview;
    try {
        overview = await this.fetchDataManagementOverview();
    } catch (error) {
        this.showNotification(error.message || 'Unable to load export settings.', 'error');
        return;
    }
    const dataset = (overview.export_datasets || []).find((item) => item.key === datasetKey);
    if (!dataset) {
        this.showNotification('Unknown export dataset.', 'error');
        return;
    }

    const fieldGroups = dataset.field_groups || { 'Available Fields': Object.keys(dataset.columns || {}) };
    const datasetFilters = dataset.filters || {};
    const exportConfigFields = `
                <div class="export-config-grid">
                    <div class="form-field export-span-2">
                        <label>Search</label>
                        <input type="text" name="search" placeholder="Filter records by typing a search phrase">
                        <small class="field-hint">Leave search and filters blank to export the full dataset. Use the field checklist below to control which columns appear in the final document.</small>
                    </div>
                    ${Object.entries(datasetFilters).map(([filterKey, filterDef]) => `
                        <div class="form-field">
                            <label>${this.escapeHtml(String(filterDef.label || filterKey))}</label>
                            <select name="${this.escapeHtml(String(filterKey))}">
                                <option value="">All</option>
                                ${(Array.isArray(filterDef.options) ? filterDef.options : []).map((option) => `
                                    <option value="${this.escapeHtml(String(option))}">${this.escapeHtml(String(option))}</option>
                                `).join('')}
                            </select>
                        </div>
                    `).join('')}
                </div>
                <div class="cleanup-warning-panel">
                    <div class="cleanup-warning-title">Field Selection</div>
                    <p>Choose only the columns required in the export. Records are exported in row-based tabular format.</p>
                </div>
                <div class="export-field-toolbar">
                    <button type="button" class="action-btn secondary small" data-export-select="all">Select All Fields</button>
                    <button type="button" class="action-btn secondary small" data-export-select="core">Core Fields</button>
                    <button type="button" class="action-btn secondary small" data-export-select="clear">Clear Selection</button>
                </div>
                <div class="export-field-groups">
                    ${Object.entries(fieldGroups).map(([groupName, fields]) => `
                        <section class="export-field-group">
                            <div class="export-field-group-head">
                                <h4>${this.escapeHtml(String(groupName))}</h4>
                                <span>${Array.isArray(fields) ? fields.length : 0} fields</span>
                            </div>
                            <div class="export-field-grid">
                                ${(Array.isArray(fields) ? fields : []).map((fieldKey) => `
                                    <label class="export-field-option">
                                        <input type="checkbox" name="selected_fields" value="${this.escapeHtml(String(fieldKey))}" checked>
                                        <span>${this.escapeHtml(String(this.getExportFieldLabel(fieldKey)))}</span>
                                    </label>
                                `).join('')}
                            </div>
                        </section>
                    `).join('')}
                </div>
    `;

    const overlay = document.createElement('div');
    overlay.className = 'admin-modal-overlay';
    overlay.innerHTML = `
        <div class="admin-modal cleanup-modal">
            <div class="admin-modal-header">
                <div>
                    <h3>Export ${this.escapeHtml(String(dataset.label || 'Data'))}</h3>
                    <p class="import-modal-subtitle">${this.escapeHtml(String(dataset.description || 'Generate an export artifact for this dataset.'))}</p>
                </div>
                <button class="admin-modal-close" type="button" aria-label="Close">&times;</button>
            </div>
            <form class="admin-modal-body cleanup-modal-body" id="dataExportForm">
                <div class="form-grid">
                      <div class="form-field">
                          <label>Export Format</label>
                          <select name="format">
                              <option value="xlsx">XLSX</option>
                              <option value="pdf">PDF</option>
                              <option value="csv">CSV</option>
                              <option value="json">JSON</option>
                          </select>
                      </div>
                </div>
                ${exportConfigFields}
                <div class="cleanup-warning-panel">
                    <div class="cleanup-warning-title">Governed extract</div>
                    <p>The generated file is written to the export store, logged in the audit trail, and available for secure download from Export History.</p>
                </div>
            </form>
            <div class="admin-modal-footer">
                <button class="action-btn secondary" type="button" data-close-modal>Cancel</button>
                <button class="action-btn" type="button" id="submitDataExportBtn">Generate Export</button>
            </div>
        </div>
    `;
    document.body.appendChild(overlay);

    const close = () => overlay.remove();
    overlay.querySelector('.admin-modal-close')?.addEventListener('click', close);
    overlay.querySelector('[data-close-modal]')?.addEventListener('click', close);
    overlay.addEventListener('click', (event) => {
        if (event.target === overlay) close();
    });

    const applyFieldPreset = (mode) => {
        const checkboxes = Array.from(overlay.querySelectorAll('input[name="selected_fields"]'));
        if (!checkboxes.length) return;
        const datasetFields = Object.keys(dataset.columns || {});
        const fallbackCore = datasetFields.slice(0, Math.min(8, datasetFields.length));
        const specificCore = {
            file_registry: ['file_number', 'computer_number', 'supplier_number', 'title', 'surname', 'first_name', 'full_name', 'gender', 'living_status', 'pay_type', 'payroll_status', 'box_number', 'phone_number', 'retirement_date'],
            staff_due: ['file_number', 'title', 'surname', 'first_name', 'unit_name', 'gender', 'phone_number', 'retirement_date', 'financial_year', 'submission_status', 'application_status'],
            users: ['user_title', 'user_name', 'email', 'phone_number', 'role_key', 'account_status'],
            claims_ledger: ['file_number', 'claim_type', 'financial_year_label', 'quarter_label', 'expected_amount', 'paid_amount', 'balance_amount', 'status'],
            tasks: ['task_id', 'file_number', 'task_title', 'task_type', 'assigned_to_name', 'assigned_role', 'priority', 'status', 'due_at'],
            feedback_submissions: ['reference_no', 'feedback_type', 'audience', 'full_name', 'email_address', 'phone_number', 'subject', 'status', 'priority', 'assigned_to_name', 'submitted_at'],
            file_movements: ['file_number', 'registry_file_number', 'movement_type', 'from_office', 'to_office', 'delivered_by', 'movement_date', 'returned_at'],
            payroll_cycles: ['cycle_id', 'financial_year_label', 'quarter_label', 'payroll_month', 'payroll_year', 'cycle_status', 'matched_count', 'unmatched_count', 'total_amount'],
            user_logs: ['user_name', 'user_role', 'activity_type', 'details', 'location', 'created_at'],
            audit_logs: ['actor_name', 'actor_role', 'action', 'entity_type', 'entity_id', 'created_at']
        };
        const coreFields = new Set(specificCore[datasetKey] || fallbackCore);
        checkboxes.forEach((checkbox) => {
            if (mode === 'all') checkbox.checked = true;
            else if (mode === 'clear') checkbox.checked = false;
            else checkbox.checked = coreFields.has(checkbox.value);
        });
    };

    overlay.querySelectorAll('[data-export-select]').forEach((button) => {
        button.addEventListener('click', () => applyFieldPreset(button.getAttribute('data-export-select') || 'all'));
    });

    overlay.querySelector('#submitDataExportBtn')?.addEventListener('click', async () => {
        const form = overlay.querySelector('#dataExportForm');
        const button = overlay.querySelector('#submitDataExportBtn');
        button.disabled = true;
        button.textContent = 'Generating...';
        try {
            const requestPayload = { dataset_key: datasetKey, format: form.format.value };
            const selectedFields = Array.from(form.querySelectorAll('input[name="selected_fields"]:checked')).map((input) => input.value);
            if (selectedFields.length === 0) {
                throw new Error('Select at least one field to include in the export.');
            }
            requestPayload.selected_fields = selectedFields;
            requestPayload.filters = { search: form.search?.value?.trim() || '' };
            Object.keys(datasetFilters).forEach((filterKey) => {
                requestPayload.filters[filterKey] = form[filterKey]?.value || '';
            });
            const response = await fetch('../backend/api/run_data_export.php', {
                method: 'POST',
                credentials: 'include',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(requestPayload)
            });
            const responseText = await response.text();
            let data = { success: false, message: 'Unable to parse the export response.' };
            try {
                data = JSON.parse(responseText);
            } catch (_error) {
                data = {
                    success: false,
                    message: responseText && responseText.trim()
                        ? responseText.replace(/<[^>]+>/g, ' ').replace(/\s+/g, ' ').trim()
                        : 'Unable to parse the export response.'
                };
            }
            if (!response.ok || !data.success) {
                throw new Error(data.message || 'Unable to generate export.');
            }
            this.showNotification(data.message || 'Export generated successfully.', 'success');
            close();
            if (data.export?.download_url) {
                window.location.href = data.export.download_url;
            }
            await this.refreshCurrentSection();
        } catch (error) {
            button.disabled = false;
            button.textContent = 'Generate Export';
            this.showNotification(error.message || 'Unable to generate export.', 'error');
        }
    });
};

AdminDashboard.prototype.openCleanupActionModal = function (actionKey) {
    const actionMap = {
        purge_inactive_sessions: { title: 'Inactive Sessions', description: 'Remove inactive or stale session rows that no longer represent live users.' },
        purge_notification_queue: { title: 'Notification Queue', description: 'Remove sent or failed notification queue rows older than the operational retention window.' },
        purge_import_history: { title: 'Import History', description: 'Purge old import run records that have passed the retention window.' },
        purge_export_history: { title: 'Export History', description: 'Purge old export history records and remove their stored artifacts.' },
        purge_backup_history: { title: 'Backup History', description: 'Purge old backup history records and remove their stored backup archives.' }
    };
    const action = actionMap[actionKey];
    if (!action) {
        this.showNotification('Unknown cleanup action.', 'error');
        return;
    }

    const overlay = document.createElement('div');
    overlay.className = 'admin-modal-overlay';
    overlay.innerHTML = `
        <div class="admin-modal cleanup-modal">
            <div class="admin-modal-header">
                <div>
                    <h3>${this.escapeHtml(action.title)} Cleanup</h3>
                    <p class="import-modal-subtitle">${this.escapeHtml(action.description)}</p>
                </div>
                <button class="admin-modal-close" type="button" aria-label="Close">&times;</button>
            </div>
            <div class="admin-modal-body cleanup-modal-body">
                <div class="cleanup-warning-panel">
                    <div class="cleanup-warning-title">Two-step execution</div>
                    <p>Run a dry check first to see how many records will be affected. Only then proceed with the actual cleanup.</p>
                </div>
            </div>
            <div class="admin-modal-footer">
                <button class="action-btn secondary" type="button" data-close-modal>Close</button>
                <button class="action-btn secondary" type="button" data-cleanup-dry-run>Dry Check</button>
                <button class="action-btn" type="button" data-cleanup-run>Run Cleanup</button>
            </div>
        </div>
    `;
    document.body.appendChild(overlay);

    const close = () => overlay.remove();
    overlay.querySelector('.admin-modal-close')?.addEventListener('click', close);
    overlay.querySelector('[data-close-modal]')?.addEventListener('click', close);
    overlay.addEventListener('click', (event) => {
        if (event.target === overlay) close();
    });

    const run = async (dryRun) => {
        try {
            const response = await this.performSensitiveAdminRequest('../backend/api/run_data_cleanup.php', {
                method: 'POST',
                credentials: 'include',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ action: actionKey, dry_run: dryRun })
            }, dryRun ? 'run the cleanup dry check' : 'run the cleanup action');
            const data = response.__adminPayloadAttached || await response.json();
            if (!response.ok || !data.success) {
                throw new Error(data.message || 'Unable to run cleanup.');
            }
            this.showNotification(data.message || 'Cleanup completed.', 'success');
            if (!dryRun) {
                close();
                await this.refreshCurrentSection();
            }
        } catch (error) {
            this.showNotification(error.message || 'Unable to run cleanup.', 'error');
        }
    };

    overlay.querySelector('[data-cleanup-dry-run]')?.addEventListener('click', () => run(true));
    overlay.querySelector('[data-cleanup-run]')?.addEventListener('click', () => run(false));
};

AdminDashboard.prototype.loadDataCleanupContent = async function () {
    const summary = await this.fetchRegistryBoxAllocationSummary();
    const overview = await this.fetchDataManagementOverview();
    const issueItems = Array.isArray(summary.issues) ? summary.issues : [];
    const boxes = Array.isArray(summary.boxes) ? summary.boxes : [];
    const cleanupStats = overview.cleanup_stats || {};

    return `
        <div class="settings-content data-cleanup-content">
            <div class="settings-header">
                <div>
                    <h2 class="section-title">Data Cleanup</h2>
                    <p class="section-subtitle">Run controlled maintenance routines that keep registry structure, workflow data, and reporting dependable.</p>
                </div>
                <div class="settings-actions">
                    <button class="action-btn secondary" id="refreshDataCleanupBtn" type="button">Refresh</button>
                </div>
            </div>

            <div class="cleanup-summary-grid">
                <div class="user-summary-card cleanup-summary-card">
                    <div class="user-summary-icon">&#128452;</div>
                    <div>
                        <div class="user-summary-value">${Number(summary.total_records || 0)}</div>
                        <div class="user-summary-label">Registry Records</div>
                    </div>
                </div>
                <div class="user-summary-card cleanup-summary-card">
                    <div class="user-summary-icon">&#128230;</div>
                    <div>
                        <div class="user-summary-value">${Number(summary.total_boxes || 0)}</div>
                        <div class="user-summary-label">Allocated Boxes</div>
                    </div>
                </div>
                <div class="user-summary-card cleanup-summary-card">
                    <div class="user-summary-icon">&#9888;</div>
                    <div>
                        <div class="user-summary-value">${issueItems.length}</div>
                        <div class="user-summary-label">Allocation Issues</div>
                    </div>
                </div>
                <div class="user-summary-card cleanup-summary-card">
                    <div class="user-summary-icon">&#128257;</div>
                    <div>
                        <div class="user-summary-value">${Number(summary.unboxed_records || 0)}</div>
                        <div class="user-summary-label">Unboxed Records</div>
                    </div>
                </div>
            </div>

            <div class="cleanup-card-grid">
                <section class="settings-card cleanup-tool-card">
                    <div class="settings-card-header">
                        <div>
                            <h3>Registry Box Allocation</h3>
                            <p>Rebuild file box numbers so each box respects the current policy: max 70 files, grouped by living status and pay type.</p>
                        </div>
                    </div>
                    <div class="cleanup-policy-grid">
                        <div class="import-policy-pill"><strong>Capacity:</strong> 70 files per box</div>
                        <div class="import-policy-pill"><strong>Grouping:</strong> Alive vs Deceased</div>
                        <div class="import-policy-pill"><strong>Pay Type:</strong> Pensioner vs One-off</div>
                        <div class="import-policy-pill"><strong>Scope:</strong> Entire registry</div>
                    </div>
                    <div class="cleanup-warning-panel">
                        <div class="cleanup-warning-title">Controlled action</div>
                        <p>The rebuild updates every existing <code>boxNo</code> value in the registry. Use it after legacy imports, manual corrections, or whenever box allocation becomes inconsistent.</p>
                    </div>
                    <div class="cleanup-card-actions">
                        <button class="action-btn secondary" type="button" id="previewRegistryBoxRebuildBtn">Review Current Allocation</button>
                        <button class="action-btn" type="button" id="openRegistryBoxRebuildBtn">Rebuild Registry Box Allocation</button>
                    </div>
                </section>

                <section class="settings-card cleanup-tool-card">
                    <div class="settings-card-header">
                        <div>
                            <h3>Operational Housekeeping</h3>
                            <p>Clean stale technical data that would otherwise inflate counts, slow reports, or clutter the admin console.</p>
                        </div>
                    </div>
                    <div class="cleanup-metric-list">
                        <div class="cleanup-metric-row"><span>Inactive Sessions</span><strong>${Number(cleanupStats.inactive_sessions || 0)}</strong></div>
                        <div class="cleanup-metric-row"><span>Old Notification Queue</span><strong>${Number(cleanupStats.notification_queue_purge || 0)}</strong></div>
                        <div class="cleanup-metric-row"><span>Old Import History</span><strong>${Number(cleanupStats.import_history_purge || 0)}</strong></div>
                        <div class="cleanup-metric-row"><span>Old Export History</span><strong>${Number(cleanupStats.export_history_purge || 0)}</strong></div>
                        <div class="cleanup-metric-row"><span>Old Backup History</span><strong>${Number(cleanupStats.backup_history_purge || 0)}</strong></div>
                    </div>
                    <div class="cleanup-card-actions">
                        <button class="action-btn secondary" type="button" data-open-cleanup-action="purge_inactive_sessions">Inactive Sessions</button>
                        <button class="action-btn secondary" type="button" data-open-cleanup-action="purge_notification_queue">Notification Queue</button>
                        <button class="action-btn secondary" type="button" data-open-cleanup-action="purge_import_history">Import History</button>
                        <button class="action-btn secondary" type="button" data-open-cleanup-action="purge_export_history">Export History</button>
                        <button class="action-btn secondary" type="button" data-open-cleanup-action="purge_backup_history">Backup History</button>
                    </div>
                </section>

                <section class="settings-card cleanup-issues-card">
                    <div class="settings-card-header">
                        <div>
                            <h3>Current Allocation Signals</h3>
                            <p>These checks show whether a rebuild is necessary before you change live registry box numbers.</p>
                        </div>
                    </div>
                    <div class="cleanup-metric-list">
                        <div class="cleanup-metric-row"><span>Mixed Classification Boxes</span><strong>${Number(summary.mixed_classification_boxes || 0)}</strong></div>
                        <div class="cleanup-metric-row"><span>Unboxed Records</span><strong>${Number(summary.unboxed_records || 0)}</strong></div>
                        <div class="cleanup-metric-row"><span>Total Boxes</span><strong>${Number(summary.total_boxes || 0)}</strong></div>
                        <div class="cleanup-metric-row"><span>Full Boxes</span><strong>${Number(summary.full_boxes || 0)}</strong></div>
                    </div>
                    <div class="cleanup-issue-list">
                        ${issueItems.length ? issueItems.slice(0, 6).map((issue) => `<div class="cleanup-issue-item">${this.escapeHtml(issue)}</div>`).join('') : '<div class="app-state-message app-state-neutral">No current allocation conflicts detected.</div>'}
                    </div>
                </section>
            </div>

            <section class="settings-card cleanup-box-table-card">
                <div class="settings-card-header">
                    <div>
                        <h3>Current Box Layout</h3>
                        <p>Review the current distribution before running the rebuild.</p>
                    </div>
                </div>
                <div class="settings-table-container">
                    <table class="settings-table cleanup-box-table">
                        <thead>
                            <tr>
                                <th>Box</th>
                                <th>Total</th>
                                <th>Death</th>
                                <th>Pensioner</th>
                                <th>One-off</th>
                                <th>Flags</th>
                            </tr>
                        </thead>
                        <tbody>
                            ${boxes.length ? boxes.map((box) => `
                                <tr>
                                    <td>${this.escapeHtml(box.box_no || '')}</td>
                                    <td>${Number(box.total_count || 0)}</td>
                                    <td>${Number(box.death_count || 0)}</td>
                                    <td>${Number(box.pensioner_count || 0)}</td>
                                    <td>${Number(box.oneoff_count || 0)}</td>
                                    <td>
                                        <div class="cleanup-flag-group">
                                            ${box.mixed_classification ? '<span class="import-status-pill invalid">Mixed Class</span>' : `<span class="import-status-pill success">${this.escapeHtml(box.allocation_class || 'Classified')}</span>`}
                                            ${box.is_full ? '<span class="import-status-pill partial">Full</span>' : '<span class="import-status-pill success">Available</span>'}
                                        </div>
                                    </td>
                                </tr>
                            `).join('') : '<tr><td colspan="7"><div class="table-loading">No boxed registry records found.</div></td></tr>'}
                        </tbody>
                    </table>
                </div>
            </section>
        </div>
    `;
};

AdminDashboard.prototype.initializeDataCleanup = function () {
    document.getElementById('refreshDataCleanupBtn')?.addEventListener('click', async () => {
        await this.refreshCurrentSection();
    });

    document.getElementById('previewRegistryBoxRebuildBtn')?.addEventListener('click', async () => {
        await this.openRegistryBoxRebuildModal(false);
    });

    document.getElementById('openRegistryBoxRebuildBtn')?.addEventListener('click', async () => {
        await this.openRegistryBoxRebuildModal(true);
    });

    document.querySelectorAll('[data-open-cleanup-action]').forEach((button) => {
        button.addEventListener('click', () => {
            const actionKey = button.getAttribute('data-open-cleanup-action');
            if (actionKey) {
                this.openCleanupActionModal(actionKey);
            }
        });
    });
};

AdminDashboard.prototype.openRegistryBoxRebuildModal = async function (allowExecute = false) {
    let summary;
    try {
        summary = await this.fetchRegistryBoxAllocationSummary();
    } catch (error) {
        this.showNotification(error.message || 'Unable to load registry box allocation summary.', 'error');
        return;
    }

    const issueItems = Array.isArray(summary.issues) ? summary.issues : [];
    const overlay = document.createElement('div');
    overlay.className = 'admin-modal-overlay';
    overlay.id = 'registryBoxRebuildOverlay';
    overlay.innerHTML = `
        <div class="admin-modal cleanup-modal">
            <div class="admin-modal-header">
                <div>
                    <h3>Registry Box Allocation Review</h3>
                    <p class="import-modal-subtitle">Inspect the current box allocation before deciding whether to rebuild it.</p>
                </div>
                <button class="admin-modal-close" type="button" aria-label="Close">&times;</button>
            </div>
            <div class="admin-modal-body cleanup-modal-body">
                <div class="cleanup-summary-grid compact">
                    <div class="cleanup-mini-card"><span>Total Records</span><strong>${Number(summary.total_records || 0)}</strong></div>
                    <div class="cleanup-mini-card"><span>Allocated Boxes</span><strong>${Number(summary.total_boxes || 0)}</strong></div>
                    <div class="cleanup-mini-card"><span>Allocation Issues</span><strong>${issueItems.length}</strong></div>
                    <div class="cleanup-mini-card"><span>Unboxed Records</span><strong>${Number(summary.unboxed_records || 0)}</strong></div>
                </div>
                <div class="cleanup-warning-panel">
                    <div class="cleanup-warning-title">What the rebuild will do</div>
                    <p>The rebuild clears existing <code>boxNo</code> assignments and reassigns them sequentially using the current boxing policy. Registry records remain intact; only their box numbers are recalculated.</p>
                </div>
                <div class="cleanup-issue-list detailed">
                    ${issueItems.length ? issueItems.map((issue) => `<div class="cleanup-issue-item">${this.escapeHtml(issue)}</div>`).join('') : '<div class="app-state-message app-state-neutral">No allocation conflicts are currently detected.</div>'}
                </div>
                ${allowExecute ? `
                    <label class="confirm-checkbox cleanup-confirm-check">
                        <input type="checkbox" id="confirmRegistryBoxRebuild">
                        <span>I understand that this action will replace all existing registry box allocations with a rebuilt sequence.</span>
                    </label>
                ` : ''}
            </div>
            <div class="admin-modal-footer">
                <button class="action-btn secondary" type="button" data-close-cleanup-modal>Close</button>
                ${allowExecute ? '<button class="action-btn" type="button" id="confirmRegistryBoxRebuildBtn" disabled>Run Rebuild</button>' : ''}
            </div>
        </div>
    `;
    document.body.appendChild(overlay);

    const close = () => overlay.remove();
    overlay.querySelector('.admin-modal-close')?.addEventListener('click', close);
    overlay.querySelector('[data-close-cleanup-modal]')?.addEventListener('click', close);
    overlay.addEventListener('click', (event) => {
        if (event.target === overlay) close();
    });

    if (!allowExecute) {
        return;
    }

    const confirmCheckbox = overlay.querySelector('#confirmRegistryBoxRebuild');
    const confirmBtn = overlay.querySelector('#confirmRegistryBoxRebuildBtn');

    confirmCheckbox?.addEventListener('change', () => {
        if (confirmBtn) {
            confirmBtn.disabled = !confirmCheckbox.checked;
        }
    });

    confirmBtn?.addEventListener('click', async () => {
        confirmBtn.disabled = true;
        confirmBtn.textContent = 'Rebuilding...';
        try {
            const response = await this.performSensitiveAdminRequest('../backend/api/rebuild_registry_box_allocation.php', {
                method: 'POST',
                credentials: 'include',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ confirm: true })
            }, 'rebuild registry box allocation');
            const data = response.__adminPayloadAttached || await response.json();
            if (!response.ok || !data.success) {
                throw new Error(data.message || 'Unable to rebuild registry box allocation.');
            }

            this.showNotification(data.message || 'Registry box allocation rebuilt successfully.', 'success');
            close();
            await this.refreshCurrentSection();
        } catch (error) {
            if (confirmBtn) {
                confirmBtn.disabled = false;
                confirmBtn.textContent = 'Run Rebuild';
            }
            this.showNotification(error.message || 'Unable to rebuild registry box allocation.', 'error');
        }
    });
};

AdminDashboard.prototype.promptForAdminReauth = function (actionLabel = 'continue', options = {}) {
    const forcePrompt = Boolean(options?.force);
    if (!forcePrompt && this.hasFreshAdminStepUp && this.hasFreshAdminStepUp()) {
        return Promise.resolve(true);
    }
    return new Promise((resolve) => {
        document.querySelector('#adminStepUpOverlay')?.remove();

        const overlay = document.createElement('div');
        overlay.className = 'admin-modal-overlay';
        overlay.id = 'adminStepUpOverlay';
        overlay.innerHTML = `
            <div class="admin-modal cleanup-modal">
                <div class="admin-modal-header">
                    <div>
                        <h3>Admin Verification Required</h3>
                        <p class="import-modal-subtitle">Re-enter your admin password to ${this.escapeHtml(actionLabel)}.</p>
                    </div>
                    <button class="admin-modal-close" type="button" aria-label="Close">&times;</button>
                </div>
                <div class="admin-modal-body cleanup-modal-body">
                    <label class="settings-field">
                        <span>Admin Password</span>
                        <input type="password" id="adminStepUpPassword" autocomplete="current-password" placeholder="Enter your admin password">
                    </label>
                    <div class="app-state-message app-state-error hidden" id="adminStepUpError"></div>
                </div>
                <div class="admin-modal-footer">
                    <button class="action-btn secondary" type="button" data-step-up-cancel>Cancel</button>
                    <button class="action-btn" type="button" data-step-up-confirm>Verify</button>
                </div>
            </div>
        `;
        document.body.appendChild(overlay);

        const passwordInput = overlay.querySelector('#adminStepUpPassword');
        const errorBox = overlay.querySelector('#adminStepUpError');
        const confirmBtn = overlay.querySelector('[data-step-up-confirm]');

        const close = (approved = false) => {
            overlay.remove();
            resolve(approved);
        };

        const showError = (message) => {
            if (!errorBox) return;
            errorBox.textContent = message;
            errorBox.classList.remove('hidden');
        };

        overlay.querySelector('.admin-modal-close')?.addEventListener('click', () => close(false));
        overlay.querySelector('[data-step-up-cancel]')?.addEventListener('click', () => close(false));
        overlay.addEventListener('click', (event) => {
            if (event.target === overlay) close(false);
        });

        let submitting = false;
        const submit = async () => {
            if (submitting) return;
            const password = passwordInput?.value?.trim() || '';
            if (!password) {
                showError('Enter your admin password.');
                return;
            }

            submitting = true;
            if (confirmBtn) {
                confirmBtn.disabled = true;
                confirmBtn.textContent = 'Verifying...';
            }

            const controller = new AbortController();
            const timeoutId = window.setTimeout(() => controller.abort(), 9000);
            try {
                const response = await fetch('../backend/api/verify_admin_password.php', {
                    method: 'POST',
                    credentials: 'include',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ password }),
                    signal: controller.signal
                });
                const data = await this.safeJson(response, { success: false, valid: false });
                if (!response.ok || !data.success || !data.valid) {
                    throw new Error(data.message || 'Password verification failed.');
                }
                if (this.rememberServerAdminReauth) {
                    this.rememberServerAdminReauth(data);
                }
                close(true);
            } catch (error) {
                showError(error.name === 'AbortError' ? 'Verification is taking too long. Please try again.' : (error.message || 'Password verification failed.'));
                if (confirmBtn) {
                    confirmBtn.disabled = false;
                    confirmBtn.textContent = 'Verify';
                }
                submitting = false;
            } finally {
                window.clearTimeout(timeoutId);
            }
        };

        confirmBtn?.addEventListener('click', submit);
        passwordInput?.addEventListener('keydown', (event) => {
            if (event.key === 'Enter') {
                event.preventDefault();
                submit();
            }
        });
        passwordInput?.focus();
    });
};

AdminDashboard.prototype.performSensitiveAdminRequest = async function (url, options = {}, actionLabel = 'continue') {
    const attempt = async () => {
        const response = await fetch(url, options);
        const contentType = (response.headers.get('content-type') || '').toLowerCase();
        const payload = contentType.includes('application/json') ? await this.safeJson(response, {}) : null;
        if (payload) {
            response.__adminPayloadAttached = payload;
        }
        return { response, payload };
    };

    let { response, payload } = await attempt();
    if (response.status === 428 || Boolean(payload?.requiresReauth)) {
        if (this.clearAdminStepUpCache) {
            this.clearAdminStepUpCache();
        }
        const verified = await this.promptForAdminReauth(actionLabel);
        if (!verified) {
            throw new Error('Admin verification was cancelled.');
        }
        ({ response, payload } = await attempt());
    }

    if (payload) {
        response.__adminPayloadAttached = payload;
    }
    return response;
};

// Initialize admin dashboard when DOM is loaded
document.addEventListener('DOMContentLoaded', () => {
    window.adminDashboard = new AdminDashboard();
});

// Export for module usage
if (typeof module !== 'undefined' && module.exports) {
    module.exports = AdminDashboard;
}

AdminDashboard.prototype.loadLiveChatSettingsContent = async function () {
    const toggle = (name, title, subtitle) => `
        <div class="settings-toggle">
            <div>
                <div class="toggle-title">${title}</div>
                <div class="toggle-subtitle">${subtitle}</div>
            </div>
            <label class="switch">
                <input type="checkbox" name="${name}">
                <span class="slider"></span>
            </label>
        </div>
    `;
    const numberField = (name, title, min, max, step, help) => `
        <label class="settings-field">
            <span>${title}</span>
            <input type="number" name="${name}" min="${min}" max="${max}" step="${step}">
            <small class="field-help">${help}</small>
        </label>
    `;

    return `
        <div class="settings-content live-chat-settings">
            <div class="settings-header">
                <div>
                    <h2 class="section-title">Live Chat Settings</h2>
                    <p class="section-subtitle">Control staff live chat availability, collaboration tools, real-time cadence, and admin oversight behavior.</p>
                </div>
                <div class="settings-actions">
                    <span class="settings-status" id="liveChatSettingsStatus">Ready</span>
                    <button class="action-btn secondary" id="resetLiveChatSettingsBtn" type="button">Reset Changes</button>
                    <button class="action-btn" id="saveLiveChatSettingsBtn" type="button">Save Settings</button>
                </div>
            </div>

            <form id="liveChatSettingsForm" class="settings-grid">
                <section class="settings-card">
                    <div class="settings-card-header">
                        <h3>Availability</h3>
                        <p>Turn core chat surfaces and conversation types on or off.</p>
                    </div>
                    <div class="settings-fields">
                        ${toggle('live_chat_enabled', 'Enable Live Chat', 'Allow authorized staff to open and use the live chat module.')}
                        ${toggle('live_chat_group_chats_enabled', 'Group Chats', 'Allow group chat creation, membership management, and group conversations.')}
                        ${toggle('live_chat_drafts_enabled', 'Persistent Drafts', 'Keep unsent text per conversation across page reloads and sign-ins on the same device.')}
                    </div>
                </section>

                <section class="settings-card">
                    <div class="settings-card-header">
                        <h3>Messaging Tools</h3>
                        <p>Control the message features available in the composer.</p>
                    </div>
                    <div class="settings-fields">
                        ${toggle('live_chat_attachments_enabled', 'Attachments', 'Allow staff to send documents, photos, videos, and other supported files.')}
                        ${toggle('live_chat_voice_notes_enabled', 'Voice Notes', 'Allow microphone recording and sending of voice notes.')}
                        ${toggle('live_chat_polls_enabled', 'Polls', 'Allow quick polls inside direct and group conversations.')}
                        ${numberField('live_chat_edit_window_minutes', 'Edit Window (minutes)', 1, 60, 1, 'How long after sending a message the sender can edit it.')}
                    </div>
                </section>

                <section class="settings-card">
                    <div class="settings-card-header">
                        <h3>Calls & Receipts</h3>
                        <p>Manage real-time call capabilities and feedback signals.</p>
                    </div>
                    <div class="settings-fields">
                        ${toggle('live_chat_audio_calls_enabled', 'Audio Calls', 'Allow staff-to-staff voice calls.')}
                        ${toggle('live_chat_video_calls_enabled', 'Video Calls', 'Allow staff video calls and audio-to-video upgrade requests.')}
                        ${toggle('live_chat_add_participants_enabled', 'Add Participants During Calls', 'Allow the call host to invite more staff into an active voice or video call.')}
                        ${toggle('live_chat_typing_presence_enabled', 'Typing Presence', 'Show typing indicators in the conversation and contact list.')}
                        ${toggle('live_chat_read_receipts_enabled', 'Read Receipts', 'Enable delivered/read receipt syncing and ticks.')}
                        ${numberField('live_chat_typing_idle_seconds', 'Typing Idle Timeout (seconds)', 2, 30, 1, 'Typing status stops after this many seconds without text changes.')}
                    </div>
                </section>

                <section class="settings-card">
                    <div class="settings-card-header">
                        <h3>Real-Time Cadence</h3>
                        <p>Tune polling intervals. Lower values feel faster but create more server traffic.</p>
                    </div>
                    <div class="settings-fields">
                        ${numberField('live_chat_message_poll_ms', 'Message Poll Interval (ms)', 150, 5000, 50, 'How often open conversations check for new messages.')}
                        ${numberField('live_chat_receipt_poll_ms', 'Receipt Poll Interval (ms)', 150, 5000, 50, 'How often sent messages reconcile delivered/read state.')}
                        ${numberField('live_chat_call_poll_ms', 'Call Poll Interval (ms)', 300, 10000, 100, 'How often the app checks for incoming or changed calls.')}
                        ${numberField('live_chat_signal_poll_ms', 'Call Signal Poll Interval (ms)', 150, 5000, 50, 'How often active calls exchange WebRTC signaling updates.')}
                    </div>
                </section>

                <section class="settings-card">
                    <div class="settings-card-header">
                        <h3>Admin Oversight</h3>
                        <p>Govern the Chat Oversight archive and administrator delete actions.</p>
                    </div>
                    <div class="settings-fields">
                        ${toggle('live_chat_admin_archive_enabled', 'Preserve Audit Archive', 'Archive message content before user or admin delete actions clear peer-visible content.')}
                        ${toggle('live_chat_admin_delete_enabled', 'Allow Oversight Delete', 'Allow administrators to remove messages from all peer chat views through Chat Oversight.')}
                        <div class="settings-note">
                            <strong>Deletion governance</strong>
                            <span>Admin deletes hide messages from users without leaving a peer-side deleted badge, while audit logging remains enabled through the system audit log.</span>
                        </div>
                    </div>
                </section>

                <section class="settings-card">
                    <div class="settings-card-header">
                        <h3>Public Live Support</h3>
                        <p>Control the separate public-facing correspondence widget without changing staff-to-staff live chat.</p>
                    </div>
                    <div class="settings-fields">
                        ${toggle('public_chat_enabled', 'Enable Public Live Support', 'Show and allow the public correspondence widget where public chat access is enabled.')}
                        ${toggle('public_chat_public_pages_enabled', 'Public Pages', 'Show the widget on Home, About, FAQs, Podcast, Feedback, and Terms.')}
                        ${toggle('public_chat_home_enabled', 'Home Page Widget', 'Allow the public chat launcher on the Home page.')}
                        ${toggle('public_chat_about_enabled', 'About Page Widget', 'Allow the public chat launcher on the About page.')}
                        ${toggle('public_chat_faq_enabled', 'FAQ Page Widget', 'Allow the public chat launcher on the FAQs page.')}
                        ${toggle('public_chat_podcast_enabled', 'Podcast Page Widget', 'Allow the public chat launcher on the Podcast page.')}
                        ${toggle('public_chat_feedback_page_enabled', 'Feedback Page Widget', 'Allow the public chat launcher on the Feedback page.')}
                        ${toggle('public_chat_terms_enabled', 'Terms Page Widget', 'Allow the public chat launcher on the Terms page.')}
                        ${toggle('public_chat_pensioner_portal_enabled', 'Pensioner Portal', 'Allow logged-in pensioners to open the same support widget with profile prefill.')}
                        ${toggle('public_chat_attachments_enabled', 'Public Attachments', 'Reserve attachment support for the public correspondence module.')}
                        ${toggle('public_chat_auto_assign_enabled', 'Auto Assignment', 'Allow the system to auto-route new public chats when enabled by operations.')}
                        ${toggle('public_chat_transcript_enabled', 'Chat Transcripts', 'Keep transcripts available for authorized officers and reporting.')}
                        ${toggle('public_chat_feedback_enabled', 'Feedback Rating', 'Ask visitors to rate support after a chat or offline request.')}
                        ${numberField('public_chat_max_active_chats_per_agent', 'Max Active Chats Per Agent', 1, 50, 1, 'Default maximum number of active public chats assigned to one agent.')}
                        ${numberField('public_chat_max_message_length', 'Max Message Length', 250, 5000, 50, 'Maximum characters allowed in each public chat message.')}
                        ${numberField('public_chat_poll_interval_ms', 'Public Poll Interval (ms)', 800, 15000, 100, 'How often visitor and agent public chat windows check for updates.')}
                        ${numberField('public_chat_max_attachment_size_mb', 'Max Attachment Size (MB)', 1, 25, 1, 'Maximum public chat attachment size.')}
                        ${numberField('public_chat_rate_limit_start_per_10min', 'Start Rate Limit / 10 min', 1, 120, 1, 'Maximum chat/offline starts per visitor window.')}
                        ${numberField('public_chat_rate_limit_messages_per_5min', 'Message Rate Limit / 5 min', 1, 120, 1, 'Maximum public messages per visitor window.')}
                        <label class="settings-field">
                            <span>Working Hours</span>
                            <input name="public_chat_working_hours" placeholder="08:00-17:00">
                            <small class="field-help">Displayed and available to routing logic as the public support hours.</small>
                        </label>
                        <label class="settings-field">
                            <span>Allowed Attachment Types</span>
                            <input name="public_chat_allowed_attachment_types" placeholder="pdf,jpg,jpeg,png,doc,docx">
                            <small class="field-help">Comma-separated extensions allowed for public uploads.</small>
                        </label>
                        <label class="settings-field">
                            <span>Welcome Text</span>
                            <textarea name="public_chat_welcome_text" rows="3"></textarea>
                        </label>
                        <label class="settings-field">
                            <span>Consent Text</span>
                            <textarea name="public_chat_consent_text" rows="3"></textarea>
                        </label>
                        <label class="settings-field">
                            <span>Offline Message</span>
                            <textarea name="public_chat_offline_message" rows="3"></textarea>
                            <small class="field-help">Shown when public live support is unavailable.</small>
                        </label>
                    </div>
                </section>
            </form>
        </div>
    `;
};

AdminDashboard.prototype.loadPublicChatSupportContent = async function () {
    const section = String(this.currentSection || 'public-chat-support').toLowerCase();
    const titles = {
        'public-chat-support': ['Public Chat Support', 'Manage public live chat handlers, reports, audit trails, and support settings from one settings workspace.'],
        'public-chat-agents': ['Public Chat Agents', 'Appoint existing registered users as public chat handlers and control their correspondence rights.'],
        'public-chat-settings': ['Public Chat Settings', 'Configure public live support widget, access, working hours, consent, feedback, uploads, and limits.'],
        'public-chat-reports': ['Public Chat Reports', 'Filter, review, and export public live chat statistics and correspondence records.'],
        'public-chat-audit': ['Public Chat Audit Logs', 'Review sensitive public live chat actions, agent changes, and settings updates.']
    };
    const [title, subtitle] = titles[section] || titles['public-chat-support'];
    const statusActions = `
        <div class="settings-actions">
            <span class="settings-status" id="publicChatSupportStatus">Ready</span>
            <select id="publicChatAgentStatus" class="settings-select">
                <option value="online">Online</option>
                <option value="busy">Busy</option>
                <option value="away">Away</option>
                <option value="offline">Offline</option>
            </select>
            <button class="action-btn secondary" id="refreshPublicChatSupportBtn" type="button">Refresh</button>
        </div>
    `;
    const agentsPanel = `
        <section class="settings-card public-chat-admin-panel" id="publicChatAgentsPanel">
            <div class="settings-card-header">
                <h3>Agent Management</h3>
                <p>Enable eligible users for public correspondence and tune their chat handling permissions.</p>
            </div>
            <div class="settings-note">
                <strong>Role alignment</strong>
                <span>Super Admin, Admin, OC, and appointed users retain their existing app roles while public chat rights are controlled here.</span>
            </div>
            <div class="public-chat-admin-toolbar" data-public-chat-tools="agents">
                <input type="search" class="filter-input" data-public-chat-filter="agents" placeholder="Search agents by name, role, status, or right">
                <button type="button" class="action-btn secondary small" data-public-chat-export-table="agents">Export CSV</button>
                <button type="button" class="action-btn secondary small" data-public-chat-print-table="agents">Print</button>
            </div>
            <div id="publicChatAgentsList"></div>
        </section>
    `;
    const reportsPanel = `
        <section class="settings-card public-chat-admin-panel" id="publicChatReportsPanel">
            <div class="settings-card-header">
                <h3>Reports and Statistics</h3>
                <p>Filter by date range, district, category, status, agent, priority, pensioner/force number, or ticket status.</p>
            </div>
            <form id="publicChatReportFilters" class="settings-form compact-form">
                <div class="settings-split-grid">
                    <input type="date" name="date_from">
                    <input type="date" name="date_to">
                    <input name="district" placeholder="District">
                    <select name="inquiry_category" id="publicChatReportCategory"></select>
                    <select name="status"><option value="">Any status</option><option>waiting</option><option>active</option><option>assigned</option><option>escalated</option><option>closed</option></select>
                    <input name="agent" placeholder="Agent name">
                    <select name="priority"><option value="">Any priority</option><option>low</option><option>normal</option><option>high</option><option>urgent</option></select>
                    <input name="number" placeholder="Force/Pensioner number">
                    <select name="ticket_status"><option value="">Any ticket status</option><option>New</option><option>Assigned</option><option>In progress</option><option>Awaiting public user</option><option>Escalated</option><option>Resolved</option><option>Closed</option><option>Reopened</option></select>
                </div>
                <div class="settings-actions">
                    <button type="submit" class="action-btn secondary">Apply Filters</button>
                    <button type="button" class="action-btn secondary" id="publicChatExportBtn">Server CSV</button>
                    <button type="button" class="action-btn secondary" data-public-chat-export-table="reports">Export Visible</button>
                    <button type="button" class="action-btn secondary" data-public-chat-print-table="reports">Print</button>
                </div>
            </form>
            <div id="publicChatReportGroups"></div>
            <div id="publicChatReportRecords"></div>
        </section>
    `;
    const auditPanel = `
        <section class="settings-card public-chat-admin-panel" id="publicChatAuditPanel">
            <div class="settings-card-header">
                <h3>Audit Logs</h3>
                <p>Track chat creation, assignment, messages, notes, escalations, tickets, closures, feedback, agent status, and settings changes.</p>
            </div>
            <div class="public-chat-admin-toolbar" data-public-chat-tools="audit">
                <input type="search" class="filter-input" data-public-chat-filter="audit" placeholder="Search audit logs by action, actor, role, IP, date, or details">
                <button type="button" class="action-btn secondary small" data-public-chat-export-table="audit">Export CSV</button>
                <button type="button" class="action-btn secondary small" data-public-chat-print-table="audit">Print</button>
            </div>
            <div id="publicChatAuditList"></div>
        </section>
    `;
    const overviewPanel = `
        <div class="settings-grid public-chat-management-grid public-chat-support-overview">
            <section class="settings-card">
                <div class="settings-card-header"><h3>Public Chat Agents</h3><p>Manage permitted public chat handlers and availability controls.</p></div>
                <button type="button" class="action-btn secondary" data-public-chat-section="public-chat-agents">Open Agents</button>
            </section>
            <section class="settings-card">
                <div class="settings-card-header"><h3>Public Chat Settings</h3><p>Open the Live Chat Settings form directly for widget, working hours, consent, feedback, uploads, and limits.</p></div>
                <button type="button" class="action-btn secondary" data-public-chat-section="public-chat-settings">Open Settings</button>
            </section>
            <section class="settings-card">
                <div class="settings-card-header"><h3>Public Chat Reports</h3><p>Review public chat records and export filtered reports.</p></div>
                <button type="button" class="action-btn secondary" data-public-chat-section="public-chat-reports">Open Reports</button>
            </section>
            <section class="settings-card">
                <div class="settings-card-header"><h3>Public Chat Audit Logs</h3><p>Inspect sensitive public chat actions and configuration changes.</p></div>
                <button type="button" class="action-btn secondary" data-public-chat-section="public-chat-audit">Open Audit Logs</button>
            </section>
        </div>
    `;
    const panelBySection = {
        'public-chat-agents': agentsPanel,
        'public-chat-reports': reportsPanel,
        'public-chat-audit': auditPanel
    };

    return `
        <div class="settings-content public-chat-support-admin">
            <div class="settings-header">
                <div>
                    <h2 class="section-title">${this.escapeHtml(title)}</h2>
                    <p class="section-subtitle">${this.escapeHtml(subtitle)}</p>
                </div>
                ${statusActions}
            </div>
            ${panelBySection[section] || overviewPanel}
        </div>
    `;
};

AdminDashboard.prototype.initializePublicChatSupport = function () {
    this.publicChatState = { selectedId: null, selected: null, lastMessageId: 0, pollTimer: null, heartbeatTimer: null };
    document.getElementById('refreshPublicChatSupportBtn')?.addEventListener('click', () => this.refreshPublicChatModule());
    document.getElementById('publicChatAgentStatus')?.addEventListener('change', (event) => this.setPublicChatAgentStatus(event.target.value));
    document.getElementById('publicChatAcceptBtn')?.addEventListener('click', () => this.publicChatAction('accept'));
    document.getElementById('publicChatTransferBtn')?.addEventListener('click', async () => {
        const agent = await this.resolvePublicChatTransferAgent();
        if (agent?.userId) this.publicChatAction('transfer', { agent_user_id: agent.userId });
    });
    document.getElementById('publicChatEscalateBtn')?.addEventListener('click', async () => {
        const reason = await this.publicChatPrompt('Record the escalation reason.', '', {
            title: 'Escalate Public Chat',
            confirmText: 'Escalate'
        });
        if (reason) this.publicChatAction('escalate', { reason });
    });
    document.getElementById('publicChatTicketBtn')?.addEventListener('click', async () => {
        const subject = await this.publicChatPrompt('Enter the ticket subject.', this.publicChatState?.selected?.session?.subject || 'Public chat follow-up', {
            title: 'Create Public Chat Ticket',
            confirmText: 'Create Ticket'
        });
        if (subject) this.publicChatAction('ticket', { subject, description: subject });
    });
    document.getElementById('publicChatCloseBtn')?.addEventListener('click', async () => {
        const reason = await this.publicChatPrompt('Record the close reason.', 'Resolved', {
            title: 'Close Public Chat',
            confirmText: 'Close Chat'
        });
        if (reason !== null) this.publicChatAction('close', { reason: reason || 'Resolved' });
    });
    document.getElementById('publicChatAgentReplyForm')?.addEventListener('submit', (event) => this.sendPublicChatReply(event));
    document.getElementById('publicChatAgentNoteForm')?.addEventListener('submit', (event) => this.addPublicChatNote(event));
    document.getElementById('publicChatCannedForm')?.addEventListener('submit', (event) => this.savePublicChatCanned(event));
    document.getElementById('publicChatExportBtn')?.addEventListener('click', () => this.exportPublicChatReport());
    document.getElementById('publicChatReportFilters')?.addEventListener('submit', (event) => {
        event.preventDefault();
        this.loadPublicChatReports();
    });
    document.querySelectorAll('[data-public-chat-filter]').forEach((input) => {
        input.addEventListener('input', () => this.filterPublicChatTable(input.dataset.publicChatFilter || '', input.value));
    });
    document.querySelectorAll('[data-public-chat-export-table]').forEach((button) => {
        button.addEventListener('click', () => this.exportPublicChatTable(button.dataset.publicChatExportTable || ''));
    });
    document.querySelectorAll('[data-public-chat-print-table]').forEach((button) => {
        button.addEventListener('click', () => this.printPublicChatTable(button.dataset.publicChatPrintTable || ''));
    });
    document.querySelectorAll('[data-public-chat-section]').forEach((button) => {
        button.addEventListener('click', () => this.navigateToSection(button.dataset.publicChatSection || 'public-chat-support'));
    });
    document.querySelectorAll('[data-public-chat-panel]').forEach((button) => {
        button.addEventListener('click', () => {
            const panel = button.dataset.publicChatPanel;
            const target = document.getElementById(`publicChat${panel.charAt(0).toUpperCase()}${panel.slice(1)}Panel`);
            if (target) target.scrollIntoView({ behavior: 'smooth', block: 'start' });
        });
    });
    this.populatePublicChatCategorySelects();
    this.refreshPublicChatModule();
    const targetPanelMap = {
        'public-chat-agents': 'publicChatAgentsPanel',
        'public-chat-settings': 'publicChatSettingsPanel',
        'public-chat-reports': 'publicChatReportsPanel',
        'public-chat-audit': 'publicChatAuditPanel'
    };
    const targetPanel = document.getElementById(targetPanelMap[this.currentSection] || '');
    if (targetPanel) window.setTimeout(() => targetPanel.scrollIntoView({ behavior: 'smooth', block: 'start' }), 80);
    this.startPublicChatHeartbeat();
};

AdminDashboard.prototype.publicChatPrompt = async function (message, defaultValue = '', options = {}) {
    if (typeof window.appPrompt === 'function') {
        return window.appPrompt(message, defaultValue, options);
    }
    this.showNotification(message, options.type || 'info');
    return null;
};

AdminDashboard.prototype.publicChatConfirm = async function (message, options = {}) {
    if (typeof window.appConfirm === 'function') {
        return window.appConfirm(message, options);
    }
    this.showNotification(message, options.type || 'info');
    return false;
};

AdminDashboard.prototype.resolvePublicChatTransferAgent = async function () {
    let agents = [];
    try {
        const data = await this.publicChatFetch({ action: 'transfer_agents' }, 'GET');
        agents = Array.isArray(data.agents) ? data.agents : [];
    } catch (error) {
        this.showNotification(error.message || 'Unable to load public chat handlers.', 'error');
        return null;
    }
    if (!agents.length) {
        this.showNotification('No enabled public chat handlers are available for transfer.', 'info');
        return null;
    }
    if (agents.length === 1) {
        const confirmed = await this.publicChatConfirm(`Transfer this chat to ${agents[0].agentLabel || agents[0].userName || 'the available handler'}?`, {
            title: 'Transfer Public Chat',
            confirmText: 'Transfer',
            cancelText: 'Cancel'
        });
        return confirmed ? agents[0] : null;
    }
    const names = agents.map((agent) => agent.agentLabel || agent.userName || 'Unnamed handler').join(', ');
    const choice = await this.publicChatPrompt(`Type the handler name. Available handlers: ${names}`, '', {
        title: 'Transfer Public Chat',
        confirmText: 'Transfer'
    });
    const normalized = String(choice || '').trim().toLowerCase();
    if (!normalized) return null;
    const matches = agents.filter((agent) => String(agent.agentLabel || agent.userName || '').trim().toLowerCase().includes(normalized));
    if (matches.length === 1) return matches[0];
    this.showNotification(matches.length > 1 ? 'More than one handler matched that name. Type a fuller name.' : 'No handler matched that name.', 'error');
    return null;
};

AdminDashboard.prototype.refreshPublicChatModule = async function () {
    await Promise.allSettled([
        this.loadPublicChatStats(),
        this.loadPublicChatAgents(),
        this.loadPublicChatReports(),
        this.loadPublicChatAudit()
    ]);
};

AdminDashboard.prototype.startPublicChatHeartbeat = function () {
    if (this.publicChatState?.heartbeatTimer) clearInterval(this.publicChatState.heartbeatTimer);
    this.publicChatState.heartbeatTimer = setInterval(() => {
        const activeSection = String(this.currentSection || '').toLowerCase();
        const isPublicChatOpen = activeSection.includes('public-chat') || activeSection === 'public-live-chat';
        if (!isPublicChatOpen || document.hidden) return;
        this.publicChatFetch({ action: 'heartbeat' }).catch(() => {});
    }, 120000);
};

AdminDashboard.prototype.populatePublicChatCategorySelects = function () {
    const categories = [
        'Pension application status', 'Retirement benefits', 'Gratuity', 'Monthly pension', 'Arrears', 'Life certificate',
        'Date of birth correction', 'Payroll/payment issue', 'Document requirements', 'General inquiry', 'Complaint', 'Technical support'
    ];
    ['publicChatCannedCategory', 'publicChatReportCategory'].forEach((id) => {
        const select = document.getElementById(id);
        if (!select) return;
        select.innerHTML = `<option value="">${id.includes('Report') ? 'Any category' : 'General'}</option>` + categories.map((cat) => `<option value="${this.escapeHtml(cat)}">${this.escapeHtml(cat)}</option>`).join('');
    });
};

AdminDashboard.prototype.publicChatFetch = async function (payload, method = 'POST') {
    const options = method === 'GET'
        ? { credentials: 'include', cache: 'no-store' }
        : { method, credentials: 'include', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(payload) };
    const url = method === 'GET'
        ? `../backend/api/public_chat_agent.php?${new URLSearchParams(payload).toString()}`
        : '../backend/api/public_chat_agent.php';
    const response = await fetch(url, options);
    const data = await this.safeJson(response, { success: false });
    if (!data.success) throw new Error(data.message || 'Public chat request failed.');
    return data;
};

AdminDashboard.prototype.publicChatLabel = function (key) {
    return String(key || '')
        .replace(/[_-]+/g, ' ')
        .replace(/([a-z])([A-Z])/g, '$1 $2')
        .replace(/\s+/g, ' ')
        .trim()
        .replace(/\b\w/g, (char) => char.toUpperCase());
};

AdminDashboard.prototype.publicChatDisplayValue = function (value) {
    if (value === null || value === undefined || value === '') return 'N/A';
    if (typeof value === 'boolean') return value ? 'Yes' : 'No';
    if (Array.isArray(value)) return value.length ? value.map((item) => this.publicChatDisplayValue(item)).join(', ') : 'N/A';
    if (typeof value === 'object') {
        return Object.entries(value)
            .filter(([key]) => !this.publicChatIsTechnicalKey(key))
            .map(([key, item]) => `${this.publicChatLabel(key)}: ${this.publicChatDisplayValue(item)}`)
            .join('; ') || 'N/A';
    }
    const raw = String(value);
    if ((raw.startsWith('{') && raw.endsWith('}')) || (raw.startsWith('[') && raw.endsWith(']'))) {
        try {
            return this.publicChatDisplayValue(JSON.parse(raw));
        } catch (_) {}
    }
    return raw;
};

AdminDashboard.prototype.publicChatIsTechnicalKey = function (key) {
    return /(^id$|_id$|userid|user_id|session_id|message_id|attachment_id|actor_user_id|assigned_to|assigned_agent_id|response_id|ticket_id|typing_id)/i.test(String(key || ''));
};

AdminDashboard.prototype.publicChatFriendlyActor = function (row, nameKey, idKey, fallback = 'Unassigned') {
    return row?.[nameKey] || row?.name || row?.userName || fallback;
};

AdminDashboard.prototype.publicChatStatusBadge = function (value) {
    const label = this.publicChatDisplayValue(value);
    const key = label.toLowerCase().replace(/\s+/g, '-');
    return `<span class="public-chat-admin-badge status-${this.escapeHtml(key)}">${this.escapeHtml(label)}</span>`;
};

AdminDashboard.prototype.publicChatRenderTable = function (scope, rows, columns, options = {}) {
    const safeRows = Array.isArray(rows) ? rows : [];
    this.publicChatTables = this.publicChatTables || {};
    this.publicChatTables[scope] = {
        title: options.title || this.publicChatLabel(scope),
        rows: safeRows,
        columns
    };
    if (!safeRows.length) {
        return `<div class="settings-note">No records found.</div>`;
    }
    const caption = options.caption ? `<caption>${this.escapeHtml(options.caption)}</caption>` : '';
    return `
        <div class="settings-table-container public-chat-admin-table-wrap" data-public-chat-table-scope="${this.escapeHtml(scope)}">
            <table class="settings-table public-chat-admin-table">
                ${caption}
                <thead>
                    <tr>${columns.map((column) => `<th scope="col">${this.escapeHtml(column.label || this.publicChatLabel(column.key))}</th>`).join('')}</tr>
                </thead>
                <tbody>
                    ${safeRows.map((row) => `
                        <tr data-public-chat-row="${this.escapeHtml(scope)}" data-search="${this.escapeHtml(columns.map((column) => this.publicChatDisplayValue(column.render ? column.render(row, true) : row[column.key])).join(' ').toLowerCase())}" ${options.rowId ? `data-user-id="${this.escapeHtml(row[options.rowId] || '')}"` : ''}>
                            ${columns.map((column) => {
                                const rendered = column.render ? column.render(row, false) : this.escapeHtml(this.publicChatDisplayValue(row[column.key]));
                                return `<td>${rendered}</td>`;
                            }).join('')}
                        </tr>
                    `).join('')}
                </tbody>
            </table>
        </div>
    `;
};

AdminDashboard.prototype.filterPublicChatTable = function (scope, query) {
    const normalized = String(query || '').trim().toLowerCase();
    document.querySelectorAll(`[data-public-chat-row="${scope}"]`).forEach((row) => {
        row.hidden = normalized !== '' && !String(row.dataset.search || '').includes(normalized);
    });
};

AdminDashboard.prototype.publicChatVisibleRows = function (scope) {
    const table = this.publicChatTables?.[scope];
    if (!table) return [];
    const domRows = Array.from(document.querySelectorAll(`[data-public-chat-row="${scope}"]`));
    if (!domRows.length) return table.rows;
    const visibleIndexes = domRows
        .map((row, index) => row.hidden ? -1 : index)
        .filter((index) => index >= 0);
    return visibleIndexes.map((index) => table.rows[index]).filter(Boolean);
};

AdminDashboard.prototype.exportPublicChatTable = function (scope) {
    const table = this.publicChatTables?.[scope];
    if (!table) {
        this.showNotification('No public chat table is available to export.', 'info');
        return;
    }
    const rows = this.publicChatVisibleRows(scope);
    const escapeCsv = (value) => `"${String(value ?? '').replace(/"/g, '""')}"`;
    const lines = [
        table.columns.map((column) => escapeCsv(column.label || this.publicChatLabel(column.key))).join(','),
        ...rows.map((row) => table.columns.map((column) => {
            const value = column.render ? column.render(row, true) : row[column.key];
            return escapeCsv(this.publicChatDisplayValue(value));
        }).join(','))
    ];
    const blob = new Blob([lines.join('\r\n')], { type: 'text/csv;charset=utf-8' });
    const link = document.createElement('a');
    link.href = URL.createObjectURL(blob);
    link.download = `${String(table.title || scope).toLowerCase().replace(/[^a-z0-9]+/g, '-')}-${new Date().toISOString().slice(0, 10)}.csv`;
    document.body.appendChild(link);
    link.click();
    link.remove();
    URL.revokeObjectURL(link.href);
};

AdminDashboard.prototype.printPublicChatTable = function (scope) {
    const table = this.publicChatTables?.[scope];
    if (!table) {
        this.showNotification('No public chat table is available to print.', 'info');
        return;
    }
    const rows = this.publicChatVisibleRows(scope);
    const html = `
        <table>
            <thead>
                <tr>${table.columns.map((column) => `<th>${this.escapeHtml(column.label || this.publicChatLabel(column.key))}</th>`).join('')}</tr>
            </thead>
            <tbody>
                ${rows.map((row) => `
                    <tr>
                        ${table.columns.map((column) => {
                            const value = column.render ? column.render(row, true) : row[column.key];
                            return `<td>${this.escapeHtml(this.publicChatDisplayValue(value))}</td>`;
                        }).join('')}
                    </tr>
                `).join('') || `<tr><td colspan="${table.columns.length}">No records found.</td></tr>`}
            </tbody>
        </table>
    `;
    const printWindow = window.open('', '_blank', 'width=1100,height=760');
    if (!printWindow) {
        this.showNotification('Allow popups to print public chat records.', 'error');
        return;
    }
    printWindow.document.open();
    printWindow.document.write(`
        <!doctype html>
        <html>
            <head>
                <title>${this.escapeHtml(table.title)}</title>
                <style>
                    body { font-family: Arial, sans-serif; color: #222; padding: 24px; }
                    h1 { font-size: 20px; margin: 0 0 6px; }
                    p { color: #555; margin: 0 0 18px; }
                    table { width: 100%; border-collapse: collapse; font-size: 12px; }
                    th, td { border: 1px solid #ddd; padding: 8px; text-align: left; vertical-align: top; }
                    th { background: #f2f2f2; }
                    .public-chat-admin-badge { border: 1px solid #bbb; border-radius: 999px; padding: 2px 7px; display: inline-block; }
                </style>
            </head>
            <body>
                <h1>${this.escapeHtml(table.title)}</h1>
                <p>Generated ${this.escapeHtml(new Date().toLocaleString())}</p>
                ${html}
            </body>
        </html>
    `);
    printWindow.document.close();
    printWindow.focus();
    setTimeout(() => printWindow.print(), 250);
};

AdminDashboard.prototype.loadPublicChatQueue = async function () {
    try {
        this.updateSettingsStatus('publicChatSupport', 'Loading...', 'info');
        const data = await this.publicChatFetch({ action: 'list' }, 'GET');
        const list = document.getElementById('publicChatQueueList');
        if (list) {
            list.innerHTML = (data.sessions || []).map((chat) => `
                <button type="button" class="settings-list-row public-chat-row" data-session-id="${chat.session_id}">
                    <strong>${this.escapeHtml(chat.chat_reference || '')}</strong>
                    <span>${this.escapeHtml(chat.visitor_name || '')} - ${this.escapeHtml(chat.inquiry_category || '')}</span>
                    <small>${this.escapeHtml(chat.status || '')} - ${this.escapeHtml(chat.district || '')}</small>
                </button>
            `).join('') || '<div class="settings-note">No active public chats.</div>';
            list.querySelectorAll('[data-session-id]').forEach((btn) => {
                btn.addEventListener('click', () => this.loadPublicChatDetail(Number(btn.dataset.sessionId || 0)));
            });
        }
        this.updateSettingsStatus('publicChatSupport', 'Up to date', 'success');
    } catch (error) {
        this.updateSettingsStatus('publicChatSupport', 'Load failed', 'error');
        this.showNotification(error.message || 'Unable to load public chats.', 'error');
    }
};

AdminDashboard.prototype.loadPublicChatStats = async function () {
    try {
        const data = await this.publicChatFetch({ action: 'stats' }, 'GET');
        const stats = data.stats || {};
        const grid = document.getElementById('publicChatStatsGrid');
        if (grid) {
            const cards = [
                ['Today', stats.totalToday], ['This Week', stats.totalWeek], ['This Month', stats.totalMonth],
                ['Waiting', stats.waiting], ['Active', stats.active], ['Closed', stats.closed],
                ['Unresolved', stats.unresolved], ['Escalated', stats.escalated], ['Offline', stats.offlineMessages],
                ['Tickets', stats.ticketsCreated], ['Complaints', stats.complaints], ['Avg Rating', stats.feedbackAverageRating || 0]
            ];
            grid.innerHTML = cards.map(([label, value]) => `<article class="analytics-stat-card"><span>${this.escapeHtml(label)}</span><strong>${this.escapeHtml(String(value ?? 0))}</strong></article>`).join('');
        }
        const groups = document.getElementById('publicChatReportGroups');
        if (groups) {
            groups.innerHTML = `
                <div class="public-chat-report-summary-grid">
                    ${Object.entries(stats.groups || {}).map(([key, rows]) => `
                        <section class="public-chat-report-summary">
                            <h4>${this.escapeHtml(this.publicChatLabel(key))}</h4>
                            ${this.publicChatRenderTable(`reports-${key}`, rows || [], [
                                { key: 'label', label: 'Group' },
                                { key: 'total', label: 'Total' }
                            ], { title: `Public Chat ${this.publicChatLabel(key)}` })}
                        </section>
                    `).join('')}
                </div>
            `;
        }
    } catch (_) {}
};

AdminDashboard.prototype.loadPublicChatReports = async function () {
    const wrap = document.getElementById('publicChatReportRecords');
    if (!wrap) return;
    try {
        wrap.innerHTML = '<div class="settings-note">Loading public chat report records...</div>';
        const form = document.getElementById('publicChatReportFilters');
        const filters = form ? Object.fromEntries(new FormData(form).entries()) : {};
        const statuses = filters.status ? [filters.status] : ['waiting', 'active', 'assigned', 'escalated', 'closed'];
        const responses = await Promise.all(statuses.map((status) => this.publicChatFetch({ action: 'list', status }, 'GET').catch(() => ({ sessions: [] }))));
        const seen = new Set();
        let sessions = responses.flatMap((data) => data.sessions || []).filter((row) => {
            const id = String(row.session_id || '');
            if (!id || seen.has(id)) return false;
            seen.add(id);
            return true;
        });
        const from = filters.date_from ? new Date(`${filters.date_from}T00:00:00`) : null;
        const to = filters.date_to ? new Date(`${filters.date_to}T23:59:59`) : null;
        sessions = sessions.filter((row) => {
            const created = new Date(String(row.created_at || '').replace(' ', 'T'));
            const matchesDate = (!from || created >= from) && (!to || created <= to);
            const matchesDistrict = !filters.district || String(row.district || '').toLowerCase().includes(String(filters.district).toLowerCase());
            const matchesCategory = !filters.inquiry_category || row.inquiry_category === filters.inquiry_category;
            const matchesAgent = !filters.agent || String(row.assigned_agent_name || '').toLowerCase().includes(String(filters.agent).toLowerCase());
            const matchesPriority = !filters.priority || row.priority === filters.priority;
            const numberText = `${row.force_number || ''} ${row.pensioner_number || ''} ${row.phone_number || ''}`.toLowerCase();
            const matchesNumber = !filters.number || numberText.includes(String(filters.number).toLowerCase());
            return matchesDate && matchesDistrict && matchesCategory && matchesAgent && matchesPriority && matchesNumber;
        });
        const ticketData = await this.publicChatFetch({ action: 'tickets' }, 'GET').catch(() => ({ tickets: [] }));
        let tickets = ticketData.tickets || [];
        if (filters.ticket_status) {
            tickets = tickets.filter((ticket) => ticket.status === filters.ticket_status);
        }
        wrap.innerHTML = `
            <section class="public-chat-report-section">
                <h4>Correspondence Records</h4>
                ${this.publicChatRenderTable('reports', sessions, [
                    { key: 'chat_reference', label: 'Chat Reference' },
                    { key: 'visitor_name', label: 'Visitor' },
                    { key: 'phone_number', label: 'Phone' },
                    { key: 'force_number', label: 'Force No.' },
                    { key: 'pensioner_number', label: 'Pensioner No.' },
                    { key: 'district', label: 'District' },
                    { key: 'inquiry_category', label: 'Category' },
                    { key: 'status', label: 'Status', render: (row, plain) => plain ? row.status : this.publicChatStatusBadge(row.status) },
                    { key: 'priority', label: 'Priority' },
                    { key: 'assigned_agent_name', label: 'Agent', render: (row, plain) => plain ? this.publicChatFriendlyActor(row, 'assigned_agent_name') : this.escapeHtml(this.publicChatFriendlyActor(row, 'assigned_agent_name')) },
                    { key: 'created_at', label: 'Created' }
                ], { title: 'Public Chat Reports' })}
            </section>
            <section class="public-chat-report-section">
                <h4>Ticket Records</h4>
                ${this.publicChatRenderTable('reportTickets', tickets, [
                    { key: 'ticket_reference', label: 'Ticket Reference' },
                    { key: 'chat_reference', label: 'Chat Reference' },
                    { key: 'status', label: 'Status', render: (row, plain) => plain ? row.status : this.publicChatStatusBadge(row.status) },
                    { key: 'subject', label: 'Subject' },
                    { key: 'visitor_name', label: 'Visitor' },
                    { key: 'assigned_name', label: 'Assigned To', render: (row, plain) => plain ? this.publicChatFriendlyActor(row, 'assigned_name') : this.escapeHtml(this.publicChatFriendlyActor(row, 'assigned_name')) },
                    { key: 'created_at', label: 'Created' }
                ], { title: 'Public Chat Ticket Reports' })}
            </section>
        `;
    } catch (error) {
        wrap.innerHTML = `<div class="settings-note">${this.escapeHtml(error.message || 'Unable to load public chat reports.')}</div>`;
    }
};

AdminDashboard.prototype.loadPublicChatDetail = async function (sessionId) {
    if (!sessionId) return;
    try {
        const data = await this.publicChatFetch({ action: 'detail', session_id: sessionId }, 'GET');
        this.publicChatState.selectedId = sessionId;
        this.publicChatState.selected = data;
        document.getElementById('publicChatDetailTitle').textContent = data.session.chat_reference || 'Conversation';
        document.getElementById('publicChatDetailMeta').textContent = `${data.session.visitor_name || ''} - ${data.session.inquiry_category || ''} - ${data.session.district || ''}`;
        this.renderPublicChatMessages(data.messages || []);
        this.renderPublicChatNotes(data.notes || []);
        ['publicChatAcceptBtn', 'publicChatTransferBtn', 'publicChatEscalateBtn', 'publicChatTicketBtn', 'publicChatCloseBtn', 'publicChatAgentReplyText', 'publicChatSendReplyBtn', 'publicChatAgentNoteText', 'publicChatAddNoteBtn'].forEach((id) => {
            const node = document.getElementById(id);
            if (node) node.disabled = false;
        });
    } catch (error) {
        this.showNotification(error.message || 'Unable to load public chat.', 'error');
    }
};

AdminDashboard.prototype.loadPublicChatTickets = async function () {
    try {
        const data = await this.publicChatFetch({ action: 'tickets' }, 'GET');
        const wrap = document.getElementById('publicChatTicketsList');
        if (!wrap) return;
        wrap.innerHTML = (data.tickets || []).map((ticket) => `
            <div class="settings-list-row">
                <strong>${this.escapeHtml(ticket.ticket_reference || '')} - ${this.escapeHtml(ticket.status || '')}</strong>
                <span>${this.escapeHtml(ticket.subject || '')}</span>
                <small>${this.escapeHtml(ticket.chat_reference || '')} - ${this.escapeHtml(ticket.visitor_name || '')}</small>
            </div>
        `).join('') || '<div class="settings-note">No tickets.</div>';
    } catch (_) {}
};

AdminDashboard.prototype.loadPublicChatEscalations = async function () {
    try {
        const data = await this.publicChatFetch({ action: 'escalations' }, 'GET');
        const wrap = document.getElementById('publicChatEscalationsList');
        if (!wrap) return;
        wrap.innerHTML = (data.escalations || []).map((item) => `
            <div class="settings-list-row">
                <strong>${this.escapeHtml(item.chat_reference || '')} - ${this.escapeHtml(item.priority || '')}</strong>
                <span>${this.escapeHtml(item.reason || '')}</span>
                <small>${this.escapeHtml(item.escalated_by_name || item.escalated_by || '')} to ${this.escapeHtml(item.escalated_to_name || item.escalated_to || 'Supervisor')}</small>
            </div>
        `).join('') || '<div class="settings-note">No escalations.</div>';
    } catch (_) {}
};

AdminDashboard.prototype.loadPublicChatCanned = async function () {
    try {
        const data = await this.publicChatFetch({ action: 'canned' }, 'GET');
        const wrap = document.getElementById('publicChatCannedList');
        if (!wrap) return;
        wrap.innerHTML = (data.responses || []).map((item) => `
            <button type="button" class="settings-list-row" data-canned-id="${item.response_id}">
                <strong>${this.escapeHtml(item.title || '')}</strong>
                <span>${this.escapeHtml(item.inquiry_category || 'General')}</span>
                <small>${this.escapeHtml((item.body || '').slice(0, 160))}</small>
            </button>
        `).join('') || '<div class="settings-note">No canned responses.</div>';
        wrap.querySelectorAll('[data-canned-id]').forEach((btn) => {
            btn.addEventListener('click', () => {
                const item = (data.responses || []).find((row) => String(row.response_id) === String(btn.dataset.cannedId));
                if (!item) return;
                const reply = document.getElementById('publicChatAgentReplyText');
                if (reply) reply.value = item.body || '';
                document.getElementById('publicChatCannedId').value = item.response_id || '';
                document.getElementById('publicChatCannedTitle').value = item.title || '';
                document.getElementById('publicChatCannedCategory').value = item.inquiry_category || '';
                document.getElementById('publicChatCannedBody').value = item.body || '';
            });
        });
    } catch (_) {}
};

AdminDashboard.prototype.savePublicChatCanned = async function (event) {
    event.preventDefault();
    try {
        await this.publicChatFetch({
            action: 'save_canned',
            response_id: document.getElementById('publicChatCannedId')?.value || 0,
            title: document.getElementById('publicChatCannedTitle')?.value || '',
            inquiry_category: document.getElementById('publicChatCannedCategory')?.value || '',
            body: document.getElementById('publicChatCannedBody')?.value || '',
            is_active: true
        });
        event.target.reset();
        await this.loadPublicChatCanned();
    } catch (error) {
        this.showNotification(error.message || 'Unable to save canned response.', 'error');
    }
};

AdminDashboard.prototype.loadPublicChatAgents = async function () {
    try {
        const data = await this.publicChatFetch({ action: 'agents' }, 'GET');
        const wrap = document.getElementById('publicChatAgentsList');
        if (!wrap) return;
        wrap.innerHTML = this.publicChatRenderTable('agents', data.agents || [], [
            { key: 'userName', label: 'Name', render: (agent, plain) => plain ? (agent.userName || 'Unnamed user') : `<strong>${this.escapeHtml(agent.userName || 'Unnamed user')}</strong>` },
            { key: 'userRole', label: 'Role' },
            { key: 'userEmail', label: 'Email' },
            { key: 'phoneNo', label: 'Phone' },
            { key: 'is_enabled', label: 'Enabled', render: (agent, plain) => plain ? (Number(agent.is_enabled) === 1 ? 'Enabled' : 'Disabled') : this.publicChatStatusBadge(Number(agent.is_enabled) === 1 ? 'Enabled' : 'Disabled') },
            { key: 'availability_status', label: 'Availability', render: (agent, plain) => plain ? (agent.availability_status || 'offline') : this.publicChatStatusBadge(agent.availability_status || 'offline') },
            { key: 'max_active_chats', label: 'Max Chats' },
            { key: 'rights', label: 'Rights', render: (agent, plain) => {
                const rights = [
                    ['Accept', agent.can_accept_chat], ['Transfer', agent.can_transfer_chat], ['Escalate', agent.can_escalate_chat],
                    ['Close', agent.can_close_chat], ['All Chats', agent.can_view_all_chats], ['Reports', agent.can_view_reports],
                    ['Canned', agent.can_manage_canned_responses], ['Settings', agent.can_manage_chat_settings]
                ].filter(([, enabled]) => Number(enabled) === 1).map(([label]) => label);
                return plain ? (rights.join(', ') || 'Viewer only') : `<span>${this.escapeHtml(rights.join(', ') || 'Viewer only')}</span>`;
            }},
            { key: 'action', label: 'Action', render: (agent, plain) => plain ? 'Edit permissions' : `<button type="button" class="action-btn secondary small" data-public-chat-edit-agent="${this.escapeHtml(agent.userId || '')}">Edit</button>` }
        ], { title: 'Public Chat Agents', rowId: 'userId' });
        this.filterPublicChatTable('agents', document.querySelector('[data-public-chat-filter="agents"]')?.value || '');
        wrap.querySelectorAll('[data-public-chat-edit-agent]').forEach((button) => button.addEventListener('click', () => this.editPublicChatAgent(button.dataset.publicChatEditAgent, data.agents || [])));
    } catch (_) {}
};

AdminDashboard.prototype.editPublicChatAgent = async function (userId, agents) {
    const current = (agents || []).find((agent) => String(agent.userId) === String(userId)) || {};
    const currentlyEnabled = Number(current.is_enabled || 0) === 1;
    const confirmed = await this.publicChatConfirm(`${currentlyEnabled ? 'Disable' : 'Enable'} ${current.userName || 'this user'} as a public chat handler?`, {
        title: 'Public Chat Agent',
        confirmText: currentlyEnabled ? 'Disable' : 'Enable',
        cancelText: 'Cancel'
    });
    if (!confirmed) return;
    const enabled = !currentlyEnabled;
    try {
        await this.publicChatFetch({
            action: 'save_agent',
            user_id: userId,
            enabled,
            can_handle_public_chat: enabled,
            can_accept_chat: enabled,
            can_transfer_chat: enabled,
            can_escalate_chat: enabled,
            can_close_chat: enabled,
            can_view_all_chats: enabled,
            can_view_reports: enabled,
            can_manage_canned_responses: enabled,
            can_manage_chat_settings: false,
            availability_status: enabled ? 'online' : 'offline',
            max_active_chats: current.max_active_chats || 5
        });
        await this.loadPublicChatAgents();
    } catch (error) {
        this.showNotification(error.message || 'Unable to save agent.', 'error');
    }
};

AdminDashboard.prototype.loadPublicChatAudit = async function () {
    try {
        const data = await this.publicChatFetch({ action: 'audit' }, 'GET');
        const wrap = document.getElementById('publicChatAuditList');
        if (!wrap) return;
        wrap.innerHTML = this.publicChatRenderTable('audit', data.logs || [], [
            { key: 'created_at', label: 'Time' },
            { key: 'action', label: 'Action' },
            { key: 'actor_name', label: 'Actor', render: (log, plain) => plain ? (log.actor_name || 'Public Visitor') : `<strong>${this.escapeHtml(log.actor_name || 'Public Visitor')}</strong>` },
            { key: 'actor_role', label: 'Role' },
            { key: 'chat_reference', label: 'Chat Reference' },
            { key: 'details', label: 'Details', render: (log, plain) => plain ? this.publicChatDisplayValue(log.details) : this.escapeHtml(this.publicChatDisplayValue(log.details)) },
            { key: 'ip_address', label: 'IP Address' }
        ], { title: 'Public Chat Audit Logs' });
        this.filterPublicChatTable('audit', document.querySelector('[data-public-chat-filter="audit"]')?.value || '');
    } catch (_) {}
};

AdminDashboard.prototype.exportPublicChatReport = function () {
    const form = document.getElementById('publicChatReportFilters');
    const params = new URLSearchParams(form ? new FormData(form) : undefined);
    window.open(`../backend/api/public_chat_export.php?${params.toString()}`, '_blank', 'noopener');
};

AdminDashboard.prototype.renderPublicChatMessages = function (messages) {
    const wrap = document.getElementById('publicChatMessages');
    if (!wrap) return;
    wrap.innerHTML = messages.map((msg) => `
        <div class="chat-oversight-message ${msg.sender_type === 'visitor' ? 'received' : 'sent'}">
            <div>${this.escapeHtml(msg.message_text || '')}</div>
            <small>${this.escapeHtml(msg.sender_name || msg.sender_type || '')} - ${this.escapeHtml(msg.created_at || '')}</small>
        </div>
    `).join('') || '<div class="settings-note">No messages yet.</div>';
    wrap.scrollTop = wrap.scrollHeight;
};

AdminDashboard.prototype.renderPublicChatNotes = function (notes) {
    const wrap = document.getElementById('publicChatNotes');
    if (!wrap) return;
    wrap.innerHTML = notes.map((note) => `
        <div class="settings-list-row">
            <strong>${this.escapeHtml(note.agent_name || 'Staff')}</strong>
            <span>${this.escapeHtml(note.note_text || '')}</span>
            <small>${this.escapeHtml(note.created_at || '')}</small>
        </div>
    `).join('');
};

AdminDashboard.prototype.sendPublicChatReply = async function (event) {
    event.preventDefault();
    const textarea = document.getElementById('publicChatAgentReplyText');
    const message = String(textarea?.value || '').trim();
    const sessionId = this.publicChatState?.selectedId;
    if (!message || !sessionId) return;
    const response = await fetch('../backend/api/public_chat_send.php', {
        method: 'POST',
        credentials: 'include',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ session_id: sessionId, message, as_agent: true })
    });
    const data = await this.safeJson(response, { success: false });
    if (!data.success) {
        this.showNotification(data.message || 'Unable to send reply.', 'error');
        return;
    }
    textarea.value = '';
    await this.loadPublicChatDetail(sessionId);
};

AdminDashboard.prototype.addPublicChatNote = async function (event) {
    event.preventDefault();
    const textarea = document.getElementById('publicChatAgentNoteText');
    const note = String(textarea?.value || '').trim();
    const sessionId = this.publicChatState?.selectedId;
    if (!note || !sessionId) return;
    await this.publicChatAction('note', { note });
    textarea.value = '';
};

AdminDashboard.prototype.publicChatAction = async function (action, extra = {}) {
    const sessionId = this.publicChatState?.selectedId;
    if (!sessionId && !['status'].includes(action)) return;
    try {
        await this.publicChatFetch({ action, session_id: sessionId, ...extra });
        if (sessionId) await this.loadPublicChatDetail(sessionId);
        await this.loadPublicChatQueue();
    } catch (error) {
        this.showNotification(error.message || 'Public chat action failed.', 'error');
    }
};

AdminDashboard.prototype.setPublicChatAgentStatus = async function (status) {
    try {
        await this.publicChatFetch({ action: 'status', agent_status: status });
        this.updateSettingsStatus('publicChatSupport', 'Status saved', 'success');
    } catch (error) {
        this.showNotification(error.message || 'Unable to update chat status.', 'error');
    }
};

AdminDashboard.prototype.loadChatOversightContent = async function () {
    return `
        <div class="chat-oversight-content">
            <div class="settings-header chat-oversight-header">
                <div>
                    <h2 class="section-title">Chat Oversight</h2>
                    <p class="section-subtitle">Administrative review of direct peer messages and group chat transcripts, including deleted-message audit state.</p>
                </div>
                <div class="settings-actions">
                    <span class="settings-status" id="chatOversightStatus">Ready</span>
                    <button class="action-btn secondary" id="refreshChatOversightBtn" type="button">Refresh</button>
                    <button class="action-btn danger" id="clearAllChatOversightBtn" type="button">Clear All</button>
                </div>
            </div>

            <div class="chat-oversight-summary" id="chatOversightSummary">
                <span class="queue-chip">Conversations: <strong>0</strong></span>
                <span class="queue-chip queued">Direct: <strong>0</strong></span>
                <span class="queue-chip sent">Groups: <strong>0</strong></span>
                <span class="queue-chip failed">Messages: <strong>0</strong></span>
            </div>

            <div class="chat-oversight-toolbar">
                <input type="search" id="chatOversightSearch" class="filter-input" placeholder="Search participants, groups, or message preview" autocomplete="off">
                <select id="chatOversightType" class="filter-select">
                    <option value="all">All chats</option>
                    <option value="direct">Direct peers</option>
                    <option value="group">Group chats</option>
                </select>
                <button class="filter-btn" id="applyChatOversightFilters" type="button">Apply</button>
                <button class="filter-btn secondary" id="clearChatOversightFilters" type="button">Clear</button>
            </div>

            <section class="chat-oversight-shell" aria-label="Read-only chat oversight">
                <aside class="chat-oversight-list" id="chatOversightList">
                    <div class="chat-oversight-empty">Loading conversations...</div>
                </aside>
                <article class="chat-oversight-thread">
                    <header class="chat-oversight-thread-header" id="chatOversightThreadHeader">
                        <div>
                            <h3>Select a conversation</h3>
                            <p>Messages open here in read-only mode.</p>
                        </div>
                        <span class="chat-oversight-readonly">Admin oversight</span>
                    </header>
                    <div class="chat-oversight-messages" id="chatOversightMessages">
                        <div class="chat-oversight-placeholder">Choose a direct or group chat from the left to inspect its transcript.</div>
                    </div>
                    <footer class="chat-oversight-footer">
                        <button class="filter-btn secondary" id="loadMoreChatOversightMessages" type="button" disabled>Load older messages</button>
                    </footer>
                </article>
            </section>
        </div>
    `;
};

AdminDashboard.prototype.initializeChatOversight = async function () {
    this.chatOversightState = {
        conversations: [],
        selected: null,
        oldestMessageId: 0,
        canLoadMore: false,
        messages: []
    };

    document.getElementById('refreshChatOversightBtn')?.addEventListener('click', () => this.loadChatOversightConversations(true));
    document.getElementById('clearAllChatOversightBtn')?.addEventListener('click', () => this.clearAllChatOversightMessages());
    document.getElementById('applyChatOversightFilters')?.addEventListener('click', () => this.loadChatOversightConversations(true));
    document.getElementById('clearChatOversightFilters')?.addEventListener('click', () => {
        const search = document.getElementById('chatOversightSearch');
        const type = document.getElementById('chatOversightType');
        if (search) search.value = '';
        if (type) type.value = 'all';
        this.loadChatOversightConversations(true);
    });
    document.getElementById('chatOversightSearch')?.addEventListener('keydown', (event) => {
        if (event.key === 'Enter') {
            this.loadChatOversightConversations(true);
        }
    });
    document.getElementById('loadMoreChatOversightMessages')?.addEventListener('click', () => {
        this.loadChatOversightMessages(this.chatOversightState?.selected, true);
    });
    document.getElementById('chatOversightMessages')?.addEventListener('click', (event) => {
        const button = event.target instanceof Element ? event.target.closest('[data-chat-oversight-delete]') : null;
        if (button) {
            this.deleteChatOversightMessages([Number(button.dataset.chatOversightDelete || 0)]);
        }
    });

    await this.loadChatOversightConversations(true);
};

AdminDashboard.prototype.fetchChatOversight = async function (params = {}) {
    const query = new URLSearchParams(params);
    const response = await fetch(`../backend/api/admin_live_chat_audit.php?${query.toString()}`, {
        credentials: 'include'
    });
    const data = await this.safeJson(response, { success: false });
    if (!response.ok || !data.success) {
        throw new Error(data.message || 'Unable to load chat oversight data.');
    }
    return data;
};

AdminDashboard.prototype.postChatOversight = async function (payload = {}) {
    const response = await fetch('../backend/api/admin_live_chat_audit.php', {
        method: 'POST',
        credentials: 'include',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload)
    });
    const data = await this.safeJson(response, { success: false });
    if (!response.ok || !data.success) {
        throw new Error(data.message || 'Unable to update chat oversight data.');
    }
    return data;
};

AdminDashboard.prototype.loadChatOversightConversations = async function (selectFirst = false) {
    const status = document.getElementById('chatOversightStatus');
    if (status) {
        status.textContent = 'Loading';
        status.dataset.state = 'info';
    }

    try {
        const data = await this.fetchChatOversight({
            action: 'conversations',
            q: document.getElementById('chatOversightSearch')?.value || '',
            type: document.getElementById('chatOversightType')?.value || 'all'
        });
        this.chatOversightState.conversations = Array.isArray(data.conversations) ? data.conversations : [];
        this.renderChatOversightSummary(data.summary || {});
        this.renderChatOversightConversationList();
        if (status) {
            status.textContent = 'Ready';
            status.dataset.state = 'success';
        }
        if (selectFirst && this.chatOversightState.conversations.length > 0) {
            await this.selectChatOversightConversation(this.chatOversightState.conversations[0].conversationKey);
        } else if (this.chatOversightState.conversations.length === 0) {
            this.renderChatOversightEmptyThread();
        }
    } catch (error) {
        if (status) {
            status.textContent = 'Load failed';
            status.dataset.state = 'error';
        }
        this.showNotification(error.message || 'Unable to load chat oversight data.', 'error');
        const list = document.getElementById('chatOversightList');
        if (list) {
            list.innerHTML = `<div class="chat-oversight-empty">${this.escapeHtml(error.message || 'Unable to load conversations.')}</div>`;
        }
    }
};

AdminDashboard.prototype.renderChatOversightSummary = function (summary = {}) {
    const target = document.getElementById('chatOversightSummary');
    if (!target) return;
    target.innerHTML = `
        <span class="queue-chip">Conversations: <strong>${Number(summary.totalConversations || 0).toLocaleString()}</strong></span>
        <span class="queue-chip queued">Direct: <strong>${Number(summary.directConversations || 0).toLocaleString()}</strong></span>
        <span class="queue-chip sent">Groups: <strong>${Number(summary.groupConversations || 0).toLocaleString()}</strong></span>
        <span class="queue-chip failed">Messages: <strong>${Number(summary.totalMessages || 0).toLocaleString()}</strong></span>
    `;
};

AdminDashboard.prototype.renderChatOversightConversationList = function () {
    const list = document.getElementById('chatOversightList');
    if (!list) return;
    const conversations = this.chatOversightState?.conversations || [];
    if (!conversations.length) {
        list.innerHTML = '<div class="chat-oversight-empty">No chat conversations match the current filters.</div>';
        return;
    }
    list.innerHTML = conversations.map((conversation) => {
        const selected = this.chatOversightState?.selected?.conversationKey === conversation.conversationKey;
        const typeLabel = conversation.type === 'group' ? 'Group' : 'Direct';
        const time = this.formatChatOversightDate(conversation.lastMessageAt, true);
        return `
            <button class="chat-oversight-item ${selected ? 'is-active' : ''}" type="button" data-chat-key="${this.escapeHtml(conversation.conversationKey)}">
                <span class="chat-oversight-item-top">
                    <strong>${this.escapeHtml(conversation.title || 'Conversation')}</strong>
                    <em>${this.escapeHtml(typeLabel)}</em>
                </span>
                <span class="chat-oversight-item-preview">${this.escapeHtml(conversation.lastMessagePreview || 'No messages yet')}</span>
                <span class="chat-oversight-item-meta">
                    <span>${Number(conversation.messageCount || 0).toLocaleString()} messages</span>
                    <span>${this.escapeHtml(time || '')}</span>
                </span>
            </button>
        `;
    }).join('');

    list.querySelectorAll('.chat-oversight-item').forEach((button) => {
        button.addEventListener('click', () => this.selectChatOversightConversation(button.dataset.chatKey || ''));
    });
};

AdminDashboard.prototype.selectChatOversightConversation = async function (conversationKey) {
    const conversation = (this.chatOversightState?.conversations || []).find((item) => item.conversationKey === conversationKey);
    if (!conversation) return;
    this.chatOversightState.selected = conversation;
    this.chatOversightState.oldestMessageId = 0;
    this.chatOversightState.canLoadMore = false;
    this.chatOversightState.messages = [];
    this.renderChatOversightConversationList();
    await this.loadChatOversightMessages(conversation, false);
};

AdminDashboard.prototype.loadChatOversightMessages = async function (conversation, appendOlder = false) {
    if (!conversation) return;
    const messagesNode = document.getElementById('chatOversightMessages');
    const loadMore = document.getElementById('loadMoreChatOversightMessages');
    if (loadMore) loadMore.disabled = true;
    if (messagesNode && !appendOlder) {
        messagesNode.innerHTML = '<div class="chat-oversight-placeholder">Loading transcript...</div>';
    }

    try {
        const params = { action: 'messages', type: conversation.type, limit: 100 };
        if (conversation.type === 'group') {
            params.group_id = conversation.groupId;
        } else {
            params.peer_a = conversation.peerA;
            params.peer_b = conversation.peerB;
        }
        if (appendOlder && this.chatOversightState.oldestMessageId > 0) {
            params.before_id = this.chatOversightState.oldestMessageId;
        }
        const data = await this.fetchChatOversight(params);
        const incoming = Array.isArray(data.messages) ? data.messages : [];
        const existing = appendOlder ? (this.chatOversightState.messages || []) : [];
        this.chatOversightState.messages = appendOlder ? incoming.concat(existing) : incoming;
        this.chatOversightState.oldestMessageId = this.chatOversightState.messages.length
            ? Number(this.chatOversightState.messages[0].id || 0)
            : 0;
        this.chatOversightState.canLoadMore = incoming.length >= 100;
        this.renderChatOversightThread(conversation, this.chatOversightState.messages);
    } catch (error) {
        if (messagesNode) {
            messagesNode.innerHTML = `<div class="chat-oversight-placeholder">${this.escapeHtml(error.message || 'Unable to load transcript.')}</div>`;
        }
    }
};

AdminDashboard.prototype.renderChatOversightThread = function (conversation, messages) {
    const header = document.getElementById('chatOversightThreadHeader');
    const messagesNode = document.getElementById('chatOversightMessages');
    const loadMore = document.getElementById('loadMoreChatOversightMessages');
    if (header) {
        header.innerHTML = `
            <div>
                <h3>${this.escapeHtml(conversation.title || 'Conversation')}</h3>
                <p>${this.escapeHtml(conversation.subtitle || '')} - ${Number(conversation.messageCount || messages.length || 0).toLocaleString()} messages</p>
            </div>
            <div class="chat-oversight-thread-actions">
                <button class="filter-btn danger" id="clearChatOversightConversationBtn" type="button">Clear conversation</button>
                <span class="chat-oversight-readonly">Admin oversight</span>
            </div>
        `;
        header.querySelector('#clearChatOversightConversationBtn')?.addEventListener('click', () => this.clearChatOversightConversation(conversation));
    }
    if (!messagesNode) return;
    if (!messages.length) {
        messagesNode.innerHTML = '<div class="chat-oversight-placeholder">This conversation has no messages yet.</div>';
    } else {
        let lastDate = '';
        messagesNode.innerHTML = messages.map((message) => {
            const dateLabel = this.formatChatOversightDate(message.createdAt, false);
            const divider = dateLabel && dateLabel !== lastDate
                ? `<div class="chat-oversight-date-divider"><span>${this.escapeHtml(dateLabel)}</span></div>`
                : '';
            if (dateLabel) lastDate = dateLabel;
            return divider + this.renderChatOversightMessage(message);
        }).join('');
        messagesNode.scrollTop = messagesNode.scrollHeight;
    }
    if (loadMore) {
        loadMore.disabled = !this.chatOversightState.canLoadMore;
    }
};

AdminDashboard.prototype.renderChatOversightMessage = function (message) {
    const isDeleted = Boolean(message.isDeleted);
    const body = this.renderChatOversightMessageBody(message);
    const reply = Number(message.replyToMessageId || 0) > 0
        ? `<div class="chat-oversight-reply"><strong>${this.escapeHtml(message.replyToSenderName || 'Message')}</strong><span>${this.escapeHtml(message.replyToMessageText || message.replyToFileName || 'Original message')}</span></div>`
        : '';
    const badges = [
        message.isAdminDeleted ? `<span>Deleted by ${this.escapeHtml(message.adminDeletedByName || 'Administrator')}</span>` : '',
        message.deletedByLabel ? `<span>${this.escapeHtml(message.deletedByLabel)}</span>` : '',
        message.isEdited ? '<span>Edited</span>' : '',
        message.isPinned ? '<span>Pinned</span>' : '',
        message.isRead ? '<span>Read</span>' : (message.deliveredAt ? '<span>Delivered</span>' : '')
    ].filter(Boolean).join('');

    return `
        <div class="chat-oversight-message ${isDeleted ? 'is-deleted' : ''}">
            <div class="chat-oversight-message-head">
                <strong>${this.escapeHtml(message.senderName || 'Unknown User')}</strong>
                <time>${this.escapeHtml(this.formatChatOversightDate(message.createdAt, true))}</time>
            </div>
            ${reply}
            ${body}
            <div class="chat-oversight-message-foot">
                <span class="chat-oversight-kind">${this.escapeHtml(message.kind || 'text')}</span>
                <span class="chat-oversight-badges">${badges}</span>
                ${message.reactionEmoji ? `<span class="chat-oversight-reaction">${this.escapeHtml(message.reactionEmoji)}</span>` : ''}
                <button class="chat-oversight-delete-btn" type="button" data-chat-oversight-delete="${Number(message.id || 0)}">Delete</button>
            </div>
        </div>
    `;
};

AdminDashboard.prototype.renderChatOversightMessageBody = function (message) {
    if (String(message.kind || '').toLowerCase() === 'call') {
        return this.renderChatOversightCallRecord(message);
    }

    const text = String(message.text || '').trim();
    const fileName = String(message.fileName || '').trim();
    const parts = [];
    if (text) {
        parts.push(`<div class="chat-oversight-text">${this.escapeHtml(text).replace(/\n/g, '<br>')}</div>`);
    }
    if (fileName) {
        parts.push(`<div class="chat-oversight-attachment"><strong>Attachment:</strong> ${this.escapeHtml(fileName)}${message.fileSize ? ` - ${this.formatBytes(Number(message.fileSize || 0))}` : ''}</div>`);
    }
    if (parts.length) return parts.join('');
    if (message.isAdminDeleted) return '<div class="chat-oversight-deleted">Message content retained only in audit archive if available.</div>';
    if (message.isDeleted) return '<div class="chat-oversight-deleted">This message was deleted before archive content was available.</div>';
    return '<div class="chat-oversight-text muted">No text content.</div>';
};

AdminDashboard.prototype.parseChatOversightCallPayload = function (message) {
    const raw = String(message?.text || '').trim();
    if (!raw) return null;
    try {
        const payload = JSON.parse(raw);
        return payload && typeof payload === 'object' ? payload : null;
    } catch (_error) {
        return null;
    }
};

AdminDashboard.prototype.formatChatOversightCallDuration = function (seconds) {
    const total = Math.max(0, Number(seconds || 0));
    if (!Number.isFinite(total) || total <= 0) return 'No duration';
    const minutes = Math.floor(total / 60);
    const remaining = Math.floor(total % 60);
    if (minutes <= 0) return `${remaining}s`;
    return `${minutes}m ${String(remaining).padStart(2, '0')}s`;
};

AdminDashboard.prototype.renderChatOversightCallRecord = function (message) {
    const call = this.parseChatOversightCallPayload(message);
    if (!call) {
        const fallback = String(message.text || '').trim();
        return fallback
            ? `<div class="chat-oversight-text">${this.escapeHtml(fallback).replace(/\n/g, '<br>')}</div>`
            : '<div class="chat-oversight-text muted">Call record unavailable.</div>';
    }

    const callType = String(call.callType || 'audio').toLowerCase();
    const status = String(call.status || 'logged').toLowerCase();
    const statusLabels = {
        missed: 'Missed call',
        rejected: 'Rejected call',
        ended: 'Ended call',
        accepted: 'Answered call',
        ringing: 'Ringing call',
        failed: 'Failed call',
        logged: 'Call record'
    };
    const caller = String(call.callerName || 'Caller');
    const callee = String(call.calleeName || 'Recipient');
    const startedAt = call.createdAt || message.createdAt || '';
    const endedAt = call.endedAt || '';
    const duration = this.formatChatOversightCallDuration(call.durationSeconds);

    return `
        <div class="chat-oversight-call-record ${this.escapeHtml(status)}">
            <div class="chat-oversight-call-icon">${callType === 'video' ? '<i class="fas fa-video"></i>' : '<i class="fas fa-phone"></i>'}</div>
            <div class="chat-oversight-call-main">
                <strong>${this.escapeHtml(statusLabels[status] || 'Call record')}</strong>
                <span>${this.escapeHtml(caller)} to ${this.escapeHtml(callee)}</span>
                <small>${this.escapeHtml(callType.charAt(0).toUpperCase() + callType.slice(1))} call${startedAt ? ` - ${this.escapeHtml(this.formatChatOversightDate(startedAt, true))}` : ''}</small>
            </div>
            <div class="chat-oversight-call-meta">
                <span>${this.escapeHtml(duration)}</span>
                ${endedAt ? `<small>Ended ${this.escapeHtml(this.formatChatOversightDate(endedAt, true))}</small>` : ''}
            </div>
        </div>
    `;
};

AdminDashboard.prototype.renderChatOversightEmptyThread = function () {
    const header = document.getElementById('chatOversightThreadHeader');
    const messages = document.getElementById('chatOversightMessages');
    const loadMore = document.getElementById('loadMoreChatOversightMessages');
    if (header) {
        header.innerHTML = `
            <div>
                <h3>No conversation selected</h3>
                <p>Adjust filters or refresh to find chats.</p>
            </div>
            <span class="chat-oversight-readonly">Admin oversight</span>
        `;
    }
    if (messages) {
        messages.innerHTML = '<div class="chat-oversight-placeholder">No conversations are available for the current filter.</div>';
    }
    if (loadMore) loadMore.disabled = true;
};

AdminDashboard.prototype.deleteChatOversightMessages = async function (messageIds = []) {
    const ids = messageIds.map(Number).filter((id) => id > 0);
    if (!ids.length) return;
    const confirmed = typeof window.appConfirm === 'function'
        ? await window.appConfirm(ids.length === 1
            ? 'Delete this message from all peer chat views? It will remain logged for oversight.'
            : `Delete ${ids.length} messages from all peer chat views? They will remain logged for oversight.`, {
                title: 'Delete Chat Message',
                confirmText: 'Delete',
                cancelText: 'Cancel'
            })
        : false;
    if (!confirmed) return;
    try {
        const data = await this.postChatOversight({
            action: 'delete',
            mode: 'selected',
            message_ids: ids,
            reason: 'Deleted from Chat Oversight'
        });
        this.showNotification(`${Number(data.deleted || 0)} message${Number(data.deleted || 0) === 1 ? '' : 's'} deleted from peer chat views.`, 'success');
        await this.loadChatOversightMessages(this.chatOversightState?.selected, false);
        await this.loadChatOversightConversations(false);
    } catch (error) {
        this.showNotification(error.message || 'Unable to delete chat message.', 'error');
    }
};

AdminDashboard.prototype.clearChatOversightConversation = async function (conversation) {
    if (!conversation) return;
    const confirmed = typeof window.appConfirm === 'function'
        ? await window.appConfirm(`Clear all messages in "${conversation.title || 'this conversation'}" from peer chat views? They will remain logged for oversight.`, {
            title: 'Clear Chat Conversation',
            confirmText: 'Clear',
            cancelText: 'Cancel'
        })
        : false;
    if (!confirmed) return;
    try {
        const payload = {
            action: 'delete',
            mode: 'conversation',
            type: conversation.type,
            group_id: conversation.groupId || '',
            peer_a: conversation.peerA || '',
            peer_b: conversation.peerB || '',
            reason: 'Conversation cleared from Chat Oversight'
        };
        const data = await this.postChatOversight(payload);
        this.showNotification(`${Number(data.deleted || 0)} message${Number(data.deleted || 0) === 1 ? '' : 's'} cleared from peer chat views.`, 'success');
        await this.loadChatOversightMessages(conversation, false);
        await this.loadChatOversightConversations(false);
    } catch (error) {
        this.showNotification(error.message || 'Unable to clear chat conversation.', 'error');
    }
};

AdminDashboard.prototype.clearAllChatOversightMessages = async function () {
    const confirmed = typeof window.appConfirm === 'function'
        ? await window.appConfirm('Clear all live chat messages from every peer and group chat view? They will remain logged for admin oversight.', {
            title: 'Clear All Chat Messages',
            confirmText: 'Clear All',
            cancelText: 'Cancel'
        })
        : false;
    if (!confirmed) return;
    try {
        const data = await this.postChatOversight({
            action: 'delete',
            mode: 'all',
            reason: 'All chat messages cleared from Chat Oversight'
        });
        this.showNotification(`${Number(data.deleted || 0)} message${Number(data.deleted || 0) === 1 ? '' : 's'} cleared from peer chat views.`, 'success');
        await this.loadChatOversightConversations(true);
    } catch (error) {
        this.showNotification(error.message || 'Unable to clear all chat messages.', 'error');
    }
};

AdminDashboard.prototype.formatChatOversightDate = function (value, withTime = true) {
    if (!value) return '';
    const date = new Date(String(value).replace(' ', 'T'));
    if (Number.isNaN(date.getTime())) return String(value);
    const options = withTime
        ? { year: 'numeric', month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit' }
        : { weekday: 'long', year: 'numeric', month: 'long', day: 'numeric' };
    return date.toLocaleString(undefined, options);
};

AdminDashboard.prototype.loadPodcastSettingsContent = async function () {
    return `
        <div class="settings-content podcast-settings-content">
            <div class="settings-header">
                <div>
                    <h2 class="section-title">Podcast Library</h2>
                    <p class="section-subtitle">Manage role-targeted pension videos, public guidance links, and podcast visibility across the platform.</p>
                </div>
                <div class="settings-actions">
                    <span class="settings-status" id="podcastSettingsStatus">Ready</span>
                    <button class="action-btn secondary" id="resetPodcastSettingsBtn" type="button">Reset Changes</button>
                    <button class="action-btn" id="savePodcastSettingsBtn" type="button">Save Settings</button>
                </div>
            </div>

            <div class="settings-grid podcast-settings-grid">
                <section class="settings-card">
                    <div class="settings-card-header">
                        <h3>Podcast Controls</h3>
                        <p>Decide who can access video guidance and whether public videos are surfaced on the About page.</p>
                    </div>
                    <form id="podcastSettingsForm" class="settings-fields">
                        <div class="settings-toggle">
                            <div><div class="toggle-title">Enable Podcast Module</div><div class="toggle-subtitle">Allow the podcast pages and video library to load.</div></div>
                            <label class="switch"><input type="checkbox" name="podcast_enabled"><span class="slider"></span></label>
                        </div>
                        <div class="settings-toggle">
                            <div><div class="toggle-title">Enable Public Videos</div><div class="toggle-subtitle">Expose public videos through the public-facing library.</div></div>
                            <label class="switch"><input type="checkbox" name="podcast_public_enabled"><span class="slider"></span></label>
                        </div>
                        <div class="settings-toggle">
                            <div><div class="toggle-title">Enable Staff Videos</div><div class="toggle-subtitle">Allow internal staff-targeted podcast videos.</div></div>
                            <label class="switch"><input type="checkbox" name="podcast_staff_enabled"><span class="slider"></span></label>
                        </div>
                        <div class="settings-toggle">
                            <div><div class="toggle-title">Enable Pensioner Videos</div><div class="toggle-subtitle">Expose pensioner-specific video guidance inside the pensioner portal.</div></div>
                            <label class="switch"><input type="checkbox" name="podcast_pensioner_enabled"><span class="slider"></span></label>
                        </div>
                        <div class="settings-toggle">
                            <div><div class="toggle-title">Show Public Podcast Button on About Page</div><div class="toggle-subtitle">Display the public entry point and preview cards on the public About page.</div></div>
                            <label class="switch"><input type="checkbox" name="podcast_show_public_about_button"><span class="slider"></span></label>
                        </div>
                        <div class="settings-toggle">
                            <div><div class="toggle-title">Log Video Views</div><div class="toggle-subtitle">Track podcast playback opens for operational analytics and audit support.</div></div>
                            <label class="switch"><input type="checkbox" name="podcast_log_views"><span class="slider"></span></label>
                        </div>
                        <div class="settings-toggle">
                            <div><div class="toggle-title">Allow Metadata Editing</div><div class="toggle-subtitle">Permit administrators to update title, description, audience, tags, publish state, and ordering of stored videos.</div></div>
                            <label class="switch"><input type="checkbox" name="podcast_allow_metadata_edit"><span class="slider"></span></label>
                        </div>
                        <div class="settings-toggle">
                            <div><div class="toggle-title">Allow Video Replacement</div><div class="toggle-subtitle">Permit replacing an existing YouTube source link while keeping the same library record and view history.</div></div>
                            <label class="switch"><input type="checkbox" name="podcast_allow_video_replace"><span class="slider"></span></label>
                        </div>
                        <div class="settings-toggle">
                            <div><div class="toggle-title">Allow Video Deletion</div><div class="toggle-subtitle">Permit permanent removal of podcast videos and their associated view records from the library.</div></div>
                            <label class="switch"><input type="checkbox" name="podcast_allow_delete"><span class="slider"></span></label>
                        </div>
                    </form>
                </section>

                <section class="settings-card podcast-library-card">
                    <div class="settings-card-header">
                        <h3>Video Library</h3>
                        <p>Add, update, publish, and retire YouTube-based pension guidance videos.</p>
                    </div>
                    <div class="podcast-admin-toolbar">
                        <div class="podcast-admin-filters">
                            <label class="settings-field compact">
                                <span>Audience</span>
                                <select id="podcastAdminAudienceFilter">
                                    <option value="all">All audiences</option>
                                    <option value="public">Public</option>
                                    <option value="staff">Staff</option>
                                    <option value="pensioner">Pensioners</option>
                                </select>
                            </label>
                            <label class="settings-field compact">
                                <span>Status</span>
                                <select id="podcastAdminStatusFilter">
                                    <option value="all">All statuses</option>
                                    <option value="published">Published</option>
                                    <option value="draft">Draft</option>
                                </select>
                            </label>
                            <label class="settings-field compact grow">
                                <span>Search</span>
                                <input type="search" id="podcastAdminSearchInput" placeholder="Search title, tag, or description">
                            </label>
                        </div>
                        <div class="podcast-admin-actions">
                            <button class="action-btn secondary" type="button" id="refreshPodcastLibraryBtn">Refresh</button>
                            <button class="action-btn" type="button" id="addPodcastVideoBtn">Add Video</button>
                        </div>
                    </div>
                    <div class="podcast-admin-summary" id="podcastAdminSummary"></div>
                    <div class="settings-table-container podcast-table-wrap">
                        <table class="settings-table podcast-admin-table">
                            <thead>
                                <tr>
                                    <th>Title</th>
                                    <th>Audience</th>
                                    <th>Status</th>
                                    <th>Featured</th>
                                    <th>Views</th>
                                    <th>Updated</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody id="podcastAdminTableBody">
                                <tr><td colspan="7">Loading podcast videos...</td></tr>
                            </tbody>
                        </table>
                    </div>
                </section>
            </div>
        </div>
    `;
};

AdminDashboard.prototype.initializePodcastSettings = async function () {
    this.podcastAdminState = this.podcastAdminState || { items: [], settings: {}, filters: { audience: 'all', status: 'all', search: '' } };
    document.getElementById('savePodcastSettingsBtn')?.addEventListener('click', () => this.savePodcastSettings());
    document.getElementById('resetPodcastSettingsBtn')?.addEventListener('click', () => this.loadPodcastSettings(true));
    document.getElementById('refreshPodcastLibraryBtn')?.addEventListener('click', () => this.loadPodcastSettings(true));
    document.getElementById('addPodcastVideoBtn')?.addEventListener('click', () => this.openPodcastVideoModal());
    document.getElementById('podcastAdminAudienceFilter')?.addEventListener('change', (event) => {
        this.podcastAdminState.filters.audience = event.target.value || 'all';
        this.renderPodcastAdminTable();
    });
    document.getElementById('podcastAdminStatusFilter')?.addEventListener('change', (event) => {
        this.podcastAdminState.filters.status = event.target.value || 'all';
        this.renderPodcastAdminTable();
    });
    document.getElementById('podcastAdminSearchInput')?.addEventListener('input', (event) => {
        this.podcastAdminState.filters.search = String(event.target.value || '').trim().toLowerCase();
        this.renderPodcastAdminTable();
    });
    await this.loadPodcastSettings();
};

AdminDashboard.prototype.loadPodcastSettings = async function (showNotification = false) {
    const form = document.getElementById('podcastSettingsForm');
    if (!form) return;

    try {
        this.updateSettingsStatus('podcast', 'Loading...', 'info');
        const [settingsResponse, libraryResponse] = await Promise.all([
            this.fetchAppSettingsBundle(showNotification),
            fetch('../backend/api/get_podcast_admin.php', { credentials: 'include', cache: 'no-store' })
        ]);
        const settingsData = settingsResponse;
        const libraryData = await this.safeJson(libraryResponse, { success: false });

        if (!settingsData.success) {
            throw new Error(settingsData.message || 'Unable to load podcast settings.');
        }
        if (!libraryData.success) {
            throw new Error(libraryData.message || 'Unable to load podcast videos.');
        }

        this.applySettingsToForm(form, settingsData.settings || {});
        this.podcastAdminState.items = Array.isArray(libraryData.items) ? libraryData.items : [];
        this.podcastAdminState.settings = settingsData.settings || {};
        this.podcastAdminState.stats = libraryData.stats || {};
        this.renderPodcastAdminSummary();
        this.renderPodcastAdminTable();
        this.updateSettingsStatus('podcast', 'Up to date', 'success');
        if (showNotification) {
            this.showNotification('Podcast library refreshed.', 'success');
        }
    } catch (error) {
        console.error('Load podcast settings error:', error);
        this.updateSettingsStatus('podcast', 'Failed to load', 'error');
        this.showNotification(error.message || 'Unable to load podcast settings.', 'error');
    }
};

AdminDashboard.prototype.getPodcastSettingsPayload = function () {
    const form = document.getElementById('podcastSettingsForm');
    if (!form) return null;
    const getValue = (name) => form.querySelector(`[name="${name}"]`);
    const getBool = (name) => Boolean(getValue(name)?.checked);
    return {
        podcast_enabled: getBool('podcast_enabled'),
        podcast_public_enabled: getBool('podcast_public_enabled'),
        podcast_staff_enabled: getBool('podcast_staff_enabled'),
        podcast_pensioner_enabled: getBool('podcast_pensioner_enabled'),
        podcast_show_public_about_button: getBool('podcast_show_public_about_button'),
        podcast_log_views: getBool('podcast_log_views'),
        podcast_allow_metadata_edit: getBool('podcast_allow_metadata_edit'),
        podcast_allow_video_replace: getBool('podcast_allow_video_replace'),
        podcast_allow_delete: getBool('podcast_allow_delete')
    };
};

AdminDashboard.prototype.savePodcastSettings = async function () {
    const payload = this.getPodcastSettingsPayload();
    if (!payload) return;
    try {
        this.updateSettingsStatus('podcast', 'Saving...', 'info');
        const response = await this.performSensitiveAdminRequest('../backend/api/update_app_settings.php', {
            method: 'POST',
            credentials: 'include',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload)
        }, 'save podcast settings');
        const data = response.__adminPayloadAttached || await this.safeJson(response, { success: false });
        if (!data.success) {
            throw new Error(data.message || 'Unable to save podcast settings.');
        }
        this.applySettingsToForm(document.getElementById('podcastSettingsForm'), data.settings || {});
        this.primeAppSettingsCache(data.settings || null);
        this.updateSettingsStatus('podcast', 'Saved', 'success');
        this.showNotification('Podcast settings saved successfully.', 'success');
    } catch (error) {
        console.error('Save podcast settings error:', error);
        this.updateSettingsStatus('podcast', 'Save failed', 'error');
        this.showNotification(error.message || 'Unable to save podcast settings.', 'error');
    }
};

AdminDashboard.prototype.renderPodcastAdminSummary = function () {
    const container = document.getElementById('podcastAdminSummary');
    if (!container) return;
    const stats = this.podcastAdminState?.stats || {};
    container.innerHTML = [
        ['Total Videos', stats.total || 0],
        ['Published', stats.published || 0],
        ['Featured', stats.featured || 0],
        ['Total Views', stats.views || 0],
        ['Public', stats.public || 0],
        ['Staff', stats.staff || 0],
        ['Pensioners', stats.pensioner || 0]
    ].map(([label, value]) => `
        <div class="podcast-summary-tile">
            <span>${label}</span>
            <strong>${value}</strong>
        </div>
    `).join('');

    this.syncPodcastAdminSummaryLayout(container);
};

AdminDashboard.prototype.syncPodcastAdminSummaryLayout = function (container = document.getElementById('podcastAdminSummary')) {
    if (!container) return;

    const tiles = Array.from(container.querySelectorAll('.podcast-summary-tile'));
    if (!tiles.length) {
        container.style.removeProperty('--podcast-summary-column-width');
        return;
    }

    const measureHost = document.createElement('div');
    measureHost.style.position = 'absolute';
    measureHost.style.visibility = 'hidden';
    measureHost.style.pointerEvents = 'none';
    measureHost.style.left = '-9999px';
    measureHost.style.top = '0';
    measureHost.style.display = 'grid';
    measureHost.style.gridTemplateColumns = 'max-content';
    measureHost.style.gap = '0.8rem';

    tiles.forEach((tile) => {
        const clone = tile.cloneNode(true);
        clone.style.width = 'auto';
        clone.style.maxWidth = 'none';
        clone.style.minWidth = '0';
        measureHost.appendChild(clone);
    });

    document.body.appendChild(measureHost);

    let maxWidth = 0;
    Array.from(measureHost.children).forEach((tile) => {
        const width = Math.ceil(tile.getBoundingClientRect().width);
        if (width > maxWidth) {
            maxWidth = width;
        }
    });

    measureHost.remove();

    if (maxWidth > 0) {
        container.style.setProperty('--podcast-summary-column-width', `${maxWidth}px`);
    } else {
        container.style.removeProperty('--podcast-summary-column-width');
    }
};

AdminDashboard.prototype.renderPodcastAdminTable = function () {
    const body = document.getElementById('podcastAdminTableBody');
    if (!body) return;
    const state = this.podcastAdminState || { items: [], filters: {} };
    const filters = state.filters || {};
    const settings = state.settings || {};
    const metadataEditAllowed = settings.podcast_allow_metadata_edit !== false;
    const replaceAllowed = settings.podcast_allow_video_replace !== false;
    const deleteAllowed = settings.podcast_allow_delete !== false;
    const search = String(filters.search || '').toLowerCase();
    const rows = (state.items || []).filter((item) => {
        if (filters.audience && filters.audience !== 'all' && item.audience !== filters.audience) return false;
        if (filters.status === 'published' && !item.is_published) return false;
        if (filters.status === 'draft' && item.is_published) return false;
        if (!search) return true;
        return [item.title, item.description, item.tags].join(' ').toLowerCase().includes(search);
    });

    if (!rows.length) {
        body.innerHTML = '<tr><td colspan="7">No podcast videos match the current filter.</td></tr>';
        return;
    }

    body.innerHTML = rows.map((item) => `
        <tr>
            <td>
                <div class="podcast-title-cell">
                    <strong>${this.escapeHtml(item.title || 'Untitled Video')}</strong>
                    <small>${this.escapeHtml(item.description || 'No description provided.')}</small>
                </div>
            </td>
            <td>${this.escapeHtml(item.audience_label || item.audience || 'Public')}</td>
            <td><span class="podcast-status-pill ${item.is_published ? 'published' : 'draft'}">${item.is_published ? 'Published' : 'Draft'}</span></td>
            <td>${item.is_featured ? 'Featured' : 'Standard'}</td>
            <td>${Number(item.view_count || 0).toLocaleString()}</td>
            <td>${this.escapeHtml(this.formatAdminDateTime(item.updated_at || item.created_at))}</td>
            <td>
                <div class="table-actions compact">
                    ${metadataEditAllowed ? `<button class="action-btn small secondary" type="button" data-podcast-edit="${item.podcast_id}">Edit Info</button>` : ''}
                    ${replaceAllowed ? `<button class="action-btn small secondary" type="button" data-podcast-replace="${item.podcast_id}">Replace</button>` : ''}
                    ${deleteAllowed ? `<button class="action-btn small danger" type="button" data-podcast-delete="${item.podcast_id}">Delete</button>` : ''}
                </div>
            </td>
        </tr>
    `).join('');

    body.querySelectorAll('[data-podcast-edit]').forEach((button) => {
        button.addEventListener('click', () => {
            const item = (state.items || []).find((entry) => Number(entry.podcast_id) === Number(button.dataset.podcastEdit));
            this.openPodcastVideoModal(item || {}, 'edit');
        });
    });
    body.querySelectorAll('[data-podcast-replace]').forEach((button) => {
        button.addEventListener('click', () => {
            const item = (state.items || []).find((entry) => Number(entry.podcast_id) === Number(button.dataset.podcastReplace));
            this.openPodcastVideoModal(item || {}, 'replace');
        });
    });
    body.querySelectorAll('[data-podcast-delete]').forEach((button) => {
        button.addEventListener('click', async () => {
            const item = (state.items || []).find((entry) => Number(entry.podcast_id) === Number(button.dataset.podcastDelete));
            if (!item) return;
            const confirmed = await window.appConfirm(`Delete \"${item.title}\" from the podcast library?`, {
                title: 'Delete Podcast Video',
                confirmText: 'Delete',
                type: 'warning'
            });
            if (!confirmed) return;
            await this.deletePodcastVideo(item.podcast_id);
        });
    });
};

AdminDashboard.prototype.openPodcastVideoModal = function (video = {}, mode = 'create') {
    document.querySelector('.admin-modal-overlay')?.remove();
    const settings = this.podcastAdminState?.settings || {};
    const metadataEditAllowed = settings.podcast_allow_metadata_edit !== false;
    const replaceAllowed = settings.podcast_allow_video_replace !== false;
    const isExisting = Boolean(video.podcast_id);
    const isReplaceMode = mode === 'replace';
    const heading = !isExisting ? 'Add Podcast Video' : (isReplaceMode ? 'Replace Podcast Video' : 'Edit Podcast Video');
    const description = !isExisting
        ? 'Paste a YouTube link, choose the audience, and decide whether the video should be published immediately.'
        : (isReplaceMode
            ? 'Replace the current YouTube source while keeping the same podcast record, ordering, and view history.'
            : 'Update the podcast metadata, publishing state, and playback source for this library item.');
    const metadataFieldsDisabled = isExisting && isReplaceMode;
    const metadataActionBlocked = isExisting && !isReplaceMode && !metadataEditAllowed;
    const replaceFieldDisabled = isExisting && !replaceAllowed;
    const saveDisabled = metadataActionBlocked || (isReplaceMode && replaceFieldDisabled);
    const overlay = document.createElement('div');
    overlay.className = 'admin-modal-overlay';
    overlay.innerHTML = `
        <div class="admin-modal-panel podcast-video-modal">
            <div class="admin-modal-header">
                <div>
                    <h3>${heading}</h3>
                    <p>${description}</p>
                </div>
                <button type="button" class="admin-modal-close" aria-label="Close">&times;</button>
            </div>
            <div class="admin-modal-body">
                <form id="podcastVideoForm" class="admin-form-grid podcast-video-form">
                    <input type="hidden" name="podcast_id" value="${video.podcast_id || ''}">
                    <input type="hidden" name="modal_mode" value="${this.escapeAttribute(mode)}">
                    ${isExisting ? `
                    <div class="podcast-video-callout full-span">
                        <strong>Current record:</strong> ${this.escapeHtml(video.title || 'Untitled Video')}
                <span>${this.escapeHtml(video.audience_label || video.audience || 'Public')} - ${video.is_published ? 'Published' : 'Draft'} - ${Number(video.view_count || 0).toLocaleString()} views</span>
                    </div>` : ''}
                    <label class="settings-field full-span">
                        <span>Video Title</span>
                        <input type="text" name="title" value="${this.escapeAttribute(video.title || '')}" maxlength="255" required ${(metadataActionBlocked || metadataFieldsDisabled) ? 'disabled' : ''}>
                    </label>
                    <label class="settings-field full-span">
                        <span>YouTube Link</span>
                        <input type="url" name="youtube_url" value="${this.escapeAttribute(video.youtube_url || '')}" placeholder="https://www.youtube.com/watch?v=..." required ${replaceFieldDisabled ? 'disabled' : ''}>
                        ${isExisting ? `<small class="field-help">Current source: ${this.escapeHtml(video.youtube_url || '')}</small>` : ''}
                    </label>
                    <label class="settings-field">
                        <span>Audience</span>
                        <select name="audience" ${(metadataActionBlocked || metadataFieldsDisabled) ? 'disabled' : ''}>
                            <option value="public" ${String(video.audience || 'public') === 'public' ? 'selected' : ''}>Public</option>
                            <option value="staff" ${String(video.audience || '') === 'staff' ? 'selected' : ''}>Staff</option>
                            <option value="pensioner" ${String(video.audience || '') === 'pensioner' ? 'selected' : ''}>Pensioners</option>
                        </select>
                    </label>
                    <label class="settings-field">
                        <span>Sort Order</span>
                        <input type="number" name="sort_order" value="${Number(video.sort_order || 0)}" min="0" step="1" ${(metadataActionBlocked || metadataFieldsDisabled) ? 'disabled' : ''}>
                    </label>
                    <label class="settings-field full-span">
                        <span>Tags</span>
                        <input type="text" name="tags" value="${this.escapeAttribute(video.tags || '')}" placeholder="claims, payroll, life certificate" ${(metadataActionBlocked || metadataFieldsDisabled) ? 'disabled' : ''}>
                        <small class="field-help">Comma-separated tags help with search and discovery.</small>
                    </label>
                    <label class="settings-field full-span">
                        <span>Description</span>
                        <textarea name="description" rows="5" placeholder="Briefly explain what the video covers." ${(metadataActionBlocked || metadataFieldsDisabled) ? 'disabled' : ''}>${this.escapeHtml(video.description || '')}</textarea>
                    </label>
                    <div class="settings-toggle full-span">
                        <div><div class="toggle-title">Publish Now</div><div class="toggle-subtitle">Published videos appear in the podcast library immediately.</div></div>
                        <label class="switch"><input type="checkbox" name="is_published" ${video.podcast_id ? (video.is_published ? 'checked' : '') : 'checked'} ${(metadataActionBlocked || metadataFieldsDisabled) ? 'disabled' : ''}><span class="slider"></span></label>
                    </div>
                    <div class="settings-toggle full-span">
                        <div><div class="toggle-title">Feature Video</div><div class="toggle-subtitle">Featured videos are pinned to the main player area first.</div></div>
                        <label class="switch"><input type="checkbox" name="is_featured" ${video.is_featured ? 'checked' : ''} ${(metadataActionBlocked || metadataFieldsDisabled) ? 'disabled' : ''}><span class="slider"></span></label>
                    </div>
                    ${saveDisabled ? `
                    <div class="podcast-video-callout muted full-span">
                        <strong>Action unavailable</strong>
                        <span>${isReplaceMode ? 'Video replacement is currently disabled in podcast settings.' : 'Podcast metadata editing is currently disabled in settings.'}</span>
                    </div>` : ''}
                </form>
            </div>
            <div class="admin-modal-footer">
                <button type="button" class="action-btn secondary" data-modal-close>Cancel</button>
                <button type="button" class="action-btn" id="savePodcastVideoModalBtn" ${saveDisabled ? 'disabled' : ''}>${isReplaceMode ? 'Replace Video' : 'Save Video'}</button>
            </div>
        </div>
    `;
    document.body.appendChild(overlay);
    const close = () => overlay.remove();
    overlay.querySelector('.admin-modal-close')?.addEventListener('click', close);
    overlay.querySelector('[data-modal-close]')?.addEventListener('click', close);
    overlay.addEventListener('click', (event) => {
        if (event.target === overlay) close();
    });
    if (saveDisabled) {
        return;
    }
    overlay.querySelector('#savePodcastVideoModalBtn')?.addEventListener('click', async () => {
        const form = overlay.querySelector('#podcastVideoForm');
        const payload = {
            podcast_id: Number(form.querySelector('[name="podcast_id"]').value || 0),
            title: form.querySelector('[name="title"]').value.trim(),
            youtube_url: form.querySelector('[name="youtube_url"]').value.trim(),
            audience: form.querySelector('[name="audience"]').value,
            sort_order: Number(form.querySelector('[name="sort_order"]').value || 0),
            tags: form.querySelector('[name="tags"]').value.trim(),
            description: String(form.querySelector('[name="description"]').value || '').replace(/\r\n?/g, '\n').trim(),
            is_published: Boolean(form.querySelector('[name="is_published"]').checked),
            is_featured: Boolean(form.querySelector('[name="is_featured"]').checked)
        };
        try {
            const response = await fetch('../backend/api/save_podcast_video.php', {
                method: 'POST',
                credentials: 'include',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload)
            });
            const data = await this.safeJson(response, { success: false });
            if (!data.success) {
                throw new Error(data.message || 'Unable to save podcast video.');
            }
            this.showNotification(data.message || 'Podcast video saved successfully.', 'success');
            close();
            await this.loadPodcastSettings();
        } catch (error) {
            this.showNotification(error.message || 'Unable to save podcast video.', 'error');
        }
    });
};

AdminDashboard.prototype.deletePodcastVideo = async function (podcastId) {
    try {
        const response = await fetch('../backend/api/delete_podcast_video.php', {
            method: 'POST',
            credentials: 'include',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ podcast_id: Number(podcastId) })
        });
        const data = await this.safeJson(response, { success: false });
        if (!data.success) {
            throw new Error(data.message || 'Unable to delete podcast video.');
        }
        this.showNotification(data.message || 'Podcast video deleted successfully.', 'success');
        await this.loadPodcastSettings();
    } catch (error) {
        this.showNotification(error.message || 'Unable to delete podcast video.', 'error');
    }
};

AdminDashboard.prototype.escapeAttribute = AdminDashboard.prototype.escapeAttribute || function (value) {
    return this.escapeHtml ? this.escapeHtml(value) : String(value ?? '');
};

AdminDashboard.prototype.formatAdminDateTime = AdminDashboard.prototype.formatAdminDateTime || function (timestamp) {
    if (!timestamp) return 'N/A';
    if (window.AppSettingsManager?.formatDateTime) {
        return window.AppSettingsManager.formatDateTime(timestamp, { includeSeconds: false });
    }

    const date = new Date(timestamp);
    if (Number.isNaN(date.getTime())) {
        return String(timestamp);
    }

    return `${date.toLocaleDateString()} ${date.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })}`;
};


AdminDashboard.prototype.getAdminInsightSectionMap = function () {
    return {
        'storage-overview': 'storage-overview',
        'document-storage': 'document-storage',
        'storage-cleanup': 'storage-cleanup',
        'workflow-logs': 'workflow-logs',
        'task-logs': 'task-logs',
        'system-logs': 'system-logs',
        'analysis-reporting': 'analysis-reporting'
    };
};

AdminDashboard.prototype.fetchAdminInsights = async function (section) {
    const insightSection = this.getAdminInsightSectionMap()[section] || section;
    const response = await fetch(`../backend/api/get_admin_settings_insights.php?section=${encodeURIComponent(insightSection)}`, {
        credentials: 'include'
    });
    const data = await this.safeJson(response, { success: false });
    if (!data.success) {
        throw new Error(data.message || 'Unable to load administrative insights.');
    }
    return data.payload || {};
};

AdminDashboard.prototype.renderInsightSummaryCards = function (cards = [], extraClass = '') {
    if (!Array.isArray(cards) || !cards.length) {
        return `<div class="settings-empty-state"><p>No analytical summary is available yet.</p></div>`;
    }
    return `<div class="admin-insight-summary ${extraClass}">${cards.map((card) => `
        <article class="admin-insight-card">
            <span>${this.escapeHtml(card.label || 'Metric')}</span>
            <strong>${this.escapeHtml(String(card.value ?? '0'))}</strong>
            <small>${this.escapeHtml(card.helper || '')}</small>
        </article>
    `).join('')}</div>`;
};

AdminDashboard.prototype.renderInsightBars = function (items = [], valueKey = 'value', labelKey = 'label', metaFormatter = null) {
    if (!Array.isArray(items) || !items.length) {
        return `<div class="settings-empty-state"><p>No breakdown data is available yet.</p></div>`;
    }
    const max = Math.max(...items.map((item) => Number(item[valueKey] ?? item.count ?? 0)), 1);
    return `<div class="admin-insight-bars">${items.map((item) => {
        const numericValue = Number(item[valueKey] ?? item.count ?? 0);
        const width = Math.max(8, Math.round((numericValue / max) * 100));
        const meta = typeof metaFormatter === 'function'
            ? metaFormatter(item)
            : this.escapeHtml(String(item.display || item.size || item.count || numericValue));
        return `
            <div class="admin-insight-bar-row">
                <div class="admin-insight-bar-head">
                    <strong>${this.escapeHtml(item[labelKey] || 'Item')}</strong>
                    <span>${meta}</span>
                </div>
                <div class="admin-insight-bar-track"><div class="admin-insight-bar-fill" style="width:${width}%"></div></div>
            </div>
        `;
    }).join('')}</div>`;
};

AdminDashboard.prototype.renderInsightNotes = function (notes = []) {
    if (!Array.isArray(notes) || !notes.length) return '';
    return `<div class="admin-insight-notes"><h4>Operational Notes</h4><ul>${notes.map((note) => `<li>${this.escapeHtml(note)}</li>`).join('')}</ul></div>`;
};

AdminDashboard.prototype.loadStorageOverviewContent = async function () {
    const insights = await this.fetchAdminInsights('storage-overview');
    return `
        <div class="settings-content advanced-settings-page">
            <div class="settings-header">
                <div>
                    <h2 class="section-title">Storage Overview</h2>
                    <p class="section-subtitle">Monitor platform storage consumption, backup posture, and operational thresholds for communication and pension records.</p>
                </div>
            </div>
            <div class="settings-card">
                <div class="section-card-header">
                    <div>
                        <h3>Managed Storage Footprint</h3>
                        <p>Use these controls to align storage thresholds with actual growth in messages, attachments, and indexed documents.</p>
                    </div>
                    <span class="settings-status-badge" id="storageOverviewStatus">Up to date</span>
                </div>
                ${this.renderInsightSummaryCards(insights.summary || [])}
                <div class="settings-split-grid storage-overview-grid">
                    <form id="storageOverviewSettingsForm" class="settings-card form-card">
                        <div class="settings-group-header">
                            <h4>Capacity Thresholds</h4>
                            <p>Set the thresholds that should trigger operational review and cleanup activity.</p>
                        </div>
                        <div class="admin-form-grid three-column">
                            <label class="settings-field"><span>Warning Threshold (MB)</span><input type="number" name="storage_warning_threshold_mb" min="256" step="256"></label>
                            <label class="settings-field"><span>Critical Threshold (MB)</span><input type="number" name="storage_critical_threshold_mb" min="512" step="256"></label>
                            <label class="settings-field"><span>Backup Retention (Days)</span><input type="number" name="storage_cleanup_backups_days" min="1" step="1"></label>
                        </div>
                        <div class="settings-group-header compact">
                            <h4>Backup Posture</h4>
                            <p>Define how cleanup and retention should behave when files are nearing limits.</p>
                        </div>
                        <div class="settings-toggle-grid">
                            <div class="settings-toggle">
                                <div><div class="toggle-title">Backup Before Cleanup</div><div class="toggle-subtitle">Require a backup safety net before destructive cleanup actions are executed.</div></div>
                                <label class="switch"><input type="checkbox" name="storage_cleanup_backup_before_delete"><span class="slider"></span></label>
                            </div>
                            <div class="settings-toggle">
                                <div><div class="toggle-title">Dry Run by Default</div><div class="toggle-subtitle">Make storage cleanup start in preview mode before any records are removed.</div></div>
                                <label class="switch"><input type="checkbox" name="storage_cleanup_dry_run_default"><span class="slider"></span></label>
                            </div>
                        </div>
                        <div class="settings-actions stacked-mobile">
                            <button type="button" class="action-btn secondary" id="refreshStorageOverviewBtn">Refresh Insight</button>
                            <button type="button" class="action-btn" id="saveStorageOverviewBtn">Save Storage Overview Settings</button>
                        </div>
                    </form>
                    <div class="settings-card analytics-card">
                        <div class="settings-group-header">
                            <h4>Storage Composition</h4>
                            <p>Highlights the datasets contributing most to managed storage.</p>
                        </div>
                        ${this.renderInsightBars(insights.breakdown || [], 'value', 'label', (item) => this.escapeHtml(`${item.display || item.count || 0}${item.count ? ` - ${item.count} records` : ''}`))}
                    </div>
                </div>
                ${this.renderInsightNotes(insights.insights || [])}
            </div>
        </div>
    `;
};

AdminDashboard.prototype.loadDocumentStorageContent = async function () {
    const insights = await this.fetchAdminInsights('document-storage');
    return `
        <div class="settings-content advanced-settings-page">
            <div class="settings-header">
                <div>
                    <h2 class="section-title">Document Storage</h2>
                    <p class="section-subtitle">Govern how pension, payroll, and workflow documents are captured, classified, previewed, and retained.</p>
                </div>
            </div>
            <div class="settings-card">
                <div class="section-card-header">
                    <div>
                        <h3>Document Governance</h3>
                        <p>These controls affect uploaded pension documents, scanned forms, and registry-linked evidence.</p>
                    </div>
                    <span class="settings-status-badge" id="documentStorageStatus">Up to date</span>
                </div>
                ${this.renderInsightSummaryCards(insights.summary || [])}
                <div class="settings-split-grid document-storage-grid">
                    <form id="documentStorageSettingsForm" class="settings-card form-card">
                        <div class="admin-form-grid three-column">
                            <label class="settings-field"><span>Maximum File Size (MB)</span><input type="number" name="document_max_size_mb" min="1" step="1"></label>
                            <label class="settings-field"><span>Retention Period (Days)</span><input type="number" name="document_retention_days" min="30" step="30"></label>
                            <label class="settings-field"><span>Archive After (Days)</span><input type="number" name="document_archive_after_days" min="30" step="30"></label>
                            <label class="settings-field full-span"><span>Allowed File Types</span><input type="text" name="document_allowed_types" placeholder="pdf,jpg,png,docx,..."><small class="field-help">Comma-separated extensions for permitted document uploads.</small></label>
                            <label class="settings-field full-span"><span>Naming Scheme</span><select name="document_naming_scheme"><option value="regno_doc_type_timestamp">Registration No. + Document Type + Timestamp</option><option value="regno_timestamp">Registration No. + Timestamp</option><option value="doc_type_timestamp">Document Type + Timestamp</option></select></label>
                        </div>
                        <div class="settings-toggle-grid dense">
                            <div class="settings-toggle"><div><div class="toggle-title">Enable Document Storage</div><div class="toggle-subtitle">Allow the platform to capture and serve linked documents.</div></div><label class="switch"><input type="checkbox" name="document_storage_enabled"><span class="slider"></span></label></div>
                            <div class="settings-toggle"><div><div class="toggle-title">Require Classification</div><div class="toggle-subtitle">Force every upload to carry a clear document type classification.</div></div><label class="switch"><input type="checkbox" name="document_classification_required"><span class="slider"></span></label></div>
                            <div class="settings-toggle"><div><div class="toggle-title">Deduplicate Uploads</div><div class="toggle-subtitle">Flag or reject duplicates to reduce storage growth.</div></div><label class="switch"><input type="checkbox" name="document_dedupe_enabled"><span class="slider"></span></label></div>
                            <div class="settings-toggle"><div><div class="toggle-title">Allow Previews</div><div class="toggle-subtitle">Enable in-app preview for common file types.</div></div><label class="switch"><input type="checkbox" name="document_preview_enabled"><span class="slider"></span></label></div>
                            <div class="settings-toggle"><div><div class="toggle-title">Audit Document Access</div><div class="toggle-subtitle">Record document access events to strengthen traceability.</div></div><label class="switch"><input type="checkbox" name="document_access_audit_enabled"><span class="slider"></span></label></div>
                            <div class="settings-toggle"><div><div class="toggle-title">Require Registry Link</div><div class="toggle-subtitle">Prevent uploads that are not tied to a pension registry or workflow record.</div></div><label class="switch"><input type="checkbox" name="document_link_registry_required"><span class="slider"></span></label></div>
                        </div>
                        <div class="settings-actions stacked-mobile">
                            <button type="button" class="action-btn secondary" id="refreshDocumentStorageBtn">Refresh Insight</button>
                            <button type="button" class="action-btn" id="saveDocumentStorageBtn">Save Document Storage Settings</button>
                        </div>
                    </form>
                    <div class="settings-card analytics-card">
                        <div class="settings-group-header">
                            <h4>Document Mix</h4>
                            <p>Track the dominant document groups and identify oversized or orphaned records.</p>
                        </div>
                        ${this.renderInsightBars(insights.breakdown || [], 'count', 'label', (item) => this.escapeHtml(`${item.count || 0} files - ${item.size || '0 B'}`))}
                        <div class="mini-table-wrap">
                            <table class="mini-table">
                                <thead>
                                    <tr>
                                        <th>Largest Files</th>
                                        <th>Type</th>
                                        <th>Size</th>
                                        <th>Uploaded</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    ${Array.isArray(insights.largest) && insights.largest.length ? insights.largest.map((row) => `
                                        <tr>
                                            <td>${this.escapeHtml(row.file_name || row.regNo || 'Document')}</td>
                                            <td>${this.escapeHtml(row.doc_type || 'Unclassified')}</td>
                                            <td>${this.escapeHtml(this.formatBytes(row.file_size || 0))}</td>
                                            <td>${this.escapeHtml(this.formatAdminDateTime(row.uploaded_at))}</td>
                                        </tr>
                                    `).join('') : '<tr><td colspan="4">No document uploads are available yet.</td></tr>'}
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
                ${this.renderInsightNotes(insights.insights || [])}
            </div>
        </div>
    `;
};

AdminDashboard.prototype.loadStorageCleanupContent = async function () {
    const insights = await this.fetchAdminInsights('storage-cleanup');
    return `
        <div class="settings-content advanced-settings-page">
            <div class="settings-header">
                <div>
                    <h2 class="section-title">Storage Cleanup Tools</h2>
                    <p class="section-subtitle">Control retention windows, preview cleanup impact, and keep evidence stores lean without losing traceability.</p>
                </div>
            </div>
            <div class="settings-card">
                <div class="section-card-header">
                    <div>
                        <h3>Cleanup Policy</h3>
                        <p>Set retention windows for operational data stores, then use the cleanup actions as governed maintenance routines.</p>
                    </div>
                    <span class="settings-status-badge" id="storageCleanupStatus">Up to date</span>
                </div>
                ${this.renderInsightSummaryCards(insights.summary || [])}
                <div class="settings-split-grid cleanup-settings-grid">
                    <form id="storageCleanupSettingsForm" class="settings-card form-card">
                        <div class="admin-form-grid three-column">
                            <label class="settings-field"><span>Inactive Sessions (Days)</span><input type="number" name="storage_cleanup_sessions_days" min="1" step="1"></label>
                            <label class="settings-field"><span>Notification Queue (Days)</span><input type="number" name="storage_cleanup_notification_days" min="1" step="1"></label>
                            <label class="settings-field"><span>Import History (Days)</span><input type="number" name="storage_cleanup_imports_days" min="1" step="1"></label>
                            <label class="settings-field"><span>Export History (Days)</span><input type="number" name="storage_cleanup_exports_days" min="1" step="1"></label>
                            <label class="settings-field"><span>Backup History (Days)</span><input type="number" name="storage_cleanup_backups_days" min="1" step="1"></label>
                            <label class="settings-field"><span>Orphan Documents (Days)</span><input type="number" name="storage_cleanup_orphan_documents_days" min="1" step="1"></label>
                        </div>
                        <div class="settings-toggle-grid">
                            <div class="settings-toggle"><div><div class="toggle-title">Backup Before Delete</div><div class="toggle-subtitle">Keep cleanup destructive operations gated by a backup-first policy.</div></div><label class="switch"><input type="checkbox" name="storage_cleanup_backup_before_delete"><span class="slider"></span></label></div>
                            <div class="settings-toggle"><div><div class="toggle-title">Dry Run as Default</div><div class="toggle-subtitle">Force preview mode until the operator intentionally runs the cleanup.</div></div><label class="switch"><input type="checkbox" name="storage_cleanup_dry_run_default"><span class="slider"></span></label></div>
                        </div>
                        <div class="settings-actions stacked-mobile">
                            <button type="button" class="action-btn secondary" id="refreshStorageCleanupBtn">Refresh Insight</button>
                            <button type="button" class="action-btn" id="saveStorageCleanupBtn">Save Cleanup Settings</button>
                        </div>
                    </form>
                    <div class="settings-card analytics-card">
                        <div class="settings-group-header"><h4>Cleanup Candidates</h4><p>Review the operational footprint before running cleanup routines.</p></div>
                        ${this.renderInsightBars(insights.cleanup_matrix || [], 'count', 'label', (item) => this.escapeHtml(`${item.count || 0} candidates`))}
                        <div class="cleanup-quick-actions">
                            <button type="button" class="action-btn secondary" data-cleanup-action="purge_inactive_sessions">Preview Sessions Cleanup</button>
                            <button type="button" class="action-btn secondary" data-cleanup-action="purge_export_history">Preview Exports Cleanup</button>
                            <button type="button" class="action-btn secondary" data-cleanup-action="purge_orphan_documents">Preview Orphan Documents</button>
                        </div>
                    </div>
                </div>
                ${this.renderInsightNotes(insights.insights || [])}
            </div>
        </div>
    `;
};

AdminDashboard.prototype.loadWorkflowLogsContent = async function () {
    const insights = await this.fetchAdminInsights('workflow-logs');
    return `
        <div class="settings-content advanced-settings-page">
            <div class="settings-card">
                <div class="section-card-header">
                    <div>
                        <h2 class="section-title">Workflow Reports</h2>
                        <p class="section-subtitle">Govern reporting depth for application movement, verification quality, and export readiness.</p>
                    </div>
                    <span class="settings-status-badge" id="workflowLogsStatus">Up to date</span>
                </div>
                ${this.renderInsightSummaryCards(insights.summary || [])}
                <div class="settings-split-grid analytics-settings-grid">
                    <form id="workflowLogsSettingsForm" class="settings-card form-card">
                        <div class="admin-form-grid three-column">
                            <label class="settings-field"><span>Retention Period (Days)</span><input type="number" name="workflow_logs_retention_days" min="30" step="30"></label>
                            <label class="settings-field"><span>Submitted Application Escalation Window (Days)</span><input type="number" name="staff_due_verification_escalation_days" min="7" max="365" step="1"></label>
                        </div>
                        <div class="settings-toggle-grid dense">
                            <div class="settings-toggle"><div><div class="toggle-title">Enable Workflow Reports</div><div class="toggle-subtitle">Keep workflow report generation active across the application pipeline.</div></div><label class="switch"><input type="checkbox" name="workflow_logs_enabled"><span class="slider"></span></label></div>
                            <div class="settings-toggle"><div><div class="toggle-title">Include Comments</div><div class="toggle-subtitle">Preserve comments and review remarks in workflow reports.</div></div><label class="switch"><input type="checkbox" name="workflow_logs_include_comments"><span class="slider"></span></label></div>
                            <div class="settings-toggle"><div><div class="toggle-title">Capture Assignment Changes</div><div class="toggle-subtitle">Store assignment routing details in workflow reporting outputs.</div></div><label class="switch"><input type="checkbox" name="workflow_logs_capture_assignment"><span class="slider"></span></label></div>
                            <div class="settings-toggle"><div><div class="toggle-title">Allow Export</div><div class="toggle-subtitle">Permit workflow report exports for supervisory reviews.</div></div><label class="switch"><input type="checkbox" name="workflow_logs_export_enabled"><span class="slider"></span></label></div>
                        </div>
                        <div class="settings-actions stacked-mobile"><button type="button" class="action-btn secondary" id="refreshWorkflowLogsBtn">Refresh Insight</button><button type="button" class="action-btn" id="saveWorkflowLogsBtn">Save Workflow Report Settings</button></div>
                    </form>
                    <div class="settings-card analytics-card">${this.renderInsightNotes(insights.insights || [])}</div>
                </div>
            </div>
        </div>
    `;
};

AdminDashboard.prototype.loadTaskLogsContent = async function () {
    const insights = await this.fetchAdminInsights('task-logs');
    return `
        <div class="settings-content advanced-settings-page">
            <div class="settings-card">
                <div class="section-card-header">
                    <div>
                        <h2 class="section-title">Task Delegation</h2>
                        <p class="section-subtitle">Control how delegation evidence is captured, retained, escalated, and exported for supervision.</p>
                    </div>
                    <span class="settings-status-badge" id="taskLogsStatus">Up to date</span>
                </div>
                ${this.renderInsightSummaryCards(insights.summary || [])}
                <div class="settings-split-grid analytics-settings-grid">
                    <form id="taskLogsSettingsForm" class="settings-card form-card">
                        <div class="admin-form-grid three-column"><label class="settings-field"><span>Retention Period (Days)</span><input type="number" name="task_delegation_retention_days" min="30" step="30"></label></div>
                        <div class="settings-toggle-grid dense">
                            <div class="settings-toggle"><div><div class="toggle-title">Enable Delegation Logs</div><div class="toggle-subtitle">Capture task handoff and delegation decisions.</div></div><label class="switch"><input type="checkbox" name="task_delegation_logs_enabled"><span class="slider"></span></label></div>
                            <div class="settings-toggle"><div><div class="toggle-title">Require Delegation Reason</div><div class="toggle-subtitle">Force rationale for every task handoff or reassignment.</div></div><label class="switch"><input type="checkbox" name="task_delegation_require_reason"><span class="slider"></span></label></div>
                            <div class="settings-toggle"><div><div class="toggle-title">Enable Escalation Signals</div><div class="toggle-subtitle">Support performance review when delegated tasks stall or bounce.</div></div><label class="switch"><input type="checkbox" name="task_delegation_escalation_enabled"><span class="slider"></span></label></div>
                            <div class="settings-toggle"><div><div class="toggle-title">Allow Delegation Export</div><div class="toggle-subtitle">Permit export of delegation logs and statistics.</div></div><label class="switch"><input type="checkbox" name="task_delegation_export_enabled"><span class="slider"></span></label></div>
                        </div>
                        <div class="settings-actions stacked-mobile"><button type="button" class="action-btn secondary" id="refreshTaskLogsBtn">Refresh Insight</button><button type="button" class="action-btn" id="saveTaskLogsBtn">Save Task Delegation Settings</button></div>
                    </form>
                    <div class="settings-card analytics-card">
                        <div class="settings-group-header"><h4>Delegation by Role</h4><p>Use this distribution to align workload and identify overused routing paths.</p></div>
                        ${this.renderInsightBars(insights.breakdown || [], 'count', 'label', (item) => this.escapeHtml(`${item.count || 0} tasks`))}
                        ${this.renderInsightNotes(insights.insights || [])}
                    </div>
                </div>
            </div>
        </div>
    `;
};

AdminDashboard.prototype.loadSystemLogsContent = async function () {
    const insights = await this.fetchAdminInsights('system-logs');
    return `
        <div class="settings-content advanced-settings-page">
            <div class="settings-card">
                <div class="section-card-header">
                    <div>
                        <h2 class="section-title">System Logs</h2>
                        <p class="section-subtitle">Define the event streams, severity threshold, and retention window used to support troubleshooting and security monitoring.</p>
                    </div>
                    <span class="settings-status-badge" id="systemLogsStatus">Up to date</span>
                </div>
                ${this.renderInsightSummaryCards(insights.summary || [])}
                <div class="settings-split-grid analytics-settings-grid">
                    <form id="systemLogsSettingsForm" class="settings-card form-card">
                        <div class="admin-form-grid three-column">
                            <label class="settings-field"><span>Retention Period (Days)</span><input type="number" name="system_logs_retention_days" min="30" step="30"></label>
                            <label class="settings-field"><span>Minimum Log Level</span><select name="system_logs_min_level"><option value="debug">Debug</option><option value="info">Info</option><option value="notice">Notice</option><option value="warning">Warning</option><option value="error">Error</option></select></label>
                        </div>
                        <div class="settings-toggle-grid dense">
                            <div class="settings-toggle"><div><div class="toggle-title">Enable System Logs</div><div class="toggle-subtitle">Maintain the structured system event stream.</div></div><label class="switch"><input type="checkbox" name="system_logs_enabled"><span class="slider"></span></label></div>
                            <div class="settings-toggle"><div><div class="toggle-title">Capture Warnings</div><div class="toggle-subtitle">Include warning-level events for early issue detection.</div></div><label class="switch"><input type="checkbox" name="system_logs_capture_warnings"><span class="slider"></span></label></div>
                            <div class="settings-toggle"><div><div class="toggle-title">Capture Errors</div><div class="toggle-subtitle">Retain error and critical events for operational response.</div></div><label class="switch"><input type="checkbox" name="system_logs_capture_errors"><span class="slider"></span></label></div>
                            <div class="settings-toggle"><div><div class="toggle-title">Capture Security Events</div><div class="toggle-subtitle">Store security-relevant activity for incident reconstruction.</div></div><label class="switch"><input type="checkbox" name="system_logs_capture_security_events"><span class="slider"></span></label></div>
                            <div class="settings-toggle"><div><div class="toggle-title">Capture Integrations</div><div class="toggle-subtitle">Track backup, import, export, and integration events inside the log stream.</div></div><label class="switch"><input type="checkbox" name="system_logs_capture_integrations"><span class="slider"></span></label></div>
                        </div>
                        <div class="settings-actions stacked-mobile"><button type="button" class="action-btn secondary" id="refreshSystemLogsBtn">Refresh Insight</button><button type="button" class="action-btn" id="saveSystemLogsBtn">Save System Log Settings</button></div>
                    </form>
                    <div class="settings-card analytics-card">
                        <div class="settings-group-header"><h4>Categories Captured</h4><p>See which operational categories generate the most system events.</p></div>
                        ${this.renderInsightBars(insights.breakdown || [], 'count', 'label', (item) => this.escapeHtml(`${item.count || 0} events`))}
                        ${this.renderInsightNotes(insights.insights || [])}
                    </div>
                </div>
            </div>
        </div>
    `;
};

AdminDashboard.prototype.loadAnalysisReportingContent = async function () {
    const insights = await this.fetchAdminInsights('analysis-reporting');
    return `
        <div class="settings-content advanced-settings-page">
            <div class="settings-card">
                <div class="section-card-header">
                    <div>
                        <h2 class="section-title">Analysis & Reporting</h2>
                        <p class="section-subtitle">Govern refresh cadence, predictive summaries, digest delivery, and export readiness for analytical products across the platform.</p>
                    </div>
                    <span class="settings-status-badge" id="analysisReportingStatus">Up to date</span>
                </div>
                ${this.renderInsightSummaryCards(insights.summary || [])}
                <div class="settings-split-grid analytics-settings-grid">
                    <form id="analysisReportingSettingsForm" class="settings-card form-card">
                        <div class="admin-form-grid three-column">
                            <label class="settings-field"><span>Refresh Interval (Minutes)</span><input type="number" name="analytics_refresh_interval_minutes" min="5" step="5"></label>
                            <label class="settings-field"><span>Snapshot Retention (Days)</span><input type="number" name="analytics_snapshot_retention_days" min="30" step="30"></label>
                            <label class="settings-field"><span>Digest Frequency</span><select name="analytics_digest_frequency"><option value="daily">Daily</option><option value="weekly">Weekly</option><option value="monthly">Monthly</option></select></label>
                            <label class="settings-field"><span>Digest Delivery Time</span><input type="time" name="analytics_digest_time"></label>
                            <label class="settings-field full-span"><span>Digest Recipient</span><input type="email" name="analytics_digest_recipient" placeholder="analytics@organisation.example"></label>
                        </div>
                        <div class="settings-toggle-grid dense">
                            <div class="settings-toggle"><div><div class="toggle-title">Enable Dashboard Snapshots</div><div class="toggle-subtitle">Persist analytical snapshots for trend comparison and reporting continuity.</div></div><label class="switch"><input type="checkbox" name="analytics_dashboard_snapshots_enabled"><span class="slider"></span></label></div>
                            <div class="settings-toggle"><div><div class="toggle-title">Allow Analytics Export</div><div class="toggle-subtitle">Permit export of analytical summaries and planning packs.</div></div><label class="switch"><input type="checkbox" name="analytics_export_enabled"><span class="slider"></span></label></div>
                            <div class="settings-toggle"><div><div class="toggle-title">Auto Digest</div><div class="toggle-subtitle">Send scheduled analytical summaries to decision makers automatically.</div></div><label class="switch"><input type="checkbox" name="analytics_auto_digest_enabled"><span class="slider"></span></label></div>
                            <div class="settings-toggle"><div><div class="toggle-title">Show Predictive Cards</div><div class="toggle-subtitle">Surface forecast and risk cards alongside current state reporting.</div></div><label class="switch"><input type="checkbox" name="analytics_show_predictive_cards"><span class="slider"></span></label></div>
                            <div class="settings-toggle"><div><div class="toggle-title">Include Financial Forecasts</div><div class="toggle-subtitle">Blend budgeting, claims and payroll forecasting into analytical outputs.</div></div><label class="switch"><input type="checkbox" name="analytics_include_financial_forecasts"><span class="slider"></span></label></div>
                            <div class="settings-toggle"><div><div class="toggle-title">Include Operational KPIs</div><div class="toggle-subtitle">Expose workload, turnaround and compliance KPIs in reports.</div></div><label class="switch"><input type="checkbox" name="analytics_include_operational_kpis"><span class="slider"></span></label></div>
                            <div class="settings-toggle"><div><div class="toggle-title">Anomaly Detection</div><div class="toggle-subtitle">Enable risk cues for unusual workflow, payroll, or compliance patterns.</div></div><label class="switch"><input type="checkbox" name="analytics_anomaly_detection_enabled"><span class="slider"></span></label></div>
                        </div>
                        <div class="settings-actions stacked-mobile"><button type="button" class="action-btn secondary" id="refreshAnalysisReportingBtn">Refresh Insight</button><button type="button" class="action-btn" id="saveAnalysisReportingBtn">Save Analysis Settings</button></div>
                    </form>
                    <div class="settings-card analytics-card">
                        <div class="settings-group-header"><h4>Analytical Coverage</h4><p>Shows the operational datasets currently contributing to analytical products.</p></div>
                        ${this.renderInsightBars(insights.breakdown || [], 'count', 'label', (item) => this.escapeHtml(`${item.count || 0} rows`))}
                        ${this.renderInsightNotes(insights.insights || [])}
                    </div>
                </div>
                <section class="settings-card runtime-settings-card">
                    <div class="section-card-header">
                        <div>
                            <h3>Analytics Digest Operations</h3>
                            <p>Preview decision-support summaries, review recent digest runs, and queue analytical reporting for delivery.</p>
                        </div>
                        <div class="runtime-toolbar">
                            <button type="button" class="action-btn secondary" id="refreshAnalyticsDigestRuntimeBtn">Refresh Runtime</button>
                            <button type="button" class="action-btn secondary" id="previewAnalyticsDigestBtn">Preview Digest</button>
                            <button type="button" class="action-btn" id="queueAnalyticsDigestBtn">Queue Digest Now</button>
                        </div>
                    </div>
                    ${this.renderInsightSummaryCards((insights.digest_summary || []).map((item) => ({
                        label: item.label || 'Metric',
                        value: item.format === 'currency'
                            ? `UGX ${Number(item.value || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`
                            : String(item.value ?? 0),
                        helper: item.helper || ''
                    })), 'compact-summary')}
                    <div class="settings-split-grid runtime-section-grid">
                        ${this.renderDefinitionListCard(
                            'Analytics Digest Status',
                            'These values reflect the active analytical reporting schedule and recipient configuration.',
                            [
                                { label: 'Auto Digest', value: (insights.runtime?.enabled ? 'Enabled' : 'Disabled') },
                                { label: 'Frequency', value: String(insights.runtime?.frequency || 'weekly').replace(/(^|\s|-)\w/g, (match) => match.toUpperCase()) },
                                { label: 'Delivery Time', value: insights.runtime?.delivery_time || '08:00' },
                                { label: 'Recipient', value: insights.runtime?.recipient || 'Not configured', title: insights.runtime?.recipient || 'Not configured' }
                            ],
                            'mail-transport-card'
                        )}
                        <div class="settings-card analytics-card">
                            <div class="settings-group-header">
                                <h4>Recent Analytics Digest Runs</h4>
                                <p>Each preview and queued digest is recorded here for reporting governance and delivery traceability.</p>
                            </div>
                            ${this.renderRuntimeTable([
                                { label: 'Run Type', render: (row) => this.escapeHtml(String(row.run_type || 'scheduled').replace(/_/g, ' ')) },
                                { label: 'Frequency', render: (row) => this.escapeHtml(String(row.digest_frequency || insights.runtime?.frequency || 'weekly')) },
                                { label: 'Status', render: (row) => this.escapeHtml(row.status || '') },
                                { label: 'Recipient', render: (row) => this.escapeHtml(row.recipient || 'Not configured') },
                                { label: 'Created', render: (row) => this.escapeHtml(this.formatAdminDateTime(row.created_at)) }
                            ], insights.recent_runs || [], 'No analytics digest activity has been recorded yet.')}
                        </div>
                    </div>
                </section>
            </div>
        </div>
    `;
};

AdminDashboard.prototype.wireSettingsForm = async function ({ formId, refreshBtnId, saveBtnId, scope }) {
    const form = document.getElementById(formId);
    if (!form) return;
    const refresh = async (showNotice = false) => {
        try {
            this.updateSettingsStatus(scope, 'Loading...', 'info');
            const data = await this.fetchAppSettingsBundle(showNotice);
            this.applySettingsToForm(form, data.settings || {});
            this.updateSettingsStatus(scope, 'Up to date', 'success');
            if (showNotice) this.showNotification('Settings refreshed.', 'success');
        } catch (error) {
            this.updateSettingsStatus(scope, 'Load failed', 'error');
            this.showNotification(error.message || 'Unable to load settings.', 'error');
        }
    };
    form.querySelectorAll('input, select, textarea').forEach((field) => {
        field.addEventListener('change', () => this.updateSettingsStatus(scope, 'Unsaved changes', 'info'));
        field.addEventListener('input', () => this.updateSettingsStatus(scope, 'Unsaved changes', 'info'));
    });
    document.getElementById(refreshBtnId)?.addEventListener('click', () => refresh(true));
    document.getElementById(saveBtnId)?.addEventListener('click', async () => {
        try {
            this.updateSettingsStatus(scope, 'Saving...', 'info');
            const payload = Object.fromEntries(new FormData(form).entries());
            form.querySelectorAll('input[type="checkbox"]').forEach((checkbox) => {
                payload[checkbox.name] = checkbox.checked;
            });
            const response = await this.performSensitiveAdminRequest('../backend/api/update_app_settings.php', { method: 'POST', credentials: 'include', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(payload) }, 'save settings');
            const data = response.__adminPayloadAttached || await this.safeJson(response, { success: false });
            if (!data.success) throw new Error(data.message || 'Unable to save settings.');
            this.applySettingsToForm(form, data.settings || {});
            this.primeAppSettingsCache(data.settings || null);
            this.updateSettingsStatus(scope, 'Saved', 'success');
            this.showNotification('Settings saved.', 'success');
            if ((this.currentSection || '') === 'storage-overview' || (this.currentSection || '') === 'document-storage' || (this.currentSection || '') === 'storage-cleanup' || (this.currentSection || '') === 'workflow-logs' || (this.currentSection || '') === 'task-logs' || (this.currentSection || '') === 'system-logs' || (this.currentSection || '') === 'analysis-reporting') {
                await this.loadSectionContent(this.currentSection);
            }
        } catch (error) {
            this.updateSettingsStatus(scope, 'Save failed', 'error');
            this.showNotification(error.message || 'Unable to save settings.', 'error');
        }
    });
    await refresh(false);
};

AdminDashboard.prototype.runManagedCleanupAction = async function (action, dryRun = true) {
    try {
        const response = await fetch('../backend/api/run_data_cleanup.php', {
            method: 'POST', credentials: 'include', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ action, dry_run: dryRun })
        });
        const data = await this.safeJson(response, { success: false });
        if (!data.success) throw new Error(data.message || 'Unable to run cleanup action.');
        this.showNotification(data.message || 'Cleanup action completed.', 'success');
        if ((this.currentSection || '') === 'storage-cleanup') await this.loadSectionContent('storage-cleanup');
    } catch (error) {
        this.showNotification(error.message || 'Unable to run cleanup action.', 'error');
    }
};

AdminDashboard.prototype.initializeStorageOverviewSettings = async function () {
    if (!document.getElementById('storageOverviewSettingsForm')) return;
    this.wireSettingsForm({ formId: 'storageOverviewSettingsForm', refreshBtnId: 'refreshStorageOverviewBtn', saveBtnId: 'saveStorageOverviewBtn', scope: 'storageOverview' });
};

AdminDashboard.prototype.initializeDocumentStorageSettings = async function () {
    if (!document.getElementById('documentStorageSettingsForm')) return;
    this.wireSettingsForm({ formId: 'documentStorageSettingsForm', refreshBtnId: 'refreshDocumentStorageBtn', saveBtnId: 'saveDocumentStorageBtn', scope: 'documentStorage' });
};

AdminDashboard.prototype.initializeStorageCleanupSettings = async function () {
    if (!document.getElementById('storageCleanupSettingsForm')) return;
    this.wireSettingsForm({ formId: 'storageCleanupSettingsForm', refreshBtnId: 'refreshStorageCleanupBtn', saveBtnId: 'saveStorageCleanupBtn', scope: 'storageCleanup' });
    document.querySelectorAll('[data-cleanup-action]').forEach((button) => {
        button.addEventListener('click', async () => {
            const action = button.dataset.cleanupAction;
            const dryRun = document.querySelector('#storageCleanupSettingsForm [name="storage_cleanup_dry_run_default"]')?.checked !== false;
            await this.runManagedCleanupAction(action, dryRun);
        });
    });
};

AdminDashboard.prototype.initializeWorkflowLogsSettings = async function () {
    if (!document.getElementById('workflowLogsSettingsForm')) return;
    this.wireSettingsForm({ formId: 'workflowLogsSettingsForm', refreshBtnId: 'refreshWorkflowLogsBtn', saveBtnId: 'saveWorkflowLogsBtn', scope: 'workflowLogs' });
};

AdminDashboard.prototype.initializeTaskLogsSettings = async function () {
    if (!document.getElementById('taskLogsSettingsForm')) return;
    this.wireSettingsForm({ formId: 'taskLogsSettingsForm', refreshBtnId: 'refreshTaskLogsBtn', saveBtnId: 'saveTaskLogsBtn', scope: 'taskLogs' });
};

AdminDashboard.prototype.initializeSystemLogsSettings = async function () {
    if (!document.getElementById('systemLogsSettingsForm')) return;
    this.wireSettingsForm({ formId: 'systemLogsSettingsForm', refreshBtnId: 'refreshSystemLogsBtn', saveBtnId: 'saveSystemLogsBtn', scope: 'systemLogs' });
};

AdminDashboard.prototype.initializeAnalysisReportingSettings = async function () {
    if (!document.getElementById('analysisReportingSettingsForm')) return;
    this.wireSettingsForm({ formId: 'analysisReportingSettingsForm', refreshBtnId: 'refreshAnalysisReportingBtn', saveBtnId: 'saveAnalysisReportingBtn', scope: 'analysisReporting' });
    document.getElementById('refreshAnalyticsDigestRuntimeBtn')?.addEventListener('click', () => this.loadSectionContent('analysis-reporting'));
    document.getElementById('previewAnalyticsDigestBtn')?.addEventListener('click', async () => {
        try {
            const response = await fetch('../backend/api/run_analytics_digest.php', {
                method: 'POST',
                credentials: 'include',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ action: 'preview' })
            });
            const data = await this.safeJson(response, { success: false });
            if (!data.success) {
                throw new Error(data.message || 'Unable to preview the analytics digest.');
            }
            this.openRuntimePreviewModal('Analytics Digest Preview', 'Review the analytical digest content before it is queued for delivery.', this.renderDigestPreview(data.digest || {}));
            await this.loadSectionContent('analysis-reporting');
        } catch (error) {
            this.showNotification(error.message || 'Unable to preview the analytics digest.', 'error');
        }
    });
    document.getElementById('queueAnalyticsDigestBtn')?.addEventListener('click', async () => {
        try {
            const response = await fetch('../backend/api/run_analytics_digest.php', {
                method: 'POST',
                credentials: 'include',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ action: 'queue_now' })
            });
            const data = await this.safeJson(response, { success: false });
            if (!data.success) {
                throw new Error(data.message || 'Unable to queue the analytics digest.');
            }
            this.showNotification(data.message || 'Analytics digest queued.', 'success');
            await this.loadSectionContent('analysis-reporting');
        } catch (error) {
            this.showNotification(error.message || 'Unable to queue the analytics digest.', 'error');
        }
    });
};

const originalInitializeSectionScripts = AdminDashboard.prototype.initializeSectionScripts;
AdminDashboard.prototype.initializeSectionScripts = function (section) {
    originalInitializeSectionScripts.call(this, section);
    switch (section) {
        case 'storage-overview': this.initializeStorageOverviewSettings(); break;
        case 'document-storage': this.initializeDocumentStorageSettings(); break;
        case 'storage-cleanup': this.initializeStorageCleanupSettings(); break;
        case 'workflow-logs': this.initializeWorkflowLogsSettings(); break;
        case 'task-logs': this.initializeTaskLogsSettings(); break;
        case 'system-logs': this.initializeSystemLogsSettings(); break;
        case 'analysis-reporting': this.initializeAnalysisReportingSettings(); break;
    }
};

const originalLoadSectionContent = AdminDashboard.prototype.loadSectionContent;
AdminDashboard.prototype.loadSectionContent = async function (section) {
    const contentBody = document.getElementById('contentBody');
    const key = String(section || '').trim().toLowerCase();
    const loaders = {
        'storage-overview': ['Storage Overview', () => this.loadStorageOverviewContent()],
        'storage-cleanup': ['Storage Cleanup Tools', () => this.loadStorageCleanupContent()],
        'workflow-logs': ['Workflow Reports', () => this.loadWorkflowLogsContent()],
        'task-logs': ['Task Delegation', () => this.loadTaskLogsContent()],
        'system-logs': ['System Logs', () => this.loadSystemLogsContent()],
        'analysis-reporting': ['Analysis & Reporting', () => this.loadAnalysisReportingContent()]
    };
    if (!loaders[key]) {
        return originalLoadSectionContent.call(this, section);
    }
    contentBody.innerHTML = `<div class="loading-state"><div class="loading-spinner"></div><p>Loading ${loaders[key][0]}...</p></div>`;
    try {
        contentBody.innerHTML = await loaders[key][1]();
        this.initializeSectionScripts(key);
    } catch (error) {
        contentBody.innerHTML = this.loadErrorContent(key, error);
    }
};

const originalUpdateSettingsStatus = AdminDashboard.prototype.updateSettingsStatus;
AdminDashboard.prototype.updateSettingsStatus = function (scope, message, type = 'info') {
    const extraMap = {
        storageOverview: 'storageOverviewStatus',
        documentStorage: 'documentStorageStatus',
        storageCleanup: 'storageCleanupStatus',
        workflowLogs: 'workflowLogsStatus',
        taskLogs: 'taskLogsStatus',
        systemLogs: 'systemLogsStatus',
        analysisReporting: 'analysisReportingStatus'
    };
    const badgeId = extraMap[scope];
    if (badgeId) {
        const badge = document.getElementById(badgeId);
        if (badge) {
            badge.textContent = message;
            badge.dataset.state = type;
        }
    }
    return originalUpdateSettingsStatus.call(this, scope, message, type);
};

const originalUpdateSystemStatus = AdminDashboard.prototype.updateSystemStatus;
AdminDashboard.prototype.formatCompactRelativeTime = function (dateInput) {
    const date = dateInput instanceof Date ? dateInput : new Date(String(dateInput || '').replace(' ', 'T'));
    if (Number.isNaN(date.getTime())) {
        return '';
    }

    const diffMs = Math.max(0, Date.now() - date.getTime());
    const diffMinutes = Math.floor(diffMs / 60000);
    if (diffMinutes < 1) return 'Just Now';
    if (diffMinutes === 1) return '1 min ago';
    if (diffMinutes < 60) return `${diffMinutes} mins ago`;

    const diffHours = Math.floor(diffMinutes / 60);
    if (diffHours === 1) return '1 hr ago';
    if (diffHours < 24) return `${diffHours} hrs ago`;

    const diffDays = Math.floor(diffHours / 24);
    if (diffDays === 1) return '1 day ago';
    if (diffDays < 7) return `${diffDays} days ago`;

    const diffWeeks = Math.floor(diffDays / 7);
    if (diffWeeks === 1) return '1 wk ago';
    if (diffWeeks < 5) return `${diffWeeks} wks ago`;

    const diffMonths = Math.floor(diffDays / 30);
    if (diffMonths <= 1) return '1 mo ago';
    if (diffMonths < 12) return `${diffMonths} mos ago`;

    const diffYears = Math.floor(diffDays / 365);
    if (diffYears <= 1) return '1 yr ago';
    return `${diffYears} yrs ago`;
};

AdminDashboard.prototype.updateSystemStatus = async function () {
    await originalUpdateSystemStatus.call(this);
    const lastBackupTime = document.getElementById('lastBackupTime');
    if (!lastBackupTime) return;
    try {
        const response = await fetch('../backend/api/get_system_status.php', { credentials: 'include' });
        const data = await this.safeJson(response, { success: false });
        let backupRaw = data.lastBackupRaw || '';
        let backupLabel = data.lastBackup || '';

        if ((!data.success || !backupRaw) && typeof this.fetchDataManagementOverview === 'function') {
            try {
                const overview = await this.fetchDataManagementOverview();
                const latestBackup = Array.isArray(overview.backup_runs) ? (overview.backup_runs[0] || null) : null;
                if (latestBackup) {
                    backupRaw = String(latestBackup.backup_time || '');
                    backupLabel = backupRaw || backupLabel;
                }
            } catch (_fallbackError) {
            }
        }

        if (!backupRaw) {
            lastBackupTime.textContent = 'Never';
            return;
        }

        const backupDate = new Date(String(backupRaw).replace(' ', 'T'));
        if (Number.isNaN(backupDate.getTime())) {
            lastBackupTime.textContent = backupLabel || 'Unknown';
            return;
        }
        lastBackupTime.textContent = this.formatCompactRelativeTime(backupDate) || 'Unknown';
    } catch (_error) {
    }
};

AdminDashboard.prototype.appendSettingsRuntimePanel = function (markup, panelHtml) {
    const closeIndex = markup.lastIndexOf('</div>');
    if (closeIndex === -1) {
        return `${markup}${panelHtml}`;
    }
    return `${markup.slice(0, closeIndex)}${panelHtml}${markup.slice(closeIndex)}`;
};

AdminDashboard.prototype.renderRuntimeTable = function (columns = [], rows = [], emptyText = 'No records available.') {
    return `
        <div class="mini-table-wrap">
            <table class="mini-table runtime-mini-table">
                <thead>
                    <tr>${columns.map((column) => `<th>${this.escapeHtml(column.label)}</th>`).join('')}</tr>
                </thead>
                <tbody>
                    ${Array.isArray(rows) && rows.length ? rows.map((row) => `
                        <tr>${columns.map((column) => `<td>${column.render ? column.render(row) : this.escapeHtml(row[column.key] ?? '')}</td>`).join('')}</tr>
                    `).join('') : `<tr><td colspan="${columns.length}">${this.escapeHtml(emptyText)}</td></tr>`}
                </tbody>
            </table>
        </div>
    `;
};

AdminDashboard.prototype.openRuntimePreviewModal = function (title, subtitle, bodyHtml) {
    document.querySelector('.admin-modal-overlay')?.remove();
    const overlay = document.createElement('div');
    overlay.className = 'admin-modal-overlay';
    overlay.innerHTML = `
        <div class="admin-modal cleanup-modal runtime-preview-modal">
            <div class="admin-modal-header">
                <div>
                    <h3>${this.escapeHtml(title)}</h3>
                    <p>${this.escapeHtml(subtitle || '')}</p>
                </div>
                <button type="button" class="admin-modal-close" aria-label="Close">&times;</button>
            </div>
            <div class="admin-modal-body cleanup-modal-body runtime-preview-body">
                ${bodyHtml}
            </div>
            <div class="admin-modal-footer">
                <button type="button" class="action-btn secondary" id="closeRuntimePreviewBtn">Close</button>
            </div>
        </div>
    `;
    const close = () => overlay.remove();
    overlay.querySelector('.admin-modal-close')?.addEventListener('click', close);
    overlay.querySelector('#closeRuntimePreviewBtn')?.addEventListener('click', close);
    overlay.addEventListener('click', (event) => {
        if (event.target === overlay) close();
    });
    document.body.appendChild(overlay);
};

AdminDashboard.prototype.renderDigestPreview = function (digest = {}) {
    const summary = Array.isArray(digest.summary) ? digest.summary : [];
    return `
        <div class="runtime-preview-stack">
            <div class="runtime-preview-hero">
                <strong>${this.escapeHtml(digest.subject || 'Daily Digest Preview')}</strong>
                <span>${this.escapeHtml(digest.generated_at ? this.formatAdminDateTime(digest.generated_at) : 'Ready for preview')}</span>
            </div>
            ${this.renderInsightSummaryCards(summary.map((item) => ({
                label: item.label,
                value: item.value,
                helper: 'Included in the current digest snapshot'
            })), 'compact-summary')}
            <div class="runtime-preview-text">
                <pre>${this.escapeHtml(digest.message || digest.body || 'No digest body is available.')}</pre>
            </div>
        </div>
    `;
};

AdminDashboard.prototype.renderDefinitionListCard = function (title, subtitle, rows = [], extraClass = '') {
    return `
        <div class="settings-card analytics-card ${extraClass}">
            <div class="settings-group-header">
                <h4>${this.escapeHtml(title)}</h4>
                <p>${this.escapeHtml(subtitle || '')}</p>
            </div>
            <div class="settings-definition-list">
                ${rows.map((row) => `
                    <div class="settings-definition-row">
                        <span>${this.escapeHtml(row.label || '')}</span>
                        <strong title="${this.escapeHtml(row.title || row.value || '')}">${this.escapeHtml(row.value || 'Not set')}</strong>
                    </div>
                `).join('')}
            </div>
        </div>
    `;
};

AdminDashboard.prototype.formatNotificationSoundBytes = function (bytes) {
    const value = Number(bytes || 0);
    if (!Number.isFinite(value) || value <= 0) {
        return 'Unknown size';
    }

    const units = ['B', 'KB', 'MB', 'GB'];
    let size = value;
    let unitIndex = 0;
    while (size >= 1024 && unitIndex < units.length - 1) {
        size /= 1024;
        unitIndex += 1;
    }

    return `${size.toFixed(unitIndex === 0 ? 0 : 1)} ${units[unitIndex]}`;
};

AdminDashboard.prototype.syncNotificationSoundRangeValue = function () {
    const input = document.getElementById('notificationSoundVolume');
    const output = document.getElementById('notificationSoundVolumeValue');
    if (!input || !output) return;

    const value = Math.max(0, Math.min(100, Number(input.value || 0)));
    output.value = `${value}%`;
    output.textContent = `${value}%`;
};

AdminDashboard.prototype.syncLiveCallSoundRangeValues = function () {
    [
        ['liveCallIncomingSoundVolume', 'liveCallIncomingSoundVolumeValue'],
        ['liveCallOutgoingSoundVolume', 'liveCallOutgoingSoundVolumeValue'],
        ['liveMessageSoundVolume', 'liveMessageSoundVolumeValue']
    ].forEach(([inputId, outputId]) => {
        const input = document.getElementById(inputId);
        const output = document.getElementById(outputId);
        if (!input || !output) return;
        const value = Math.max(0, Math.min(100, Number(input.value || 0)));
        output.value = `${value}%`;
        output.textContent = `${value}%`;
    });
};

AdminDashboard.prototype.renderNotificationSoundLibrary = function (sounds = [], selectedPath = '') {
    if (!Array.isArray(sounds) || sounds.length === 0) {
        return `
            <div class="notification-sound-empty">
                No notification sounds are available yet. Upload a custom sound or keep using the built-in alert.
            </div>
        `;
    }

    return sounds.map((sound) => {
        const isSelected = (sound.path || '') === selectedPath;
        const badges = [
            sound.is_builtin ? 'Built-in' : 'Custom',
            (sound.extension || '').toUpperCase() || 'AUDIO',
            this.formatNotificationSoundBytes(sound.size_bytes || 0)
        ].filter(Boolean);

        return `
            <div class="notification-sound-item ${isSelected ? 'is-selected' : ''}">
                <div class="notification-sound-item-main">
                    <strong>${this.escapeHtml(sound.name || sound.file_name || 'Notification Sound')}</strong>
                    <div class="notification-sound-meta">
                        ${badges.map((badge) => `<span class="notification-sound-chip">${this.escapeHtml(badge)}</span>`).join('')}
                    </div>
                </div>
                <div class="notification-sound-item-side">
                    ${isSelected ? '<span class="settings-pill active">Selected</span>' : ''}
                    <span>${this.escapeHtml(sound.last_modified || '')}</span>
                </div>
            </div>
        `;
    }).join('');
};

AdminDashboard.prototype.applyNotificationSoundLibrary = function (sounds = [], selectedPath = '', options = {}) {
    const library = Array.isArray(sounds) ? sounds.filter(Boolean) : [];
    this.notificationSoundLibrary = library;

    const fillSelect = (select, preferredPath = '') => {
        if (!select) return '';
        select.innerHTML = library.map((sound) => {
            const suffix = sound.is_builtin ? 'Built-in' : 'Custom';
            return `<option value="${this.escapeHtml(sound.path || '')}">${this.escapeHtml(`${sound.name || sound.file_name || 'Notification Sound'} (${suffix})`)}</option>`;
        }).join('');

        const preferred = String(preferredPath || select.value || '').trim();
        const selectedRecord = library.find((sound) => (sound.path || '') === preferred) || library[0] || null;
        if (selectedRecord) select.value = selectedRecord.path || '';
        return select.value || selectedRecord?.path || '';
    };

    const select = document.getElementById('notificationSoundPicker');
    const activePath = fillSelect(select, selectedPath);
    fillSelect(document.getElementById('liveCallIncomingSoundPicker'), options.incomingCallPath || document.getElementById('liveCallIncomingSoundPicker')?.value || activePath);
    fillSelect(document.getElementById('liveCallOutgoingSoundPicker'), options.outgoingCallPath || document.getElementById('liveCallOutgoingSoundPicker')?.value || activePath);
    fillSelect(document.getElementById('liveMessageSoundPicker'), document.getElementById('liveMessageSoundPicker')?.value || activePath);

    const list = document.getElementById('notificationSoundLibraryList');
    if (list) {
        list.innerHTML = this.renderNotificationSoundLibrary(library, activePath);
    }

    this.refreshNotificationSoundControls();
    this.refreshLiveCallSoundControls();
};

AdminDashboard.prototype.loadNotificationSoundLibrary = async function ({ selectedPath = '', incomingCallPath = '', outgoingCallPath = '', silent = true } = {}) {
    const select = document.getElementById('notificationSoundPicker');
    if (!select) return [];

    try {
        const response = await fetch('../backend/api/get_notification_sound_library.php', {
            credentials: 'include',
            cache: 'no-store'
        });
        const data = await this.safeJson(response, { success: false, sounds: [] });
        if (!data.success) {
            throw new Error(data.message || 'Unable to load notification sounds.');
        }

        const resolvedSelectedPath = String(selectedPath || data.selected_path || '').trim();
        this.applyNotificationSoundLibrary(data.sounds || [], resolvedSelectedPath, { incomingCallPath, outgoingCallPath });
        return Array.isArray(data.sounds) ? data.sounds : [];
    } catch (error) {
        console.error('Load notification sound library error:', error);
        if (!silent) {
            this.showNotification(error.message || 'Unable to load notification sounds.', 'error');
        }
        return [];
    }
};

AdminDashboard.prototype.updateNotificationPermissionStatus = function () {
    const statusEl = document.getElementById('notificationPermissionStatus');
    if (!statusEl) return;

    if (!('Notification' in window)) {
        statusEl.textContent = 'This browser does not support desktop notifications.';
        return;
    }

    const permission = Notification.permission || 'default';
    if (permission === 'granted') {
        statusEl.textContent = 'Browser alerts are enabled for this device/browser.';
    } else if (permission === 'denied') {
        statusEl.textContent = 'Browser alerts are blocked for this device/browser. Re-enable them from the browser site permissions if needed.';
    } else {
        statusEl.textContent = 'Browser alerts are supported, but this device/browser has not granted permission yet.';
    }
};

AdminDashboard.prototype.refreshNotificationSoundControls = function () {
    const select = document.getElementById('notificationSoundPicker');
    const meta = document.getElementById('notificationSoundSelectionMeta');
    const deleteBtn = document.getElementById('deleteNotificationSoundBtn');
    const previewBtn = document.getElementById('previewNotificationSoundBtn');
    const requestPermissionBtn = document.getElementById('requestNotificationPermissionBtn');
    const testBrowserBtn = document.getElementById('testBrowserNotificationBtn');
    const desktopToggle = document.getElementById('notifyBroadcastDesktopEnabled');

    const selectedPath = String(select?.value || '').trim();
    const selectedSound = (this.notificationSoundLibrary || []).find((sound) => (sound.path || '') === selectedPath) || null;

    if (meta) {
        if (selectedSound) {
            const traits = [
                selectedSound.is_builtin ? 'Built-in' : 'Custom',
                (selectedSound.extension || '').toUpperCase() || 'AUDIO',
                this.formatNotificationSoundBytes(selectedSound.size_bytes || 0)
            ];
            meta.textContent = `${selectedSound.name || selectedSound.file_name || 'Notification Sound'} • ${traits.join(' • ')}`;
        } else {
            meta.textContent = 'Choose the sound that should play for new broadcasts.';
        }
    }

    if (deleteBtn) {
        deleteBtn.disabled = !selectedSound || Boolean(selectedSound.is_builtin);
    }

    if (previewBtn) {
        previewBtn.disabled = !selectedSound;
    }

    const notificationsSupported = 'Notification' in window;
    if (requestPermissionBtn) {
        requestPermissionBtn.disabled = !notificationsSupported;
        requestPermissionBtn.textContent = notificationsSupported && Notification.permission === 'granted'
            ? 'Browser Alerts Allowed'
            : 'Allow Browser Alerts';
    }

    if (testBrowserBtn) {
        testBrowserBtn.disabled = !notificationsSupported || Notification.permission !== 'granted';
    }

    if (desktopToggle && !notificationsSupported) {
        desktopToggle.checked = false;
        desktopToggle.disabled = true;
    }
};

AdminDashboard.prototype.refreshLiveCallSoundControls = function () {
    const notificationsSupported = 'Notification' in window;
    const permissionText = document.getElementById('liveCallNotificationPermissionStatus');
    const requestBtn = document.getElementById('requestCallNotificationPermissionBtn');
    const desktopToggle = document.getElementById('liveCallDesktopAlertsEnabled');
    const setMeta = (selectId, metaId, fallback) => {
        const select = document.getElementById(selectId);
        const meta = document.getElementById(metaId);
        const selectedPath = String(select?.value || '').trim();
        const selectedSound = (this.notificationSoundLibrary || []).find((sound) => (sound.path || '') === selectedPath) || null;
        if (meta) {
            meta.textContent = selectedSound
                ? `${selectedSound.name || selectedSound.file_name || 'Call Sound'} - ${(selectedSound.is_builtin ? 'Built-in' : 'Custom')} - ${this.formatNotificationSoundBytes(selectedSound.size_bytes || 0)}`
                : fallback;
        }
    };

    setMeta('liveCallIncomingSoundPicker', 'liveCallIncomingSoundMeta', 'Choose the ringtone used for incoming calls.');
    setMeta('liveCallOutgoingSoundPicker', 'liveCallOutgoingSoundMeta', 'Choose the tone used while a call is ringing.');
    setMeta('liveMessageSoundPicker', 'liveMessageSoundMeta', 'Choose the tone used for new live chat messages.');

    if (!notificationsSupported) {
        if (permissionText) permissionText.textContent = 'This browser does not support call notifications.';
        if (requestBtn) requestBtn.disabled = true;
        if (desktopToggle) {
            desktopToggle.checked = false;
            desktopToggle.disabled = true;
        }
        return;
    }

    if (permissionText) {
        if (Notification.permission === 'granted') {
            permissionText.textContent = 'Browser call alerts are enabled for this device/browser.';
        } else if (Notification.permission === 'denied') {
            permissionText.textContent = 'Browser call alerts are blocked. Re-enable them from browser site permissions.';
        } else {
            permissionText.textContent = 'Browser call alerts are supported, but permission has not been granted yet.';
        }
    }

    if (requestBtn) {
        requestBtn.disabled = false;
        requestBtn.textContent = Notification.permission === 'granted' ? 'Call Alerts Allowed' : 'Allow Call Alerts';
    }
};

AdminDashboard.prototype.setSoundPreviewButtonActive = function (button, isActive) {
    if (!(button instanceof Element)) return;
    button.classList.toggle('is-sound-previewing', Boolean(isActive));
    button.setAttribute('aria-pressed', isActive ? 'true' : 'false');
};

AdminDashboard.prototype.stopActiveSoundPreview = function () {
    if (!this.activeSoundPreview) return;
    const preview = this.activeSoundPreview;
    this.activeSoundPreview = null;
    try {
        preview.audio?.pause();
        if (preview.audio) {
            preview.audio.currentTime = 0;
        }
    } catch (error) {
        console.warn('Unable to stop sound preview:', error);
    }
    if (preview.handleEnded && preview.audio) {
        preview.audio.removeEventListener('ended', preview.handleEnded);
    }
    this.setSoundPreviewButtonActive(preview.button, false);
};

AdminDashboard.prototype.toggleSoundPreview = async function ({ source, button = null, volume = 0.85, repeatCount = 1, key = '' } = {}) {
    const previewKey = String(key || source || '').trim();
    if (!source || !previewKey) return false;

    if (this.activeSoundPreview?.key === previewKey) {
        this.stopActiveSoundPreview();
        return false;
    }

    this.stopActiveSoundPreview();

    const audio = new Audio(source);
    audio.preload = 'auto';
    audio.volume = Math.max(0, Math.min(1, Number(volume) || 0));

    const maxRepeats = Math.max(1, Number(repeatCount) || 1);
    let playCount = 0;
    const handleEnded = async () => {
        if (this.activeSoundPreview?.key !== previewKey) return;
        if (playCount >= maxRepeats) {
            this.stopActiveSoundPreview();
            return;
        }
        try {
            playCount += 1;
            audio.currentTime = 0;
            await audio.play();
        } catch (error) {
            this.stopActiveSoundPreview();
            this.showNotification(error.message || 'Unable to continue the sound preview.', 'error');
        }
    };

    audio.addEventListener('ended', handleEnded);
    this.activeSoundPreview = {
        audio,
        button,
        key: previewKey,
        handleEnded
    };
    this.setSoundPreviewButtonActive(button, true);

    try {
        playCount += 1;
        audio.currentTime = 0;
        await audio.play();
        return true;
    } catch (error) {
        this.stopActiveSoundPreview();
        throw error;
    }
};

AdminDashboard.prototype.previewLiveCallSound = async function (direction = 'incoming', button = null) {
    const prefix = direction === 'outgoing' ? 'Outgoing' : 'Incoming';
    const select = document.getElementById(`liveCall${prefix}SoundPicker`);
    const volumeInput = document.getElementById(`liveCall${prefix}SoundVolume`);
    const repeatInput = document.getElementById(`liveCall${prefix}RepeatCount`);
    const selectedPath = String(select?.value || '').trim();
    if (!selectedPath) {
        this.showNotification(`Choose the ${direction} call sound first.`, 'info');
        return;
    }

    const volume = Math.max(0, Math.min(100, Number(volumeInput?.value || 85))) / 100;
    const repeatCount = Math.max(1, Math.min(3, Number(repeatInput?.value || 1) || 1));
    try {
        const started = await this.toggleSoundPreview({
            source: new URL(selectedPath, window.location.href).href,
            button,
            volume,
            repeatCount,
            key: `live-call:${direction}:${selectedPath}`
        });
        if (started) {
            this.showNotification(`Playing ${direction} call sound preview.`, 'info');
        }
    } catch (error) {
        this.showNotification(error.message || `Unable to preview ${direction} call sound.`, 'error');
    }
};

AdminDashboard.prototype.previewLiveMessageSound = async function (button = null) {
    const select = document.getElementById('liveMessageSoundPicker');
    const volumeInput = document.getElementById('liveMessageSoundVolume');
    const repeatInput = document.getElementById('liveMessageRepeatCount');
    const selectedPath = String(select?.value || '').trim();
    if (!selectedPath) {
        this.showNotification('Choose the live chat message sound first.', 'info');
        return;
    }

    const volume = Math.max(0, Math.min(100, Number(volumeInput?.value || 70))) / 100;
    const repeatCount = Math.max(1, Math.min(3, Number(repeatInput?.value || 1) || 1));
    try {
        const started = await this.toggleSoundPreview({
            source: new URL(selectedPath, window.location.href).href,
            button,
            volume,
            repeatCount,
            key: `live-message:${selectedPath}`
        });
        if (started) {
            this.showNotification('Playing live chat message sound preview.', 'info');
        }
    } catch (error) {
        this.showNotification(error.message || 'Unable to preview live chat message sound.', 'error');
    }
};

AdminDashboard.prototype.previewNotificationSound = async function (button = null) {
    const select = document.getElementById('notificationSoundPicker');
    const volumeInput = document.getElementById('notificationSoundVolume');
    const repeatInput = document.getElementById('notificationSoundRepeatCount');

    const selectedPath = String(select?.value || '').trim();
    if (!selectedPath) {
        this.showNotification('Choose a notification sound first.', 'info');
        return;
    }

    const volume = Math.max(0, Math.min(100, Number(volumeInput?.value || 85))) / 100;
    const repeatCount = Math.max(1, Math.min(5, Number(repeatInput?.value || 1)));

    try {
        const started = await this.toggleSoundPreview({
            source: new URL(selectedPath, window.location.href).href,
            button,
            volume,
            repeatCount,
            key: `notification:${selectedPath}`
        });
        if (started) {
            this.showNotification('Playing the selected notification sound preview.', 'info');
        }
    } catch (error) {
        this.showNotification(error.message || 'Unable to preview the selected sound.', 'error');
    }
};

AdminDashboard.prototype.requestBrowserNotificationPermission = async function () {
    if (!('Notification' in window)) {
        this.showNotification('This browser does not support desktop notifications.', 'info');
        return;
    }

    try {
        const permission = await Notification.requestPermission();
        this.updateNotificationPermissionStatus();
        this.refreshNotificationSoundControls();

        if (permission === 'granted') {
            this.showNotification('Browser alerts enabled for this device.', 'success');
        } else if (permission === 'denied') {
            this.showNotification('Browser alerts were blocked for this device.', 'warning');
        } else {
            this.showNotification('Browser alert permission was dismissed.', 'info');
        }
    } catch (error) {
        this.showNotification(error.message || 'Unable to request browser notification permission.', 'error');
    }
};

AdminDashboard.prototype.testBrowserNotification = async function () {
    if (!('Notification' in window)) {
        this.showNotification('This browser does not support desktop notifications.', 'info');
        return;
    }

    if (Notification.permission !== 'granted') {
        await this.requestBrowserNotificationPermission();
        if (Notification.permission !== 'granted') {
            return;
        }
    }

    try {
        const notification = new Notification('PensionsGo Broadcast Test', {
            body: 'This is how broadcast browser alerts will appear on this device when a new message is sent.',
            icon: new URL('assets/pwa/icon-192.png', window.location.href).href,
            badge: new URL('assets/pwa/icon-192.png', window.location.href).href,
            tag: `broadcast-settings-test-${Date.now()}`
        });

        notification.onclick = () => {
            window.focus();
            notification.close();
        };

        this.showNotification('Test browser alert sent.', 'success');
    } catch (error) {
        this.showNotification(error.message || 'Unable to send a test browser alert.', 'error');
    } finally {
        this.updateNotificationPermissionStatus();
        this.refreshNotificationSoundControls();
    }
};

AdminDashboard.prototype.closeNotificationSoundModal = function () {
    const overlay = document.querySelector('#notificationSoundModalOverlay');
    if (overlay && this.activeSoundPreview?.button && overlay.contains(this.activeSoundPreview.button)) {
        this.stopActiveSoundPreview();
    }
    overlay?.remove();
};

AdminDashboard.prototype.openNotificationSoundUploadModal = function () {
    this.closeNotificationSoundModal();
    const overlay = document.createElement('div');
    overlay.className = 'admin-modal-overlay notification-sound-modal-overlay';
    overlay.id = 'notificationSoundModalOverlay';
    overlay.innerHTML = `
        <div class="admin-modal notification-sound-modal" role="dialog" aria-modal="true" aria-labelledby="notificationSoundUploadTitle">
            <div class="admin-modal-header">
                <div>
                    <h3 id="notificationSoundUploadTitle">Upload New Sound</h3>
                    <p class="import-modal-subtitle">Add an MP3, WAV, OGG, or M4A alert sound to the notification library.</p>
                </div>
                <button class="admin-modal-close" type="button" aria-label="Close">&times;</button>
            </div>
            <div class="admin-modal-body">
                <label class="notification-sound-dropzone" for="notificationSoundUploadInput">
                    <span class="notification-sound-dropzone-icon">♪</span>
                    <strong id="notificationSoundUploadFileName">Choose an audio file</strong>
                    <small>Maximum file size: 5 MB</small>
                    <input type="file" id="notificationSoundUploadInput" accept=".mp3,.wav,.ogg,.m4a,audio/*">
                </label>
                <div class="settings-note notification-sound-upload-note" id="notificationSoundModalStatus">No file selected.</div>
            </div>
            <div class="admin-modal-footer">
                <button class="action-btn secondary" type="button" data-close-modal>Cancel</button>
                <button class="action-btn" id="uploadNotificationSoundBtn" type="button">Upload Sound</button>
            </div>
        </div>
    `;
    document.body.appendChild(overlay);
    const close = () => this.closeNotificationSoundModal();
    overlay.querySelector('.admin-modal-close')?.addEventListener('click', close);
    overlay.querySelector('[data-close-modal]')?.addEventListener('click', close);
    overlay.addEventListener('click', (event) => {
        if (event.target === overlay) close();
    });
    overlay.querySelector('#notificationSoundUploadInput')?.addEventListener('change', (event) => {
        const file = event?.target?.files?.[0];
        const name = overlay.querySelector('#notificationSoundUploadFileName');
        const status = overlay.querySelector('#notificationSoundModalStatus');
        if (name) name.textContent = file ? file.name : 'Choose an audio file';
        if (status) status.textContent = file ? `${file.name} selected and ready to upload.` : 'No file selected.';
    });
    overlay.querySelector('#uploadNotificationSoundBtn')?.addEventListener('click', () => this.uploadNotificationSound());
    overlay.querySelector('#notificationSoundUploadInput')?.focus();
};

AdminDashboard.prototype.renderNotificationSoundLibraryRows = function (sounds = [], selectedPath = '') {
    if (!Array.isArray(sounds) || sounds.length === 0) {
        return `<div class="notification-sound-empty">No sounds are available. Upload a custom sound to extend the library.</div>`;
    }

    return sounds.map((sound) => {
        const path = String(sound.path || '');
        const isSelected = path === selectedPath;
        const name = sound.name || sound.file_name || 'Notification Sound';
        const badges = [
            sound.is_builtin ? 'Built-in' : 'Custom',
            (sound.extension || '').toUpperCase() || 'AUDIO',
            this.formatNotificationSoundBytes(sound.size_bytes || 0)
        ].filter(Boolean);
        return `
            <article class="notification-sound-row ${isSelected ? 'is-selected' : ''}" data-sound-path="${this.escapeHtml(path)}">
                <div class="notification-sound-row-main">
                    <strong>${this.escapeHtml(name)}</strong>
                    <span>${this.escapeHtml(path)}</span>
                    <div class="notification-sound-meta">
                        ${badges.map((badge) => `<span class="notification-sound-chip">${this.escapeHtml(badge)}</span>`).join('')}
                        ${isSelected ? '<span class="settings-pill active">Selected</span>' : ''}
                    </div>
                </div>
                <div class="notification-sound-row-actions">
                    <button class="action-btn secondary" type="button" data-sound-action="preview">Preview</button>
                    <button class="action-btn secondary" type="button" data-sound-action="select">Use</button>
                    <button class="action-btn danger" type="button" data-sound-action="delete" ${sound.is_builtin ? 'disabled' : ''}>Delete</button>
                </div>
            </article>
        `;
    }).join('');
};

AdminDashboard.prototype.refreshNotificationSoundLibraryModal = function () {
    const list = document.getElementById('notificationSoundLibraryModalList');
    if (!list) return;
    if (this.activeSoundPreview?.button && list.contains(this.activeSoundPreview.button)) {
        this.stopActiveSoundPreview();
    }
    const selectedPath = String(document.getElementById('notificationSoundPicker')?.value || '').trim();
    list.innerHTML = this.renderNotificationSoundLibraryRows(this.notificationSoundLibrary || [], selectedPath);
};

AdminDashboard.prototype.openNotificationSoundLibraryModal = async function () {
    this.closeNotificationSoundModal();
    const overlay = document.createElement('div');
    overlay.className = 'admin-modal-overlay notification-sound-modal-overlay';
    overlay.id = 'notificationSoundModalOverlay';
    overlay.innerHTML = `
        <div class="admin-modal notification-sound-modal notification-sound-library-modal" role="dialog" aria-modal="true" aria-labelledby="notificationSoundLibraryTitle">
            <div class="admin-modal-header">
                <div>
                    <h3 id="notificationSoundLibraryTitle">Notification Sound Library</h3>
                    <p class="import-modal-subtitle">Preview, select, upload, or remove custom notification sounds.</p>
                </div>
                <button class="admin-modal-close" type="button" aria-label="Close">&times;</button>
            </div>
            <div class="admin-modal-body">
                <div class="notification-sound-library-toolbar">
                    <button class="action-btn secondary" id="refreshNotificationSoundLibraryBtn" type="button">Refresh</button>
                    <button class="action-btn" id="libraryUploadNotificationSoundBtn" type="button">Upload New Sound</button>
                </div>
                <div class="notification-sound-library-modal-list" id="notificationSoundLibraryModalList">
                    <div class="notification-sound-empty">Loading sounds...</div>
                </div>
            </div>
            <div class="admin-modal-footer">
                <button class="action-btn secondary" type="button" data-close-modal>Close</button>
            </div>
        </div>
    `;
    document.body.appendChild(overlay);
    const close = () => this.closeNotificationSoundModal();
    overlay.querySelector('.admin-modal-close')?.addEventListener('click', close);
    overlay.querySelector('[data-close-modal]')?.addEventListener('click', close);
    overlay.addEventListener('click', (event) => {
        if (event.target === overlay) close();
    });
    overlay.querySelector('#refreshNotificationSoundLibraryBtn')?.addEventListener('click', async () => {
        await this.loadNotificationSoundLibrary({ silent: false });
        this.refreshNotificationSoundLibraryModal();
    });
    overlay.querySelector('#libraryUploadNotificationSoundBtn')?.addEventListener('click', () => this.openNotificationSoundUploadModal());
    overlay.querySelector('#notificationSoundLibraryModalList')?.addEventListener('click', (event) => this.handleNotificationSoundLibraryAction(event));
    await this.loadNotificationSoundLibrary({ silent: true });
    this.refreshNotificationSoundLibraryModal();
};

AdminDashboard.prototype.handleNotificationSoundLibraryAction = async function (event) {
    const button = event.target instanceof Element ? event.target.closest('[data-sound-action]') : null;
    if (!button) return;
    const row = button.closest('[data-sound-path]');
    const path = String(row?.dataset?.soundPath || '').trim();
    const sound = (this.notificationSoundLibrary || []).find((item) => String(item.path || '') === path);
    if (!sound) return;
    const action = button.dataset.soundAction;
    if (action === 'preview') {
        await this.previewSoundPath(path, button);
        return;
    }
    if (action === 'select') {
        const select = document.getElementById('notificationSoundPicker');
        if (select) {
            select.value = path;
            this.refreshNotificationSoundControls();
            this.refreshNotificationSoundLibraryModal();
            this.updateSettingsStatus('notification', 'Edited', 'info');
        }
        return;
    }
    if (action === 'delete') {
        await this.deleteNotificationSoundByPath(path);
    }
};

AdminDashboard.prototype.previewSoundPath = async function (path, button = null) {
    const sound = (this.notificationSoundLibrary || []).find((item) => String(item.path || '') === String(path || ''));
    if (!sound) return;
    try {
        const source = new URL(sound.path, window.location.href).href;
        const started = await this.toggleSoundPreview({
            source,
            button,
            volume: Math.max(0, Math.min(1, Number(document.getElementById('notificationSoundVolume')?.value || 85) / 100)),
            repeatCount: 1,
            key: `library:${sound.path}`
        });
        if (started) {
            this.showNotification(`Playing ${sound.name || sound.file_name || 'notification sound'} preview.`, 'info');
        }
    } catch (error) {
        this.showNotification(error.message || 'Unable to preview this sound.', 'error');
    }
};

AdminDashboard.prototype.confirmNotificationSoundDelete = function (sound) {
    return new Promise((resolve) => {
        const name = sound?.name || sound?.file_name || 'this notification sound';
        const overlay = document.createElement('div');
        overlay.className = 'admin-modal-overlay notification-sound-confirm-overlay';
        overlay.innerHTML = `
            <div class="admin-confirm-modal notification-sound-confirm-modal" role="dialog" aria-modal="true" aria-labelledby="deleteNotificationSoundTitle">
                <div class="admin-modal-header">
                    <div>
                        <h3 id="deleteNotificationSoundTitle">Delete Sound?</h3>
                        <p class="import-modal-subtitle">This custom sound will be removed from the notification library.</p>
                    </div>
                    <button class="admin-modal-close" type="button" aria-label="Close">&times;</button>
                </div>
                <div class="admin-modal-body">
                    <div class="notification-sound-delete-summary">
                        <strong>${this.escapeHtml(name)}</strong>
                        <span>${this.escapeHtml(sound?.path || '')}</span>
                    </div>
                </div>
                <div class="admin-modal-footer">
                    <button class="action-btn secondary" type="button" data-delete-confirm="cancel">Cancel</button>
                    <button class="action-btn danger" type="button" data-delete-confirm="delete">Delete Sound</button>
                </div>
            </div>
        `;
        const finish = (confirmed) => {
            overlay.remove();
            resolve(Boolean(confirmed));
        };
        overlay.querySelector('.admin-modal-close')?.addEventListener('click', () => finish(false), { once: true });
        overlay.querySelector('[data-delete-confirm="cancel"]')?.addEventListener('click', () => finish(false), { once: true });
        overlay.querySelector('[data-delete-confirm="delete"]')?.addEventListener('click', () => finish(true), { once: true });
        overlay.addEventListener('click', (event) => {
            if (event.target === overlay) finish(false);
        });
        overlay.addEventListener('keydown', (event) => {
            if (event.key === 'Escape') {
                event.preventDefault();
                finish(false);
            }
        });
        document.body.appendChild(overlay);
        overlay.querySelector('[data-delete-confirm="cancel"]')?.focus();
    });
};

AdminDashboard.prototype.uploadNotificationSound = async function () {
    const input = document.getElementById('notificationSoundUploadInput');
    const statusEl = document.getElementById('notificationSoundModalStatus') || document.getElementById('notificationSoundUploadStatus');
    const file = input?.files?.[0];

    if (!file) {
        this.showNotification('Choose a sound file to upload first.', 'info');
        return;
    }

    if (statusEl) {
        statusEl.textContent = `Uploading ${file.name}...`;
    }

    try {
        const formData = new FormData();
        formData.append('sound', file);

        const response = await fetch('../backend/api/upload_notification_sound.php', {
            method: 'POST',
            credentials: 'include',
            body: formData
        });
        const data = await this.safeJson(response, { success: false, sounds: [] });
        if (!data.success) {
            throw new Error(data.message || 'Unable to upload the sound file.');
        }

        this.applyNotificationSoundLibrary(data.sounds || [], data.sound?.path || '');
        if (input) {
            input.value = '';
        }
        if (statusEl) {
            statusEl.textContent = `${data.sound?.name || file.name} uploaded. Save settings to make it the default broadcast sound.`;
        }
        this.showNotification(data.message || 'Notification sound uploaded successfully.', 'success');
        this.closeNotificationSoundModal();
        this.openNotificationSoundLibraryModal();
    } catch (error) {
        if (statusEl) {
            statusEl.textContent = 'Supported formats: MP3, WAV, OGG, and M4A. Maximum file size: 5 MB.';
        }
        this.showNotification(error.message || 'Unable to upload the selected sound.', 'error');
    }
};

AdminDashboard.prototype.deleteNotificationSoundByPath = async function (soundPath) {
    const selectedPath = String(soundPath || '').trim();
    const selectedSound = (this.notificationSoundLibrary || []).find((sound) => (sound.path || '') === selectedPath) || null;

    if (!selectedSound) {
        this.showNotification('Choose a custom sound to delete.', 'info');
        return;
    }

    if (selectedSound.is_builtin) {
        this.showNotification('Built-in notification sounds cannot be deleted.', 'info');
        return;
    }

    if (!(await this.confirmNotificationSoundDelete(selectedSound))) {
        return;
    }

    try {
        const response = await fetch('../backend/api/delete_notification_sound.php', {
            method: 'POST',
            credentials: 'include',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ path: selectedSound.path })
        });
        const data = await this.safeJson(response, { success: false, sounds: [] });
        if (!data.success) {
            throw new Error(data.message || 'Unable to delete the selected sound.');
        }

        this.applyNotificationSoundLibrary(data.sounds || [], data.selected_path || '');
        const statusEl = document.getElementById('notificationSoundUploadStatus');
        if (statusEl) {
            statusEl.textContent = 'Custom sound removed. Save settings if you want the fallback selection to become the new default.';
        }
        this.refreshNotificationSoundLibraryModal();
        this.showNotification(data.message || 'Notification sound deleted successfully.', 'success');
    } catch (error) {
        this.showNotification(error.message || 'Unable to delete the selected sound.', 'error');
    }
};

AdminDashboard.prototype.deleteSelectedNotificationSound = async function () {
    const select = document.getElementById('notificationSoundPicker');
    await this.deleteNotificationSoundByPath(String(select?.value || '').trim());
};

const originalLoadNotificationSettingsContentPlanned = AdminDashboard.prototype.loadNotificationSettingsContent;
AdminDashboard.prototype.loadNotificationSettingsContent = async function () {
    const baseMarkup = (await originalLoadNotificationSettingsContentPlanned.call(this))
        .replace(/<span class="settings-pill planned">Planned<\/span>/g, '');
    const insights = await this.fetchAdminInsights('notification-settings');
    const transport = insights.transport_runtime || {};
    const panelHtml = `
        <section class="settings-card runtime-settings-card">
            <div class="section-card-header">
                <div>
                    <h3>Daily Digest Operations</h3>
                    <p>Preview digest coverage, review recent runs, and trigger an immediate briefing for administrators.</p>
                </div>
                <div class="runtime-toolbar">
                    <button type="button" class="action-btn secondary" id="refreshNotificationDigestInsightsBtn">Refresh Runtime</button>
                    <button type="button" class="action-btn secondary" id="processNotificationQueueBtn">Process Queue Now</button>
                    <button type="button" class="action-btn secondary" id="previewNotificationDigestBtn">Preview Digest</button>
                    <button type="button" class="action-btn" id="queueNotificationDigestBtn">Queue Digest Now</button>
                </div>
            </div>
            ${this.renderInsightSummaryCards(insights.summary || [], 'compact-summary')}
            <div class="settings-split-grid runtime-section-grid">
                ${this.renderDefinitionListCard(
                    'Mail Transport Status',
                    'Read-only transport values currently in use by the outbound email worker.',
                    [
                        { label: 'Current Transport', value: transport.transport || 'Unknown' },
                        { label: 'SMTP Host', value: transport.smtp_host || 'Not applicable', title: transport.smtp_host || 'Not applicable' },
                        { label: 'Port', value: transport.smtp_port || 'Not applicable' },
                        { label: 'Encryption', value: transport.encryption || 'Not applicable' },
                        { label: 'Last Queue Run', value: transport.last_queue_run || 'No run yet' },
                        { label: 'Last Queue Failure', value: transport.last_failure_summary || 'No failed deliveries recorded', title: transport.last_failure_summary || 'No failed deliveries recorded' }
                    ],
                    'mail-transport-card'
                )}
                <div class="settings-card analytics-card">
                    <div class="settings-group-header">
                        <h4>Digest Coverage</h4>
                        <p>These metrics define what the current digest will surface to administrators.</p>
                    </div>
                    ${this.renderInsightBars((insights.digest_summary || []).map((item) => ({
                        label: item.label,
                        count: Number(item.value || 0)
                    })), 'count', 'label', (item) => `${Number(item.count || 0).toLocaleString()} included`)}
                </div>
                <div class="settings-card analytics-card">
                    <div class="settings-group-header">
                        <h4>Recent Digest Runs</h4>
                        <p>Preview and queue activity is recorded here for operational traceability.</p>
                    </div>
                    ${this.renderRuntimeTable([
                        { label: 'Run Type', render: (row) => this.escapeHtml(String(row.run_type || 'scheduled').replace(/_/g, ' ')) },
                        { label: 'Status', render: (row) => this.escapeHtml(row.status || '') },
                        { label: 'Recipient', render: (row) => this.escapeHtml(row.recipient || 'Not configured') },
                        { label: 'Created', render: (row) => this.escapeHtml(this.formatAdminDateTime(row.created_at)) }
                    ], insights.recent_runs || [], 'No digest activity has been recorded yet.')}
                </div>
            </div>
            ${this.renderInsightNotes(insights.insights || [])}
        </section>
    `;
    return this.appendSettingsRuntimePanel(baseMarkup, panelHtml);
};

const originalLoadMessageStorageContentPlanned = AdminDashboard.prototype.loadMessageStorageContent;
AdminDashboard.prototype.loadMessageStorageContent = async function () {
    const baseMarkup = (await originalLoadMessageStorageContentPlanned.call(this))
        .replace(/<span class="settings-pill planned">Planned<\/span>/g, '');
    const insights = await this.fetchAdminInsights('message-storage');
    const runtime = insights.runtime || {};
    const panelHtml = `
        <section class="settings-card runtime-settings-card">
            <div class="section-card-header">
                <div>
                    <h3>Message Recovery & Snapshot Runtime</h3>
                    <p>Track deleted message views, audit snapshot coverage, and create a fresh snapshot before sensitive maintenance.</p>
                </div>
                <div class="runtime-toolbar">
                    <button type="button" class="action-btn secondary" id="refreshMessageStorageRuntimeBtn">Refresh Runtime</button>
                    <button type="button" class="action-btn" id="createMessageSnapshotBtn">Create Snapshot Now</button>
                </div>
            </div>
            ${this.renderInsightSummaryCards(insights.summary || [], 'compact-summary')}
            <div class="settings-split-grid runtime-section-grid">
                <div class="settings-card analytics-card">
                    <div class="settings-group-header">
                        <h4>Soft Delete Footprint</h4>
                        <p>Shows how much recoverable message state is currently being retained.</p>
                    </div>
                    ${this.renderInsightBars(insights.breakdown || [], 'count', 'label', (item) => `${Number(item.count || 0).toLocaleString()} records`)}
                    <div class="runtime-callout">
                        <strong>Storage posture</strong>
                        <span>${this.escapeHtml(runtime.last_snapshot_at ? `Latest snapshot was created ${this.formatAdminDateTime(runtime.last_snapshot_at)}.` : 'No snapshot has been created yet.')}</span>
                    </div>
                </div>
                <div class="settings-card analytics-card">
                    <div class="settings-group-header">
                        <h4>Recent Snapshots</h4>
                        <p>Snapshots capture message evidence and attachment references for controlled recovery.</p>
                    </div>
                    ${this.renderRuntimeTable([
                        { label: 'File', render: (row) => this.escapeHtml(row.file_name || 'Snapshot') },
                        { label: 'Type', render: (row) => this.escapeHtml(row.snapshot_type || 'auto') },
                        { label: 'Size', render: (row) => this.escapeHtml(this.formatBytes(row.file_size_bytes || 0)) },
                        { label: 'Created', render: (row) => this.escapeHtml(this.formatAdminDateTime(row.created_at)) }
                    ], insights.snapshots || [], 'No message storage snapshots have been created yet.')}
                </div>
            </div>
            ${this.renderInsightNotes(insights.insights || [])}
        </section>
    `;
    return this.appendSettingsRuntimePanel(baseMarkup, panelHtml);
};

const originalLoadAttachmentStorageContentPlanned = AdminDashboard.prototype.loadAttachmentStorageContent;
AdminDashboard.prototype.loadAttachmentStorageContent = async function () {
    const baseMarkup = (await originalLoadAttachmentStorageContentPlanned.call(this))
        .replace(/<span class="settings-pill planned">Planned<\/span>/g, '');
    const insights = await this.fetchAdminInsights('attachment-storage');
    const runtime = insights.runtime || {};
    const panelHtml = `
        <section class="settings-card runtime-settings-card">
            <div class="section-card-header">
                <div>
                    <h3>File Scan Runtime</h3>
                    <p>Verify which scan engine is active, review recent scan outcomes, and tighten upload policy around suspicious files.</p>
                </div>
                <div class="runtime-toolbar">
                    <button type="button" class="action-btn secondary" id="refreshAttachmentStorageRuntimeBtn">Refresh Runtime</button>
                </div>
            </div>
            ${this.renderInsightSummaryCards(insights.summary || [], 'compact-summary')}
            <div class="settings-split-grid runtime-section-grid">
                <div class="settings-card analytics-card">
                    <div class="settings-group-header">
                        <h4>Scanner Posture</h4>
                        <p>Operational visibility into the engine currently protecting uploads.</p>
                    </div>
                    <div class="runtime-callout ${runtime.native_available ? '' : 'warning'}">
                        <strong>${this.escapeHtml(runtime.native_available ? 'Native scanner detected' : 'Heuristic scanner active')}</strong>
                        <span>${this.escapeHtml(runtime.native_available ? 'ClamAV is available and uploads are being checked with a native engine.' : 'No native scanner was detected. The system is relying on heuristic inspection rules.')}</span>
                    </div>
                    ${this.renderInsightBars(insights.breakdown || [], 'count', 'label', (item) => `${Number(item.count || 0).toLocaleString()} files`)}
                </div>
                <div class="settings-card analytics-card">
                    <div class="settings-group-header">
                        <h4>Recent Scan Activity</h4>
                        <p>Review the latest uploads and the outcome of the scanning policy.</p>
                    </div>
                    ${this.renderRuntimeTable([
                        { label: 'Context', render: (row) => this.escapeHtml(String(row.storage_context || 'attachment').replace(/_/g, ' ')) },
                        { label: 'File', render: (row) => this.escapeHtml(row.file_name || 'Unknown file') },
                        { label: 'Status', render: (row) => this.escapeHtml(row.scan_status || '') },
                        { label: 'Scanned', render: (row) => this.escapeHtml(this.formatAdminDateTime(row.scanned_at)) }
                    ], insights.recent_scans || [], 'No file scans have been logged yet.')}
                </div>
            </div>
            ${this.renderInsightNotes(insights.insights || [])}
        </section>
    `;
    return this.appendSettingsRuntimePanel(baseMarkup, panelHtml);
};

const originalInitializeNotificationSettingsPlanned = AdminDashboard.prototype.initializeNotificationSettings;
AdminDashboard.prototype.initializeNotificationSettings = async function () {
    await originalInitializeNotificationSettingsPlanned.call(this);

    document.getElementById('notificationSoundVolume')?.addEventListener('input', () => this.syncNotificationSoundRangeValue());
    document.getElementById('notificationSoundPicker')?.addEventListener('change', () => {
        this.stopActiveSoundPreview();
        this.refreshNotificationSoundControls();
    });
    document.getElementById('liveCallIncomingSoundVolume')?.addEventListener('input', () => this.syncLiveCallSoundRangeValues());
    document.getElementById('liveCallOutgoingSoundVolume')?.addEventListener('input', () => this.syncLiveCallSoundRangeValues());
    document.getElementById('liveMessageSoundVolume')?.addEventListener('input', () => this.syncLiveCallSoundRangeValues());
    document.getElementById('liveCallIncomingSoundPicker')?.addEventListener('change', () => {
        this.stopActiveSoundPreview();
        this.refreshLiveCallSoundControls();
    });
    document.getElementById('liveCallOutgoingSoundPicker')?.addEventListener('change', () => {
        this.stopActiveSoundPreview();
        this.refreshLiveCallSoundControls();
    });
    document.getElementById('liveMessageSoundPicker')?.addEventListener('change', () => {
        this.stopActiveSoundPreview();
        this.refreshLiveCallSoundControls();
    });
    document.getElementById('previewNotificationSoundBtn')?.addEventListener('click', (event) => this.previewNotificationSound(event.currentTarget));
    document.getElementById('previewIncomingCallSoundBtn')?.addEventListener('click', (event) => this.previewLiveCallSound('incoming', event.currentTarget));
    document.getElementById('previewOutgoingCallSoundBtn')?.addEventListener('click', (event) => this.previewLiveCallSound('outgoing', event.currentTarget));
    document.getElementById('previewLiveMessageSoundBtn')?.addEventListener('click', (event) => this.previewLiveMessageSound(event.currentTarget));
    document.getElementById('requestNotificationPermissionBtn')?.addEventListener('click', () => this.requestBrowserNotificationPermission());
    document.getElementById('requestCallNotificationPermissionBtn')?.addEventListener('click', () => this.requestBrowserNotificationPermission().then(() => this.refreshLiveCallSoundControls()));
    document.getElementById('testBrowserNotificationBtn')?.addEventListener('click', () => this.testBrowserNotification());
    document.getElementById('openNotificationSoundUploadModalBtn')?.addEventListener('click', () => this.openNotificationSoundUploadModal());
    document.getElementById('openNotificationSoundLibraryModalBtn')?.addEventListener('click', () => this.openNotificationSoundLibraryModal());

    this.syncNotificationSoundRangeValue();
    this.syncLiveCallSoundRangeValues();
    this.refreshNotificationSoundControls();
    this.refreshLiveCallSoundControls();
    this.updateNotificationPermissionStatus();

    document.getElementById('refreshNotificationDigestInsightsBtn')?.addEventListener('click', () => this.loadSectionContent('notification-settings'));
    document.getElementById('processNotificationQueueBtn')?.addEventListener('click', async () => {
        try {
            const response = await fetch('../backend/api/process_notification_queue.php', {
                method: 'POST',
                credentials: 'include',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({})
            });
            const data = await this.safeJson(response, { success: false });
            if (!data.success) {
                throw new Error(data.message || 'Unable to process notification queue.');
            }
            this.showNotification(data.message || 'Notification queue processed.', 'success');
            await this.loadSectionContent('notification-settings');
            if (typeof this.loadNotificationQueue === 'function') {
                this.loadNotificationQueue(1);
            }
        } catch (error) {
            this.showNotification(error.message || 'Unable to process notification queue.', 'error');
        }
    });
    document.getElementById('previewNotificationDigestBtn')?.addEventListener('click', async () => {
        try {
            const response = await fetch('../backend/api/run_notification_digest.php', {
                method: 'POST',
                credentials: 'include',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ action: 'preview' })
            });
            const data = await this.safeJson(response, { success: false });
            if (!data.success) {
                throw new Error(data.message || 'Unable to preview the daily digest.');
            }
            this.openRuntimePreviewModal('Daily Digest Preview', 'Review the digest content before it is queued for delivery.', this.renderDigestPreview(data.digest || {}));
            await this.loadSectionContent('notification-settings');
        } catch (error) {
            this.showNotification(error.message || 'Unable to preview the daily digest.', 'error');
        }
    });
    document.getElementById('queueNotificationDigestBtn')?.addEventListener('click', async () => {
        try {
            const response = await fetch('../backend/api/run_notification_digest.php', {
                method: 'POST',
                credentials: 'include',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ action: 'queue_now' })
            });
            const data = await this.safeJson(response, { success: false });
            if (!data.success) {
                throw new Error(data.message || 'Unable to queue the daily digest.');
            }
            this.showNotification(data.message || 'Daily digest queued.', 'success');
            await this.loadSectionContent('notification-settings');
        } catch (error) {
            this.showNotification(error.message || 'Unable to queue the daily digest.', 'error');
        }
    });
};

const originalInitializeMessageStorageSettingsPlanned = AdminDashboard.prototype.initializeMessageStorageSettings;
AdminDashboard.prototype.initializeMessageStorageSettings = async function () {
    await originalInitializeMessageStorageSettingsPlanned.call(this);

    document.getElementById('refreshMessageStorageRuntimeBtn')?.addEventListener('click', () => this.loadSectionContent('message-storage'));
    document.getElementById('createMessageSnapshotBtn')?.addEventListener('click', async () => {
        try {
            const response = await fetch('../backend/api/run_message_storage_snapshot.php', {
                method: 'POST',
                credentials: 'include'
            });
            const data = await this.safeJson(response, { success: false });
            if (!data.success) {
                throw new Error(data.message || 'Unable to create a message storage snapshot.');
            }
            this.showNotification(data.message || 'Message storage snapshot created.', 'success');
            await this.loadSectionContent('message-storage');
        } catch (error) {
            this.showNotification(error.message || 'Unable to create a message storage snapshot.', 'error');
        }
    });
};

const originalInitializeAttachmentStorageSettingsPlanned = AdminDashboard.prototype.initializeAttachmentStorageSettings;
AdminDashboard.prototype.initializeAttachmentStorageSettings = async function () {
    await originalInitializeAttachmentStorageSettingsPlanned.call(this);
    document.getElementById('refreshAttachmentStorageRuntimeBtn')?.addEventListener('click', () => this.loadSectionContent('attachment-storage'));
};

const originalSaveNotificationSettingsPlanned = AdminDashboard.prototype.saveNotificationSettings;
AdminDashboard.prototype.saveNotificationSettings = async function () {
    await originalSaveNotificationSettingsPlanned.call(this);
    if ((this.currentSection || '') === 'notification-settings') {
        await this.loadSectionContent('notification-settings');
    }
};

const originalSaveMessageStorageSettingsPlanned = AdminDashboard.prototype.saveMessageStorageSettings;
AdminDashboard.prototype.saveMessageStorageSettings = async function () {
    await originalSaveMessageStorageSettingsPlanned.call(this);
    if ((this.currentSection || '') === 'message-storage') {
        await this.loadSectionContent('message-storage');
    }
};

const originalSaveAttachmentStorageSettingsPlanned = AdminDashboard.prototype.saveAttachmentStorageSettings;
AdminDashboard.prototype.saveAttachmentStorageSettings = async function () {
    await originalSaveAttachmentStorageSettingsPlanned.call(this);
    if ((this.currentSection || '') === 'attachment-storage') {
        await this.loadSectionContent('attachment-storage');
    }
};

const finalLoadSectionContent = AdminDashboard.prototype.loadSectionContent;
AdminDashboard.prototype.loadSectionContent = async function (section) {
    await finalLoadSectionContent.call(this, section);
    await this.applyPendingSearchTarget(section);
};
