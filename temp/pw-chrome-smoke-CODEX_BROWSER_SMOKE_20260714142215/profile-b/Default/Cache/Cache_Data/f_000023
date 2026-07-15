document.addEventListener('DOMContentLoaded', () => {
  const form = document.getElementById('benefitsForm');
  if (!form) return;

  const elements = {
    form,
    resultsSection: document.getElementById('results'),
    retirementType: document.getElementById('retirementType'),
    dobGroup: document.getElementById('dobGroup'),
    birthDate: document.getElementById('birthDate'),
    enlistmentDate: document.getElementById('enlistmentDate'),
    retirementDate: document.getElementById('retirementDate'),
    monthlySalary: document.getElementById('monthlySalary'),
    lengthOfService: document.getElementById('lengthOfService'),
    serviceRoundingNote: document.getElementById('serviceRoundingNote'),
    annualSalary: document.getElementById('annualSalary'),
    expectedGratuity: document.getElementById('expectedGratuity'),
    expectedMonthlyPension: document.getElementById('expectedMonthlyPension'),
    expectedFullPension: document.getElementById('expectedFullPension'),
    eligibilityNote: document.getElementById('eligibilityNote'),
    estimateTitle: document.getElementById('estimateTitle'),
    formulaNote: document.getElementById('formulaNote'),
    scenarioAdvice: document.getElementById('scenarioAdvice'),
    serviceBand: document.getElementById('serviceBand'),
    ageAtRetirement: document.getElementById('ageAtRetirement'),
    retirementWindow: document.getElementById('retirementWindow'),
    dateOn15Years: document.getElementById('dateOn15Years'),
    printEstimateBtn: document.getElementById('printEstimateBtn'),
    copyEstimateBtn: document.getElementById('copyEstimateBtn')
  };

  prepareSalaryField(elements.monthlySalary);
  bindEvents(elements);
  updateBirthDateRequirement(elements);
  resetDerivedSummary(elements);
});

function bindEvents(elements) {
  elements.retirementType?.addEventListener('change', () => {
    updateBirthDateRequirement(elements);
    updateDerivedSummary(elements);
  });

  [elements.birthDate, elements.enlistmentDate, elements.retirementDate, elements.monthlySalary].forEach((field) => {
    field?.addEventListener('input', () => updateDerivedSummary(elements));
    field?.addEventListener('change', () => updateDerivedSummary(elements));
  });

  elements.form.addEventListener('submit', (event) => {
    event.preventDefault();
    const payload = collectFormValues(elements);
    const validationError = validateCalculatorPayload(payload);
    if (validationError) {
      notifyUser(validationError);
      return;
    }

    const results = calculateBenefits(payload);
    renderCalculationResults(elements, payload, results);
  });

  elements.form.addEventListener('reset', () => {
    window.setTimeout(() => {
      updateBirthDateRequirement(elements);
      clearResults(elements);
      resetDerivedSummary(elements);
    }, 0);
  });

  elements.printEstimateBtn?.addEventListener('click', () => {
    if (elements.resultsSection?.classList.contains('hidden')) {
      notifyUser('Calculate an estimate before printing.');
      return;
    }
    window.print();
  });

  elements.copyEstimateBtn?.addEventListener('click', async () => {
    if (elements.resultsSection?.classList.contains('hidden')) {
      notifyUser('Calculate an estimate before copying the summary.');
      return;
    }
    const summary = buildCopySummary(elements);
    try {
      await navigator.clipboard.writeText(summary);
      notifyUser('Estimate summary copied.', 'info');
    } catch (_error) {
      notifyUser('Unable to copy the summary from this browser.', 'error');
    }
  });
}

function prepareSalaryField(input) {
  if (!input) return;
  input.setAttribute('type', 'text');
  input.setAttribute('inputmode', 'numeric');
  input.setAttribute('autocomplete', 'off');

  if (!input.parentElement?.classList.contains('salary-wrapper')) {
    const wrapper = document.createElement('div');
    wrapper.className = 'salary-wrapper';
    wrapper.style.position = 'relative';

    const prefix = document.createElement('span');
    prefix.className = 'salary-prefix';
    prefix.textContent = getCurrencyLabel();
    prefix.style.position = 'absolute';
    prefix.style.left = '12px';
    prefix.style.top = '50%';
    prefix.style.transform = 'translateY(-50%)';
    prefix.style.pointerEvents = 'none';
    prefix.style.color = 'var(--muted-color)';

    input.parentNode.insertBefore(wrapper, input);
    wrapper.appendChild(input);
    wrapper.appendChild(prefix);
    input.style.paddingLeft = '52px';
  }

  input.addEventListener('input', () => {
    const raw = input.value.replace(/[^0-9.]/g, '');
    if (!raw) {
      input.value = '';
      return;
    }

    const parts = raw.split('.');
    const integerPart = parts.shift()?.replace(/^0+(?=\d)/, '') || '0';
    const decimals = parts.length ? parts.join('').slice(0, 2) : '';
    input.value = integerPart.replace(/\B(?=(\d{3})+(?!\d))/g, ',') + (decimals ? `.${decimals}` : '');
  });
}

function updateBirthDateRequirement(elements) {
  const hasRetirementType = Boolean(String(elements.retirementType?.value || '').trim());
  elements.dobGroup?.classList.toggle('hidden', !hasRetirementType);
  if (elements.birthDate) {
    elements.birthDate.required = false;
  }
}

function collectFormValues(elements) {
  const retirementTypes = getRetirementTypesApi();
  return {
    retirementType: retirementTypes.normalizeValue(String(elements.retirementType?.value || '').trim()),
    birthDate: String(elements.birthDate?.value || '').trim(),
    enlistmentDate: String(elements.enlistmentDate?.value || '').trim(),
    retirementDate: String(elements.retirementDate?.value || '').trim(),
    monthlySalary: parseCurrencyInput(elements.monthlySalary?.value || '')
  };
}

function validateCalculatorPayload(payload) {
  if (!payload.retirementType) return 'Please select the type of retirement.';
  if (!payload.enlistmentDate || !payload.retirementDate || payload.monthlySalary <= 0) {
    return 'Provide the enlistment date, retirement date, and a valid monthly salary.';
  }
  const retirementProfile = getRetirementTypesApi().validateRetirementProfile(payload);
  if (retirementProfile.errors?.length) {
    return retirementProfile.primaryMessage || 'The retirement profile does not satisfy the configured policy checks.';
  }
  return '';
}

function calculateBenefits(payload) {
  const retirementTypes = getRetirementTypesApi();
  const snapshot = retirementTypes.calculateBenefitSnapshot(payload);
  const servicePeriod = snapshot.servicePeriod || calculateServicePeriod(payload.enlistmentDate, payload.retirementDate);
  const months = snapshot.lengthOfService || servicePeriod.roundedMonths;
  const annualSalary = snapshot.annualSalary || (payload.monthlySalary * 12);
  const reducedPension = snapshot.reducedPension || 0;
  const fullPension = snapshot.fullPension || 0;
  const gratuity = snapshot.gratuity || 0;
  const ageAtRetirement = payload.birthDate ? retirementTypes.calculateAgeAtRetirement(payload.birthDate, payload.retirementDate) : null;
  const dateOn15Years = addYears(payload.retirementDate, 15);
  const retirementPolicy = retirementTypes.validateRetirementProfile(payload);
  let note = '';
  let noteClass = 'red';
  let headline = 'Estimate Results';
  let advice = 'Review the result against source records before moving the file into approval or registry processes.';

  switch (payload.retirementType) {
    case 'mandatory':
    case 'voluntary':
    case 'oldAge': {
      note = `Calculated using the ${labelForRetirementType(payload.retirementType)} formula family.`;
      noteClass = 'blue';
      break;
    }

    case 'early':
    case 'aor': {
      if (months >= 120) {
        note = `${labelForRetirementType(payload.retirementType)} qualifies for full Mandatory Retirement-style benefits because service is at least 10 years.`;
        noteClass = 'blue';
      } else {
        note = `${labelForRetirementType(payload.retirementType)} attracts no benefits when service is below 10 years.`;
        advice = 'This case should not proceed with gratuity or pension values until the service threshold is met or the retirement mode changes.';
      }
      break;
    }

    case 'death':
    case 'medical': {
      if (months >= 120) {
        note = `Eligible for gratuity, reduced pension, and full pension under ${labelForRetirementType(payload.retirementType)}.`;
        noteClass = 'blue';
      } else {
        note = `Eligible for gratuity only under ${labelForRetirementType(payload.retirementType)} because service is below 10 years.`;
        noteClass = 'yellow';
      }
      headline = 'Survivor / Medical Benefit Estimate';
      advice = 'Use this estimate together with supporting documents such as death certificate, medical reports, and establishment records.';
      break;
    }

    case 'marriage': {
      note = 'Discharge (Marriage) attracts marriage gratuity only. Reduced and full pension do not apply in this category.';
      noteClass = 'yellow';
      headline = 'Discharge (Marriage) Benefit Estimate';
      advice = 'Use this outcome for marriage-gratuity review and validate that the authority and conditions for discharge on marriage grounds are fully documented.';
      break;
    }

    case 'cbe':
    case 'ube':
    case 'public': {
      if (months >= 120) {
        note = `${labelForRetirementType(payload.retirementType)} has been treated as Mandatory Retirement because service is at least 10 years.`;
        noteClass = 'blue';
      } else {
        note = `${labelForRetirementType(payload.retirementType)} attracts short-service gratuity only because service is below 10 years.`;
        noteClass = 'yellow';
      }
      headline = 'Short-Service / Full-Benefit Estimate';
      advice = 'Check whether the case qualifies for short-service gratuity only, or transitions to full Mandatory Retirement-style benefits once the 10-year threshold is met.';
      break;
    }

    case 'contract':
    case 'tx': {
      note = `${labelForRetirementType(payload.retirementType)} is calculated using the contract gratuity rule only, without monthly or full pension.`;
      noteClass = 'yellow';
      headline = 'Limited Benefit Estimate';
      advice = 'This category does not normally result in monthly pension. Verify supporting authority and service record before final decision.';
      break;
    }

    case 'abolition': {
      note = 'Abolition of Office uses the special 25% abolition formula for gratuity, reduced pension, and full pension.';
      noteClass = 'blue';
      headline = 'Abolition of Office Estimate';
      advice = 'Confirm that the case is legally recorded as abolition of office before relying on this formula.';
      break;
    }

    default: {
      note = 'Benefits have been estimated using the available retirement profile.';
      noteClass = months >= 120 ? 'blue' : 'yellow';
    }
  }

  if (retirementPolicy.warnings?.length) {
    note = `${note} ${retirementPolicy.warnings[0]}`.trim();
    if (noteClass === 'red') {
      noteClass = 'yellow';
    }
  }

  return {
    months,
    servicePeriod,
    annualSalary,
    gratuity,
    reducedPension,
    fullPension,
    note,
    noteClass,
    headline,
    advice,
    ageAtRetirement,
    dateOn15Years,
    serviceBand: determineServiceBand(months),
    retirementWindow: describeRetirementWindow(payload.retirementDate),
    periodTo15Years: describePeriodTo15Years(dateOn15Years),
    periodFrom15Years: describePeriodFrom15Years(dateOn15Years)
  };
}

function renderCalculationResults(elements, payload, results) {
  const currency = getCurrencyLabel();
  if (elements.estimateTitle) elements.estimateTitle.textContent = results.headline;
  if (elements.formulaNote) {
    elements.formulaNote.textContent = `${labelForRetirementType(payload.retirementType)} estimate based on service of ${formatServiceDuration(results.servicePeriod)}.`;
  }
  if (elements.lengthOfService) {
    const totalMonths = results.servicePeriod?.totalMonths ?? results.months ?? 0;
    const days = results.servicePeriod?.days ?? 0;
    const monthLabel = `${totalMonths} month${totalMonths === 1 ? '' : 's'}`;
    const dayLabel = `${days} day${days === 1 ? '' : 's'}`;
    elements.lengthOfService.textContent = `${monthLabel}, ${dayLabel}`;
  }
  if (elements.serviceRoundingNote) {
    const rounded = results.months ?? 0;
    elements.serviceRoundingNote.textContent = `Rounded to ${rounded} months for benefit computation.`;
  }
  if (elements.annualSalary) elements.annualSalary.textContent = formatCurrency(results.annualSalary, currency);
  if (elements.expectedGratuity) elements.expectedGratuity.textContent = formatCurrency(results.gratuity, currency);
  if (elements.expectedMonthlyPension) elements.expectedMonthlyPension.textContent = formatCurrency(results.reducedPension, currency);
  if (elements.expectedFullPension) elements.expectedFullPension.textContent = formatCurrency(results.fullPension, currency);
  if (elements.serviceBand) elements.serviceBand.textContent = results.serviceBand;
  if (elements.ageAtRetirement) {
    elements.ageAtRetirement.textContent = results.ageAtRetirement == null
      ? (payload.birthDate ? 'Unavailable' : 'Optional')
      : `${results.ageAtRetirement} years`;
  }
  if (elements.retirementWindow) elements.retirementWindow.textContent = results.retirementWindow;
  if (elements.dateOn15Years) elements.dateOn15Years.textContent = formatLongDate(results.dateOn15Years);
  if (elements.scenarioAdvice) elements.scenarioAdvice.textContent = results.advice;
  if (elements.eligibilityNote) {
    elements.eligibilityNote.innerHTML = `<div class="eligibility-alert ${results.noteClass}">${escapeHtml(results.note)}</div>`;
  }
  elements.resultsSection?.classList.remove('hidden');
  elements.resultsSection?.scrollIntoView({ behavior: 'smooth', block: 'start' });
}

function clearResults(elements) {
  elements.resultsSection?.classList.add('hidden');
  if (elements.eligibilityNote) elements.eligibilityNote.innerHTML = '';
  if (elements.scenarioAdvice) elements.scenarioAdvice.textContent = '';
  if (elements.serviceRoundingNote) elements.serviceRoundingNote.textContent = '';
}

function updateDerivedSummary(elements) {
  const payload = collectFormValues(elements);
  const retirementTypes = getRetirementTypesApi();
  const period = payload.enlistmentDate && payload.retirementDate && new Date(payload.retirementDate) > new Date(payload.enlistmentDate)
    ? retirementTypes.calculateServicePeriod(payload.enlistmentDate, payload.retirementDate)
    : { months: 0, days: 0, roundedMonths: 0 };
  const months = period.roundedMonths;
  if (elements.serviceBand) elements.serviceBand.textContent = months ? determineServiceBand(months) : 'Awaiting data';
  if (elements.ageAtRetirement) {
    if (payload.birthDate && payload.retirementDate) {
      const age = retirementTypes.calculateAgeAtRetirement(payload.birthDate, payload.retirementDate);
      elements.ageAtRetirement.textContent = age == null ? 'Unavailable' : `${age} years`;
    } else {
      elements.ageAtRetirement.textContent = payload.retirementType ? 'Optional' : '-';
    }
  }
  if (elements.retirementWindow) {
    elements.retirementWindow.textContent = payload.retirementDate ? describeRetirementWindow(payload.retirementDate) : '-';
  }
}

function resetDerivedSummary(elements) {
  if (elements.serviceBand) elements.serviceBand.textContent = 'Awaiting data';
  if (elements.ageAtRetirement) elements.ageAtRetirement.textContent = '-';
  if (elements.retirementWindow) elements.retirementWindow.textContent = '-';
  if (elements.dateOn15Years) elements.dateOn15Years.textContent = '-';
  if (elements.estimateTitle) elements.estimateTitle.textContent = 'Estimate Results';
  if (elements.formulaNote) elements.formulaNote.textContent = 'Computed from the currently supplied dates and salary.';
  if (elements.serviceRoundingNote) elements.serviceRoundingNote.textContent = '';
}

function determineServiceBand(months) {
  if (months >= 240) return 'Long Service';
  if (months >= 120) return 'Pension Eligible';
  if (months > 0) return 'Short Service';
  return 'Awaiting data';
}

function describeRetirementWindow(dateValue) {
  const date = new Date(dateValue);
  const month = date.toLocaleString('en-GB', { month: 'long' });
  const financialYear = buildFinancialYearLabel(dateValue);
  const quarter = determineFinancialYearQuarter(dateValue);
  return `${month} • ${quarter} • ${financialYear}`;
}

function describePeriodTo15Years(dateValue) {
  const today = startOfDay(new Date());
  const milestone = startOfDay(new Date(dateValue));
  if (today >= milestone) return '15 years reached';
  return formatDuration(today, milestone);
}

function describePeriodFrom15Years(dateValue) {
  const today = startOfDay(new Date());
  const milestone = startOfDay(new Date(dateValue));
  if (today <= milestone) return 'Still within first 15 years';
  return formatDuration(milestone, today);
}

function buildCopySummary(elements) {
  return [
    elements.estimateTitle?.textContent || 'Benefits Estimate',
    `Length of Service: ${elements.lengthOfService?.textContent || '-'}`,
    `Annual Salary: ${elements.annualSalary?.textContent || '-'}`,
    `Expected Gratuity: ${elements.expectedGratuity?.textContent || '-'}`,
    `Reduced Monthly Pension: ${elements.expectedMonthlyPension?.textContent || '-'}`,
    `Expected Full Pension: ${elements.expectedFullPension?.textContent || '-'}`,
    `Date On 15 Years: ${elements.dateOn15Years?.textContent || '-'}`,
    stripHtml(elements.eligibilityNote?.textContent || ''),
    elements.scenarioAdvice?.textContent || ''
  ].filter(Boolean).join('\n');
}

function calculateServicePeriod(enlistmentDate, retirementDate) {
  if (!enlistmentDate || !retirementDate) {
    return { years: 0, months: 0, days: 0, totalMonths: 0, roundedMonths: 0 };
  }

  const start = new Date(enlistmentDate);
  const end = new Date(retirementDate);
  if (Number.isNaN(start.getTime()) || Number.isNaN(end.getTime()) || end <= start) {
    return { years: 0, months: 0, days: 0, totalMonths: 0, roundedMonths: 0 };
  }

  let years = end.getFullYear() - start.getFullYear();
  let months = end.getMonth() - start.getMonth();
  let days = end.getDate() - start.getDate();

  if (days < 0) {
    const prevMonthEnd = new Date(end.getFullYear(), end.getMonth(), 0);
    days += prevMonthEnd.getDate();
    months -= 1;
  }

  if (months < 0) {
    months += 12;
    years -= 1;
  }

  const totalMonths = Math.max(0, (years * 12) + months);
  const safeDays = Math.max(0, days);
  const roundedMonths = safeDays >= 15 ? totalMonths + 1 : totalMonths;
  return { years, months, days: safeDays, totalMonths, roundedMonths };
}

function calculateAgeAtRetirement(birthDate, retirementDate) {
  const dob = new Date(birthDate);
  const retirement = new Date(retirementDate);
  let age = retirement.getFullYear() - dob.getFullYear();
  const monthDiff = retirement.getMonth() - dob.getMonth();
  if (monthDiff < 0 || (monthDiff === 0 && retirement.getDate() < dob.getDate())) {
    age -= 1;
  }
  return age;
}

function addYears(dateValue, years) {
  const date = new Date(dateValue);
  date.setFullYear(date.getFullYear() + years);
  return date;
}

function formatCurrency(amount, currencyLabel = getCurrencyLabel()) {
  const value = Number(amount || 0);
  return `${currencyLabel} ${value.toLocaleString('en-UG', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
}

function parseCurrencyInput(value) {
  const normalized = String(value || '').replace(/,/g, '');
  const parsed = Number(normalized);
  return Number.isFinite(parsed) ? parsed : 0;
}

function formatLongDate(dateValue) {
  const date = new Date(dateValue);
  return date.toLocaleDateString('en-GB', {
    day: '2-digit',
    month: 'short',
    year: 'numeric'
  });
}

function formatServiceDuration(period) {
  const years = Number(period?.years ?? 0);
  const months = Number(period?.months ?? 0);
  const days = Number(period?.days ?? 0);
  const safeYears = Number.isFinite(years) ? Math.max(0, years) : 0;
  const safeMonths = Number.isFinite(months) ? Math.max(0, months) : 0;
  const safeDays = Number.isFinite(days) ? Math.max(0, days) : 0;
  const yearLabel = `${safeYears} year${safeYears === 1 ? '' : 's'}`;
  const monthLabel = `${safeMonths} month${safeMonths === 1 ? '' : 's'}`;
  const dayLabel = `${safeDays} day${safeDays === 1 ? '' : 's'}`;
  return `${yearLabel}, ${monthLabel} and ${dayLabel}`;
}

function buildFinancialYearLabel(dateValue) {
  const date = new Date(dateValue);
  const year = date.getFullYear();
  const startYear = date.getMonth() >= 6 ? year : year - 1;
  return `FY ${startYear}/${startYear + 1}`;
}

function determineFinancialYearQuarter(dateValue) {
  const date = new Date(dateValue);
  const month = date.getMonth() + 1;
  if (month >= 7 && month <= 9) return 'Q1';
  if (month >= 10 && month <= 12) return 'Q2';
  if (month >= 1 && month <= 3) return 'Q3';
  return 'Q4';
}

function formatDuration(startDate, endDate) {
  const start = new Date(startDate);
  const end = new Date(endDate);
  let years = end.getFullYear() - start.getFullYear();
  let months = end.getMonth() - start.getMonth();
  let days = end.getDate() - start.getDate();

  if (days < 0) {
    months -= 1;
    const previousMonthDays = new Date(end.getFullYear(), end.getMonth(), 0).getDate();
    days += previousMonthDays;
  }
  if (months < 0) {
    years -= 1;
    months += 12;
  }

  const parts = [];
  if (years > 0) parts.push(`${years} Year${years === 1 ? '' : 's'}`);
  if (months > 0) parts.push(`${months} Month${months === 1 ? '' : 's'}`);
  if (days > 0 || !parts.length) parts.push(`${days} Day${days === 1 ? '' : 's'}`);
  return parts.join(', ');
}

function startOfDay(date) {
  const copy = new Date(date);
  copy.setHours(0, 0, 0, 0);
  return copy;
}

function labelForRetirementType(type) {
  return getRetirementTypesApi().getLabel(type) || 'Retirement';
}

function getRetirementTypesApi() {
  return window.PensionsGoRetirementTypes || {
    normalizeValue: (value) => String(value || '').trim(),
    getLabel: (value) => String(value || '').trim(),
    validateRetirementProfile: () => ({
      valid: true,
      errors: [],
      warnings: [],
      primaryMessage: '',
      status: 'neutral'
    }),
    calculateServicePeriod,
    calculateAgeAtRetirement,
    calculateBenefitSnapshot: (payload) => {
      const period = calculateServicePeriod(payload.enlistmentDate, payload.retirementDate);
      const annualSalary = Number(payload.monthlySalary || 0) * 12;
      return {
        servicePeriod: period,
        lengthOfService: period.roundedMonths,
        annualSalary,
        gratuity: 0,
        reducedPension: 0,
        fullPension: 0
      };
    }
  };
}

function getCurrencyLabel() {
  if (window.AppSettingsManager?.get) {
    return window.AppSettingsManager.get('currency', 'UGX') || 'UGX';
  }
  try {
    const cached = JSON.parse(localStorage.getItem('appSettings') || '{}');
    return cached.currency || 'UGX';
  } catch (_error) {
    return 'UGX';
  }
}

function notifyUser(message, type = 'error') {
  const text = String(message || '').trim();
  if (!text) return;
  if (typeof window.appAlert === 'function') {
    window.appAlert(text, {
      title: type === 'error' ? 'Benefits Calculator' : 'Information',
      type
    });
    return;
  }
  window.alert(text);
}

function escapeHtml(value) {
  return String(value || '')
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#39;');
}

function stripHtml(value) {
  return String(value || '').replace(/\s+/g, ' ').trim();
}
