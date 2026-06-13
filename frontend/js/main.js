// 
// main.js - PensionsGo Frontend Controller
// 
// Purpose:
//  - Centralized frontend logic with unified session management
//  - Web Worker for background session monitoring
//  - Adaptive polling based on network conditions
//  - Grace period support for device conflicts
//  - Cross-tab synchronization
// 
import { loadFooter } from './modules/footer.js?v=20260507c';
import { initAppUI } from './modules/ui_feedback.js';
import { initPwaShell } from './modules/pwa.js?v=20260528b';

const DEVICE_TOKEN_STORAGE_KEY = 'pensionsgo_device_token';
const HOSTED_SESSION_ID_STORAGE_KEY = 'pensionsgo_hosted_session_id';
const HOSTED_SESSION_USER_STORAGE_KEY = 'pensionsgo_hosted_session_user';
const HOSTED_SESSION_VERIFIED_AT_STORAGE_KEY = 'pensionsgo_hosted_session_verified_at';
const TAB_AUTH_MARKER_KEY = 'pensionsgo_tab_auth_verified';
const SESSION_VALIDATION_CACHE_KEY = 'pensionsgo_session_validation_state';
const LAST_SECURE_PAGE_KEY = 'pensionsgo_last_secure_page';
const PUBLIC_SESSION_ALLOWANCE_KEY = 'pensionsgo_public_session_allowance';
const DOCUMENT_VIEWER_RETURN_PREFIX = 'pensionsgo_document_viewer_return_';
const PUBLIC_SESSION_ALLOWANCE_TTL_MS = 20 * 60 * 1000;
const SESSION_VALIDATION_CACHE_TTL_MS = 25 * 1000;
const PUBLIC_REAUTH_PAGES = new Set(['login.html', 'index.html', 'about.html', 'feedback.html', 'terms.html']);
const PUBLIC_DROPDOWN_EXCEPTION_PAGES = new Set(['feedback.html', 'terms.html']);

function isPrivateIpv4Address(hostname) {
  const match = String(hostname || '').match(/^(\d+)\.(\d+)\.(\d+)\.(\d+)$/);
  if (!match) return false;
  const a = Number(match[1]);
  const b = Number(match[2]);
  return a === 10
    || a === 127
    || (a === 192 && b === 168)
    || (a === 172 && b >= 16 && b <= 31);
}

function isLocalAppServerContext() {
  const hostname = String(window.location.hostname || '').trim().toLowerCase();
  if (!hostname) return false;
  return hostname === 'localhost'
    || hostname === '::1'
    || hostname.endsWith('.localhost')
    || hostname.endsWith('.local')
    || isPrivateIpv4Address(hostname);
}

function createSecureDeviceToken() {
  if (window.crypto?.getRandomValues) {
    const bytes = new Uint8Array(32);
    window.crypto.getRandomValues(bytes);
    return Array.from(bytes, (byte) => byte.toString(16).padStart(2, '0')).join('');
  }

  return Array.from({ length: 64 }, () => Math.floor(Math.random() * 16).toString(16)).join('');
}

function getPersistentDeviceToken() {
  const existingToken = (localStorage.getItem(DEVICE_TOKEN_STORAGE_KEY) || '').trim().toLowerCase();
  if (/^[a-f0-9]{64}$/.test(existingToken)) {
    return existingToken;
  }

  const deviceToken = createSecureDeviceToken();
  localStorage.setItem(DEVICE_TOKEN_STORAGE_KEY, deviceToken);
  return deviceToken;
}

function withDeviceTokenHeaders(headers = {}) {
  const mergedHeaders = { ...headers };
  const deviceToken = getPersistentDeviceToken();
  if (deviceToken) {
    mergedHeaders['X-Device-Token'] = deviceToken;
  }
  return withHostedSessionHeaders(mergedHeaders);
}

function withHostedSessionHeaders(headers = {}) {
  const mergedHeaders = { ...headers };
  const hostedSessionId = (localStorage.getItem(HOSTED_SESSION_ID_STORAGE_KEY) || '').trim();
  const hostedSessionUser = (localStorage.getItem(HOSTED_SESSION_USER_STORAGE_KEY) || '').trim();
  if (/^[a-f0-9]{64}$/i.test(hostedSessionId) && hostedSessionUser) {
    syncHostedSessionClientCookies(hostedSessionId, hostedSessionUser);
    mergedHeaders['X-PensionsGo-Session-Id'] = hostedSessionId;
    mergedHeaders['X-PensionsGo-User-Id'] = hostedSessionUser;
  }
  return mergedHeaders;
}

function syncHostedSessionClientCookies(sessionId, userId) {
  const sid = String(sessionId || '').trim();
  const uid = String(userId || '').trim();
  if (!/^[a-f0-9]{64}$/i.test(sid) || !uid) return;
  const secure = window.location.protocol === 'https:' ? '; Secure' : '';
  document.cookie = `PENSION_APP_CLIENT_SID=${encodeURIComponent(sid)}; Path=/; SameSite=Lax${secure}`;
  document.cookie = `PENSION_APP_CLIENT_UID=${encodeURIComponent(uid)}; Path=/; SameSite=Lax${secure}`;
}

function clearHostedSessionClientCookies() {
  const secure = window.location.protocol === 'https:' ? '; Secure' : '';
  document.cookie = `PENSION_APP_CLIENT_SID=; Max-Age=0; Path=/; SameSite=Lax${secure}`;
  document.cookie = `PENSION_APP_CLIENT_UID=; Max-Age=0; Path=/; SameSite=Lax${secure}`;
}

function normalizeDocumentViewerCandidate(url) {
  try {
    const candidate = new URL(String(url || ''), window.location.href);
    if (candidate.origin !== window.location.origin) {
      return null;
    }
    return candidate;
  } catch (_error) {
    return null;
  }
}

function buildDocumentDownloadUrl(resourceUrl) {
  const normalized = normalizeDocumentViewerCandidate(resourceUrl);
  if (!normalized) {
    return '';
  }

  normalized.searchParams.set('download', '1');
  return `${normalized.pathname}${normalized.search}`;
}

function buildInAppDocumentViewerUrl(resourceUrl, options = {}) {
  const normalized = normalizeDocumentViewerCandidate(resourceUrl);
  if (!normalized) {
    return '';
  }

  const viewerUrl = new URL('document_viewer.html', window.location.href);
  viewerUrl.searchParams.set('src', `${normalized.pathname}${normalized.search}`);

  const label = String(options.label || '').trim();
  if (label) {
    viewerUrl.searchParams.set('label', label);
  }

  const backUrl = normalizeDocumentViewerCandidate(options.backUrl || window.location.href);
  if (backUrl) {
    viewerUrl.searchParams.set('back', `${backUrl.pathname}${backUrl.search}${backUrl.hash}`);
  }

  if (options.returnState && backUrl) {
    const returnKey = `${DOCUMENT_VIEWER_RETURN_PREFIX}${Date.now()}_${Math.random().toString(36).slice(2, 10)}`;
    try {
      sessionStorage.setItem(returnKey, JSON.stringify(options.returnState));
      viewerUrl.searchParams.set('return_key', returnKey);
    } catch (_error) {
      // Ignore storage failures and continue without modal restore context.
    }
  }

  const downloadUrl = normalizeDocumentViewerCandidate(options.downloadUrl || buildDocumentDownloadUrl(normalized.href));
  if (downloadUrl) {
    viewerUrl.searchParams.set('download', `${downloadUrl.pathname}${downloadUrl.search}`);
  }

  return `${viewerUrl.pathname.split('/').pop()}?${viewerUrl.searchParams.toString()}`;
}

window.PensionsGoDocumentViewer = {
  buildViewerUrl: buildInAppDocumentViewerUrl,
  buildDownloadUrl: buildDocumentDownloadUrl,
  consumeReturnState(returnKey) {
    const normalizedKey = String(returnKey || '').trim();
    if (!normalizedKey || !normalizedKey.startsWith(DOCUMENT_VIEWER_RETURN_PREFIX)) {
      return null;
    }

    try {
      const raw = sessionStorage.getItem(normalizedKey);
      sessionStorage.removeItem(normalizedKey);
      return raw ? JSON.parse(raw) : null;
    } catch (_error) {
      sessionStorage.removeItem(normalizedKey);
      return null;
    }
  }
};

function escapeExportHtml(value) {
  return String(value ?? '')
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#039;');
}

function triggerPensionsGoExportDownload(url, fileName = '') {
  const safeUrl = String(url || '').trim();
  if (!safeUrl) return false;
  const anchor = document.createElement('a');
  anchor.href = safeUrl;
  if (fileName) {
    anchor.download = fileName;
  }
  anchor.rel = 'noopener';
  anchor.style.display = 'none';
  document.body.appendChild(anchor);
  anchor.click();
  anchor.remove();
  return true;
}

function openPensionsGoPdfExportViewer(url, label = 'Exported PDF') {
  const safeUrl = String(url || '').trim();
  if (!safeUrl) return false;
  const viewerUrl = buildInAppDocumentViewerUrl(safeUrl, {
    label,
    backUrl: window.location.href
  });
  window.location.assign(viewerUrl || safeUrl);
  return true;
}

function deliverPensionsGoExport(url, options = {}) {
  const safeUrl = String(url || '').trim();
  if (!safeUrl) return false;
  const format = String(options.format || '').trim().toLowerCase();
  if (format === 'pdf') {
    return openPensionsGoPdfExportViewer(safeUrl, options.label || 'Exported PDF');
  }
  return triggerPensionsGoExportDownload(safeUrl, options.fileName || '');
}

function buildPensionsGoPrintDocument({ title = 'PensionsGo Export', meta = '', columns = [], rows = [] } = {}) {
  const safeColumns = Array.from(columns || []).map((column) => String(column ?? ''));
  const safeRows = Array.from(rows || []);
  return `<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <title>${escapeExportHtml(title)}</title>
  <style>
    @page { margin: 14mm; }
    * { box-sizing: border-box; }
    body { margin: 0; font-family: Tahoma, Arial, sans-serif; font-size: 12pt; color: #1f2937; background: #fff; }
    h1 { margin: 0 0 3px; color: #741a2d; font-size: 12pt; line-height: 1.25; }
    p { margin: 0 0 5px; color: #475569; font-size: 12pt; line-height: 1.25; }
    table { width: 100%; border-collapse: collapse; table-layout: auto; }
    thead tr { background: #741a2d; color: #fff; }
    th, td { border: 1px solid #b44556; padding: 5px; text-align: left; vertical-align: middle; line-height: 1.2; white-space: normal; overflow-wrap: normal; word-break: normal; }
    th { font-weight: 700; border-top: 2px solid #d6a64a; border-bottom: 2px solid #d6a64a; }
    tbody tr:nth-child(even) td { background: #fffdf8; }
    tbody tr:nth-child(odd) td { background: #fff8eb; }
  </style>
</head>
<body>
  <h1>${escapeExportHtml(title)}</h1>
  ${meta ? `<p>${escapeExportHtml(meta)}</p>` : ''}
  <table>
    <thead><tr>${safeColumns.map((column) => `<th>${escapeExportHtml(column)}</th>`).join('')}</tr></thead>
    <tbody>${safeRows.map((row) => `<tr>${safeColumns.map((column) => `<td>${escapeExportHtml(row?.[column] ?? '')}</td>`).join('')}</tr>`).join('')}</tbody>
  </table>
</body>
</html>`;
}

function ensurePensionsGoPrintPreviewModal() {
  let modal = document.getElementById('pgoPrintPreviewModal');
  if (modal) return modal;
  modal = document.createElement('div');
  modal.id = 'pgoPrintPreviewModal';
  modal.className = 'pgo-export-preview-modal';
  modal.setAttribute('aria-hidden', 'true');
  modal.innerHTML = `
    <div class="pgo-export-preview-dialog" role="dialog" aria-modal="true" aria-labelledby="pgoPrintPreviewTitle">
      <div class="pgo-export-preview-header">
        <div>
          <span>Secure Document Viewer</span>
          <h3 id="pgoPrintPreviewTitle">Print Preview</h3>
        </div>
        <button type="button" id="pgoPrintPreviewClose" aria-label="Close print preview">&times;</button>
      </div>
      <iframe id="pgoPrintPreviewFrame" class="pgo-export-preview-frame" referrerpolicy="same-origin"></iframe>
      <div class="pgo-export-preview-footer">
        <button type="button" class="btn-action btn-secondary" id="pgoPrintPreviewPrint">Print</button>
        <button type="button" class="btn-action" id="pgoPrintPreviewDone">Close</button>
      </div>
    </div>
  `;
  document.body.appendChild(modal);
  const close = () => {
    modal.classList.remove('open');
    modal.setAttribute('aria-hidden', 'true');
    document.body.classList.remove('modal-open');
  };
  modal.querySelector('#pgoPrintPreviewClose')?.addEventListener('click', close);
  modal.querySelector('#pgoPrintPreviewDone')?.addEventListener('click', close);
  modal.addEventListener('click', (event) => {
    if (event.target === modal) close();
  });
  modal.querySelector('#pgoPrintPreviewPrint')?.addEventListener('click', () => {
    modal.querySelector('#pgoPrintPreviewFrame')?.contentWindow?.print();
  });
  return modal;
}

function openPensionsGoPrintPreview({ title = 'Print Preview', html = '' } = {}) {
  const modal = ensurePensionsGoPrintPreviewModal();
  const titleEl = modal.querySelector('#pgoPrintPreviewTitle');
  const frame = modal.querySelector('#pgoPrintPreviewFrame');
  if (titleEl) titleEl.textContent = title;
  if (frame) {
    frame.removeAttribute('src');
    frame.srcdoc = String(html || '');
  }
  modal.classList.add('open');
  modal.setAttribute('aria-hidden', 'false');
  document.body.classList.add('modal-open');
  return modal;
}

window.PensionsGoExports = {
  deliver: deliverPensionsGoExport,
  download: triggerPensionsGoExportDownload,
  openPdf: openPensionsGoPdfExportViewer,
  buildPrintDocument: buildPensionsGoPrintDocument,
  openPrintPreview: openPensionsGoPrintPreview
};

const MONEY_INPUT_SELECTOR = 'input[data-money-input]';
let moneyInputObserver = null;

function getMoneyInputMaxDecimals(input) {
  const explicit = Number.parseInt(String(input?.dataset?.moneyDecimals || '').trim(), 10);
  if (Number.isInteger(explicit) && explicit >= 0) {
    return explicit;
  }

  const fixed = Number.parseInt(String(input?.dataset?.moneyFixedDecimals || '').trim(), 10);
  if (Number.isInteger(fixed) && fixed >= 0) {
    return fixed;
  }

  const step = String(input?.getAttribute?.('step') || '').trim();
  if (/^\d+(?:\.\d+)?$/.test(step) && step.includes('.')) {
    return step.split('.')[1].length;
  }

  return 2;
}

function getMoneyInputFixedDecimals(input) {
  const explicit = Number.parseInt(String(input?.dataset?.moneyFixedDecimals || '').trim(), 10);
  if (Number.isInteger(explicit) && explicit >= 0) {
    return explicit;
  }

  return input?.readOnly || input?.disabled ? getMoneyInputMaxDecimals(input) : null;
}

function moneyInputAllowsNegative(input) {
  const explicit = String(input?.dataset?.moneyAllowNegative || '').trim().toLowerCase();
  if (explicit) {
    return ['1', 'true', 'yes'].includes(explicit);
  }

  return String(input?.getAttribute?.('min') || '').trim().startsWith('-');
}

function normalizeMoneyValue(value, options = {}) {
  const maxDecimals = Number.isInteger(options.maxDecimals) && options.maxDecimals >= 0
    ? options.maxDecimals
    : 2;
  const allowNegative = Boolean(options.allowNegative);
  let text = String(value ?? '').trim();
  if (!text) {
    return '';
  }

  text = text.replace(/,/g, '').replace(/[^\d.\-]/g, '');
  let negative = false;
  if (allowNegative && text.startsWith('-')) {
    negative = true;
  }
  text = text.replace(/-/g, '');

  const hadTrailingDecimal = text.endsWith('.');
  const parts = text.split('.');
  let integerPart = parts.shift() || '';
  let decimalPart = parts.join('');

  if (maxDecimals === 0) {
    decimalPart = '';
  } else if (decimalPart) {
    decimalPart = decimalPart.slice(0, maxDecimals);
  }

  integerPart = integerPart.replace(/^0+(?=\d)/, '');
  if (!integerPart) {
    integerPart = decimalPart || hadTrailingDecimal ? '0' : '';
  }

  if (!integerPart && !decimalPart) {
    return '';
  }

  let result = `${negative ? '-' : ''}${integerPart || '0'}`;
  if (hadTrailingDecimal || decimalPart !== '') {
    result += `.${decimalPart}`;
  }
  return result;
}

function parseMoneyValue(value, fallback = 0) {
  const normalized = normalizeMoneyValue(value, { allowNegative: true, maxDecimals: 12 });
  if (!normalized || normalized === '-' || normalized === '.' || normalized === '-.') {
    return fallback;
  }

  const parsed = Number(normalized);
  return Number.isFinite(parsed) ? parsed : fallback;
}

function formatMoneyValue(value, options = {}) {
  const maxDecimals = Number.isInteger(options.maxDecimals) && options.maxDecimals >= 0
    ? options.maxDecimals
    : 2;
  const fixedDecimals = Number.isInteger(options.fixedDecimals) && options.fixedDecimals >= 0
    ? options.fixedDecimals
    : null;
  const normalized = normalizeMoneyValue(value, {
    allowNegative: Boolean(options.allowNegative),
    maxDecimals
  });

  if (!normalized) {
    return '';
  }

  if (fixedDecimals !== null) {
    const parsed = parseMoneyValue(normalized, 0);
    const absoluteParts = Math.abs(parsed).toFixed(fixedDecimals).split('.');
    const groupedInteger = absoluteParts[0].replace(/\B(?=(\d{3})+(?!\d))/g, ',');
    const decimals = absoluteParts[1] || '';
    return `${parsed < 0 ? '-' : ''}${groupedInteger}${fixedDecimals > 0 ? `.${decimals}` : ''}`;
  }

  const negative = normalized.startsWith('-');
  const unsigned = negative ? normalized.slice(1) : normalized;
  const trailingDecimal = unsigned.endsWith('.');
  const parts = unsigned.split('.');
  const integerPart = String(parts.shift() || '0').replace(/^0+(?=\d)/, '') || '0';
  const groupedInteger = integerPart.replace(/\B(?=(\d{3})+(?!\d))/g, ',');
  const decimalPart = parts.join('');

  let suffix = '';
  if (decimalPart !== '') {
    suffix = `.${decimalPart}`;
  } else if (trailingDecimal) {
    suffix = '.';
  }

  return `${negative ? '-' : ''}${groupedInteger}${suffix}`;
}

function enhanceMoneyInput(input) {
  if (!(input instanceof HTMLInputElement) || input.dataset.moneyInputBound === '1') {
    return input;
  }

  input.dataset.moneyInputBound = '1';
  if (input.type === 'number') {
    input.type = 'text';
  }
  input.inputMode = getMoneyInputMaxDecimals(input) > 0 ? 'decimal' : 'numeric';
  input.autocomplete = input.autocomplete || 'off';

  const applyFormatting = ({ forceFixed = false } = {}) => {
    const maxDecimals = getMoneyInputMaxDecimals(input);
    const fixedDecimals = forceFixed ? getMoneyInputFixedDecimals(input) : null;
    input.value = formatMoneyValue(input.value, {
      allowNegative: moneyInputAllowsNegative(input),
      maxDecimals,
      fixedDecimals
    });
  };

  input.addEventListener('input', () => applyFormatting());
  input.addEventListener('change', () => applyFormatting({ forceFixed: true }));
  input.addEventListener('blur', () => applyFormatting({ forceFixed: true }));
  input.addEventListener('paste', () => {
    window.setTimeout(() => applyFormatting(), 0);
  });

  applyFormatting({ forceFixed: true });
  return input;
}

function scanMoneyInputs(root = document) {
  if (!root) {
    return;
  }

  if (root instanceof HTMLInputElement && root.matches(MONEY_INPUT_SELECTOR)) {
    enhanceMoneyInput(root);
    return;
  }

  if (!(root instanceof Element) && root !== document) {
    return;
  }

  const scope = root === document ? document : root;
  scope.querySelectorAll?.(MONEY_INPUT_SELECTOR).forEach((input) => {
    enhanceMoneyInput(input);
  });
}

function setMoneyInputValue(input, value, options = {}) {
  if (!(input instanceof HTMLInputElement)) {
    return;
  }

  enhanceMoneyInput(input);
  input.value = formatMoneyValue(value, {
    allowNegative: moneyInputAllowsNegative(input),
    maxDecimals: getMoneyInputMaxDecimals(input),
    fixedDecimals: options.forceFixed === false
      ? null
      : (Number.isInteger(options.fixedDecimals) ? options.fixedDecimals : getMoneyInputFixedDecimals(input))
  });
}

function initMoneyInputEnhancements() {
  scanMoneyInputs(document);
  if (moneyInputObserver || !document.documentElement) {
    return;
  }

  moneyInputObserver = new MutationObserver((mutations) => {
    mutations.forEach((mutation) => {
      mutation.addedNodes.forEach((node) => {
        if (!(node instanceof Element)) {
          return;
        }
        scanMoneyInputs(node);
      });
    });
  });

  moneyInputObserver.observe(document.documentElement, {
    childList: true,
    subtree: true
  });
}

window.PensionsGoMoney = {
  parse(value, fallback = 0) {
    return parseMoneyValue(value, fallback);
  },
  normalize(value, options = {}) {
    return normalizeMoneyValue(value, options);
  },
  format(value, options = {}) {
    return formatMoneyValue(value, options);
  },
  enhanceInput(input) {
    return enhanceMoneyInput(input);
  },
  scanInputs(root = document) {
    scanMoneyInputs(root);
  },
  setInputValue(input, value, options = {}) {
    setMoneyInputValue(input, value, options);
  },
  getNumericValue(input, fallback = 0) {
    return parseMoneyValue(input?.value ?? input, fallback);
  }
};

const TEXT_CASE_SELECTOR = [
  'input:not([type])',
  'input[type="text"]',
  'textarea'
].join(', ');

const TEXT_CASE_SKIP_PATTERNS = [
  /\bsearch\b/,
  /\bfilter\b/,
  /\blookup\b/,
  /\bquery\b/,
  /\btrack(?:ing)?\b/,
  /\breg(?:istration)?\s*no\b/,
  /\bfile\s*(?:number|no)\b/,
  /\bsupplier\s*no\b/,
  /\bcomputer\s*no\b/,
  /\btin\b/,
  /\bphone\b/,
  /\btel\b/,
  /\bcontact\b/,
  /\baccount(?:\s*(?:number|no))?\b/,
  /\bemail\b/,
  /\bpassword\b/,
  /\bwebsite\b/,
  /\bpage\s*context\b/,
  /\burl\b/,
  /\blink\b/,
  /\bref(?:erence)?\b/
];

const TEXT_CASE_UPPER_PATTERNS = [
  /\bnin\b/,
  /\bnational\s*id(?:entification)?\b/
];

const TEXT_CASE_FILE_NUMBER_PATTERNS = [
  /\breg(?:istration)?\s*(?:number|no)\b/,
  /\bfile\s*(?:number|no)\b/
];

const TEXT_CASE_MIXED_FILE_LOOKUP_PATTERNS = [
  /\bname\b/,
  /\bapplicant\b/,
  /\bpensioner\b/,
  /\bsupplier\b/,
  /\bphone\b/,
  /\btel\b/,
  /\bcontact\b/,
  /\bsearch\b/,
  /\bfilter\b/,
  /\blookup\b/,
  /\bquery\b/,
  /\btrack(?:ing)?\b/
];

const TEXT_CASE_SENTENCE_PATTERNS = [
  /\bnote(?:s)?\b/,
  /\bremark(?:s)?\b/,
  /\bcomment(?:s)?\b/,
  /\bdescription\b/,
  /\bsummary\b/,
  /\bdetails?\b/
];

let textCaseObserver = null;
let textCaseSubmitBound = false;

function normalizeTextCaseIdentityPart(value) {
  return String(value || '')
    .replace(/([a-z0-9])([A-Z])/g, '$1 $2')
    .replace(/[_-]+/g, ' ')
    .trim()
    .toLowerCase();
}

function getTextCaseFieldIdentity(field) {
  return [
    field?.id,
    field?.name,
    field?.placeholder,
    field?.getAttribute?.('aria-label'),
    field?.dataset?.textCaseContext
  ]
    .map((value) => normalizeTextCaseIdentityPart(value))
    .filter(Boolean)
    .join(' ');
}

function matchesTextCasePattern(identity, patterns) {
  return patterns.some((pattern) => pattern.test(identity));
}

function isDedicatedFileNumberField(identity) {
  return Boolean(
    identity
    && matchesTextCasePattern(identity, TEXT_CASE_FILE_NUMBER_PATTERNS)
    && !matchesTextCasePattern(identity, TEXT_CASE_MIXED_FILE_LOOKUP_PATTERNS)
  );
}

function detectTextCaseMode(field) {
  if (!(field instanceof HTMLInputElement || field instanceof HTMLTextAreaElement)) {
    return 'off';
  }

  const explicitMode = String(field.dataset.textCase || '').trim().toLowerCase();
  if (['title', 'upper', 'sentence', 'off'].includes(explicitMode)) {
    return explicitMode;
  }

  if (field.disabled || field.readOnly) {
    return 'off';
  }

  if (field instanceof HTMLInputElement) {
    const type = String(field.type || 'text').trim().toLowerCase();
    if ([
      'hidden',
      'search',
      'email',
      'password',
      'url',
      'tel',
      'number',
      'date',
      'datetime-local',
      'month',
      'week',
      'time',
      'file',
      'checkbox',
      'radio',
      'range',
      'color'
    ].includes(type)) {
      return 'off';
    }

    if (field.hasAttribute('data-money-input')) {
      return 'off';
    }
  }

  const identity = getTextCaseFieldIdentity(field);
  if (isDedicatedFileNumberField(identity)) {
    return 'upper';
  }

  if (identity && matchesTextCasePattern(identity, TEXT_CASE_SKIP_PATTERNS)) {
    return 'off';
  }

  if (identity && matchesTextCasePattern(identity, TEXT_CASE_UPPER_PATTERNS)) {
    return 'upper';
  }

  if (field instanceof HTMLTextAreaElement) {
    return 'sentence';
  }

  if (identity && matchesTextCasePattern(identity, TEXT_CASE_SENTENCE_PATTERNS)) {
    return 'sentence';
  }

  return 'title';
}

function preserveUppercaseToken(token) {
  if (!token || !/[A-Z]/.test(token)) {
    return false;
  }

  if (/^(?:[A-Z]{2,4}|[IVXLCDM]{1,6}|\d+[A-Z]{1,3}|[A-Z]{1,3}\d+)$/u.test(token)) {
    return true;
  }

  return false;
}

function normalizeTitleCaseValue(value) {
  return String(value ?? '').replace(/[A-Za-z0-9]+(?:['/-][A-Za-z0-9]+)*/g, (token) => {
    if (preserveUppercaseToken(token)) {
      return token.toUpperCase();
    }

    return token
      .split(/([/'-])/)
      .map((part) => {
        if (!part || /[/'-]/.test(part)) {
          return part;
        }
        if (/^\d+$/.test(part)) {
          return part;
        }
        const lowered = part.toLowerCase();
        return lowered.charAt(0).toUpperCase() + lowered.slice(1);
      })
      .join('');
  });
}

function normalizeSentenceCaseValue(value) {
  return String(value ?? '')
    .replace(/\r\n?/g, '\n')
    .replace(/(^|[.!?]\s+|\n+)([a-z])/g, (match, prefix, char) => `${prefix}${char.toUpperCase()}`);
}

function normalizeFieldCaseValue(field, mode, { finalize = false } = {}) {
  const rawValue = String(field?.value ?? '');
  const baseValue = finalize
    ? rawValue.replace(/\r\n?/g, '\n').trim()
    : rawValue;

  switch (mode) {
    case 'upper':
      return baseValue.toUpperCase();
    case 'sentence':
      return normalizeSentenceCaseValue(baseValue);
    case 'title':
      return normalizeTitleCaseValue(baseValue);
    default:
      return rawValue;
  }
}

function applyTextCaseToField(field, { finalize = false } = {}) {
  if (!(field instanceof HTMLInputElement || field instanceof HTMLTextAreaElement)) {
    return field;
  }

  const mode = field.dataset.textCaseResolved || detectTextCaseMode(field);
  if (mode === 'off') {
    return field;
  }

  const previousValue = String(field.value ?? '');
  const selectionStart = typeof field.selectionStart === 'number' ? field.selectionStart : null;
  const selectionEnd = typeof field.selectionEnd === 'number' ? field.selectionEnd : null;
  const nextValue = normalizeFieldCaseValue(field, mode, { finalize });

  if (nextValue !== previousValue) {
    field.value = nextValue;

    if (!finalize && document.activeElement === field && selectionStart !== null && selectionEnd !== null && previousValue.length === nextValue.length) {
      try {
        field.setSelectionRange(selectionStart, selectionEnd);
      } catch (_error) {
        // Ignore selection restoration failures for unsupported input types.
      }
    }
  }

  return field;
}

function enhanceTextCaseField(field) {
  if (!(field instanceof HTMLInputElement || field instanceof HTMLTextAreaElement) || field.dataset.textCaseBound === '1') {
    return field;
  }

  field.dataset.textCaseBound = '1';
  const mode = detectTextCaseMode(field);
  field.dataset.textCaseResolved = mode;

  if (mode === 'off') {
    return field;
  }

  if (!field.hasAttribute('autocapitalize')) {
    field.setAttribute('autocapitalize', mode === 'upper' ? 'characters' : (mode === 'sentence' ? 'sentences' : 'words'));
  }

  if (mode === 'upper') {
    field.spellcheck = false;
    if (!field.hasAttribute('autocorrect')) {
      field.setAttribute('autocorrect', 'off');
    }
  }

  const applyLiveCase = () => applyTextCaseToField(field, { finalize: false });
  const applyFinalCase = () => applyTextCaseToField(field, { finalize: true });

  field.addEventListener('input', applyLiveCase);
  field.addEventListener('change', applyFinalCase);
  field.addEventListener('blur', applyFinalCase);
  field.addEventListener('focus', applyLiveCase);
  field.addEventListener('paste', () => {
    window.setTimeout(applyLiveCase, 0);
  });

  applyFinalCase();
  return field;
}

function scanTextCaseInputs(root = document) {
  if (!root) {
    return;
  }

  if ((root instanceof HTMLInputElement || root instanceof HTMLTextAreaElement) && root.matches(TEXT_CASE_SELECTOR)) {
    enhanceTextCaseField(root);
    return;
  }

  if (!(root instanceof Element) && root !== document) {
    return;
  }

  const scope = root === document ? document : root;
  scope.querySelectorAll?.(TEXT_CASE_SELECTOR).forEach((field) => {
    enhanceTextCaseField(field);
  });
}

function normalizeTextCaseWithin(root = document) {
  if (!root) {
    return;
  }

  if ((root instanceof HTMLInputElement || root instanceof HTMLTextAreaElement) && root.matches(TEXT_CASE_SELECTOR)) {
    applyTextCaseToField(root, { finalize: true });
    return;
  }

  const scope = root === document ? document : root;
  scope.querySelectorAll?.(TEXT_CASE_SELECTOR).forEach((field) => {
    applyTextCaseToField(field, { finalize: true });
  });
}

function initTextCaseEnhancements() {
  scanTextCaseInputs(document);

  if (!textCaseSubmitBound) {
    textCaseSubmitBound = true;
    document.addEventListener('submit', (event) => {
      const target = event.target;
      if (target instanceof HTMLFormElement) {
        normalizeTextCaseWithin(target);
      }
    }, true);
  }

  if (textCaseObserver || !document.documentElement) {
    return;
  }

  textCaseObserver = new MutationObserver((mutations) => {
    mutations.forEach((mutation) => {
      mutation.addedNodes.forEach((node) => {
        if (!(node instanceof Element)) {
          return;
        }
        scanTextCaseInputs(node);
      });
    });
  });

  textCaseObserver.observe(document.documentElement, {
    childList: true,
    subtree: true
  });
}

window.PensionsGoTextCase = {
  detectMode(field) {
    return detectTextCaseMode(field);
  },
  applyToField(field, options = {}) {
    return applyTextCaseToField(field, options);
  },
  scanInputs(root = document) {
    scanTextCaseInputs(root);
  },
  normalizeWithin(root = document) {
    normalizeTextCaseWithin(root);
  }
};

if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', () => {
    initMoneyInputEnhancements();
    initTextCaseEnhancements();
  }, { once: true });
} else {
  initMoneyInputEnhancements();
  initTextCaseEnhancements();
}

function normalizeNationalIdValue(value) {
  return String(value || '').trim().toUpperCase();
}

function validateNationalIdValue(value, options = {}) {
  const normalized = normalizeNationalIdValue(value);
  if (!normalized) {
    return {
      valid: true,
      normalized: '',
      message: ''
    };
  }

  if (!/^C[MF][A-Z0-9]{12}$/.test(normalized)) {
    return {
      valid: false,
      normalized,
      message: 'NIN must start with CM or CF, use letters and numbers only, and be exactly 14 characters long.'
    };
  }

  const gender = String(options.gender || '').trim().toLowerCase();
  if (gender === 'male' && !normalized.startsWith('CM')) {
    return {
      valid: false,
      normalized,
      message: 'NIN prefix must be CM for a male record.'
    };
  }
  if (gender === 'female' && !normalized.startsWith('CF')) {
    return {
      valid: false,
      normalized,
      message: 'NIN prefix must be CF for a female record.'
    };
  }

  return {
    valid: true,
    normalized,
    message: ''
  };
}

window.PensionsGoNin = {
  normalize(value) {
    return normalizeNationalIdValue(value);
  },
  validate(value, options = {}) {
    return validateNationalIdValue(value, options);
  }
};

const CSRF_EXEMPT_ENDPOINTS = new Set(['login.php', 'get_csrf_token.php']);
const nativeFetch = window.fetch.bind(window);
let csrfTokenCache = '';
let csrfTokenPromise = null;

function resolveRequestUrl(input) {
  try {
    if (input instanceof Request) {
      return new URL(input.url, window.location.href);
    }
    return new URL(String(input), window.location.href);
  } catch (error) {
    return null;
  }
}

function getRequestMethod(input, init = {}) {
  const method = init.method || (input instanceof Request ? input.method : 'GET');
  return String(method || 'GET').toUpperCase();
}

function isAbortLikeError(error) {
  if (!error) {
    return false;
  }

  const name = String(error.name || '').trim();
  const message = String(error.message || error || '').trim().toLowerCase();
  return name === 'AbortError'
    || name === 'TimeoutError'
    || message.includes('aborted')
    || message.includes('timed out');
}

function normalizeRequestErrorMessage(error, fallbackMessage = 'Request failed.') {
  if (isAbortLikeError(error)) {
    return fallbackMessage;
  }

  const directMessage = String(error?.message || error || '').trim();
  return directMessage || fallbackMessage;
}

function clientHasAuthenticatedSessionHint() {
  return sessionStorage.getItem('isLoggedIn') === 'true'
    || Boolean(sessionStorage.getItem('userId'))
    || Boolean(localStorage.getItem('loggedInUser'));
}

function markTabAuthenticationVerified() {
  sessionStorage.setItem(TAB_AUTH_MARKER_KEY, 'true');
}

function hasTabAuthenticationVerified() {
  return sessionStorage.getItem(TAB_AUTH_MARKER_KEY) === 'true';
}

function rememberSessionValidationState(sessionData = {}) {
  if (!sessionData?.active) {
    sessionStorage.removeItem(SESSION_VALIDATION_CACHE_KEY);
    return;
  }

  const cachePayload = {
    ...sessionData,
    active: true,
    cachedAt: Date.now()
  };
  try {
    sessionStorage.setItem(SESSION_VALIDATION_CACHE_KEY, JSON.stringify(cachePayload));
  } catch (_error) {
    // Ignore storage quota issues; the live session check remains authoritative.
  }
}

function getFreshSessionValidationState() {
  if (!hasTabAuthenticationVerified()) {
    return null;
  }

  try {
    const cached = JSON.parse(sessionStorage.getItem(SESSION_VALIDATION_CACHE_KEY) || 'null');
    const cachedAt = Number(cached?.cachedAt || 0);
    if (!cached?.active || cachedAt <= 0 || Date.now() - cachedAt > SESSION_VALIDATION_CACHE_TTL_MS) {
      return null;
    }

    return {
      ...cached,
      validationCacheHit: true
    };
  } catch (_error) {
    sessionStorage.removeItem(SESSION_VALIDATION_CACHE_KEY);
    return null;
  }
}

function clearTabAuthenticationState() {
  sessionStorage.removeItem(TAB_AUTH_MARKER_KEY);
  sessionStorage.removeItem(SESSION_VALIDATION_CACHE_KEY);
  sessionStorage.removeItem(PUBLIC_SESSION_ALLOWANCE_KEY);
}

function rememberLastSecurePage(url = window.location.href) {
  const safeUrl = String(url || '').trim();
  if (!safeUrl) return;
  localStorage.setItem(LAST_SECURE_PAGE_KEY, safeUrl);
}

function getLastSecurePage() {
  return (localStorage.getItem(LAST_SECURE_PAGE_KEY) || '').trim();
}

function clearLastSecurePage() {
  localStorage.removeItem(LAST_SECURE_PAGE_KEY);
}

function rememberAuthenticatedPublicAllowance(pageName) {
  const normalizedPage = String(pageName || '').trim().toLowerCase();
  if (!PUBLIC_DROPDOWN_EXCEPTION_PAGES.has(normalizedPage)) {
    return;
  }

  sessionStorage.setItem(PUBLIC_SESSION_ALLOWANCE_KEY, JSON.stringify({
    page: normalizedPage,
    issuedAt: Date.now()
  }));
}

function hasValidAuthenticatedPublicAllowance(pageName) {
  const normalizedPage = String(pageName || '').trim().toLowerCase();
  if (!PUBLIC_DROPDOWN_EXCEPTION_PAGES.has(normalizedPage) || !hasTabAuthenticationVerified()) {
    return false;
  }

  try {
    const payload = JSON.parse(sessionStorage.getItem(PUBLIC_SESSION_ALLOWANCE_KEY) || '{}');
    const issuedAt = Number(payload.issuedAt || 0);
    const page = String(payload.page || '').trim().toLowerCase();
    return page === normalizedPage
      && issuedAt > 0
      && (Date.now() - issuedAt) <= PUBLIC_SESSION_ALLOWANCE_TTL_MS;
  } catch (_error) {
    return false;
  }
}

function markAuthenticatedPublicNavigation(targetUrl) {
  try {
    const candidate = new URL(String(targetUrl || ''), window.location.href);
    if (candidate.origin !== window.location.origin) {
      return;
    }

    const targetPage = (candidate.pathname.split('/').pop() || '').toLowerCase();
    if (!PUBLIC_DROPDOWN_EXCEPTION_PAGES.has(targetPage)) {
      return;
    }

    if (!clientHasAuthenticatedSessionHint() && !hasTabAuthenticationVerified()) {
      return;
    }

    if (isAuthRequiredPage(getCurrentPageName())) {
      rememberLastSecurePage(window.location.href);
    }

    rememberAuthenticatedPublicAllowance(targetPage);
  } catch (_error) {
    // Ignore malformed targets.
  }
}

function initializeAuthenticatedPublicNavigationBridge() {
  if (document.body?.dataset.publicAllowanceBridgeBound === 'true') {
    return;
  }

  if (document.body) {
    document.body.dataset.publicAllowanceBridgeBound = 'true';
  }

  document.addEventListener('click', (event) => {
    if (event.defaultPrevented || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) {
      return;
    }

    const link = event.target instanceof Element ? event.target.closest('a[href]') : null;
    if (!link) {
      return;
    }

    const targetAttr = String(link.getAttribute('target') || '').trim();
    if (targetAttr && targetAttr !== '_self') {
      return;
    }

    markAuthenticatedPublicNavigation(link.href || link.getAttribute('href') || '');
  }, true);
}

function shouldAttachCsrf(input, init = {}) {
  const url = resolveRequestUrl(input);
  const method = getRequestMethod(input, init);
  if (!url || !['POST', 'PUT', 'PATCH', 'DELETE'].includes(method)) {
    return false;
  }
  if (!clientHasAuthenticatedSessionHint()) {
    return false;
  }
  if (url.origin !== window.location.origin) {
    return false;
  }
  const pathname = (url.pathname || '').toLowerCase();
  if (!pathname.includes('/backend/api/')) {
    return false;
  }
  const scriptName = pathname.split('/').pop() || '';
  return !CSRF_EXEMPT_ENDPOINTS.has(scriptName);
}

async function fetchCsrfToken(forceRefresh = false) {
  if (!forceRefresh && csrfTokenCache) {
    return csrfTokenCache;
  }
  if (!forceRefresh && csrfTokenPromise) {
    return csrfTokenPromise;
  }

  csrfTokenPromise = (async () => {
    const tokenUrl = new URL('../backend/api/get_csrf_token.php', window.location.href);
    const response = await nativeFetch(tokenUrl.href, {
      credentials: 'include',
      cache: 'no-store',
      headers: {
        'Accept': 'application/json',
        'X-Requested-With': 'XMLHttpRequest'
      }
    });

    if (!response.ok) {
      throw new Error(`CSRF token request failed with status ${response.status}`);
    }

    const data = await response.json();
    if (!data.success || !data.token) {
      throw new Error(data.message || 'Unable to initialize request security token.');
    }

    csrfTokenCache = String(data.token);
    return csrfTokenCache;
  })();

  try {
    return await csrfTokenPromise;
  } finally {
    csrfTokenPromise = null;
  }
}

function mergeRequestHeaders(input, init = {}) {
  const headers = new Headers(input instanceof Request ? input.headers : undefined);
  if (init.headers) {
    new Headers(init.headers).forEach((value, key) => {
      headers.set(key, value);
    });
  }
  return headers;
}

async function parseJsonResponseStrict(response, fallbackMessage = 'Server returned invalid response format.') {
  if (!response) {
    throw new Error('No response received from server.');
  }

  const rawText = await response.text();
  if (!rawText) {
    throw new Error('Server returned empty response.');
  }

  try {
    return JSON.parse(rawText);
  } catch (error) {
    console.error('Failed to parse JSON response:', error);
    console.error('Raw response:', rawText);

    const fatalMatch = rawText.match(/(Fatal error|Parse error):[^<]+/i);
    if (fatalMatch) {
      throw new Error('Server error: ' + fatalMatch[0]);
    }

    const messageMatch = rawText.match(/(message|error)[\"']?\s*[:=]\s*[\"']([^\"']+)/i);
    if (messageMatch?.[2]) {
      throw new Error(messageMatch[2]);
    }

    throw new Error(fallbackMessage);
  }
}

if (!window.__pensionsgoCsrfFetchPatched) {
  window.__pensionsgoCsrfFetchPatched = true;
  window.fetch = async (input, init = {}) => {
    const url = resolveRequestUrl(input);
    const isSameOriginApi = Boolean(url && url.origin === window.location.origin && (url.pathname || '').toLowerCase().includes('/backend/api/'));
    const shouldAttachHostedSession = isSameOriginApi && clientHasAuthenticatedSessionHint();
    if (!shouldAttachCsrf(input, init)) {
      if (!shouldAttachHostedSession) {
        return nativeFetch(input, init);
      }
      const headers = mergeRequestHeaders(input, init);
      new Headers(withHostedSessionHeaders(Object.fromEntries(headers.entries()))).forEach((value, key) => headers.set(key, value));
      return nativeFetch(input, { ...init, headers });
    }

    const headers = mergeRequestHeaders(input, init);
    new Headers(withHostedSessionHeaders(Object.fromEntries(headers.entries()))).forEach((value, key) => headers.set(key, value));
    const existingToken = (headers.get('X-CSRF-Token') || '').trim();
    if (!existingToken) {
      const csrfToken = await fetchCsrfToken();
      headers.set('X-CSRF-Token', csrfToken);
    }

    return nativeFetch(input, {
      ...init,
      headers
    });
  };
}

function getCachedOfflineSessionState() {
  let storedUser = {};
  try {
    storedUser = JSON.parse(localStorage.getItem('loggedInUser') || '{}') || {};
  } catch (_error) {
    storedUser = {};
  }

  const userId = sessionStorage.getItem('userId') || storedUser.id || storedUser.userId || '';
  const userName = sessionStorage.getItem('userName') || storedUser.name || storedUser.userName || '';
  const userRole = (
    sessionStorage.getItem('userRole')
    || localStorage.getItem('userRole')
    || storedUser.role
    || storedUser.userRole
    || ''
  ).toLowerCase();
  const userRoleEffective = (
    sessionStorage.getItem('userRoleEffective')
    || localStorage.getItem('userRoleEffective')
    || storedUser.roleEffective
    || storedUser.userRoleEffective
    || userRole
    || ''
  ).toLowerCase();
  const userEmail = sessionStorage.getItem('userEmail') || storedUser.email || storedUser.userEmail || '';
  const userPhoto = sessionStorage.getItem('userPhoto') || storedUser.photo || storedUser.userPhoto || '';
  const loggedInFlag = sessionStorage.getItem('isLoggedIn') === 'true' || Boolean(userId && (userRole || userRoleEffective));

  if (!loggedInFlag || (!userRole && !userRoleEffective)) {
    return { active: false };
  }

  return {
    active: true,
    offline: !navigator.onLine,
    sessionCheckDegraded: navigator.onLine,
    userId,
    userName: userName || 'User',
    userRole,
    userRoleEffective,
    userEmail,
    userPhoto
  };
}

function hasHostedDeploymentSessionHint() {
  const sessionId = (localStorage.getItem(HOSTED_SESSION_ID_STORAGE_KEY) || '').trim();
  const userId = (localStorage.getItem(HOSTED_SESSION_USER_STORAGE_KEY) || '').trim();
  const verifiedAt = Number(localStorage.getItem(HOSTED_SESSION_VERIFIED_AT_STORAGE_KEY) || 0);
  let timeoutSeconds = Number(sessionStorage.getItem('sessionTimeout') || 0);
  if (!Number.isFinite(timeoutSeconds) || timeoutSeconds <= 0) {
    try {
      const settings = JSON.parse(localStorage.getItem('sessionSettings') || '{}');
      timeoutSeconds = Number(settings.session_timeout || settings.sessionTimeout || 0);
    } catch (_error) {
      timeoutSeconds = 0;
    }
  }
  if (!Number.isFinite(timeoutSeconds) || timeoutSeconds <= 0) timeoutSeconds = 1800;
  const maxAgeMs = Math.max(5 * 60 * 1000, timeoutSeconds * 1000);
  return /^[a-f0-9]{64}$/i.test(sessionId)
    && Boolean(userId)
    && verifiedAt > 0
    && Date.now() - verifiedAt <= maxAgeMs;
}

function getHostedDeploymentSessionState() {
  const sessionId = (localStorage.getItem(HOSTED_SESSION_ID_STORAGE_KEY) || '').trim();
  const hostedUserId = (localStorage.getItem(HOSTED_SESSION_USER_STORAGE_KEY) || '').trim();
  if (!/^[a-f0-9]{64}$/i.test(sessionId) || !hostedUserId || !hasHostedDeploymentSessionHint()) {
    return { active: false };
  }

  const cached = getCachedOfflineSessionState();
  if (cached.active) {
    syncHostedSessionClientCookies(sessionId, hostedUserId);
    return {
      ...cached,
      userId: cached.userId || hostedUserId,
      hostedSessionFallback: true,
      sessionCheckDegraded: true,
      offline: !navigator.onLine
    };
  }
  return { active: false };
}

function getStoredEffectiveRole() {
  return (sessionStorage.getItem('userRoleEffective') || localStorage.getItem('userRoleEffective') || '').toLowerCase().trim();
}

function resolveAccessRole(role, effectiveRole) {
  const explicit = String(effectiveRole || '').trim().toLowerCase();
  if (explicit) return explicit;
  const stored = getStoredEffectiveRole();
  if (stored) return stored;
  return String(role || '').trim().toLowerCase();
}

/* 1. APPLICATION LOADING COORDINATION */
const AppLoader = {
  isHeaderLoaded: false,
  isFooterLoaded: false,
  isDOMReady: false,
  initCallbacks: [],

  markHeaderLoaded() { this.isHeaderLoaded = true; this.checkAllLoaded(); },
  markFooterLoaded() { this.isFooterLoaded = true; this.checkAllLoaded(); },
  markDOMReady() { this.isDOMReady = true; this.checkAllLoaded(); },

  // Execute all init callbacks once DOM + header + footer are ready
  checkAllLoaded() {
    if (this.isHeaderLoaded && this.isFooterLoaded && this.isDOMReady) {
      this.initCallbacks.forEach(cb => { try { cb(); } catch (e) { console.error(e); } });
      this.initCallbacks = [];
    }
  },
  onAllLoaded(cb) {
    if (this.isHeaderLoaded && this.isFooterLoaded && this.isDOMReady) cb();
    else this.initCallbacks.push(cb);
  }
};
window.AppLoader = AppLoader;

let globalLayoutResizeObserver = null;
let globalLayoutSyncStarted = false;

function syncGlobalFixedLayoutOffset() {
  const root = document.documentElement;
  const header = document.getElementById('mainHeader');
  const footer = document.querySelector('footer');

  const headerHeight = header ? Math.max(0, Math.round(header.getBoundingClientRect().height)) : 0;
  const footerHeight = footer ? Math.max(0, Math.round(footer.getBoundingClientRect().height)) : 0;

  root.style.setProperty('--main-header-height', `${headerHeight}px`);
  root.style.setProperty('--main-footer-height', `${footerHeight}px`);
}

function initializeGlobalFixedLayoutOffsetSync() {
  if (globalLayoutSyncStarted) {
    syncGlobalFixedLayoutOffset();
    return;
  }

  globalLayoutSyncStarted = true;

  const attachObservers = () => {
    if (!('ResizeObserver' in window) || globalLayoutResizeObserver) return;
    globalLayoutResizeObserver = new ResizeObserver(() => {
      syncGlobalFixedLayoutOffset();
    });
  };

  const observeTargets = () => {
    const header = document.getElementById('mainHeader');
    const footer = document.querySelector('footer');
    if (globalLayoutResizeObserver) {
      if (header) globalLayoutResizeObserver.observe(header);
      if (footer) globalLayoutResizeObserver.observe(footer);
    }
    syncGlobalFixedLayoutOffset();
  };

  attachObservers();
  observeTargets();

  let attempts = 0;
  const maxAttempts = 60;
  const poll = setInterval(() => {
    attempts += 1;
    observeTargets();
    if (attempts >= maxAttempts) {
      clearInterval(poll);
    }
  }, 120);

  window.addEventListener('resize', syncGlobalFixedLayoutOffset, { passive: true });
  window.addEventListener('orientationchange', syncGlobalFixedLayoutOffset);
  window.addEventListener('load', syncGlobalFixedLayoutOffset);
}

/* APP SETTINGS MANAGER (Public-facing settings) */
const AppSettingsManager = {
  settings: null,
  loading: null,

  loadCached() {
    if (this.settings) return this.settings;

    const cached = localStorage.getItem('appSettings');
    if (cached) {
      try {
        this.settings = JSON.parse(cached);
      } catch {
        this.settings = null;
      }
    }

    return this.settings;
  },

  async load(options = {}) {
    if (this.loading) return this.loading;
    const timeoutMs = Number.isFinite(Number(options.timeoutMs)) ? Number(options.timeoutMs) : 1800;

    this.loadCached();

    this.loading = (async () => {
      let timeoutId = null;
      try {
        const controller = new AbortController();
        timeoutId = setTimeout(() => controller.abort('settings_timeout'), timeoutMs);
        const response = await fetch('../backend/api/get_public_settings.php', {
          signal: controller.signal,
          credentials: 'include',
          cache: 'no-store',
          headers: { 'Accept': 'application/json' }
        });
        clearTimeout(timeoutId);
        timeoutId = null;

        if (response.ok) {
          const data = await response.json();
          if (data.success && data.settings) {
            this.settings = data.settings;
            localStorage.setItem('appSettings', JSON.stringify(this.settings));
          }
        }
      } catch (error) {
        console.log('[warn] App settings load failed:', error.message);
      } finally {
        if (timeoutId) {
          clearTimeout(timeoutId);
        }
      }

      if (!this.settings) {
        this.loadCached();
      }

      this.applyToDom();
      return this.settings;
    })();

    return this.loading;
  },

  get(key, fallback = '') {
    if (this.settings && Object.prototype.hasOwnProperty.call(this.settings, key)) {
      return this.settings[key];
    }
    const cached = localStorage.getItem('appSettings');
    if (cached) {
      try {
        const parsed = JSON.parse(cached);
        if (Object.prototype.hasOwnProperty.call(parsed, key)) {
          return parsed[key];
        }
      } catch {
        return fallback;
      }
    }
    return fallback;
  },

  getBool(key, fallback = false) {
    const value = this.get(key, fallback);
    if (typeof value === 'boolean') {
      return value;
    }

    const normalized = String(value ?? '').trim().toLowerCase();
    if (['1', 'true', 'yes', 'on'].includes(normalized)) {
      return true;
    }
    if (['0', 'false', 'no', 'off', ''].includes(normalized)) {
      return false;
    }
    return Boolean(value);
  },

  getInt(key, fallback = 0) {
    const value = Number(this.get(key, fallback));
    return Number.isFinite(value) ? Math.round(value) : fallback;
  },

  applyToDom() {
    this.applyDocumentTitle();
    this.applyHeaderBrand();
    this.applyFooterBrand();
    this.applyLoginBanner();
  },

  applyDocumentTitle() {
    const appName = this.get('app_name', '');
    if (!appName) return;

    if (document.title.includes('PensionsGo')) {
      document.title = document.title.replace(/PensionsGo/g, appName);
      return;
    }
    if (!document.title.includes(appName)) {
      document.title = `${appName} - ${document.title}`;
    }
  },

  applyHeaderBrand() {
    const appName = this.get('app_name', '');
    if (!appName) return;

    const headerTitles = document.querySelectorAll('#mainHeader h1');
    headerTitles.forEach(el => {
      el.textContent = appName;
    });
  },

  applyFooterBrand() {
    const appName = this.get('app_name', '');
    if (appName) {
      document.querySelectorAll('#footerAppName').forEach(el => {
        el.textContent = appName;
      });
    }

    const tagline = (this.get('app_tagline', '') || '').trim();
    const taglineEl = document.getElementById('footerTagline');
    if (taglineEl) {
      if (tagline) {
        taglineEl.textContent = tagline;
        taglineEl.classList.remove('hidden');
      } else {
        taglineEl.classList.add('hidden');
      }
    }

    const escapeHtml = (value) => String(value || '')
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#39;');

    const orgName = (this.get('public_footer_org_name', '') || '').trim();
    const orgNameEl = document.getElementById('footerOrgName');
    if (orgNameEl && orgName) {
      orgNameEl.textContent = orgName;
    }

    const orgAddress = (this.get('public_footer_address', '') || '').trim();
    const orgAddressEl = document.getElementById('footerOrgAddress');
    if (orgAddressEl && orgAddress) {
      const safeAddress = escapeHtml(orgAddress).replace(/\r?\n/g, '<br />');
      orgAddressEl.innerHTML = safeAddress;
    }

    const supportEmail = (this.get('support_email', '') || '').trim();
    if (supportEmail) {
      const emailLink = document.getElementById('supportEmailLink');
      if (emailLink) {
        emailLink.textContent = supportEmail;
        emailLink.href = `mailto:${supportEmail}`;
      }
    }

    const supportPhone = (this.get('support_phone', '') || '').trim();
    if (supportPhone) {
      const phoneLink = document.getElementById('supportPhoneLink');
      if (phoneLink) {
        phoneLink.textContent = supportPhone;
        phoneLink.href = `tel:${supportPhone.replace(/\s+/g, '')}`;
      }
    }

    const techSupportEmail = (this.get('public_footer_tech_support_email', '') || '').trim();
    const techSupportLink = document.getElementById('techSupportEmailLink');
    if (techSupportLink) {
      const resolvedTechEmail = techSupportEmail || supportEmail;
      if (resolvedTechEmail) {
        techSupportLink.textContent = resolvedTechEmail;
        techSupportLink.href = `mailto:${resolvedTechEmail}`;
      }
    }

    const socialMap = [
      { key: 'public_footer_social_facebook', linkId: 'footerSocialFacebook', itemId: 'footerSocialFacebookItem' },
      { key: 'public_footer_social_twitter', linkId: 'footerSocialTwitter', itemId: 'footerSocialTwitterItem' },
      { key: 'public_footer_social_instagram', linkId: 'footerSocialInstagram', itemId: 'footerSocialInstagramItem' },
      { key: 'public_footer_social_linkedin', linkId: 'footerSocialLinkedin', itemId: 'footerSocialLinkedinItem' }
    ];

    socialMap.forEach(({ key, linkId, itemId }) => {
      const url = (this.get(key, '') || '').trim();
      const link = document.getElementById(linkId);
      const item = document.getElementById(itemId);
      if (!link || !item) return;
      if (url) {
        link.href = url;
        item.classList.remove('hidden');
      } else {
        item.classList.add('hidden');
      }
    });

    const devName = (this.get('public_footer_developer_name', '') || '').trim();
    const devEmail = (this.get('public_footer_developer_email', '') || '').trim();
    const devPhone = (this.get('public_footer_developer_phone', '') || '').trim();

    const devRow = document.getElementById('footerDeveloperRow');
    const devEmailLink = document.getElementById('footerDeveloperEmailLink');
    const devPhoneLink = document.getElementById('footerDeveloperPhoneLink');
    const devPhoneSeparator = document.getElementById('footerDeveloperPhoneSeparator');

    if (devRow) {
      const hasDevInfo = devName || devEmail || devPhone;
      devRow.classList.toggle('hidden', !hasDevInfo);
    }

    if (devEmailLink) {
      const displayName = devName || devEmail || devEmailLink.textContent;
      devEmailLink.textContent = displayName;
      if (devEmail) {
        devEmailLink.href = `mailto:${devEmail}`;
      } else {
        devEmailLink.removeAttribute('href');
      }
    }

    if (devPhoneLink) {
      if (devPhone) {
        devPhoneLink.href = `tel:${devPhone.replace(/\s+/g, '')}`;
        devPhoneLink.classList.remove('hidden');
        if (devPhoneSeparator) devPhoneSeparator.classList.remove('hidden');
      } else {
        devPhoneLink.classList.add('hidden');
        if (devPhoneSeparator) devPhoneSeparator.classList.add('hidden');
      }
    }
  },

  applyLoginBanner() {
    const banner = document.getElementById('loginBanner');
    if (!banner) return;

    const text = (this.get('login_banner', '') || '').trim();
    if (!text) {
      banner.classList.add('hidden');
      banner.textContent = '';
      return;
    }

    banner.textContent = text;
    banner.classList.remove('hidden');
  },

  formatDateTime(value, options = {}) {
    if (!value) return '';
    const date = new Date(value);
    if (Number.isNaN(date.getTime())) return '';

    const timeZone = this.get('timezone', 'Africa/Kampala') || 'Africa/Kampala';
    const timeFormat = this.get('time_format', '24h');
    const includeSeconds = Boolean(options.includeSeconds);
    const includeTime = options.includeTime !== false;
    const includeDate = options.includeDate !== false;
    const hour12 = timeFormat === '12h';

    const formatter = new Intl.DateTimeFormat('en-GB', {
      timeZone,
      year: 'numeric',
      month: 'short',
      day: 'numeric',
      hour: includeTime ? '2-digit' : undefined,
      minute: includeTime ? '2-digit' : undefined,
      second: includeTime && includeSeconds ? '2-digit' : undefined,
      hour12
    });

    const parts = formatter.formatToParts(date).reduce((acc, part) => {
      acc[part.type] = part.value;
      return acc;
    }, {});

    const year = parts.year || '';
    const month = parts.month || '';
    const day = parts.day || '';

    let dateString = '';
    if (includeDate) {
      dateString = `${day}-${month}-${year}`;
    }

    let timeString = '';
    if (includeTime && parts.hour) {
      timeString = `${parts.hour}:${parts.minute || '00'}`;
      if (includeSeconds && parts.second) {
        timeString += `:${parts.second}`;
      }
      if (hour12 && parts.dayPeriod) {
        timeString += ` ${parts.dayPeriod}`;
      }
    }

    if (dateString && timeString) return `${dateString} ${timeString}`;
    if (dateString) return dateString;
    return timeString;
  },

  isWithinQuietHours() {
    const start = this.get('notify_quiet_hours_start', '').trim();
    const end = this.get('notify_quiet_hours_end', '').trim();
    if (!start || !end) return false;

    const now = new Date();
    const nowMinutes = now.getHours() * 60 + now.getMinutes();
    const [startH, startM] = start.split(':').map(v => parseInt(v, 10));
    const [endH, endM] = end.split(':').map(v => parseInt(v, 10));
    if (Number.isNaN(startH) || Number.isNaN(endH)) return false;

    const startMinutes = startH * 60 + (Number.isNaN(startM) ? 0 : startM);
    const endMinutes = endH * 60 + (Number.isNaN(endM) ? 0 : endM);

    if (startMinutes === endMinutes) return false;
    if (startMinutes < endMinutes) {
      return nowMinutes >= startMinutes && nowMinutes < endMinutes;
    }
    return nowMinutes >= startMinutes || nowMinutes < endMinutes;
  },

  isBroadcastNotificationsEnabled() {
    const enabled = this.getBool('enable_notifications', true);
    const broadcasts = this.getBool('notify_broadcast_enabled', true);
    return enabled && broadcasts && !this.isWithinQuietHours();
  },

  isBroadcastSoundEnabled() {
    return this.isBroadcastNotificationsEnabled() && this.getBool('notify_broadcast_sound_enabled', true);
  },

  getBroadcastSoundPath() {
    const configured = String(this.get('notify_broadcast_sound_path', 'audio/notification.mp3') || '').trim();
    return configured || 'audio/notification.mp3';
  },

  getBroadcastSoundVolume() {
    const volume = this.getInt('notify_broadcast_sound_volume', 85);
    const clamped = Math.max(0, Math.min(100, volume));
    return clamped / 100;
  },

  getBroadcastSoundRepeatCount() {
    const repeats = this.getInt('notify_broadcast_sound_repeat_count', 1);
    return Math.max(1, Math.min(5, repeats));
  },

  isBroadcastDesktopEnabled() {
    return this.isBroadcastNotificationsEnabled()
      && this.getBool('notify_push_enabled', true)
      && this.getBool('notify_broadcast_desktop_enabled', true);
  },

  isBroadcastDesktopHiddenOnly() {
    return this.getBool('notify_broadcast_desktop_hidden_only', true);
  }
};

const ClientSecurityControls = {
  initialized: false,
  isAuthenticated: false,
  lastNoticeAt: 0,

  init() {
    if (this.initialized) return;
    document.addEventListener('contextmenu', (event) => this.handleContextMenu(event), true);
    document.addEventListener('copy', (event) => this.handleClipboard(event, 'copy'), true);
    document.addEventListener('cut', (event) => this.handleClipboard(event, 'cut'), true);
    document.addEventListener('paste', (event) => this.handleClipboard(event, 'paste'), true);
    document.addEventListener('selectstart', (event) => this.handleSelectStart(event), true);
    document.addEventListener('selectionchange', () => this.handleSelectionChange(), true);
    document.addEventListener('dragstart', (event) => this.handleDragStart(event), true);
    document.addEventListener('keydown', (event) => this.handleKeydown(event), true);
    this.initialized = true;
  },

  configure(isAuthenticated) {
    this.isAuthenticated = Boolean(isAuthenticated);
    this.init();
  },

  isEnabled(key) {
    return Boolean(AppSettingsManager.get(key, false));
  },

  shouldEnforce() {
    return this.isAuthenticated === true;
  },

  notify(message) {
    const now = Date.now();
    if (now - this.lastNoticeAt < 1800) return;
    this.lastNoticeAt = now;
    if (typeof window.appToast === 'function') {
      window.appToast(message, { type: 'warning', title: 'Restricted Action', duration: 2600 });
    }
  },

  block(event, message) {
    event.preventDefault();
    event.stopPropagation();
    this.notify(message);
  },

  isInteractiveTarget(target) {
    if (!(target instanceof Element)) {
      return false;
    }

    return Boolean(
      target.closest(
        'button, a, input, select, textarea, label, summary, [role="button"], [role="switch"], [role="tab"], [contenteditable="true"], .switch, .slider'
      )
    );
  },

  handleContextMenu(event) {
    if (!this.shouldEnforce() || !this.isEnabled('security_block_context_menu')) return;
    this.block(event, 'Right click is disabled on this page.');
  },

  handleClipboard(event, action) {
    if (!this.shouldEnforce()) return;
    const map = {
      copy: ['security_block_copy', 'Copy is disabled on this page.'],
      cut: ['security_block_cut', 'Cut is disabled on this page.'],
      paste: ['security_block_paste', 'Paste is disabled on this page.']
    };
    const [settingKey, message] = map[action] || [];
    if (!settingKey || !this.isEnabled(settingKey)) return;
    this.block(event, message);
  },

  handleSelectStart(event) {
    if (!this.shouldEnforce() || !this.isEnabled('security_block_text_selection')) return;
    if (this.isInteractiveTarget(event.target)) return;
    const selection = typeof window.getSelection === 'function' ? window.getSelection() : null;
    if (!selection || selection.isCollapsed) return;
    this.block(event, 'Text selection is disabled on this page.');
  },

  handleSelectionChange() {
    if (!this.shouldEnforce() || !this.isEnabled('security_block_text_selection')) return;
    const selection = typeof window.getSelection === 'function' ? window.getSelection() : null;
    if (!selection || selection.isCollapsed || selection.rangeCount < 1) return;

    const active = document.activeElement;
    if (active && ['INPUT', 'TEXTAREA'].includes(active.tagName)) {
      return;
    }

    let anchorElement = null;
    if (selection.anchorNode && selection.anchorNode.nodeType === Node.ELEMENT_NODE) {
      anchorElement = selection.anchorNode;
    } else if (selection.anchorNode && selection.anchorNode.parentElement) {
      anchorElement = selection.anchorNode.parentElement;
    }
    if (anchorElement && this.isInteractiveTarget(anchorElement)) {
      return;
    }

    selection.removeAllRanges();
  },

  handleDragStart(event) {
    if (!this.shouldEnforce() || !this.isEnabled('security_block_drag')) return;
    this.block(event, 'Dragging content is disabled on this page.');
  },

  handleKeydown(event) {
    if (!this.shouldEnforce()) return;
    const key = String(event.key || '').toLowerCase();
    const ctrlOrMeta = event.ctrlKey || event.metaKey;
    const shift = event.shiftKey;

    if (this.isEnabled('security_block_developer_tools')) {
      const devtoolsShortcut =
        key === 'f12' ||
        (ctrlOrMeta && shift && ['i', 'j', 'c', 'k'].includes(key)) ||
        (ctrlOrMeta && ['u'].includes(key));
      if (devtoolsShortcut) {
        this.block(event, 'Developer tools shortcuts are disabled on this page.');
        return;
      }
    }

    if (ctrlOrMeta && this.isEnabled('security_block_copy') && key === 'c') {
      this.block(event, 'Copy is disabled on this page.');
      return;
    }
    if (ctrlOrMeta && this.isEnabled('security_block_cut') && key === 'x') {
      this.block(event, 'Cut is disabled on this page.');
      return;
    }
    if (ctrlOrMeta && this.isEnabled('security_block_paste') && key === 'v') {
      this.block(event, 'Paste is disabled on this page.');
      return;
    }
    if (ctrlOrMeta && this.isEnabled('security_block_copy') && key === 'insert') {
      this.block(event, 'Copy is disabled on this page.');
      return;
    }
    if (shift && this.isEnabled('security_block_paste') && key === 'insert') {
      this.block(event, 'Paste is disabled on this page.');
      return;
    }
  }
};


function disableLegacyDevtoolsDetectors() {
  try {
    const basic = window.basicSecurity;
    if (basic) {
      if (basic.options && typeof basic.options === 'object') {
        basic.options.detectDevTools = false;
      }
      if (typeof basic.stopDevToolsDetection === 'function') {
        basic.stopDevToolsDetection();
      }
      basic.devToolsDetected = false;
    }

    const ctor = window.SecurityManager;
    if (typeof ctor === 'function' && ctor.prototype) {
      ctor.prototype.detectDevTools = function () {
        this.devToolsDetected = false;
        if (typeof this.stopDevToolsDetection === 'function') {
          this.stopDevToolsDetection();
        }
      };

      const originalShow = ctor.prototype.showSecurityWarning;
      ctor.prototype.showSecurityWarning = function (message, ...rest) {
        const text = String(message || '');
        if (/developer tools detected/i.test(text) || /security violation detected/i.test(text)) {
          return;
        }
        if (typeof originalShow === 'function') {
          return originalShow.call(this, message, ...rest);
        }
      };
    }
  } catch (_error) {
    // No-op: compatibility guard for legacy security bundles.
  }
}

window.AppSettingsManager = AppSettingsManager;

/* 2. CACHE & HISTORY PROTECTION */
(() => {
  // Disable cached page access after logout
  window.history.pushState(null, "", window.location.href);
  window.onpopstate = () => window.history.pushState(null, "", window.location.href);

  // Add anti-cache meta tags dynamically
  const metaTags = `
    <meta http-equiv="Cache-Control" content="no-store, no-cache, must-revalidate, max-age=0" />
    <meta http-equiv="Pragma" content="no-cache" />
    <meta http-equiv="Expires" content="0" />
    <link rel="icon" href="../favicon.ico" type="image/x-icon" />
  `;
  document.head.insertAdjacentHTML("beforeend", metaTags);

  // Detect and reload pages restored from back/forward cache
  window.addEventListener("pageshow", e => { 
    if (e.persisted) {
      console.log('[info] Page restored from bfcache - reloading');
      window.location.reload(); 
    }
  });

  const startupParams = new URLSearchParams(window.location.search || '');
  const isLoginRoute = window.location.pathname.includes("login.html") || window.location.pathname.endsWith("/");
  if (isLoginRoute && (startupParams.has('logout') || startupParams.has('reauth'))) {
    clearClientAuthState();
  }

  // Login page state is handled centrally during application bootstrap so
  // public-page navigation can enforce re-auth without racing session cleanup.
})();

/* 3. ENHANCED SESSION MANAGER CLASS */
const VIEWPORT_MODAL_ROOT_SELECTOR = [
  '[class*="modal-overlay"]',
  '.modal-system-overlay',
  '.dashboard-data-modal',
  '.claims-modal-overlay',
  '.registry-modal-overlay',
  '.workspace-modal-overlay',
  '.file-history-modal-overlay',
  '.feedback-modal-overlay',
  '.feedback-compose-overlay',
  '.pensioner-modal-overlay',
  '.pensioner-profile-edit-overlay',
  '.admin-modal-overlay',
  '.admin-confirm-overlay',
  '.app-ui-modal-overlay',
  '.security-overlay',
  '.access-denied-overlay',
  '.reauth-overlay',
  '.global-broadcast-overlay',
  '.logout-overlay',
  '.payroll-modal',
  '.session-overlay',
  '.session-warning-overlay',
  '.inactivity-warning'
].join(', ');

const RESPONSIVE_TABLE_SKIP_WRAPPER_SELECTOR = [
  '.app-table-scroll',
  '.table-responsive',
  '.table-scroll',
  '.dataTables_wrapper',
  '[class*="table-wrap"]',
  '[class*="table-container"]'
].join(', ');

const AUTO_CLOSE_MODAL_PANEL_SELECTOR = [
  '.auth-modal',
  '.claims-modal',
  '.app-ui-modal',
  '.modal-system',
  '.logout-modal',
  '.file-history-modal',
  '.registry-modal-panel',
  '.workspace-modal-panel',
  '.dashboard-data-modal-panel',
  '.status-modal-panel',
  '.task-modal-panel',
  '.pensioner-modal',
  '.admin-modal',
  '.admin-confirm-modal',
  '.payroll-modal-panel'
].join(', ');

const AUTO_CLOSE_MODAL_HEADER_SELECTOR = [
  '.auth-modal-header',
  '.claims-modal-header',
  '.app-ui-modal-header',
  '.modal-header',
  '.file-history-modal-header',
  '.registry-modal-header',
  '.workspace-modal-header',
  '.dashboard-data-modal-header',
  '.status-modal-header',
  '.task-modal-header',
  '.pensioner-modal-header',
  '.admin-modal-header',
  '.payroll-modal-header',
  '.queue-modal-header',
  '.logout-modal-header'
].join(', ');

const EXISTING_MODAL_CLOSE_SELECTOR = [
  '.app-modal-close-affordance',
  '.claims-modal-close',
  '.file-history-close',
  '.registry-modal-close',
  '.workspace-modal-close',
  '.dashboard-data-modal-close',
  '.task-modal-close',
  '.pensioner-modal-close',
  '.admin-modal-close',
  '.payroll-modal-close',
  '.feedback-modal-close',
  '.btn-close',
  '[aria-label="Close"]'
].join(', ');

let viewportModalRootObserver = null;

function collectViewportModalRoots(root = document) {
  const matches = [];
  if (!root) {
    return matches;
  }

  if (root instanceof Element) {
    const ancestorRoot = root.closest(VIEWPORT_MODAL_ROOT_SELECTOR);
    if (ancestorRoot) {
      matches.push(ancestorRoot);
    }
  }

  if (root instanceof Element && root.matches(VIEWPORT_MODAL_ROOT_SELECTOR)) {
    matches.push(root);
  }

  if (typeof root.querySelectorAll === 'function') {
    matches.push(...root.querySelectorAll(VIEWPORT_MODAL_ROOT_SELECTOR));
  }

  return matches;
}

function collectResponsiveTables(root = document) {
  const matches = [];
  if (!root) {
    return matches;
  }

  if (root instanceof HTMLTableElement) {
    matches.push(root);
  }

  if (typeof root.querySelectorAll === 'function') {
    matches.push(...root.querySelectorAll('table'));
  }

  return matches;
}

function shouldWrapResponsiveTable(table) {
  if (!(table instanceof HTMLTableElement)) {
    return false;
  }

  if (table.dataset.responsiveTableBound === '1') {
    return false;
  }

  if (!table.parentElement || table.closest('thead, tbody, tfoot')) {
    return false;
  }

  if (table.matches('.no-responsive-wrap, [data-no-responsive-wrap="true"]')) {
    return false;
  }

  if (table.closest(RESPONSIVE_TABLE_SKIP_WRAPPER_SELECTOR)) {
    return false;
  }

  return true;
}

function enhanceResponsiveTables(root = document) {
  collectResponsiveTables(root).forEach((table) => {
    if (!shouldWrapResponsiveTable(table)) {
      return;
    }

    const wrapper = document.createElement('div');
    wrapper.className = 'app-table-scroll';
    table.parentNode.insertBefore(wrapper, table);
    wrapper.appendChild(table);
    table.dataset.responsiveTableBound = '1';
  });
}

function buttonLooksLikeDismissControl(control) {
  if (!(control instanceof HTMLElement)) {
    return false;
  }

  const identity = [
    control.getAttribute('aria-label') || '',
    control.getAttribute('data-close-modal') !== null ? 'close' : '',
    control.getAttribute('data-modal-close') !== null ? 'close' : '',
    control.getAttribute('data-close-alert') !== null ? 'close' : '',
    control.getAttribute('data-data-modal-close') !== null ? 'close' : '',
    control.getAttribute('data-demographics-modal-close') !== null ? 'close' : '',
    control.id || '',
    control.className || '',
    control.textContent || ''
  ].join(' ').toLowerCase();

  if (!identity.trim()) {
    return false;
  }

  if (/(confirm|submit|save|delete|remove|verify|approve|logout|proceed|continue|upload|import|apply|retry|\byes\b)/.test(identity)) {
    return false;
  }

  return /(close|cancel|dismiss|back|done|\bok\b|\bokay\b|\bno\b)/.test(identity);
}

function resolveDismissControl(panel) {
  if (!(panel instanceof HTMLElement)) {
    return null;
  }

  const preferredContainers = panel.querySelectorAll([
    '.auth-modal-footer',
    '.claims-modal-footer',
    '.app-ui-modal-actions',
    '.modal-actions',
    '.file-history-modal-footer',
    '.workspace-modal-footer',
    '.registry-modal-footer',
    '.dashboard-data-modal-footer',
    '.task-modal-footer',
    '.admin-modal-footer',
    '.pensioner-modal-footer',
    '.payroll-modal-footer'
  ].join(', '));

  const orderedCandidates = [];
  preferredContainers.forEach((container) => {
    orderedCandidates.push(...container.querySelectorAll('button, a, [role="button"]'));
  });

  if (orderedCandidates.length === 0) {
    orderedCandidates.push(...panel.querySelectorAll('button, a, [role="button"]'));
  }

  return orderedCandidates.find((control) => buttonLooksLikeDismissControl(control)) || null;
}

function enhanceDismissibleModals(root = document) {
  const processed = new Set();
  collectViewportModalRoots(root).forEach((modalRoot) => {
    if (!(modalRoot instanceof HTMLElement) || processed.has(modalRoot)) {
      return;
    }
    processed.add(modalRoot);

    if (modalRoot.matches('.session-overlay, .session-warning-overlay, .inactivity-warning')) {
      return;
    }

    const panel = modalRoot.querySelector(AUTO_CLOSE_MODAL_PANEL_SELECTOR);
    if (!(panel instanceof HTMLElement)) {
      return;
    }

    if (panel.querySelector(EXISTING_MODAL_CLOSE_SELECTOR)) {
      return;
    }

    const header = panel.querySelector(AUTO_CLOSE_MODAL_HEADER_SELECTOR);
    if (!(header instanceof HTMLElement)) {
      return;
    }

    const dismissControl = resolveDismissControl(panel);
    if (!(dismissControl instanceof HTMLElement)) {
      return;
    }

    const closeButton = document.createElement('button');
    closeButton.type = 'button';
    closeButton.className = 'app-modal-close-affordance';
    closeButton.setAttribute('aria-label', 'Close');
    closeButton.innerHTML = '&times;';
    closeButton.addEventListener('click', (event) => {
      event.preventDefault();
      event.stopPropagation();
      dismissControl.click();
    });

    header.appendChild(closeButton);
  });
}

function hoistViewportModalRoots(root = document) {
  const host = document.body || document.documentElement;
  if (!host) {
    return;
  }

  const seen = new Set();
  collectViewportModalRoots(root).forEach((modalRoot) => {
    if (!(modalRoot instanceof HTMLElement) || seen.has(modalRoot)) {
      return;
    }
    seen.add(modalRoot);

    if (modalRoot.parentElement !== host) {
      host.appendChild(modalRoot);
    }

    modalRoot.dataset.viewportModalRoot = 'true';
    enhanceDismissibleModals(modalRoot);
  });
}

function observeViewportModalRoots() {
  if (viewportModalRootObserver || !document.body) {
    return;
  }

  hoistViewportModalRoots(document);

  viewportModalRootObserver = new MutationObserver((mutations) => {
    mutations.forEach((mutation) => {
      mutation.addedNodes.forEach((node) => {
        if (node instanceof Element) {
          hoistViewportModalRoots(node);
          enhanceResponsiveTables(node);
        }
      });
    });
  });

  viewportModalRootObserver.observe(document.body, {
    childList: true,
    subtree: true
  });
}

function normalizeViewportDialogOverlay(overlay, {
  contentSelector = '',
  zIndexCssVar = '--z-modal-layer'
} = {}) {
  if (!overlay) return null;

  const host = document.body || document.documentElement;
  if (host) {
    host.appendChild(overlay);
  }

  overlay.hidden = false;
  overlay.dataset.viewportModalRoot = 'true';
  overlay.setAttribute('aria-hidden', 'false');
  overlay.setAttribute('role', 'dialog');
  overlay.setAttribute('aria-modal', 'true');
  overlay.style.position = 'fixed';
  overlay.style.inset = '0';
  overlay.style.display = 'grid';
  overlay.style.placeItems = 'center';
  overlay.style.alignItems = 'center';
  overlay.style.justifyItems = 'center';
  overlay.style.visibility = 'visible';
  overlay.style.opacity = '1';
  overlay.style.pointerEvents = 'auto';
  overlay.style.zIndex = `var(${zIndexCssVar}, 2147483647)`;

  if (contentSelector) {
    const content = overlay.querySelector(contentSelector);
    if (content) {
      content.hidden = false;
      content.setAttribute('aria-hidden', 'false');
      content.style.visibility = 'visible';
      content.style.opacity = '1';
      content.style.pointerEvents = 'auto';
      content.style.position = 'relative';
      content.style.zIndex = '1';
    }
  }

  return overlay;
}

function syncSessionStateModalLayer(forceActive = null) {
  const hasActiveSessionModal = forceActive === null
    ? Boolean(document.querySelector('.session-overlay, .session-warning-overlay, .inactivity-warning'))
    : Boolean(forceActive);

  document.documentElement?.classList.toggle('session-state-modal-open', hasActiveSessionModal);
  document.body?.classList.toggle('session-state-modal-open', hasActiveSessionModal);
}

function normalizeSessionOverlay(overlay, { message = '', forceStatic = false } = {}) {
  if (!overlay) return null;

  const shouldForceStatic = forceStatic || document.hidden || document.visibilityState !== 'visible';
  syncSessionStateModalLayer(true);
  normalizeViewportDialogOverlay(overlay, {
    contentSelector: '.session-overlay-content',
    zIndexCssVar: '--z-session-overlay'
  });

  if (shouldForceStatic) {
    overlay.classList.add('session-overlay-static');
  } else {
    overlay.classList.remove('session-overlay-static');
  }

  const content = overlay.querySelector('.session-overlay-content');
  if (content) {
    content.hidden = false;
    content.setAttribute('aria-hidden', 'false');
    content.style.visibility = 'visible';
    content.style.opacity = '1';
    content.style.pointerEvents = 'auto';
    content.style.position = 'relative';
    content.style.zIndex = '1';

    if (shouldForceStatic) {
      content.style.animation = 'none';
      content.style.transform = 'translateY(0) scale(1)';
    } else {
      content.style.removeProperty('animation');
      content.style.removeProperty('transform');
    }

    if (message) {
      const bodyText = content.querySelector('p');
      if (bodyText) {
        bodyText.textContent = message;
      }
    }
  }

  const focusTarget = overlay.querySelector('button, [href], input, select, textarea, [tabindex]:not([tabindex="-1"])');
  if (focusTarget) {
    requestAnimationFrame(() => {
      try {
        focusTarget.focus({ preventScroll: true });
      } catch (_error) {
        focusTarget.focus();
      }
    });
  }

  return overlay;
}

function normalizeSessionWarningOverlay(overlay, { message = '' } = {}) {
  if (!overlay) return null;

  syncSessionStateModalLayer(true);
  normalizeViewportDialogOverlay(overlay, {
    contentSelector: '.session-warning-modal, .warning-content',
    zIndexCssVar: '--z-session-overlay'
  });

  const content = overlay.querySelector('.session-warning-modal, .warning-content');
  if (content && message) {
    const bodyText = content.querySelector('p');
    if (bodyText) {
      bodyText.textContent = message;
    }
  }

  const focusTarget = overlay.querySelector('button, [href], input, select, textarea, [tabindex]:not([tabindex="-1"])');
  if (focusTarget) {
    requestAnimationFrame(() => {
      try {
        focusTarget.focus({ preventScroll: true });
      } catch (_error) {
        focusTarget.focus();
      }
    });
  }

  return overlay;
}

class SessionManager {
  constructor() {
    this.sessionExpired = false;
    this.sessionExpiredMessage = '';
    this.lastActivity = Date.now();
    this.timeoutWarningShown = false;
    this.worker = null;
    this.tabId = `tab_${Date.now()}_${Math.random().toString(36).substr(2, 9)}`;
    this.sessionSettings = null;
    this.consecutiveFailures = 0;
    this.maxConsecutiveFailures = 5;
    this.pollingInterval = 15000;
    this.adaptivePolling = true;
    this.activeNotifications = new Set();
    this.apiPathCache = new Map();
    this.lastKeepAliveWarnAt = 0;
    this.lastBroadcastErrorLogAt = 0;
    this.lastBroadcastSoundErrorLogAt = 0;
    this.broadcastAudioWarmupBound = false;
    this.currentBroadcastSoundStop = null;
    
    // Bind methods
    this.updateSessionActivity = this.updateSessionActivity.bind(this);
    this.handleSessionWorkerMessage = this.handleSessionWorkerMessage.bind(this);
    this.showTimeoutWarning = this.showTimeoutWarning.bind(this);
  }

  buildApiCandidates(endpoint) {
    const cleanEndpoint = String(endpoint || '').replace(/^\/+/, '');
    if (!cleanEndpoint) return [];

    if (this.apiPathCache.has(cleanEndpoint)) {
      return this.apiPathCache.get(cleanEndpoint);
    }

    const candidates = [];
    const seen = new Set();
    const pushCandidate = (url) => {
      const key = String(url || '').trim();
      if (!key || seen.has(key)) return;
      seen.add(key);
      candidates.push(key);
    };

    // 1) Relative to current page (works for normal /frontend/*.html routes)
    pushCandidate(`../backend/api/${cleanEndpoint}`);

    // 2) Resolve using module path as fallback (handles unusual page paths)
    try {
      pushCandidate(new URL(`../../backend/api/${cleanEndpoint}`, import.meta.url).href);
    } catch (error) {
      // ignore URL build failures
    }

    // 3) Absolute fallback from detected project root in current pathname
    try {
      const origin = window.location.origin;
      const pathname = window.location.pathname || '/';
      const lowered = pathname.toLowerCase();
      let projectRoot = pathname.replace(/\/[^\/]*$/, '');
      const frontendIdx = lowered.indexOf('/frontend/');
      if (frontendIdx >= 0) {
        projectRoot = pathname.slice(0, frontendIdx);
      } else if (lowered.endsWith('/frontend')) {
        projectRoot = pathname.slice(0, lowered.lastIndexOf('/frontend'));
      }
      if (!projectRoot) {
        projectRoot = '';
      }
      pushCandidate(`${origin}${projectRoot}/backend/api/${cleanEndpoint}`);
    } catch (error) {
      // ignore URL build failures
    }

    this.apiPathCache.set(cleanEndpoint, candidates);
    return candidates;
  }

  async fetchApiWithFallback(endpoint, options = {}) {
    const candidates = this.buildApiCandidates(endpoint);
    const requestOptions = {
      credentials: 'include',
      cache: 'no-store',
      ...options
    };

    let lastError = null;

    for (const url of candidates) {
      try {
        const response = await fetch(url, requestOptions);
        if (response.status === 404) {
          lastError = new Error(`404 Not Found: ${url}`);
          continue;
        }
        return response;
      } catch (error) {
        lastError = error;
      }
    }

    if (lastError) {
      throw lastError;
    }
    throw new Error(`Unable to resolve API endpoint: ${endpoint}`);
  }
  
  // Initialize session management
  async initialize() {
    console.log('[init] Initializing Enhanced Session Manager');
    
    const isLoginPage = window.location.pathname.includes("login.html") ||
                       window.location.pathname.endsWith("/");
    const isLoggedIn = sessionStorage.getItem('isLoggedIn') === 'true';
    
    if (isLoggedIn && !isLoginPage) {
      // Load session settings
      await this.loadSessionSettings();
      
      // Initialize Web Worker
      this.initializeSessionWorker();
      
      // Set up activity listeners
      this.initializeActivityListeners();
      
      // Set up cross-tab synchronization
      this.initializeCrossTabSync();
      
      // Initialize broadcast checker
      this.initializeBroadcastChecker();
      
      // Initial session check
      setTimeout(() => this.performSessionCheck(), 1000);
      
      console.log('[ok] Session Manager initialized with adaptive polling');
    }
  }
  
  // Load session settings from server
  async loadSessionSettings() {
    try {
      const response = await fetch('../backend/api/get_session_settings.php', {
        credentials: 'include',
        cache: 'no-store',
        headers: withDeviceTokenHeaders({ 'X-Requested-With': 'XMLHttpRequest' })
      });
      
      if (response.ok) {
        const data = await response.json();
        if (data.success) {
          this.sessionSettings = data.settings;
          console.log('[settings] Session settings loaded:', this.sessionSettings);
        }
      }
    } catch (error) {
      console.log('[warn] Could not load session settings:', error.message);
      // Use defaults
      this.sessionSettings = {
        session_timeout: 1800,
        grace_period_minutes: 5,
        inactivity_warning_minutes: 5,
        allow_multiple_devices: false
      };
    }
  }
  
  // Initialize Web Worker for background session monitoring (Dynamic BASE_API Injection)
  initializeSessionWorker() {
      if (typeof Worker === "undefined") {
          console.warn("Web Workers not supported. Running fallback monitoring.");
          this.startMainThreadMonitoring();
          return;
      }

      try {
          const workerURL = new URL("./modules/session-worker.js?v=20260606b", import.meta.url);
          this.worker = new Worker(workerURL, { type: "module" });

          this.worker.onmessage = this.handleSessionWorkerMessage;
          this.worker.onerror = (err) => {
              console.error("[error] Worker error:", err);
              this.startMainThreadMonitoring();
          };

          /** 
           * 
           * PROPER BACKEND PATH RESOLUTION (Fixes 404)
           * 
           *
           * main.js -> /frontend/js/main.js
           *
           * We need to go:
           *   /frontend/js/         -> go up twice -> project root
           *   /backend/api/         -> append this
           *
           * This ensures the correct path even if project folder changes.
           */
          const computedBaseApi = new URL(
              "../../backend/api/",
              import.meta.url
          ).href;

          console.log("[info] Worker BASE_API set to:", computedBaseApi);

          // Send configuration to worker
          this.worker.postMessage({
              type: "CONFIG",
              BASE_API: computedBaseApi,
              tabId: this.tabId,
              deviceToken: getPersistentDeviceToken(),
              hostedSessionId: (localStorage.getItem(HOSTED_SESSION_ID_STORAGE_KEY) || '').trim(),
              hostedSessionUser: (localStorage.getItem(HOSTED_SESSION_USER_STORAGE_KEY) || '').trim()
          });

          // Start monitoring
          setTimeout(() => {
              this.worker.postMessage({
                  type: "START_MONITORING",
                  tabId: this.tabId
              });
          }, 1500);

          console.log("[ok] Session Worker initialized.");

      } catch (err) {
          console.error("[error] Failed to init Session Worker:", err);
          this.startMainThreadMonitoring();
      }
  }

  
  // Handle messages from session worker
  handleSessionWorkerMessage(event) {
    switch (event.data.type) {
      case 'WORKER_READY':
        console.log('[ok] Session Worker ready');
        break;
        
      case 'SESSION_CHECK_RESULT':
        this.handleSessionCheckResult(event.data);
        break;
        
      case 'SESSION_CHECK_ERROR':
        this.handleSessionCheckError(event.data);
        break;
        
      case 'SESSION_EXPIRED':
        this.handleSessionExpired(event.data);
        break;
        
      case 'WORKER_HEARTBEAT':
        // Worker is alive, update UI if needed
        break;
        
      case 'TABS_UPDATED':
        console.log(`[info] Active tabs: ${event.data.count}`);
        break;
    }
  }
  
  // Fallback to main thread monitoring
  startMainThreadMonitoring() {
    console.log('[init] Starting main thread session monitoring');
    
    // Adaptive polling based on network conditions
    const performCheck = async () => {
      if (this.sessionExpired) return;
      
      await this.performSessionCheck();
      
      // Schedule next check with adaptive interval
      const nextCheck = this.adaptivePolling ? 
        this.calculateAdaptiveInterval() : 
        this.pollingInterval;
      
      setTimeout(performCheck, nextCheck);
    };
    
    // Initial check
    setTimeout(performCheck, 2000);
  }
  
  // Calculate adaptive polling interval
  calculateAdaptiveInterval() {
    if (this.consecutiveFailures > 0) {
      // Exponential backoff for failures
      return Math.min(
        this.pollingInterval * Math.pow(1.5, this.consecutiveFailures),
        30000 // Max 30 seconds
      );
    }
    
    // Normal adaptive interval
    const lastActivityTime = Date.now() - this.lastActivity;
    
    if (lastActivityTime > 300000) { // 5 minutes inactive
      return 30000; // Check every 30 seconds
    } else if (lastActivityTime > 60000) { // 1 minute inactive
      return 20000; // Check every 20 seconds
    }
    
    return 15000; // Default 15 seconds
  }
  
  // Perform session check
  async performSessionCheck() {
    if (this.sessionExpired) return;
    
    const startTime = Date.now();
    let timeoutId = null;
    
    try {
      const controller = new AbortController();
      timeoutId = setTimeout(() => controller.abort('session_check_timeout'), 10000);
      
      const response = await fetch('../backend/api/check_session.php', {
        signal: controller.signal,
        credentials: 'include',
        cache: 'no-store',
        headers: withDeviceTokenHeaders({
          'X-Requested-With': 'XMLHttpRequest'
        })
      });
      
      clearTimeout(timeoutId);
      timeoutId = null;
      const duration = Date.now() - startTime;
      
      if (!response.ok) {
        throw new Error(`HTTP ${response.status}`);
      }
      
      const data = await response.json();
      
      // Reset failure counter on success
      this.consecutiveFailures = 0;
      if (data?.active) {
        rememberSessionValidationState({
          active: true,
          userId: data.userId || '',
          userName: data.userName || 'User',
          userRole: String(data.userRole || '').toLowerCase(),
          userRoleEffective: String(data.userRoleEffective || data.userRole || '').toLowerCase(),
          userPhoto: data.userPhoto || ''
        });
      } else {
        sessionStorage.removeItem(SESSION_VALIDATION_CACHE_KEY);
      }
      
      return this.handleSessionCheckResult({
        data: data,
        duration: duration,
        timestamp: Date.now()
      });
      
    } catch (error) {
      this.consecutiveFailures++;
      return this.handleSessionCheckError({
        error: normalizeRequestErrorMessage(error, 'Session check timed out.'),
        consecutiveFailures: this.consecutiveFailures,
        duration: Date.now() - startTime,
        silent: isAbortLikeError(error) || String(error?.message || error || '').includes('session_check_timeout')
      });
    } finally {
      if (timeoutId) {
        clearTimeout(timeoutId);
      }
    }
  }
  
  // Handle successful session check
  handleSessionCheckResult(checkData) {
    const data = checkData.data;
    
    if (data.active) {
      // Update session info
      if (data.userName) {
        this.updateUserDisplay(data);
      }
      
      // Check for timeout warning
      if (data.time_until_timeout) {
        const warningThreshold = (this.sessionSettings?.inactivity_warning_minutes || 5) * 60;
        
        if (data.time_until_timeout <= warningThreshold && !this.timeoutWarningShown) {
          this.showTimeoutWarning(data.time_until_timeout);
        }
      }
      
      return data;
    } else {
      // Session is not active
      this.handleSessionEnd(data);
      return data;
    }
  }
  
  // Handle session check error
  handleSessionCheckError(errorData) {
    if (!errorData?.silent) {
      console.warn('[warn] Session check failed:', errorData.error);
    }
    
    // If we have too many consecutive failures, show warning
    if (errorData.consecutiveFailures >= this.maxConsecutiveFailures) {
      this.showNetworkWarning();
    }
    
    // Check local session state
    if (this.isUserLikelyLoggedIn()) {
      // Maintain session despite network issues
      console.log('[offline] Network issue - maintaining local session state');
      return { active: true, networkError: true };
    } else {
      // Network error and local state suggests logged out
      this.handleSessionExpired({
        reason: 'network_error',
        message: 'Unable to verify session due to network issues'
      });
      return { active: false, networkError: true };
    }
  }
  
  // Handle session termination
  handleSessionEnd(sessionData) {
    if (this.sessionExpired) return;
    
    this.sessionExpired = true;
    this.sessionExpiredMessage = String(sessionData.message || '');
    
    // Stop worker if running
    if (this.worker) {
      this.worker.postMessage({ type: 'STOP_MONITORING' });
      this.worker.terminate();
      this.worker = null;
    }
    
    // Clear activity listeners
    this.removeActivityListeners();
    
    // Store current page for potential return
    localStorage.setItem("lastVisitedPage", window.location.href);
    if (isAuthRequiredPage()) {
      rememberLastSecurePage(window.location.href);
      sessionStorage.removeItem(PUBLIC_SESSION_ALLOWANCE_KEY);
    }
    
    // Clear storage
    this.clearUserData();
    
    // Show appropriate overlay
    if (sessionData.reason === 'device_conflict') {
      this.showDeviceConflictOverlay(sessionData.message);
    } else {
      this.showSessionExpiredOverlay(sessionData.message);
    }
  }
  
  // Handle session expired event from worker
  handleSessionExpired(eventData) {
    this.handleSessionEnd({
      reason: eventData.reason || 'unknown',
      message: eventData.message || 'Session expired'
    });
  }
  
  // Update user display
  updateUserDisplay(userData) {
    if (!userData) return;
    
    // Update user name displays
    const nameElements = document.querySelectorAll('.user-name, [data-user="name"]');
    nameElements.forEach(element => {
      if (element.closest('.user-management-content, .users-table, .user-logs-content')) {
        return;
      }
      if (userData.userName) {
        element.textContent = userData.userName;
      }
    });
    
    // Update user role displays
    const roleElements = document.querySelectorAll('.user-role, [data-user="role"]');
    roleElements.forEach(element => {
      if (element.closest('.user-management-content, .users-table, .user-logs-content')) {
        return;
      }
      if (userData.userRole) {
        element.textContent = userData.userRole;
      }
    });
    
    // Update user photo
    const photoElements = document.querySelectorAll('.user-photo, [data-user="photo"]');
    photoElements.forEach(element => {
      if (element.tagName === 'IMG' && userData.userPhoto) {
        element.src = userData.userPhoto;
      }
    });
  }
  
  // Check if user is likely still logged in
  isUserLikelyLoggedIn() {
    const loggedInUser = localStorage.getItem('loggedInUser');
    const lastActivity = sessionStorage.getItem('lastActivity');
    
    if (!loggedInUser) return false;
    
    try {
      const userData = JSON.parse(loggedInUser);
      if (!userData.id || !userData.role) return false;
      
      // Check if last activity was within reasonable time
      if (lastActivity) {
        const lastActivityTime = parseInt(lastActivity);
        const currentTime = Date.now();
        const gracePeriod = (this.sessionSettings?.grace_period_minutes || 5) * 60 * 1000;
        const extendedTimeout = 35 * 60 * 1000; // 35 minutes
        
        return (currentTime - lastActivityTime) < (extendedTimeout + gracePeriod);
      }
      
      return true;
    } catch (error) {
      console.error('Error checking user login status:', error);
      return false;
    }
  }
  
  // Update session activity
  updateSessionActivity() {
    if (this.sessionExpired) return;
    
    this.lastActivity = Date.now();
    sessionStorage.setItem('lastActivity', this.lastActivity.toString());
    
    // Send activity to worker if running
    if (this.worker) {
      this.worker.postMessage({
        type: 'USER_ACTIVITY',
        activityType: 'user_interaction'
      });
    }
    
    // Throttle keep-alive requests
    if (window.lastKeepAlive && (Date.now() - window.lastKeepAlive < 2000)) {
      return;
    }
    window.lastKeepAlive = Date.now();
    
    // Send keep-alive request
    this.sendKeepAlive();
  }
  
  // Send keep-alive request
  async sendKeepAlive() {
    try {
      const response = await this.fetchApiWithFallback('keep_alive.php', {
        method: 'POST',
        headers: withDeviceTokenHeaders({
          'X-Requested-With': 'XMLHttpRequest'
        })
      });
      
      if (!response.ok) {
        if (response.status === 401 || response.status === 403) {
          this.performSessionCheck();
          return;
        }

        const now = Date.now();
        if ((now - this.lastKeepAliveWarnAt) > 60000) {
          console.warn('Keep-alive returned', response.status);
          this.lastKeepAliveWarnAt = now;
        }
      }
    } catch (error) {
      const now = Date.now();
      if ((now - this.lastKeepAliveWarnAt) > 60000) {
        console.warn('Keep-alive failed:', error?.message || error);
        this.lastKeepAliveWarnAt = now;
      }
    }
  }
  
  // Initialize activity listeners
  initializeActivityListeners() {
    const activityEvents = [
      'click', 'mousemove', 'mousedown', 'mouseup',
      'keydown', 'keyup', 'keypress',
      'scroll', 'wheel',
      'touchstart', 'touchmove', 'touchend',
      'focus', 'blur', 'input', 'change'
    ];

    activityEvents.forEach(eventType => {
      document.addEventListener(eventType, this.updateSessionActivity, { 
        passive: true,
        capture: true 
      });
    });

    // Track visibility changes
    document.addEventListener('visibilitychange', () => {
      if (!document.hidden) {
        if (this.sessionExpired) {
          this.ensureSessionOverlayVisible();
          return;
        }
        this.updateSessionActivity();
        
        // Check session immediately when tab becomes visible
        if (sessionStorage.getItem('isLoggedIn') === 'true' && !this.sessionExpired) {
          setTimeout(() => this.performSessionCheck(), 500);
        }
      }
    });

    // Track page focus
    window.addEventListener('focus', () => {
      if (this.sessionExpired) {
        this.ensureSessionOverlayVisible();
        return;
      }
      this.updateSessionActivity();
      
      if (sessionStorage.getItem('isLoggedIn') === 'true' && !this.sessionExpired) {
        setTimeout(() => this.performSessionCheck(), 500);
      }
    });
    
    console.log('[ok] Activity listeners initialized');
  }
  
  // Remove activity listeners
  removeActivityListeners() {
    const activityEvents = [
      'click', 'mousemove', 'mousedown', 'mouseup',
      'keydown', 'keyup', 'keypress',
      'scroll', 'wheel',
      'touchstart', 'touchmove', 'touchend',
      'focus', 'blur', 'input', 'change'
    ];

    activityEvents.forEach(eventType => {
      document.removeEventListener(eventType, this.updateSessionActivity, { 
        passive: true,
        capture: true 
      });
    });
  }
  
  // Initialize cross-tab synchronization
  initializeCrossTabSync() {
    // Listen for storage events (other tabs logging out)
    window.addEventListener('storage', (e) => {
      if (e.key === 'session_logout_event') {
        const logoutEvent = JSON.parse(e.newValue || '{}');
        if (logoutEvent.tabId !== this.tabId) {
          console.log('[sync] Other tab logged out - synchronizing');
          this.handleSessionExpired({
            reason: 'cross_tab_logout',
            message: 'Logged out from another tab'
          });
        }
      }
    });
    
    // Broadcast logout to other tabs
    this.broadcastLogoutToOtherTabs = () => {
      localStorage.setItem('session_logout_event', JSON.stringify({
        tabId: this.tabId,
        timestamp: Date.now(),
        reason: 'user_logout'
      }));
      
      // Remove after 1 second to avoid storage buildup
      setTimeout(() => {
        localStorage.removeItem('session_logout_event');
      }, 1000);
    };
  }
  
  // Clear user data
  clearUserData() {
    console.log("[cleanup] Clearing all user data...");
    
    // Clear user-specific localStorage
    localStorage.removeItem('loggedInUser');
    localStorage.removeItem('userRole');
    localStorage.removeItem('userRoleEffective');
    localStorage.removeItem('pensionsgo_seen_broadcasts');
    localStorage.removeItem('lastVisitedPage');
    localStorage.removeItem(LAST_SECURE_PAGE_KEY);
    localStorage.removeItem('sessionSettings');
    localStorage.removeItem(HOSTED_SESSION_ID_STORAGE_KEY);
    localStorage.removeItem(HOSTED_SESSION_USER_STORAGE_KEY);
    localStorage.removeItem(HOSTED_SESSION_VERIFIED_AT_STORAGE_KEY);
    clearHostedSessionClientCookies();
    
    // Clear all sessionStorage
    sessionStorage.clear();
    
    // Close any active notifications properly
    this.closeAllNotifications();
    
    // Clear any form data or temporary storage
    if (typeof window.clearUserData === 'function') {
      window.clearUserData();
    }
    
    // Dispatch event for other modules to clean up
    window.dispatchEvent(new CustomEvent('userLoggedOut'));
    
    console.log('[ok] All user data cleared from client storage');
  }
  
  // Close all notifications
  closeAllNotifications() {
    this.activeNotifications.forEach((notification) => {
      try {
        notification.close();
      } catch (_error) {
        // Ignore notification close failures.
      }
    });
    this.activeNotifications.clear();

    if ('Notification' in window && Notification.permission === 'granted') {
      // Get all active service worker registrations and close their notifications
      if ('serviceWorker' in navigator) {
        navigator.serviceWorker.getRegistrations().then(registrations => {
          registrations.forEach(registration => {
            registration.getNotifications().then(notifications => {
              notifications.forEach(notification => notification.close());
            });
          });
        });
      }
    }
  }
  
  // Show timeout warning
  showTimeoutWarning(secondsRemaining) {
    if (this.timeoutWarningShown) return;
    
    this.timeoutWarningShown = true;
    
    const minutes = Math.ceil(secondsRemaining / 60);
    const message = `Your session will expire in ${minutes} minute${minutes !== 1 ? 's' : ''} due to inactivity.`;
    const existingWarning = document.querySelector('.session-warning-overlay, .inactivity-warning');
    if (existingWarning) {
      normalizeSessionWarningOverlay(existingWarning, { message });
      return;
    }
    
    // Create warning modal
    const warningModal = document.createElement('div');
    warningModal.className = 'session-warning-overlay inactivity-warning';
    warningModal.innerHTML = `
        <div class="session-warning-modal warning-content">
        <div class="session-modal-header">
          <div class="warning-icon session-warning-icon" aria-hidden="true"><span>!</span></div>
          <h3>Session About to Expire</h3>
        </div>
        <div class="session-modal-body">
          <p></p>
          <div class="warning-actions">
            <button id="extendSession" class="warning-btn primary btn-primary">Stay Logged In</button>
            <button id="logoutNow" class="warning-btn secondary btn-secondary">Logout Now</button>
          </div>
        </div>
      </div>
    `;
    
    document.body.appendChild(warningModal);
    normalizeSessionWarningOverlay(warningModal, { message });

    const closeWarningModal = () => {
      warningModal.remove();
      syncSessionStateModalLayer();
    };
    
    // Add event listeners
    warningModal.querySelector('#extendSession')?.addEventListener('click', () => {
      this.updateSessionActivity();
      closeWarningModal();
      this.timeoutWarningShown = false;
    });
    
    warningModal.querySelector('#logoutNow')?.addEventListener('click', () => {
      closeWarningModal();
      this.performLogout('user_initiated', 'User chose to logout from warning');
    });
    
    // Auto-remove after 30 seconds
    setTimeout(() => {
      if (document.body.contains(warningModal)) {
        closeWarningModal();
        this.timeoutWarningShown = false;
      }
    }, 30000);
  }
  
  // Show network warning
  showNetworkWarning() {
    // Show subtle network warning
    const networkWarning = document.createElement('div');
    networkWarning.className = 'network-warning';
    networkWarning.innerHTML = `
      <span>&#9888; Network connection unstable. Session checks may fail.</span>
      <button id="dismissNetworkWarning">Dismiss</button>
    `;
    
    networkWarning.style.cssText = `
      position: fixed;
      bottom: 20px;
      right: 20px;
      background: #f8d7da;
      color: #721c24;
      padding: 10px 15px;
      border-radius: 5px;
      border: 1px solid #f5c6cb;
      z-index: 10000;
      display: flex;
      align-items: center;
      gap: 10px;
      box-shadow: 0 2px 10px rgba(0,0,0,0.1);
    `;
    
    document.body.appendChild(networkWarning);
    
    document.getElementById('dismissNetworkWarning').addEventListener('click', () => {
      networkWarning.remove();
    });
    
    // Auto-remove after 10 seconds
    setTimeout(() => {
      if (document.body.contains(networkWarning)) {
        networkWarning.remove();
      }
    }, 10000);
  }
  
  // Perform logout - CSRF REMOVED
  async performLogout(logoutType = 'user_initiated', reason = 'User action') {
    try {
      const csrfToken = await fetchCsrfToken();
      const response = await fetch('../backend/api/logout.php', {
        method: 'POST',
        credentials: 'include',
        headers: withDeviceTokenHeaders({
          'Content-Type': 'application/x-www-form-urlencoded',
          'X-CSRF-Token': csrfToken
        }),
        body: `logout_type=${logoutType}&logout_reason=${encodeURIComponent(reason)}`
      });

      const result = await parseJsonResponseStrict(response, 'Logout response could not be read.');
      
      if (result.success) {
        this.clearUserData();
        if (this.broadcastLogoutToOtherTabs) {
          this.broadcastLogoutToOtherTabs();
        }
        
        // Redirect to login
        setTimeout(() => {
          window.location.href = 'login.html?logout=success&t=' + Date.now();
        }, 500);
        
        return { success: true, message: result.message };
      } else {
        throw new Error(result.message);
      }
    } catch (error) {
      console.error('Logout failed:', error);
      this.clearUserData();
      if (this.broadcastLogoutToOtherTabs) {
        this.broadcastLogoutToOtherTabs();
      }
      
      // Still redirect even if logout failed
      setTimeout(() => {
        window.location.href = 'login.html?logout=error&t=' + Date.now();
      }, 500);
      
      return { success: false, message: error.message };
    }
  }
  
  /* 4. ENHANCED SESSION EXPIRED OVERLAY WITH DEVICE CONFLICT */
  
  // Session expired overlay
  showSessionExpiredOverlay(message = 'Your session has expired due to inactivity. Please login again to continue.') {
    // Prevent multiple overlays
    const existingOverlay = document.querySelector('.session-overlay');
    if (existingOverlay) {
      normalizeSessionOverlay(existingOverlay, { message, forceStatic: true });
      return;
    }

    const overlay = document.createElement('div');
    overlay.classList.add('session-overlay', 'session-expired');
    overlay.setAttribute('data-type', 'session-expired');
    overlay.innerHTML = `
        <div class="session-overlay-content">
            <div class="session-modal-header">
                <div class="session-icon session-warning-icon" aria-hidden="true"><span>!</span></div>
                <h2>Session Expired</h2>
            </div>
            <div class="session-modal-body">
                <p></p>
                <div class="session-overlay-buttons">
                    <button id="sessionOkButton" class="session-btn session-btn-primary">OK</button>
                </div>
            </div>
        </div>
    `;
    document.body.appendChild(overlay);
    normalizeSessionOverlay(overlay, { message });
    
    const mainContent = document.querySelector('main, .main-content, #app-content');
    document.documentElement.classList.add('session-expired-blur');
    document.body.classList.add('session-expired-blur');
    document.body.classList.add('modal-open');
    if (mainContent) {
      mainContent.classList.add('session-expired-blur');
    }

    const redirectToLogin = () => {
      // Remove overlay
      overlay.remove();
      syncSessionStateModalLayer();
      
      if (mainContent) {
        mainContent.classList.remove('session-expired-blur');
      }
      document.documentElement.classList.remove('session-expired-blur');
      document.body.classList.remove('session-expired-blur');
      document.body.classList.remove('modal-open');
      
      // Get the last visited page
      const lastPage = localStorage.getItem("lastVisitedPage") || window.location.href;
      
      // Clear all intervals
      this.stopSessionMonitoring();
      
      // Redirect to login with return URL
      window.location.href = `login.html?return=${encodeURIComponent(lastPage)}`;
    };

    // Add event listeners
    overlay.querySelector('#sessionOkButton')?.addEventListener('click', redirectToLogin, { once: true });
    
    overlay.addEventListener('click', (e) => { 
      if (e.target === overlay) redirectToLogin(); 
    });
    
    // Escape key handler
    const escapeHandler = (e) => { 
      if (e.key === 'Escape') {
        e.preventDefault();
        document.removeEventListener('keydown', escapeHandler);
        redirectToLogin();
      }
    };
    document.addEventListener('keydown', escapeHandler, { once: true });
  }

  ensureSessionOverlayVisible(message = '') {
    if (!this.sessionExpired) return;
    const overlay = document.querySelector('.session-overlay.session-expired');
    const resolvedMessage = String(message || this.sessionExpiredMessage || 'Your session has expired due to inactivity. Please login again to continue.');
    if (!overlay) {
      this.showSessionExpiredOverlay(resolvedMessage);
      return;
    }
    normalizeSessionOverlay(overlay, { message: resolvedMessage, forceStatic: true });
  }

  // Device conflict overlay
  showDeviceConflictOverlay(message = 'Your account was logged in from another device. For security, this session has been terminated.') {
    // Prevent multiple overlays
    const existingOverlay = document.querySelector('.session-overlay');
    if (existingOverlay) {
      normalizeSessionOverlay(existingOverlay, { message, forceStatic: true });
      return;
    }

    const overlay = document.createElement('div');
    overlay.classList.add('session-overlay', 'device-conflict');
    overlay.setAttribute('data-type', 'device-conflict');
    overlay.innerHTML = `
        <div class="session-overlay-content">
            <div class="session-modal-header">
                <div class="session-icon">&#128274;</div>
                <h2>Logged In Elsewhere</h2>
            </div>
            <div class="session-modal-body">
                <p></p>
                <div class="session-overlay-buttons">
                    <button id="deviceConflictOkButton" class="session-btn session-btn-primary">OK</button>
                </div>
            </div>
        </div>
    `;
    
    document.body.appendChild(overlay);
    normalizeSessionOverlay(overlay, { message });
    
    const mainContent = document.querySelector('main, .main-content, #app-content');
    document.documentElement.classList.add('session-expired-blur');
    document.body.classList.add('session-expired-blur');
    document.body.classList.add('modal-open');
    if (mainContent) {
      mainContent.classList.add('session-expired-blur');
    }

    const redirectToLogin = () => {
      // Remove overlay
      overlay.remove();
      syncSessionStateModalLayer();
      
      if (mainContent) {
        mainContent.classList.remove('session-expired-blur');
      }
      document.documentElement.classList.remove('session-expired-blur');
      document.body.classList.remove('session-expired-blur');
      document.body.classList.remove('modal-open');
      
      // Get the last visited page
      const lastPage = localStorage.getItem("lastVisitedPage") || window.location.href;
      
      // Clear all intervals
      this.stopSessionMonitoring();
      
      // Redirect to login with return URL
      window.location.href = `login.html?return=${encodeURIComponent(lastPage)}`;
    };

    // Add event listeners
    overlay.querySelector('#deviceConflictOkButton')?.addEventListener('click', redirectToLogin, { once: true });
    
    overlay.addEventListener('click', (e) => { 
      if (e.target === overlay) redirectToLogin(); 
    });
    
    // Escape key handler
    const escapeHandler = (e) => { 
      if (e.key === 'Escape') {
        e.preventDefault();
        document.removeEventListener('keydown', escapeHandler);
        redirectToLogin();
      }
    };
    document.addEventListener('keydown', escapeHandler, { once: true });
  }
  
  // Stop session monitoring
  stopSessionMonitoring() {
    // Stop worker if running
    if (this.worker) {
      this.worker.postMessage({ type: 'STOP_MONITORING' });
      this.worker.terminate();
      this.worker = null;
    }
  }
  
  /* 5. BROADCAST MESSAGE CHECKER (Enhanced) */
  
  /**
   * Initialize broadcast checker for all pages
   */
  initializeBroadcastChecker() {
    // Only load broadcast checking on non-messages pages
    if (!window.location.pathname.includes('messages.html')) {
      if (false) {
        console.log('[info] Broadcast notifications disabled by settings');
        return;
      }
      console.log('[init] Initializing broadcast checker for all pages');
      window.__broadcastCheckerRunning = true;
      this.ensureBroadcastStyles();
      this.initializeBroadcastAudioWarmup();

      // Check immediately
      this.checkForBroadcasts();
      
      // Then check every 5 seconds for near-instant delivery
      this.broadcastInterval = setInterval(() => this.checkForBroadcasts(), 5000);

      // Also check when the tab becomes active
      document.addEventListener('visibilitychange', () => {
        if (!document.hidden) {
          this.checkForBroadcasts();
        }
      });
      window.addEventListener('focus', () => this.checkForBroadcasts());
    }
  }

  initializeBroadcastAudioWarmup() {
    if (this.broadcastAudioWarmupBound) {
      return;
    }

    this.broadcastAudioWarmupBound = true;
    const warmup = () => this.warmBroadcastAudio();
    document.addEventListener('pointerdown', warmup, { passive: true, once: true });
    document.addEventListener('keydown', warmup, { passive: true, once: true });
    document.addEventListener('touchstart', warmup, { passive: true, once: true });
  }

  warmBroadcastAudio() {
    const soundPath = window.AppSettingsManager?.getBroadcastSoundPath?.() || 'audio/notification.mp3';
    if (!soundPath) {
      return;
    }

    try {
      const audio = new Audio(this.resolveClientAssetUrl(soundPath));
      audio.preload = 'auto';
      audio.volume = 0;

      const playPromise = audio.play();
      if (playPromise && typeof playPromise.then === 'function') {
        playPromise
          .then(() => {
            audio.pause();
            audio.currentTime = 0;
          })
          .catch(() => audio.load());
      } else {
        audio.load();
      }
    } catch (_error) {
      // Ignore warmup failures; playback will still be attempted on demand.
    }
  }

  resolveClientAssetUrl(path) {
    try {
      return new URL(String(path || ''), window.location.href).href;
    } catch (_error) {
      return String(path || '');
    }
  }

  buildBroadcastPreviewText(broadcast) {
    const preview = String(broadcast?.message_preview || '').replace(/\s+/g, ' ').trim();
    if (!preview) {
      return 'A new broadcast message is available.';
    }

    return preview.length > 160 ? `${preview.slice(0, 157)}...` : preview;
  }

  buildBroadcastTargetUrl(broadcast) {
    const params = new URLSearchParams();
    const broadcastId = broadcast?.broadcast_id || '';
    const messageId = broadcast?.message_id || '';

    if (messageId) params.set('message_id', messageId);
    if (broadcastId) params.set('broadcast_id', broadcastId);

    const query = params.toString();
    return `messages.html${query ? `?${query}` : ''}#broadcast`;
  }

  stopBroadcastSound() {
    if (typeof this.currentBroadcastSoundStop === 'function') {
      this.currentBroadcastSoundStop();
      this.currentBroadcastSoundStop = null;
    }
  }

  handleBroadcastSoundPlaybackFailure(error) {
    const now = Date.now();
    if ((now - this.lastBroadcastSoundErrorLogAt) > 60000) {
      console.warn('Broadcast sound playback failed:', error?.message || error);
      this.lastBroadcastSoundErrorLogAt = now;
    }
  }

  playBroadcastSound() {
    if (!window.AppSettingsManager?.isBroadcastSoundEnabled?.()) {
      return () => {};
    }

    const soundPath = window.AppSettingsManager.getBroadcastSoundPath();
    if (!soundPath) {
      return () => {};
    }

    const audio = new Audio(this.resolveClientAssetUrl(soundPath));
    audio.preload = 'auto';
    audio.volume = window.AppSettingsManager.getBroadcastSoundVolume();

    const repeatCount = window.AppSettingsManager.getBroadcastSoundRepeatCount();
    let playCount = 0;
    let stopped = false;

    const cleanup = () => {
      audio.pause();
      audio.removeEventListener('ended', handleEnded);
    };

    const playOnce = async () => {
      if (stopped) return;
      playCount += 1;
      audio.currentTime = 0;
      await audio.play();
    };

    const handleEnded = async () => {
      if (stopped) {
        cleanup();
        return;
      }

      if (playCount >= repeatCount) {
        cleanup();
        return;
      }

      try {
        await playOnce();
      } catch (error) {
        cleanup();
        this.handleBroadcastSoundPlaybackFailure(error);
      }
    };

    audio.addEventListener('ended', handleEnded);

    playOnce().catch((error) => {
      cleanup();
      this.handleBroadcastSoundPlaybackFailure(error);
    });

    return () => {
      if (stopped) return;
      stopped = true;
      cleanup();
    };
  }

  shouldShowDesktopBroadcastNotification() {
    if (!('Notification' in window)) {
      return false;
    }

    if (!window.AppSettingsManager?.isBroadcastDesktopEnabled?.()) {
      return false;
    }

    if (Notification.permission !== 'granted') {
      return false;
    }

    const hiddenOnly = window.AppSettingsManager.isBroadcastDesktopHiddenOnly();
    if (!hiddenOnly) {
      return true;
    }

    const hidden = document.hidden || document.visibilityState !== 'visible';
    const focused = typeof document.hasFocus === 'function' ? document.hasFocus() : true;
    return hidden || !focused;
  }

  showDesktopBroadcastNotification(broadcast) {
    if (!this.shouldShowDesktopBroadcastNotification()) {
      return;
    }

    try {
      const broadcastId = broadcast?.broadcast_id || broadcast?.message_id || `broadcast-${Date.now()}`;
      const targetUrl = this.buildBroadcastTargetUrl(broadcast);
      const notification = new Notification(broadcast?.subject || 'New Broadcast', {
        body: this.buildBroadcastPreviewText(broadcast),
        icon: this.resolveClientAssetUrl('assets/pwa/icon-192.png'),
        badge: this.resolveClientAssetUrl('assets/pwa/icon-192.png'),
        tag: `broadcast-${broadcastId}`
      });

      const cleanup = () => {
        this.activeNotifications.delete(notification);
      };

      notification.onclick = async () => {
        cleanup();
        try {
          window.focus();
        } catch (_error) {
          // Ignore focus failures.
        }

        if (broadcast?.broadcast_id) {
          await this.markBroadcastAsRead(broadcast.broadcast_id);
        }

        notification.close();
        window.location.href = targetUrl;
      };
      notification.onclose = cleanup;
      notification.onerror = cleanup;
      this.activeNotifications.add(notification);
    } catch (error) {
      const now = Date.now();
      if ((now - this.lastBroadcastErrorLogAt) > 60000) {
        console.warn('Desktop broadcast notification failed:', error?.message || error);
        this.lastBroadcastErrorLogAt = now;
      }
    }
  }

  /**
   * Check for new broadcast messages
   */
  async checkForBroadcasts() {
    try {
      if (window.AppSettingsManager && !window.AppSettingsManager.isBroadcastNotificationsEnabled()) {
        return;
      }
      const response = await this.fetchApiWithFallback('check_broadcasts.php', {
        headers: {
          'Accept': 'application/json'
        }
      });
      
      if (!response.ok) return;
      
      const data = await response.json();
      
      if (data.success && data.has_new && data.latest_broadcast) {
        const broadcast = data.latest_broadcast;
        const broadcastId = broadcast.broadcast_id || broadcast.message_id;
        
        if (!broadcastId) return;

        const seenBroadcasts = this.getSeenBroadcasts();
        if (seenBroadcasts.includes(String(broadcastId))) {
          return;
        }

        this.markBroadcastSeenLocal(broadcastId);
        this.showDesktopBroadcastNotification(broadcast);
        this.showBroadcastModal(broadcast);
      }
    } catch (error) {
      const now = Date.now();
      if ((now - this.lastBroadcastErrorLogAt) > 60000) {
        console.warn('Broadcast check failed:', error?.message || error);
        this.lastBroadcastErrorLogAt = now;
      }
    }
  }

  /**
   * Show in-app modal for new broadcast
   */
  showBroadcastModal(broadcast) {
    const existing = document.querySelector('.global-broadcast-overlay');
    if (existing) existing.remove();
    this.stopBroadcastSound();

    const modal = document.createElement('div');
    modal.className = 'global-broadcast-overlay';
    modal.innerHTML = `
      <div class="broadcast-popup">
        <div class="broadcast-header">
          <i class="fas fa-bullhorn"></i>
          <span>New Broadcast</span>
        </div>
        <div class="broadcast-body">
          <h4>${this.escapeHtml(broadcast.subject || 'Broadcast Message')}</h4>
          <p>${this.escapeHtml(this.buildBroadcastPreviewText(broadcast))}</p>
          <small>From: ${this.escapeHtml(broadcast.sender_name || 'System')}</small>
        </div>
        <div class="broadcast-actions">
          <button class="btn-dismiss" id="dismissBroadcast">Dismiss</button>
          <button class="btn-view" id="viewBroadcast">View Message</button>
        </div>
      </div>
    `;
    document.body.appendChild(modal);
    document.body.classList.add('modal-open');

    const broadcastId = broadcast.broadcast_id || '';
    const targetUrl = this.buildBroadcastTargetUrl(broadcast);
    const stopSound = this.playBroadcastSound();
    this.currentBroadcastSoundStop = stopSound;

    const closeModal = () => {
      stopSound();
      if (this.currentBroadcastSoundStop === stopSound) {
        this.currentBroadcastSoundStop = null;
      }
      modal.remove();
      document.body.classList.remove('modal-open');
    };

    modal.querySelector('#dismissBroadcast')?.addEventListener('click', () => {
      closeModal();
    });

    modal.querySelector('#viewBroadcast')?.addEventListener('click', async () => {
      if (broadcastId) {
        await this.markBroadcastAsRead(broadcastId);
      }
      closeModal();
      window.location.href = targetUrl;
    });

    modal.addEventListener('click', (e) => {
      if (e.target === modal) {
        closeModal();
      }
    });
  }

  async markBroadcastAsRead(broadcastId) {
    try {
      await fetch('../backend/api/mark_broadcast_seen.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        credentials: 'include',
        body: JSON.stringify({ broadcast_id: broadcastId })
      });
    } catch (err) {
      console.warn('Failed to mark broadcast as read:', err);
    }
  }

  getSeenBroadcasts() {
    try {
      const raw = localStorage.getItem('pensionsgo_seen_broadcasts') || '[]';
      return JSON.parse(raw);
    } catch {
      return [];
    }
  }

  markBroadcastSeenLocal(id) {
    try {
      const seen = this.getSeenBroadcasts();
      if (!seen.includes(String(id))) {
        seen.push(String(id));
        localStorage.setItem('pensionsgo_seen_broadcasts', JSON.stringify(seen));
      }
    } catch (e) { /* ignore */ }
  }

  ensureBroadcastStyles() {
    if (document.getElementById('broadcast-popup-styles')) {
      return;
    }

    const link = document.createElement('link');
    link.rel = 'stylesheet';
    link.id = 'broadcast-popup-styles';
    link.href = this.getBroadcastCssUrl();
    document.head.appendChild(link);
  }

  getBroadcastCssUrl() {
    const path = window.location.pathname;
    if (path.includes('/frontend/')) {
      const base = path.split('/frontend/')[0];
      return `${window.location.origin}${base}/frontend/css/broadcast_popup.css`;
    }
    return '../frontend/css/broadcast_popup.css';
  }

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
}

// 
// 6. GLOBAL SESSION MANAGER INSTANCE
// 
const sessionManager = new SessionManager();

// 
// 7. EXPOSE GLOBAL FUNCTIONS (for backward compatibility)
// 
window.verifyActiveSession = () => sessionManager.performSessionCheck();
window.checkSessionStatus = () => sessionManager.performSessionCheck();
window.isUserLikelyLoggedIn = () => sessionManager.isUserLikelyLoggedIn();
window.updateSessionActivity = () => sessionManager.updateSessionActivity();
window.performLogout = (type, reason) => sessionManager.performLogout(type, reason);
window.clearAllUserData = () => sessionManager.clearUserData();
window.sessionManager = sessionManager;
window.getPersistentDeviceToken = getPersistentDeviceToken;
window.withDeviceTokenHeaders = withDeviceTokenHeaders;
window.fetchCsrfToken = fetchCsrfToken;
window.markTabAuthenticationVerified = markTabAuthenticationVerified;
window.rememberLastSecurePage = rememberLastSecurePage;
window.rememberAuthenticatedPublicAllowance = rememberAuthenticatedPublicAllowance;
window.hasValidAuthenticatedPublicAllowance = hasValidAuthenticatedPublicAllowance;
window.markAuthenticatedPublicNavigation = markAuthenticatedPublicNavigation;
window.forceReauthentication = forceReauthentication;

// 
// 8. ACCESS DENIED OVERLAY
// 
function showAccessDeniedOverlay() {
  const existingOverlay = document.querySelector('.session-overlay');
  if (existingOverlay) {
    normalizeSessionOverlay(existingOverlay, {
      message: 'You do not have the required permissions to access this page.',
      forceStatic: true
    });
    return;
  }

  const overlay = document.createElement('div');
  overlay.classList.add('session-overlay');
  overlay.setAttribute('data-type', 'access-denied');
  overlay.innerHTML = `
    <div class="session-overlay-content">
      <div class="session-modal-header">
        <div class="session-icon">&#128683;</div>
        <h2>Access Denied</h2>
      </div>
      <div class="session-modal-body">
        <p></p>
        <div class="session-overlay-buttons">
          <button id="accessOkButton" class="session-btn session-btn-primary">OK</button>
        </div>
      </div>
    </div>
  `;
  document.body.appendChild(overlay);
  normalizeSessionOverlay(overlay, {
    message: 'You do not have the required permissions to access this page.'
  });
  document.documentElement.classList.add('session-expired-blur');
  document.body.classList.add('session-expired-blur');
  document.body.classList.add('modal-open');

  overlay.querySelector('#accessOkButton')?.addEventListener('click', () => {
    const role = localStorage.getItem('userRole');
    overlay.remove();
    syncSessionStateModalLayer();
    document.documentElement.classList.remove('session-expired-blur');
    document.body.classList.remove('session-expired-blur');
    document.body.classList.remove('modal-open');
    window.location.href = getRoleBasedRedirectUrl(role);
  });
}

/* 9. ROLE-BASED REDIRECT LOGIC */
function getRoleBasedRedirectUrl(userRole, requestedUrl = '', userRoleEffective = '') {
  const role = (userRole || '').toLowerCase();
  const accessRole = resolveAccessRole(role, userRoleEffective);
  const dashboardFirstRoles = new Set([
    'user',
    'oc_pen',
    'dep_oc',
    'deputy_oc',
    'deputy_oc_pen',
    'deputy_oc_pension'
  ]);
  const roleLandingPages = {
    super_admin: 'dashboard.html',
    admin: 'dashboard.html',
    clerk: 'pension_file_registry.html',
    pensioner: 'pensioner_board.html',
    user: 'dashboard.html',
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

  const defaultLanding = 'dashboard.html';
  const safeLanding = roleLandingPages[accessRole] || roleLandingPages[role] || defaultLanding;
  if (dashboardFirstRoles.has(accessRole) || dashboardFirstRoles.has(role)) return safeLanding;
  if (!requestedUrl) return safeLanding;

  const pageName = requestedUrl.split('/').pop().split('?')[0];
  return isUrlAccessibleForRole(pageName, accessRole) ? requestedUrl : safeLanding;
}

/* 10. ROLE ACCESS RULES VALIDATION */
function isUrlAccessibleForRole(pageName, userRole) {
  const claimsPages = ['claim_form.html', 'claims.html', 'budgeting.html'];
  const sharedToolsPages = ['benefits_calculator.html', 'podcast.html', 'document_viewer.html'];
  const normalizedRole = String(userRole || '').toLowerCase().trim();
  if (pageName === 'dashboard.html' && normalizedRole && !['user', 'pensioner'].includes(normalizedRole)) {
    return true;
  }
  const rules = {
    super_admin: () => true,
    admin: () => true,
    clerk: p => ['file_registry.html','pension_file_registry.html','staff_due.html','add_staff.html','edit_staff.html','view_staff.html','tasks.html','file_tracking.html','application_status.html','profile.html','edit_user.html','messages.html','reports.html','dashboard.html'].concat(claimsPages, sharedToolsPages).includes(p),
    oc_pen: p => ['pension_file_registry.html','staff_due.html','add_staff.html','view_staff.html','tasks.html','file_tracking.html','application_status.html','profile.html','edit_user.html','messages.html','reports.html','dashboard.html'].concat(claimsPages, sharedToolsPages).includes(p),
    dep_oc: p => ['pension_file_registry.html','staff_due.html','add_staff.html','view_staff.html','tasks.html','file_tracking.html','application_status.html','profile.html','edit_user.html','messages.html','reports.html','dashboard.html'].concat(claimsPages, sharedToolsPages).includes(p),
    deputy_oc: p => ['pension_file_registry.html','staff_due.html','add_staff.html','view_staff.html','tasks.html','file_tracking.html','application_status.html','profile.html','edit_user.html','messages.html','reports.html','dashboard.html'].concat(claimsPages, sharedToolsPages).includes(p),
    deputy_oc_pen: p => ['pension_file_registry.html','staff_due.html','add_staff.html','view_staff.html','tasks.html','file_tracking.html','application_status.html','profile.html','edit_user.html','messages.html','reports.html','dashboard.html'].concat(claimsPages, sharedToolsPages).includes(p),
    deputy_oc_pension: p => ['pension_file_registry.html','staff_due.html','add_staff.html','view_staff.html','tasks.html','file_tracking.html','application_status.html','profile.html','edit_user.html','messages.html','reports.html','dashboard.html'].concat(claimsPages, sharedToolsPages).includes(p),
    writeup_officer: p => ['pension_file_registry.html','staff_due.html','add_staff.html','edit_staff.html','view_staff.html','tasks.html','file_tracking.html','application_status.html','profile.html','edit_user.html','messages.html','reports.html','dashboard.html'].concat(claimsPages, sharedToolsPages).includes(p),
    file_creator: p => ['pension_file_registry.html','tasks.html','file_tracking.html','application_status.html','profile.html','edit_user.html','messages.html','dashboard.html'].concat(claimsPages, sharedToolsPages).includes(p),
    data_entry: p => ['file_registry.html','pension_file_registry.html','tasks.html','staff_due.html','add_staff.html','edit_staff.html','view_staff.html','file_tracking.html','application_status.html','profile.html','edit_user.html','messages.html','dashboard.html'].concat(claimsPages, sharedToolsPages).includes(p),
    assessor: p => ['pension_file_registry.html','tasks.html','file_tracking.html','application_status.html','profile.html','edit_user.html','messages.html','dashboard.html'].concat(claimsPages, sharedToolsPages).includes(p),
    auditor: p => ['pension_file_registry.html','tasks.html','file_tracking.html','application_status.html','profile.html','edit_user.html','messages.html','dashboard.html'].concat(claimsPages, sharedToolsPages).includes(p),
    approver: p => ['pension_file_registry.html','tasks.html','file_tracking.html','application_status.html','profile.html','edit_user.html','messages.html','dashboard.html'].concat(claimsPages, sharedToolsPages).includes(p),
    pensioner: p => ['pensioner_board.html','pensioner_lookup.html','profile.html','edit_user.html'].concat(sharedToolsPages).includes(p),
    user: p => ['dashboard.html','pension_file_registry.html','application_status.html','profile.html','edit_user.html','faq.html','about.html'].concat(claimsPages, sharedToolsPages).includes(p)
  };
  return (rules[userRole] || (() => false))(pageName);
}

const AUTH_REQUIRED_PAGES = new Set([
  'admin_dashboard.html',
  'add_staff.html',
  'application_status.html',
  'benefits_calculator.html',
  'budgeting.html',
  'claim_form.html',
  'claims.html',
  'dashboard.html',
  'document_viewer.html',
  'edit_staff.html',
  'edit_user.html',
  'file_registry.html',
  'file_tracking.html',
  'messages.html',
  'pension_file_registry.html',
  'pensioner_board.html',
  'pensioner_lookup.html',
  'profile.html',
  'reports.html',
  'staff_due.html',
  'tasks.html',
  'users.html',
  'view_staff.html'
]);

function getCurrentPageName() {
  const page = window.location.pathname.split('/').pop();
  return page && page.trim() !== '' ? page.toLowerCase() : 'index.html';
}

function isAuthRequiredPage(pageName = getCurrentPageName()) {
  return AUTH_REQUIRED_PAGES.has((pageName || '').toLowerCase());
}

function isPublicReauthPage(pageName = getCurrentPageName()) {
  return PUBLIC_REAUTH_PAGES.has((pageName || '').toLowerCase());
}

function hasReauthRedirectContext() {
  try {
    const params = new URLSearchParams(window.location.search || '');
    return params.get('reauth') === '1';
  } catch (_error) {
    return false;
  }
}

function getPreferredReauthReturnUrl(sessionState = {}, pageName = getCurrentPageName()) {
  const normalizedPage = (pageName || '').toLowerCase();
  if (isAuthRequiredPage(normalizedPage)) {
    return window.location.href;
  }

  const lastSecurePage = getLastSecurePage();
  if (lastSecurePage) {
    return lastSecurePage;
  }

  return getRoleBasedRedirectUrl(
    (sessionState.userRole || sessionStorage.getItem('userRole') || '').toLowerCase(),
    '',
    sessionState.userRoleEffective || sessionStorage.getItem('userRoleEffective') || ''
  );
}

async function forceReauthentication(returnUrl, reason = 'reauth_required') {
  const targetReturn = String(returnUrl || window.location.href).trim() || window.location.href;
  const loginUrl = `login.html?reauth=1&reason=${encodeURIComponent(reason)}&return=${encodeURIComponent(targetReturn)}`;

  try {
    const headers = withDeviceTokenHeaders({
      'Content-Type': 'application/x-www-form-urlencoded',
      'Accept': 'application/json'
    });

    try {
      const csrfToken = await fetchCsrfToken();
      headers['X-CSRF-Token'] = csrfToken;
    } catch (_error) {
      // Best-effort: proceed to server logout even if a CSRF token cannot be fetched.
    }

    await nativeFetch('../backend/api/logout.php', {
      method: 'POST',
      credentials: 'include',
      cache: 'no-store',
      headers,
      body: `logout_type=reauth_required&logout_reason=${encodeURIComponent(reason)}`
    });
  } catch (error) {
    console.warn('Forced re-authentication logout failed:', error.message || error);
  } finally {
    clearClientAuthState();
    window.location.replace(loginUrl);
  }
}

function clearClientAuthState() {
  sessionStorage.removeItem('isLoggedIn');
  sessionStorage.removeItem('userId');
  sessionStorage.removeItem('userName');
    sessionStorage.removeItem('userRole');
    sessionStorage.removeItem('userRoleEffective');
  sessionStorage.removeItem('userEmail');
  sessionStorage.removeItem('userPhoto');
  sessionStorage.removeItem('lastActivity');
  sessionStorage.removeItem('phoneNo');
  sessionStorage.removeItem('sessionTimeout');
  sessionStorage.removeItem('gracePeriod');
  sessionStorage.removeItem(SESSION_VALIDATION_CACHE_KEY);
  clearTabAuthenticationState();
  localStorage.removeItem('loggedInUser');
    localStorage.removeItem('userRole');
    localStorage.removeItem('userRoleEffective');
  localStorage.removeItem('sessionSettings');
}

function syncClientAuthState(sessionData) {
  const payload = sessionData || {};
  let existingUser = {};
  try {
    existingUser = JSON.parse(localStorage.getItem('loggedInUser') || '{}');
  } catch (error) {
    existingUser = {};
  }

  const userId = payload.userId || '';
  const userName = payload.userName || 'User';
    const userRole = (payload.userRole || '').toLowerCase();
    const userRoleEffective = (payload.userRoleEffective || '').toLowerCase();
  const userEmail = payload.userEmail || '';
  const userPhoto = payload.userPhoto || existingUser.photo || existingUser.userPhoto || '';

  sessionStorage.setItem('isLoggedIn', 'true');
  sessionStorage.setItem('userId', userId);
    sessionStorage.setItem('userName', userName);
    sessionStorage.setItem('userRole', userRole);
    sessionStorage.setItem('userRoleEffective', userRoleEffective);
    sessionStorage.setItem('userEmail', userEmail);
    sessionStorage.setItem('userPhoto', userPhoto);
    sessionStorage.setItem('lastActivity', Date.now().toString());
    localStorage.setItem('userRole', userRole);
    localStorage.setItem('userRoleEffective', userRoleEffective);
    localStorage.setItem('loggedInUser', JSON.stringify({
      id: userId,
      name: userName,
      role: userRole,
      roleEffective: userRoleEffective,
      email: userEmail,
      photo: userPhoto,
      userPhoto: userPhoto
    }));
    rememberSessionValidationState({
      active: true,
      userId,
      userName,
      userRole,
      userRoleEffective,
      userEmail,
      userPhoto
    });
}

async function validateActiveSession() {
  const cachedSession = getCachedOfflineSessionState();
  const freshValidation = getFreshSessionValidationState();
  if (freshValidation?.active) {
    return freshValidation;
  }
  const hostedPrecheck = getHostedDeploymentSessionState();
  if (hostedPrecheck.active) {
    rememberSessionValidationState(hostedPrecheck);
    return hostedPrecheck;
  }
  let timeoutId = null;

  try {
    const controller = new AbortController();
    timeoutId = setTimeout(() => controller.abort('session_validation_timeout'), 8000);

    const response = await fetch('../backend/api/check_session.php', {
      signal: controller.signal,
      credentials: 'include',
      cache: 'no-store',
      headers: withDeviceTokenHeaders({ 'X-Requested-With': 'XMLHttpRequest' })
    });

    if (!response.ok) {
      if (response.status >= 500 && cachedSession.active && hasTabAuthenticationVerified()) {
        return {
          ...cachedSession,
          offline: !navigator.onLine,
          sessionCheckDegraded: navigator.onLine
        };
      }
      return { active: false };
    }

    const data = await response.json();
    const resolvedRole = String(
      data?.userRole
      || sessionStorage.getItem('userRole')
      || localStorage.getItem('userRole')
      || ''
    ).toLowerCase();
    const resolvedEffectiveRole = String(
      data?.userRoleEffective
      || sessionStorage.getItem('userRoleEffective')
      || localStorage.getItem('userRoleEffective')
      || resolvedRole
      || ''
    ).toLowerCase();

    if (data && data.active && resolvedRole) {
      const activeState = {
        active: true,
        userId: data.userId || cachedSession.userId || '',
        userName: data.userName || cachedSession.userName || 'User',
        userRole: resolvedRole,
        userRoleEffective: resolvedEffectiveRole,
        userEmail: data.userEmail || cachedSession.userEmail || '',
        userPhoto: data.userPhoto || cachedSession.userPhoto || ''
      };
      rememberSessionValidationState(activeState);
      return activeState;
    }

    const hostedFallback = getHostedDeploymentSessionState();
    if (hostedFallback.active && ['not_authenticated', 'not_found', 'invalid', null, undefined, ''].includes(data?.reason)) {
      rememberSessionValidationState(hostedFallback);
      return hostedFallback;
    }

    sessionStorage.removeItem(SESSION_VALIDATION_CACHE_KEY);
    return { active: false };
  } catch (error) {
    if (!isAbortLikeError(error)) {
      console.warn('Session validation failed:', normalizeRequestErrorMessage(error, 'Unable to validate session.'));
    }

    if (cachedSession.active && hasTabAuthenticationVerified()) {
      return {
        ...cachedSession,
        offline: !navigator.onLine,
        sessionCheckDegraded: navigator.onLine
      };
    }

    const hostedFallback = getHostedDeploymentSessionState();
    if (hostedFallback.active) {
      rememberSessionValidationState(hostedFallback);
      return hostedFallback;
    }

    if (!navigator.onLine) {
      return cachedSession;
    }
    return { active: false };
  } finally {
    if (timeoutId) {
      clearTimeout(timeoutId);
    }
  }
}

function redirectToLoginForAuth() {
  const returnUrl = window.location.href;
  const loginUrl = `login.html?return=${encodeURIComponent(returnUrl)}&reason=session_required`;
  window.location.replace(loginUrl);
}

function hasLogoutRedirectContext() {
  try {
    const params = new URLSearchParams(window.location.search || '');
    return params.has('logout');
  } catch (error) {
    return false;
  }
}

function enforceHeader2LinkVisibility(isAuthenticated) {
  const header = document.getElementById('mainHeader');
  if (!header) return;
  const protectedItems = header.querySelectorAll('[data-auth-required="true"]');
  protectedItems.forEach((item) => {
    item.classList.toggle('hidden', !isAuthenticated);
  });
}

function ensureHeaderStylesheet(id, href) {
  if (document.getElementById(id)) {
    return;
  }

  const link = document.createElement('link');
  link.id = id;
  link.rel = 'stylesheet';
  link.href = href;
  document.head.appendChild(link);
}

function syncAuthenticatedHeaderStyles(isAuthenticated) {
  const authHeaderStyles = [
    { id: 'pensionsgo-auth-header-profile-css', href: 'css/profile.css' },
    { id: 'pensionsgo-auth-header-menu-css', href: 'css/menu.css' }
  ];

  if (isAuthenticated) {
    authHeaderStyles.forEach((asset) => ensureHeaderStylesheet(asset.id, asset.href));
    return;
  }

  authHeaderStyles.forEach((asset) => {
    document.getElementById(asset.id)?.remove();
  });
}

/* 11. HEADER LOADING (Dynamic) */
function hasStylesheetAsset(assetName) {
  const normalizedAssetName = String(assetName || '').trim().toLowerCase();
  if (!normalizedAssetName) {
    return false;
  }

  return Array.from(document.querySelectorAll('link[rel="stylesheet"][href]')).some((link) => {
    const rawHref = String(link.getAttribute('href') || '').trim().toLowerCase();
    if (rawHref.includes(normalizedAssetName)) {
      return true;
    }

    try {
      return new URL(link.href, window.location.href).pathname.toLowerCase().endsWith(`/css/${normalizedAssetName}`);
    } catch (_error) {
      return false;
    }
  });
}

function ensureSharedOverlayStylesheet() {
  if (document.getElementById('pensionsgo-shared-overlay-css')) {
    return;
  }

  if (hasStylesheetAsset('overlay.css') || hasStylesheetAsset('auth.css')) {
    return;
  }

  const link = document.createElement('link');
  link.id = 'pensionsgo-shared-overlay-css';
  link.rel = 'stylesheet';
  link.href = 'css/overlay.css';
  document.head.appendChild(link);
}

const HEADER_TEMPLATE_CACHE_PREFIX = 'pensionsgoHeaderTemplate:';
const HEADER_TEMPLATE_TIMEOUT_MS = 7000;

function getCachedHeaderTemplate(headerPath) {
  try {
    return localStorage.getItem(`${HEADER_TEMPLATE_CACHE_PREFIX}${headerPath}`) || '';
  } catch (_error) {
    return '';
  }
}

function cacheHeaderTemplate(headerPath, html) {
  if (!html || !html.includes('<header')) return;
  try {
    localStorage.setItem(`${HEADER_TEMPLATE_CACHE_PREFIX}${headerPath}`, html);
  } catch (_error) {}
}

async function fetchHeaderTemplate(headerPath) {
  const controller = new AbortController();
  const timeoutId = setTimeout(() => controller.abort('header_timeout'), HEADER_TEMPLATE_TIMEOUT_MS);
  try {
    const res = await fetch(headerPath, {
      cache: 'no-cache',
      signal: controller.signal
    });
    if (!res.ok) throw new Error(`Failed to fetch header: ${res.status}`);

    const headerHTML = await res.text();
    if (!/<header[\s>]/i.test(headerHTML)) {
      throw new Error(`Invalid header template returned from ${headerPath}`);
    }
    cacheHeaderTemplate(headerPath, headerHTML);
    return headerHTML;
  } finally {
    clearTimeout(timeoutId);
  }
}

async function loadAppropriateHeader(isAuthenticated = false) {
  try {
    const existingHeader = document.getElementById('mainHeader');
    if (existingHeader) {
      existingHeader.remove();
    }

    const headerPath = isAuthenticated ? './header2.html' : './header1.html';
    syncAuthenticatedHeaderStyles(isAuthenticated);

    const headerHTML = await fetchHeaderTemplate(headerPath)
      .catch((error) => {
        const cached = getCachedHeaderTemplate(headerPath);
        if (cached) return cached;
        throw error;
      });
    document.body.insertAdjacentHTML('afterbegin', headerHTML);
    initializeGlobalFixedLayoutOffsetSync();
    syncGlobalFixedLayoutOffset();

    initializeThemeToggle();
    highlightActivePage();
    AppSettingsManager.applyHeaderBrand();
    AppSettingsManager.applyDocumentTitle();

    if (isAuthenticated) {
      enforceHeader2LinkVisibility(true);
      try {
        const mod = await import('./modules/header_interactions.js?v=20260526a');
        mod?.initHeaderInteractions?.();
      } catch {
        initBasicMobileMenu();
      }
      initializeLogoutModule();
    } else {
      setTimeout(() => initPublicHeaderMenuToggle(), 50);
    }

    AppLoader.markHeaderLoaded();
  } catch (err) {
    console.error("[error] Header load failed:", err);
    AppLoader.markHeaderLoaded();
    document.body.insertAdjacentHTML('afterbegin', `
      <header style="background:#003366;color:white;padding:1rem;text-align:center;">PensionsGo</header>
    `);
    initializeGlobalFixedLayoutOffsetSync();
    syncGlobalFixedLayoutOffset();
  }
}

/* 12. MOBILE MENU (LOGGED-IN) */
function initBasicMobileMenu() {
  const menuToggle = document.getElementById('menuToggle');
  const dropdownMenu = document.getElementById('dropdownMenu');
  if (!menuToggle || !dropdownMenu) return;

  const toggleMenu = () => dropdownMenu.classList.toggle('visible');
  menuToggle.addEventListener('click', e => { e.stopPropagation(); toggleMenu(); });
  menuToggle.addEventListener('touchend', e => { e.preventDefault(); toggleMenu(); });
  document.addEventListener('click', e => {
    if (!dropdownMenu.contains(e.target) && !menuToggle.contains(e.target))
      dropdownMenu.classList.remove('visible');
  });
}

/* 13. MOBILE MENU (PUBLIC) */
function initPublicHeaderMenuToggle() {
  const menuToggle = document.getElementById('menuToggle');
  const navMenu = document.getElementById('navLinks');
  if (!menuToggle || !navMenu) return;

  const toggleMenu = () => {
    navMenu.classList.toggle('show');
    menuToggle.classList.toggle('open');
  };
  menuToggle.addEventListener('click', e => { e.stopPropagation(); toggleMenu(); });
  menuToggle.addEventListener('touchend', e => { e.preventDefault(); toggleMenu(); });
  document.addEventListener('click', e => {
    if (!menuToggle.contains(e.target) && !navMenu.contains(e.target)) {
      navMenu.classList.remove('show'); menuToggle.classList.remove('open');
    }
  });
}

/* 14. LOGOUT MODULE */
async function initializeLogoutModule() {
  try {
    const logoutMod = await import('./logout.js?v=20260526a');
    logoutMod?.initLogout?.();
  } catch {
    setupFallbackLogout();
  }
}

// Fallback logout in case dynamic module fails
function setupFallbackLogout() {
  setTimeout(() => {
    const btn = document.getElementById('logoutBtn');
    if (!btn) return;
    const clone = btn.cloneNode(true);
    btn.parentNode.replaceChild(clone, btn);
    clone.addEventListener('click', async (e) => {
      e.preventDefault();
      const confirmed = await appConfirm('Are you sure you want to logout?', {
        title: 'Confirm Logout',
        confirmText: 'Logout'
      });
      if (confirmed) {
        if (typeof window.performEnhancedLogout === 'function') {
          await window.performEnhancedLogout('user_initiated', 'Fallback logout handler', {
            clearLocalData: true,
            logoutAllDevices: false
          });
          return;
        }
        await sessionManager.performLogout('user_initiated', 'Fallback logout handler');
      }
    });
  }, 300);
}

/* 15. THEME TOGGLE */
function initializeThemeToggle() {
  const html = document.documentElement;
  const btn = document.getElementById('themeToggle');
  const theme = localStorage.getItem('theme') || 'light';
  html.setAttribute('data-theme', theme);

  if (btn) {
    const clone = btn.cloneNode(true);
    btn.parentNode.replaceChild(clone, btn);
    const toggle = () => {
      const current = html.getAttribute('data-theme');
      const next = current === 'light' ? 'dark' : 'light';
      html.setAttribute('data-theme', next);
      localStorage.setItem('theme', next);
    };
    clone.addEventListener('click', toggle);
    clone.addEventListener('touchend', e => { e.preventDefault(); toggle(); });
  }
}

function initScrollToTopButton() {
  if (document.getElementById('scrollToTopBtn')) return;

  const button = document.createElement('button');
  button.id = 'scrollToTopBtn';
  button.type = 'button';
  button.className = 'scroll-to-top';
  button.setAttribute('aria-label', 'Scroll to top');
  button.innerHTML = '<span class="scroll-to-top-icon" aria-hidden="true">&#10095;</span>';
  document.body.appendChild(button);

  let visibilityFrame = 0;
  const updateVisibility = () => {
    if (visibilityFrame) return;
    visibilityFrame = window.requestAnimationFrame(() => {
      visibilityFrame = 0;
      const viewportWidth = window.innerWidth || document.documentElement.clientWidth || 0;
      const scrollTop = window.pageYOffset || document.documentElement.scrollTop || 0;
      const viewportHeight = window.innerHeight || document.documentElement.clientHeight || 0;
      const pageHeight = document.documentElement.scrollHeight || 0;
      const shouldShow = viewportWidth <= 767 && scrollTop > 220;
      const nearFooter = shouldShow && (viewportHeight + scrollTop) >= (pageHeight - 120);

      button.classList.toggle('show', shouldShow);
      button.classList.toggle('near-footer', nearFooter);
    });
  };

  button.addEventListener('click', () => {
    window.scrollTo({ top: 0, behavior: 'smooth' });
  });

  window.addEventListener('scroll', updateVisibility, { passive: true });
  window.addEventListener('resize', updateVisibility, { passive: true });
  window.requestAnimationFrame(updateVisibility);
}

function bindHardRefreshTriggers() {
  if (window.__pwaHardRefreshBound) return;
  window.__pwaHardRefreshBound = true;

  document.addEventListener('click', async (event) => {
    const trigger = event.target instanceof Element ? event.target.closest('[data-hard-refresh="true"]') : null;
    if (!trigger) return;
    event.preventDefault();
    await performHardRefresh();
  });
}

async function requestPwaUpdateCheck(options = {}) {
  if (window.PwaUpdateManager?.check) {
    return window.PwaUpdateManager.check(options);
  }

  window.__pendingPwaUpdateCheck = options;
  try {
    document.dispatchEvent(new CustomEvent('pwa:check-update', { detail: options }));
  } catch (_error) {
    // Ignore event dispatch failures.
  }
  return null;
}

async function clearAllIndexedDb() {
  if (!('indexedDB' in window)) return;
  if (typeof indexedDB.databases !== 'function') return;
  try {
    const databases = await indexedDB.databases();
    await Promise.all(
      databases
        .filter((db) => db && db.name)
        .map((db) => new Promise((resolve) => {
          const request = indexedDB.deleteDatabase(db.name);
          request.onsuccess = () => resolve();
          request.onerror = () => resolve();
          request.onblocked = () => resolve();
        }))
    );
  } catch (error) {
    console.warn('IndexedDB cleanup failed:', error.message || error);
  }
}

async function clearClientStorageForRefresh(options = {}) {
  const preserveSession = options.preserveSession !== false;
  const preserveLocalKeys = new Set([
    DEVICE_TOKEN_STORAGE_KEY,
    'loggedInUser',
    'userRole',
    'userRoleEffective',
    'sessionSettings',
    LAST_SECURE_PAGE_KEY
  ]);

  try {
    if ('caches' in window) {
      const keys = await caches.keys();
      await Promise.all(keys.map((key) => caches.delete(key)));
    }
  } catch (error) {
    console.warn('Cache cleanup failed:', error);
  }

  await clearAllIndexedDb();

  if (!preserveSession) {
    try {
      sessionStorage.clear();
    } catch (_error) {}
  }

  try {
    if (preserveSession) {
      Object.keys(localStorage || {}).forEach((key) => {
        if (!preserveLocalKeys.has(key)) {
          localStorage.removeItem(key);
        }
      });
    } else {
      localStorage.clear();
    }
  } catch (_error) {}
}

async function performHardRefresh() {
  const confirmed = typeof window.appConfirm === 'function'
    ? await window.appConfirm('This will clear cached data and reload the app while keeping your session active. Continue?', {
        title: 'Refresh App',
        confirmText: 'Refresh',
        cancelText: 'Cancel'
      })
    : window.confirm('This will clear cached data and reload the app while keeping your session active. Continue?');

  if (!confirmed) return;

  if (typeof window.appToast === 'function') {
    window.appToast('Refreshing app\u2026', {
      type: 'info',
      title: 'Updating',
      duration: 1800
    });
  }

  try {
    await requestPwaUpdateCheck({ reason: 'manual_refresh', skipReload: true, forceReload: true });
  } catch (error) {
    console.warn('Hard refresh update check failed:', error);
  }

  await clearClientStorageForRefresh({ preserveSession: true });

  const nextUrl = new URL(window.location.href);
  nextUrl.searchParams.set('hard_refresh', Date.now().toString());
  window.location.replace(nextUrl.toString());
}

// Expose for header interaction handlers (mobile profile dropdown).
window.performHardRefresh = performHardRefresh;

/* 16. ACTIVE PAGE HIGHLIGHTING */
function highlightActivePage() {
  const current = window.location.pathname.split('/').pop();
  document.querySelectorAll('.nav-link').forEach(link => {
    if (link.getAttribute('href') === current) link.classList.add('active');
  });
}

/* 17. FOOTER LOADING */
async function loadFooterWithCoordination(isAuthenticated = false) {
  try {
    await loadFooter({ variant: isAuthenticated ? 'authenticated' : 'public', notify: false });
    initializeGlobalFixedLayoutOffsetSync();
    syncGlobalFixedLayoutOffset();
    AppSettingsManager.applyFooterBrand();
  } finally {
    AppLoader.markFooterLoaded();
  }
}

function scheduleLiveChatInitialization(sessionState = {}) {
  if (window.__pensionsgoLiveChatInitScheduled) {
    return;
  }
  window.__pensionsgoLiveChatInitScheduled = true;
  

  const startLiveChat = async () => {
    try {
      const { initLiveChat } = await import('./modules/live_chat.js?v=20260609d');
      await initLiveChat({ userId: sessionState.userId || '' });
    } catch (error) {
      console.warn('Live chat initialization failed:', error.message || error);
      window.__pensionsgoLiveChatInitScheduled = false;
    }
  };

  const schedule = window.requestIdleCallback
    ? (callback) => window.requestIdleCallback(callback, { timeout: 1200 })
    : (callback) => window.setTimeout(callback, 150);

  schedule(() => {
    startLiveChat();
  });
}


/* 18. APP INITIALIZATION ENTRY POINT */
async function initializeApplication() {
  disableLegacyDevtoolsDetectors();
  ensureSharedOverlayStylesheet();
  console.log('[init] Initializing PensionsGo Application with Enhanced Session Management...');
  observeViewportModalRoots();
  enhanceResponsiveTables(document);
  enhanceDismissibleModals(document);
  initAppUI();
  initializeAuthenticatedPublicNavigationBridge();
  initPwaShell().catch((error) => {
    console.warn('PWA shell initialization failed:', error.message || error);
  });
  initScrollToTopButton();
  bindHardRefreshTriggers();

  AppSettingsManager.loadCached();
  const settingsPromise = AppSettingsManager.load({ timeoutMs: 1800 })
    .then(() => {
      AppSettingsManager.applyToDom();
    })
    .catch((error) => {
      console.log('[warn] App settings async load failed:', error.message || error);
    });
  
  const isLoginPage = window.location.pathname.includes("login.html") ||
                     window.location.pathname.endsWith("/");
  const forcedLogoutContext = hasLogoutRedirectContext();
  const reauthContext = hasReauthRedirectContext();

  if (isLoginPage && (forcedLogoutContext || reauthContext)) {
    clearClientAuthState();
  }

  const currentPage = getCurrentPageName();
  const requiresAuth = isAuthRequiredPage(currentPage);
  let sessionState = await validateActiveSession();

  if (isLoginPage && (forcedLogoutContext || reauthContext)) {
    sessionState = { active: false };
  }

  if (sessionState.active) {
    const hasPublicDropdownAllowance = hasValidAuthenticatedPublicAllowance(currentPage);

    if (requiresAuth && !hasTabAuthenticationVerified() && !sessionState.hostedSessionFallback) {
      await forceReauthentication(window.location.href, 'tab_reauthentication_required');
      return;
    }

    if (isPublicReauthPage(currentPage) && !hasPublicDropdownAllowance) {
      const reauthReturnUrl = getPreferredReauthReturnUrl(sessionState, currentPage);
      await forceReauthentication(reauthReturnUrl, 'public_page_reauthentication_required');
      return;
    }

    if (isLoginPage && !reauthContext) {
      window.location.replace(getRoleBasedRedirectUrl(sessionState.userRole || '', '', sessionState.userRoleEffective || ''));
      return;
    }

    syncClientAuthState(sessionState);
    if (sessionState.offline && typeof window.appToast === 'function') {
      const offlineMessage = isLocalAppServerContext()
        ? 'Internet is unavailable, but this app is running on a local server. Live actions can still work while the local server remains reachable.'
        : 'You are viewing cached app content. Live updates and save actions need an internet connection.';
      window.appToast(offlineMessage, {
        type: 'warning',
        title: 'Offline Mode',
        duration: 3600
      });
    }
    if (requiresAuth) {
      const accessRole = resolveAccessRole((sessionState.userRole || '').toLowerCase(), sessionState.userRoleEffective || '');
      if (!isUrlAccessibleForRole(currentPage, accessRole)) {
        window.location.replace(getRoleBasedRedirectUrl(sessionState.userRole || '', '', sessionState.userRoleEffective || ''));
        return;
      }
    }

    if (requiresAuth) {
      rememberLastSecurePage(window.location.href);
      sessionStorage.removeItem(PUBLIC_SESSION_ALLOWANCE_KEY);
    }
  } else {
    clearClientAuthState();
    if (!isLoginPage && requiresAuth) {
      redirectToLoginForAuth();
      return;
    }
  }

  const isLoggedIn = !!sessionState.active;
  const hasAsideLayout = document.querySelector('aside') !== null ||
    currentPage === 'dashboard.html' ||
    currentPage === 'admin_dashboard.html';
  document.body.classList.toggle('layout-public', !isLoggedIn);
  document.body.classList.toggle('layout-authenticated', isLoggedIn);
  document.body.classList.toggle('layout-with-aside', isLoggedIn && hasAsideLayout);
  ClientSecurityControls.configure(isLoggedIn);

  // Only initialize session monitoring for logged-in users on non-login pages
  if (isLoggedIn && !isLoginPage) {
    window.__broadcastCheckerRunning = true;
    // Initialize session manager
    sessionManager.initialize();
    scheduleLiveChatInitialization(sessionState);

    // Additional check after 3 seconds to catch any immediate conflicts
    setTimeout(() => {
      sessionManager.performSessionCheck();
    }, 3000);
  }
  
  const shouldUseAuthenticatedHeader = isLoggedIn && !isLoginPage;
  const headerPromise = loadAppropriateHeader(shouldUseAuthenticatedHeader);
  const footerPromise = loadFooterWithCoordination(isLoggedIn);
  await headerPromise;
  footerPromise.catch((error) => {
    console.warn('Footer async load failed:', error.message || error);
  });
  settingsPromise.catch(() => {});

  AppLoader.onAllLoaded(() => {
    AppSettingsManager.applyToDom();
  });
  
  console.log('[ok] Application initialized successfully');
}

/* 19. DOM READY EVENT */
document.addEventListener('DOMContentLoaded', () => {
  AppLoader.markDOMReady();
  initializeApplication().catch((error) => {
    console.error('Application initialization failed:', error);
  });
  AppLoader.onAllLoaded(() => {
    document.documentElement.classList.add('app-loaded');
    console.log('[ok] All core components loaded.');
  });
});

// Timeout safeguard in case some resource takes too long
setTimeout(() => {
  if (!AppLoader.isHeaderLoaded) AppLoader.markHeaderLoaded();
  if (!AppLoader.isFooterLoaded) AppLoader.markFooterLoaded();
}, 8000);

// Export functions for potential use in other modules
if (typeof module !== 'undefined' && module.exports) {
  module.exports = {
    initializeApplication,
    getRoleBasedRedirectUrl,
    verifyActiveSession,
    checkSessionStatus,
    isUserLikelyLoggedIn,
    sessionManager
  };
}

// Global error handler for better debugging
window.addEventListener('error', function(e) {
  console.error('Global error:', e.error);
});

// Promise rejection handler
window.addEventListener('unhandledrejection', function(e) {
  console.error('Unhandled promise rejection:', e.reason);
});

console.log('[ok] main.js loaded successfully.');
