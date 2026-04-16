<?php

/**
 * @file
 * Drupal form for the member salary declaration page.
 *
 * Route: /member-dues-declaration
 * Defined in ra_custom_utility.routing.yml
 *
 * Workflow:
 *   1. Identify the logged-in user's CiviCRM contact.
 *   2. Check whether a new declaration is required (new member, 5-year cycle,
 *      job change flag).
 *   3. If not required and a current-year declaration exists, redirect the
 *      member directly to the payment page.
 *   4. Display salary/status fields with a real-time dues calculation.
 *   5. On submit, store the declaration in the CiviCRM custom field group and
 *      redirect to the payment page (contribution form id=11).
 */

namespace Drupal\ra_custom_utility\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;

class DuesDeclarationForm extends FormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'ra_dues_declaration_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    \Drupal::service('civicrm')->initialize();

    $currentUser = \Drupal::currentUser();
    $uid         = $currentUser->id();

    if (!$uid) {
      // Anonymous users cannot declare – redirect to login.
      $form_state->setRedirectUrl(Url::fromRoute('user.login', [], ['query' => ['destination' => '/member-dues-declaration']]));
      return $form;
    }

    $contactId = $this->getContactId($uid);
    if (!$contactId) {
      $this->messenger()->addError($this->t('No CiviCRM contact found for your account. Please contact the office.'));
      return $form;
    }

    // Store contact ID for use in submitForm.
    $form_state->set('contact_id', $contactId);

    // Determine current dues year (matches DuesCalculator::getDuesYear()).
    $duesYear = $this->getDuesYear();
    $form_state->set('dues_year', $duesYear);

    // Check whether declaration is required.
    if (!$this->declarationRequired($contactId, $duesYear)) {
      // Member already has a valid current-year declaration → skip to payment.
      $paymentUrl = Url::fromUri('internal:/civicrm/contribute/transact', ['query' => ['id' => 11, 'reset' => 1]]);
      $form_state->setRedirectUrl($paymentUrl);
      return $form;
    }

    $form['#attached']['library'][] = 'ra_custom_utility/ra_dues';

    $form['intro'] = [
      '#markup' => '<p>' . $this->t(
        'Please complete your salary declaration for <strong>%year</strong>. Your dues will be calculated automatically.',
        ['%year' => $duesYear]
      ) . '</p>',
    ];

    $form['salary_declared'] = [
      '#type'          => 'textfield',
      '#title'         => $this->t('Annual Gross Salary'),
      '#description'   => $this->t('Enter your annual gross salary in US dollars (numbers only).'),
      '#required'      => TRUE,
      '#size'          => 15,
      '#maxlength'     => 15,
      '#attributes'    => [
        'placeholder' => '125000',
        'inputmode'   => 'numeric',
        'class'       => ['ra-salary-field'],
      ],
    ];

    $form['status_category'] = [
      '#type'    => 'select',
      '#title'   => $this->t('Membership Status'),
      '#options' => [
        'member'        => $this->t('Member'),
        'widow_widower' => $this->t('Widow/Widower'),
        'other'         => $this->t('Other'),
      ],
      '#required' => TRUE,
      '#default_value' => 'member',
    ];

    $form['job_change'] = [
      '#type'        => 'checkbox',
      '#title'       => $this->t('I have recently changed jobs or employers'),
      '#description' => $this->t('Check this box if you have changed employment since your last declaration.'),
    ];

    $form['calculated_dues_display'] = [
      '#type'   => 'item',
      '#title'  => $this->t('Your calculated dues for %year', ['%year' => $duesYear]),
      '#markup' => '<div id="ra-calculated-dues-display" class="ra-dues-readonly-field">$0.00</div>',
    ];

    // Hidden field to pass calculated dues to submit handler.
    $form['calculated_dues'] = [
      '#type'  => 'hidden',
      '#value' => 0,
    ];

    $form['actions'] = [
      '#type' => 'actions',
    ];
    $form['actions']['submit'] = [
      '#type'  => 'submit',
      '#value' => $this->t('Complete Declaration'),
      '#attributes' => ['class' => ['button', 'button--primary']],
    ];

    $form['#attributes']['data-ra-dues-declaration'] = 'true';

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    $salary = $form_state->getValue('salary_declared');
    $salary = preg_replace('/[^0-9.]/', '', $salary);
    if (!is_numeric($salary) || (float) $salary <= 0) {
      $form_state->setErrorByName('salary_declared', $this->t('Please enter a valid positive salary amount.'));
    }
    else {
      // Normalise.
      $form_state->setValue('salary_declared', (float) $salary);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    \Drupal::service('civicrm')->initialize();

    $contactId  = $form_state->get('contact_id');
    $duesYear   = $form_state->get('dues_year');
    $salary     = (float) $form_state->getValue('salary_declared');
    $status     = $form_state->getValue('status_category');
    $jobChange  = (bool) $form_state->getValue('job_change');

    try {
      \CRM_CustomUtility_Utils_DuesCalculator::saveDeclaration($contactId, $salary, $status, $jobChange, $duesYear);

      $this->messenger()->addStatus($this->t(
        'Your salary declaration for %year has been saved. Please proceed to payment.',
        ['%year' => $duesYear]
      ));
    }
    catch (\Exception $e) {
      \Drupal::logger('ra_custom_utility')->error('DuesDeclarationForm::submitForm error: @msg', ['@msg' => $e->getMessage()]);
      $this->messenger()->addError($this->t('There was a problem saving your declaration. Please try again or contact the office.'));
      return;
    }

    // Redirect to contribution form id=11 (payment page).
    $form_state->setRedirectUrl(Url::fromUri('internal:/civicrm/contribute/transact', ['query' => ['id' => 11, 'reset' => 1]]));
  }

  // ---------------------------------------------------------------------------
  // Helpers
  // ---------------------------------------------------------------------------

  /**
   * Returns the CiviCRM contact ID for the given Drupal user ID.
   *
   * @param int $uid  Drupal user ID.
   *
   * @return int|null
   */
  protected function getContactId($uid) {
    try {
      $result = civicrm_api3('UFMatch', 'getsingle', ['uf_id' => $uid]);
      return $result['contact_id'] ?? NULL;
    }
    catch (\CiviCRM_API3_Exception $e) {
      return NULL;
    }
  }

  /**
   * Returns the current dues year, e.g. "2026-2027".
   *
   * The fiscal year runs July–June; January–June → prior year start.
   *
   * @return string
   */
  protected function getDuesYear() {
    $month     = (int) date('n');
    $year      = (int) date('Y');
    $startYear = ($month < 7) ? $year - 1 : $year;
    return $startYear . '-' . ($startYear + 1);
  }

  /**
   * Determines whether the member must complete a new declaration.
   *
   * Conditions triggering a new declaration:
   *   - No prior declaration exists.
   *   - No current-year declaration.
   *   - Last declaration was ≥ 5 years ago.
   *   - Job change flag is set on the most recent declaration.
   *
   * @param int    $contactId
   * @param string $duesYear
   *
   * @return bool
   */
  protected function declarationRequired($contactId, $duesYear) {
    // Delegate to the CiviCRM extension's DuesCalculator.
    if (class_exists('\CRM_CustomUtility_Utils_DuesCalculator')) {
      if (!\CRM_CustomUtility_Utils_DuesCalculator::hasCurrentYearDeclaration($contactId, $duesYear)) {
        return TRUE;
      }
      return \CRM_CustomUtility_Utils_DuesCalculator::declarationRequired($contactId);
    }

    // Fallback: always require declaration if calculator is unavailable.
    return TRUE;
  }

}
