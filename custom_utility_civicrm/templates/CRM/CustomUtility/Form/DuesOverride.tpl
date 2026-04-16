{* CRM/CustomUtility/Form/DuesOverride.tpl *}
{* Form for adding or editing a dues override on a member contact *}

<div class="crm-content-block crm-block dues-override-form">

  <h3>{ts}Set Dues Override{/ts}</h3>

  {if $contact_name}
    <div class="crm-section">
      <div class="label">{ts}Member{/ts}</div>
      <div class="content"><strong>{$contact_name}</strong></div>
    </div>
  {/if}

  <div class="crm-section">
    <div class="label">{ts}Staff Member{/ts}</div>
    <div class="content">{$staff_name}</div>
  </div>

  <hr />

  {* ------------------------------------------------------------------
     Override Type
     ------------------------------------------------------------------ *}
  <div class="crm-section" id="dues-override-type-section">
    <div class="label">
      <label for="is_permanent">{ts}Override Type{/ts}</label>
    </div>
    <div class="content">
      {$form.is_permanent.html}&nbsp;
      <label for="is_permanent">{ts}Permanent Override (applies to all years){/ts}</label>
      <div class="description">{ts}Leave unchecked to create a year-specific override.{/ts}</div>
    </div>
  </div>

  {* Year selector – hidden when Permanent is checked *}
  <div class="crm-section" id="dues-override-year-section">
    <div class="label">
      <label for="override_year">{ts}Override Year{/ts} <span class="crm-marker">*</span></label>
    </div>
    <div class="content">
      {$form.override_year.html}
      {if $form.override_year.error}
        <span class="crm-error">{$form.override_year.error}</span>
      {/if}
    </div>
  </div>

  {* ------------------------------------------------------------------
     Override Amount
     ------------------------------------------------------------------ *}
  <div class="crm-section">
    <div class="label">
      <label for="override_amount">{ts}Override Amount{/ts} <span class="crm-marker">*</span></label>
    </div>
    <div class="content">
      {$form.override_amount.html}
      {if $form.override_amount.error}
        <span class="crm-error">{$form.override_amount.error}</span>
      {/if}
    </div>
  </div>

  {* ------------------------------------------------------------------
     Override Reason
     ------------------------------------------------------------------ *}
  <div class="crm-section">
    <div class="label">
      <label for="override_reason">{ts}Reason{/ts} <span class="crm-marker">*</span></label>
    </div>
    <div class="content">
      {$form.override_reason.html}
      {if $form.override_reason.error}
        <span class="crm-error">{$form.override_reason.error}</span>
      {/if}
    </div>
  </div>

  {* ------------------------------------------------------------------
     Reason Notes
     ------------------------------------------------------------------ *}
  <div class="crm-section">
    <div class="label">
      <label for="reason_notes">{ts}Notes{/ts}</label>
    </div>
    <div class="content">
      {$form.reason_notes.html}
    </div>
  </div>

  {* ------------------------------------------------------------------
     Date Set
     ------------------------------------------------------------------ *}
  <div class="crm-section">
    <div class="label">
      <label for="date_set">{ts}Date Set{/ts} <span class="crm-marker">*</span></label>
    </div>
    <div class="content">
      {include file="CRM/common/jcalendar.tpl" elementName=date_set}
    </div>
  </div>

  {* Hidden fields *}
  {$form.contact_id.html}
  {$form.staff_member_id.html}

  {* ------------------------------------------------------------------
     Buttons
     ------------------------------------------------------------------ *}
  <div class="crm-submit-buttons">
    {include file="CRM/common/formButtons.tpl" location="bottom"}
  </div>

</div>

<script type="text/javascript">
  {literal}
  (function ($) {
    'use strict';

    // Toggle year selector based on "Permanent" checkbox.
    function toggleYearField() {
      if ($('#is_permanent').is(':checked')) {
        $('#dues-override-year-section').hide();
        $('#override_year').val('');
      } else {
        $('#dues-override-year-section').show();
      }
    }

    $(document).ready(function () {
      toggleYearField();
      $('#is_permanent').on('change', toggleYearField);
    });
  }(CRM.$));
  {/literal}
</script>
