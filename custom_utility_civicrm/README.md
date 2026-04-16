# Custom Utility – Dues Management (CiviCRM Extension)

## Overview

This CiviCRM extension manages the member dues architecture for the Rabbinical Assembly, including:

- **Custom field groups** (`salary_declarations`, `dues_overrides`) created on install
- **Dues calculator** – converts declared salary to dues amount based on status
- **Payment form hook** – enhances the contribution form (page id=11) with pre-condition checks, override lookups, and installment UI
- **Admin override interface** – contact-record tab for managing permanent and year-specific dues overrides

## Architecture

The system uses a hybrid Drupal + CiviCRM approach:

| Layer | Responsibility |
|---|---|
| **Drupal** (`ra_custom_utility`) | Member-facing declaration form at `/member-dues-declaration` |
| **CiviCRM** (this extension) | Dues calculator, custom field storage, payment form hook, admin override interface |

### Workflow

```
Member visits /member-dues-declaration (Drupal)
    │
    ├─ Is declaration required? (new / 5-year cycle / job change)
    │       └─ Yes → show form → save salary_declarations record → redirect to payment
    │       └─ No  → redirect directly to payment (form id=11)
    │
Member visits /civicrm/contribute/transact?id=11 (CiviCRM)
    │
    ├─ Current-year declaration exists?
    │       └─ No  → block form; show link to declaration page
    │       └─ Yes → continue
    │
    ├─ Override (permanent or year-specific) exists?
    │       └─ Yes → use override amount
    │       └─ No  → use calculated dues from declaration
    │
    └─ Show read-only dues + optional contributions + installment UI
```

## Extension Structure

```
custom_utility_civicrm/
├── info.xml                                    Extension metadata
├── custom_utility.php                          Main: install hooks, buildForm hook
├── CRM/
│   └── CustomUtility/
│       ├── Utils/
│       │   └── DuesCalculator.php             Salary → dues calculation & declaration logic
│       ├── Form/
│       │   └── DuesOverride.php               Override add/edit form
│       └── Page/
│           └── DuesOverrideTab.php            Contact tab display
├── xml/
│   ├── Menu/
│   │   └── duesmanagement.xml                 Admin route definitions
│   └── schema/
│       └── customfields.xml                   Field group documentation
├── templates/
│   ├── CRM/CustomUtility/Page/DuesOverrideTab.tpl
│   └── CRM/CustomUtility/Form/DuesOverride.tpl
├── css/
│   └── ra_dues.css                            Dues-specific styling
├── js/
│   └── ra_dues.js                             Real-time calculation, installment UI
└── README.md                                  This file
```

## Custom Field Groups

### `salary_declarations` (multi-record per contact)

| Field | Type | Description |
|---|---|---|
| `declaration_year` | Text | Fiscal year, e.g. `"2026-2027"` |
| `declaration_date` | Date | When the declaration was made |
| `salary_declared`  | Money | Annual salary in USD |
| `status_category`  | Select | `member`, `widow_widower`, `other` |
| `calculated_dues`  | Money (read-only) | Computed by `DuesCalculator::calculate()` |
| `job_change_flag`  | Boolean | Member reports a job change |

### `dues_overrides` (multi-record per contact)

| Field | Type | Description |
|---|---|---|
| `override_year`   | Text | `"2026-2027"` for year-specific; empty for permanent |
| `override_amount` | Money | Override dues amount |
| `override_reason` | Select | `extenuating_circumstances`, `hardship`, `board_decision`, `other` |
| `reason_notes`    | Memo | Admin notes |
| `staff_member_id` | ContactReference | Who set the override |
| `date_set`        | Date | When the override was created |
| `is_permanent`    | Boolean | If true, applies to all years |

## Dues Calculation

```
dues = salary × rate (rounded to nearest $5)

Rates:
  member        → 2.3% of salary
  widow_widower → 1.5% of salary
  other         → $0 (requires manual override)
```

## Declaration Requirement Logic

A member must declare salary when:

1. **New** – no previous declaration exists
2. **5-year cycle** – most recent declaration is ≥ 5 years old
3. **Job change** – previous year's declaration has `job_change_flag = true`

Otherwise, the member goes directly to the payment form.

## Admin Override Interface

**URL**: `/civicrm/contact/dues-management?cid={contactId}`

Staff with the `administer CiviCRM` permission can:
- View the member's current declaration summary and effective dues
- Add permanent or year-specific overrides
- Review the full override history (amount, reason, staff, date)

## Installation

1. Copy the `custom_utility_civicrm/` directory to your CiviCRM extensions path.
2. Navigate to **Administer → System Settings → Extensions**.
3. Install **Custom Utility – Dues Management**.
4. The custom field groups `salary_declarations` and `dues_overrides` will be created automatically.

## Dependencies

- CiviCRM 5.0+
- Drupal 8.x+ with `ra_custom_utility` module
- PHP 7.2+

## Backward Compatibility

- Existing contributions on page id=11 continue to work.
- The hook is additive: it only modifies display/UI, not stored contribution data.
- New declarations are stored in the new custom field groups alongside existing data.
