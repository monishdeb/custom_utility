<?php

/**
 * @file
 * DuesCalculator – converts a declared salary into annual dues.
 *
 * Formula:  dues = salary × rate(status)
 * Rounding: nearest $5
 *
 * Status rates (configurable via CiviCRM settings):
 *   member         2.3 %
 *   widow_widower  1.5 %
 *   other          0.0 % (requires administrative override)
 */

class CRM_CustomUtility_Utils_DuesCalculator {

  /**
   * Dues rates keyed by status_category value.
   *
   * @var array
   */
  const RATES = [
    'member'        => 0.023,
    'widow_widower' => 0.015,
    'other'         => 0.0,
  ];

  /**
   * Minimum dues amount enforced regardless of calculation.
   *
   * @var float
   */
  const MINIMUM_DUES = 0.0;

  /**
   * Rounding increment (nearest $5).
   *
   * @var int
   */
  const ROUNDING_INCREMENT = 5;

  /**
   * Calculates annual dues from the declared salary and membership status.
   *
   * @param float  $salary  Annual gross salary declared by the member.
   * @param string $status  Status category: 'member', 'widow_widower', 'other'.
   *
   * @return float  Calculated dues amount, rounded to the nearest $5.
   */
  public static function calculate($salary, $status = 'member') {
    $salary = (float) $salary;
    if ($salary <= 0) {
      return self::MINIMUM_DUES;
    }

    $rate = self::RATES[$status] ?? self::RATES['member'];
    $raw  = $salary * $rate;

    $rounded = self::roundToNearest($raw, self::ROUNDING_INCREMENT);

    return max($rounded, self::MINIMUM_DUES);
  }

  /**
   * Returns the current dues year string (e.g. "2026-2027").
   *
   * The fiscal/dues year runs July–June.
   * If the current month is January–June the dues year started in the prior
   * calendar year; if July–December it started in the current calendar year.
   *
   * @return string  e.g. "2026-2027"
   */
  public static function getDuesYear() {
    $month     = (int) date('n');
    $year      = (int) date('Y');
    $startYear = ($month < 7) ? $year - 1 : $year;
    return $startYear . '-' . ($startYear + 1);
  }

  /**
   * Determines whether a member needs to submit a new salary declaration.
   *
   * Rules (any one triggers a new declaration):
   *   1. No prior declaration exists.
   *   2. Last declaration was ≥ 5 years ago.
   *   3. The member has a job_change_flag set on their latest declaration.
   *
   * @param int $contactId  CiviCRM contact ID.
   *
   * @return bool  TRUE if a new declaration is required.
   */
  public static function declarationRequired($contactId) {
    try {
      $declarations = self::getAllDeclarations($contactId);
    }
    catch (Exception $e) {
      CRM_Core_Error::debug_log_message('DuesCalculator::declarationRequired: ' . $e->getMessage());
      return TRUE;
    }

    if (empty($declarations)) {
      return TRUE;
    }

    // Sort by declaration_date descending.
    usort($declarations, static function ($a, $b) {
      return strcmp($b['declaration_date'] ?? '', $a['declaration_date'] ?? '');
    });

    $latest = $declarations[0];

    // Job change flag.
    if (!empty($latest['job_change_flag'])) {
      return TRUE;
    }

    // 5-year rule: compare timestamps to get total elapsed years accurately.
    $lastDate = $latest['declaration_date'] ?? NULL;
    if (!$lastDate) {
      return TRUE;
    }
    $lastTimestamp = strtotime($lastDate);
    if ($lastTimestamp === FALSE) {
      return TRUE;
    }
    $yearsSince = (int) floor((time() - $lastTimestamp) / (365.25 * 24 * 3600));
    if ($yearsSince >= 5) {
      return TRUE;
    }

    return FALSE;
  }

  /**
   * Checks whether the member has a current-year declaration on record.
   *
   * @param int    $contactId
   * @param string $duesYear  e.g. "2026-2027"
   *
   * @return bool
   */
  public static function hasCurrentYearDeclaration($contactId, $duesYear = NULL) {
    if ($duesYear === NULL) {
      $duesYear = self::getDuesYear();
    }
    $declarations = self::getAllDeclarations($contactId);
    foreach ($declarations as $d) {
      if (($d['declaration_year'] ?? '') === $duesYear) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * Saves a new salary declaration to the contact's custom field group.
   *
   * @param int    $contactId
   * @param float  $salary
   * @param string $status        Status category value.
   * @param bool   $jobChange     Whether this declaration was triggered by a job change.
   * @param string $duesYear      Dues year; defaults to current year.
   *
   * @return array  The created CustomValue API result.
   * @throws CiviCRM_API3_Exception
   */
  public static function saveDeclaration($contactId, $salary, $status = 'member', $jobChange = FALSE, $duesYear = NULL) {
    if ($duesYear === NULL) {
      $duesYear = self::getDuesYear();
    }
    $calculatedDues = self::calculate($salary, $status);
    $fields         = _custom_utility_get_custom_field_map('salary_declarations');

    $params = [
      'entity_id'    => $contactId,
      'entity_table' => 'civicrm_contact',
    ];

    $fieldMap = [
      'declaration_year'  => $duesYear,
      'declaration_date'  => date('Y-m-d'),
      'salary_declared'   => $salary,
      'status_category'   => $status,
      'calculated_dues'   => $calculatedDues,
      'job_change_flag'   => $jobChange ? 1 : 0,
    ];

    foreach ($fieldMap as $name => $value) {
      if (isset($fields[$name])) {
        $params['custom_' . $fields[$name]] = $value;
      }
    }

    return civicrm_api3('CustomValue', 'create', $params);
  }

  /**
   * Returns all salary declaration records for a contact.
   *
   * @param int $contactId
   *
   * @return array  List of declaration value arrays.
   */
  public static function getAllDeclarations($contactId) {
    $fields  = _custom_utility_get_custom_field_map('salary_declarations');
    if (empty($fields)) {
      return [];
    }

    $returnFields = [];
    foreach ($fields as $name => $id) {
      $returnFields[] = 'custom_' . $id;
    }

    try {
      $result = civicrm_api3('Contact', 'getsingle', [
        'id'     => $contactId,
        'return' => implode(',', $returnFields),
      ]);
    }
    catch (CiviCRM_API3_Exception $e) {
      return [];
    }

    // Rekey by field name, handling both single and multi-record values.
    $declarations = [];
    $idToName     = array_flip($fields);
    foreach ($result as $key => $value) {
      if (strpos($key, 'custom_') === 0) {
        $fieldId   = (int) substr($key, 7);
        $fieldName = $idToName[$fieldId] ?? NULL;
        if ($fieldName === NULL) {
          continue;
        }
        // Multi-record fields return arrays; single-value fields return scalar.
        if (is_array($value)) {
          foreach ($value as $idx => $v) {
            $declarations[$idx][$fieldName] = $v;
          }
        }
        else {
          $declarations[0][$fieldName] = $value;
        }
      }
    }
    return array_values($declarations);
  }

  /**
   * Rounds a value to the nearest $increment.
   *
   * @param float $value
   * @param int   $increment
   *
   * @return float
   */
  protected static function roundToNearest($value, $increment) {
    if ($increment <= 0) {
      return round($value, 2);
    }
    return round($value / $increment) * $increment;
  }

}
