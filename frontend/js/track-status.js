document.addEventListener("DOMContentLoaded", () => {
  const form = document.getElementById("trackForm");
  const resultDiv = document.getElementById("trackingResult");
  const queryInput = document.getElementById("trackingId");

  const stepModal = document.getElementById("stepDetailModal");
  const stepModalBackdrop = document.getElementById("stepDetailBackdrop");
  const stepModalClose = document.getElementById("stepDetailClose");
  const stepModalTitle = document.getElementById("stepModalTitle");
  const stepModalBody = document.getElementById("stepDetailBody");

  if (!form || !resultDiv) return;

  let stepStore = new Map();
  let stepCounter = 0;
  let carouselInstances = [];

  const DONE_STATES = ["completed", "approved", "verified", "submitted", "authorized", "done", "success"];
  const REJECTED_STATES = ["rejected", "declined", "queried", "query", "failed"];

  function openStepModal() {
    if (!stepModal) return;
    stepModal.classList.remove("hidden");
    stepModal.setAttribute("aria-hidden", "false");
    document.body.classList.add("modal-open");
  }

  function closeStepModal() {
    if (!stepModal) return;
    stepModal.classList.add("hidden");
    stepModal.setAttribute("aria-hidden", "true");
    document.body.classList.remove("modal-open");
  }

  async function ensureAuthenticated(redirectOnFailure = true) {
    try {
      const res = await fetch("../backend/api/check_session.php", {
        credentials: "include",
        cache: "no-store"
      });
      const data = await res.json();
      if (!data.active || !data.userId) {
        if (redirectOnFailure) {
          window.location.href = "login.html";
        }
        return false;
      }
      return true;
    } catch (error) {
      console.error("Session validation failed:", error);
      if (redirectOnFailure) {
        window.location.href = "login.html";
      }
      return false;
    }
  }

  function bindEventHandlers() {
    if (stepModalClose) {
      stepModalClose.addEventListener("click", closeStepModal);
    }

    if (stepModalBackdrop) {
      stepModalBackdrop.addEventListener("click", closeStepModal);
    }

    document.addEventListener("keydown", (event) => {
      if (event.key === "Escape") {
        closeStepModal();
      }
    });

    form.addEventListener("submit", async (event) => {
      event.preventDefault();
      const trackingId = (queryInput?.value || "").trim();
      resultDiv.innerHTML = "";
      stepStore = new Map();
      stepCounter = 0;
      closeStepModal();

      if (!trackingId) {
        resultDiv.innerHTML = '<p class="error-msg">Please enter a valid search term.</p>';
        return;
      }

      try {
        const res = await fetch(`../backend/api/get_application_status.php?q=${encodeURIComponent(trackingId)}`, {
          credentials: "include",
          cache: "no-store"
        });

        const data = await res.json();
        if (!data.success || !Array.isArray(data.records) || data.records.length === 0) {
          resultDiv.innerHTML = '<p class="error-msg">No matching application found.</p>';
          return;
        }

      resultDiv.innerHTML = data.records.map(renderRecordCard).join("");
      initializeProgressCarousels();
      } catch (error) {
        console.error("Tracking error:", error);
        resultDiv.innerHTML = '<p class="error-msg">Unable to load application status.</p>';
      }
    });

    resultDiv.addEventListener("click", (event) => {
      const node = event.target.closest(".step-node");
      if (!node) return;
      const instance = findCarouselInstanceByNode(node);
      if (instance) {
        centerNode(instance, node, true);
        updateCenteredNode(instance);
        updateProgressLine(instance);
      }
      const stepId = node.dataset.stepId;
      if (!stepId || !stepStore.has(stepId)) return;
      const data = stepStore.get(stepId);

      const fallbackMessage = buildFallbackMessage(data.state);
      const detailText = data.comment && data.comment.trim() !== "" ? data.comment : fallbackMessage;

      if (stepModalTitle) {
        stepModalTitle.textContent = `${data.label} Step`;
      }
      if (stepModalBody) {
        stepModalBody.innerHTML = `
          <div class="status-detail-row"><span>Status</span><strong>${escapeHtml(data.statusLabel)}</strong></div>
          <div class="status-detail-row"><span>Time</span><strong>${escapeHtml(data.timeLabel)}</strong></div>
          <div class="status-detail-row"><span>Applicant</span><strong>${escapeHtml(data.applicantName)}</strong></div>
          <div class="status-msg">${escapeHtml(detailText)}</div>
        `;
      }

      openStepModal();
    });
  }

  function renderRecordCard(record) {
    const progress = buildProgressSteps(record.steps || [], record.name || "Unknown Applicant");

    return `
      <article class="tracking-result-card">
        <div class="result-top compact">
          <div class="result-pill"><span>File</span><strong>${escapeHtml(record.regNo || "-")}</strong></div>
          <div class="result-pill"><span>Name</span><strong>${escapeHtml(record.name || "-")}</strong></div>
          <div class="result-pill"><span>Contact</span><strong>${escapeHtml(record.telNo || "-")}</strong></div>
          <div class="result-pill"><span>Status</span><strong>${escapeHtml(record.appnStatus || "-")}</strong></div>
        </div>
        ${progress.html}
      </article>
    `;
  }

  function buildProgressSteps(steps, applicantName) {
    const normalized = steps.map((step) => normalizeStep(step));
    const firstRejected = normalized.findIndex((step) => step.baseState === "rejected");
    const firstIncomplete = normalized.findIndex((step) => step.baseState !== "done");
    let currentIndex = 0;

    const nodes = normalized.map((step, index) => {
      let state = "pending";
      if (step.baseState === "rejected") {
        state = "rejected";
      } else if (step.baseState === "done") {
        state = "done";
      } else if (firstRejected >= 0) {
        state = index < firstRejected ? "done" : index === firstRejected ? "rejected" : "pending";
      } else if (firstIncomplete >= 0) {
        state = index < firstIncomplete ? "done" : index === firstIncomplete ? "current" : "pending";
      } else {
        state = "done";
      }
      if (state === "current" || state === "rejected") {
        currentIndex = index;
      } else if (firstIncomplete < 0 && state === "done") {
        currentIndex = index;
      }

      const statusLabel = step.statusLabel;
      const timeLabel = step.timeLabel;
      const detailText = step.comment && step.comment.trim() !== "" ? step.comment : buildFallbackMessage(state);
      const stepId = registerStep({
        label: step.label,
        statusLabel,
        timeLabel,
        comment: step.comment,
        state,
        applicantName
      });

      return `
        <button type="button" class="step-node is-${state} ${state === "current" ? "is-pulsing" : ""}" data-step-id="${stepId}" data-step-index="${index}" aria-label="${escapeHtml(step.label)}">
          <span class="node-dot"></span>
          <span class="node-label">${escapeHtml(step.label)}</span>
          <div class="node-panel">
            <div class="node-panel-head">
              <strong>${escapeHtml(step.label)}</strong>
              <span class="node-state">${escapeHtml(statusLabel)}</span>
            </div>
            <small>${escapeHtml(timeLabel)}</small>
            <p>${escapeHtml(detailText)}</p>
          </div>
        </button>
      `;
    }).join("");

    return {
      html: `
        <div class="progress-carousel" data-current-index="${currentIndex}">
          <div class="progress-track">
            ${nodes}
          </div>
        </div>
      `
    };
  }

  function normalizeStep(step) {
    const rawStatus = (step.status || "").toString().trim();
    const statusLower = rawStatus.toLowerCase();
    const hasTime = step.time && step.time !== "";

    let baseState = "pending";
    if (REJECTED_STATES.some((state) => statusLower.includes(state))) {
      baseState = "rejected";
    } else if (DONE_STATES.some((state) => statusLower.includes(state)) || (rawStatus !== "" && hasTime)) {
      baseState = "done";
    } else if (rawStatus !== "") {
      baseState = "current";
    }

    return {
      label: step.label || "Step",
      comment: step.comment || "",
      statusLabel: rawStatus !== "" ? toTitleCase(rawStatus) : "Pending",
      timeLabel: step.time || "Not yet",
      baseState
    };
  }

  function registerStep(stepData) {
    const stepId = `step_${++stepCounter}`;
    stepStore.set(stepId, stepData);
    return stepId;
  }

  function buildFallbackMessage(state) {
    if (state === "done") {
      return "No additional comment was recorded. This step has been completed.";
    }
    if (state === "current") {
      return "This step is currently active and awaiting completion.";
    }
    if (state === "rejected") {
      return "This step was queried or rejected and requires review.";
    }
    return "This step has not started yet.";
  }

  function toTitleCase(value) {
    if (!value) return "";
    return value
      .replace(/_/g, " ")
      .replace(/\s+/g, " ")
      .trim()
      .replace(/\b\w/g, (char) => char.toUpperCase());
  }

  function escapeHtml(value) {
    if (value === null || value === undefined) return "";
    return value
      .toString()
      .replace(/&/g, "&amp;")
      .replace(/</g, "&lt;")
      .replace(/>/g, "&gt;")
      .replace(/"/g, "&quot;")
      .replace(/'/g, "&#039;");
  }

  function initializeProgressCarousels() {
    carouselInstances.forEach((instance) => {
      if (instance.rafId) {
        cancelAnimationFrame(instance.rafId);
      }
    });
    carouselInstances = [];

    const carousels = resultDiv.querySelectorAll(".progress-carousel");
    carousels.forEach((carousel) => {
      const track = carousel.querySelector(".progress-track");
      const nodes = Array.from(carousel.querySelectorAll(".step-node"));
      if (!track || nodes.length === 0) return;

      const preferredIndexRaw = Number(carousel.dataset.currentIndex || "0");
      const preferredIndex = Number.isFinite(preferredIndexRaw) ? Math.min(Math.max(preferredIndexRaw, 0), nodes.length - 1) : 0;

      const instance = { carousel, track, nodes, rafId: null };
      carouselInstances.push(instance);

      const update = () => {
        updateTrackEdgePadding(instance);
        updateCenteredNode(instance);
        updateProgressLine(instance);
      };

      const scheduleUpdate = () => {
        if (instance.rafId) return;
        instance.rafId = requestAnimationFrame(() => {
          instance.rafId = null;
          update();
        });
      };

      track.addEventListener("scroll", scheduleUpdate, { passive: true });
      window.addEventListener("resize", scheduleUpdate);

      const preferredNode = nodes[preferredIndex];
      updateTrackEdgePadding(instance);
      centerNode(instance, preferredNode, false);
      update();
    });
  }

  function updateTrackEdgePadding(instance) {
    const { track, nodes } = instance;
    if (!track || !nodes || nodes.length === 0) return;
    const vertical = isVerticalLayout(track);

    if (vertical) {
      const nodeHeight = nodes[0].offsetHeight || 92;
      const edgePad = Math.max((track.clientHeight / 2) - (nodeHeight / 2), 8);
      track.style.setProperty("--edge-pad-block", `${edgePad}px`);
      return;
    }

    const nodeWidth = nodes[0].offsetWidth || 132;
    const edgePad = Math.max((track.clientWidth / 2) - (nodeWidth / 2), 16);
    track.style.setProperty("--edge-pad-inline", `${edgePad}px`);
  }

  function findCarouselInstanceByNode(node) {
    const parentTrack = node.closest(".progress-track");
    if (!parentTrack) return null;
    return carouselInstances.find((instance) => instance.track === parentTrack) || null;
  }

  function isVerticalLayout(track) {
    return window.getComputedStyle(track).flexDirection === "column";
  }

  function centerNode(instance, node, smooth = true) {
    if (!node) return;
    const { track } = instance;
    const vertical = isVerticalLayout(track);
    if (vertical) {
      const targetTop = node.offsetTop - (track.clientHeight / 2) + (node.clientHeight / 2);
      track.scrollTo({ top: Math.max(targetTop, 0), behavior: smooth ? "smooth" : "auto" });
    } else {
      const targetLeft = node.offsetLeft - (track.clientWidth / 2) + (node.clientWidth / 2);
      track.scrollTo({ left: Math.max(targetLeft, 0), behavior: smooth ? "smooth" : "auto" });
    }
  }

  function updateCenteredNode(instance) {
    const { track, nodes } = instance;
    const vertical = isVerticalLayout(track);
    const viewportCenter = vertical
      ? track.scrollTop + (track.clientHeight / 2)
      : track.scrollLeft + (track.clientWidth / 2);

    let nearestNode = nodes[0];
    let nearestDistance = Number.POSITIVE_INFINITY;

    nodes.forEach((node) => {
      const nodeCenter = vertical
        ? node.offsetTop + (node.clientHeight / 2)
        : node.offsetLeft + (node.clientWidth / 2);
      const distance = Math.abs(nodeCenter - viewportCenter);
      if (distance < nearestDistance) {
        nearestDistance = distance;
        nearestNode = node;
      }
    });

    nodes.forEach((node) => node.classList.remove("is-centered"));
    nearestNode.classList.add("is-centered");
  }

  function updateProgressLine(instance) {
    const { track, nodes } = instance;
    const vertical = isVerticalLayout(track);
    const centeredNode = nodes.find((node) => node.classList.contains("is-centered")) || nodes[0];
    const progressIndexRaw = Number(instance.carousel.dataset.currentIndex || "0");
    const progressIndex = Number.isFinite(progressIndexRaw)
      ? Math.min(Math.max(progressIndexRaw, 0), nodes.length - 1)
      : 0;
    const progressNode = nodes[progressIndex] || centeredNode;
    const firstNode = nodes[0];
    const lastNode = nodes[nodes.length - 1];

    if (!firstNode || !centeredNode || !lastNode) return;

    const firstDot = firstNode.querySelector(".node-dot");
    const progressDot = progressNode.querySelector(".node-dot");
    const lastDot = lastNode.querySelector(".node-dot");
    if (!firstDot || !progressDot || !lastDot) return;

    const trackRect = track.getBoundingClientRect();
    const firstDotRect = firstDot.getBoundingClientRect();
    const lastDotRect = lastDot.getBoundingClientRect();

    const firstCenter = vertical
      ? (firstDotRect.top - trackRect.top) + track.scrollTop + (firstDotRect.height / 2)
      : (firstDotRect.left - trackRect.left) + track.scrollLeft + (firstDotRect.width / 2);
    // Use average cross-axis center of all dots so the connector stays centered on every circle.
    const dotCenters = nodes
      .map((node) => node.querySelector(".node-dot"))
      .filter((dot) => !!dot)
      .map((dot) => dot.getBoundingClientRect())
      .map((rect) => vertical
        ? (rect.left - trackRect.left) + track.scrollLeft + (rect.width / 2)
        : (rect.top - trackRect.top) + track.scrollTop + (rect.height / 2)
      );

    const axisCenter = dotCenters.length
      ? (dotCenters.reduce((acc, val) => acc + val, 0) / dotCenters.length)
      : (vertical
        ? (firstDotRect.left - trackRect.left) + track.scrollLeft + (firstDotRect.width / 2)
        : (firstDotRect.top - trackRect.top) + track.scrollTop + (firstDotRect.height / 2)
      );

    const progressDotRect = progressDot.getBoundingClientRect();
    const progressCenter = vertical
      ? (progressDotRect.top - trackRect.top) + track.scrollTop + (progressDotRect.height / 2)
      : (progressDotRect.left - trackRect.left) + track.scrollLeft + (progressDotRect.width / 2);

    const lastCenter = vertical
      ? (lastDotRect.top - trackRect.top) + track.scrollTop + (lastDotRect.height / 2)
      : (lastDotRect.left - trackRect.left) + track.scrollLeft + (lastDotRect.width / 2);

    const lineLength = Math.max(lastCenter - firstCenter, 0);
    const progressLength = Math.max(progressCenter - firstCenter, 0);

    track.style.setProperty("--line-start", `${firstCenter}px`);
    track.style.setProperty("--line-length", `${lineLength}px`);
    track.style.setProperty("--line-progress", `${progressLength}px`);
    track.style.setProperty("--line-axis", `${axisCenter}px`);
  }

  const currentPage = (window.location.pathname.split("/").pop() || "index.html").toLowerCase();
  const authRequiredPages = new Set(["application_status.html"]);
  const requiresAuth = authRequiredPages.has(currentPage);

  (async () => {
    if (requiresAuth) {
      const authenticated = await ensureAuthenticated(true);
      if (!authenticated) return;
    }
    bindEventHandlers();
  })();
});
