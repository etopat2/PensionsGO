"use strict";

const fs = require("fs");
const path = require("path");
const { chromium } = require("./playwright-smoke/node_modules/playwright-core");

const ROOT = path.resolve(__dirname, "..");
const BASE_URL = process.env.PENSIONAPP_BASE_URL || "http://localhost/PROJECTS/PensionApp";
const FRONTEND_URL = `${BASE_URL}/frontend`;
const CHROME_PATH = process.env.CHROME_PATH || "C:\\Program Files (x86)\\Google\\Chrome\\Application\\chrome.exe";
const PASSWORD = process.env.CODEX_SMOKE_PASSWORD || "Prisons123!";
const MARKER = process.env.CODEX_SMOKE_MARKER || `CODEX_PUBLIC_CHAT_${Date.now()}`;
const FAKE_MEDIA = process.env.CODEX_REAL_MEDIA === "1" ? false : true;
const profileRoot = path.join(ROOT, "temp", `pw-public-chat-${MARKER.replace(/[^a-z0-9_-]/gi, "_")}`);
const AGENT = { email: "etomet2patrick@gmail.com" };
const CHAT_API_RE = /\/backend\/api\/(?:login\.php|public_chat_|get_csrf_token\.php)/;

const delay = (ms) => new Promise((resolve) => setTimeout(resolve, ms));

function writeReport(report, exitCode) {
  const json = `${JSON.stringify(report, null, 2)}\n`;
  if (process.env.CODEX_REPORT_FILE) {
    fs.writeFileSync(process.env.CODEX_REPORT_FILE, json);
  } else {
    fs.writeSync(exitCode ? 2 : 1, json);
  }
  process.exit(exitCode);
}

function pushCapped(list, value, limit = 120) {
  list.push(value);
  while (list.length > limit) list.shift();
}

async function launchProfile(name, x) {
  const userDataDir = path.join(profileRoot, name);
  fs.mkdirSync(userDataDir, { recursive: true });
  const args = [
    "--no-first-run",
    "--no-default-browser-check",
    "--disable-background-networking",
    "--disable-background-timer-throttling",
    "--disable-backgrounding-occluded-windows",
    "--disable-renderer-backgrounding",
    "--disable-features=CalculateNativeWinOcclusion",
    "--autoplay-policy=no-user-gesture-required",
    "--use-fake-ui-for-media-stream",
    "--unsafely-treat-insecure-origin-as-secure=http://localhost",
    `--window-position=${x},40`,
    "--window-size=980,900"
  ];
  if (FAKE_MEDIA) args.push("--use-fake-device-for-media-stream");
  return chromium.launchPersistentContext(userDataDir, {
    executablePath: CHROME_PATH,
    headless: false,
    viewport: { width: 980, height: 900 },
    acceptDownloads: true,
    permissions: ["microphone", "camera", "notifications"],
    args
  });
}

function collectBrowserEvents(page, bucket) {
  page.on("console", (msg) => {
    if (["warning", "error"].includes(msg.type())) bucket.console.push({ type: msg.type(), text: msg.text().slice(0, 500) });
  });
  page.on("pageerror", (error) => bucket.pageErrors.push(String(error.message || error).slice(0, 500)));
  page.on("requestfailed", (request) => {
    const failure = request.failure();
    bucket.requestFailures.push({ url: request.url(), method: request.method(), error: failure?.errorText || "" });
  });
  page.on("response", (response) => {
    const url = response.url();
    if (CHAT_API_RE.test(url)) pushCapped(bucket.apiResponses, { at: Date.now(), status: response.status(), url });
    if (response.status() >= 400 && CHAT_API_RE.test(url)) bucket.httpErrors.push({ status: response.status(), url });
  });
}

async function login(page, user) {
  await page.goto(`${FRONTEND_URL}/login.html?smoke=${encodeURIComponent(MARKER)}`, { waitUntil: "domcontentloaded" });
  const data = await page.evaluate(async ({ email, password }) => {
    const form = new FormData();
    form.append("email", email);
    form.append("password", password);
    const response = await fetch("../backend/api/login.php", { method: "POST", body: form, credentials: "include" });
    const json = await response.json().catch(() => ({}));
    if (!response.ok || !json.success) return { success: false, status: response.status, json };
    sessionStorage.setItem("isLoggedIn", "true");
    sessionStorage.setItem("userName", json.userName || "");
    sessionStorage.setItem("userRole", json.userRole || "");
    sessionStorage.setItem("userRoleEffective", json.userRoleEffective || "");
    sessionStorage.setItem("userId", json.userId || "");
    sessionStorage.setItem("phoneNo", json.phoneNo || "");
    sessionStorage.setItem("userPhoto", json.userPhoto || "");
    sessionStorage.setItem("lastActivity", Date.now().toString());
    sessionStorage.setItem("sessionTimeout", json.sessionTimeout || 1800);
    sessionStorage.setItem("gracePeriod", json.gracePeriod || 5);
    sessionStorage.setItem("pensionsgo_tab_auth_verified", "true");
    localStorage.setItem("loggedInUser", JSON.stringify({
      name: json.userName || "",
      role: json.userRole || "",
      effectiveRole: json.userRoleEffective || json.userRole || "",
      id: json.userId || "",
      photo: json.userPhoto || "images/default-user.png",
      phone: json.phoneNo || "",
      sessionTimeout: json.sessionTimeout || 1800,
      gracePeriod: json.gracePeriod || 5
    }));
    localStorage.setItem("userRole", json.userRole || "");
    localStorage.setItem("userRoleEffective", json.userRoleEffective || "");
    if (json.sessionId && json.userId) {
      localStorage.setItem("pensionsgo_hosted_session_id", String(json.sessionId));
      localStorage.setItem("pensionsgo_hosted_session_user", String(json.userId));
      localStorage.setItem("pensionsgo_hosted_session_verified_at", Date.now().toString());
      document.cookie = `PENSION_APP_CLIENT_SID=${encodeURIComponent(json.sessionId)}; Path=/; SameSite=Lax`;
      document.cookie = `PENSION_APP_CLIENT_UID=${encodeURIComponent(json.userId)}; Path=/; SameSite=Lax`;
    }
    return { success: true, userId: json.userId, userName: json.userName, role: json.userRole };
  }, { email: user.email, password: PASSWORD });
  if (!data?.success) throw new Error(`Login failed for ${user.email}: ${JSON.stringify(data)}`);
  return data;
}

async function fillAndInput(page, selector, value) {
  await page.locator(selector).fill(value);
  await page.locator(selector).dispatchEvent("input", { bubbles: true });
}

async function testAudioPreview(page, selector) {
  return page.evaluate(async (sel) => {
    const audio = document.querySelector(sel);
    if (!audio) return { ok: false, reason: "missing audio" };
    audio.muted = true;
    audio.volume = 0;
    audio.load?.();
    await new Promise((resolve) => {
      if (audio.readyState >= 1) return resolve();
      audio.addEventListener("loadedmetadata", resolve, { once: true });
      setTimeout(resolve, 2500);
    });
    const before = audio.currentTime;
    try {
      await audio.play();
    } catch (error) {
      return { ok: false, reason: error.message, readyState: audio.readyState, duration: audio.duration || 0 };
    }
    await new Promise((resolve) => setTimeout(resolve, 500));
    audio.pause();
    return {
      ok: audio.currentTime > before || audio.readyState >= 2,
      readyState: audio.readyState,
      duration: Number.isFinite(audio.duration) ? audio.duration : 0,
      currentTime: audio.currentTime
    };
  }, selector);
}

async function openAgentConsole(page) {
  await page.goto(`${FRONTEND_URL}/dashboard.html?smoke=${encodeURIComponent(MARKER)}`, { waitUntil: "domcontentloaded" });
  await page.waitForFunction(() => Boolean(document.getElementById("publicChatDashboardMount")), null, { timeout: 25000 });
  await page.waitForFunction(() => Boolean(document.getElementById("publicChatDashboardMount") && !document.getElementById("publicChatDashboardMount").hidden), null, { timeout: 25000 });
  await page.evaluate(() => {
    document.querySelectorAll(".dashboard-section").forEach((section) => section.classList.toggle("active", section.id === "publicChatSection"));
    document.querySelectorAll(".desktop-nav a[data-target]").forEach((link) => link.classList.toggle("active", link.dataset.target === "publicChatSection"));
    const mobile = document.getElementById("mobileNavSelect");
    if (mobile) mobile.value = "publicChatSection";
  });
  await page.waitForSelector("#publicChatAvailabilityBtn", { state: "visible", timeout: 15000 });
  const label = await page.locator("#publicChatAvailabilityBtn").textContent();
  if (/set online/i.test(label || "")) {
    await page.locator("#publicChatAvailabilityBtn").click();
    await page.waitForFunction(() => /set offline/i.test(document.getElementById("publicChatAvailabilityBtn")?.textContent || ""), null, { timeout: 15000 });
  }
  await page.locator("#publicChatOpenConsoleBtn").click();
  await page.waitForFunction(() => document.getElementById("publicChatConsoleModal")?.getAttribute("aria-hidden") === "false", null, { timeout: 12000 });
}

async function startVisitorChat(page, context, results) {
  await context.clearCookies();
  await page.goto(`${FRONTEND_URL}/index.html?smoke=${encodeURIComponent(MARKER)}`, { waitUntil: "domcontentloaded" });
  await page.evaluate(() => { localStorage.clear(); sessionStorage.clear(); });
  await page.reload({ waitUntil: "domcontentloaded" });
  await page.waitForSelector("#publicChatLauncher", { timeout: 20000 });
  await page.locator("#publicChatLauncher").click();
  await page.waitForSelector("#publicChatStartForm", { timeout: 12000 });
  const subject = `${MARKER} public subject`;
  const initial = `${MARKER} initial public message`;
  const started = Date.now();
  await page.evaluate(({ marker, subject, initial }) => {
    const form = document.getElementById("publicChatStartForm");
    form.querySelector('[name="visitor_name"]').value = `Browser Smoke Visitor ${marker.slice(-6)}`;
    form.querySelector('[name="phone_number"]').value = "0700000042";
    form.querySelector('[name="email"]').value = "browser-smoke@example.com";
    form.querySelector('[name="inquiry_category"]').value = "General inquiry";
    form.querySelector('[name="subject"]').value = subject;
    form.querySelector('[name="message"]').value = initial;
    form.querySelector('[name="consent"]').checked = true;
    form.dispatchEvent(new Event("submit", { bubbles: true, cancelable: true }));
  }, { marker: MARKER, subject, initial });
  await page.waitForSelector("#publicChatThread", { state: "attached", timeout: 15000 });
  results.startMs = Date.now() - started;
  return { subject, initial };
}

async function acceptChat(agentPage, subject, results) {
  const started = Date.now();
  await delay(800);
  await agentPage.locator("#publicChatRefreshDashboardBtn").click().catch(() => {});
  try {
    await agentPage.waitForFunction((text) => {
      const needle = String(text || "").toLowerCase();
      const row = Array.from(document.querySelectorAll("[data-public-chat-session]")).find((node) => node.textContent.toLowerCase().includes(needle));
      if (row) {
        row.click();
        return true;
      }
      return false;
    }, subject, { timeout: 45000, polling: 500 });
  } catch (error) {
    results.queueDiagnostics = await agentPage.evaluate((text) => ({
      subject: text,
      consoleOpen: document.getElementById("publicChatConsoleModal")?.getAttribute("aria-hidden"),
      view: Array.from(document.querySelectorAll("[data-public-chat-console-view]")).find((button) => button.classList.contains("active"))?.dataset.publicChatConsoleView || "",
      title: document.getElementById("publicChatConsoleListTitle")?.textContent || "",
      listText: document.getElementById("publicChatConsoleList")?.textContent.replace(/\s+/g, " ").trim() || "",
      rowCount: document.querySelectorAll("[data-public-chat-session]").length,
      rows: Array.from(document.querySelectorAll("[data-public-chat-session]")).slice(0, 8).map((row) => row.textContent.replace(/\s+/g, " ").trim()),
      availability: document.getElementById("publicChatAvailabilityBtn")?.textContent || "",
      sectionActive: document.getElementById("publicChatSection")?.className || ""
    }), subject).catch((diagError) => ({ error: diagError.message || String(diagError) }));
    throw error;
  }
  results.queueAppearMs = Date.now() - started;
  await agentPage.waitForFunction(() => Boolean(document.getElementById("publicChatDashboardAcceptBtn")) && !document.getElementById("publicChatDashboardAcceptBtn").disabled, null, { timeout: 12000 });
  await agentPage.locator("#publicChatDashboardAcceptBtn").click();
  await agentPage.waitForFunction(() => !document.getElementById("publicChatDashboardReplyText")?.disabled, null, { timeout: 12000 });
}

async function textRoundTrip(agentPage, visitorPage, results) {
  const visitorMessage = `${MARKER} visitor public followup`;
  const agentMessage = `${MARKER} agent public reply`;
  await fillAndInput(visitorPage, "#publicChatReplyText", visitorMessage);
  let started = Date.now();
  await visitorPage.keyboard.press("Enter");
  await agentPage.waitForFunction((text) => Array.from(document.querySelectorAll(".public-chat-dashboard-message.visitor")).some((node) => node.textContent.includes(text)), visitorMessage, { timeout: 12000 });
  results.visitorToAgentMs = Date.now() - started;

  await fillAndInput(agentPage, "#publicChatDashboardReplyText", agentMessage);
  started = Date.now();
  await agentPage.keyboard.press("Enter");
  await visitorPage.waitForFunction((text) => Array.from(document.querySelectorAll(".public-chat-message.agent")).some((node) => node.textContent.includes(text)), agentMessage, { timeout: 12000 });
  results.agentToVisitorMs = Date.now() - started;
}

async function voiceRoundTrip(agentPage, visitorPage, results) {
  const started = Date.now();
  await visitorPage.locator("#publicChatVoiceBtn").click();
  await visitorPage.waitForSelector("#publicChatStopRecording", { timeout: 8000 });
  await delay(1700);
  await visitorPage.locator("#publicChatStopRecording").click();
  await visitorPage.waitForSelector("#publicChatSendVoiceDraft", { timeout: 12000 });
  const visitorDraft = await visitorPage.evaluate(() => {
    const input = document.querySelector("#publicChatVoiceDraft audio source");
    return {
      sourceType: input?.getAttribute("type") || "",
      source: Boolean(input?.getAttribute("src")),
      text: document.getElementById("publicChatVoiceDraft")?.textContent.replace(/\s+/g, " ").trim() || ""
    };
  });
  const preview = await testAudioPreview(visitorPage, "#publicChatVoiceDraft audio");
  await visitorPage.locator("#publicChatSendVoiceDraft").click();
  await agentPage.waitForSelector(".public-chat-dashboard-message.visitor .live-thread-voice-note audio", { timeout: 25000 });
  const receivedPreview = await testAudioPreview(agentPage, ".public-chat-dashboard-message.visitor .live-thread-voice-note audio");
  results.visitorVoice = { ok: preview.ok && receivedPreview.ok, ms: Date.now() - started, draft: visitorDraft, preview, receivedPreview };

  const agentStarted = Date.now();
  await agentPage.locator("#publicChatDashboardVoiceBtn").click();
  await agentPage.waitForSelector("#publicChatDashboardStopRecording", { timeout: 8000 });
  await delay(1700);
  await agentPage.locator("#publicChatDashboardStopRecording").click();
  await agentPage.waitForSelector("#publicChatDashboardSendVoiceDraft", { timeout: 12000 });
  const agentDraft = await agentPage.evaluate(() => {
    const input = document.querySelector("#publicChatDashboardVoiceDraft audio source");
    return {
      sourceType: input?.getAttribute("type") || "",
      source: Boolean(input?.getAttribute("src")),
      text: document.getElementById("publicChatDashboardVoiceDraft")?.textContent.replace(/\s+/g, " ").trim() || ""
    };
  });
  const agentPreview = await testAudioPreview(agentPage, "#publicChatDashboardVoiceDraft audio");
  await agentPage.locator("#publicChatDashboardSendVoiceDraft").click();
  await visitorPage.waitForSelector(".public-chat-message.agent .live-thread-voice-note audio", { timeout: 25000 });
  const agentReceivedPreview = await testAudioPreview(visitorPage, ".public-chat-message.agent .live-thread-voice-note audio");
  results.agentVoice = { ok: agentPreview.ok && agentReceivedPreview.ok, ms: Date.now() - agentStarted, draft: agentDraft, preview: agentPreview, receivedPreview: agentReceivedPreview };
}

async function finishChat(agentPage, visitorPage, results) {
  await visitorPage.locator("#publicChatEndBtn").click().catch(() => {});
  const feedbackVisible = await visitorPage.waitForFunction(() => {
    const modal = document.getElementById("publicChatFeedbackModal");
    return modal && !modal.hidden;
  }, null, { timeout: 10000 }).then(() => true).catch(() => false);
  if (feedbackVisible) {
    await visitorPage.evaluate(() => {
      const stars = Array.from(document.querySelectorAll("#publicChatRating button"));
      (stars[4] || stars[0])?.click();
      const comments = document.getElementById("publicChatFeedbackComments");
      if (comments) comments.value = "Browser smoke feedback";
      document.getElementById("publicChatFeedbackSend")?.click();
    });
    await visitorPage.waitForFunction(() => document.getElementById("publicChatFeedbackStatus")?.textContent.toLowerCase().includes("thank"), null, { timeout: 12000 });
    results.feedback = "submitted";
  } else {
    results.feedback = "not shown";
  }
  await agentPage.evaluate(() => {
    if (/set offline/i.test(document.getElementById("publicChatAvailabilityBtn")?.textContent || "")) {
      document.getElementById("publicChatAvailabilityBtn")?.click();
    }
  }).catch(() => {});
}

async function main() {
  if (!fs.existsSync(CHROME_PATH)) throw new Error(`Chrome not found at ${CHROME_PATH}`);
  fs.mkdirSync(profileRoot, { recursive: true });
  const results = {
    marker: MARKER,
    baseUrl: BASE_URL,
    fakeMedia: FAKE_MEDIA,
    public: {},
    browserErrors: {
      agent: { console: [], pageErrors: [], requestFailures: [], httpErrors: [], apiResponses: [] },
      visitor: { console: [], pageErrors: [], requestFailures: [], httpErrors: [], apiResponses: [] }
    }
  };
  let agentContext;
  let visitorContext;
  try {
    agentContext = await launchProfile("agent", 20);
    visitorContext = await launchProfile("visitor", 1020);
    const agentPage = agentContext.pages()[0] || await agentContext.newPage();
    const visitorPage = visitorContext.pages()[0] || await visitorContext.newPage();
    collectBrowserEvents(agentPage, results.browserErrors.agent);
    collectBrowserEvents(visitorPage, results.browserErrors.visitor);

    results.login = await login(agentPage, AGENT);
    await openAgentConsole(agentPage);
    const seed = await startVisitorChat(visitorPage, visitorContext, results.public);
    await acceptChat(agentPage, seed.subject, results.public);
    await textRoundTrip(agentPage, visitorPage, results.public);
    await voiceRoundTrip(agentPage, visitorPage, results.public);
    await finishChat(agentPage, visitorPage, results.public);

    results.ok = Boolean(results.public.visitorVoice?.ok && results.public.agentVoice?.ok);
    if (!results.ok) throw new Error("Public voice note playback smoke failed.");
  } catch (error) {
    results.ok = false;
    results.error = error.message || String(error);
  } finally {
    await agentContext?.close().catch(() => {});
    await visitorContext?.close().catch(() => {});
  }
  writeReport(results, results.ok ? 0 : 1);
}

main().catch((error) => writeReport({ ok: false, marker: MARKER, error: error.message || String(error) }, 1));
