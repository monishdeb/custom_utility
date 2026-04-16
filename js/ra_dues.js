/**
 * ra_dues.js – RA Custom Utility dues management JavaScript.
 *
 * Loaded on the CiviCRM Member Dues contribution form (id=11).
 *
 * Responsibilities:
 *   1. Real-time dues calculation display (salary × rate).
 *   2. Installment splitting UI: show/hide installment count field.
 *   3. Payment option radio toggle.
 *   4. Read-only display of declared salary and effective dues.
 */

(function ($, CRM) {
  'use strict';

  /**
   * Dues rates matching CRM_CustomUtility_Utils_DuesCalculator::RATES.
   */
  var DUES_RATES = {
    member:        0.023,
    widow_widower: 0.015,
    other:         0.0
  };

  var ROUNDING_INCREMENT = 5;

  /**
   * Rounds a value to the nearest increment.
   *
   * @param {number} value
   * @param {number} increment
   * @returns {number}
   */
  function roundToNearest(value, increment) {
    if (!increment) { return Math.round(value * 100) / 100; }
    return Math.round(value / increment) * increment;
  }

  /**
   * Calculates dues from salary and status, mirroring the PHP DuesCalculator.
   *
   * @param {number} salary
   * @param {string} status
   * @returns {number}
   */
  function calculateDues(salary, status) {
    salary = parseFloat(salary) || 0;
    var rate = DUES_RATES[status] !== undefined ? DUES_RATES[status] : DUES_RATES.member;
    var raw  = salary * rate;
    return roundToNearest(raw, ROUNDING_INCREMENT);
  }

  /**
   * Formats a number as a US dollar string.
   *
   * @param {number} amount
   * @returns {string}
   */
  function formatMoney(amount) {
    return '$' + amount.toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, ',');
  }

  /**
   * Updates the on-screen dues display and sets the hidden dues field value.
   */
  function updateDuesDisplay() {
    var $salaryField = $('[name="salary_declared"], [name="custom_406"]');
    var $statusField = $('[name="status_category"]');
    var $duesDisplay = $('#ra-calculated-dues-display');
    var $duesField   = $('[name="calculated_dues"]');

    var salary = parseFloat($salaryField.val().replace(/[^0-9.]/g, '')) || 0;
    var status = $statusField.val() || 'member';
    var dues   = calculateDues(salary, status);

    $duesDisplay.text(formatMoney(dues));
    if ($duesField.length) {
      $duesField.val(dues.toFixed(2));
    }
  }

  // ============================================================
  // Installment splitting UI
  // ============================================================

  /**
   * Handles payment-option radio changes, showing/hiding the installment row.
   */
  function handlePaymentOptionChange() {
    var selected = $('[name="recurrence_options"]:checked').val();
    if (selected === '2') {
      $('#ra-installment-row').slideDown(200);
    } else {
      $('#ra-installment-row').slideUp(200);
    }
    if (selected === '3') {
      $('#ra-other-amount-row').slideDown(200);
    } else {
      $('#ra-other-amount-row').slideUp(200);
    }
  }

  /**
   * Injects the installment count and other-amount rows into the form
   * after the payment options radio group.
   */
  function injectInstallmentUI() {
    var $radioGroup = $('[name="recurrence_options"]').closest('.content, .form-item, tr').last();
    if (!$radioGroup.length) {
      $radioGroup = $('[name="recurrence_options"]').last().closest('div');
    }

    var vars = CRM.vars.raDues || {};

    if (!$('#ra-installment-row').length) {
      var installmentHtml = [
        '<div id="ra-installment-row" style="display:none;">',
        '  <label for="recur_installment">' + CRM.ts('Number of installments') + ':</label>',
        '  <input type="number" id="recur_installment" name="recur_installment" min="2" max="12" value="2" style="width:60px;">',
        '  <span class="description">' + CRM.ts('We will divide your payment into equal installments.') + '</span>',
        '</div>'
      ].join('\n');

      var otherHtml = [
        '<div id="ra-other-amount-row" style="display:none;">',
        '  <label for="recur_other">' + CRM.ts('Other amount') + ':</label>',
        '  <input type="text" id="recur_other" name="recur_other" size="10">',
        '</div>'
      ].join('\n');

      $radioGroup.after(installmentHtml + otherHtml);
    }

    // Render a read-only summary block above the form if raDues vars are set.
    if (vars.effectiveDues !== undefined && !$('#ra-dues-payment-summary').length) {
      var overrideNote = vars.hasOverride
        ? ' <span class="ra-dues-override-badge">' + CRM.ts('Override Applied') + '</span>'
        : '';
      var cssClass = vars.hasOverride ? 'ra-overridden-info' : '';
      var summaryHtml = [
        '<div id="ra-dues-payment-summary" class="ra-dues-payment-info ' + cssClass + '">',
        '  <table>',
        '    <tr>',
        '      <td><strong>' + CRM.ts('Dues Year') + ':</strong></td>',
        '      <td>' + (vars.duesYear || '') + '</td>',
        '    </tr>',
        '    <tr>',
        '      <td><strong>' + CRM.ts('Dues Amount') + ':</strong></td>',
        '      <td class="' + (vars.hasOverride ? 'ra-dues-overridden' : 'ra-dues-standard') + '">',
        formatMoney(parseFloat(vars.effectiveDues) || 0) + overrideNote,
        '      </td>',
        '    </tr>',
        '    <tr>',
        '      <td><strong>' + CRM.ts('Already Paid') + ':</strong></td>',
        '      <td>' + formatMoney(parseFloat(vars.duesPaid) || 0) + '</td>',
        '    </tr>',
        '    <tr>',
        '      <td><strong>' + CRM.ts('Balance Due') + ':</strong></td>',
        '      <td><strong>' + formatMoney(parseFloat(vars.duesBalance) || 0) + '</strong></td>',
        '    </tr>',
        '  </table>',
        '</div>'
      ].join('\n');

      // Insert before the first price-set section.
      var $priceSet = $('.crm-contribution-main-form-block').first();
      if ($priceSet.length) {
        $priceSet.prepend(summaryHtml);
      }
    }
  }

  // ============================================================
  // Declaration form helpers (Drupal-side, included here for reuse)
  // ============================================================

  /**
   * Attaches real-time dues calculation to the declaration form fields
   * (used on the Drupal /member-dues-declaration page).
   */
  function initDeclarationForm() {
    var $form = $('[data-ra-dues-declaration]');
    if (!$form.length) { return; }

    $('[name="salary_declared"], [name="status_category"]', $form).on('input change', updateDuesDisplay);
    updateDuesDisplay();
  }

  // ============================================================
  // Bootstrap
  // ============================================================

  $(document).ready(function () {
    // Payment form (contribution id=11).
    injectInstallmentUI();
    $('[name="recurrence_options"]').on('change', handlePaymentOptionChange);
    handlePaymentOptionChange();

    // Declaration form (Drupal page).
    initDeclarationForm();

    // Bind live dues calculation to any salary field on this page.
    $('[name="salary_declared"], [name="custom_406"]').on('input', updateDuesDisplay);
    $('[name="status_category"]').on('change', updateDuesDisplay);
  });

}(CRM.$, CRM));
