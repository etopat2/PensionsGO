# Retirement Type Formula Reference

This sheet is the quick-reference guide for training, support, and QA on how PensionApp now interprets retirement types and calculates benefits.

## Core Calculation Inputs

- `Length of service`:
  calculated from `enlistmentDate` to `retirementDate`, rounded to months using the 15-day rule
- `Annual salary`:
  `monthlySalary * 12`
- `Base amount`:
  `(lengthOfService * annualSalary) / 500`
- `Long-service threshold`:
  `10 years = 120 months`

Note:
- The shared calculation engine caps service months at `900` months before applying formulas.

## Canonical Retirement Types

| Display Label | System Key | Benefit Family |
| --- | --- | --- |
| Mandatory Retirement | `mandatory` | Mandatory Retirement formula family |
| Early Retirement | `early` | Mandatory Retirement formula family only if service is at least 10 years |
| Death | `death` | Death / survivor rule |
| Discharge (A.O.R) | `aor` | Same rule as Early Retirement |
| Discharge (Medical) | `medical` | Medical rule |
| Discharge (Marriage) | `marriage` | Marriage gratuity only |
| Discharge (C.B.E) | `cbe` | Short-service gratuity below 10 years; Mandatory Retirement formula family at 10+ years |
| Discharge (U.B.E) | `ube` | Short-service gratuity below 10 years; Mandatory Retirement formula family at 10+ years |
| Discharge (Public Interest) | `public` | Short-service gratuity below 10 years; Mandatory Retirement formula family at 10+ years |
| End of Contract | `contract` | Contract gratuity only |
| Discharge (T.X) | `tx` | Same rule as End of Contract |
| Voluntary | `voluntary` | Same rule as Mandatory Retirement |
| Old Age | `oldAge` | Same rule as Mandatory Retirement |
| Abolition of Office | `abolition` | Special abolition formula |

## Formula Families

### 1. Mandatory Family

Applies to:
- Mandatory Retirement
- Voluntary
- Old Age
- Early Retirement when service is at least 10 years
- Discharge (A.O.R) when it satisfies the same route as Early Retirement
- Discharge (C.B.E) when service is at least 10 years
- Discharge (U.B.E) when service is at least 10 years
- Discharge (Public Interest) when service is at least 10 years

Formulas:
- `gratuity = baseAmount * (1/3) * 15`
- `monthly pension = (baseAmount * (2/3)) / 12`
- `full pension = baseAmount / 12`

### 2. No-Benefit Below 10 Years

Applies to:
- Early Retirement
- Discharge (A.O.R)

Rule:
- entry validation follows the same qualifying-service route for both labels:
  - `20 years of service`, or
  - `at least 10 years of service` with `age 45 years or above`
- if `lengthOfService < 120 months`
  - `gratuity = 0`
  - `monthly pension = 0`
  - `full pension = 0`

### 3. Short-Service Gratuity Rule

Applies to:
- Discharge (C.B.E)
- Discharge (U.B.E)
- Discharge (Public Interest)

Rule:
- if `lengthOfService < 120 months`
  - `short service gratuity = (lengthOfService * annualSalary * 10) / 500`
  - `monthly pension = 0`
  - `full pension = 0`
- if `lengthOfService >= 120 months`
  - use the Mandatory Retirement formula family

### 4. Death / Medical Rule

Applies to:
- Death
- Discharge (Medical)

Rule:
- `gratuity = max(3 * annualSalary, Mandatory Retirement gratuity)`
- if `lengthOfService >= 120 months`
  - `monthly pension = Mandatory Retirement monthly pension`
  - `full pension = Mandatory Retirement full pension`
- else
  - `monthly pension = 0`
  - `full pension = 0`

### 5. Marriage Rule

Applies to:
- Discharge (Marriage)

Formula:
- `Discharge (Marriage) gratuity = (lengthOfService * annualSalary * 5) / 500`

Rule:
- `monthly pension = 0`
- `full pension = 0`

### 6. Contract Rule

Applies to:
- End of Contract
- Discharge (T.X)

Formula:
- `End of Contract gratuity = 0.25 * annualSalary * 2`

Rule:
- `monthly pension = 0`
- `full pension = 0`

### 7. Abolition of Office Rule

Applies to:
- Abolition of Office

Formulas:
- `gratuity = (((lengthOfService * annualSalary) / 500) * 0.25 * (1/3) * 15)`
- `monthly pension = ((((lengthOfService * annualSalary) / 500) * 0.25 * (2/3)) / 12)`
- `full pension = ((((lengthOfService * annualSalary) / 500) * 0.25) / 12)`

## Legacy Value Mapping

The app normalizes older or mixed values into the canonical keys above.

Examples:
- `Contract Expired` -> End of Contract (`contract`)
- `End of Contract` -> End of Contract (`contract`)
- `Retirement by Death` -> Death (`death`)
- `At Own Request` -> Discharge (A.O.R) (`aor`)
- `Medical Grounds` -> Discharge (Medical) (`medical`)
- `Public Interest` -> Discharge (Public Interest) (`public`)
- `Discharge` -> Discharge (C.B.E) (`cbe`)
- `Old Age` -> Old Age (`oldAge`)

## QA Expectations

### Quick validation checklist

- Early Retirement with service below 10 years should return all zeros.
- Discharge (A.O.R) should match Early Retirement for the same service, age profile, salary, and dates.
- Discharge (C.B.E) below 10 years should show gratuity only, using short-service gratuity.
- Discharge (U.B.E) below 10 years should show gratuity only, using short-service gratuity.
- Discharge (Public Interest) below 10 years should show gratuity only, using short-service gratuity.
- Discharge (Marriage) should always show gratuity only.
- End of Contract and Discharge (T.X) should always show End of Contract gratuity only.
- Voluntary should match Mandatory Retirement for the same dates and salary.
- Old Age should match Mandatory Retirement for the same dates and salary.
- Abolition of Office should never use the normal Mandatory Retirement pension values.
- Death and Discharge (Medical) should use the larger of:
  - `3 * annual salary`
  - Mandatory Retirement gratuity

### Suggested QA sample cases

| Case | Service | Type | Expected Outcome |
| --- | --- | --- | --- |
| 1 | 96 months | Early Retirement | All benefit outputs are `0.00` |
| 2 | 96 months | Discharge (C.B.E) | Short-service gratuity only |
| 3 | 180 months | Discharge (C.B.E) | Same outputs as Mandatory Retirement |
| 4 | 96 months | Discharge (Public Interest) | Short-service gratuity only |
| 5 | 84 months | Discharge (Marriage) | Marriage gratuity only |
| 6 | 144 months | End of Contract | Contract gratuity only |
| 7 | 144 months | Discharge (T.X) | Same as End of Contract |
| 8 | 156 months | Abolition of Office | Special abolition outputs |
| 9 | 132 months | Voluntary | Same outputs as Mandatory Retirement |
| 10 | 132 months | Old Age | Same outputs as Mandatory Retirement |
| 11 | 108 months | Death | Gratuity only, no monthly/full pension |

## App Areas Using These Rules

These rules are now shared across:
- Benefits Calculator
- Add Staff Due for Retirement
- Edit Staff Record
- Workflow write-up / checkpoint computation
- Pension File Registry create/update
- Import normalization and auto-computation
- Claims aggregation display labels
- Pensioner and dashboard retirement-type displays

## Training Note

For operational use, staff should always enter or select the approved retirement label from the official list. Older labels may still be accepted during import or lookup, but the system will normalize them internally to the matching system key before saving or calculating benefits.
