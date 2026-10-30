<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Your Easy Refer program has ended</title>
</head>
<body style="margin: 0; padding: 0; font-family: -apple-system, 'Segoe UI', Roboto, Arial, sans-serif; color: #16130e">
    {{-- E-10 (FE-CANCEL-01, Amendment 69): the end confirmation.
         The bill section is composed by the mailable as one line
         per outcome leg (charged / could-not-collect / written
         off), each with its own figure, so a mixed outcome is
         told in full inside the ONE email. --}}
    <p>Your program ended on {{ $endedDate }}. Your QR code is off.</p>
    @foreach ($billLines as $billLine)
        <p>{{ $billLine }}</p>
    @endforeach
    <p>Rewards your customers already earned stay good until {{ $claimUntil }}, or until a reward's own use by date if that comes first. After that they cannot be claimed.</p>
    <p>You can still sign in and see your history. Nothing is deleted.</p>
    <p>If you have posters or cards out, you can take them down now. Scans will show your program has ended.</p>
</body>
</html>
