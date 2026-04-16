<?php

/**
 * @file
 * CiviCRM Extension: RA Custom Utility
 *
 * Provides dues management, salary declaration tracking, and administrative
 * override capabilities for the Rabbinical Assembly CiviCRM instance.
 *
 * Converted from the ra_custom_utility Drupal module.
 */

use CRM_CustomUtility_Utils_DuesCalculator as DuesCalculator;

/**
 * Implements hook_civicrm_config().
 */
function custom_utility_civicrm_config(&$config) {
  $extRoot = dirname(__FILE__) . DIRECTORY_SEPARATOR;
  $include_path = $extRoot . DIRECTORY_SEPARATOR . 'CRM' . PATH_SEPARATOR . get_include_path();
  set_include_path($include_path);
}

/**
 * Implements hook_civicrm_install().
 *
 * Creates custom field groups for salary declarations and dues overrides
 * on the Contact entity when the extension is first installed.
 */
function custom_utility_civicrm_install() {
  _custom_utility_create_custom_groups();
}

/**
 * Implements hook_civicrm_enable().
 *
 * Re-checks that custom field groups exist (idempotent).
 */
function custom_utility_civicrm_enable() {
  _custom_utility_create_custom_groups();
}

/**
 * Creates (or ensures existence of) the salary_declarations and dues_overrides
 * custom field groups on the Contact entity.
 */
function _custom_utility_create_custom_groups() {
  // -----------------------------------------------------------------------
  // 1. Salary Declarations (multi-record)
  // -----------------------------------------------------------------------
  $salaryGroupResult = civicrm_api3('CustomGroup', 'get', [
    'name'   => 'salary_declarations',
    'return' => ['id'],
  ]);

  if (empty($salaryGroupResult['values'])) {
    $salaryGroup = civicrm_api3('CustomGroup', 'create', [
      'name'              => 'salary_declarations',
      'title'             => 'Salary Declarations',
      'extends'           => 'Individual',
      'style'             => 'Tab with table',
      'is_multiple'       => 1,
      'collapse_adv_display' => 1,
      'is_active'         => 1,
    ]);
    $salaryGroupId = $salaryGroup['id'];

    $salaryFields = [
      [
        'name'       => 'declaration_year',
        'label'      => 'Declaration Year',
        'data_type'  => 'String',
        'html_type'  => 'Text',
        'is_required' => 1,
      ],
      [
        'name'      => 'declaration_date',
        'label'     => 'Declaration Date',
        'data_type' => 'Date',
        'html_type' => 'Select Date',
      ],
      [
        'name'      => 'salary_declared',
        'label'     => 'Salary Declared',
        'data_type' => 'Money',
        'html_type' => 'Text',
        'is_required' => 1,
      ],
      [
        'name'      => 'status_category',
        'label'     => 'Status Category',
        'data_type' => 'String',
        'html_type' => 'Select',
        'option_values' => [
          'member'         => 'Member',
          'widow_widower'  => 'Widow/Widower',
          'other'          => 'Other',
        ],
      ],
      [
        'name'      => 'calculated_dues',
        'label'     => 'Calculated Dues',
        'data_type' => 'Money',
        'html_type' => 'Text',
        'is_view'   => 1,
      ],
      [
        'name'      => 'job_change_flag',
        'label'     => 'Job Change',
        'data_type' => 'Boolean',
        'html_type' => 'CheckBox',
        'default_value' => '0',
      ],
    ];

    foreach ($salaryFields as $fieldDef) {
      $optionValues = $fieldDef['option_values'] ?? [];
      unset($fieldDef['option_values']);
      $fieldDef['custom_group_id'] = $salaryGroupId;
      $fieldDef['is_active'] = 1;
      $created = civicrm_api3('CustomField', 'create', $fieldDef);
      if ($optionValues && !empty($created['id'])) {
        // Attach option values to the newly created option group.
        $optGroupId = civicrm_api3('CustomField', 'getsingle', ['id' => $created['id']])['option_group_id'] ?? NULL;
        if ($optGroupId) {
          $weight = 1;
          foreach ($optionValues as $val => $label) {
            civicrm_api3('OptionValue', 'create', [
              'option_group_id' => $optGroupId,
              'value'           => $val,
              'label'           => $label,
              'name'            => $val,
              'weight'          => $weight++,
              'is_active'       => 1,
            ]);
          }
        }
      }
    }
  }

  // -----------------------------------------------------------------------
  // 2. Dues Overrides (multi-record)
  // -----------------------------------------------------------------------
  $overrideGroupResult = civicrm_api3('CustomGroup', 'get', [
    'name'   => 'dues_overrides',
    'return' => ['id'],
  ]);

  if (empty($overrideGroupResult['values'])) {
    $overrideGroup = civicrm_api3('CustomGroup', 'create', [
      'name'              => 'dues_overrides',
      'title'             => 'Dues Overrides',
      'extends'           => 'Individual',
      'style'             => 'Tab with table',
      'is_multiple'       => 1,
      'collapse_adv_display' => 1,
      'is_active'         => 1,
    ]);
    $overrideGroupId = $overrideGroup['id'];

    $overrideReasonOptionGroup = civicrm_api3('OptionGroup', 'create', [
      'name'      => 'dues_override_reason',
      'title'     => 'Dues Override Reason',
      'is_active' => 1,
    ]);
    $reasonGroupId = $overrideReasonOptionGroup['id'];
    $reasons = [
      'extenuating_circumstances' => 'Extenuating Circumstances',
      'hardship'                  => 'Hardship',
      'board_decision'            => 'Board Decision',
      'other'                     => 'Other',
    ];
    $weight = 1;
    foreach ($reasons as $val => $label) {
      civicrm_api3('OptionValue', 'create', [
        'option_group_id' => $reasonGroupId,
        'value'           => $val,
        'label'           => $label,
        'name'            => $val,
        'weight'          => $weight++,
        'is_active'       => 1,
      ]);
    }

    $overrideFields = [
      [
        'name'      => 'override_year',
        'label'     => 'Override Year',
        'data_type' => 'String',
        'html_type' => 'Text',
        'help_pre'  => 'Leave blank if this is a permanent override.',
      ],
      [
        'name'      => 'override_amount',
        'label'     => 'Override Amount',
        'data_type' => 'Money',
        'html_type' => 'Text',
        'is_required' => 1,
      ],
      [
        'name'             => 'override_reason',
        'label'            => 'Override Reason',
        'data_type'        => 'String',
        'html_type'        => 'Select',
        'option_group_id'  => $reasonGroupId,
        'is_required'      => 1,
      ],
      [
        'name'      => 'reason_notes',
        'label'     => 'Reason Notes',
        'data_type' => 'Memo',
        'html_type' => 'TextArea',
      ],
      [
        'name'        => 'staff_member_id',
        'label'       => 'Set By (Staff)',
        'data_type'   => 'ContactReference',
        'html_type'   => 'Autocomplete-Select',
        'filter'      => 'action=get&group=Staff',
      ],
      [
        'name'      => 'date_set',
        'label'     => 'Date Set',
        'data_type' => 'Date',
        'html_type' => 'Select Date',
        'default_value' => date('Y-m-d'),
      ],
      [
        'name'          => 'is_permanent',
        'label'         => 'Permanent Override',
        'data_type'     => 'Boolean',
        'html_type'     => 'CheckBox',
        'default_value' => '0',
        'help_pre'      => 'If checked, this override applies to all future years.',
      ],
    ];

    foreach ($overrideFields as $fieldDef) {
      $fieldDef['custom_group_id'] = $overrideGroupId;
      $fieldDef['is_active'] = 1;
      civicrm_api3('CustomField', 'create', $fieldDef);
    }
  }
}

// =============================================================================
// FORM HOOK: Contribution Form (id = 11 – Member Dues)
// =============================================================================

/**
 * Implements hook_civicrm_buildForm().
 *
 * Modifies the Member Dues contribution form (id=11) to:
 *   - Block access if no current-year salary declaration exists.
 *   - Apply active dues overrides (permanent or year-specific).
 *   - Display read-only salary and dues amounts.
 *   - Add installment splitting UI.
 *   - Show confirmation message if dues already paid for the current year.
 */
function custom_utility_civicrm_buildForm($formName, &$form) {
  // -------------------------------------------------------------------------
  // Donation form (id=6): cosmetic fix for amount display.
  // -------------------------------------------------------------------------
  if ($formName === 'CRM_Contribute_Form_Contribution_Main' && $form->_id == 6) {
    if ($form->elementExists('price_80')) {
      $amounts   = &$form->getElement('price_80');
      $elements  = &$amounts->getElements();
      foreach ($elements as $key => $val) {
        $text = $elements[$key]->getText();
        if ($text !== 'Other Amount') {
          $text = str_replace(' ', '', $text);
        }
        else {
          $elements[$key]->setValue(1);
        }
        $elements[$key]->setText($text);
      }
    }
    return;
  }

  // -------------------------------------------------------------------------
  // Member Dues form (id=11).
  // -------------------------------------------------------------------------
  if ($formName !== 'CRM_Contribute_Form_Contribution_Main' || $form->_id != 11) {
    return;
  }

  $contactId = _custom_utility_get_logged_in_contact_id();
  if (!$contactId) {
    return;
  }

  $duesYear = DuesCalculator::getDuesYear();

  // ----- Rename submit button -----------------------------------------------
  if ($form->elementExists('buttons')) {
    $buttons = &$form->getElement('buttons');
    foreach ($buttons->_elements as $e) {
      if (isset($e->_attributes['type']) && $e->_attributes['type'] === 'submit') {
        $e->setValue('Confirm Payment');
      }
    }
  }

  // ----- Check whether member has already paid this year --------------------
  $existingContribution = _custom_utility_get_paid_contribution($contactId, $duesYear);
  if ($existingContribution) {
    CRM_Core_Session::setStatus(
      ts('You have already paid dues for %1. Amount: %2. If you need to update your salary or status, <a href="%3">click here</a>.',
        [
          1 => $duesYear,
          2 => CRM_Utils_Money::format($existingContribution['total_amount']),
          3 => CRM_Utils_System::url('member-dues-declaration'),
        ]
      ),
      ts('Dues Already Paid'),
      'info'
    );
    // Hide the payment form elements so the page shows only the message.
    _custom_utility_hide_payment_elements($form);
    return;
  }

  // ----- Load current-year salary declaration --------------------------------
  $declaration = _custom_utility_get_declaration($contactId, $duesYear);

  if (empty($declaration)) {
    // No declaration: block access and redirect member to declaration page.
    $declarationUrl = CRM_Utils_System::url('member-dues-declaration');
    CRM_Core_Session::setStatus(
      ts('You must complete your salary declaration before paying dues. <a href="%1">Click here to complete your declaration.</a>', [1 => $declarationUrl]),
      ts('Declaration Required'),
      'error'
    );
    _custom_utility_hide_payment_elements($form);
    return;
  }

  // ----- Determine effective dues amount (check overrides) ------------------
  $calculatedDues = $declaration['calculated_dues'] ?? 0;
  $effectiveDues  = $calculatedDues;
  $hasOverride    = FALSE;
  $overrideReason = '';

  $override = _custom_utility_get_active_override($contactId, $duesYear);
  if ($override) {
    $effectiveDues  = $override['override_amount'];
    $hasOverride    = TRUE;
    $overrideReason = $override['override_reason'] ?? '';
  }

  // ----- Inject read-only salary / dues display into the form ---------------
  $salaryFormatted = CRM_Utils_Money::format($declaration['salary_declared'] ?? 0);
  $duesFormatted   = CRM_Utils_Money::format($effectiveDues);
  $duesClass       = $hasOverride ? 'ra-dues-overridden' : 'ra-dues-standard';
  $overrideNote    = $hasOverride ? ' <span class="ra-dues-override-badge">(override applied)</span>' : '';

  $form->assign('raDuesYear', $duesYear);
  $form->assign('raSalaryDeclared', $salaryFormatted);
  $form->assign('raDuesAmount', $duesFormatted);
  $form->assign('raDuesClass', $duesClass);
  $form->assign('raHasOverride', $hasOverride);
  $form->assign('raOverrideNote', $overrideNote);
  $form->assign('raDeclarationUrl', CRM_Utils_System::url('member-dues-declaration'));

  // Pre-populate the contribution amount with effective dues balance.
  $previousData     = _custom_utility_get_previous_dues_data($contactId, $duesYear);
  $duesPaid         = $previousData['dues_paid'] ?? 0;
  $duesBalance      = $effectiveDues - $duesPaid;
  $duesBalance      = max($duesBalance, 0);

  if ($form->elementExists('price_275')) {
    $contributionAmount = &$form->getElement('price_275');
    $contributionAmount->setValue($duesBalance > 0 ? $duesBalance : 0);
  }

  // ----- Installment splitting UI -------------------------------------------
  $customRecurOptions = [
    1 => ts("I'd like to make a single payment of %1", [1 => CRM_Utils_Money::format($duesBalance)]),
    2 => ts('Please divide my payments into'),
    3 => ts('Other amount'),
  ];
  $recurOptions = [];
  foreach ($customRecurOptions as $key => $label) {
    $recurOptions[$key] = $form->createElement('radio', NULL, ts('Payment Option'), $label, $key);
  }
  $form->addGroup($recurOptions, 'recurrence_options', ts('Payment Options'));
  $form->setDefaults(['recurrence_options' => 1]);
  $form->add('text', 'recur_installment', ts('Number of Installments'));
  $form->add('text', 'recur_other', ts('Other Amount'));

  // Attach dues-specific CSS and JS.
  CRM_Core_Resources::singleton()->addStyleFile('custom_utility', 'css/ra_dues.css');
  CRM_Core_Resources::singleton()->addScriptFile('custom_utility', 'js/ra_dues.js');
  CRM_Core_Resources::singleton()->addVars('raDues', [
    'effectiveDues' => $effectiveDues,
    'duesPaid'      => $duesPaid,
    'duesBalance'   => $duesBalance,
    'hasOverride'   => $hasOverride,
    'duesYear'      => $duesYear,
  ]);
}

// =============================================================================
// TABSET HOOK: Add "Dues Management" tab to contact record
// =============================================================================

/**
 * Implements hook_civicrm_tabset().
 *
 * Adds a "Dues Management" tab to the CiviCRM contact record summary page,
 * visible only to users with the 'administer CiviCRM' permission.
 */
function custom_utility_civicrm_tabset($tabsetName, &$tabs, $context) {
  if ($tabsetName !== 'civicrm/contact/view') {
    return;
  }
  if (!CRM_Core_Permission::check('administer CiviCRM')) {
    return;
  }
  $contactId = $context['contact_id'] ?? NULL;
  if (!$contactId) {
    return;
  }

  $tabs[] = [
    'id'    => 'dues_management',
    'url'   => CRM_Utils_System::url('civicrm/contact/dues-management', "reset=1&cid={$contactId}"),
    'title' => ts('Dues Management'),
    'weight' => 200,
    'count'  => _custom_utility_get_override_count($contactId),
  ];
}

// =============================================================================
// NAVIGATION MENU HOOK
// =============================================================================

/**
 * Implements hook_civicrm_navigationMenu().
 *
 * Adds a "Dues Management" entry under the CiviCRM Contributions menu.
 */
function custom_utility_civicrm_navigationMenu(&$menu) {
  // Find the Contributions parent key.
  $contributionsKey = NULL;
  foreach ($menu as $key => $item) {
    if (($item['attributes']['name'] ?? '') === 'Contributions') {
      $contributionsKey = $key;
      break;
    }
  }

  $newItem = [
    'attributes' => [
      'label'      => ts('Dues Override Management'),
      'name'       => 'Dues Override Management',
      'url'        => 'civicrm/dues/override',
      'permission' => 'administer CiviCRM',
      'operator'   => NULL,
      'separator'  => NULL,
      'parentID'   => $contributionsKey,
      'navID'      => max(array_keys($menu)) + 1,
      'active'     => 1,
    ],
  ];
  if ($contributionsKey !== NULL) {
    $menu[$contributionsKey]['child'][] = $newItem;
  }
  else {
    $menu[] = $newItem;
  }
}

// =============================================================================
// HELPER FUNCTIONS
// =============================================================================

/**
 * Returns the CiviCRM contact ID for the currently logged-in user.
 *
 * @return int|null
 */
function _custom_utility_get_logged_in_contact_id() {
  $session = CRM_Core_Session::singleton();
  return $session->getLoggedInContactID() ?: NULL;
}

/**
 * Returns the dues year string (e.g. "2026-2027") for which a completed
 * contribution already exists for the given contact.
 *
 * @param int    $contactId
 * @param string $duesYear   e.g. "2026-2027"
 *
 * @return array|null  Contribution values array, or NULL if not found.
 */
function _custom_utility_get_paid_contribution($contactId, $duesYear) {
  try {
    $result = civicrm_api3('Contribution', 'get', [
      'sequential'             => 1,
      'contact_id'             => $contactId,
      'contribution_status_id' => 'Completed',
      'contribution_source'    => ['LIKE' => "%Member Dues%{$duesYear}%"],
      'return'                 => ['id', 'total_amount', 'receive_date'],
      'options'                => ['limit' => 1],
    ]);
    if (!empty($result['values'])) {
      return $result['values'][0];
    }
  }
  catch (CiviCRM_API3_Exception $e) {
    CRM_Core_Error::debug_log_message('custom_utility: _custom_utility_get_paid_contribution: ' . $e->getMessage());
  }
  return NULL;
}

/**
 * Loads the salary declaration record for a contact for the given year.
 *
 * @param int    $contactId
 * @param string $duesYear
 *
 * @return array|null  Custom-field values keyed by field name, or NULL.
 */
function _custom_utility_get_declaration($contactId, $duesYear) {
  try {
    $groupId = _custom_utility_get_custom_group_id('salary_declarations');
    if (!$groupId) {
      return NULL;
    }
    $fields    = _custom_utility_get_custom_field_map('salary_declarations');
    $yearField = 'custom_' . ($fields['declaration_year'] ?? 0);

    $result = civicrm_api3('CustomValue', 'get', [
      'entity_id'    => $contactId,
      'entity_table' => 'civicrm_contact',
      $yearField     => $duesYear,
    ]);

    if (!empty($result['values'])) {
      return _custom_utility_map_declaration_values($result['values'], $fields);
    }
  }
  catch (CiviCRM_API3_Exception $e) {
    CRM_Core_Error::debug_log_message('custom_utility: _custom_utility_get_declaration: ' . $e->getMessage());
  }
  return NULL;
}

/**
 * Returns the active override record for a contact in the given dues year.
 *
 * Precedence: permanent override > year-specific override.
 *
 * @param int    $contactId
 * @param string $duesYear
 *
 * @return array|null  Override values, or NULL if no active override.
 */
function _custom_utility_get_active_override($contactId, $duesYear) {
  try {
    $fields = _custom_utility_get_custom_field_map('dues_overrides');
    if (empty($fields)) {
      return NULL;
    }

    $permanentField = 'custom_' . ($fields['is_permanent'] ?? 0);
    $yearField      = 'custom_' . ($fields['override_year'] ?? 0);

    // Check permanent override first.
    $permanent = civicrm_api3('CustomValue', 'get', [
      'entity_id'      => $contactId,
      'entity_table'   => 'civicrm_contact',
      $permanentField  => 1,
    ]);
    if (!empty($permanent['values'])) {
      return _custom_utility_map_override_values(reset($permanent['values']), $fields);
    }

    // Check year-specific override.
    $yearSpecific = civicrm_api3('CustomValue', 'get', [
      'entity_id'    => $contactId,
      'entity_table' => 'civicrm_contact',
      $yearField     => $duesYear,
    ]);
    if (!empty($yearSpecific['values'])) {
      return _custom_utility_map_override_values(reset($yearSpecific['values']), $fields);
    }
  }
  catch (CiviCRM_API3_Exception $e) {
    CRM_Core_Error::debug_log_message('custom_utility: _custom_utility_get_active_override: ' . $e->getMessage());
  }
  return NULL;
}

/**
 * Returns the count of override records for a contact (used for tab badge).
 *
 * @param int $contactId
 *
 * @return int
 */
function _custom_utility_get_override_count($contactId) {
  try {
    $result = civicrm_api3('CustomValue', 'get', [
      'entity_id'    => $contactId,
      'entity_table' => 'civicrm_contact',
      'return'       => ['id'],
      'options'      => ['limit' => 0],
    ]);
    // Count only records in the dues_overrides group.
    $groupId = _custom_utility_get_custom_group_id('dues_overrides');
    return $groupId ? (int) ($result['count'] ?? 0) : 0;
  }
  catch (CiviCRM_API3_Exception $e) {
    return 0;
  }
}

/**
 * Retrieves aggregated prior-year dues data for display on the payment form.
 *
 * @param int    $contactId
 * @param string $currentDuesYear
 *
 * @return array  ['dues_paid' => float]
 */
function _custom_utility_get_previous_dues_data($contactId, $currentDuesYear) {
  $data = ['dues_paid' => 0];
  try {
    $contributions = civicrm_api3('Contribution', 'get', [
      'sequential'             => 1,
      'contact_id'             => $contactId,
      'contribution_status_id' => 'Completed',
      'options'                => ['limit' => 0],
      'return'                 => ['id', 'total_amount', 'contribution_source'],
    ]);
    foreach ($contributions['values'] as $c) {
      if (strpos($c['contribution_source'] ?? '', $currentDuesYear) !== FALSE
        && strpos($c['contribution_source'] ?? '', 'Member Dues') !== FALSE
      ) {
        $data['dues_paid'] += (float) $c['total_amount'];
      }
    }
  }
  catch (CiviCRM_API3_Exception $e) {
    CRM_Core_Error::debug_log_message('custom_utility: _custom_utility_get_previous_dues_data: ' . $e->getMessage());
  }
  return $data;
}

/**
 * Hides the core payment form elements when access should be blocked.
 *
 * @param CRM_Core_Form $form
 */
function _custom_utility_hide_payment_elements(&$form) {
  // Freeze/remove the submit button group so the form cannot be submitted.
  if ($form->elementExists('buttons')) {
    $form->removeElement('buttons');
  }
  if ($form->elementExists('_qf_Main_upload')) {
    $form->freeze('_qf_Main_upload');
  }
}

/**
 * Returns the internal ID of a custom group by its machine name.
 *
 * @param string $name
 *
 * @return int|null
 */
function _custom_utility_get_custom_group_id($name) {
  static $cache = [];
  if (!isset($cache[$name])) {
    try {
      $result       = civicrm_api3('CustomGroup', 'getsingle', ['name' => $name, 'return' => ['id']]);
      $cache[$name] = $result['id'] ?? NULL;
    }
    catch (CiviCRM_API3_Exception $e) {
      $cache[$name] = NULL;
    }
  }
  return $cache[$name];
}

/**
 * Returns a map of [field_name => custom_field_id] for all fields in a group.
 *
 * @param string $groupName
 *
 * @return array
 */
function _custom_utility_get_custom_field_map($groupName) {
  static $cache = [];
  if (!isset($cache[$groupName])) {
    $cache[$groupName] = [];
    $groupId = _custom_utility_get_custom_group_id($groupName);
    if (!$groupId) {
      return $cache[$groupName];
    }
    try {
      $fields = civicrm_api3('CustomField', 'get', [
        'custom_group_id' => $groupId,
        'return'          => ['id', 'name'],
        'options'         => ['limit' => 0],
      ]);
      foreach ($fields['values'] as $field) {
        $cache[$groupName][$field['name']] = $field['id'];
      }
    }
    catch (CiviCRM_API3_Exception $e) {
      CRM_Core_Error::debug_log_message('custom_utility: _custom_utility_get_custom_field_map: ' . $e->getMessage());
    }
  }
  return $cache[$groupName];
}

/**
 * Maps raw CustomValue API results to a keyed declaration array.
 *
 * @param array $rawValues
 * @param array $fields     Field name → ID map.
 *
 * @return array
 */
function _custom_utility_map_declaration_values($rawValues, $fields) {
  $mapped = [];
  $idToName = array_flip($fields);
  foreach ($rawValues as $customFieldId => $valueRow) {
    $fieldName = $idToName[$customFieldId] ?? NULL;
    if ($fieldName) {
      $mapped[$fieldName] = $valueRow['latest'] ?? $valueRow[0] ?? NULL;
    }
  }
  return $mapped;
}

/**
 * Maps a raw override CustomValue row to a keyed array.
 *
 * @param array $rawRow
 * @param array $fields
 *
 * @return array
 */
function _custom_utility_map_override_values($rawRow, $fields) {
  $mapped   = [];
  $idToName = array_flip($fields);
  foreach ($rawRow as $customFieldId => $value) {
    $fieldName = $idToName[$customFieldId] ?? NULL;
    if ($fieldName) {
      $mapped[$fieldName] = $value;
    }
  }
  return $mapped;
}
