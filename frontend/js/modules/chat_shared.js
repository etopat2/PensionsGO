export function escapeHtml(value) {
  return String(value ?? "").replace(/[&<>"']/g, (char) => ({
    "&": "&amp;",
    "<": "&lt;",
    ">": "&gt;",
    '"': "&quot;",
    "'": "&#039;"
  }[char]));
}

export function formatText(value) {
  return escapeHtml(value).replace(/\n/g, "<br>");
}

export function formatTime(value) {
  if (!value) return "";
  const date = new Date(String(value).replace(" ", "T"));
  if (Number.isNaN(date.getTime())) return "";
  return date.toLocaleTimeString([], { hour: "2-digit", minute: "2-digit" });
}

export function parseChatDate(value) {
  if (!value) return null;
  const date = new Date(String(value).replace(" ", "T"));
  return Number.isNaN(date.getTime()) ? null : date;
}

export function chatDateKey(value) {
  const date = parseChatDate(value);
  if (!date) return "";
  const year = date.getFullYear();
  const month = String(date.getMonth() + 1).padStart(2, "0");
  const day = String(date.getDate()).padStart(2, "0");
  return `${year}-${month}-${day}`;
}

export function formatChatFullDate(value) {
  const date = parseChatDate(value);
  if (!date) return "";
  return date.toLocaleDateString([], { weekday: "long", year: "numeric", month: "long", day: "numeric" });
}

export async function parseJsonResponse(response, fallbackMessage = "The server returned an unreadable response.") {
  const text = await response.text();
  try {
    return JSON.parse(text);
  } catch (_error) {
    const clean = text.replace(/<[^>]+>/g, " ").replace(/\s+/g, " ").trim();
    throw new Error(clean || fallbackMessage || `Server returned ${response.status}.`);
  }
}

export async function fetchJson(url, options = {}) {
  const { timeoutMs = 0, ...requestOptions } = options;
  const timeoutValue = Math.max(0, Number(timeoutMs || 0));
  let timeoutId = null;
  let abortController = null;
  let abortListener = null;
  const externalSignal = requestOptions.signal;
  if (timeoutValue > 0) {
    abortController = new AbortController();
    if (externalSignal?.aborted) {
      abortController.abort();
    } else if (externalSignal) {
      abortListener = () => abortController.abort();
      externalSignal.addEventListener("abort", abortListener, { once: true });
    }
    requestOptions.signal = abortController.signal;
    timeoutId = window.setTimeout(() => abortController.abort(), timeoutValue);
  }
  let response;
  try {
    response = await fetch(url, { credentials: "include", cache: "no-store", ...requestOptions });
  } catch (error) {
    return {
      success: false,
      message: error?.name === "AbortError" ? "The request timed out." : (error?.message || "The request could not be completed.")
    };
  } finally {
    if (timeoutId) window.clearTimeout(timeoutId);
    if (externalSignal && abortListener) {
      externalSignal.removeEventListener("abort", abortListener);
    }
  }
  let data;
  try {
    data = await parseJsonResponse(response);
  } catch (_error) {
    data = { success: false, message: "The server returned an unreadable response." };
  }
  if (!response.ok && data && data.success !== true) {
    data.message = data.message || "The request could not be completed.";
  }
  return data;
}

export function createClientNonce(prefix = "chat") {
  const random = window.crypto?.getRandomValues
    ? Array.from(window.crypto.getRandomValues(new Uint32Array(2))).map((part) => part.toString(36)).join("")
    : Math.random().toString(36).slice(2);
  return `${prefix}-${Date.now().toString(36)}-${random}`.slice(0, 80);
}

export function formatFileSize(bytes) {
  const size = Number(bytes || 0);
  if (!Number.isFinite(size) || size <= 0) return "";
  if (size < 1024) return `${size} B`;
  if (size < 1024 * 1024) return `${(size / 1024).toFixed(1)} KB`;
  return `${(size / 1024 / 1024).toFixed(1)} MB`;
}

export function formatDuration(seconds) {
  const total = Math.max(0, Math.round(Number(seconds || 0)));
  const mins = String(Math.floor(total / 60)).padStart(2, "0");
  const secs = String(total % 60).padStart(2, "0");
  return `${mins}:${secs}`;
}

export const STAFF_TYPING_NOTIFY_MS = 1200;
export const STAFF_TYPING_IDLE_MS = 5000;
export const STAFF_TYPING_PULSE_MS = 2500;
export const STAFF_TYPING_HIDE_MS = 1200;

export function staffVoiceCaptureConstraints() {
  return {
    audio: {
      echoCancellation: { ideal: true },
      noiseSuppression: { ideal: true },
      autoGainControl: { ideal: true },
      channelCount: { ideal: 1 }
    }
  };
}

export function getSupportedVoiceMimeType() {
  const candidates = [
    "audio/webm;codecs=opus",
    "audio/webm",
    "audio/ogg;codecs=opus",
    "audio/mp4"
  ];
  return candidates.find((type) => window.MediaRecorder?.isTypeSupported?.(type)) || "";
}

export function normalizeVoiceMimeType(mimeType = "") {
  const clean = String(mimeType || "").toLowerCase();
  if (clean.includes("ogg")) return "audio/ogg";
  if (clean.includes("mp4") || clean.includes("aac") || clean.includes("m4a")) return "audio/mp4";
  if (clean.includes("mpeg") || clean.includes("mp3")) return "audio/mpeg";
  if (clean.includes("wav")) return "audio/wav";
  return "audio/webm";
}

export function createStaffVoiceFile(chunks, startedAt) {
  const mimeType = normalizeVoiceMimeType(chunks.find((chunk) => chunk?.type)?.type || getSupportedVoiceMimeType() || "audio/webm");
  const extension = mimeType.includes("ogg") ? "ogg" : (mimeType.includes("mp4") || mimeType.includes("aac") ? "m4a" : "webm");
  const blob = new Blob(chunks, { type: mimeType });
  return {
    blob,
    file: new File([blob], `voice-note-${Date.now()}.${extension}`, { type: mimeType }),
    url: URL.createObjectURL(blob),
    duration: Math.max(1, Math.round((Date.now() - startedAt) / 1000)),
    mimeType
  };
}

export function getSupportedAttachmentVideoMimeType() {
  const candidates = [
    "video/webm;codecs=vp9,opus",
    "video/webm;codecs=vp8,opus",
    "video/webm"
  ];
  return candidates.find((type) => window.MediaRecorder?.isTypeSupported?.(type)) || "";
}

export function attachmentMediaKind(attachment = {}, message = {}) {
  if (isVoiceAttachment(attachment, message)) return "voice";
  const mime = String(attachment.mime_type || "").toLowerCase();
  const name = String(attachment.file_name || "").toLowerCase();
  if (mime.startsWith("image/") || /\.(png|jpe?g|gif|webp)$/i.test(name)) return "image";
  if (mime.startsWith("video/") || /\.(webm|mp4|mov)$/i.test(name)) return "video";
  if (mime.startsWith("audio/") || /\.(ogg|mp3|wav|m4a)$/i.test(name)) return "audio";
  return "file";
}

export function isVoiceAttachment(attachment = {}, message = {}) {
  const mime = String(attachment.mime_type || "").toLowerCase();
  const name = String(attachment.file_name || "").toLowerCase();
  return attachment.is_voice === true
    || message.message_kind === "voice"
    || message.kind === "voice"
    || mime.startsWith("audio/")
    || /\.(webm|ogg|mp3|wav|m4a)$/i.test(name);
}
