{if $coldtill_status == 'paid'}
  <p>{l s='Your crypto payment has been received.' d='Modules.Coldtill.Shop'}</p>
{elseif $coldtill_status == 'cancelled'}
  <p>{l s='This crypto payment was cancelled.' d='Modules.Coldtill.Shop'}</p>
{else}
  <p>
    {if $coldtill_status == 'partial'}
      {l s='Part of your crypto payment has arrived. Please pay the rest on the payment page.' d='Modules.Coldtill.Shop'}
    {else}
      {l s='Your order is waiting for your crypto payment. It updates by itself once the payment is confirmed.' d='Modules.Coldtill.Shop'}
    {/if}
  </p>
  {if $coldtill_url}
    <p><a class="btn btn-primary" href="{$coldtill_url|escape:'htmlall':'UTF-8'}">{l s='Go to the payment page' d='Modules.Coldtill.Shop'}</a></p>
  {/if}
{/if}
