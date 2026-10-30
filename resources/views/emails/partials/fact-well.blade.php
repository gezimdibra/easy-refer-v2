{{-- The E3 key-fact well (R5 pack B0): label/value rows for the
     2-3 facts an action or money email turns on, in the shared
     .em-well treatment. Variables: $rows = [[label, value], ...]. --}}
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin-top: 24px">
    <tr>
        <td class="em-well" bgcolor="#faf6ef" style="border-radius: 12px; padding: 16px">
            @foreach ($rows as $i => [$label, $value])
                <p style="margin: {{ $i === 0 ? '0' : '12px' }} 0 0; font-size: 12px; line-height: 16px; font-weight: 600; letter-spacing: 0.09em; text-transform: uppercase; color: #66594a">{{ $label }}</p>
                <p class="em-text" style="margin: 4px 0 0; font-size: 16px; line-height: 26px; font-weight: 600; color: #16130e">{{ $value }}</p>
            @endforeach
        </td>
    </tr>
</table>
