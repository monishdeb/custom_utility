<?php

/**
 * @file
 * Dues Calculator – converts salary to dues and manages declaration lookups.
 */

/**
 * Salary → dues conversion utilities.
 */
class CRM_CustomUtility_Utils_DuesCalculator {

  /**
   * Percentage rates by status category.
   */
  const RATES = [
    'member'        => 0.023,  // 2.3 % of salary
    'widow_widower' => 0.015,  // 1.5 % of salary
    'other'         => 0.0,    // $0 – requires override
  ];

  /**
   * Round to the nearest multiple of this dollar amount.
   */
  const ROUNDING_INTERVAL = 5;

  // ---------------------------------------------------------------------------
  // Core calculation
  // ---------------------------------------------------------------------------

  /**
   * Calculates dues from salary and membership status.
   *
   * @param float  $salary
   * @param string $statusCategory  One of: member, widow_widower, other.
   *
   * @return float  Dues amount rounded to the nearest $5.
   */
  public static function calculate($salary, $statusCategory) {
    if (!array_key_exists($statusCategory, self::RATES)) {
      return 0.0;
    }
    $dues = (float) $salary * self::RATES[$statusCategory];
    return round($dues / self::ROUNDING_INTERVAL) * self::ROUNDING_INTERVAL;
  }

  // ---------------------------------------------------------------------------
  // Declaration persistence
  // ---------------------------------------------------------------------------

  /**
   * Saves a salary_declarations record for a contact.
   *
   * @param int    $contactId
   * @param float  $salary
   * @param string $status        One of: member, widow_widower, other.
   * @param bool   $jobChange     Whether the member flagged a job change.
   * @param string|null $duesYear e.g. "2026-2027"; defaults to current year.
   *
   * @return array  Result from CustomValue.create API call.
   *
   * @throws CRM_Core_Exception
   */
  public static function saveDeclaration($contactId, $salary, $status, $jobChange = FALSE, $duesYear = NULL) {
    if (!$duesYear) {
      $duesYear = self::getCurrentDuesYear();
    }
    $calculatedDues = self::calculate((float) $salary, $status);

    $group = civicrm_api3('CustomGroup', 'getsingle', ['name' => 'salary_declarations', 'return' => ['id']]);
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
      'entity_id'                         => $contactId,
      $fieldMap['declaration_year']       => $duesYear,
      $fieldMap['declaration_date']       => date('Y-m-d'),
      $fieldMap['salary_declared']        => $salary,
      $fieldMap['status_category']        => $status,
      $fieldMap['calculated_dues']        => $calculatedDues,
      $fieldMap['job_change_flag']        => $jobChange ? 1 : 0,
    ];

    return civicrm_api3('CustomValue', 'create', $params);
  }

  // ---------------------------------------------------------------------------
  // Declaration requirement check
  // ---------------------------------------------------------------------------

  /**
   * Determines whether a member must submit a new salary declaration.
   *
   * @param int         $contactId
   * @param string|null $duesYear
   *
   * @return string|null  "new", "5year", "job_change", or NULL (no declaration needed).
   */
  public static function declarationRequired($contactId, $duesYear = NULL) {
    if (!$duesYear) {
      $duesYear = self::getCurrentDuesYear();
    }

    $groupInfo = self::_getSalaryDeclarationsGroupInfo();
    if (!$groupInfo) {
      // Custom group not yet installed; treat as new.
      return 'new';
    }

    ['table_name' => $tableName, 'field_map' => $fm] = $groupInfo;

    // 1. No declarations ever → new member.
    $countRow = CRM_Core_DAO::executeQuery(
      "SELECT COUNT(*) AS cnt FROM {$tableName} WHERE entity_id = %1",
      [1 => [$contactId, 'Integer']]
    );
    $countRow->fetch();
    if ((int) $countRow->cnt === 0) {
      return 'new';
    }

    // 2. Current-year declaration already exists → none needed.
    $currentRow = CRM_Core_DAO::executeQuery(
      "SELECT id FROM {$tableName} WHERE entity_id = %1 AND {$fm['declaration_year']} = %2 LIMIT 1",
      [1 => [$contactId, 'Integer'], 2 => [$duesYear, 'String']]
    );
    if ($currentRow->fetch()) {
      return NULL;
    }

    // 3. Job change flagged in the previous fiscal year.
    $previousYear = self::getPreviousDuesYear($duesYear);
    $jobRow = CRM_Core_DAO::executeQuery(
      "SELECT id FROM {$tableName}
       WHERE entity_id = %1
         AND {$fm['declaration_year']} = %2
         AND {$fm['job_change_flag']} = 1
       LIMIT 1",
      [1 => [$contactId, 'Integer'], 2 => [$previousYear, 'String']]
    );
    if ($jobRow->fetch()) {
      return 'job_change';
    }

    // 4. 5-year cycle: most recent declaration ≥ 5 years ago.
    $lastRow = CRM_Core_DAO::executeQuery(
      "SELECT {$fm['declaration_date']} AS declaration_date
       FROM   {$tableName}
       WHERE  entity_id = %1
       ORDER  BY {$fm['declaration_date']} DESC
       LIMIT  1",
      [1 => [$contactId, 'Integer']]
    );
    if ($lastRow->fetch() && !empty($lastRow->declaration_date)) {
      $yearsAgo = (time() - strtotime($lastRow->declaration_date)) / (365.25 * 24 * 3600);
      if ($yearsAgo >= 5) {
        return '5year';
      }
    }

    return NULL;
  }

  // ---------------------------------------------------------------------------
  // Effective dues (with override support)
  // ---------------------------------------------------------------------------

  /**
   * Returns the effective dues for a contact, applying overrides if present.
   *
   * @param int         $contactId
   * @param string|null $duesYear
   *
   * @return array|null  ['amount' => float, 'type' => string, 'reason' => string|null]
   */
  public static function getEffectiveDues($contactId, $duesYear = NULL) {
    if (!$duesYear) {
      $duesYear = self::getCurrentDuesYear();
    }

    // 1. Permanent override.
    $overrideGroup = self::_getDuesOverridesGroupInfo();
    if ($overrideGroup) {
      ['table_name' => $ot, 'field_map' => $of] = $overrideGroup;

      $permRow = CRM_Core_DAO::executeQuery(
        "SELECT {$of['override_amount']} AS amount, {$of['override_reason']} AS reason
         FROM   {$ot}
         WHERE  entity_id = %1 AND {$of['is_permanent']} = 1
         ORDER  BY {$of['date_set']} DESC
         LIMIT  1",
        [1 => [$contactId, 'Integer']]
      );
      if ($permRow->fetch()) {
        return [
          'amount' => (float) $permRow->amount,
          'type'   => 'override_permanent',
          'reason' => $permRow->reason,
        ];
      }

      // 2. Year-specific override.
      $yearRow = CRM_Core_DAO::executeQuery(
        "SELECT {$of['override_amount']} AS amount, {$of['override_reason']} AS reason
         FROM   {$ot}
         WHERE  entity_id = %1
           AND  {$of['override_year']} = %2
           AND  ({$of['is_permanent']} = 0 OR {$of['is_permanent']} IS NULL)
         ORDER  BY {$of['date_set']} DESC
         LIMIT  1",
        [1 => [$contactId, 'Integer'], 2 => [$duesYear, 'String']]
      );
      if ($yearRow->fetch()) {
        return [
          'amount' => (float) $yearRow->amount,
          'type'   => 'override_year_specific',
          'reason' => $yearRow->reason,
        ];
      }
    }

    // 3. Calculated dues from salary_declarations.
    $declGroup = self::_getSalaryDeclarationsGroupInfo();
    if ($declGroup) {
      ['table_name' => $dt, 'field_map' => $df] = $declGroup;

      $calcRow = CRM_Core_DAO::executeQuery(
        "SELECT {$df['calculated_dues']} AS calculated_dues
         FROM   {$dt}
         WHERE  entity_id = %1 AND {$df['declaration_year']} = %2
         LIMIT  1",
        [1 => [$contactId, 'Integer'], 2 => [$duesYear, 'String']]
      );
      if ($calcRow->fetch()) {
        return [
          'amount' => (float) $calcRow->calculated_dues,
          'type'   => 'calculated',
          'reason' => NULL,
        ];
      }
    }

    return NULL;
  }

  // ---------------------------------------------------------------------------
  // Year helpers
  // ---------------------------------------------------------------------------

  /**
   * Returns the current fiscal dues year string (e.g. "2026-2027").
   */
  public static function getCurrentDuesYear() {
    return date('Y') . '-' . (date('Y') + 1);
  }

  /**
   * Returns the previous fiscal year string given a dues year.
   *
   * @param string $duesYear  e.g. "2026-2027"
   *
   * @return string  e.g. "2025-2026"
   */
  public static function getPreviousDuesYear($duesYear) {
    [$from] = explode('-', $duesYear);
    $prev = (int) $from - 1;
    return $prev . '-' . $from;
  }

  // ---------------------------------------------------------------------------
  // Private helpers – custom group introspection
  // ---------------------------------------------------------------------------

  /**
   * Returns salary_declarations table name and column map, or NULL if not installed.
   *
   * @return array|null  ['table_name' => string, 'field_map' => array]
   */
  private static function _getSalaryDeclarationsGroupInfo() {
    return self::_getCustomGroupInfo('salary_declarations', [
      'declaration_year',
      'declaration_date',
      'salary_declared',
      'status_category',
      'calculated_dues',
      'job_change_flag',
    ]);
  }

  /**
   * Returns dues_overrides table name and column map, or NULL if not installed.
   *
   * @return array|null
   */
  private static function _getDuesOverridesGroupInfo() {
    return self::_getCustomGroupInfo('dues_overrides', [
      'override_year',
      'override_amount',
      'override_reason',
      'is_permanent',
      'date_set',
    ]);
  }

  /**
   * Generic helper to retrieve a custom group's table name and field column map.
   *
   * @param string   $groupName
   * @param string[] $fieldNames
   *
   * @return array|null
   */
  private static function _getCustomGroupInfo($groupName, array $fieldNames) {
    try {
      $group = civicrm_api3('CustomGroup', 'getsingle', [
        'name'   => $groupName,
        'return' => ['id', 'table_name'],
      ]);
    }
    catch (Exception $e) {
      return NULL;
    }

    $fields = civicrm_api3('CustomField', 'get', [
      'custom_group_id' => $group['id'],
      'name'            => ['IN' => $fieldNames],
      'return'          => ['name', 'column_name'],
      'options'         => ['limit' => 0],
    ]);

    $fieldMap = [];
    foreach ($fields['values'] as $f) {
      $fieldMap[$f['name']] = $f['column_name'];
    }

    return [
      'table_name' => $group['table_name'],
      'field_map'  => $fieldMap,
    ];
  }

}
