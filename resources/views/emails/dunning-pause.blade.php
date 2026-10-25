<!DOCTYPE html>
<html lang="en" xmlns="http://www.w3.org/1999/xhtml" xmlns:v="urn:schemas-microsoft-com:vml" xmlns:o="urn:schemas-microsoft-com:office">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light dark">
    <meta name="supported-color-schemes" content="light dark">
    <title>Your Easy Refer billing pauses on {{ $pauseDate }}</title>
    <style>
        /* The well (PE-05, PE-06): one cascade, so the rendered
           color is deterministic in every client. The light value
           (the surface-1 token) lives here as a class rule and on
           the cell as its bgcolor attribute for clients that drop
           style blocks; never in an inline style. The dark value
           is the registered mail-well-dark token (#29231a) from
           the app.css token table. This template renders no well
           today; the rule keeps the shared shell's style block
           identical across the custom templates. */
        .em-well { background-color: #faf6ef; }
        @media only screen and (max-width: 620px) {
            .em-card { padding: 24px !important; }
        }
        @media (prefers-color-scheme: dark) {
            .em-outer { background-color: #16130e !important; }
            .em-card { background-color: #16130e !important; border-color: #453d31 !important; }
            .em-text { color: #faf6ef !important; }
            .em-muted { color: #b4a88f !important; }
            .em-well { background-color: #29231a !important; }
        }
    </style>
</head>
<body class="em-outer" style="margin: 0; padding: 0; background-color: #faf6ef; font-family: -apple-system, 'Segoe UI', Roboto, 'Inter', Arial, sans-serif; color: #16130e">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color: #faf6ef" class="em-outer">
        <tr>
            <td align="center" style="padding: 32px 16px">
                <table role="presentation" width="600" cellpadding="0" cellspacing="0" style="width: 600px; max-width: 100%">
                    <tr>
                        <td class="em-card" style="background-color: #FFFFFF; border: 1px solid #e7dfce; border-radius: 16px; padding: 32px">
                            @include('emails.partials.header')
                            <div style="padding-top: 24px">
{{-- Dunning day 6 (spec Section 9.6; subject DUN-04, body DUN-02 + the DUN-05 line, verbatim). --}}
                                <p class="em-text" style="margin: 0; font-size: 16px; line-height: 26px; color: #16130e">Your Easy Refer charge of {{ $amount }} did not go through. Your QR code keeps working until {{ $graceEndDate }}. Update your card to keep billing current: <a href="{{ $billingUrl }}" style="color: #0d0b08; text-decoration: underline">{{ $billingUrl }}</a></p>
                                <p class="em-text" style="margin: 16px 0 0; font-size: 16px; line-height: 26px; color: #16130e">If the charge does not succeed, new signups pause on {{ $pauseDate }}. Customers who already earned rewards can still redeem them.</p>
                            </div>
                            @include('emails.partials.footer')
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
