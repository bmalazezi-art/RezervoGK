<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="color-scheme" content="light"><title>{{ __('Kodi yt i hyrjes') }}</title></head>
<body style="margin:0;padding:24px 12px;background:#f4f6f0;color:#172c25;font-family:Arial,Helvetica,sans-serif;line-height:1.6">
<div style="display:none;max-height:0;overflow:hidden">{{ __('Kodi për hyrjen në administrim') }}</div>
<table role="presentation" style="width:100%;max-width:600px;margin:auto;border-collapse:collapse"><tr><td style="padding:24px;background:#172c25;color:#fff;font-size:22px;font-weight:bold">Gjej portier</td></tr>
<tr><td style="padding:28px 24px;background:#fff;border:1px solid #e2e7df">
<h1 style="margin:0 0 20px;font-size:24px;line-height:1.3">{{ __('Kodi yt i hyrjes') }}</h1>
<p>{{ __('Përdore këtë kod për të përfunduar hyrjen në panelin e administrimit të Gjej portier:') }}</p>
<p style="margin:24px 0;font-size:34px;font-weight:bold;letter-spacing:8px;font-family:'Courier New',monospace">{{ $code }}</p>
<p>{{ __('Kodi vlen :minutes minuta dhe mund të përdoret vetëm një herë.', ['minutes' => $minutes]) }}</p>
<p>{{ __('Nëse nuk po provon të hysh, ndrysho fjalëkalimin menjëherë.') }}</p>
<p>{{ __('Me respekt,') }}<br>{{ __('Ekipi Gjej portier') }}</p>
</td></tr><tr><td style="padding:20px;text-align:center;font-size:12px;color:#59665e">© {{ date('Y') }} Gjej portier. {{ __('Të gjitha të drejtat e rezervuara.') }}</td></tr></table>
</body>
</html>
