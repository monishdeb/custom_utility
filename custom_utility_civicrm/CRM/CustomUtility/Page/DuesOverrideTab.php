<?php

/**
 * @file
 * Contact-record tab page displaying salary declarations, calculated dues,
 * effective dues, and all override records for a member.
 */

use CRM_CustomUtility_Utils_DuesCalculator as DuesCalculator;

/**
 * Page: Dues Management tab on a contact record.
 */
class CRM_CustomUtility_Page_DuesOverrideTab extends CRM_Core_Page {

  public function run() {
    $contactId = CRM_Utils_Request::retrieve('cid', 'Positive', $this, TRUE);

    if (!CRM_Core_Permission::check('administer CiviCRM')) {
      CRM_Core_Error::statusBounce(ts('You do not have permission to view dues management information.'));
    }

    $duesYear = DuesCalculator::getCurrentDuesYear();
    $this->assign('dues_year', $duesYear);
    $this->assign('contact_id', $contactId);

    // ------------------------------------------------------------------
    // Current declaration summary.
    // ------------------------------------------------------------------
    $declaration = $this->_getCurrentDeclaration($contactId, $duesYear);
    $this->assign('declaration', $declaration);

    // ------------------------------------------------------------------
    // Effective dues (calculated or overridden).
    // ------------------------------------------------------------------
    $effective = DuesCalculator::getEffectiveDues($contactId, $duesYear);
    $this->assign('effective_dues', $effective);

    // ------------------------------------------------------------------
    // All override records (historical + current).
    // ------------------------------------------------------------------
    $overrides = $this->_getAllOverrides($contactId);
    $this->assign('overrides', $overrides);

    // ------------------------------------------------------------------
    // Add override URL.
    // ------------------------------------------------------------------
    $addOverrideUrl = CRM_Utils_System::url(
      'civicrm/contact/dues-management/override',
      'cid=' . $contactId . '&reset=1'
    );
    $this->assign('add_override_url', $addOverrideUrl);

    // Attach assets.
    CRM_Core_Resources::singleton()->addStyleFile('custom_utility_civicrm', 'css/ra_dues.css');
    CRM_Core_Resources::singleton()->addScriptFile('custom_utility_civicrm', 'js/ra_dues.js');

    parent::run();
  }

  // ---------------------------------------------------------------------------
  // Private helpers
  // ---------------------------------------------------------------------------

  /**
   * Returns the current-year salary declaration row for a contact.
   *
   * @param int    $contactId
   * @param string $duesYear
   *
   * @return array|null
   */
  private function _getCurrentDeclaration($contactId, $duesYear) {
    try {
      $group = civicrm_api3('CustomGroup', 'getsingle', [
        'name'   => 'salary_declarations',
        'return' => ['id', 'table_name'],
      ]);
      $tableName = $group['table_name'];

      $fields = civicrm_api3('CustomField', 'get', [
        'custom_group_id' => $group['id'],
        'return'          => ['name', 'column_name'],
        'options'         => ['limit' => 0],
      ]);
      $fm = [];
      foreach ($fields['values'] as $f) {
        $fm[$f['name']] = $f['column_name'];
      }

      $row = CRM_Core_DAO::executeQuery(
        "SELECT {$fm['declaration_year']}  AS declaration_year,
                {$fm['declaration_date']}  AS declaration_date,
                {$fm['salary_declared']}   AS salary_declared,
                {$fm['status_category']}   AS status_category,
                {$fm['calculated_dues']}   AS calculated_dues,
                {$fm['job_change_flag']}   AS job_change_flag
         FROM   {$tableName}
         WHERE  entity_id = %1
           AND  {$fm['declaration_year']} = %2
         LIMIT  1",
        [1 => [$contactId, 'Integer'], 2 => [$duesYear, 'String']]
      );

      if ($row->fetch()) {
        return [
          'declaration_year' => $row->declaration_year,
          'declaration_date' => $row->declaration_date,
          'salary_declared'  => $row->salary_declared,
          'status_category'  => $row->status_category,
          'calculated_dues'  => $row->calculated_dues,
          'job_change_flag'  => $row->job_change_flag,
        ];
      }
    }
    catch (Exception $e) {
      CRM_Core_Error::debug_log_message('DuesOverrideTab: ' . $e->getMessage());
    }
    return NULL;
  }

  /**
   * Returns all override records for a contact, newest first.
   *
   * @param int $contactId
   *
   * @return array
   */
  private function _getAllOverrides($contactId) {
    $overrides = [];
    try {
      $group = civicrm_api3('CustomGroup', 'getsingle', [
        'name'   => 'dues_overrides',
        'return' => ['id', 'table_name'],
      ]);
      $tableName = $group['table_name'];

      $fields = civicrm_api3('CustomField', 'get', [
        'custom_group_id' => $group['id'],
        'return'          => ['name', 'column_name'],
        'options'         => ['limit' => 0],
      ]);
      $fm = [];
      foreach ($fields['values'] as $f) {
        $fm[$f['name']] = $f['column_name'];
      }

      $dao = CRM_Core_DAO::executeQuery(
        "SELECT id,
                {$fm['override_year']}    AS override_year,
                {$fm['override_amount']}  AS override_amount,
                {$fm['override_reason']}  AS override_reason,
                {$fm['reason_notes']}     AS reason_notes,
                {$fm['staff_member_id']}  AS staff_member_id,
                {$fm['date_set']}         AS date_set,
                {$fm['is_permanent']}     AS is_permanent
         FROM   {$tableName}
         WHERE  entity_id = %1
         ORDER  BY {$fm['date_set']} DESC",
        [1 => [$contactId, 'Integer']]
      );

      while ($dao->fetch()) {
        // Resolve staff member name.
        $staffName = '';
        if (!empty($dao->staff_member_id)) {
          try {
            $staff = civicrm_api3('Contact', 'getsingle', [
              'id'     => $dao->staff_member_id,
              'return' => ['display_name'],
            ]);
            $staffName = $staff['display_name'];
          }
          catch (Exception $e) {
            // Ignore.
          }
        }

        $overrides[] = [
          'id'              => $dao->id,
          'override_year'   => $dao->is_permanent ? ts('Permanent') : $dao->override_year,
          'override_amount' => $dao->override_amount,
          'override_reason' => $dao->override_reason,
          'reason_notes'    => $dao->reason_notes,
          'staff_name'      => $staffName,
          'date_set'        => $dao->date_set,
          'is_permanent'    => $dao->is_permanent,
        ];
      }
    }
    catch (Exception $e) {
      CRM_Core_Error::debug_log_message('DuesOverrideTab: ' . $e->getMessage());
    }
    return $overrides;
  }

}
