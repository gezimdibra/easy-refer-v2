@props(['url'])
<tr>
<td class="header">
<a href="{{ $url }}" style="display: inline-block;">
<img src="{{ asset('images/mail-mark.png') }}" width="28" height="28" alt="" style="display: inline-block; vertical-align: middle; border-radius: 7px; border: none;">
<span style="display: inline-block; vertical-align: middle; margin-left: 8px;">{!! $slot !!}</span>
</a>
</td>
</tr>
<tr>
<td style="padding: 0 32px;">
<table align="center" width="100%" cellpadding="0" cellspacing="0" role="presentation">
<tr><td style="border-top: 3px solid #16130E; font-size: 0; line-height: 0;">&nbsp;</td></tr>
</table>
</td>
</tr>
