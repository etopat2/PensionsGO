document.addEventListener('DOMContentLoaded', () => {
  const pageEl = document.querySelector('.document-viewer-page');
  const headerEl = document.querySelector('.document-viewer-header');
  const titleEl = document.getElementById('documentViewerTitle');
  const subtitleEl = document.getElementById('documentViewerSubtitle');
  const stateEl = document.getElementById('documentViewerState');
  const frameWrap = document.getElementById('documentViewerFrameWrap');
  const frameEl = document.getElementById('documentViewerFrame');
  const backBtn = document.getElementById('documentViewerBackBtn');
  const downloadBtn = document.getElementById('documentViewerDownloadBtn');

  const params = new URLSearchParams(window.location.search || '');
  const requestedSrc = params.get('src') || '';
  const requestedLabel = params.get('label') || 'Document Preview';
  const requestedBack = params.get('back') || '';
  const requestedDownload = params.get('download') || '';
  const requestedReturnKey = params.get('return_key') || '';
  const allowedEndpointSuffixes = [
    '/backend/api/view_staff_document.php',
    '/backend/api/view_message_attachment.php',
    '/backend/api/view_payroll_document.php',
    '/backend/api/download_data_artifact.php',
    '/backend/api/exports/export_budget_planning.php',
    '/backend/api/export_registry_recycle_bin.php',
    '/backend/api/exports/export_workflow_alerts.php',
    '/backend/api/exports/export_workflow_performance.php'
  ];

  const resolveAllowedUrl = (rawValue) => {
    try {
      const candidate = new URL(String(rawValue || ''), window.location.href);
      if (candidate.origin !== window.location.origin) {
        return null;
      }
      const pathname = String(candidate.pathname || '').toLowerCase();
      const isAllowed = allowedEndpointSuffixes.some((suffix) => pathname.endsWith(suffix));
      if (!isAllowed) {
        return null;
      }
      return candidate;
    } catch (_error) {
      return null;
    }
  };

  const resolveFrontendUrl = (rawValue, fallbackPath = 'dashboard.html') => {
    try {
      const candidate = new URL(String(rawValue || fallbackPath), window.location.href);
      if (candidate.origin !== window.location.origin) {
        return new URL(fallbackPath, window.location.href);
      }
      if (!candidate.pathname.includes('/frontend/')) {
        return new URL(fallbackPath, window.location.href);
      }
      return candidate;
    } catch (_error) {
      return new URL(fallbackPath, window.location.href);
    }
  };

  const setState = (message, type = 'neutral') => {
    stateEl.textContent = message;
    stateEl.classList.remove('hidden', 'is-error');
    if (type === 'error') {
      stateEl.classList.add('is-error');
    }
  };

  const clearState = () => {
    stateEl.textContent = '';
    stateEl.classList.add('hidden');
    stateEl.classList.remove('is-error');
  };

  const syncViewerLayout = () => {
    if (!pageEl || !frameWrap) {
      return;
    }

    const viewportHeight = window.innerHeight || document.documentElement.clientHeight || 0;
    const frameRect = frameWrap.getBoundingClientRect();
    const bottomPadding = window.innerWidth <= 640 ? 8 : 12;
    const availableHeight = Math.max(280, Math.floor(viewportHeight - frameRect.top - bottomPadding));
    document.documentElement.style.setProperty('--document-viewer-frame-height', `${availableHeight}px`);
  };

  const enterImmersiveMode = () => {
    if (!pageEl) {
      return;
    }
    if (!pageEl.classList.contains('is-immersive')) {
      pageEl.classList.add('is-immersive');
      requestAnimationFrame(syncViewerLayout);
    }
  };

  const exitImmersiveMode = () => {
    if (!pageEl) {
      return;
    }
    if (pageEl.classList.contains('is-immersive')) {
      pageEl.classList.remove('is-immersive');
      requestAnimationFrame(syncViewerLayout);
    }
  };

  const previewUrl = resolveAllowedUrl(requestedSrc);
  const downloadUrl = resolveAllowedUrl(requestedDownload) || previewUrl;
  const backUrl = resolveFrontendUrl(requestedBack, 'dashboard.html');

  titleEl.textContent = requestedLabel;
  document.title = `${requestedLabel} - PensionsGo`;
  subtitleEl.textContent = '';
  subtitleEl.classList.add('hidden');

  backBtn.addEventListener('click', () => {
    if (requestedReturnKey) {
      backUrl.searchParams.set('viewer_return', requestedReturnKey);
    }
    window.location.assign(backUrl.href);
  });

  if (downloadUrl) {
    downloadBtn.href = downloadUrl.href;
    downloadBtn.classList.remove('hidden');
  } else {
    downloadBtn.classList.add('hidden');
  }

  if (!previewUrl) {
    frameWrap.classList.add('hidden');
    setState('Unable to open this document inside the app. The requested file route is not allowed for embedded preview.', 'error');
    requestAnimationFrame(syncViewerLayout);
    return;
  }

  setState('Loading secure document preview...');
  frameWrap.classList.remove('hidden');

  frameWrap.addEventListener('wheel', enterImmersiveMode, { passive: true });
  frameWrap.addEventListener('touchstart', enterImmersiveMode, { passive: true });
  frameWrap.addEventListener('pointerdown', enterImmersiveMode, { passive: true });
  frameWrap.addEventListener('focusin', enterImmersiveMode);
  frameEl.addEventListener('load', () => {
    clearState();
    syncViewerLayout();
  }, { once: true });

  if (headerEl) {
    headerEl.addEventListener('mouseenter', exitImmersiveMode);
    headerEl.addEventListener('focusin', exitImmersiveMode);
  }

  window.addEventListener('resize', syncViewerLayout);
  window.addEventListener('orientationchange', syncViewerLayout);
  window.addEventListener('load', syncViewerLayout, { once: true });
  requestAnimationFrame(syncViewerLayout);

  frameEl.src = previewUrl.href;
});
