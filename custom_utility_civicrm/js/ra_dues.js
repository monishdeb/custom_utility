/**
 * ra_dues.js – Dues Management UI Helpers
 * CiviCRM Extension: custom_utility_civicrm
 *
 * Responsibilities:
 *  - Real-time dues calculation preview on the Drupal declaration form
 *  - Installment-split UI on the CiviCRM payment form (contribution page id=11)
 *  - Payment summary display helpers
 */

(function ($, CRM) {
  'use strict';

  /* =========================================================================
     1. Real-time dues calculator (Drupal declaration form)
     ========================================================================= */

  var RATES = {
    member:        0.023,
    widow_widower: 0.015,
    other:         0.0
  };
  var ROUNDING_INTERVAL = 5;

  /**
   * Calculates dues from salary and status, rounded to nearest $5.
   *
   * @param {number} salary
   * @param {string} status  One of: member, widow_widower, other.
   * @return {number}
   */
  function calculateDues(salary, status) {
    var rate = RATES[status] !== undefined ? RATES[status] : 0;
    var dues  = parseFloat(salary) * rate;
    return Math.round(dues / ROUNDING_INTERVAL) * ROUNDING_INTERVAL;
  }

  /**
   * Formats a number as USD currency.
   *
   * @param {number} amount
   * @return {string}
   */
  function formatCurrency(amount) {
    return '$' + amount.toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, ',');
  }

  /**
   * Updates the dues preview panel on the Drupal declaration form.
   */
  function updateDuesPreview() {
    var salary = parseFloat($('#edit-salary-declared').val()) || 0;
    var status = $('#edit-status-category').val() || 'member';
    var dues   = calculateDues(salary, status);

    if (salary > 0) {
      $('#dues-preview-amount').text(formatCurrency(dues));
      $('#dues-calculator-preview').show();
    } else {
      $('#dues-calculator-preview').hide();
    }
  }

  // Bind real-time calculation on the Drupal declaration form.
  $(document).ready(function () {
    if ($('.member-dues-declaration-form').length) {
      $('#edit-salary-declared').on('input keyup change', updateDuesPreview);
      $('#edit-status-category').on('change', updateDuesPreview);
      updateDuesPreview();
    }
  });

  /* =========================================================================
     2. Installment-split UI (CiviCRM payment form, contribution page id=11)
     ========================================================================= */

  /**
   * Shows or hides the installment count input based on the selected payment
   * option radio button.
   */
  function toggleInstallmentInput() {
    var selected = $('input[name="recurrence_options"]:checked').val();
    if (selected == 2) {
      $('.recur-installment-content').show();
    } else {
      $('.recur-installment-content').hide();
    }
  }

  /**
   * Updates the payment summary (effective dues + installment breakdown).
   */
  function updatePaymentSummary() {
    var duesAmount  = parseFloat($('#dues-effective-amount-value').data('amount')) || 0;
    var installments = parseInt($('#recur_installment').val(), 10) || 1;
    var selected    = $('input[name="recurrence_options"]:checked').val();

    if (selected == 2 && installments > 1) {
      var perInstallment = duesAmount / installments;
      $('#payment-summary-per-installment').text(
        installments + ' × ' + formatCurrency(perInstallment)
      );
      $('#payment-summary-breakdown').show();
    } else {
      $('#payment-summary-breakdown').hide();
    }
  }

  $(document).ready(function () {
    if ($('.crm-contribution-page-id-11').length) {

      // Initial state.
      toggleInstallmentInput();

      // Toggle on radio change.
      $(document).on('change', 'input[name="recurrence_options"]', function () {
        toggleInstallmentInput();
        updatePaymentSummary();
      });

      // Update summary when installment count changes.
      $(document).on('input change', '#recur_installment', updatePaymentSummary);

      updatePaymentSummary();
    }
  });

  /* =========================================================================
     3. Override form helpers (CiviCRM admin override form)
     ========================================================================= */

  $(document).ready(function () {
    if ($('.dues-override-form').length) {

      /**
       * Toggles the override year field visibility based on the Permanent checkbox.
       */
      function toggleYearField() {
        if ($('#is_permanent').is(':checked')) {
          $('#dues-override-year-section').hide();
          $('#override_year').val('');
        } else {
          $('#dues-override-year-section').show();
        }
      }

      toggleYearField();
      $('#is_permanent').on('change', toggleYearField);
    }
  });

}(jQuery, CRM));
