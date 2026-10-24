{{-- Shared mail header (redesign-spec.md Sections 8.6 and
     10.7): the PNG mark at 28px plus the wordmark as live text
     (the one sanctioned exception to the shared lockup component:
     email clients do not render SVG reliably), over a 3px
     brand-600 rule. --}}
<table role="presentation" width="100%" cellpadding="0" cellspacing="0">
    <tr>
        <td style="padding-bottom: 16px">
            <img src="{{ asset('images/mail-mark.png') }}" width="28" height="28" alt="" style="display: inline-block; vertical-align: middle; border-radius: 7px">
            <span style="display: inline-block; vertical-align: middle; margin-left: 8px; font-size: 20px; font-weight: 700; letter-spacing: -0.02em; color: #16130e">Easy Refer</span>
        </td>
    </tr>
    <tr>
        <td style="border-top: 3px solid #16130e; font-size: 0; line-height: 0">&nbsp;</td>
    </tr>
</table>
