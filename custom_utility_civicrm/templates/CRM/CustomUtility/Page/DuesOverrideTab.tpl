{* CRM/CustomUtility/Page/DuesOverrideTab.tpl *}
{* Dues Management – contact tab showing declaration summary, effective dues, and override history *}

<div class="crm-content-block crm-block dues-management-tab">

  <h3>{ts}Dues Management{/ts} – {$dues_year}</h3>

  {* ------------------------------------------------------------------ *}
  {* Current Declaration Summary                                          *}
  {* ------------------------------------------------------------------ *}
  <div class="crm-accordion-wrapper crm-dues-declaration-summary">
    <div class="crm-accordion-header">{ts}Current Salary Declaration{/ts}</div>
    <div class="crm-accordion-body">
      {if $declaration}
        <table class="crm-info-panel">
          <tr>
            <td class="label">{ts}Declaration Year{/ts}</td>
            <td>{$declaration.declaration_year}</td>
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
            <td>{$declaration.status_category}</td>
          </tr>
          <tr>
            <td class="label">{ts}Calculated Dues{/ts}</td>
            <td>{$declaration.calculated_dues|crmMoney}</td>
          </tr>
          {if $declaration.job_change_flag}
          <tr>
            <td class="label">{ts}Job Change{/ts}</td>
            <td><span class="crm-marker">&#10003;</span> {ts}Reported{/ts}</td>
          </tr>
          {/if}
        </table>
      {else}
        <p class="status-notice">{ts}No salary declaration found for {$dues_year}.{/ts}</p>
      {/if}
    </div>
  </div>

  {* ------------------------------------------------------------------ *}
  {* Effective Dues                                                       *}
  {* ------------------------------------------------------------------ *}
  <div class="crm-accordion-wrapper crm-dues-effective">
    <div class="crm-accordion-header">{ts}Effective Dues{/ts}</div>
    <div class="crm-accordion-body">
      {if $effective_dues}
        <table class="crm-info-panel">
          <tr>
            <td class="label">{ts}Amount{/ts}</td>
            <td class="dues-effective-amount">{$effective_dues.amount|crmMoney}</td>
          </tr>
          <tr>
            <td class="label">{ts}Basis{/ts}</td>
            <td>
              {if $effective_dues.type eq 'override_permanent'}
                <span class="crm-tag dues-override-permanent">{ts}Permanent Override{/ts}</span>
              {elseif $effective_dues.type eq 'override_year_specific'}
                <span class="crm-tag dues-override-year">{ts}Year-Specific Override{/ts}</span>
              {else}
                {ts}Calculated from declared salary{/ts}
              {/if}
            </td>
          </tr>
          {if $effective_dues.reason}
          <tr>
            <td class="label">{ts}Reason{/ts}</td>
            <td>{$effective_dues.reason}</td>
          </tr>
          {/if}
        </table>
      {else}
        <p class="status-notice">{ts}No effective dues could be determined. A salary declaration may be required.{/ts}</p>
      {/if}
    </div>
  </div>

  {* ------------------------------------------------------------------ *}
  {* Override History                                                     *}
  {* ------------------------------------------------------------------ *}
  <div class="crm-accordion-wrapper crm-dues-overrides">
    <div class="crm-accordion-header">{ts}Override History{/ts}</div>
    <div class="crm-accordion-body">
      <div class="action-link">
        <a href="{$add_override_url}" class="button"><span>{ts}Add Override{/ts}</span></a>
      </div>
      {if $overrides}
        <table class="crm-datatable" id="dues-override-table">
          <thead>
            <tr>
              <th>{ts}Year / Type{/ts}</th>
              <th>{ts}Amount{/ts}</th>
              <th>{ts}Reason{/ts}</th>
              <th>{ts}Notes{/ts}</th>
              <th>{ts}Staff Member{/ts}</th>
              <th>{ts}Date Set{/ts}</th>
              <th>{ts}Actions{/ts}</th>
            </tr>
          </thead>
          <tbody>
            {foreach from=$overrides item=override}
            <tr class="{cycle values='odd-row,even-row'}">
              <td>
                {if $override.is_permanent}
                  <strong>{ts}Permanent{/ts}</strong>
                {else}
                  {$override.override_year}
                {/if}
              </td>
              <td>{$override.override_amount|crmMoney}</td>
              <td>{$override.override_reason}</td>
              <td>{$override.reason_notes}</td>
              <td>{$override.staff_name}</td>
              <td>{$override.date_set|crmDate}</td>
              <td>
                <a href="{crmURL p='civicrm/contact/dues-management/override' q="cid=`$contact_id`&override_id=`$override.id`&reset=1"}">{ts}Edit{/ts}</a>
              </td>
            </tr>
            {/foreach}
          </tbody>
        </table>
      {else}
        <p class="status-notice">{ts}No overrides on record.{/ts}</p>
      {/if}
    </div>
  </div>

</div>
