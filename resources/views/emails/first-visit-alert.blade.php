<!DOCTYPE html>
<html lang="en" xmlns="http://www.w3.org/1999/xhtml" xmlns:v="urn:schemas-microsoft-com:vml" xmlns:o="urn:schemas-microsoft-com:office:office">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light dark">
    <meta name="supported-color-schemes" content="light dark">
    <title>Your first referred customer just visited</title>
    <style>
        /* The well (PE-05, PE-06): one cascade, so the rendered
           color is deterministic in every client. The light value
           (the surface-1 token) lives here as a class rule and on
           the cell as its bgcolor attribute for clients that drop
           style blocks; it never sits in an inline style, where it
           used to fight the dark rule below. The dark value is
           the registered mail-well-dark token (#29231a) from the
           app.css token table. */
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

                                <h1 class="em-text" style="margin: 0; font-size: 24px; line-height: 30px; font-weight: 650; letter-spacing: -0.02em; color: #16130e">Your first referred customer just visited</h1>
                                <p class="em-text" style="margin: 16px 0 0; font-size: 16px; line-height: 26px; color: #16130e">Good news: your first referred customer just visited {{ $business->name }}. That is Easy Refer working.</p>
                                {{-- B1 (R5 pack): the one-row "detail table"
                                     is retired; the lead line already
                                     names the business, and a table
                                     built for facts it does not have
                                     only padded the celebration. --}}
                                @include('emails.partials.button', ['url' => $dashboardUrl, 'label' => 'See your results'])

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
