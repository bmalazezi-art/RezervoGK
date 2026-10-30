@extends('mail.layout')
@section('content')
<p>{{ __('Po e merr këtë email sepse u kërkua rivendosja e fjalëkalimit të llogarisë sate.') }}</p>
<p>{{ __('Kjo lidhje skadon pas :minutes minutash dhe mund të përdoret vetëm një herë.', ['minutes' => $minutes]) }}</p>
<p>{{ __('Nëse nuk e ke kërkuar rivendosjen, mos e hap lidhjen. Fjalëkalimi yt mbetet i pandryshuar.') }}</p>
<p>{{ __('Mos e ndaj këtë lidhje, fjalëkalimin ose kodet e hyrjes me askënd.') }}</p>
@endsection
