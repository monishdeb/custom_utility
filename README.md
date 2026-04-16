# RA Custom Utility – CiviCRM Extension

A CiviCRM extension for the Rabbinical Assembly that provides:

- **Dues Management**: Separates salary declaration from payment processing.
- **Persistent Storage**: Stores salary and dues on the CiviCRM contact record.
- **Administrative Overrides**: Allows staff to set hardship/board-decision overrides (permanent or year-specific).
- **Installment Splitting**: Members choose how many payments to split their dues into.
- **Reporting Support**: Line-item dues storage enables QuickBooks export and invoicing.

---

## Extension Structure

```
custom_utility/
├── info.xml                                    # CiviCRM extension metadata
├── custom_utility.php                          # Main extension hooks
├── CRM/
│   └── CustomUtility/
│       ├── Form/
│       │   └── DuesOverride.php                # Admin override add/edit form
│       ├── Page/
│       │   └── DuesOverrideTab.php             # Contact record tab
│       └── Utils/
│           └── DuesCalculator.php              # Salary → dues calculation engine
├── xml/
│   ├── Menu/
│   │   └── duesmanagement.xml                  # CiviCRM route definitions
│   └── schema/
│       └── customfields.xml                    # Field group documentation
├── templates/
│   └── CRM/
│       └── CustomUtility/
│           ├── Form/
│           │   └── DuesOverride.tpl            # Override form Smarty template
│           └── Page/
│               └── DuesOverrideTab.tpl         # Contact tab Smarty template
├── css/
│   ├── ra_common.css                           # Shared styles (from Drupal module)
│   ├── crm_menubar.css                         # CRM menu bar styles
│   └── ra_dues.css                             # Dues management styles
├── js/
│   ├── ra_custom_utility.js                    # Shared JS (from Drupal module)
│   ├── jquery.doubleScroll.js
│   ├── jquery.mousewheel.min.js
│   └── ra_dues.js                              # Dues management JS
├── src/
│   ├── Controller/
│   │   └── RaUtilityController.php             # Drupal state/country API
│   └── Form/
│       ├── DuesDeclarationForm.php             # Drupal dues declaration form
│       └── UploadTaxDocMemberForm.php          # Drupal tax document upload form
├── ra_custom_utility.info.yml                  # Drupal module metadata (kept for Drupal-side forms)
├── ra_custom_utility.libraries.yml             # Drupal library definitions
├── ra_custom_utility.module                    # Drupal module hooks (legacy, keep for Drupal-specific functionality)
├── ra_custom_utility.routing.yml               # Drupal route definitions
└── README.md                                   # This file
```

---

## Architecture Overview

### Three-Component Dues Workflow

```
Member Visit
     │
     ▼
[Declaration Required?] ──No──► [Payment Page (CiviCRM Form id=11)]
     │ Yes                              │
     ▼                                  │
[/member-dues-declaration]             │
(Drupal form)                          │
     │ Submit                          │
     ▼                                  │
[Store in salary_declarations]         │
[CiviCRM custom field group]           │
     │                                  │
     └──────────────────────────────────►
                                        │
                                  Check override
                                        │
                              [Active override?]
                                  │         │
                                 Yes        No
                                  │         │
                            Override     Calculated
                            amount       dues amount
                                  │         │
                                  └────┬────┘
                                       │
                                 [Display dues]
                                       │
                                 [Member pays]
                                       │
                              [Contribution saved]
```

### Custom Field Groups

#### `salary_declarations` (multi-record, on Contact)

| Field               | Type             | Description                                |
|---------------------|------------------|--------------------------------------------|
| `declaration_year`  | Text             | e.g. "2026-2027"                           |
| `declaration_date`  | Date             | Date member submitted the declaration      |
| `salary_declared`   | Money            | Annual gross salary                        |
| `status_category`   | Select           | member / widow_widower / other             |
| `calculated_dues`   | Money (read-only)| Salary × rate, rounded to nearest $5       |
| `job_change_flag`   | Boolean          | True if triggered by job change            |

#### `dues_overrides` (multi-record, on Contact)

| Field             | Type              | Description                                       |
|-------------------|-------------------|---------------------------------------------------|
| `override_year`   | Text              | e.g. "2026-2027"; blank = permanent               |
| `override_amount` | Money             | Override dues amount                              |
| `override_reason` | Select            | extenuating_circumstances / hardship / board_decision / other |
| `reason_notes`    | Memo              | Staff notes                                       |
| `staff_member_id` | Contact Reference | Staff member who set the override                 |
| `date_set`        | Date              | When the override was created                     |
| `is_permanent`    | Boolean           | If true, applies to all years                     |

---

## Dues Calculation Formula

```
dues = salary × rate(status)
```

Rates:
- **Member**: 2.3% (`salary × 0.023`)
- **Widow/Widower**: 1.5% (`salary × 0.015`)
- **Other**: 0% (requires override)

Rounding: nearest **$5**

Example: `$125,000 × 2.3% = $2,875`

---

## Declaration Frequency Logic

A member is directed to `/member-dues-declaration` if **any** of the following are true:

1. No prior salary declaration exists.
2. No current-year declaration exists.
3. The most recent declaration was **≥ 5 years ago**.
4. The `job_change_flag` is set on the most recent declaration.

Otherwise, the member proceeds directly to the CiviCRM payment form (id=11).

---

## Override Precedence

When determining the effective dues amount for a member:

1. **Permanent override** (`is_permanent = true`) → always takes precedence.
2. **Year-specific override** (`override_year` matches current dues year) → used if no permanent override.
3. **Calculated dues** (from salary declaration) → used if no override.

---

## Dues Year

The dues year runs **July – June**:
- January–June 2027 → dues year "2026-2027"
- July–December 2027 → dues year "2027-2028"

---

## Installation

### 1. Install as a CiviCRM extension

Place the `custom_utility` directory in your CiviCRM extensions path
(e.g. `sites/default/files/civicrm/ext/custom_utility`) and enable via:

**Administer → System Settings → Extensions**

The install hook will automatically create the two custom field groups
(`salary_declarations`, `dues_overrides`) on the first enable.

### 2. Drupal module (for declaration form)

The directory also functions as a Drupal module for the
`/member-dues-declaration` route and the tax-document upload form.
Enable it in Drupal as you would any custom module.

---

## CiviCRM Routes Added

| URL                                 | Controller                               | Description                         |
|-------------------------------------|------------------------------------------|-------------------------------------|
| `civicrm/contact/dues-management`   | `CRM_CustomUtility_Page_DuesOverrideTab` | Dues Management contact tab         |
| `civicrm/dues/override/add`         | `CRM_CustomUtility_Form_DuesOverride`    | Add a new override                  |
| `civicrm/dues/override/edit`        | `CRM_CustomUtility_Form_DuesOverride`    | Edit an existing override           |

---

## Drupal Routes Added

| URL                          | Controller                                       | Description                     |
|------------------------------|--------------------------------------------------|---------------------------------|
| `/member-dues-declaration`   | `DuesDeclarationForm`                            | Member salary declaration form  |
| `/states/list`               | `RaUtilityController::getStates`                 | AJAX state/province list        |
| `/upload/tax-doc-upload-member/user/{user}` | `UploadTaxDocMemberForm`            | Tax document upload             |

---

## Migration from Drupal Module

The original `ra_custom_utility.module` contained:

| Original Code                         | Disposition                                               |
|---------------------------------------|-----------------------------------------------------------|
| `ra_custom_utility_civicrm_buildForm` | Moved to `custom_utility.php` as `custom_utility_civicrm_buildForm` |
| Views hooks (query_alter, pre_view)   | **Removed** – Drupal-specific, not needed in CiviCRM     |
| `hook_preprocess_*`                   | **Removed** – Drupal-specific                             |
| `hook_page_attachments`               | **Removed** – handled via CiviCRM resource system        |
| `get_country_list`, `get_province_list` | Kept in `ra_custom_utility.module` for Drupal views     |
| `get_civiid($uid)`                    | Replaced by direct `UFMatch` API calls in each context   |
| `check_user_orders`                   | Kept in `ra_custom_utility.module` (Drupal-specific)     |
| Dues form logic (form id=11)          | Expanded in `custom_utility.php` with new architecture   |

---

## Access Control

| Capability                      | Required Permission          |
|---------------------------------|------------------------------|
| View Dues Management tab        | `administer CiviCRM`         |
| Add / edit dues override        | `administer CiviCRM`         |
| Complete salary declaration     | Drupal role: `ra_member`     |
| View own dues history           | Any authenticated member     |

---

## QuickBooks / Reporting Support

Dues and optional contributions are stored as separate line items in the
CiviCRM contribution record. The `salary_declarations` custom group provides
a persistent salary record per member per year, enabling:

- Annual dues invoicing
- QuickBooks export with dues vs. optional breakdown
- Multi-year reporting on salary trends

---

## 5-Year Rollout Plan

| Year 1 (2026-2027) | All existing members complete the declaration form once to seed the data. |
|--------------------|---------------------------------------------------------------------------|
| Year 2+            | Only new members, members flagged for job change, or 5-year renewal.     |
| Admin overrides    | Available immediately on first enable.                                    |

---

## Development Notes

- PHP class autoloading is handled by CiviCRM's include-path mechanism set in `custom_utility_civicrm_config`.
- `DuesCalculator::saveDeclaration()` is the canonical write path for new declarations; call it from both the Drupal form and any API script.
- All helper functions prefixed `_custom_utility_` are internal; call through the public class API where possible.
- The extension is idempotent: re-enabling it will not create duplicate custom groups.
