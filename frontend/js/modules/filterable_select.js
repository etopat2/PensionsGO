(function () {
  const instances = new WeakMap();
  const liveInstances = new Set();

  function escapeHtml(value) {
    return String(value ?? "")
      .replace(/&/g, "&amp;")
      .replace(/</g, "&lt;")
      .replace(/>/g, "&gt;")
      .replace(/"/g, "&quot;")
      .replace(/'/g, "&#039;");
  }

  function normalize(value) {
    return String(value || "").trim().toLowerCase();
  }

  function collectOptions(select) {
    const entries = [];
    Array.from(select.options || []).forEach((option, index) => {
      entries.push({
        value: option.value,
        label: option.textContent || option.label || option.value || "",
        normalizedLabel: normalize(option.textContent || option.label || option.value || ""),
        disabled: Boolean(option.disabled),
        selected: Boolean(option.selected),
        index
      });
    });
    return entries;
  }

  function getSelectedEntry(entries, select) {
    const direct = entries.find((entry) => entry.value === select.value);
    if (direct) return direct;
    return entries.find((entry) => entry.selected) || entries[0] || null;
  }

  function isClippingOverflow(value) {
    return /(auto|scroll|hidden|clip)/i.test(String(value || ""));
  }

  function findPlacementBoundary(element) {
    let current = element?.parentElement || null;
    while (current && current !== document.body) {
      const styles = window.getComputedStyle(current);
      if (
        isClippingOverflow(styles.overflow) ||
        isClippingOverflow(styles.overflowY) ||
        isClippingOverflow(styles.overflowX)
      ) {
        return current;
      }
      current = current.parentElement;
    }
    return null;
  }

  function resetMenuPlacement(instance) {
    if (!instance) return;
    instance.wrapper.style.zIndex = "";
    instance.menu.style.top = "";
    instance.menu.style.bottom = "";
    instance.menu.style.zIndex = "";
    instance.menu.style.maxHeight = "";
  }

  function updateMenuPlacement(instance) {
    if (!instance || !instance.wrapper.classList.contains("is-open")) {
      resetMenuPlacement(instance);
      return;
    }

    const boundary = findPlacementBoundary(instance.wrapper);
    const boundaryRect = boundary?.getBoundingClientRect?.();
    const wrapperRect = instance.wrapper.getBoundingClientRect();
    const defaultMaxHeight = Number.parseFloat(
      instance.menu.dataset.defaultMaxHeight || window.getComputedStyle(instance.menu).maxHeight
    ) || 260;
    const menuHeight = Math.min(instance.menu.scrollHeight || defaultMaxHeight, defaultMaxHeight);
    const gutter = 10;
    const topLimit = Number.isFinite(boundaryRect?.top) ? boundaryRect.top : 0;
    const bottomLimit = Number.isFinite(boundaryRect?.bottom) ? boundaryRect.bottom : window.innerHeight;
    const spaceBelow = Math.max(0, bottomLimit - wrapperRect.bottom - gutter);
    const spaceAbove = Math.max(0, wrapperRect.top - topLimit - gutter);
    const openUpward = spaceBelow < menuHeight && spaceAbove >= spaceBelow;
    const availableSpace = Math.max(openUpward ? spaceAbove : spaceBelow, 48);
    const resolvedMaxHeight = Math.min(defaultMaxHeight, Math.max(48, Math.floor(availableSpace)));

    instance.menu.dataset.defaultMaxHeight = String(defaultMaxHeight);
    instance.wrapper.style.zIndex = "70";
    instance.menu.style.zIndex = "90";
    instance.menu.style.top = openUpward ? "auto" : "calc(100% + 0.35rem)";
    instance.menu.style.bottom = openUpward ? "calc(100% + 0.35rem)" : "auto";
    instance.menu.style.maxHeight = `${resolvedMaxHeight}px`;
    instance.wrapper.classList.toggle("opens-upward", openUpward);
  }

  function createInstance(select, config = {}) {
    const wrapper = document.createElement("div");
    wrapper.className = `filterable-select-shell ${select.className || ""}`.trim();

    const control = document.createElement("div");
    control.className = "filterable-select-control";

    const input = document.createElement("input");
    input.type = "text";
    input.className = "filterable-select-input";
    input.autocomplete = "off";
    input.setAttribute("role", "combobox");
    input.setAttribute("aria-autocomplete", "list");
    input.setAttribute("aria-expanded", "false");
    input.placeholder = config.placeholder || select.options?.[0]?.textContent || "Type to filter options";

    const toggle = document.createElement("button");
    toggle.type = "button";
    toggle.className = "filterable-select-toggle";
    toggle.setAttribute("aria-label", "Toggle options");
    toggle.innerHTML = '<span aria-hidden="true">&#9662;</span>';

    const menu = document.createElement("div");
    menu.className = "filterable-select-menu hidden";
    menu.setAttribute("role", "listbox");

    control.appendChild(input);
    control.appendChild(toggle);
    wrapper.appendChild(control);
    wrapper.appendChild(menu);

    select.classList.add("filterable-select-native");
    select.setAttribute("tabindex", "-1");
    select.insertAdjacentElement("afterend", wrapper);

    const instance = {
      select,
      wrapper,
      input,
      toggle,
      menu,
      config,
      entries: [],
      filteredEntries: [],
      highlightIndex: -1,
      observer: null
    };

    instances.set(select, instance);
    liveInstances.add(instance);
    bindInstance(instance);
    syncElement(select);
  }

  function openMenu(instance) {
    if (instance.input.disabled) return;
    instance.wrapper.classList.add("is-open");
    instance.menu.classList.remove("hidden");
    instance.input.setAttribute("aria-expanded", "true");
    renderMenu(instance, instance.input.value);
    updateMenuPlacement(instance);
  }

  function closeMenu(instance) {
    instance.wrapper.classList.remove("is-open");
    instance.wrapper.classList.remove("opens-upward");
    instance.menu.classList.add("hidden");
    instance.input.setAttribute("aria-expanded", "false");
    instance.highlightIndex = -1;
    resetMenuPlacement(instance);
  }

  function selectEntry(instance, entry, { dispatch = true } = {}) {
    instance.select.value = entry?.value ?? "";
    instance.input.value = entry?.value === "" ? "" : (entry?.label || "");
    instance.entries.forEach((item) => {
      const option = instance.select.options[item.index];
      if (option) option.selected = option.value === instance.select.value;
    });
    if (dispatch) {
      instance.select.dispatchEvent(new Event("change", { bubbles: true }));
      instance.select.dispatchEvent(new Event("input", { bubbles: true }));
    }
    renderMenu(instance, instance.input.value);
  }

  function reconcileInput(instance) {
    const typed = normalize(instance.input.value);
    const selected = getSelectedEntry(instance.entries, instance.select);
    if (typed === "") {
      instance.input.value = selected?.value === "" ? "" : (selected?.label || "");
      return;
    }
    const exact = instance.entries.find((entry) => !entry.disabled && entry.normalizedLabel === typed);
    if (exact) {
      selectEntry(instance, exact);
      return;
    }
    instance.input.value = selected?.value === "" ? "" : (selected?.label || "");
  }

  function renderMenu(instance, query = "") {
    const normalizedQuery = normalize(query);
    const entries = normalizedQuery
      ? instance.entries.filter((entry) => entry.normalizedLabel.includes(normalizedQuery))
      : instance.entries.slice();

    instance.filteredEntries = entries;
    instance.highlightIndex = entries.findIndex((entry) => entry.value === instance.select.value && !entry.disabled);
    if (instance.highlightIndex < 0) {
      instance.highlightIndex = entries.findIndex((entry) => !entry.disabled);
    }

    if (!entries.length) {
      instance.menu.innerHTML = '<div class="filterable-select-empty">No matching options</div>';
      return;
    }

    instance.menu.innerHTML = entries.map((entry, index) => `
      <button
        type="button"
        class="filterable-select-option${entry.disabled ? " is-disabled" : ""}${index === instance.highlightIndex ? " is-highlighted" : ""}${entry.value === instance.select.value ? " is-selected" : ""}"
        data-index="${index}"
        role="option"
        aria-selected="${entry.value === instance.select.value ? "true" : "false"}"
        ${entry.disabled ? "disabled" : ""}
      >${escapeHtml(entry.label || "")}</button>
    `).join("");

    instance.menu.querySelectorAll(".filterable-select-option").forEach((button) => {
      button.addEventListener("mousedown", (event) => {
        event.preventDefault();
      });
      button.addEventListener("click", () => {
        const idx = Number.parseInt(button.dataset.index || "", 10);
        const entry = instance.filteredEntries[idx];
        if (!entry || entry.disabled) return;
        selectEntry(instance, entry);
        closeMenu(instance);
      });
    });

    updateMenuPlacement(instance);
  }

  function moveHighlight(instance, direction) {
    if (!instance.filteredEntries.length) return;
    let index = instance.highlightIndex;
    for (let guard = 0; guard < instance.filteredEntries.length; guard += 1) {
      index = (index + direction + instance.filteredEntries.length) % instance.filteredEntries.length;
      if (!instance.filteredEntries[index].disabled) {
        instance.highlightIndex = index;
        renderMenu(instance, instance.input.value);
        const highlighted = instance.menu.querySelector(".filterable-select-option.is-highlighted");
        highlighted?.scrollIntoView({ block: "nearest" });
        return;
      }
    }
  }

  function bindInstance(instance) {
    instance.input.addEventListener("focus", () => openMenu(instance));
    instance.input.addEventListener("click", () => openMenu(instance));
    instance.input.addEventListener("input", () => {
      openMenu(instance);
      renderMenu(instance, instance.input.value);
    });
    instance.input.addEventListener("keydown", (event) => {
      if (event.key === "ArrowDown") {
        event.preventDefault();
        openMenu(instance);
        moveHighlight(instance, 1);
      } else if (event.key === "ArrowUp") {
        event.preventDefault();
        openMenu(instance);
        moveHighlight(instance, -1);
      } else if (event.key === "Enter") {
        if (!instance.wrapper.classList.contains("is-open")) {
          return;
        }
        event.preventDefault();
        const entry = instance.filteredEntries[instance.highlightIndex];
        if (entry && !entry.disabled) {
          selectEntry(instance, entry);
        } else {
          reconcileInput(instance);
        }
        closeMenu(instance);
      } else if (event.key === "Escape") {
        event.preventDefault();
        reconcileInput(instance);
        closeMenu(instance);
      }
    });
    instance.input.addEventListener("blur", () => {
      window.setTimeout(() => {
        if (!instance.wrapper.contains(document.activeElement)) {
          reconcileInput(instance);
          closeMenu(instance);
        }
      }, 120);
    });
    instance.toggle.addEventListener("click", () => {
      if (instance.wrapper.classList.contains("is-open")) {
        reconcileInput(instance);
        closeMenu(instance);
      } else {
        instance.input.focus();
        openMenu(instance);
      }
    });

    instance.select.addEventListener("change", () => syncElement(instance.select));

    document.addEventListener("click", (event) => {
      if (!instance.wrapper.contains(event.target)) {
        reconcileInput(instance);
        closeMenu(instance);
      }
    });

    const observer = new MutationObserver(() => syncElement(instance.select));
    observer.observe(instance.select, {
      childList: true,
      subtree: true,
      attributes: true,
      attributeFilter: ["disabled"]
    });
    instance.observer = observer;
  }

  function syncElement(select, config = {}) {
    if (!select) return;
    if (!instances.has(select)) {
      createInstance(select, config);
      return;
    }

    const instance = instances.get(select);
    instance.config = { ...instance.config, ...config };
    instance.entries = collectOptions(select);

    const selected = getSelectedEntry(instance.entries, select);
    instance.input.placeholder = instance.config.placeholder || select.options?.[0]?.textContent || "Type to filter options";
    instance.input.disabled = Boolean(select.disabled);
    instance.toggle.disabled = Boolean(select.disabled);
    instance.wrapper.classList.toggle("is-disabled", Boolean(select.disabled));
    instance.input.value = selected?.value === "" ? "" : (selected?.label || "");
    renderMenu(instance, instance.input.value);
  }

  function enhanceElement(select, config = {}) {
    if (!select || select.dataset.filterableSelect === "off") return;
    syncElement(select, config);
  }

  function setValue(select, value) {
    if (!select) return;
    select.value = value ?? "";
    syncElement(select);
  }

  document.addEventListener("DOMContentLoaded", () => {
    document.querySelectorAll("select[data-filterable-select]").forEach((select) => {
      enhanceElement(select);
    });
  });

  window.addEventListener("resize", () => {
    liveInstances.forEach((instance) => updateMenuPlacement(instance));
  });

  document.addEventListener("scroll", () => {
    liveInstances.forEach((instance) => updateMenuPlacement(instance));
  }, true);

  window.PensionsGoFilterableSelect = {
    enhanceElement,
    syncElement,
    setValue
  };
})();
