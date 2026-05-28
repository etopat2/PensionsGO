document.addEventListener('DOMContentLoaded', () => {
  const form = document.getElementById('feedbackForm');
  if (!form) return;

  const typeSelect = document.getElementById('feedbackType');
  const topicCards = Array.from(document.querySelectorAll('.feedback-topic-card'));
  const intro = document.getElementById('feedbackIntroText');
  const banner = document.getElementById('feedbackBanner');
  const submitBtn = document.getElementById('feedbackSubmitBtn');
  const resetBtn = document.getElementById('feedbackResetBtn');
  const nameInput = document.getElementById('feedbackName');
  const emailInput = document.getElementById('feedbackEmail');
  const phoneInput = document.getElementById('feedbackPhone');
  const audienceSelect = document.getElementById('feedbackAudience');
  const messageInput = document.getElementById('feedbackMessage');
  const charCount = document.getElementById('feedbackCharCount');
  const formModal = document.getElementById('feedbackFormModal');
  const openFormBtn = document.getElementById('feedbackOpenFormBtn');
  const formModalClose = document.getElementById('feedbackFormModalClose');
  const formModalDismiss = document.getElementById('feedbackFormModalDismiss');
  const modal = document.getElementById('feedbackModal');
  const modalClose = document.getElementById('feedbackModalClose');
  const modalDismiss = document.getElementById('feedbackModalDismiss');
  const modalMessage = document.getElementById('feedbackModalMessage');
  const modalReference = document.getElementById('feedbackReferenceNo');
  const supportEmail = document.getElementById('feedbackSupportEmail');
  const supportPhone = document.getElementById('feedbackSupportPhone');
  const accessPlatformBtn = document.getElementById('feedbackAccessPlatformBtn');
  let publicSettings = {};

  applyLocalSessionCtaVisibility();
  applyAuthenticatedCtaVisibility().catch((error) => {
    console.error('Unable to determine feedback CTA visibility:', error);
  });
  const typeDescriptions = {
    general_feedback: 'Use this for broad service feedback, navigation clarity, or a general observation about the platform.',
    bug_report: 'Describe exactly what failed, the page involved, and what you expected to happen instead.',
    data_correction: 'Reference the affected file number, applicant, registry record, or payroll item so the issue can be checked accurately.',
    service_request: 'State what assistance you need and the stage or module where support is required.',
    suggestion: 'Focus on the practical improvement and the problem it would solve for staff, pensioners, or the public.',
    complaint: 'State the concern factually, including the service area affected and any prior follow-up if applicable.',
    pensioner_support: 'Use this for pensioner-facing concerns such as status visibility, payroll understanding, claims guidance, or account clarification.'
  };

  hydrateContacts().catch((error) => {
    console.error('Unable to load public settings for feedback page:', error);
  });
  prefillIdentity();
  syncTopicCards(typeSelect.value);
  updateIntro(typeSelect.value);
  updateCharCount();

  topicCards.forEach((card) => {
    card.addEventListener('click', () => {
      const nextType = card.dataset.feedbackType || 'general_feedback';
      typeSelect.value = nextType;
      syncTopicCards(nextType);
      updateIntro(nextType);
      const subject = document.getElementById('feedbackSubject');
      if (subject && !subject.value.trim()) {
        subject.focus();
      }
    });
  });

  typeSelect?.addEventListener('change', () => {
    syncTopicCards(typeSelect.value);
    updateIntro(typeSelect.value);
  });

  audienceSelect?.addEventListener('change', () => {
    applyFeedbackAccessPolicy();
  });

  messageInput?.addEventListener('input', updateCharCount);
  openFormBtn?.addEventListener('click', openFormModal);
  formModalClose?.addEventListener('click', closeFormModal);
  formModalDismiss?.addEventListener('click', closeFormModal);
  formModal?.addEventListener('click', (event) => {
    if (event.target === formModal) {
      closeFormModal();
    }
  });

  resetBtn?.addEventListener('click', () => {
    window.setTimeout(() => {
      prefillIdentity();
      typeSelect.value = 'general_feedback';
      syncTopicCards('general_feedback');
      updateIntro('general_feedback');
      hideBanner();
      updateCharCount();
    }, 0);
  });

  form.addEventListener('submit', async (event) => {
    event.preventDefault();
    hideBanner();

    const payload = {
      feedback_type: typeSelect?.value || 'general_feedback',
      audience: audienceSelect?.value || 'public',
      full_name: String(nameInput?.value || '').trim(),
      email_address: String(emailInput?.value || '').trim(),
      phone_number: String(phoneInput?.value || '').trim(),
      subject: String(document.getElementById('feedbackSubject')?.value || '').trim(),
      message: String(messageInput?.value || '').trim(),
      page_context: String(document.getElementById('feedbackPageContext')?.value || 'feedback.html').trim(),
      website: String(document.getElementById('feedbackWebsite')?.value || '').trim()
    };

    const consent = document.getElementById('feedbackConsent');
    if (!consent?.checked) {
      showBanner('Please confirm the submission declaration before sending your feedback.', 'error');
      return;
    }

    if (!isAudienceEnabled(payload.audience)) {
      showBanner('Feedback submission is currently disabled for the selected audience.', 'error');
      return;
    }

    if (!payload.full_name || !payload.subject || payload.message.length < 20) {
      showBanner('Provide your name, a clear subject, and enough detail in the message before submitting.', 'error');
      return;
    }

    submitBtn.disabled = true;
    submitBtn.textContent = 'Submitting...';

    try {
      const response = await fetch('../backend/api/submit_feedback.php', {
        method: 'POST',
        credentials: 'include',
        cache: 'no-store',
        headers: {
          'Accept': 'application/json',
          'Content-Type': 'application/json',
          'X-Requested-With': 'XMLHttpRequest'
        },
        body: JSON.stringify(payload)
      });
      const data = await readJson(response, 'Unable to submit feedback right now.');
      if (!response.ok || !data.success) {
        throw new Error(data.message || 'Unable to submit feedback right now.');
      }

      showBanner('Feedback submitted successfully. Use the reference number below if follow-up is needed.', 'success');
      closeFormModal({ preserveBodyLock: true });
      openModal(
        'Feedback submitted',
        data.message || 'Your submission has been recorded and routed for review.',
        data.referenceNo || '-'
      );
      form.reset();
      prefillIdentity();
      typeSelect.value = 'general_feedback';
      syncTopicCards('general_feedback');
      updateIntro('general_feedback');
      updateCharCount();
    } catch (error) {
      console.error('Feedback submission failed:', error);
      showBanner(error.message || 'Unable to submit feedback right now.', 'error');
    } finally {
      submitBtn.disabled = false;
      submitBtn.textContent = 'Submit Feedback';
    }
  });

  [modalClose, modalDismiss].forEach((button) => {
    button?.addEventListener('click', closeModal);
  });
  modal?.addEventListener('click', (event) => {
    if (event.target === modal) {
      closeModal();
    }
  });
  document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape' && formModal && !formModal.hidden) {
      closeFormModal();
      return;
    }
    if (event.key === 'Escape' && modal && !modal.hidden) {
      closeModal();
    }
  });

  function prefillIdentity() {
    const isLoggedIn = sessionStorage.getItem('isLoggedIn') === 'true';
    const role = String(sessionStorage.getItem('userRole') || '').trim().toLowerCase();
    const userName = String(sessionStorage.getItem('userName') || '').trim();
    const userEmail = String(sessionStorage.getItem('userEmail') || '').trim();
    const phone = String(sessionStorage.getItem('phoneNo') || '').trim();

    if (userName) nameInput.value = userName;
    if (userEmail) emailInput.value = userEmail;
    if (phone) phoneInput.value = phone;

    if (isLoggedIn) {
      const nextAudience = role === 'pensioner' ? 'pensioner' : 'staff';
      if (audienceSelect) audienceSelect.value = nextAudience;
    } else if (audienceSelect) {
      audienceSelect.value = 'public';
    }
  }

  async function hydrateContacts() {
    const response = await fetch('../backend/api/get_public_settings.php', {
      cache: 'no-store',
      headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
    });
    const data = await response.json().catch(() => ({ success: false }));
    if (!response.ok || !data.success || !data.settings) return;

    publicSettings = data.settings || {};
    const email = String(data.settings.support_email || '').trim();
    const phone = String(data.settings.support_phone || '').trim();
    if (email && supportEmail) {
      supportEmail.textContent = email;
      supportEmail.href = `mailto:${email}`;
    }
    if (phone && supportPhone) {
      supportPhone.textContent = phone;
      supportPhone.href = `tel:${phone.replace(/\s+/g, '')}`;
    }

    applyFeedbackAccessPolicy();
  }

  async function applyAuthenticatedCtaVisibility() {
    try {
      const response = await fetch('../backend/api/check_session.php', {
        credentials: 'include',
        cache: 'no-store',
        headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
      });
      const data = await response.json().catch(() => ({ success: false }));
      const hasActiveSession = Boolean(response.ok && data && data.success);
      if (!hasActiveSession) return;

      hideAccessPlatformButton();
    } catch (error) {
      console.error('Feedback session CTA check failed:', error);
    }
  }

  function applyLocalSessionCtaVisibility() {
    const hasLocalSession = sessionStorage.getItem('isLoggedIn') === 'true'
      || Boolean(sessionStorage.getItem('userRole') || localStorage.getItem('userRole'));
    if (hasLocalSession) {
      hideAccessPlatformButton();
    }
  }

  function hideAccessPlatformButton() {
    if (accessPlatformBtn) {
      accessPlatformBtn.style.display = 'none';
    }
  }

  function isAudienceEnabled(audience) {
    const keyMap = {
      public: 'feedback_public_enabled',
      staff: 'feedback_staff_enabled',
      pensioner: 'feedback_pensioner_enabled'
    };
    const key = keyMap[String(audience || '').trim().toLowerCase()] || 'feedback_public_enabled';
    return publicSettings[key] !== false;
  }

  function applyFeedbackAccessPolicy() {
    const selectedAudience = String(audienceSelect?.value || 'public').trim().toLowerCase();
    const enabled = isAudienceEnabled(selectedAudience);
    if (submitBtn) submitBtn.disabled = !enabled;
    if (!enabled) {
      showBanner('Feedback submission is currently disabled for the selected audience. Please contact support for assistance.', 'error');
    } else if (banner?.classList.contains('error') && banner.textContent.includes('currently disabled')) {
      hideBanner();
    }
  }

  function syncTopicCards(type) {
    topicCards.forEach((card) => {
      card.classList.toggle('active', card.dataset.feedbackType === type);
    });
  }

  function updateIntro(type) {
    if (!intro) return;
    intro.textContent = typeDescriptions[type] || typeDescriptions.general_feedback;
  }

  function updateCharCount() {
    if (!charCount || !messageInput) return;
    charCount.textContent = String(messageInput.value.length);
  }

  function showBanner(message, tone) {
    if (!banner) return;
    banner.hidden = false;
    banner.className = `feedback-banner ${tone === 'success' ? 'success' : 'error'}`;
    banner.innerHTML = `<strong>${tone === 'success' ? 'Submission recorded' : 'Unable to continue'}</strong><p>${escapeHtml(message)}</p>`;
  }

  function hideBanner() {
    if (!banner) return;
    banner.hidden = true;
    banner.className = 'feedback-banner';
    banner.innerHTML = '';
  }

  function openModal(title, message, referenceNo) {
    if (!modal || !modalMessage || !modalReference) return;
    const titleNode = document.getElementById('feedbackModalTitle');
    if (titleNode) titleNode.textContent = title;
    modalMessage.textContent = message;
    modalReference.textContent = referenceNo;
    modal.hidden = false;
    syncModalBodyState();
  }

  function closeModal() {
    if (!modal) return;
    modal.hidden = true;
    syncModalBodyState();
  }

  function openFormModal() {
    if (!formModal) return;
    formModal.hidden = false;
    syncModalBodyState();
    window.setTimeout(() => {
      typeSelect?.focus();
    }, 60);
  }

  function closeFormModal(options = {}) {
    if (!formModal) return;
    formModal.hidden = true;
    if (!options.preserveBodyLock) {
      syncModalBodyState();
    }
  }

  function syncModalBodyState() {
    const hasOpenModal = (formModal && !formModal.hidden) || (modal && !modal.hidden);
    document.body.classList.toggle('modal-open', Boolean(hasOpenModal));
  }
});

async function readJson(response, fallbackMessage) {
  const text = await response.text();
  if (!text) {
    throw new Error('Server returned empty response.');
  }
  try {
    return JSON.parse(text);
  } catch (error) {
    console.error('Failed to parse JSON response:', error, text);
    throw new Error(fallbackMessage);
  }
}

function escapeHtml(value) {
  return String(value ?? '')
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#039;');
}
