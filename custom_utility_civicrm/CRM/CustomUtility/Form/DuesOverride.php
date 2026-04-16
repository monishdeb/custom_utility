<?php

/**
 * @file
 * Form for adding or editing a dues override on a contact record.
 */

/**
 * Form class: Add / edit a dues override for a member contact.
 */
class CRM_CustomUtility_Form_DuesOverride extends CRM_Core_Form {

  /**
   * Contact ID for which the override is being managed.
   *
   * @var int
   */
  protected $_contactId;

  /**
   * ID of an existing override record being edited (NULL for new records).
   *
   * @var int|null
   */
  protected $_overrideId;

  // ---------------------------------------------------------------------------
  // Lifecycle
  // ---------------------------------------------------------------------------

  public function preProcess() {
    parent::preProcess();

    $this->_contactId = CRM_Utils_Request::retrieve('cid', 'Positive', $this, TRUE);
    $this->_overrideId = CRM_Utils_Request::retrieve('override_id', 'Positive', $this, FALSE);

    if (!CRM_Core_Permission::check('administer CiviCRM')) {
      CRM_Core_Error::statusBounce(ts('You do not have permission to manage dues overrides.'));
    }
  }

  public function buildQuickForm() {
    $this->setTitle(ts('Set Dues Override'));

    // Read-only: contact display name.
    try {
      $contact = civicrm_api3('Contact', 'getsingle', [
        'id'     => $this->_contactId,
        'return' => ['display_name'],
      ]);
      $this->assign('contact_name', $contact['display_name']);
    }
    catch (Exception $e) {
      $this->assign('contact_name', '');
    }
    $this->assign('contact_id', $this->_contactId);

    // Hidden contact ID.
    $this->add('hidden', 'contact_id', $this->_contactId);

    // Is Permanent checkbox.
    $this->add('checkbox', 'is_permanent', ts('Permanent Override (applies to all years)'));

    // Override Year (for year-specific overrides).
    $yearOptions = $this->_buildYearOptions();
    $this->add('select', 'override_year', ts('Override Year'), ['' => ts('- Select Year -')] + $yearOptions);

    // Override Amount.
    $this->add('text', 'override_amount', ts('Override Amount'), ['size' => 12], TRUE);
    $this->addRule('override_amount', ts('Please enter a valid amount.'), 'money');

    // Override Reason.
    $reasonOptions = [
      ''                          => ts('- Select Reason -'),
      'extenuating_circumstances' => ts('Extenuating Circumstances'),
      'hardship'                  => ts('Hardship'),
      'board_decision'            => ts('Board Decision'),
      'other'                     => ts('Other'),
    ];
    $this->add('select', 'override_reason', ts('Reason'), $reasonOptions, TRUE);

    // Reason Notes.
    $this->add('textarea', 'reason_notes', ts('Notes'), ['rows' => 4, 'cols' => 50]);

    // Date Set (read-only default today).
    $this->addDate('date_set', ts('Date Set'), TRUE);
    $this->setDefaults(['date_set' => date('m/d/Y')]);

    // Staff member (auto-filled from current user, read-only display).
    $staffId = CRM_Core_Session::getLoggedInContactID();
    $staffName = '';
    if ($staffId) {
      try {
        $staffContact = civicrm_api3('Contact', 'getsingle', [
          'id'     => $staffId,
          'return' => ['display_name'],
        ]);
        $staffName = $staffContact['display_name'];
      }
      catch (Exception $e) {
        // Ignore.
      }
    }
    $this->add('hidden', 'staff_member_id', $staffId);
    $this->assign('staff_name', $staffName);

    // Buttons.
    $this->addButtons([
      ['type' => 'submit', 'name' => ts('Set Override'), 'isDefault' => TRUE],
      ['type' => 'cancel', 'name' => ts('Cancel')],
    ]);

    parent::buildQuickForm();
  }

  public function validate() {
    $values = $this->exportValues();

    if (empty($values['is_permanent']) && empty($values['override_year'])) {
      $this->_errors['override_year'] = ts('Please select a year for a year-specific override, or check Permanent Override.');
    }

    return parent::validate();
  }

  public function postProcess() {
    $values = $this->exportValues();

    $contactId    = $this->_contactId;
    $isPermanent  = !empty($values['is_permanent']) ? 1 : 0;
    $overrideYear = $isPermanent ? '' : CRM_Utils_Array::value('override_year', $values, '');
    $amount       = CRM_Utils_Rule::cleanMoney($values['override_amount']);
    $reason       = $values['override_reason'];
    $notes        = CRM_Utils_Array::value('reason_notes', $values, '');
    $staffId      = CRM_Utils_Array::value('staff_member_id', $values, CRM_Core_Session::getLoggedInContactID());
    $dateSet      = CRM_Utils_Date::processDate($values['date_set'], NULL, FALSE, 'Y-m-d');

    try {
      $group = civicrm_api3('CustomGroup', 'getsingle', ['name' => 'dues_overrides', 'return' => ['id']]);
      $groupId = $group['id'];

      $fields = civicrm_api3('CustomField', 'get', [
        'custom_group_id' => $groupId,
        'return'          => ['id', 'name'],
        'options'         => ['limit' => 0],
      ]);
      $fieldMap = [];
      foreach ($fields['values'] as $f) {
        $fieldMap[$f['name']] = 'custom_' . $f['id'];
      }

      $params = [
        'entity_id'                      => $contactId,
        $fieldMap['override_year']       => $overrideYear,
        $fieldMap['override_amount']     => $amount,
        $fieldMap['override_reason']     => $reason,
        $fieldMap['reason_notes']        => $notes,
        $fieldMap['staff_member_id']     => $staffId,
        $fieldMap['date_set']            => $dateSet,
        $fieldMap['is_permanent']        => $isPermanent,
      ];

      if ($this->_overrideId) {
        $params['id'] = $this->_overrideId;
      }

      civicrm_api3('CustomValue', 'create', $params);

      CRM_Core_Session::setStatus(
        ts('Dues override saved successfully.'),
        ts('Override Saved'),
        'success'
      );
    }
    catch (Exception $e) {
      CRM_Core_Error::debug_log_message('DuesOverride postProcess: ' . $e->getMessage());
      CRM_Core_Session::setStatus(
        ts('Failed to save override: %1', [1 => $e->getMessage()]),
        ts('Error'),
        'error'
      );
    }

    $url = CRM_Utils_System::url('civicrm/contact/dues-management', 'cid=' . $contactId . '&reset=1');
    CRM_Utils_System::redirect($url);
  }

  // ---------------------------------------------------------------------------
  // Helpers
  // ---------------------------------------------------------------------------

  /**
   * Builds a list of upcoming fiscal years for the Override Year select.
   *
   * @return array  [value => label, ...]
   */
  private function _buildYearOptions() {
    $options = [];
    $baseYear = (int) date('Y') - 1;
    for ($i = 0; $i <= 5; $i++) {
      $from = $baseYear + $i;
      $to   = $from + 1;
      $key  = "{$from}-{$to}";
      $options[$key] = $key;
    }
    return $options;
  }

}
