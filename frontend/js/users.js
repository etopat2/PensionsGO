/**
 * 
 * Users Management Script
 * 
 * Handles:
 *  - Fetching and displaying user list
 *  - Role-based access control
 *  - Filtering users by role
 *  - Adding, editing, and deleting users
 *  - Includes phone number column display
 * 
 */

document.addEventListener('DOMContentLoaded', () => {
    let isInitialized = false;

    // Dom Elements
    const roleFilter = document.getElementById('roleFilter');
    const accountTypeFilter = document.getElementById('accountTypeFilter');
    const userSearchFilter = document.getElementById('userSearchFilter');
    const clearFilterButton = document.getElementById('clearFilterButton');
    const addUserButton = document.getElementById('addUserButton');
    const usersTableBody = document.getElementById('usersTableBody');
    const deleteModal = document.getElementById('deleteModal');
    const userDetailsModal = document.getElementById('userDetailsModal');
    const userDetailsBody = document.getElementById('userDetailsBody');
    const userDetailsActions = document.getElementById('userDetailsActions');
    const closeUserDetailsModal = document.getElementById('closeUserDetailsModal');
    const deleteUserName = document.getElementById('deleteUserName');
    const confirmDelete = document.getElementById('confirmDelete');
    const closeModalButtons = document.querySelectorAll('.close-modal, .modal-btn.cancel');
    const usersPagination = document.getElementById('usersPagination');
    const usersPaginationSummary = document.getElementById('usersPaginationSummary');
    const usersPaginationControls = document.getElementById('usersPaginationControls');

    let currentUserToDelete = null;
    let allUsers = [];
    let filteredUsers = [];
    let roleLabels = {};
    let availableRoles = [];
    let isMobileView = window.matchMedia('(max-width: 768px)').matches;
    let currentPage = 1;
    const pageSize = 12;

    function getCurrentUserContext() {
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

    function isAdminAccountRole(role) {
        return ['admin', 'super_admin'].includes(String(role || '').toLowerCase());
    }

    function canModifyUserAccount(user) {
        const actor = getCurrentUserContext();
        if (!actor.isAdmin) return false;
        if (!isAdminAccountRole(user?.userRole)) return true;
        return actor.isSuperAdmin && String(user?.userRole || '').toLowerCase() !== 'super_admin';
    }

    function canDeleteUserAccount(user) {
        const actor = getCurrentUserContext();
        if (!canModifyUserAccount(user)) return false;
        return String(user?.userId || '') !== String(actor.id || '');
    }

    // Initialization
    if (!isInitialized) {
        isInitialized = true;
        initializeUsersPage();
    }

    // Redirect if not logged in
    if (sessionStorage.getItem('isLoggedIn') !== 'true') {
        window.location.replace('login.html');
    }

    /**
     * 
     * Initialize page and event listeners
     * 
     */
    function initializeUsersPage() {
        if (roleFilter) roleFilter.addEventListener('change', filterUsers);
        if (userSearchFilter) userSearchFilter.addEventListener('input', filterUsers);
        if (accountTypeFilter) {
            accountTypeFilter.addEventListener('change', async () => {
                await populateUsersTable();
                filterUsers();
            });
        }
        if (clearFilterButton) clearFilterButton.addEventListener('click', clearFilter);
        if (addUserButton) addUserButton.addEventListener('click', () => {
            window.location.href = 'register_user.html';
        });

        // Modal event listeners
        if (confirmDelete) confirmDelete.addEventListener('click', deleteUser);
        if (closeUserDetailsModal) closeUserDetailsModal.addEventListener('click', () => {
            if (userDetailsModal) userDetailsModal.style.display = 'none';
        });
        closeModalButtons.forEach(button => {
            button.addEventListener('click', () => {
                if (deleteModal) deleteModal.style.display = 'none';
            });
        });

        // Close modal on click outside
        window.addEventListener('click', (e) => {
            if (e.target === deleteModal) deleteModal.style.display = 'none';
            if (e.target === userDetailsModal) userDetailsModal.style.display = 'none';
        });

        // Load user data
        populateUsersTable();

        window.addEventListener('resize', () => {
            const mobileNow = window.matchMedia('(max-width: 768px)').matches;
            if (mobileNow !== isMobileView) {
                isMobileView = mobileNow;
                renderUsersPage();
            }
        });
    }

    /**
     * 
     * Fetch and populate the users table
     * 
     */
    async function populateUsersTable() {
        try {
            if (!usersTableBody) return;
            usersTableBody.innerHTML = '<tr><td colspan="6" class="no-users">Loading users...</td></tr>';

            // Fetch users from API
            const payload = await fetchUsers();
            allUsers = Array.isArray(payload.users) ? payload.users : [];
            roleLabels = payload.role_labels && typeof payload.role_labels === 'object' ? payload.role_labels : {};
            availableRoles = Array.isArray(payload.roles) ? payload.roles : [];
            populateRoleFilterOptions();

            if (allUsers && allUsers.length > 0) {
                filterUsers();
            } else {
                filteredUsers = [];
                currentPage = 1;
                renderUsersPagination();
                usersTableBody.innerHTML = '<tr><td colspan="6" class="no-users">No users found in the system.</td></tr>';
            }
        } catch (error) {
            console.error('Error populating users table:', error);
            if (usersTableBody) {
                filteredUsers = [];
                currentPage = 1;
                renderUsersPagination();
                usersTableBody.innerHTML = '<tr><td colspan="6" class="no-users">Error loading users. Please try again.</td></tr>';
            }
        }
    }

    /**
     * 
     * Fetch users from backend API
     * 
     */
    async function fetchUsers() {
        try {
            const accountType = accountTypeFilter ? String(accountTypeFilter.value || '').trim().toLowerCase() : '';
            const query = new URLSearchParams();
            if (accountType === 'staff' || accountType === 'pensioner') {
                query.set('account_type', accountType);
            }

            const url = `../backend/api/get_users.php${query.toString() ? `?${query.toString()}` : ''}`;
            const response = await fetch(url, {
                credentials: 'include',
                cache: 'no-store'
            });
            if (!response.ok) throw new Error('Failed to fetch users');

            const data = await response.json();
            if (data.success) {
                return data;
            } else {
                throw new Error(data.message || 'Failed to load users');
            }
        } catch (error) {
            console.error('Error fetching users:', error);
            throw error;
        }
    }

    /**
     * 
     * Display users in the table
     * 
     */
    function displayUsers(users) {
        if (!usersTableBody) return;

        if (users.length === 0) {
            renderUsersPagination();
            usersTableBody.innerHTML = '<tr><td colspan="6" class="no-users">No users found.</td></tr>';
            return;
        }

        usersTableBody.innerHTML = '';

        users.forEach(user => {
            const tr = document.createElement('tr');

            // === Profile ===
            const profileCell = document.createElement('td');
            const profileImg = document.createElement('img');
            const profileSrc = resolveImagePath(user.userPhoto || 'images/default-user.png');
            profileImg.src = profileSrc;
            profileImg.alt = 'Profile';
            profileImg.className = 'profile-thumbnail';
            profileImg.onerror = () => profileImg.src = 'images/default-user.png';
            profileCell.appendChild(profileImg);

            // === Name ===
            const nameCell = document.createElement('td');
            nameCell.innerHTML = `
                <div class="user-name-cell">
                    <div class="user-name-main">${escapeHtml(user.userName || 'N/A')}</div>
                </div>
            `;

            // === Email ===
            const emailCell = document.createElement('td');
            emailCell.textContent = user.userEmail || 'N/A';
            emailCell.classList.add('desktop-only-cell', 'email-cell');

            // === Phone Number ===
            const phoneCell = document.createElement('td');
            phoneCell.textContent = user.phoneNo || 'N/A';
            phoneCell.classList.add('desktop-only-cell', 'phone-cell');

            // === Role ===
            const roleCell = document.createElement('td');
            roleCell.textContent = formatRoleLabel(user.userRole, user.roleLabel);
            roleCell.classList.add('user-role-cell');

            // === Actions ===
            const actionsCell = document.createElement('td');
            actionsCell.classList.add('desktop-only-cell');
            const actionButtons = document.createElement('div');
            actionButtons.className = 'action-buttons';

            const editButton = document.createElement('button');
            editButton.textContent = 'Edit';
            editButton.className = 'edit-button';
            editButton.disabled = !canModifyUserAccount(user);
            editButton.title = editButton.disabled ? 'Only the super administrator can modify administrator accounts.' : 'Edit user';
            editButton.onclick = (event) => {
                event.stopPropagation();
                if (!canModifyUserAccount(user)) {
                    appAlert('Only the super administrator can modify administrator accounts.');
                    return;
                }
                window.location.href = `edit_user.html?user_id=${user.userId}`;
            };
            actionButtons.appendChild(editButton);

            if (canDeleteUserAccount(user)) {
                const deleteButton = document.createElement('button');
                deleteButton.textContent = 'Delete';
                deleteButton.className = 'delete-button';
                deleteButton.onclick = (event) => {
                    event.stopPropagation();
                    showDeleteConfirmation(user);
                };
                actionButtons.appendChild(deleteButton);
            }

            actionsCell.appendChild(actionButtons);

            // Append all cells to row
            tr.appendChild(profileCell);
            tr.appendChild(nameCell);
            tr.appendChild(emailCell);
            tr.appendChild(phoneCell);
            tr.appendChild(roleCell);
            tr.appendChild(actionsCell);

            if (isMobileView) {
                tr.classList.add('user-row-clickable');
                tr.addEventListener('click', () => openUserDetailsModal(user));
            }

            usersTableBody.appendChild(tr);
        });

        renderUsersPagination();
    }

    function openUserDetailsModal(user) {
        if (!userDetailsModal || !userDetailsBody || !userDetailsActions) return;
        const photo = resolveImagePath(user.userPhoto || 'images/default-user.png');
        const title = String(user.userTitle || '').trim();
        const name = String(user.userName || 'N/A').trim();
        const normalizedTitle = title.replace(/\s+/g, ' ').trim();
        const titledName = normalizedTitle ? `${normalizedTitle} - ${name}` : name;

        userDetailsBody.innerHTML = `
            <div class="user-details-hero">
                <img class="user-details-avatar" src="${photo}" alt="${escapeHtml(user.userName || 'User')}" onerror="this.src='images/default-user.png'">
                <div class="user-details-identity">
                    <h3>${escapeHtml(titledName)}</h3>
                    <p>${escapeHtml(formatRoleLabel(user.userRole, user.roleLabel))}</p>
                </div>
            </div>
            <div class="user-details-grid">
                <div class="detail-item"><span class="detail-label">Email</span><strong>${escapeHtml(user.userEmail || 'N/A')}</strong></div>
                <div class="detail-item"><span class="detail-label">Phone</span><strong>${escapeHtml(user.phoneNo || 'N/A')}</strong></div>
            </div>
        `;

        const canEdit = canModifyUserAccount(user);
        const canDelete = canDeleteUserAccount(user);
        userDetailsActions.innerHTML = `
            <button class="modal-btn cancel" id="closeUserDetailsBtn">Close</button>
            ${canEdit ? '<button class="modal-btn confirm" id="editUserDetailsBtn">Edit</button>' : ''}
            ${canDelete ? '<button class="modal-btn confirm danger-btn" id="deleteUserDetailsBtn">Delete</button>' : ''}
        `;

        const closeBtn = document.getElementById('closeUserDetailsBtn');
        const editBtn = document.getElementById('editUserDetailsBtn');
        const deleteBtn = document.getElementById('deleteUserDetailsBtn');

        if (closeBtn) {
            closeBtn.addEventListener('click', () => {
                userDetailsModal.style.display = 'none';
            });
        }
        if (editBtn) {
            editBtn.addEventListener('click', () => {
                window.location.href = `edit_user.html?user_id=${user.userId}`;
            });
        }
        if (deleteBtn) {
            deleteBtn.addEventListener('click', () => {
                userDetailsModal.style.display = 'none';
                showDeleteConfirmation(user);
            });
        }

        userDetailsModal.style.display = 'flex';
    }

    /**
     * 
     * Resolve image path for consistent loading
     * 
     */
    function resolveImagePath(imagePath) {
        if (!imagePath || imagePath === 'images/default-user.png' || imagePath === 'default-user.png') {
            return 'images/default-user.png';
        }

        if (imagePath.startsWith('http://') || imagePath.startsWith('https://') || imagePath.startsWith('data:')) {
            return imagePath;
        }

        if (imagePath.includes('uploads/') || imagePath.includes('backend/uploads/')) {
            const filename = imagePath.split('/').pop();
            return `../backend/api/get_image.php?file=${filename}&type=profile`;
        }

        if (imagePath.startsWith('images/')) return imagePath;

        return `../backend/api/get_image.php?file=${imagePath}&type=profile`;
    }

    /**
     * 
     * Filter users by selected role
     * 
     */
    function filterUsers() {
        const selectedRole = roleFilter ? roleFilter.value : '';
        const selectedAccountType = accountTypeFilter ? accountTypeFilter.value : '';
        const searchPhrase = (userSearchFilter ? userSearchFilter.value : '').trim().toLowerCase();
        const normalizedRole = String(selectedRole || '').toLowerCase();

        filteredUsers = allUsers.filter(user => {
            const userRole = String(user.userRole || '').toLowerCase();
            const roleMatch = normalizedRole ? userRole === normalizedRole : true;
            const typeMatch = selectedAccountType
                ? (selectedAccountType === 'staff' ? userRole !== 'pensioner' : userRole === 'pensioner')
                : true;

            const searchable = [
                user.userTitle || '',
                user.userName || '',
                user.userEmail || '',
                user.phoneNo || '',
                user.userRole || '',
                user.roleLabel || ''
            ].join(' ').toLowerCase();
            const searchMatch = searchPhrase ? searchable.includes(searchPhrase) : true;

            return roleMatch && typeMatch && searchMatch;
        });
        currentPage = 1;
        renderUsersPage();
    }

    /**
     * 
     * Clear filter and reload all users
     * 
     */
    async function clearFilter() {
        if (roleFilter) roleFilter.value = '';
        if (accountTypeFilter) accountTypeFilter.value = '';
        if (userSearchFilter) userSearchFilter.value = '';
        await populateUsersTable();
        filterUsers();
    }

    function populateRoleFilterOptions() {
        if (!roleFilter) return;
        const current = roleFilter.value || '';

        let roles = availableRoles
            .filter(role => role && role.role_key)
            .map(role => ({
                key: String(role.role_key).toLowerCase(),
                label: String(role.role_label || formatRoleLabel(role.role_key))
            }));

        if (!roles.length) {
            roles = Object.entries(roleLabels || {}).map(([key, label]) => ({
                key: String(key).toLowerCase(),
                label: String(label || formatRoleLabel(key))
            }));
        }

        if (!roles.length) {
            roles = [
                { key: 'super_admin', label: 'Super Administrator' },
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
        }

        roles.sort((a, b) => a.label.localeCompare(b.label));
        roleFilter.innerHTML = '<option value="">All Roles</option>' +
            roles.map(role => `<option value="${escapeHtml(role.key)}">${escapeHtml(role.label)}</option>`).join('');

        if (current) {
            const has = Array.from(roleFilter.options).some(option => option.value === current);
            if (has) roleFilter.value = current;
        }
    }

    function formatRoleLabel(role, providedLabel = '') {
        if (providedLabel) return providedLabel;
        const key = String(role || '').toLowerCase();
        if (roleLabels[key]) return roleLabels[key];
        const fallback = {
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
        return fallback[key] || (key ? key.replace(/_/g, ' ').replace(/\b\w/g, m => m.toUpperCase()) : 'N/A');
    }

    function escapeHtml(value) {
        const text = String(value ?? '');
        return text
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    /**
     * 
     * Show delete confirmation modal
     * 
     */
    function showDeleteConfirmation(user) {
        if (!canDeleteUserAccount(user)) {
            appAlert('Only the super administrator can delete administrator accounts.');
            return;
        }
        currentUserToDelete = user;
        if (deleteUserName) deleteUserName.textContent = user.userName || 'Unknown User';
        if (deleteModal) deleteModal.style.display = 'flex';
    }

    /**
     * 
     * Delete selected user from backend
     * 
     */
    async function deleteUser() {
        if (!currentUserToDelete) return;

        try {
            if (confirmDelete) confirmDelete.innerHTML = '<span class="loading"></span>';

            const response = await fetch('../backend/api/delete_user.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ userId: currentUserToDelete.userId })
            });

            const data = await response.json();

            if (data.success) {
                allUsers = allUsers.filter(u => u.userId !== currentUserToDelete.userId);
                filterUsers();
                if (deleteModal) deleteModal.style.display = 'none';
                appAlert(`User ${currentUserToDelete.userName} deleted successfully.`);
            } else {
                appAlert(`Error deleting user: ${data.message}`);
            }

            currentUserToDelete = null;
        } catch (error) {
            console.error('Error deleting user:', error);
            appAlert('An error occurred while deleting user. Please try again.');
        } finally {
            if (confirmDelete) confirmDelete.textContent = 'Delete';
        }
    }

    function renderUsersPage() {
        const totalPages = getTotalPages();
        if (currentPage > totalPages) {
            currentPage = totalPages;
        }

        const startIndex = (currentPage - 1) * pageSize;
        const visibleUsers = filteredUsers.slice(startIndex, startIndex + pageSize);
        displayUsers(visibleUsers);
    }

    function getTotalPages() {
        return Math.max(1, Math.ceil(filteredUsers.length / pageSize));
    }

    function renderUsersPagination() {
        if (!usersPagination || !usersPaginationSummary || !usersPaginationControls) {
            return;
        }

        if (!filteredUsers.length) {
            usersPagination.hidden = true;
            usersPaginationSummary.textContent = '';
            usersPaginationControls.innerHTML = '';
            return;
        }

        usersPagination.hidden = false;
        const totalPages = getTotalPages();
        const startItem = ((currentPage - 1) * pageSize) + 1;
        const endItem = Math.min(currentPage * pageSize, filteredUsers.length);
        usersPaginationSummary.textContent = `Showing ${startItem}-${endItem} of ${filteredUsers.length} users`;

        const buttons = buildPaginationButtons(currentPage, totalPages);
        usersPaginationControls.innerHTML = `
            <button type="button" class="users-page-btn users-page-nav" data-page-nav="prev" ${currentPage <= 1 ? 'disabled' : ''}>Previous</button>
            ${buttons.map((item) => item === 'ellipsis'
                ? '<button type="button" class="users-page-btn" aria-hidden="true" disabled>…</button>'
                : `<button type="button" class="users-page-btn ${item === currentPage ? 'is-active' : ''}" data-page-number="${item}">${item}</button>`
            ).join('')}
            <button type="button" class="users-page-btn users-page-nav" data-page-nav="next" ${currentPage >= totalPages ? 'disabled' : ''}>Next</button>
        `;

        usersPaginationControls.querySelectorAll('[data-page-number]').forEach((button) => {
            button.addEventListener('click', () => {
                currentPage = Number(button.dataset.pageNumber || currentPage);
                renderUsersPage();
                scrollUsersTableIntoView();
            });
        });

        usersPaginationControls.querySelectorAll('[data-page-nav]').forEach((button) => {
            button.addEventListener('click', () => {
                const direction = button.dataset.pageNav;
                if (direction === 'prev' && currentPage > 1) {
                    currentPage -= 1;
                } else if (direction === 'next' && currentPage < totalPages) {
                    currentPage += 1;
                } else {
                    return;
                }
                renderUsersPage();
                scrollUsersTableIntoView();
            });
        });
    }

    function buildPaginationButtons(page, totalPages) {
        if (totalPages <= 7) {
            return Array.from({ length: totalPages }, (_, index) => index + 1);
        }

        const pages = new Set([1, totalPages, page, page - 1, page + 1]);
        if (page <= 3) {
            pages.add(2);
            pages.add(3);
            pages.add(4);
        }
        if (page >= totalPages - 2) {
            pages.add(totalPages - 1);
            pages.add(totalPages - 2);
            pages.add(totalPages - 3);
        }

        const sortedPages = Array.from(pages)
            .filter((value) => value >= 1 && value <= totalPages)
            .sort((a, b) => a - b);

        const output = [];
        sortedPages.forEach((value, index) => {
            if (index > 0 && value - sortedPages[index - 1] > 1) {
                output.push('ellipsis');
            }
            output.push(value);
        });
        return output;
    }

    function scrollUsersTableIntoView() {
        const target = document.querySelector('.users-table-container');
        if (!target) return;
        const prefersReducedMotion = window.matchMedia?.('(prefers-reduced-motion: reduce)')?.matches;
        target.scrollIntoView({
            behavior: prefersReducedMotion ? 'auto' : 'smooth',
            block: 'start'
        });
    }
});

