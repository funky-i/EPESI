<br>
{$form_open}
<table id="Utils_LeightboxPrompt__form" cellspacing="0" cellpadding="0" style="width:70%">
	{foreach item=e from=$form_data}
		{if isset($e.label) && !is_string($e) && $e.type!='hidden' && $e.name!='submit' && $e.name!='cancel'}
			<tr>
			    <td rowspan="2" class="label" nowrap="1">
			    	{$e.label}
				</td>
				<td style="color:red;padding-left:5px;text-align:left;">
					{$e.error}
			    </td>
			</tr>
			<tr>
				<td style="padding-left:5px;" id="{$e.name}__leightbox_prompt__data_span">
					{$e.html}
				</td>
			</tr>
		{/if}
	{/foreach}
	<tr  class="Utils_LeightboxPrompt__form_button">
	    <td style="float:right;">
	    	{$form_data.cancel.html}
		</td>
		<td style="padding-left:5px;">
			{$form_data.submit.html}
		</td>
	</tr>
</table>
{$form_close}