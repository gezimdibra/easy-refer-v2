<!DOCTYPE html>
<html lang="en" xmlns="http://www.w3.org/1999/xhtml" xmlns:v="urn:schemas-microsoft-com:vml" xmlns:o="urn:schemas-microsoft-com:office">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light dark">
    <meta name="supported-color-schemes" content="light dark">
    <title>{{ $mailTitle }}</title>
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
            .em-card { background-color: #221d17 !important; border-color: #453d31 !important; }
            .em-text { color: #faf6ef !important; }
            .em-muted { color: #b4a88f !important; }
            .em-well { background-color: #29231a !important; }
            /* EU-S1 (R5 pack E1): the dark repair. The card lifts
               one step above the outer ground (#221d17 over
               #16130e); the wordmark flips to the cream text
               token; links take the light-amber token; the footer
               takes the dark muted token and hairline; the button
               goes amber with ink text (the app's dark action),
               so it can never vanish into the card again; the
               header rule goes amber. */
            .em-wordmark { color: #faf6ef !important; }
            .em-a { color: #f0b64a !important; }
            .em-foot { color: #b4a88f !important; border-top-color: #453d31 !important; }
            .em-btn { background-color: #f5a300 !important; }
            .em-btn a { color: #16130e !important; }
            .em-rule { border-top-color: #f5a300 !important; }
            .em-eyebrow { color: #f0b64a !important; }
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
{{-- D3 no-scan stall (Amendment 72, [M5-D3-*]): {days_live}
     comes from the first poster event; when it cannot be
     sourced the day clause is cut, per the amendment. --}}
                                <h1 class="em-text" style="margin: 0; font-size: 24px; line-height: 30px; font-weight: 650; letter-spacing: -0.02em; color: #16130e">Your poster is out. The register is its best spot</h1>
                                @if ($daysLive !== null)
                                <p class="em-text" style="margin: 16px 0 0; font-size: 16px; line-height: 26px; color: #16130e">Your code for {{ $business->name }} has been live for {{ $daysLive }} days, and no customer has scanned it yet. Posters at the register get scanned most, because customers see them while they pay. If your poster is anywhere else, try moving it there. You can also scan your own code and join with your own number, to see exactly what a customer sees.</p>
                                @else
                                <p class="em-text" style="margin: 16px 0 0; font-size: 16px; line-height: 26px; color: #16130e">Your code for {{ $business->name }} is live, and no customer has scanned it yet. Posters at the register get scanned most, because customers see them while they pay. If your poster is anywhere else, try moving it there. You can also scan your own code and join with your own number, to see exactly what a customer sees.</p>
                                @endif
                                @include('emails.partials.button', ['url' => $actionUrl, 'label' => 'See your placements'])
                                <p class="em-muted" style="margin: 24px 0 0; font-size: 14px; line-height: 22px; color: #453d31">Or open this link: <a href="{{ $actionUrl }}" class="em-a" style="color: #0d0b08; text-decoration: underline">{{ $actionUrl }}</a></p>
                                <p class="em-muted" style="margin: 24px 0 0; font-size: 14px; line-height: 22px; color: #453d31">You are getting this because setup for {{ $business->name }} is not finished. Turn off setup reminder emails in Settings, under Notifications. Payment and security emails are never turned off.</p>
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
