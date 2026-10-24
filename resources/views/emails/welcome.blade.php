<!DOCTYPE html>
<html lang="en" xmlns="http://www.w3.org/1999/xhtml" xmlns:v="urn:schemas-microsoft-com:vml" xmlns:o="urn:schemas-microsoft-com:office:office">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light dark">
    <meta name="supported-color-schemes" content="light dark">
    <title>Welcome to Easy Refer: one step left before your code works</title>
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

                                <h1 class="em-text" style="margin: 0; font-size: 24px; line-height: 30px; font-weight: 650; letter-spacing: -0.02em; color: #16130e">Welcome to Easy Refer</h1>
                                <p class="em-text" style="margin: 16px 0 0; font-size: 16px; line-height: 26px; color: #16130e">Your referral program for {{ $business->name }} is set up, on the Pay Per Visit plan: you pay $2 only when a referred friend actually visits your store and redeems. There is no monthly fee, your first 5 verified visits are free, and you never pay more than $99 in a month. Nothing is charged today.</p>
                                @if ($verified)
                                    <p class="em-text" style="margin: 16px 0 0; font-size: 16px; line-height: 26px; color: #16130e">Your email address is verified, so your code is ready to go out.</p>
                                    @include('emails.partials.button', ['url' => $actionUrl, 'label' => $actionLabel])
                                    <p class="em-text" style="margin: 24px 0 0; font-size: 16px; line-height: 26px; color: #16130e">Set your two rewards, then print your poster and tent card from the QR page and put them out. Everything runs from <a href="{{ $dashboardUrl }}" style="color: #0d0b08; text-decoration: underline">your dashboard</a>.</p>
                                @else
                                    <p class="em-text" style="margin: 16px 0 0; font-size: 16px; line-height: 26px; color: #16130e">One step left before your code works: verify this email address.</p>
                                    @include('emails.partials.button', ['url' => $actionUrl, 'label' => $actionLabel])
                                    <p class="em-muted" style="margin: 24px 0 0; font-size: 14px; line-height: 22px; color: #453d31">Or open this link: <a href="{{ $actionUrl }}" style="color: #0d0b08; text-decoration: underline">{{ $actionUrl }}</a></p>
                                    <p class="em-text" style="margin: 16px 0 0; font-size: 16px; line-height: 26px; color: #16130e">Once verified, set your two rewards, then print your poster and tent card from the QR page and put them out. Everything runs from <a href="{{ $dashboardUrl }}" style="color: #0d0b08; text-decoration: underline">your dashboard</a>.</p>
                                @endif
                                <p class="em-muted" style="margin: 16px 0 0; font-size: 14px; line-height: 22px; color: #453d31">Questions? The <a href="{{ $faqUrl }}" style="color: #0d0b08; text-decoration: underline">FAQ page</a> is a good place to start.</p>

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
