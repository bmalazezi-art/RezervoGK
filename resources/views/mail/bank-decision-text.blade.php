Gjej portier — {{ $title }}

{{ __('Përshëndetje!') }}
{{ __('Referenca e njoftimit:') }} {{ $reference }}
@if ($decision === 'approved')
{{ __('Administratori ka verifikuar pagesën prej €7 EUR në bankë dhe ka miratuar saktësisht 30 ditë qasje publike.') }}
{{ __('Fillimi:') }} {{ $startsAt }}
{{ __('Përfundimi:') }} {{ $endsAt }}
{{ __('Zona kohore:') }} {{ $timezone }}
{{ __('Koha e mbetur e provës ose periudhës së mëparshme ruhet. Periudha e re mund të fillojë më vonë; shiko gjendjen aktuale në panel.') }}
@else
{{ __('Njoftimi i pagesës u refuzua. Ky vendim nuk shton periudhë të re abonimi.') }}
{{ __('Shiko arsyen dhe hollësitë në panelin tënd të sigurt. Nëse ke ende provë ose periudhë të miratuar aktive, ajo vazhdon sipas afatit të vet.') }}
@endif

{{ __('Hap panelin:') }}
{!! $dashboardUrl !!}

{{ __('Nuk ka rinovim ose debitim automatik. Pagesat e ndeshjeve mbeten në dorë mes organizatorit dhe portierit.') }}
{{ __('Me respekt,') }}
{{ __('Ekipi Gjej portier') }}
{{ __('Të gjitha të drejtat e rezervuara.') }}
