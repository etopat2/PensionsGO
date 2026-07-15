// frontend/js/register_user.js

// Redirect if not logged in
if (sessionStorage.getItem('isLoggedIn') !== 'true') {
  window.location.replace('login.html');
}

document.addEventListener('DOMContentLoaded', () => {
  const DEFAULT_USER_PASSWORD = 'Prisons123';
  const form = document.getElementById('registerForm');
  const submitBtn = form.querySelector('button[type="submit"]');
  const passwordInput = document.getElementById('userPassword');
  if (passwordInput && !passwordInput.value) {
    passwordInput.value = DEFAULT_USER_PASSWORD;
  }

  const titles = [
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

  // Populate dropdowns dynamically
  const currentRawRole = String(
    sessionStorage.getItem('userRole')
    || localStorage.getItem('userRole')
    || ''
  ).toLowerCase();
  const isSuperAdmin = currentRawRole === 'super_admin';

  const filterAssignableRoles = (roles) => roles.filter((role) => {
    const key = String(role?.key || '').toLowerCase();
    if (key === 'super_admin') return isSuperAdmin;
    if (key === 'admin') return isSuperAdmin;
    return true;
  });

  const populateSelect = (id, items) => {
    const el = document.getElementById(id);
    if (!el) return;
    el.innerHTML = '<option value="" disabled selected>-- Select --</option>';
    const normalizedItems = id === 'userRole' ? filterAssignableRoles(items) : items;
    normalizedItems.forEach(item => {
      const opt = document.createElement('option');
      if (typeof item === 'string') {
        opt.value = item;
        opt.textContent = item;
      } else {
        opt.value = item.key;
        opt.textContent = item.label;
      }
      el.appendChild(opt);
    });
  };
  populateSelect('userTitle', titles);

  const loadRoles = async () => {
    try {
      const response = await fetch('../backend/api/get_roles.php?active_only=1', {
        credentials: 'include',
        cache: 'no-store'
      });
      const data = await response.json();
      if (!response.ok || !data.success || !Array.isArray(data.roles) || data.roles.length === 0) {
        populateSelect('userRole', staticRoles);
        return;
      }

      const roles = data.roles
        .filter(role => role && role.role_key)
        .map(role => ({
          key: String(role.role_key).toLowerCase(),
          label: String(role.role_label || role.role_key)
        }));

      if (!roles.length) {
        populateSelect('userRole', staticRoles);
        return;
      }
      populateSelect('userRole', roles);
    } catch (error) {
      console.warn('Unable to load dynamic roles:', error);
      populateSelect('userRole', staticRoles);
    }
  };

  loadRoles();

  // Utility to show inline messages
  const showMessage = (msg, type = 'info') => {
    let box = document.getElementById('registerMessageBox');
    if (!box) {
      box = document.createElement('div');
      box.id = 'registerMessageBox';
      form.parentElement.insertBefore(box, form);
    }
    box.innerHTML = `<div class="message ${type}">${msg}</div>`;
    setTimeout(() => { box.innerHTML = ''; }, 8000);
  };

  // Password and email validation
  const passwordValid = (pwd) => (
    /[a-z]/.test(pwd) && /[A-Z]/.test(pwd) && /\d/.test(pwd) && pwd.length >= 6
  );

  const emailValid = (email) => /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email);

  const normalizePhone = (value) => {
    const input = String(value || "").trim().replace(/[\s().-]/g, "");
    if (!input) return null;
    if (/^00[1-9]\d{7,14}$/.test(input)) return `+${input.slice(2)}`;
    if (/^\+[1-9]\d{7,14}$/.test(input)) return input;
    if (/^0\d{9}$/.test(input)) return `+256${input.slice(1)}`;
    if (/^[1-9]\d{7,14}$/.test(input)) return `+${input}`;
    return null;
  };

  // Form submission
  form.addEventListener('submit', async (e) => {
    e.preventDefault();
    submitBtn.disabled = true;
    showMessage('Processing registration... please wait', 'info');

    const formData = new FormData(form);

    // --- Client-side validations ---
    const pwd = formData.get('userPassword');
    const email = formData.get('userEmail');
    const phone = formData.get('phoneNo');

    if (!passwordValid(pwd)) {
      showMessage('❌ Password must include uppercase, lowercase, and a number (min 6 chars).', 'error');
      submitBtn.disabled = false;
      return;
    }

    if (!emailValid(email)) {
      showMessage('❌ Please enter a valid email address.', 'error');
      submitBtn.disabled = false;
      return;
    }

    const normalizedPhone = normalizePhone(phone);
    if (!normalizedPhone) {
      showMessage('❌ Enter a valid phone number (e.g., +256700123456, 0770123456, 0312123456, 0800123456).', 'error');
      submitBtn.disabled = false;
      return;
    }
    formData.set('phoneNo', normalizedPhone);

    // --- Send to backend ---
    try {
      const response = await fetch('../backend/api/register_user.php', {
        method: 'POST',
        body: formData,
      });

      const data = await response.json();

      if (response.ok && data.success) {
        showMessage(`✅ ${data.message}<br>Reference Code: <strong>${data.referenceCode}</strong>`, 'success');
        form.reset();
        if (passwordInput) passwordInput.value = DEFAULT_USER_PASSWORD;
      } else {
        showMessage(`❌ ${data.message || 'Server error occurred.'}`, 'error');
      }
    } catch (error) {
      console.error('Register error:', error);
      showMessage('⚠️ Network error: Could not connect to server.', 'error');
    } finally {
      submitBtn.disabled = false;
    }
  });
});

