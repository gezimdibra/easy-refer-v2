<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>@yield('title', 'Your shop preview') | Easy Refer</title>
<style>
  body { font-family: system-ui, sans-serif; margin: 0; color: #1c1917; background: #fff; }
  main { max-width: 34rem; margin: 0 auto; padding: 1rem; }
  .preview-strip { background: #fef3c7; border: 1px solid #f59e0b; border-radius: .5rem; padding: .75rem; font-size: .9rem; }
  .demo-chip { display: inline-block; background: #1c1917; color: #fbbf24; border-radius: 999px; padding: .1rem .6rem; font-size: .8rem; font-weight: 700; }
  .btn { display: inline-block; background: #1c1917; color: #fff; padding: .7rem 1.2rem; border-radius: .5rem; text-decoration: none; border: 0; font-size: 1rem; cursor: pointer; }
  .poster { border: 2px solid #1c1917; border-radius: .75rem; padding: 1.25rem; text-align: center; }
  .poster .art-line { background: #f59e0b; font-weight: 700; padding: .25rem .5rem; }
  .error { color: #b91c1c; }
  footer { margin-top: 2.5rem; font-size: .85rem; color: #57534e; }
</style>
</head>
<body>
<main>
@yield('content')
</main>
</body>
</html>
