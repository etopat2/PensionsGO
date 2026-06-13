// 
// Edit User Script
// Handles user profile editing, validation, image upload,
// password change, and admin/user confirmation flow.
// Supports flexible phone number input (international + Uganda local formats)
// 
// Redirect if not logged in
if (sessionStorage.getItem('isLoggedIn') !== 'true') {
  window.location.replace('login.html');
}

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

    // Handle redirect if no user_id in query
    if (handleProfileMenuRedirect()) return;

    // Extract user_id from query params
    const urlParams = new URLSearchParams(window.location.search);
    const userId = urlParams.get('user_id');

    // DOM references
    const editUserForm = document.getElementById('editUserForm');
    const saveBtn = document.getElementById('saveBtn');
    const cancelBtn = document.getElementById('cancelBtn');
    const profilePicture = document.getElementById('profilePicture');
    const profilePreview = document.getElementById('profilePreview');
    const passwordToggle = document.getElementById('passwordToggle');
    const passwordFields = document.getElementById('passwordFields');
    const newPassword = document.getElementById('newPassword');
    const confirmPassword = document.getElementById('confirmPassword');
    const passwordMatch = document.getElementById('passwordMatch');
    const passwordMismatch = document.getElementById('passwordMismatch');
    const roleSection = document.getElementById('roleSection');
    const phoneNoInput = document.getElementById('phoneNo');
    const userRoleSelect = document.getElementById('userRole');
    const userTitleSelect = document.getElementById('userTitle');
    const officialTitles = [
        'Mr.',
        'Mrs.',
        'Ms.',
        'Miss',
        'Dr.',
        'Prof.',
        'Hon.',
        'Rev.',
        'Fr.',
        'Sr.'
    ];
    const staticRoles = [
        { key: 'admin', label: 'Administrator' },
        { key: 'clerk', label: 'Clerk' },
        { key: 'oc_pen', label: 'OC/Pension' },
        { key: 'writeup_officer', label: 'Writeup Officer' },
        { key: 'file_creator', label: 'File Creator' },
        { key: 'data_entry', label: 'Data Entrant' },
        { key: 'assessor', label: 'Assessor' },
        { key: 'auditor', label: 'Auditor' },
        { key: 'approver', label: 'Approver' },
        { key: 'user', label: 'User' },
        { key: 'pensioner', label: 'Pensioner' }
    ];

    function normalizePhone(value) {
        const input = String(value || '').trim().replace(/[\s().-]/g, '');
        if (!input) return null;
        if (/^00[1-9]\d{7,14}$/.test(input)) return `+${input.slice(2)}`;
        if (/^\+[1-9]\d{7,14}$/.test(input)) return input;
        if (/^0\d{9}$/.test(input)) return `+256${input.slice(1)}`;
        if (/^[1-9]\d{7,14}$/.test(input)) return `+${input}`;
        return null;
    }

    // Modal elements
    const passwordModal = document.getElementById('passwordModal');
    const adminModal = document.getElementById('adminModal');
    const currentPassword = document.getElementById('currentPassword');
    const confirmWithPassword = document.getElementById('confirmWithPassword');
    const confirmAdmin = document.getElementById('confirmAdmin');
    const closeModalButtons = document.querySelectorAll('.close-modal, .modal-btn.cancel');

    // State
    let currentUserData = null;
    let isPasswordChangeRequested = false;
    let newProfilePicture = null;
    let originalFormData = {};

    // Determine logged-in user
    const currentUser = JSON.parse(localStorage.getItem('loggedInUser') || '{}');
    const currentUserId = resolveCurrentUserId();
    if (!currentUser.id && currentUserId) {
        currentUser.id = currentUserId;
    }
    const currentEffectiveRole = String(
        currentUser.effectiveRole
        || localStorage.getItem('userRoleEffective')
        || currentUser.role
        || ''
    ).toLowerCase();
    const isAdmin = currentEffectiveRole === 'admin' || currentEffectiveRole === 'super_admin' || currentUser.role === 'super_admin';
    const currentRawRole = String(
        sessionStorage.getItem('userRole')
        || localStorage.getItem('userRole')
        || currentUser.role
        || ''
    ).toLowerCase();
    const isSuperAdmin = currentRawRole === 'super_admin';

    // Hide role section for non-admin users
    if (!isAdmin && roleSection) {
        roleSection.style.display = 'none';
        if (userRoleSelect) {
            userRoleSelect.disabled = true;
            userRoleSelect.removeAttribute('required');
        }
    }

    // Initialize
    initializeForm();

    // Event Listeners
    if (passwordToggle) passwordToggle.addEventListener('click', togglePasswordFields);
    if (newPassword) newPassword.addEventListener('input', checkPasswordMatch);
    if (confirmPassword) confirmPassword.addEventListener('input', checkPasswordMatch);
    if (profilePicture) profilePicture.addEventListener('change', handleProfilePictureChange);
    if (editUserForm) editUserForm.addEventListener('submit', handleFormSubmit);
    if (cancelBtn) cancelBtn.addEventListener('click', handleCancel);
    if (confirmWithPassword) confirmWithPassword.addEventListener('click', confirmChangesWithPassword);
    if (confirmAdmin) confirmAdmin.addEventListener('click', confirmChangesAsAdmin);
    closeModalButtons.forEach(btn => btn.addEventListener('click', closeAllModals));
    window.addEventListener('click', e => {
        if (e.target === passwordModal) passwordModal.style.display = 'none';
        if (e.target === adminModal) adminModal.style.display = 'none';
    });

    // Initialization
    function handleProfileMenuRedirect() {
        const userIdFromUrl = new URLSearchParams(window.location.search).get('user_id');
        if (!userIdFromUrl) {
            const resolvedId = resolveCurrentUserId();
            if (resolvedId) {
                window.location.href = `edit_user.html?user_id=${encodeURIComponent(resolvedId)}`;
                return true;
            }
            appAlert('Unable to determine user ID. Please login again.');
            window.location.href = 'login.html';
            return true;
        }
        return false;
    }

    async function initializeForm() {
        if (!userId) {
            appAlert('No user ID provided. Redirecting...');
            window.location.href = 'profile.html';
            return;
        }
        try {
            saveBtn.disabled = true;
            saveBtn.innerHTML = '<span class="loading"></span> Loading...';

            currentUserData = await fetchUserData(userId);
            if (!currentUserData) throw new Error('User not found');
            const targetRole = String(currentUserData.userRole || '').toLowerCase();
            if ((targetRole === 'admin' || targetRole === 'super_admin') && !isSuperAdmin) {
                appAlert('Only the super administrator can modify administrator accounts.');
                window.location.href = 'users.html';
                return;
            }
            populateTitleOptions(currentUserData.userTitle || '');
            await loadRoleOptions(currentUserData.userRole || '');

            // Non-admins can only edit their own profile
            if (!isAdmin && currentUserData.userId !== currentUserId) {
                appAlert('You can only edit your own profile.');
                window.location.href = 'profile.html';
                return;
            }

            populateForm(currentUserData);
            storeOriginalFormData();

            saveBtn.textContent = 'Save Changes';
            saveBtn.disabled = false;
        } catch (err) {
            console.error('Init error:', err);
            appAlert('Error loading user data.');
            window.location.href = 'profile.html';
        }
    }

    async function fetchUserData(id) {
        const res = await fetch(`../backend/api/get_user.php?userId=${id}`);
        const data = await res.json();
        return data.success ? data.user : null;
    }

    async function loadRoleOptions(selectedRole = '') {
        if (!userRoleSelect) return;

        const applyOptions = (roles) => {
            userRoleSelect.innerHTML = '<option value="">Select Role</option>';
            roles.filter((role) => {
                const key = String(role?.key || '').toLowerCase();
                if (key === 'super_admin') return isSuperAdmin || selectedRole === 'super_admin';
                if (key === 'admin') return isSuperAdmin || selectedRole === 'admin';
                return true;
            }).forEach((role) => {
                const option = document.createElement('option');
                option.value = role.key;
                option.textContent = role.label;
                userRoleSelect.appendChild(option);
            });
            if (selectedRole) {
                userRoleSelect.value = String(selectedRole).toLowerCase();
            }
        };

        try {
            const response = await fetch('../backend/api/get_roles.php?active_only=1', {
                credentials: 'include',
                cache: 'no-store'
            });
            const data = await response.json();
            if (!response.ok || !data.success || !Array.isArray(data.roles) || !data.roles.length) {
                applyOptions(staticRoles);
                return;
            }

            const roleOptions = data.roles
                .filter((role) => role && role.role_key)
                .map((role) => ({
                    key: String(role.role_key).toLowerCase(),
                    label: String(role.role_label || role.role_key)
                }));

            applyOptions(roleOptions.length ? roleOptions : staticRoles);
        } catch (error) {
            console.warn('Unable to load roles dynamically:', error);
            applyOptions(staticRoles);
        }
    }

    function populateTitleOptions(selectedTitle = '') {
        if (!userTitleSelect) return;

        const normalizedSelected = String(selectedTitle || '').trim();
        userTitleSelect.innerHTML = '<option value="">Select Title</option>';

        officialTitles.forEach((title) => {
            const option = document.createElement('option');
            option.value = title;
            option.textContent = title;
            userTitleSelect.appendChild(option);
        });

        if (normalizedSelected && !officialTitles.includes(normalizedSelected)) {
            const fallbackOption = document.createElement('option');
            fallbackOption.value = normalizedSelected;
            fallbackOption.textContent = normalizedSelected;
            userTitleSelect.appendChild(fallbackOption);
        }

        if (normalizedSelected) {
            userTitleSelect.value = normalizedSelected;
        }
    }

    function populateForm(u) {
        document.getElementById('userId').value = u.userId || '';
        document.getElementById('userTitle').value = u.userTitle || '';
        document.getElementById('userName').value = u.userName || '';
        document.getElementById('userEmail').value = u.userEmail || '';
        document.getElementById('phoneNo').value = u.phoneNo || '';

        if (isAdmin) document.getElementById('userRole').value = u.userRole || '';
        profilePreview.src = resolveImagePath(u.userPhoto || 'images/default-user.png');
    }

    function storeOriginalFormData() {
        originalFormData = {
            userTitle: document.getElementById('userTitle').value,
            userName: document.getElementById('userName').value,
            userEmail: document.getElementById('userEmail').value,
            phoneNo: document.getElementById('phoneNo').value,
            userRole: isAdmin ? document.getElementById('userRole').value : '',
            userPhoto: currentUserData.userPhoto || 'images/default-user.png'
        };
    }

    function hasFormChanges() {
        const cur = {
            userTitle: document.getElementById('userTitle').value,
            userName: document.getElementById('userName').value,
            userEmail: document.getElementById('userEmail').value,
            phoneNo: document.getElementById('phoneNo').value,
            userRole: isAdmin ? document.getElementById('userRole').value : '',
            userPhoto: newProfilePicture ? 'new_image' : (currentUserData.userPhoto || 'images/default-user.png')
        };

        return JSON.stringify(cur) !== JSON.stringify(originalFormData) || isPasswordChangeRequested;
    }

    function resolveImagePath(path) {
        if (!path || path.includes('default-user')) return 'images/default-user.png';
        if (path.startsWith('http') || path.startsWith('data:')) return path;
        if (path.includes('uploads/')) {
            const filename = path.split('/').pop();
            return `../backend/api/get_image.php?file=${filename}&type=profile`;
        }
        return `images/${path}`;
    }

    // Validation
    function togglePasswordFields() {
        isPasswordChangeRequested = !isPasswordChangeRequested;
        passwordFields.classList.toggle('visible', isPasswordChangeRequested);
        passwordToggle.textContent = isPasswordChangeRequested ? 'Cancel Password Change' : 'Change Password';
    }

    function checkPasswordMatch() {
        const np = newPassword.value, cp = confirmPassword.value;
        passwordMatch.style.display = np && cp && np === cp ? 'block' : 'none';
        passwordMismatch.style.display = np && cp && np !== cp ? 'block' : 'none';
    }

    function validateForm() {
        // Validate phone number format
        if (phoneNoInput) {
            const normalizedPhone = normalizePhone(phoneNoInput.value);
            if (!normalizedPhone) {
                appAlert('Please enter a valid phone number (e.g. +256700123456, 0770123456, 0312123456, 0800123456)');
                return false;
            }
            phoneNoInput.value = normalizedPhone;
        }

        // Password checks
        if (isPasswordChangeRequested) {
            const np = newPassword.value, cp = confirmPassword.value;
            if (!np || !cp) return appAlert('Please fill both password fields'), false;
            if (np !== cp) return appAlert('Passwords do not match'), false;
            if (!/[a-z]/.test(np) || !/[A-Z]/.test(np) || !/\d/.test(np) || np.length < 6)
                return appAlert('Password must contain uppercase, lowercase, numbers (min 6 chars)'), false;
        }
        return true;
    }

    // Handlers
    function handleProfilePictureChange(e) {
        const file = e.target.files[0];
        if (!file) return;
        if (!file.type.match('image.*')) return appAlert('Please select a valid image');
        if (file.size > 5 * 1024 * 1024) return appAlert('Max image size 5MB');

        newProfilePicture = file;
        const reader = new FileReader();
        reader.onload = ev => (profilePreview.src = ev.target.result);
        reader.readAsDataURL(file);
    }

    function handleFormSubmit(e) {
        e.preventDefault();
        if (!hasFormChanges()) return appAlert('No changes to save.');
        if (!validateForm()) return;

        (isAdmin ? adminModal : passwordModal).style.display = 'flex';
    }

    async function confirmChangesWithPassword() {
        const pwd = currentPassword.value;
        if (!pwd) return appAlert('Please enter your current password');

        confirmWithPassword.innerHTML = '<span class="loading"></span> Verifying...';
        const valid = await verifyCurrentPassword(pwd);

        if (valid) {
            await saveUserChanges();
            closeAllModals();
        } else {
            appAlert('Invalid password.');
            currentPassword.value = '';
        }
        confirmWithPassword.textContent = 'Confirm Changes';
    }

    async function confirmChangesAsAdmin() {
        await saveUserChanges();
        closeAllModals();
    }

    async function saveUserChanges() {
        try {
            saveBtn.innerHTML = '<span class="loading"></span> Saving...';
            saveBtn.disabled = true;

            const formData = new FormData(editUserForm);
            if (isPasswordChangeRequested) formData.append('newPassword', newPassword.value);
            if (newProfilePicture) formData.append('profilePicture', newProfilePicture);
            if (!isAdmin) formData.delete('userRole');

            const res = await fetch('../backend/api/update_user.php', { method: 'POST', body: formData });
            const data = await res.json();

            if (data.success) {
                appAlert('User updated successfully!');
                window.location.href = isAdmin ? 'users.html' : 'profile.html';
            } else throw new Error(data.message);
        } catch (err) {
            appAlert('Error saving user data.');
            console.error(err);
        } finally {
            saveBtn.textContent = 'Save Changes';
            saveBtn.disabled = false;
        }
    }

    async function verifyCurrentPassword(password) {
        if (!currentUserId) {
            appAlert('Unable to determine user ID. Please login again.');
            window.location.href = 'login.html';
            return false;
        }
        const res = await fetch('../backend/api/verify_password.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ userId: currentUserId, password })
        });
        const data = await res.json();
        return data.success;
    }

    async function handleCancel() {
        if (hasFormChanges()) {
            const shouldDiscard = await appConfirm('Discard unsaved changes?', {
                title: 'Unsaved Changes',
                confirmText: 'Discard'
            });
            if (!shouldDiscard) return;
        }
        window.location.href = isAdmin ? 'users.html' : 'profile.html';
    }

    function closeAllModals() {
        [passwordModal, adminModal].forEach(m => (m.style.display = 'none'));
        currentPassword.value = '';
    }
});


