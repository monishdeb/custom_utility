<?php

/**
 * @file
 * CiviCRM Extension: Custom Utility – Dues Management
 *
 * Provides:
 *  - Custom field groups: salary_declarations, dues_overrides (installed on extension enable)
 *  - Payment form hook (contribution form id=11): declaration pre-condition, override lookup
 *  - Admin override interface: CRM_CustomUtility_Form_DuesOverride / Page_DuesOverrideTab
 */

use CRM_CustomUtility_Utils_DuesCalculator as DuesCalculator;

// ---------------------------------------------------------------------------
// Extension lifecycle hooks
// ---------------------------------------------------------------------------

/**
 * Implements hook_civicrm_install().
 *
 * Creates the salary_declarations and dues_overrides custom field groups.
 */
function custom_utility_civicrm_install() {
  _custom_utility_create_custom_field_groups();
}

/**
 * Implements hook_civicrm_enable().
 */
function custom_utility_civicrm_enable() {
  _custom_utility_create_custom_field_groups();
}

// ---------------------------------------------------------------------------
// Custom-field group creation
// ---------------------------------------------------------------------------

/**
 * Creates (or verifies) the salary_declarations and dues_overrides custom groups.
 */
function _custom_utility_create_custom_field_groups() {
  _custom_utility_ensure_salary_declarations_group();
  _custom_utility_ensure_dues_overrides_group();
}

/**
 * Creates the salary_declarations multi-record custom group and its fields.
 */
function _custom_utility_ensure_salary_declarations_group() {
  // Check whether the group already exists.
  $existing = civicrm_api3('CustomGroup', 'get', [
    'name'  => 'salary_declarations',
    'return' => ['id'],
  ]);
  if (!empty($existing['id'])) {
    return;
  }

  $group = civicrm_api3('CustomGroup', 'create', [
    'name'             => 'salary_declarations',
    'title'            => 'Salary Declarations',
    'extends'          => 'Individual',
    'style'            => 'Tab with table',
    'is_multiple'      => 1,
    'is_active'        => 1,
    'table_name'       => 'civicrm_value_salary_declarations',
  ]);
  $groupId = $group['id'];

  $fields = [
    ['name' => 'declaration_year',  'label' => 'Declaration Year',  'data_type' => 'String',  'html_type' => 'Text',     'is_required' => 1],
    ['name' => 'declaration_date',  'label' => 'Declaration Date',  'data_type' => 'Date',    'html_type' => 'Select Date', 'is_required' => 1],
    ['name' => 'salary_declared',   'label' => 'Salary Declared',   'data_type' => 'Money',   'html_type' => 'Text',     'is_required' => 1],
    ['name' => 'status_category',   'label' => 'Status Category',   'data_type' => 'String',  'html_type' => 'Select',   'is_required' => 1,
      'option_values' => ['member' => 'Member', 'widow_widower' => 'Widow/Widower', 'other' => 'Other'],
    ],
    ['name' => 'calculated_dues',   'label' => 'Calculated Dues',   'data_type' => 'Money',   'html_type' => 'Text',     'is_view' => 1],
    ['name' => 'job_change_flag',   'label' => 'Job Change',        'data_type' => 'Boolean', 'html_type' => 'Radio',    'is_required' => 0],
  ];

  foreach ($fields as $fieldDef) {
    $optionValues = isset($fieldDef['option_values']) ? $fieldDef['option_values'] : [];
    unset($fieldDef['option_values']);

    $fieldDef['custom_group_id'] = $groupId;
    $fieldDef['is_active']       = 1;
    $created = civicrm_api3('CustomField', 'create', $fieldDef);

    if (!empty($optionValues) && !empty($created['id'])) {
      $field = civicrm_api3('CustomField', 'getsingle', ['id' => $created['id'], 'return' => ['option_group_id']]);
      if (!empty($field['option_group_id'])) {
        foreach ($optionValues as $value => $label) {
          civicrm_api3('OptionValue', 'create', [
            'option_group_id' => $field['option_group_id'],
            'value'           => $value,
            'label'           => $label,
            'is_active'       => 1,
          ]);
        }
      }
    }
  }
}

/**
 * Creates the dues_overrides multi-record custom group and its fields.
 */
function _custom_utility_ensure_dues_overrides_group() {
  $existing = civicrm_api3('CustomGroup', 'get', [
    'name'  => 'dues_overrides',
    'return' => ['id'],
  ]);
  if (!empty($existing['id'])) {
    return;
  }

  $group = civicrm_api3('CustomGroup', 'create', [
    'name'        => 'dues_overrides',
    'title'       => 'Dues Overrides',
    'extends'     => 'Individual',
    'style'       => 'Tab with table',
    'is_multiple' => 1,
    'is_active'   => 1,
    'table_name'  => 'civicrm_value_dues_overrides',
  ]);
  $groupId = $group['id'];

  $fields = [
    ['name' => 'override_year',   'label' => 'Override Year',   'data_type' => 'String',  'html_type' => 'Text'],
    ['name' => 'override_amount', 'label' => 'Override Amount', 'data_type' => 'Money',   'html_type' => 'Text',   'is_required' => 1],
    ['name' => 'override_reason', 'label' => 'Override Reason', 'data_type' => 'String',  'html_type' => 'Select', 'is_required' => 1,
      'option_values' => [
        'extenuating_circumstances' => 'Extenuating Circumstances',
        'hardship'                  => 'Hardship',
        'board_decision'            => 'Board Decision',
        'other'                     => 'Other',
      ],
    ],
    ['name' => 'reason_notes',     'label' => 'Reason Notes',    'data_type' => 'Memo',    'html_type' => 'TextArea'],
    ['name' => 'staff_member_id',  'label' => 'Staff Member',    'data_type' => 'ContactReference', 'html_type' => 'Autocomplete-Select'],
    ['name' => 'date_set',         'label' => 'Date Set',        'data_type' => 'Date',    'html_type' => 'Select Date', 'is_required' => 1],
    ['name' => 'is_permanent',     'label' => 'Is Permanent',    'data_type' => 'Boolean', 'html_type' => 'Radio'],
  ];

  foreach ($fields as $fieldDef) {
    $optionValues = isset($fieldDef['option_values']) ? $fieldDef['option_values'] : [];
    unset($fieldDef['option_values']);

    $fieldDef['custom_group_id'] = $groupId;
    $fieldDef['is_active']       = 1;
    $created = civicrm_api3('CustomField', 'create', $fieldDef);

    if (!empty($optionValues) && !empty($created['id'])) {
      $field = civicrm_api3('CustomField', 'getsingle', ['id' => $created['id'], 'return' => ['option_group_id']]);
      if (!empty($field['option_group_id'])) {
        foreach ($optionValues as $value => $label) {
          civicrm_api3('OptionValue', 'create', [
            'option_group_id' => $field['option_group_id'],
            'value'           => $value,
            'label'           => $label,
            'is_active'       => 1,
          ]);
        }
      }
    }
  }
}

// ---------------------------------------------------------------------------
// CiviCRM hooks
// ---------------------------------------------------------------------------

/**
 * Implements hook_civicrm_buildForm().
 *
 * Enhances the contribution form (id=11):
 *  1. Blocks access if no current-year salary declaration exists.
 *  2. Applies permanent or year-specific override amounts.
 *  3. Locks dues amount (read-only).
 *  4. Shows a confirmation message if the member has already paid this year.
 */
function custom_utility_civicrm_buildForm($formName, &$form) {
  if ($formName !== 'CRM_Contribute_Form_Contribution_Main' || $form->_id != 11) {
    return;
  }

  $contactId = CRM_Core_Session::getLoggedInContactID();
  if (!$contactId) {
    return;
  }

  $duesYear = DuesCalculator::getCurrentDuesYear();

  // ------------------------------------------------------------------
  // 1. Check for current-year salary declaration.
  // ------------------------------------------------------------------
  $declarationRequired = DuesCalculator::declarationRequired($contactId, $duesYear);
  if ($declarationRequired !== null) {
    $declarationUrl = CRM_Utils_System::url('member-dues-declaration', '', FALSE, NULL, FALSE, TRUE);
    $message = ts(
      'A salary declaration is required before you can make a dues payment. '
      . '<a href="%1">Click here to complete your salary declaration</a>.',
      [1 => $declarationUrl]
    );
    CRM_Core_Session::setStatus($message, ts('Declaration Required'), 'error');
    $form->assign('dues_declaration_required', TRUE);
    $form->assign('dues_declaration_url', $declarationUrl);
    $form->assign('dues_declaration_message', $message);
    return;
  }

  // ------------------------------------------------------------------
  // 2. Check if payment already made this year → show confirmation.
  // ------------------------------------------------------------------
  $alreadyPaid = _custom_utility_check_current_year_payment($contactId, $duesYear);
  if ($alreadyPaid) {
    $form->assign('dues_already_paid', TRUE);
    $form->assign('dues_year', $duesYear);
    return;
  }

  // ------------------------------------------------------------------
  // 3. Determine effective dues (override or calculated).
  // ------------------------------------------------------------------
  $effectiveDues = DuesCalculator::getEffectiveDues($contactId, $duesYear);
  if (empty($effectiveDues)) {
    return;
  }

  $amount      = $effectiveDues['amount'];
  $duesType    = $effectiveDues['type'];
  $duesReason  = $effectiveDues['reason'];

  // ------------------------------------------------------------------
  // 4. Get salary declaration details for display.
  // ------------------------------------------------------------------
  $declaration = _custom_utility_get_current_declaration($contactId, $duesYear);

  // ------------------------------------------------------------------
  // 5. Assign display variables to the form template.
  // ------------------------------------------------------------------
  $form->assign('dues_year', $duesYear);
  $form->assign('dues_salary_declared', $declaration ? $declaration['salary_declared'] : '');
  $form->assign('dues_calculated', $declaration ? $declaration['calculated_dues'] : '');
  $form->assign('dues_effective_amount', $amount);
  $form->assign('dues_type', $duesType);
  $form->assign('dues_override_reason', $duesReason);

  // ------------------------------------------------------------------
  // 6. Lock the dues amount on the form.
  // ------------------------------------------------------------------
  if ($form->elementExists('price_275')) {
    $contributionAmountEl =& $form->getElement('price_275');
    $contributionAmountEl->setValue($amount);
    $contributionAmountEl->freeze();
  }

  // ------------------------------------------------------------------
  // 7. Add installment-split UI.
  // ------------------------------------------------------------------
  $customRecurOptions = [
    1 => ts("I'd like to pay the full amount of $%1", [1 => number_format($amount, 2)]),
    2 => ts('Please divide my payment into installments'),
  ];
  $recurElements = [];
  foreach ($customRecurOptions as $key => $label) {
    $recurElements[$key] = $form->createElement('radio', NULL, ts('Payment Option'), $label, $key);
  }
  $form->addGroup($recurElements, 'recurrence_options', ts('Payment Options'));
  $form->setDefaults(['recurrence_options' => 1]);
  $form->add('text', 'recur_installment', ts('Number of Installments'));

  // Change submit label.
  if ($form->elementExists('buttons')) {
    $buttons =& $form->getElement('buttons');
    foreach ($buttons->_elements as $e) {
      if ($e->_attributes['type'] === 'submit') {
        $e->setValue(ts('Confirm Payment'));
      }
    }
  }

  // Attach assets.
  CRM_Core_Resources::singleton()->addStyleFile('custom_utility_civicrm', 'css/ra_dues.css');
  CRM_Core_Resources::singleton()->addScriptFile('custom_utility_civicrm', 'js/ra_dues.js');
}

/**
 * Implements hook_civicrm_navigationMenu().
 *
 * Adds the Dues Management item to the CiviCRM admin menu.
 */
function custom_utility_civicrm_navigationMenu(&$menu) {
  _custom_utility_insert_navigation_menu($menu, 'Contacts', [
    'label'      => ts('Dues Management'),
    'name'       => 'dues_management',
    'url'        => 'civicrm/contact/dues-management',
    'permission' => 'administer CiviCRM',
    'operator'   => 'OR',
    'separator'  => 0,
  ]);
}

/**
 * Helper: inserts a navigation menu item under a given parent.
 */
function _custom_utility_insert_navigation_menu(&$menu, $parentPath, $item) {
  foreach ($menu as $key => &$entry) {
    if ($entry['attributes']['name'] == $parentPath) {
      $maxKey = max(array_keys($entry['child'] ?? []));
      $entry['child'][$maxKey + 1] = ['attributes' => $item + ['is_active' => 1]];
      return TRUE;
    }
    if (!empty($entry['child'])) {
      if (_custom_utility_insert_navigation_menu($entry['child'], $parentPath, $item)) {
        return TRUE;
      }
    }
  }
  return FALSE;
}

// ---------------------------------------------------------------------------
// Internal helpers
// ---------------------------------------------------------------------------

/**
 * Checks whether the contact has a completed contribution for dues this year.
 *
 * @param int    $contactId
 * @param string $duesYear  e.g. "2026-2027"
 *
 * @return bool
 */
function _custom_utility_check_current_year_payment($contactId, $duesYear) {
  $result = civicrm_api3('Contribution', 'get', [
    'contact_id'            => $contactId,
    'contribution_page_id'  => 11,
    'contribution_status_id' => 1, // Completed
    'options'               => ['limit' => 1],
    'return'                => ['id'],
  ]);
  // Simple check: any completed contribution on page 11 this fiscal year.
  // A more precise check would filter by receive_date within the fiscal year.
  if (!empty($result['values'])) {
    foreach ($result['values'] as $contribution) {
      $receiveDate = $contribution['receive_date'] ?? '';
      if ($receiveDate) {
        $year = (int) date('Y', strtotime($receiveDate));
        [$fromYear] = explode('-', $duesYear);
        if ($year == (int) $fromYear) {
          return TRUE;
        }
      }
    }
  }
  return FALSE;
}

/**
 * Returns the current-year salary_declarations record for a contact.
 *
 * @param int    $contactId
 * @param string $duesYear
 *
 * @return array|null
 */
function _custom_utility_get_current_declaration($contactId, $duesYear) {
  try {
    $group = civicrm_api3('CustomGroup', 'getsingle', [
      'name'   => 'salary_declarations',
      'return' => ['id', 'table_name'],
    ]);
    $tableName = $group['table_name'];

    $yearField = civicrm_api3('CustomField', 'getsingle', [
      'custom_group_id' => $group['id'],
      'name'            => 'declaration_year',
      'return'          => ['column_name'],
    ]);
    $salaryField = civicrm_api3('CustomField', 'getsingle', [
      'custom_group_id' => $group['id'],
      'name'            => 'salary_declared',
      'return'          => ['column_name'],
    ]);
    $calcField = civicrm_api3('CustomField', 'getsingle', [
      'custom_group_id' => $group['id'],
      'name'            => 'calculated_dues',
      'return'          => ['column_name'],
    ]);

    $row = CRM_Core_DAO::executeQuery(
      "SELECT {$salaryField['column_name']} AS salary_declared,
              {$calcField['column_name']}   AS calculated_dues
       FROM   {$tableName}
       WHERE  entity_id = %1
         AND  {$yearField['column_name']} = %2
       LIMIT  1",
      [
        1 => [$contactId, 'Integer'],
        2 => [$duesYear,  'String'],
      ]
    );
    if ($row->fetch()) {
      return [
        'salary_declared' => $row->salary_declared,
        'calculated_dues' => $row->calculated_dues,
      ];
    }
  }
  catch (Exception $e) {
    CRM_Core_Error::debug_log_message('custom_utility: ' . $e->getMessage());
  }
  return NULL;
}
