<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Confirm your subscription</title>
</head>
<body style="font-family: system-ui, sans-serif; color: #0F172A; line-height: 1.5;">
    <p style="font-size: 20px; font-weight: 700;">Easy Refer</p>

    <p>Please confirm your subscription to Easy Refer updates.</p>

    <p>
        <a href="{{ $confirmUrl }}" style="display: inline-block; background: #2563EB; color: #FFFFFF; padding: 12px 20px; border-radius: 10px; text-decoration: none; font-weight: 600;">Confirm subscription</a>
    </p>

    <p>Or open this link: <a href="{{ $confirmUrl }}">{{ $confirmUrl }}</a></p>

    <p>If you did not ask for this, you can ignore this email.</p>
</body>
</html>
