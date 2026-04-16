<?php

/**
 * @file
 * CiviCRM form for adding / editing a dues override on a contact record.
 *
 * Route (defined in xml/Menu/duesmanagement.xml):
 *   civicrm/dues/override/add?cid=<contact_id>
 *   civicrm/dues/override/edit?cid=<contact_id>&id=<record_id>
 */

use CRM_CustomUtility_Utils_DuesCalculator as DuesCalculator;

class CRM_CustomUtility_Form_DuesOverride extends CRM_Core_Form {

  /**
   * CiviCRM contact ID being overridden.
   *
   * @var int
   */
  protected $_contactId;

  /**
   * ID of an existing override record being edited (NULL for new records).
   *
   * @var int|null
   */
  protected $_recordId;

  /**
   * {@inheritdoc}
   */
  public function preProcess() {
    parent::preProcess();

    if (!CRM_Core_Permission::check('administer CiviCRM')) {
      CRM_Core_Error::fatal(ts('You do not have permission to manage dues overrides.'));
    }

    $this->_contactId = CRM_Utils_Request::retrieve('cid', 'Positive', $this, TRUE);
    $this->_recordId  = CRM_Utils_Request::retrieve('id', 'Positive', $this, FALSE);

    $this->assign('contactId', $this->_contactId);
    $this->assign('isEdit', !empty($this->_recordId));

    // Set page title.
    $contactName = civicrm_api3('Contact', 'getvalue', ['id' => $this->_contactId, 'return' => 'display_name']);
    CRM_Utils_System::setTitle(ts('Dues Override for %1', [1 => $contactName]));
  }

  /**
   * {@inheritdoc}
   */
  public function buildQuickForm() {
    // Contact display (read-only).
    $this->add('static', 'contact_display', ts('Contact'), $this->_contactId);

    // Permanent override checkbox.
    $this->add('checkbox', 'is_permanent', ts('Permanent Override (applies to all future years)'));

    // Override year (only active when not permanent).
    $this->add('text', 'override_year', ts('Override Year (e.g. 2026-2027)'), ['maxlength' => 20]);
    $this->addRule('override_year', ts('Override year should be in the format YYYY-YYYY.'), 'regex', '/^\d{4}-\d{4}$/');

    // Override amount.
    $this->add('text', 'override_amount', ts('Override Amount'), ['size' => 12], TRUE);
    $this->addRule('override_amount', ts('Please enter a valid monetary amount.'), 'money');

    // Reason.
    $reasonOptions = [
      ''                          => ts('-- Select --'),
      'extenuating_circumstances' => ts('Extenuating Circumstances'),
      'hardship'                  => ts('Hardship'),
      'board_decision'            => ts('Board Decision'),
      'other'                     => ts('Other'),
    ];
    $this->add('select', 'override_reason', ts('Reason'), $reasonOptions, TRUE);

    // Notes.
    $this->add('textarea', 'reason_notes', ts('Notes'), ['rows' => 4, 'cols' => 50]);

    // Date set.
    $this->addDate('date_set', ts('Date Set'), FALSE, ['formatType' => 'activityDate']);

    // Staff member (contact reference).
    $this->addEntityRef('staff_member_id', ts('Set By (Staff)'), ['api' => ['extra' => ['email']]]);

    $this->addButtons([
      ['type' => 'submit', 'name' => ts('Save Override'), 'isDefault' => TRUE],
      ['type' => 'cancel', 'name' => ts('Cancel')],
    ]);

    // Pre-populate for edit.
    if ($this->_recordId) {
      $defaults = $this->_loadExistingRecord();
      $this->setDefaults($defaults);
    }
    else {
      $this->setDefaults([
        'date_set'     => date('m/d/Y'),
        'staff_member_id' => CRM_Core_Session::singleton()->getLoggedInContactID(),
      ]);
    }

    parent::buildQuickForm();
  }

  /**
   * {@inheritdoc}
   */
  public function validate() {
    $values = $this->exportValues();

    // If not a permanent override, override_year is required.
    if (empty($values['is_permanent']) && empty($values['override_year'])) {
      $this->_errors['override_year'] = ts('Please enter the override year, or check "Permanent Override".');
    }

    return parent::validate();
  }

  /**
   * {@inheritdoc}
   */
  public function postProcess() {
    $values = $this->exportValues();

    $fields = _custom_utility_get_custom_field_map('dues_overrides');

    $params = [
      'entity_id'    => $this->_contactId,
      'entity_table' => 'civicrm_contact',
    ];

    $fieldMap = [
      'override_year'   => $values['is_permanent'] ? '' : ($values['override_year'] ?? ''),
      'override_amount' => CRM_Utils_Rule::cleanMoney($values['override_amount']),
      'override_reason' => $values['override_reason'],
      'reason_notes'    => $values['reason_notes'] ?? '',
      'staff_member_id' => $values['staff_member_id'] ?? CRM_Core_Session::singleton()->getLoggedInContactID(),
      'date_set'        => CRM_Utils_Date::processDate($values['date_set'], NULL, FALSE, 'Y-m-d'),
      'is_permanent'    => !empty($values['is_permanent']) ? 1 : 0,
    ];

    foreach ($fieldMap as $name => $value) {
      if (isset($fields[$name])) {
        $params['custom_' . $fields[$name]] = $value;
      }
    }

    // Include record ID when editing.
    if ($this->_recordId) {
      $params['id'] = $this->_recordId;
    }

    try {
      civicrm_api3('CustomValue', 'create', $params);

      CRM_Core_Session::setStatus(
        ts('Dues override saved successfully.'),
        ts('Saved'),
        'success'
      );
    }
    catch (CiviCRM_API3_Exception $e) {
      CRM_Core_Session::setStatus(
        ts('Error saving override: %1', [1 => $e->getMessage()]),
        ts('Error'),
        'error'
      );
    }

    // Redirect back to the Dues Management tab.
    $url = CRM_Utils_System::url(
      'civicrm/contact/dues-management',
      "reset=1&cid={$this->_contactId}"
    );
    CRM_Utils_System::redirect($url);
  }

  /**
   * Loads existing override record values for pre-population on edit.
   *
   * @return array
   */
  protected function _loadExistingRecord() {
    $defaults = [];
    $fields   = _custom_utility_get_custom_field_map('dues_overrides');
    $idToName = array_flip($fields);

    try {
      $result = civicrm_api3('CustomValue', 'get', [
        'entity_id'    => $this->_contactId,
        'entity_table' => 'civicrm_contact',
        'id'           => $this->_recordId,
      ]);
      foreach ($result['values'] ?? [] as $fieldId => $row) {
        $name = $idToName[$fieldId] ?? NULL;
        if ($name) {
          $defaults[$name] = $row['latest'] ?? $row[0] ?? NULL;
        }
      }
    }
    catch (CiviCRM_API3_Exception $e) {
      CRM_Core_Error::debug_log_message('DuesOverride::_loadExistingRecord: ' . $e->getMessage());
    }

    return $defaults;
  }

}
