<?php

/**
 * @file
 * CiviCRM page controller for the "Dues Management" contact record tab.
 *
 * Route (defined in xml/Menu/duesmanagement.xml):
 *   civicrm/contact/dues-management?reset=1&cid=<contact_id>
 *
 * Displays:
 *   - Current active salary declaration (read-only).
 *   - Calculated dues and any active override.
 *   - Complete list of all override records (with edit links).
 *   - "Add Override" action link.
 */

use CRM_CustomUtility_Utils_DuesCalculator as DuesCalculator;

class CRM_CustomUtility_Page_DuesOverrideTab extends CRM_Core_Page {

  /**
   * {@inheritdoc}
   */
  public function run() {
    if (!CRM_Core_Permission::check('administer CiviCRM')) {
      CRM_Core_Error::fatal(ts('You do not have permission to view this page.'));
    }

    $contactId = CRM_Utils_Request::retrieve('cid', 'Positive', $this, TRUE);
    $this->assign('contactId', $contactId);

    // ------------------------------------------------------------------
    // Contact summary.
    // ------------------------------------------------------------------
    try {
      $contact = civicrm_api3('Contact', 'getsingle', [
        'id'     => $contactId,
        'return' => ['display_name', 'contact_type'],
      ]);
      $this->assign('contactName', $contact['display_name'] ?? '');
      CRM_Utils_System::setTitle(ts('Dues Management – %1', [1 => $contact['display_name']]));
    }
    catch (CiviCRM_API3_Exception $e) {
      CRM_Core_Error::fatal(ts('Contact not found.'));
    }

    $duesYear = DuesCalculator::getDuesYear();
    $this->assign('duesYear', $duesYear);

    // ------------------------------------------------------------------
    // Current-year salary declaration.
    // ------------------------------------------------------------------
    $declaration = _custom_utility_get_declaration($contactId, $duesYear);
    $this->assign('declaration', $declaration);

    // ------------------------------------------------------------------
    // Active override (permanent or year-specific).
    // ------------------------------------------------------------------
    $activeOverride = _custom_utility_get_active_override($contactId, $duesYear);
    $this->assign('activeOverride', $activeOverride);

    // Effective dues amount.
    $calculatedDues = $declaration['calculated_dues'] ?? 0;
    $effectiveDues  = $activeOverride ? $activeOverride['override_amount'] : $calculatedDues;
    $this->assign('calculatedDues', CRM_Utils_Money::format($calculatedDues));
    $this->assign('effectiveDues', CRM_Utils_Money::format($effectiveDues));
    $this->assign('hasOverride', !empty($activeOverride));

    // ------------------------------------------------------------------
    // All override records (historical + current).
    // ------------------------------------------------------------------
    $allOverrides = $this->_getAllOverrides($contactId);
    $this->assign('allOverrides', $allOverrides);

    // ------------------------------------------------------------------
    // All declaration records (for history display).
    // ------------------------------------------------------------------
    $allDeclarations = DuesCalculator::getAllDeclarations($contactId);
    $this->assign('allDeclarations', $allDeclarations);

    // ------------------------------------------------------------------
    // Action URLs.
    // ------------------------------------------------------------------
    $this->assign('addOverrideUrl', CRM_Utils_System::url(
      'civicrm/dues/override/add',
      "reset=1&cid={$contactId}"
    ));

    CRM_Core_Resources::singleton()->addStyleFile('custom_utility', 'css/ra_dues.css');

    parent::run();
  }

  /**
   * Returns all override records for the contact, enriched with human-readable
   * labels and edit URLs.
   *
   * @param int $contactId
   *
   * @return array
   */
  protected function _getAllOverrides($contactId) {
    $fields     = _custom_utility_get_custom_field_map('dues_overrides');
    $idToName   = array_flip($fields);
    $overrides  = [];

    if (empty($fields)) {
      return $overrides;
    }

    $returnFields = [];
    foreach ($fields as $name => $id) {
      $returnFields[] = 'custom_' . $id;
    }

    // Reason label map.
    $reasonLabels = [
      'extenuating_circumstances' => ts('Extenuating Circumstances'),
      'hardship'                  => ts('Hardship'),
      'board_decision'            => ts('Board Decision'),
      'other'                     => ts('Other'),
    ];

    try {
      $result = civicrm_api3('Contact', 'getsingle', [
        'id'     => $contactId,
        'return' => implode(',', $returnFields),
      ]);

      // Re-group multi-record values.
      $grouped = [];
      foreach ($result as $key => $value) {
        if (strpos($key, 'custom_') === 0) {
          $fieldId   = (int) substr($key, 7);
          $fieldName = $idToName[$fieldId] ?? NULL;
          if ($fieldName && is_array($value)) {
            foreach ($value as $idx => $v) {
              $grouped[$idx][$fieldName] = $v;
            }
          }
          elseif ($fieldName) {
            $grouped[0][$fieldName] = $value;
          }
        }
      }

      foreach ($grouped as $idx => $record) {
        $record['reason_label']     = $reasonLabels[$record['override_reason'] ?? ''] ?? $record['override_reason'] ?? '';
        $record['is_permanent_label'] = !empty($record['is_permanent']) ? ts('Permanent') : ts('Year-specific');
        $record['override_amount_formatted'] = CRM_Utils_Money::format($record['override_amount'] ?? 0);

        // Resolve the staff member's display name for the UI.
        $staffId = $record['staff_member_id'] ?? NULL;
        if ($staffId) {
          try {
            $record['staff_member_name'] = civicrm_api3('Contact', 'getvalue', [
              'id'     => $staffId,
              'return' => 'display_name',
            ]);
          }
          catch (CiviCRM_API3_Exception $e) {
            $record['staff_member_name'] = ts('(Unknown)');
          }
        }
        else {
          $record['staff_member_name'] = '';
        }

        $record['edit_url']         = CRM_Utils_System::url(
          'civicrm/dues/override/edit',
          "reset=1&cid={$contactId}&id={$idx}"
        );
        $overrides[] = $record;
      }
    }
    catch (CiviCRM_API3_Exception $e) {
      CRM_Core_Error::debug_log_message('DuesOverrideTab::_getAllOverrides: ' . $e->getMessage());
    }

    return $overrides;
  }

}
