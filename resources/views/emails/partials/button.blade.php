{{-- The one button per email (redesign-spec.md Section 10.7
     item 4): bulletproof table-cell construction, brand-600
     fill, white 16px 600 text, radius 10. Variables: $url, $label. --}}
<table role="presentation" cellpadding="0" cellspacing="0" style="margin-top: 24px">
    <tr>
        <td style="border-radius: 10px; background-color: #16130e">
            <a href="{{ $url }}" style="display: inline-block; padding: 14px 28px; font-size: 16px; font-weight: 600; line-height: 20px; color: #FFFFFF; text-decoration: none">{{ $label }}</a>
        </td>
    </tr>
</table>
