# Retirement Type Formula Handout

## Purpose

Quick one-page training and QA reference for PensionApp retirement-type labels, normalization, and benefit formulas.

## Formula Matrix

| Display Label | System Key | Rule Summary |
| --- | --- | --- |
| Mandatory Retirement | `mandatory` | Uses the Mandatory Retirement formula family |
| Early Retirement | `early` | No benefits below 10 years; uses the Mandatory Retirement formula family at 10+ years |
| Death | `death` | Gratuity is the higher of `3 x annual salary` or the Mandatory Retirement gratuity; pension applies only at 10+ years |
| Discharge (A.O.R) | `aor` | Same rule as Early Retirement |
| Discharge (Medical) | `medical` | Uses the same rule as Death |
| Discharge (Marriage) | `marriage` | Marriage gratuity only; no monthly or full pension |
| Discharge (C.B.E) | `cbe` | Short-service gratuity below 10 years; uses the Mandatory Retirement formula family at 10+ years |
| Discharge (U.B.E) | `ube` | Short-service gratuity below 10 years; uses the Mandatory Retirement formula family at 10+ years |
| Discharge (Public Interest) | `public` | Short-service gratuity below 10 years; uses the Mandatory Retirement formula family at 10+ years |
| End of Contract | `contract` | Contract gratuity only |
| Discharge (T.X) | `tx` | Uses the same rule as End of Contract |
| Voluntary | `voluntary` | Uses the Mandatory Retirement formula family |
| Old Age | `oldAge` | Uses the Mandatory Retirement formula family |
| Abolition of Office | `abolition` | Uses the special 25% abolition formula |

## Core Formulas

- Mandatory Retirement gratuity: `(service x annual salary / 500) x (1/3) x 15`
- Mandatory Retirement monthly pension: `((service x annual salary / 500) x (2/3)) / 12`
- Mandatory Retirement full pension: `(service x annual salary / 500) / 12`
- Short-service gratuity: `(service x annual salary x 10) / 500`
- Discharge (Marriage) gratuity: `(service x annual salary x 5) / 500`
- End of Contract gratuity: `0.25 x annual salary x 2`
- Abolition gratuity: `((service x annual salary / 500) x 0.25 x (1/3) x 15)`
- Abolition monthly pension: `((service x annual salary / 500) x 0.25 x (2/3)) / 12`
- Abolition full pension: `((service x annual salary / 500) x 0.25) / 12`

## QA Checks

- Early Retirement and Discharge (A.O.R): same qualification route and same outputs for the same service, age profile, salary, and dates
- Discharge (C.B.E), Discharge (U.B.E), and Discharge (Public Interest) below 10 years: gratuity only
- Discharge (Marriage): gratuity only
- End of Contract and Discharge (T.X): same output
- Voluntary and Old Age: same output as Mandatory Retirement
- Abolition of Office: must not use the normal Mandatory Retirement pension values

## Legacy Aliases

- `Contract Expired` -> End of Contract (`contract`)
- `Retirement by Death` -> Death (`death`)
- `At Own Request` -> Discharge (A.O.R) (`aor`)
- `Medical Grounds` -> Discharge (Medical) (`medical`)
- `Public Interest` -> Discharge (Public Interest) (`public`)
- `Discharge` -> Discharge (C.B.E) (`cbe`)
