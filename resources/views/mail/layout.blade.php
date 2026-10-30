<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="color-scheme" content="light"><title>{{ $title }}</title></head>
<body style="margin:0;padding:24px 12px;background:#f4f6f0;color:#172c25;font-family:Arial,Helvetica,sans-serif;line-height:1.6">
<div style="display:none;max-height:0;overflow:hidden">{{ $preheader }}</div>
<table role="presentation" style="width:100%;max-width:600px;margin:auto;border-collapse:collapse"><tr><td style="padding:24px;background:#172c25;color:#fff;font-size:22px;font-weight:bold">Gjej portier</td></tr>
<tr><td style="padding:28px 24px;background:#fff;border:1px solid #e2e7df;overflow-wrap:anywhere">
<h1 style="margin:0 0 20px;font-size:24px;line-height:1.3">{{ $title }}</h1>
<p>{{ __('Përshëndetje!') }}</p>
@yield('content')
<p style="margin:28px 0"><a href="{{ $actionUrl }}" style="display:inline-block;background:#c7f547;color:#172c25;padding:12px 20px;border-radius:6px;font-weight:bold;text-decoration:none">{{ $actionText }}</a></p>
<p style="font-size:13px">{{ __('Nëse nuk mund të hapësh butonin, kopjo këtë lidhje në shfletues:') }}<br><a href="{{ $actionUrl }}" style="color:#245c43;word-break:break-all">{{ $actionUrl }}</a></p>
<p>{{ __('Me respekt,') }}<br>{{ __('Ekipi Gjej portier') }}</p>
</td></tr><tr><td style="padding:20px;text-align:center;font-size:12px;color:#59665e">{{ __(\App\Support\LaunchModel::isFree() ? 'Njoftim për llogarinë tënde. Shërbimi i platformës është falas.' : 'Njoftim për llogarinë tënde. Pa rinovim ose debitim automatik.') }}<br>© {{ date('Y') }} Gjej portier. {{ __('Të gjitha të drejtat e rezervuara.') }}</td></tr></table>
</body></html>
