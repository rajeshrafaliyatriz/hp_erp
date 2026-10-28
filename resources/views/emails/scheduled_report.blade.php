<p>Your scheduled report <strong>{{ $reportTitle }}</strong> has run.</p>

@if($reportDescription)
<p>{{ $reportDescription }}</p>
@endif

<p>{{ $rowCount }} {{ $rowCount === 1 ? 'row' : 'rows' }} — see the attached CSV for the full result.</p>
