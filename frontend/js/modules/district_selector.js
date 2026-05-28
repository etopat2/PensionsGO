(function () {
  const instances = new Map();
  let districtsPromise = null;
  let districtsCache = [];

  function normalizeValue(value) {
    return String(value || "").trim().replace(/\s+/g, " ");
  }

  function normalizeKey(value) {
    return normalizeValue(value).toLowerCase();
  }

  function escapeHtml(value) {
    return String(value ?? "")
      .replace(/&/g, "&amp;")
      .replace(/</g, "&lt;")
      .replace(/>/g, "&gt;")
      .replace(/"/g, "&quot;")
      .replace(/'/g, "&#39;");
  }

  async function fetchDistricts() {
    if (districtsPromise) {
      return districtsPromise;
    }

    districtsPromise = fetch("../backend/api/get_political_districts.php", {
      credentials: "include",
      cache: "no-store"
    })
      .then(async (response) => {
        const data = await response.json().catch(() => ({}));
        if (!response.ok || !data.success || !Array.isArray(data.districts)) {
          throw new Error(data.message || "Unable to load districts.");
        }
        districtsCache = data.districts
          .map((row) => ({
            district: normalizeValue(row?.district || ""),
            region: normalizeValue(row?.region || "")
          }))
          .filter((row) => row.district !== "");
        return districtsCache;
      })
      .catch((error) => {
        console.error("Unable to initialize district selector:", error);
        districtsCache = [];
        return districtsCache;
      });

    return districtsPromise;
  }

  function closeAllDropdowns(exceptSource = null) {
    instances.forEach((instance, source) => {
      if (exceptSource && source === exceptSource) {
        return;
      }
      instance.dropdown.hidden = true;
      instance.wrapper.classList.remove("is-open");
      instance.wrapper.classList.remove("opens-upward");
      instance.highlightIndex = -1;
      resetDropdownPlacement(instance);
    });
  }

  function getSourceValue(source) {
    return normalizeValue(source?.value || "");
  }

  function normalizeOptionRows(items) {
    return (Array.isArray(items) ? items : [])
      .map((row) => {
        if (typeof row === "string") {
          return {
            district: normalizeValue(row),
            region: ""
          };
        }
        return {
          district: normalizeValue(row?.district || row?.value || row?.label || ""),
          region: normalizeValue(row?.region || row?.meta || "")
        };
      })
      .filter((row) => row.district !== "");
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

  function resetDropdownPlacement(instance) {
    if (!instance) return;
    instance.wrapper.style.zIndex = "";
    instance.dropdown.style.top = "";
    instance.dropdown.style.bottom = "";
    instance.dropdown.style.zIndex = "";
    instance.dropdownBody.style.maxHeight = "";
  }

  function updateDropdownPlacement(instance) {
    if (!instance || instance.dropdown.hidden) {
      resetDropdownPlacement(instance);
      return;
    }

    const boundary = findPlacementBoundary(instance.wrapper);
    const boundaryRect = boundary?.getBoundingClientRect?.();
    const wrapperRect = instance.wrapper.getBoundingClientRect();
    const defaultMaxHeight = Number.parseFloat(
      instance.dropdown.dataset.defaultMaxHeight || window.getComputedStyle(instance.dropdownBody).maxHeight
    ) || 260;
    const menuHeight = Math.min(
      instance.dropdown.scrollHeight || instance.dropdownBody.scrollHeight || defaultMaxHeight,
      defaultMaxHeight
    );
    const gutter = 10;
    const topLimit = Number.isFinite(boundaryRect?.top) ? boundaryRect.top : 0;
    const bottomLimit = Number.isFinite(boundaryRect?.bottom) ? boundaryRect.bottom : window.innerHeight;
    const spaceBelow = Math.max(0, bottomLimit - wrapperRect.bottom - gutter);
    const spaceAbove = Math.max(0, wrapperRect.top - topLimit - gutter);
    const openUpward = spaceBelow < menuHeight && spaceAbove >= spaceBelow;
    const availableSpace = Math.max(openUpward ? spaceAbove : spaceBelow, 48);
    const resolvedMaxHeight = Math.min(defaultMaxHeight, Math.max(48, Math.floor(availableSpace)));

    instance.dropdown.dataset.defaultMaxHeight = String(defaultMaxHeight);
    instance.wrapper.style.zIndex = "70";
    instance.dropdown.style.zIndex = "90";
    instance.dropdown.style.top = openUpward ? "auto" : "calc(100% + 0.35rem)";
    instance.dropdown.style.bottom = openUpward ? "calc(100% + 0.35rem)" : "auto";
    instance.dropdownBody.style.maxHeight = `${resolvedMaxHeight}px`;
    instance.wrapper.classList.toggle("opens-upward", openUpward);
  }

  function getBaseDistrictList(source) {
    const instance = instances.get(source);
    const currentValue = getSourceValue(source);
    const currentKey = normalizeKey(currentValue);
    const sourceList = instance?.customOptions?.length ? instance.customOptions : districtsCache;
    const list = Array.isArray(sourceList) ? sourceList.slice() : [];
    if (currentValue && !list.some((row) => normalizeKey(row.district) === currentKey)) {
      list.unshift({
        district: currentValue,
        region: instance?.currentValueLabel || "Current value"
      });
    }
    return list;
  }

  function getFilteredDistricts(instance) {
    const query = normalizeKey(instance.search.value);
    const baseList = getBaseDistrictList(instance.source);
    if (!query) {
      return baseList.slice(0, 40);
    }
    return baseList
      .filter((row) => {
        const district = normalizeKey(row.district);
        const region = normalizeKey(row.region);
        return district.includes(query) || region.includes(query);
      })
      .slice(0, 40);
  }

  function getExactMatch(instance, value) {
    const queryKey = normalizeKey(value);
    if (!queryKey) {
      return "";
    }
    const match = getBaseDistrictList(instance.source).find((row) => normalizeKey(row.district) === queryKey);
    return match ? rowValue(match) : "";
  }

  function rowValue(row) {
    return normalizeValue(row?.district || "");
  }

  function applySourceValue(instance, value) {
    const normalized = normalizeValue(value);
    instance.source.value = normalized;
    instance.search.value = normalized;
    instance.wrapper.classList.toggle("has-value", normalized !== "");
  }

  function syncDisabledState(instance) {
    const disabled = Boolean(instance.source.disabled);
    const readOnly = Boolean(instance.source.readOnly || disabled);
    instance.search.disabled = disabled;
    instance.search.readOnly = readOnly;
    instance.toggle.disabled = disabled;
    instance.wrapper.classList.toggle("is-disabled", disabled);
    instance.wrapper.classList.toggle("is-readonly", readOnly);
    if (readOnly) {
      closeAllDropdowns();
    }
  }

  function renderDropdown(instance) {
    const items = getFilteredDistricts(instance);
    instance.options = items;
    if (!items.length) {
      instance.dropdownBody.innerHTML = `<div class="district-select-empty">${escapeHtml(instance.emptyText || "No matching districts found.")}</div>`;
      instance.dropdown.hidden = false;
      instance.wrapper.classList.add("is-open");
      instance.highlightIndex = -1;
      updateDropdownPlacement(instance);
      return;
    }

    instance.dropdownBody.innerHTML = items
      .map((row, index) => `
        <button
          type="button"
          class="district-select-option${index === instance.highlightIndex ? " is-active" : ""}"
          data-district-option="${escapeHtml(rowValue(row))}"
          data-index="${index}"
        >
          <span>${escapeHtml(rowValue(row))}</span>
          ${row.region ? `<small>${escapeHtml(row.region)}</small>` : ""}
        </button>
      `)
      .join("");

    instance.dropdown.hidden = false;
    instance.wrapper.classList.add("is-open");
    updateDropdownPlacement(instance);

    instance.dropdownBody.querySelectorAll(".district-select-option").forEach((button) => {
      button.addEventListener("mousedown", (event) => {
        event.preventDefault();
      });
      button.addEventListener("click", () => {
        applySourceValue(instance, button.dataset.districtOption || "");
        closeAllDropdowns();
      });
    });
  }

  function finalizeInput(instance) {
    const typed = normalizeValue(instance.search.value);
    if (!typed) {
      applySourceValue(instance, "");
      closeAllDropdowns();
      return;
    }

    const exactMatch = getExactMatch(instance, typed);
    if (exactMatch) {
      applySourceValue(instance, exactMatch);
    } else {
      instance.search.value = getSourceValue(instance.source);
    }
    closeAllDropdowns();
  }

  function setHighlight(instance, nextIndex) {
    const maxIndex = instance.options.length - 1;
    if (maxIndex < 0) {
      instance.highlightIndex = -1;
      return;
    }
    if (nextIndex < 0) {
      instance.highlightIndex = maxIndex;
    } else if (nextIndex > maxIndex) {
      instance.highlightIndex = 0;
    } else {
      instance.highlightIndex = nextIndex;
    }
    renderDropdown(instance);
  }

  function wireInstance(instance) {
    instance.search.addEventListener("focus", () => {
      if (instance.search.disabled || instance.search.readOnly) {
        return;
      }
      closeAllDropdowns(instance.source);
      renderDropdown(instance);
    });

    instance.search.addEventListener("input", () => {
      if (instance.search.disabled || instance.search.readOnly) {
        return;
      }
      instance.highlightIndex = -1;
      renderDropdown(instance);
    });

    instance.search.addEventListener("keydown", (event) => {
      if (instance.search.disabled || instance.search.readOnly) {
        return;
      }

      if (event.key === "ArrowDown") {
        event.preventDefault();
        setHighlight(instance, instance.highlightIndex + 1);
        return;
      }

      if (event.key === "ArrowUp") {
        event.preventDefault();
        setHighlight(instance, instance.highlightIndex - 1);
        return;
      }

      if (event.key === "Enter") {
        if (!instance.dropdown.hidden && instance.highlightIndex >= 0 && instance.options[instance.highlightIndex]) {
          event.preventDefault();
          applySourceValue(instance, rowValue(instance.options[instance.highlightIndex]));
          closeAllDropdowns();
          return;
        }
        finalizeInput(instance);
        return;
      }

      if (event.key === "Escape") {
        event.preventDefault();
        instance.search.value = getSourceValue(instance.source);
        closeAllDropdowns();
      }
    });

    instance.search.addEventListener("blur", () => {
      window.setTimeout(() => finalizeInput(instance), 120);
    });

    instance.toggle.addEventListener("click", () => {
      if (instance.search.disabled || instance.search.readOnly) {
        return;
      }
      const isOpen = !instance.dropdown.hidden;
      closeAllDropdowns(instance.source);
      if (!isOpen) {
        instance.highlightIndex = -1;
        renderDropdown(instance);
        instance.search.focus();
      }
    });
  }

  async function enhanceElement(source, options = {}) {
    if (!source || instances.has(source)) {
      if (source && instances.has(source)) {
        syncElement(source);
      }
      return instances.get(source) || null;
    }

    const customOptions = normalizeOptionRows(options.items);
    if (!customOptions.length) {
      await fetchDistricts();
    }

    const wrapper = document.createElement("div");
    wrapper.className = "district-select";

    const search = document.createElement("input");
    search.type = "text";
    search.className = "district-select-input";
    search.placeholder = String(options.placeholder || "Type to search district");
    search.autocomplete = "off";
    search.spellcheck = false;

    const toggle = document.createElement("button");
    toggle.type = "button";
    toggle.className = "district-select-toggle";
    toggle.setAttribute("aria-label", String(options.toggleLabel || "Show district options"));
    toggle.innerHTML = '<span aria-hidden="true">&#9662;</span>';

    const dropdown = document.createElement("div");
    dropdown.className = "district-select-dropdown";
    dropdown.hidden = true;

    const dropdownBody = document.createElement("div");
    dropdownBody.className = "district-select-options";
    dropdown.appendChild(dropdownBody);

    source.classList.add("district-source-hidden");
    source.setAttribute("aria-hidden", "true");
    if (source.tagName === "INPUT") {
      source.dataset.originalType = source.type || "text";
      source.type = "hidden";
    } else {
      source.hidden = true;
    }

    source.insertAdjacentElement("afterend", wrapper);
    wrapper.appendChild(search);
    wrapper.appendChild(toggle);
    wrapper.appendChild(dropdown);

    const instance = {
      source,
      wrapper,
      search,
      toggle,
      dropdown,
      dropdownBody,
      options: [],
      highlightIndex: -1,
      customOptions,
      emptyText: String(options.noResultsText || "No matching districts found."),
      currentValueLabel: String(options.currentValueLabel || "Current value")
    };
    instances.set(source, instance);

    wireInstance(instance);
    applySourceValue(instance, source.value || "");
    syncDisabledState(instance);
    return instance;
  }

  function syncElement(source) {
    const instance = instances.get(source);
    if (!instance) {
      return;
    }
    applySourceValue(instance, source.value || "");
    syncDisabledState(instance);
  }

  function setValue(source, value) {
    if (!source) {
      return;
    }
    source.value = normalizeValue(value);
    syncElement(source);
  }

  function setReadOnly(source, readOnly) {
    if (!source) {
      return;
    }
    source.readOnly = Boolean(readOnly);
    syncElement(source);
  }

  document.addEventListener("click", (event) => {
    const target = event.target;
    let insideSelector = false;
    instances.forEach((instance) => {
      if (instance.wrapper.contains(target)) {
        insideSelector = true;
      }
    });
    if (!insideSelector) {
      closeAllDropdowns();
    }
  });

  window.addEventListener("resize", () => {
    instances.forEach((instance) => updateDropdownPlacement(instance));
  });

  document.addEventListener("scroll", () => {
    instances.forEach((instance) => updateDropdownPlacement(instance));
  }, true);

  window.PensionsGoDistrictSelector = {
    enhanceElement,
    syncElement,
    setValue,
    setReadOnly,
    async getDistricts() {
      await fetchDistricts();
      return districtsCache.slice();
    }
  };
})();
