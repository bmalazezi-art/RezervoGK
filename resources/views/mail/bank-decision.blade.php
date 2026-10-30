@extends('mail.layout')
@section('content')
<p>{{ __('Referenca e njoftimit:') }} <strong>{{ $reference }}</strong></p>
@if ($decision === 'approved')
<p>{{ __('Administratori ka verifikuar pagesën prej') }} <strong>€7 EUR</strong> {{ __('në bankë dhe ka miratuar një periudhë prej saktësisht') }} <strong>{{ __('30 ditësh') }}</strong> {{ __('për profilin tënd publik.') }}</p>
<p><strong>{{ __('Fillimi:') }}</strong> {{ $startsAt }}<br><strong>{{ __('Përfundimi:') }}</strong> {{ $endsAt }}<br>{{ __('Zona kohore:') }} {{ $timezone }}</p>
<p>{{ __('Koha e mbetur e provës ose periudhës së mëparshme ruhet. Periudha e re mund të fillojë më vonë; shiko gjendjen aktuale në panel.') }}</p>
@else
<p>{{ __('Njoftimi i pagesës u refuzua. Ky vendim nuk shton periudhë të re abonimi.') }}</p>
<p>{{ __('Shiko arsyen dhe hollësitë në panelin tënd të sigurt. Nëse ke ende provë ose periudhë të miratuar aktive, ajo vazhdon sipas afatit të vet.') }}</p>
@endif
<p>{{ __('Nuk ka rinovim ose debitim automatik. Pagesat e ndeshjeve mbeten në dorë mes organizatorit dhe portierit.') }}</p>
@endsection
