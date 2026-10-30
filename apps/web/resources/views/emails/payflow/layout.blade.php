<!DOCTYPE html>
<html lang="{{ $locale }}">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="color-scheme" content="light dark">
<title>{{ $subject }}</title>
<style>
body { margin: 0; padding: 0; background: #F7F8FA; color: {{ $ink }}; font-family: -apple-system, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; font-size: 15px; line-height: 1.6; }
.wrap { max-width: 600px; margin: 0 auto; padding: 24px 16px; }
.card { background: #FFFFFF; border-radius: 12px; border-top: 4px solid {{ $primary }}; padding: 28px 28px 20px; }
.brand { font-weight: 700; font-size: 16px; margin: 0 0 20px; color: {{ $ink }}; }
.content p { margin: 0 0 14px; }
.content a { color: {{ $primary }}; font-weight: 600; }
.footer { color: #5B6475; font-size: 12px; line-height: 1.5; padding: 16px 8px 0; }
.footer p { margin: 0 0 8px; }
.footer a { color: #5B6475; }
@media (prefers-color-scheme: dark) {
  body { background: #070B14; color: #E6E9EF; }
  .card { background: #111827; }
  .brand { color: #E6E9EF; }
  .content a { color: #34D399; }
  .footer, .footer a { color: #9AA3B2; }
}
</style>
</head>
<body>
<div class="wrap">
  <div class="card">
    <p class="brand">{{ $company }}</p>
    <div class="content">{!! $body !!}</div>
  </div>
  <div class="footer">
    @foreach ($footer as $line)
      <p>@if ($line['url'] !== null){!! str_replace(e($line['url']), '<a href="'.e($line['url']).'">'.e($line['url']).'</a>', e($line['text'])) !!}@else{{ $line['text'] }}@endif</p>
    @endforeach
  </div>
</div>
</body>
</html>
