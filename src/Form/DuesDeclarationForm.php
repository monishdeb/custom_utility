<?php

/**
 * @file
 * Contains \Drupal\ra_custom_utility\Form\DuesDeclarationForm.
 */

namespace Drupal\ra_custom_utility\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;

/**
 * Member salary declaration form.
 *
 * Presented to members when:
 *  - They are new (no prior declaration exists), OR
 *  - It has been ≥ 5 years since their last declaration, OR
 *  - They flagged a job change in the previous declaration year.
 *
 * On successful submission, a salary_declarations record is saved to the
 * member's CiviCRM contact via the DuesCalculator in the CiviCRM extension,
 * and the member is redirected to the dues payment page (contribution form
 * id = 11).
 */
class DuesDeclarationForm extends FormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'dues_declaration_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    // Initialise CiviCRM so API calls are available.
    \Drupal::service('civicrm')->initialize();

    $contactId = $this->getCurrentUserCiviCrmId();

    // If no CiviCRM contact is found the member cannot proceed.
    if (!$contactId) {
      $form['error'] = [
        '#markup' => '<p class="messages messages--error">'
          . $this->t('Your account could not be linked to a member record. Please contact the office.')
          . '</p>',
      ];
      return $form;
    }

    // Determine whether a declaration is required.
    $declarationStatus = $this->getDeclarationRequired($contactId);

    if ($declarationStatus === NULL) {
      // No declaration required – show a skip message and link to payment.
      $paymentUrl = Url::fromUri('internal:/civicrm/contribute/transact', [
        'query' => ['reset' => '1', 'id' => '11'],
      ])->toString();

      $form['skip_message'] = [
        '#markup' => '<p class="messages messages--status">'
          . $this->t(
            'You have a current salary declaration on file. '
            . '<a href="@url">Click here to proceed to payment.</a>',
            ['@url' => $paymentUrl]
          )
          . '</p>',
      ];
      $form['#attributes']['class'][] = 'member-dues-declaration-form';
      return $form;
    }

    // Show the reason this declaration is needed.
    $reasonMessages = [
      'new'        => $this->t('Welcome! As a new member, please complete a salary declaration to calculate your dues.'),
      '5year'      => $this->t('It has been five or more years since your last salary declaration. Please update your information.'),
      'job_change' => $this->t('You indicated a job change last year. Please update your salary information.'),
    ];
    $form['declaration_notice'] = [
      '#markup' => '<p class="messages messages--status">' . ($reasonMessages[$declarationStatus] ?? '') . '</p>',
    ];

    $form['#attributes']['class'][] = 'member-dues-declaration-form';

    // Salary input.
    $form['salary_declared'] = [
      '#type'        => 'number',
      '#title'       => $this->t('Annual Salary (USD)'),
      '#description' => $this->t('Enter your annual gross salary in US dollars.'),
      '#required'    => TRUE,
      '#min'         => 0,
      '#step'        => '0.01',
      '#attributes'  => ['id' => 'edit-salary-declared'],
    ];

    // Status category.
    $form['status_category'] = [
      '#type'    => 'select',
      '#title'   => $this->t('Membership Status'),
      '#options' => [
        'member'        => $this->t('Member'),
        'widow_widower' => $this->t('Widow / Widower'),
        'other'         => $this->t('Other'),
      ],
      '#required'   => TRUE,
      '#attributes' => ['id' => 'edit-status-category'],
    ];

    // Job change flag.
    $form['job_change_flag'] = [
      '#type'        => 'checkbox',
      '#title'       => $this->t('I have changed jobs or expect to change jobs this year'),
      '#description' => $this->t('Checking this will prompt you to submit a new declaration next year.'),
    ];

    // Real-time dues preview (populated by ra_dues.js).
    $form['dues_preview'] = [
      '#markup' => '<div id="dues-calculator-preview" class="dues-calculator-preview" style="display:none;">'
        . '<span class="dues-result-label">' . $this->t('Estimated Dues:') . '</span>'
        . '<span class="dues-result-amount" id="dues-preview-amount"></span>'
        . '</div>',
    ];

    $form['actions']['#type'] = 'actions';
    $form['actions']['submit'] = [
      '#type'  => 'submit',
      '#value' => $this->t('Submit Declaration and Continue to Payment'),
    ];

    // Inline JS for real-time dues preview.
    // NOTE: The CiviCRM extension (custom_utility_civicrm/js/ra_dues.js) contains
    // equivalent logic for the payment form. Drupal cannot load assets from the
    // CiviCRM extension directory via its library system, so the calculator
    // snippet is inlined here. If the calculation rates change, update both files.
    $form['#attached']['html_head'][] = [
      [
        '#tag'        => 'style',
        '#value'      => '
.member-dues-declaration-form .dues-calculator-preview {
  background-color: #e8eef8;
  border: 1px solid #b0c4de;
  border-radius: 4px;
  padding: 12px 16px;
  margin: 16px 0;
  font-size: 1.05em;
}
.member-dues-declaration-form .dues-calculator-preview .dues-result-label { font-weight: bold; margin-right: 6px; }
.member-dues-declaration-form .dues-calculator-preview .dues-result-amount { font-size: 1.3em; font-weight: bold; color: #1a5276; }
',
        '#attributes' => ['type' => 'text/css'],
      ],
      'ra_dues_declaration_css',
    ];
    $form['#attached']['html_head'][] = [
      [
        '#tag'   => 'script',
        '#value' => '
(function ($) {
  "use strict";
  var RATES = { member: 0.023, widow_widower: 0.015, other: 0.0 };
  var ROUNDING = 5;
  function calcDues(salary, status) {
    var rate = RATES[status] !== undefined ? RATES[status] : 0;
    var dues = parseFloat(salary) * rate;
    return Math.round(dues / ROUNDING) * ROUNDING;
  }
  function fmtCurrency(n) {
    return "$" + n.toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, ",");
  }
  function updatePreview() {
    var salary = parseFloat($("#edit-salary-declared").val()) || 0;
    var status = $("#edit-status-category").val() || "member";
    if (salary > 0) {
      $("#dues-preview-amount").text(fmtCurrency(calcDues(salary, status)));
      $("#dues-calculator-preview").show();
    } else {
      $("#dues-calculator-preview").hide();
    }
  }
  $(document).ready(function () {
    if ($(".member-dues-declaration-form").length) {
      $("#edit-salary-declared").on("input keyup change", updatePreview);
      $("#edit-status-category").on("change", updatePreview);
      updatePreview();
    }
  });
}(jQuery));
',
        '#attributes' => ['type' => 'text/javascript'],
      ],
      'ra_dues_declaration_js',
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    $salary = $form_state->getValue('salary_declared');
    if ($salary !== NULL && !is_numeric($salary)) {
      $form_state->setErrorByName('salary_declared', $this->t('Please enter a valid salary amount.'));
    }
    if ($salary !== NULL && (float) $salary < 0) {
      $form_state->setErrorByName('salary_declared', $this->t('Salary must be a positive number.'));
    }
    $status = $form_state->getValue('status_category');
    $allowed = ['member', 'widow_widower', 'other'];
    if ($status !== NULL && !in_array($status, $allowed, TRUE)) {
      $form_state->setErrorByName('status_category', $this->t('Please select a valid membership status.'));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    \Drupal::service('civicrm')->initialize();

    $salary    = (float) $form_state->getValue('salary_declared');
    $status    = $form_state->getValue('status_category');
    $jobChange = (bool) $form_state->getValue('job_change_flag');
    $contactId = $this->getCurrentUserCiviCrmId();

    if (!$contactId) {
      $this->messenger()->addError($this->t('Failed to identify your member record. Please contact the office.'));
      return;
    }

    try {
      $this->saveDeclaration($contactId, $salary, $status, $jobChange);

      $this->messenger()->addStatus($this->t('Your salary declaration has been saved.'));

      // Redirect to the CiviCRM dues payment page.
      $paymentUrl = Url::fromUri('internal:/civicrm/contribute/transact', [
        'query' => ['reset' => '1', 'id' => '11'],
      ]);
      $form_state->setRedirectUrl($paymentUrl);
    }
    catch (\Exception $e) {
      \Drupal::logger('ra_custom_utility')->error($e->getMessage());
      $this->messenger()->addError($this->t('Failed to save your declaration. Please try again or contact the office.'));
    }
  }

  // ---------------------------------------------------------------------------
  // Private helpers
  // ---------------------------------------------------------------------------

  /**
   * Returns the CiviCRM contact ID for the currently logged-in Drupal user.
   *
   * @return int|null
   */
  private function getCurrentUserCiviCrmId() {
    $uid = \Drupal::currentUser()->id();
    if (!$uid) {
      return NULL;
    }
    try {
      $result = civicrm_api3('UFMatch', 'getsingle', ['uf_id' => $uid, 'return' => ['contact_id']]);
      return !empty($result['contact_id']) ? (int) $result['contact_id'] : NULL;
    }
    catch (\Exception $e) {
      return NULL;
    }
  }

  /**
   * Delegates to CRM_CustomUtility_Utils_DuesCalculator::declarationRequired().
   *
   * Returns "new", "5year", "job_change", or NULL.
   *
   * @param int $contactId
   *
   * @return string|null
   */
  private function getDeclarationRequired($contactId) {
    if (!class_exists('CRM_CustomUtility_Utils_DuesCalculator')) {
      // Extension not installed yet; require declaration.
      return 'new';
    }
    return \CRM_CustomUtility_Utils_DuesCalculator::declarationRequired($contactId);
  }

  /**
   * Delegates to CRM_CustomUtility_Utils_DuesCalculator::saveDeclaration().
   *
   * @param int    $contactId
   * @param float  $salary
   * @param string $status
   * @param bool   $jobChange
   *
   * @throws \Exception
   */
  private function saveDeclaration($contactId, $salary, $status, $jobChange) {
    if (!class_exists('CRM_CustomUtility_Utils_DuesCalculator')) {
      throw new \RuntimeException('The CiviCRM Dues Management extension is not installed.');
    }
    \CRM_CustomUtility_Utils_DuesCalculator::saveDeclaration($contactId, $salary, $status, $jobChange);
  }

}
