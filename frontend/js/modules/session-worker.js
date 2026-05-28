/**
 * session-worker.js
 * 
 * FIXES:
 * ✔ 403 ≠ session expired
 * ✔ Expiry broadcast once
 * ✔ Grace-aware
 * ✔ No modal spam
 */

let BASE_API = null;
let DEVICE_TOKEN = '';
let sessionExpiredBroadcasted = false;

const state = {
  pollingInterval: 15000,
  failures: 0
};

self.onmessage = (e) => {
  if (e.data.type === "CONFIG") {
    BASE_API = e.data.BASE_API;
    DEVICE_TOKEN = e.data.deviceToken || '';
  }
  if (e.data.type === "START_MONITORING") poll();
};

async function poll() {
  if (!BASE_API) return;

  try {
    const res = await fetch(BASE_API + "check_session.php", {
      credentials: "include",
      cache: "no-store",
      headers: DEVICE_TOKEN ? { "X-Device-Token": DEVICE_TOKEN } : {}
    });

    if (!res.ok) return;

    const data = await res.json();

    self.postMessage({ type: "SESSION_CHECK_RESULT", data });

    if (!data.active && !sessionExpiredBroadcasted) {
      sessionExpiredBroadcasted = true;
      self.postMessage({
        type: "SESSION_EXPIRED",
        reason: data.reason || "expired",
        message: data.message || "Session expired"
      });
    }

    state.failures = 0;
  } catch {
    state.failures++;
  }

  setTimeout(poll, state.pollingInterval);
}

self.postMessage({ type: "WORKER_READY" });

