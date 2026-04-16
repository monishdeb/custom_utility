{*
 * Template: DuesOverride.tpl
 * Controller: CRM_CustomUtility_Form_DuesOverride
 *
 * Form for adding or editing a dues override on a CiviCRM contact record.
 *}

<div class="crm-container ra-dues-management">

  <h2>
    {if $isEdit}
      {ts}Edit Dues Override{/ts}
    {else}
      {ts}Add Dues Override{/ts}
    {/if}
    {if $contactName} &ndash; {$contactName|escape}{/if}
  </h2>

  <div class="help">
    {ts}Use this form to set an administrative dues override for this member.
    A <strong>permanent override</strong> applies to all future years unless superseded.
    A <strong>year-specific override</strong> applies only to the specified dues year.{/ts}
  </div>

  <div class="crm-form-block">

    {* Permanent override toggle *}
    <div class="crm-section">
      <div class="label">{$form.is_permanent.label}</div>
      <div class="content">
        {$form.is_permanent.html}
        <span class="description">
          {ts}If checked, this override applies indefinitely and the Override Year field is ignored.{/ts}
        </span>
      </div>
      <div class="clear"></div>
    </div>

    {* Override year (hidden when permanent is checked) *}
    <div class="crm-section" id="ra-override-year-row">
      <div class="label">
        {$form.override_year.label}
        <span class="crm-marker">*</span>
      </div>
      <div class="content">
        {$form.override_year.html}
        <span class="description">{ts}Format: YYYY-YYYY (e.g. 2026-2027). Leave blank only if "Permanent Override" is checked.{/ts}</span>
      </div>
      <div class="clear"></div>
    </div>

    {* Override amount *}
    <div class="crm-section">
      <div class="label">
        {$form.override_amount.label}
        <span class="crm-marker">*</span>
      </div>
      <div class="content">{$form.override_amount.html}</div>
      <div class="clear"></div>
    </div>

    {* Override reason *}
    <div class="crm-section">
      <div class="label">
        {$form.override_reason.label}
        <span class="crm-marker">*</span>
      </div>
      <div class="content">{$form.override_reason.html}</div>
      <div class="clear"></div>
    </div>

    {* Notes *}
    <div class="crm-section">
      <div class="label">{$form.reason_notes.label}</div>
      <div class="content">{$form.reason_notes.html}</div>
      <div class="clear"></div>
    </div>

    {* Staff member *}
    <div class="crm-section">
      <div class="label">{$form.staff_member_id.label}</div>
      <div class="content">{$form.staff_member_id.html}</div>
      <div class="clear"></div>
    </div>

    {* Date set *}
    <div class="crm-section">
      <div class="label">{$form.date_set.label}</div>
      <div class="content">
        {include file="CRM/common/jcalendar.tpl" elementName=date_set}
      </div>
      <div class="clear"></div>
    </div>

  </div>{* .crm-form-block *}

  <div class="crm-submit-buttons">
    {include file="CRM/common/formButtons.tpl" location="bottom"}
  </div>

</div>{* .ra-dues-management *}

{literal}
<script type="text/javascript">
  (function ($) {
    'use strict';

    function toggleYearField() {
      var isPermanent = $('#is_permanent').is(':checked');
      if (isPermanent) {
        $('#ra-override-year-row').hide();
        $('#override_year').removeAttr('required');
      } else {
        $('#ra-override-year-row').show();
        $('#override_year').attr('required', 'required');
      }
    }

    $(document).ready(function () {
      toggleYearField();
      $('#is_permanent').on('change', toggleYearField);
    });
  }(CRM.$));
</script>
{/literal}
