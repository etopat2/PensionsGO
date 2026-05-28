// frontend/js/profile.js
document.addEventListener('DOMContentLoaded', () => {
    function resolveCurrentUserId() {
        const sessionId = sessionStorage.getItem('userId');
        if (sessionId) return sessionId;
        try {
            const stored = JSON.parse(localStorage.getItem('loggedInUser') || '{}');
            return stored.id || stored.userId || '';
        } catch (_error) {
            return '';
        }
    }
    // DOM Elements
    const profileAvatar = document.getElementById('profileAvatar');
    const profileName = document.getElementById('profileName');
    const profileEmail = document.getElementById('profileEmail');
    const profilePhone = document.getElementById('profilePhone');
    const profileRole = document.getElementById('profileRole');
    const roleBadge = document.getElementById('roleBadge');
    const profileTitleLabel = document.getElementById('profileTitleLabel');
    const editProfileBtn = document.getElementById('editProfileBtn');
    const backToDashboardBtn = document.getElementById('backToDashboardBtn');
    const lookupVisibilityCard = document.getElementById('pensionerLookupVisibilityCard');
    const lookupVisibilityStatus = document.getElementById('pensionerLookupVisibilityStatus');
    const lookupVisibilityHelp = document.getElementById('pensionerLookupVisibilityHelp');
    const lookupVisibilityToggle = document.getElementById('pensionerLookupVisibilityToggle');
    const lookupVisibilityToggleText = document.getElementById('pensionerLookupVisibilityToggleText');
    let lookupContext = null;

    initializeProfilePage();

    // Event Listeners
    if (editProfileBtn) editProfileBtn.addEventListener('click', redirectToEditProfile);
    if (backToDashboardBtn) backToDashboardBtn.addEventListener('click', redirectToDashboard);
    if (lookupVisibilityToggle) lookupVisibilityToggle.addEventListener('click', toggleLookupVisibility);

    // Initialize Profile
    async function initializeProfilePage() {
        const currentUser = JSON.parse(localStorage.getItem('loggedInUser') || '{}');
        const resolvedUserId = resolveCurrentUserId();
        if (!currentUser.id && resolvedUserId) {
            currentUser.id = resolvedUserId;
        }

        if (!currentUser.id) {
            showError('User not logged in. Redirecting to login...');
            setTimeout(() => (window.location.href = 'login.html'), 2000);
            return;
        }

        try {
            const userData = await fetchUserData(currentUser.id);
            if (userData) {
                const completeUserData = {
                    ...currentUser,
                    email: userData.userEmail,
                    name: userData.userName,
                    title: userData.userTitle,
                    role: userData.userRole,
                    phone: userData.phoneNo,
                    photo: userData.userPhoto
                };

                localStorage.setItem('loggedInUser', JSON.stringify(completeUserData));
                populateProfileData(completeUserData);
                initializePensionerLookupVisibility(completeUserData.role);
            } else {
                populateProfileData(currentUser);
                initializePensionerLookupVisibility(currentUser.role);
            }
        } catch (error) {
            console.error('Error fetching user data:', error);
            populateProfileData(currentUser);
            initializePensionerLookupVisibility(currentUser.role);
        }
    }

    // Fetch User from API
    async function fetchUserData(userId) {
        try {
            const res = await fetch(`../backend/api/get_user.php?userId=${userId}`);
            if (!res.ok) throw new Error('Failed to fetch user data');

            const data = await res.json();
            if (data.success) return data.user;
            throw new Error(data.message);
        } catch (err) {
            console.error('Fetch user failed:', err);
            return null;
        }
    }

    // Populate Profile
    function populateProfileData(user) {
        console.log('Populating profile with:', user);

        // Avatar
        if (profileAvatar) {
            const src = resolveImagePath(user.photo || 'images/default-user.png');
            profileAvatar.src = src;
            profileAvatar.onerror = () => (profileAvatar.src = 'images/default-user.png');
        }

        // Dynamic title label
        if (profileTitleLabel) {
            profileTitleLabel.textContent = user.title || 'User';
        }

        // Full Name
        if (profileName) {
            profileName.textContent = user.name || 'Not specified';
        }

        // Email
        if (profileEmail) {
            profileEmail.textContent = user.email || 'Not specified';
        }

        // Phone
        if (profilePhone) {
            profilePhone.textContent = user.phone || 'Not specified';
        }

        // Role
        if (profileRole && roleBadge) {
            const role = user.role || 'user';
            roleBadge.textContent = formatRole(role);
            roleBadge.setAttribute('data-role', role);
        }
    }

    async function initializePensionerLookupVisibility(role) {
        const normalizedRole = String(role || '').trim().toLowerCase();
        if (normalizedRole !== 'pensioner') {
            if (lookupVisibilityCard) lookupVisibilityCard.hidden = true;
            return;
        }

        if (lookupVisibilityCard) lookupVisibilityCard.hidden = false;
        try {
            const response = await fetch('../backend/api/get_pensioner_lookup_context.php', {
                credentials: 'include',
                cache: 'no-store',
                headers: {
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest'
                }
            });
            const data = await response.json().catch(() => ({}));
            if (!response.ok || !data.success) {
                throw new Error(data.message || 'Unable to load pensioner directory visibility.');
            }
            lookupContext = data;
            renderLookupVisibilityState();
        } catch (error) {
            console.error('Unable to load pensioner lookup visibility:', error);
            if (lookupVisibilityStatus) lookupVisibilityStatus.textContent = 'Unavailable';
            if (lookupVisibilityHelp) lookupVisibilityHelp.textContent = error.message || 'Unable to load pensioner directory visibility.';
            if (lookupVisibilityToggle) {
                lookupVisibilityToggle.disabled = true;
                lookupVisibilityToggle.textContent = 'Off';
                lookupVisibilityToggle.classList.remove('is-on');
                lookupVisibilityToggle.setAttribute('aria-pressed', 'false');
            }
            lookupVisibilityCard?.classList.add('is-disabled');
        }
    }

    function renderLookupVisibilityState() {
        if (!lookupVisibilityCard) return;
        const enabled = Boolean(lookupContext?.enabled);
        const visible = Boolean(lookupContext?.visibilityEnabled);

        lookupVisibilityCard.classList.toggle('is-disabled', !enabled);

        if (lookupVisibilityStatus) {
            lookupVisibilityStatus.textContent = enabled
                ? (visible ? 'Visible to fellow pensioners' : 'Hidden from directory')
                : 'Directory disabled';
        }

        if (lookupVisibilityHelp) {
            lookupVisibilityHelp.textContent = enabled
                ? (visible
                    ? 'Fellow pensioners can find your shared contact details in the directory.'
                    : 'Your contact details are currently hidden from the pensioner directory.')
                : 'The pensioner directory is currently disabled by the pensions office.';
        }

        if (lookupVisibilityToggle) {
            lookupVisibilityToggle.disabled = !enabled;
            lookupVisibilityToggle.classList.toggle('is-on', visible && enabled);
            lookupVisibilityToggle.setAttribute('aria-pressed', visible && enabled ? 'true' : 'false');
        }
        if (lookupVisibilityToggleText) {
            lookupVisibilityToggleText.textContent = visible && enabled ? 'On' : 'Off';
        }
    }

    async function toggleLookupVisibility() {
        if (!lookupContext?.enabled || !lookupVisibilityToggle) {
            return;
        }

        const nextVisible = !Boolean(lookupContext.visibilityEnabled);
        lookupVisibilityToggle.disabled = true;
        try {
            const response = await fetch('../backend/api/update_pensioner_lookup_visibility.php', {
                method: 'POST',
                credentials: 'include',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest'
                },
                body: JSON.stringify({ visible: nextVisible })
            });
            const data = await response.json().catch(() => ({}));
            if (!response.ok || !data.success) {
                throw new Error(data.message || 'Unable to update pensioner directory visibility.');
            }

            lookupContext = {
                ...(lookupContext || {}),
                visibilityEnabled: Boolean(data.visible)
            };
            renderLookupVisibilityState();
        } catch (error) {
            console.error('Unable to update pensioner lookup visibility:', error);
            if (lookupVisibilityHelp) {
                lookupVisibilityHelp.textContent = error.message || 'Unable to update pensioner directory visibility.';
            }
        } finally {
            if (lookupVisibilityToggle) {
                lookupVisibilityToggle.disabled = !lookupContext?.enabled;
            }
        }
    }

    // Helpers
    function resolveImagePath(imagePath) {
        if (!imagePath || imagePath === 'images/default-user.png') return 'images/default-user.png';
        if (imagePath.startsWith('http') || imagePath.startsWith('data:')) return imagePath;
        if (imagePath.includes('uploads/')) {
            const file = imagePath.split('/').pop();
            return `../backend/api/get_image.php?file=${file}&type=profile`;
        }
        return `../backend/api/get_image.php?file=${imagePath}&type=profile`;
    }

    function formatRole(role) {
        const map = {
            super_admin: 'Super Administrator',
            admin: 'Administrator',
            clerk: 'Clerk',
            oc_pen: 'OC/Pension',
            dep_oc: 'Deputy OC/Pension',
            deputy_oc: 'Deputy OC/Pension',
            deputy_oc_pen: 'Deputy OC/Pension',
            deputy_oc_pension: 'Deputy OC/Pension',
            writeup_officer: 'Writeup Officer',
            file_creator: 'File Creator',
            data_entry: 'Data Entrant',
            assessor: 'Assessor',
            auditor: 'Auditor',
            approver: 'Approver',
            user: 'User',
            pensioner: 'Pensioner'
        };
        return map[role] || role.charAt(0).toUpperCase() + role.slice(1);
    }

    function redirectToEditProfile() {
        const currentUser = JSON.parse(localStorage.getItem('loggedInUser') || '{}');
        const resolvedId = currentUser.id || currentUser.userId || resolveCurrentUserId();
        if (resolvedId) window.location.href = `edit_user.html?user_id=${encodeURIComponent(resolvedId)}`;
        else showError('Unable to determine user ID. Please login again.');
    }

    function redirectToDashboard() {
        const user = JSON.parse(localStorage.getItem('loggedInUser') || '{}');
        const role = user.role?.toLowerCase() || 'user';
        const dashboards = {
            super_admin: 'dashboard.html',
            admin: 'dashboard.html',
            user: 'dashboard.html',
            clerk: 'pension_file_registry.html',
            pensioner: 'pensioner_board.html',
            oc_pen: 'dashboard.html',
            dep_oc: 'dashboard.html',
            deputy_oc: 'dashboard.html',
            deputy_oc_pen: 'dashboard.html',
            deputy_oc_pension: 'dashboard.html',
            writeup_officer: 'tasks.html',
            file_creator: 'tasks.html',
            data_entry: 'tasks.html',
            assessor: 'tasks.html',
            auditor: 'tasks.html',
            approver: 'tasks.html'
        };
        window.location.href = dashboards[role] || 'tasks.html';
    }

    function showError(msg) {
        const modal = document.createElement('div');
        modal.className = 'auth-modal-overlay';
        modal.innerHTML = `
            <div class="auth-modal login-error-modal">
                <div class="auth-modal-header"><h3>Error</h3></div>
                <div class="auth-modal-body">
                    <div class="login-error-icon">⚠️</div>
                    <p>${msg}</p>
                </div>
                <div class="auth-modal-footer">
                    <button id="closeErrorModal" class="auth-btn auth-btn-secondary">OK</button>
                </div>
            </div>`;
        document.body.appendChild(modal);
        document.getElementById('closeErrorModal').onclick = () => modal.remove();
        modal.onclick = e => { if (e.target === modal) modal.remove(); };
    }

    // Allow external refresh
    window.refreshProfileData = () => {
        const u = JSON.parse(localStorage.getItem('loggedInUser') || '{}');
        populateProfileData(u);
    };
});

