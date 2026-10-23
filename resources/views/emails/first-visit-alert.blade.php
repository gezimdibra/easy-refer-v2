<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Your first referred customer just visited</title>
</head>
<body style="font-family: system-ui, sans-serif; color: #0F172A; line-height: 1.5;">
    <p style="font-size: 20px; font-weight: 700;">Easy Refer</p>

    <p>Good news: your first referred customer just visited {{ $business->name }}. That is Easy Refer working. See your results: <a href="{{ $dashboardUrl }}">{{ $dashboardUrl }}</a></p>
</body>
</html>
