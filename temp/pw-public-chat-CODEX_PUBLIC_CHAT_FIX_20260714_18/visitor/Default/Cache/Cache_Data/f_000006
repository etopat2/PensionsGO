(function (global) {
  const definitions = [
    { key: "mandatory", label: "Mandatory Retirement", aliases: ["mandatory", "mandatory retirement"] },
    { key: "early", label: "Early Retirement", aliases: ["early", "early retirement"] },
    { key: "death", label: "Death", aliases: ["death", "retirement by death"] },
    { key: "aor", label: "Discharge (A.O.R)", aliases: ["aor", "at own request", "discharge aor", "attainment of required age", "age of retirement"] },
    { key: "medical", label: "Discharge (Medical)", aliases: ["medical", "medical grounds", "medical retirement", "discharge medical"] },
    { key: "marriage", label: "Discharge (Marriage)", aliases: ["marriage", "marriage grounds", "discharge marriage"] },
    { key: "cbe", label: "Discharge (C.B.E)", aliases: ["cbe", "discharge cbe", "compulsory board exit", "discharge"] },
    { key: "ube", label: "Discharge (U.B.E)", aliases: ["ube", "discharge ube"] },
    { key: "public", label: "Discharge (Public Interest)", aliases: ["public", "public interest", "discharge public interest", "discharge (public interest)"] },
    { key: "contract", label: "End of Contract", aliases: ["contract", "contract expired", "contract expiry", "contract end", "end of contract"] },
    { key: "tx", label: "Discharge (T.X)", aliases: ["tx", "t.x", "time expired", "discharge tx"] },
    { key: "voluntary", label: "Voluntary", aliases: ["voluntary", "voluntary retirement"] },
    { key: "oldAge", label: "Old Age", aliases: ["oldage", "old age"] },
    { key: "abolition", label: "Abolition of Office", aliases: ["abolition", "abolition of office"] }
  ];

  const labelByKey = new Map();
  const aliasToKey = new Map();

  function normalizeToken(value) {
    return String(value || "")
      .trim()
      .toLowerCase()
      .replace(/[^a-z0-9]+/g, "");
  }

  definitions.forEach((entry) => {
    labelByKey.set(entry.key, entry.label);
    aliasToKey.set(normalizeToken(entry.key), entry.key);
    entry.aliases.forEach((alias) => aliasToKey.set(normalizeToken(alias), entry.key));
  });

  function normalizeValue(value) {
    const token = normalizeToken(value);
    if (!token) return "";
    return aliasToKey.get(token) || String(value || "").trim();
  }

  function getLabel(value) {
    const normalized = normalizeValue(value);
    if (!normalized) return "";
    return labelByKey.get(normalized) || String(value || "").trim();
  }

  function getDefinitions() {
    return definitions.map((entry) => ({ ...entry }));
  }

  function normalizePayType(value) {
    const raw = String(value || "").trim().toLowerCase();
    if (!raw) return "Pensioner";
    const compact = raw.replace(/[^a-z0-9]/g, "");
    if (["oneoffpayment", "oneoff", "oneoffpayout", "oneoffpay", "gratuityonly"].includes(compact)) {
      return "One-off Payment";
    }
    return "Pensioner";
  }

  function isDeathType(value) {
    return normalizeValue(value) === "death";
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
      const previousMonthDays = new Date(end.getFullYear(), end.getMonth(), 0).getDate();
      days += previousMonthDays;
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
    if (!birthDate || !retirementDate) return null;

    const dob = new Date(birthDate);
    const retirement = new Date(retirementDate);
    if (Number.isNaN(dob.getTime()) || Number.isNaN(retirement.getTime()) || retirement < dob) {
      return null;
    }

    let age = retirement.getFullYear() - dob.getFullYear();
    const monthDiff = retirement.getMonth() - dob.getMonth();
    if (monthDiff < 0 || (monthDiff === 0 && retirement.getDate() < dob.getDate())) {
      age -= 1;
    }

    return Math.max(0, age);
  }

  function formatServiceLabel(months) {
    if (months == null) return "service duration unavailable";
    const safeMonths = Math.max(0, Number(months || 0));
    const years = Math.floor(safeMonths / 12);
    const remainingMonths = safeMonths % 12;
    const yearLabel = `${years} year${years === 1 ? "" : "s"}`;
    const monthLabel = `${remainingMonths} month${remainingMonths === 1 ? "" : "s"}`;
    return `${yearLabel}, ${monthLabel}`;
  }

  function parseDateValue(value) {
    const raw = String(value || "").trim();
    if (!raw) return null;
    const parsed = new Date(raw);
    return Number.isNaN(parsed.getTime()) ? null : parsed;
  }

  function validateRetirementProfile(payload = {}) {
    const retirementTypeKey = normalizeValue(payload.retirementType);
    const retirementTypeLabel = getLabel(retirementTypeKey);
    const birthDate = String(payload.birthDate || "").trim();
    const enlistmentDate = String(payload.enlistmentDate || "").trim();
    const retirementDate = String(payload.retirementDate || "").trim();
    const errors = [];
    const warnings = [];
    const mandatoryRetirementRuleLabel = "age 60 at retirement, or age 55 when the retirement year is 2000 or earlier";

    const birth = parseDateValue(birthDate);
    const enlistment = parseDateValue(enlistmentDate);
    const retirement = parseDateValue(retirementDate);

    if (birthDate && !birth) errors.push("Date of birth is invalid.");
    if (enlistmentDate && !enlistment) errors.push("Date of enlistment is invalid.");
    if (retirementDate && !retirement) errors.push("Date of retirement is invalid.");

    if (birth && enlistment && enlistment <= birth) {
      errors.push("Date of enlistment must be later than date of birth.");
    }
    if (birth && retirement && retirement <= birth) {
      errors.push("Retirement date must be later than date of birth.");
    }
    if (enlistment && retirement && retirement <= enlistment) {
      errors.push("Retirement date must be later than the enlistment date.");
    }

    const servicePeriod = enlistment && retirement && retirement > enlistment
      ? calculateServicePeriod(enlistmentDate, retirementDate)
      : null;
    const lengthOfServiceMonths = servicePeriod ? Number(servicePeriod.roundedMonths || 0) : null;
    const ageAtRetirement = birth && retirement && retirement > birth
      ? calculateAgeAtRetirement(birthDate, retirementDate)
      : null;
    const qualifiesMandatoryRetirementAge = (age, retirementMoment) => {
      if (age === 60) return true;
      if (age === 55 && retirementMoment instanceof Date && !Number.isNaN(retirementMoment.getTime())) {
        return retirementMoment.getFullYear() <= 2000;
      }
      return false;
    };

    if (retirementTypeKey) {
      switch (retirementTypeKey) {
        case "mandatory":
          if (!birthDate) {
            errors.push(`${retirementTypeLabel} requires date of birth so the system can confirm ${mandatoryRetirementRuleLabel}.`);
          } else if (!qualifiesMandatoryRetirementAge(ageAtRetirement, retirement)) {
            const ageText = ageAtRetirement == null ? "an invalid age profile" : `age ${ageAtRetirement}`;
            const retirementYearText = retirement instanceof Date && !Number.isNaN(retirement.getTime())
              ? ` in ${retirement.getFullYear()}`
              : " with no valid retirement year";
            errors.push(`${retirementTypeLabel} requires ${mandatoryRetirementRuleLabel}; this profile evaluates to ${ageText}${retirementYearText}.`);
          }
          break;

        case "oldAge":
          if (!birthDate) {
            errors.push(`${retirementTypeLabel} requires date of birth so the system can confirm retirement at age 60 or above.`);
          } else if (ageAtRetirement == null || ageAtRetirement < 60) {
            const ageText = ageAtRetirement == null ? "an invalid age profile" : `age ${ageAtRetirement}`;
            errors.push(`${retirementTypeLabel} requires age 60 or above at retirement; this profile evaluates to ${ageText}.`);
          }
          break;

        case "early":
        case "aor":
          if (!enlistmentDate || !retirementDate) {
            errors.push(`${retirementTypeLabel} requires enlistment and retirement dates so the qualifying service can be confirmed.`);
            break;
          }
          if (lengthOfServiceMonths == null) {
            errors.push("Retirement date must be later than the enlistment date.");
            break;
          }
          if (lengthOfServiceMonths >= 240) {
            break;
          }
          if (!birthDate) {
            errors.push(`${retirementTypeLabel} requires either 20 years of service, or at least 10 years of service with age 45 years or above. Provide date of birth to validate the age-based route.`);
            break;
          }
          if (!(lengthOfServiceMonths >= 120 && ageAtRetirement != null && ageAtRetirement >= 45)) {
            const ageText = ageAtRetirement == null ? "age unavailable" : `age ${ageAtRetirement}`;
            errors.push(`${retirementTypeLabel} requires either 20 years of service, or at least 10 years of service with age 45 years or above at retirement. This profile evaluates to ${formatServiceLabel(lengthOfServiceMonths)} and ${ageText}.`);
          }
          break;

        case "marriage":
          if (!birthDate) {
            errors.push(`${retirementTypeLabel} requires date of birth so the system can confirm the below-45 age policy.`);
          } else if (ageAtRetirement == null || ageAtRetirement >= 45) {
            const ageText = ageAtRetirement == null ? "an invalid age profile" : `age ${ageAtRetirement}`;
            errors.push(`${retirementTypeLabel} should only be captured below age 45; this profile evaluates to ${ageText}.`);
          }
          if (lengthOfServiceMonths != null && lengthOfServiceMonths >= 240) {
            warnings.push(`${retirementTypeLabel} is unusual at ${formatServiceLabel(lengthOfServiceMonths)} of service. Reconfirm the retirement authority.`);
          }
          break;

        default:
          break;
      }
    }

    const uniqueErrors = Array.from(new Set(errors.filter(Boolean)));
    const uniqueWarnings = Array.from(new Set(warnings.filter(Boolean)));
    const primaryMessage = uniqueErrors[0] || uniqueWarnings[0] || "";
    const hasProfileData = Boolean(retirementTypeKey || birthDate || enlistmentDate || retirementDate);
    const status = uniqueErrors.length
      ? "error"
      : uniqueWarnings.length
        ? "warning"
        : hasProfileData && retirementTypeKey
          ? "valid"
          : "neutral";

    return {
      valid: uniqueErrors.length === 0,
      errors: uniqueErrors,
      warnings: uniqueWarnings,
      primaryMessage,
      status,
      retirementTypeKey,
      retirementTypeLabel,
      ageAtRetirement,
      lengthOfServiceMonths
    };
  }

  function toNumber(value) {
    const normalized = String(value ?? "").replace(/,/g, "");
    const parsed = Number(normalized);
    return Number.isFinite(parsed) ? parsed : 0;
  }

  function roundMoney(value) {
    return Math.round((Number(value || 0) + Number.EPSILON) * 100) / 100;
  }

  function calculateBenefitSnapshot(payload = {}) {
    const retirementTypeKey = normalizeValue(payload.retirementType);
    const servicePeriod = calculateServicePeriod(payload.enlistmentDate, payload.retirementDate);
    const months = servicePeriod.roundedMonths;
    const monthlySalary = Math.max(0, toNumber(payload.monthlySalary));
    const annualSalary = roundMoney(monthlySalary * 12);
    const ageAtRetirement = calculateAgeAtRetirement(payload.birthDate, payload.retirementDate);

    const result = {
      retirementTypeKey,
      retirementTypeLabel: getLabel(retirementTypeKey) || "Retirement",
      servicePeriod,
      lengthOfService: months,
      monthlySalary,
      annualSalary,
      gratuity: 0,
      reducedPension: 0,
      fullPension: 0,
      ageAtRetirement
    };

    if (!retirementTypeKey || !months || annualSalary <= 0) {
      return result;
    }

    const cappedMonths = Math.min(months, 900);
    const baseAmount = (cappedMonths * annualSalary) / 500;
    const mandatoryGratuity = baseAmount * (1 / 3) * 15;
    const mandatoryReduced = (baseAmount * (2 / 3)) / 12;
    const mandatoryFull = baseAmount / 12;
    const shortServiceGratuity = ((cappedMonths * annualSalary) * 10) / 500;
    const marriageGratuity = ((cappedMonths * annualSalary) * 5) / 500;
    const contractGratuity = 0.25 * annualSalary * 2;
    const abolitionGratuity = baseAmount * 0.25 * (1 / 3) * 15;
    const abolitionReduced = (baseAmount * 0.25 * (2 / 3)) / 12;
    const abolitionFull = (baseAmount * 0.25) / 12;
    const qualifiesForLongService = cappedMonths >= 120;

    const assignMandatoryBenefits = () => {
      result.gratuity = roundMoney(mandatoryGratuity);
      result.reducedPension = roundMoney(mandatoryReduced);
      result.fullPension = roundMoney(mandatoryFull);
    };

    switch (retirementTypeKey) {
      case "mandatory":
      case "voluntary":
      case "oldAge":
        assignMandatoryBenefits();
        break;

      case "early":
      case "aor":
        if (qualifiesForLongService) {
          assignMandatoryBenefits();
        }
        break;

      case "cbe":
      case "ube":
      case "public":
        if (qualifiesForLongService) {
          assignMandatoryBenefits();
        } else {
          result.gratuity = roundMoney(shortServiceGratuity);
        }
        break;

      case "death":
      case "medical":
        result.gratuity = roundMoney(Math.max(3 * annualSalary, mandatoryGratuity));
        if (qualifiesForLongService) {
          result.reducedPension = roundMoney(mandatoryReduced);
          result.fullPension = roundMoney(mandatoryFull);
        }
        break;

      case "marriage":
        result.gratuity = roundMoney(marriageGratuity);
        break;

      case "contract":
      case "tx":
        result.gratuity = roundMoney(contractGratuity);
        break;

      case "abolition":
        result.gratuity = roundMoney(abolitionGratuity);
        result.reducedPension = roundMoney(abolitionReduced);
        result.fullPension = roundMoney(abolitionFull);
        break;

      default:
        if (qualifiesForLongService) {
          assignMandatoryBenefits();
        }
        break;
    }

    return result;
  }

  function derivePayType(payload = {}) {
    const retirementTypeKey = normalizeValue(payload.retirementType);
    const rawFallback = String(payload.payType ?? "").trim();
    const fallback = rawFallback ? normalizePayType(rawFallback) : "";

    if (!retirementTypeKey) {
      return fallback;
    }

    switch (retirementTypeKey) {
      case "mandatory":
      case "voluntary":
      case "oldAge":
      case "abolition":
        return "Pensioner";

      case "marriage":
      case "contract":
      case "tx":
        return "One-off Payment";

      default:
        break;
    }

    const servicePeriod = calculateServicePeriod(payload.enlistmentDate, payload.retirementDate);
    const months = Number(servicePeriod.roundedMonths || 0);
    if (!months) {
      return fallback;
    }

    const qualifiesForLongService = months >= 120;

    switch (retirementTypeKey) {
      case "early":
      case "aor":
      case "cbe":
      case "ube":
      case "public":
      case "death":
      case "medical":
        return qualifiesForLongService ? "Pensioner" : "One-off Payment";

      default:
        return fallback;
    }
  }

  global.PensionsGoRetirementTypes = {
    getDefinitions,
    getLabel,
    normalizeValue,
    normalizePayType,
    derivePayType,
    validateRetirementProfile,
    calculateServicePeriod,
    calculateAgeAtRetirement,
    calculateBenefitSnapshot,
    isDeathType
  };
})(window);
