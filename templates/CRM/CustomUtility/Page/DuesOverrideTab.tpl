{*
 * Template: DuesOverrideTab.tpl
 * Controller: CRM_CustomUtility_Page_DuesOverrideTab
 *
 * Displays the Dues Management tab on a CiviCRM contact record.
 * Shows:
 *   - Current dues year and calculated / effective dues.
 *   - Active override indicator.
 *   - Full list of all override records with edit links.
 *   - Full list of all salary declaration records.
 *}

<div class="crm-container ra-dues-management">

  <h2>{ts}Dues Management – {$duesYear}{/ts}</h2>

  {* =========================================================
     Current-year declaration summary
     ========================================================= *}
  <div class="crm-section ra-dues-declaration-summary">
    <h3>{ts}Current-Year Declaration{/ts}</h3>
    {if $declaration}
      <table class="crm-info-panel ra-dues-table">
        <tr>
          <td class="label">{ts}Declaration Year{/ts}</td>
          <td>{$declaration.declaration_year|escape}</td>
        </tr>
        <tr>
          <td class="label">{ts}Declaration Date{/ts}</td>
          <td>{$declaration.declaration_date|crmDate}</td>
        </tr>
        <tr>
          <td class="label">{ts}Salary Declared{/ts}</td>
          <td>{$declaration.salary_declared|crmMoney}</td>
        </tr>
        <tr>
          <td class="label">{ts}Status Category{/ts}</td>
          <td>{$declaration.status_category|capitalize|escape}</td>
        </tr>
        <tr>
          <td class="label">{ts}Calculated Dues{/ts}</td>
          <td class="ra-dues-amount">{$calculatedDues}</td>
        </tr>
      </table>
    {else}
      <div class="crm-notice">
        {ts}No salary declaration found for {$duesYear}.{/ts}
        &nbsp;
        <a href="{crmURL p='civicrm/contact/dues-management' q="reset=1&cid=$contactId"}">{ts}Refresh{/ts}</a>
      </div>
    {/if}
  </div>

  {* =========================================================
     Effective dues (with override indicator if applicable)
     ========================================================= *}
  <div class="crm-section ra-dues-effective">
    <h3>{ts}Effective Dues Amount{/ts}</h3>
    <div class="ra-dues-effective-amount {if $hasOverride}ra-dues-overridden{else}ra-dues-standard{/if}">
      {$effectiveDues}
      {if $hasOverride}
        &nbsp;<span class="crm-badge ra-dues-override-badge">{ts}Override Applied{/ts}</span>
      {/if}
    </div>
    {if $hasOverride && $activeOverride}
      <div class="ra-dues-override-detail crm-section">
        <strong>{ts}Active Override:{/ts}</strong>
        {if $activeOverride.is_permanent}
          {ts}Permanent –{/ts}
        {else}
          {ts 1=$activeOverride.override_year}Year-specific (%1) –{/ts}
        {/if}
        {$activeOverride.override_amount|crmMoney}
        &nbsp;({$activeOverride.override_reason|escape})
      </div>
    {/if}
  </div>

  {* =========================================================
     Override records
     ========================================================= *}
  <div class="crm-section ra-dues-overrides">
    <h3>
      {ts}Dues Overrides{/ts}
      &nbsp;
      <a href="{$addOverrideUrl}" class="button ra-button-primary">
        <span>{ts}+ Add Override{/ts}</span>
      </a>
    </h3>

    {if $allOverrides}
      <table class="crm-datatable ra-dues-table" id="ra-overrides-table">
        <thead>
          <tr>
            <th>{ts}Year{/ts}</th>
            <th>{ts}Amount{/ts}</th>
            <th>{ts}Type{/ts}</th>
            <th>{ts}Reason{/ts}</th>
            <th>{ts}Notes{/ts}</th>
            <th>{ts}Set By{/ts}</th>
            <th>{ts}Date Set{/ts}</th>
            <th>{ts}Actions{/ts}</th>
          </tr>
        </thead>
        <tbody>
          {foreach from=$allOverrides item=override}
            <tr class="{if $override.is_permanent}ra-override-permanent{else}ra-override-yearly{/if}">
              <td>
                {if $override.is_permanent}
                  <em>{ts}All years{/ts}</em>
                {else}
                  {$override.override_year|escape}
                {/if}
              </td>
              <td class="ra-dues-amount">{$override.override_amount_formatted}</td>
              <td>{$override.is_permanent_label}</td>
              <td>{$override.reason_label|escape}</td>
              <td>{$override.reason_notes|escape|nl2br}</td>
              <td>
                {if $override.staff_member_name}
                  {$override.staff_member_name|escape}
                {else}
                  &mdash;
                {/if}
              </td>
              <td>{$override.date_set|crmDate}</td>
              <td>
                <a href="{$override.edit_url}">{ts}Edit{/ts}</a>
              </td>
            </tr>
          {/foreach}
        </tbody>
      </table>
    {else}
      <div class="crm-notice">{ts}No overrides on record for this contact.{/ts}</div>
    {/if}
  </div>

  {* =========================================================
     Declaration history
     ========================================================= *}
  <div class="crm-section ra-dues-declaration-history">
    <h3>{ts}Declaration History{/ts}</h3>
    {if $allDeclarations}
      <table class="crm-datatable ra-dues-table" id="ra-declarations-table">
        <thead>
          <tr>
            <th>{ts}Year{/ts}</th>
            <th>{ts}Date{/ts}</th>
            <th>{ts}Salary{/ts}</th>
            <th>{ts}Status{/ts}</th>
            <th>{ts}Calculated Dues{/ts}</th>
            <th>{ts}Job Change{/ts}</th>
          </tr>
        </thead>
        <tbody>
          {foreach from=$allDeclarations item=decl}
            <tr>
              <td>{$decl.declaration_year|escape}</td>
              <td>{$decl.declaration_date|crmDate}</td>
              <td>{$decl.salary_declared|crmMoney}</td>
              <td>{$decl.status_category|capitalize|escape}</td>
              <td class="ra-dues-amount">{$decl.calculated_dues|crmMoney}</td>
              <td>{if $decl.job_change_flag}{ts}Yes{/ts}{else}{ts}No{/ts}{/if}</td>
            </tr>
          {/foreach}
        </tbody>
      </table>
    {else}
      <div class="crm-notice">{ts}No declaration history found.{/ts}</div>
    {/if}
  </div>

</div>{* .ra-dues-management *}
