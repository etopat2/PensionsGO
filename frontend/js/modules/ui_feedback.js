const UI_FEEDBACK_STYLES_ID = "app-ui-feedback-styles";
const UI_FEEDBACK_TOAST_STACK_ID = "app-ui-toast-stack";

if (typeof window !== "undefined") {
  if (typeof window.appAlert !== "function") {
    window.appAlert = (message) => window.alert(message);
  }
  if (typeof window.appConfirm !== "function") {
    window.appConfirm = (message) => Promise.resolve(window.confirm(message));
  }
  if (typeof window.appPrompt !== "function") {
    window.appPrompt = (message, defaultValue = "") => Promise.resolve(window.prompt(message, defaultValue));
  }
  if (typeof window.appToast !== "function") {
    window.appToast = (message) => window.appAlert(message);
  }
}

function escapeHtml(value) {
  if (value === null || value === undefined) return "";
  return String(value)
    .replace(/&/g, "&amp;")
    .replace(/</g, "&lt;")
    .replace(/>/g, "&gt;")
    .replace(/"/g, "&quot;")
    .replace(/'/g, "&#039;");
}

function ensureStylesheet() {
  if (document.getElementById(UI_FEEDBACK_STYLES_ID)) return;
  const link = document.createElement("link");
  link.id = UI_FEEDBACK_STYLES_ID;
  link.rel = "stylesheet";
  link.href = new URL("../../css/ui_feedback.css", import.meta.url).href;
  document.head.appendChild(link);
}

function ensureToastStack() {
  let stack = document.getElementById(UI_FEEDBACK_TOAST_STACK_ID);
  if (stack) return stack;
  stack = document.createElement("div");
  stack.id = UI_FEEDBACK_TOAST_STACK_ID;
  stack.className = "app-ui-toast-stack";
  document.body.appendChild(stack);
  return stack;
}

function toast(message, options = {}) {
  ensureStylesheet();
  const stack = ensureToastStack();
  const type = String(options.type || "info").toLowerCase();
  const safeType = ["info", "success", "warning", "error"].includes(type) ? type : "info";
  const title = options.title || ({
    info: "Info",
    success: "Success",
    warning: "Warning",
    error: "Error"
  })[safeType];
  const duration = Number(options.duration || 3600);

  const item = document.createElement("article");
  item.className = `app-ui-toast app-ui-toast-${safeType}`;
  item.innerHTML = `
    <h4 class="app-ui-toast-title">${escapeHtml(title)}</h4>
    <p class="app-ui-toast-message">${escapeHtml(message || "")}</p>
  `;
  stack.appendChild(item);

  window.setTimeout(() => {
    item.remove();
  }, Math.max(900, duration));
}

function showDialog(config) {
  ensureStylesheet();
  return new Promise((resolve) => {
    const mode = config.mode || "alert";
    const title = config.title || (
      mode === "confirm" ? "Please Confirm" :
      mode === "prompt" ? "Input Required" :
      "Notification"
    );
    const confirmText = config.confirmText || (mode === "prompt" ? "Submit" : "OK");
    const cancelText = config.cancelText || "Cancel";
    const defaultValue = config.defaultValue || "";

    const overlay = document.createElement("div");
    overlay.className = "app-ui-modal-overlay";
    overlay.innerHTML = `
      <div class="app-ui-modal" role="dialog" aria-modal="true" aria-labelledby="appUiDialogTitle">
        <header class="app-ui-modal-header">
          <h3 id="appUiDialogTitle">${escapeHtml(title)}</h3>
        </header>
        <div class="app-ui-modal-body">
          <p class="app-ui-modal-message">${escapeHtml(config.message || "")}</p>
          ${mode === "prompt" ? `<input type="text" class="app-ui-modal-input" value="${escapeHtml(defaultValue)}" />` : ""}
        </div>
        <div class="app-ui-modal-actions">
          ${mode !== "alert" ? `<button type="button" class="app-ui-btn app-ui-btn-cancel">${escapeHtml(cancelText)}</button>` : ""}
          <button type="button" class="app-ui-btn app-ui-btn-primary">${escapeHtml(confirmText)}</button>
        </div>
      </div>
    `;

    const close = (result) => {
      overlay.remove();
      document.body.classList.remove("app-ui-open");
      resolve(result);
    };

    const onKey = (evt) => {
      if (evt.key === "Escape" && mode !== "alert") {
        evt.preventDefault();
        close(mode === "confirm" ? false : null);
      }
      if (evt.key === "Enter") {
        const target = evt.target;
        const isInput = target && target.classList && target.classList.contains("app-ui-modal-input");
        if (mode === "prompt" && !isInput) return;
        evt.preventDefault();
        if (mode === "prompt") {
          const input = overlay.querySelector(".app-ui-modal-input");
          close(input ? input.value : "");
        } else if (mode === "confirm") {
          close(true);
        } else {
          close(undefined);
        }
      }
    };

    document.body.appendChild(overlay);
    document.body.classList.add("app-ui-open");

    const input = overlay.querySelector(".app-ui-modal-input");
    const primaryBtn = overlay.querySelector(".app-ui-btn-primary");
    const cancelBtn = overlay.querySelector(".app-ui-btn-cancel");
    if (mode === "prompt" && input) {
      input.focus();
      input.select();
    } else if (primaryBtn) {
      primaryBtn.focus();
    }

    if (primaryBtn) {
      primaryBtn.addEventListener("click", () => {
        if (mode === "prompt") {
          close(input ? input.value : "");
          return;
        }
        if (mode === "confirm") {
          close(true);
          return;
        }
        close(undefined);
      });
    }

    if (cancelBtn) {
      cancelBtn.addEventListener("click", () => {
        close(mode === "confirm" ? false : null);
      });
    }

    overlay.addEventListener("click", (evt) => {
      if (evt.target !== overlay) return;
      if (mode === "alert") return;
      close(mode === "confirm" ? false : null);
    });

    overlay.addEventListener("keydown", onKey);
  });
}

function initApi() {
  if (window.AppUI) return window.AppUI;
  const api = {
    alert(message, options = {}) {
      return showDialog({
        mode: "alert",
        message: String(message || ""),
        title: options.title || "Notification",
        confirmText: options.confirmText || "OK"
      });
    },
    confirm(message, options = {}) {
      return showDialog({
        mode: "confirm",
        message: String(message || ""),
        title: options.title || "Please Confirm",
        confirmText: options.confirmText || "Confirm",
        cancelText: options.cancelText || "Cancel"
      });
    },
    prompt(message, defaultValue = "", options = {}) {
      return showDialog({
        mode: "prompt",
        message: String(message || ""),
        defaultValue: String(defaultValue || ""),
        title: options.title || "Input Required",
        confirmText: options.confirmText || "Submit",
        cancelText: options.cancelText || "Cancel"
      });
    },
    toast
  };

  window.AppUI = api;
  window.appAlert = (...args) => api.alert(...args);
  window.appConfirm = (...args) => api.confirm(...args);
  window.appPrompt = (...args) => api.prompt(...args);
  window.appToast = (...args) => api.toast(...args);

  // Replace native alert globally with themed modal.
  window.alert = (...args) => {
    api.alert(...args);
  };

  return api;
}

export function initAppUI() {
  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", () => {
      ensureStylesheet();
      initApi();
    }, { once: true });
    return window.AppUI || {};
  }
  ensureStylesheet();
  return initApi();
}
