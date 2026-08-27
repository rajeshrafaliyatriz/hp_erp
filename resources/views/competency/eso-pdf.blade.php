{{--
    The human-readable ESO — a printable standard operating procedure.

    Deliberately plain. This is a document that goes into a quality binder or in
    front of an auditor, not a marketing page. dompdf supports a limited subset
    of CSS, so everything here is tables and simple blocks rather than flexbox.

    THE STATUS BANNER IS NOT DECORATION. A printed page has no surrounding UI to
    tell the reader that this was written by a machine and never reviewed. If
    that warning is not on the paper, it does not exist.
--}}
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>{{ $eso->title }}</title>
    <style>
        @page { margin: 22mm 18mm 20mm 18mm; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 10.5px; color: #1a1a1a; line-height: 1.5; }
        h1 { font-size: 19px; margin: 0 0 2px 0; }
        h2 { font-size: 12.5px; margin: 18px 0 6px 0; padding-bottom: 3px;
             border-bottom: 1px solid #d4d4d4; text-transform: uppercase; letter-spacing: .04em; }
        p { margin: 0 0 8px 0; }
        ul { margin: 0 0 8px 0; padding-left: 16px; }
        li { margin-bottom: 4px; }
        table { width: 100%; border-collapse: collapse; margin-bottom: 8px; }
        th { background: #f2f2f2; text-align: left; font-size: 9.5px; text-transform: uppercase;
             letter-spacing: .04em; padding: 5px 6px; border: 1px solid #d4d4d4; }
        td { padding: 5px 6px; border: 1px solid #d4d4d4; vertical-align: top; }
        .meta { width: 100%; margin-bottom: 4px; font-size: 9.5px; color: #555; }
        .meta td { border: none; padding: 1px 0; }
        .banner { border: 1.5px solid #b91c1c; background: #fef2f2; color: #7f1d1d;
                  padding: 9px 11px; margin: 12px 0 4px 0; }
        .banner strong { font-size: 11.5px; }
        .prohibited { border: 1px solid #b91c1c; background: #fefafa; padding: 8px 11px; }
        .prohibited li { color: #7f1d1d; }
        .muted { color: #666; font-style: italic; }
        .actor { font-size: 9px; font-weight: bold; white-space: nowrap; }
        .foot { margin-top: 22px; padding-top: 7px; border-top: 1px solid #d4d4d4;
                font-size: 8.5px; color: #777; }
    </style>
</head>
<body>

<h1>{{ $eso->title }}</h1>

<table class="meta">
    <tr>
        <td><strong>Version</strong> {{ $eso->version }} &nbsp;·&nbsp; <strong>Status</strong> {{ $eso->status }}</td>
        <td style="text-align:right">{{ $eso->scope }}{{ $eso->execution_mode ? ' · ' . $eso->execution_mode : '' }}</td>
    </tr>
    @if ($task)
        <tr>
            <td colspan="2"><strong>Task</strong> {{ $task->task }}</td>
        </tr>
        <tr>
            <td colspan="2"><strong>Job role</strong> {{ $task->jobrole }}{{ $task->critical_work_function ? ' · ' . $task->critical_work_function : '' }}</td>
        </tr>
    @endif
</table>

{{-- THE WARNING TRAVELS WITH THE PAPER. Nothing else on a printed page
     distinguishes a reviewed procedure from a machine's first draft. --}}
@if ($eso->status !== 'Published')
    <div class="banner">
        <strong>NOT APPROVED FOR USE — this document is {{ strtoupper($eso->status) }}.</strong><br>
        @if ($unreviewed)
            It was written by {{ $eso->model ?? 'AI' }} and no person has reviewed it.
            Do not follow these steps and do not give them to an agent.
        @else
            It has not been published, so it is not yet the agreed way of doing this work.
        @endif
    </div>
@endif

@if ($eso->execution_mode)
    <h2>How this work is executed</h2>
    <p><strong>{{ $eso->execution_mode }}</strong> — {{ $modeLabel }}</p>
@endif

@if ($eso->objective)
    <h2>Objective</h2>
    <p>{{ $eso->objective }}</p>
@endif

@if ($eso->expected_outcome)
    <h2>Expected outcome</h2>
    <p>{{ $eso->expected_outcome }}</p>
@endif

<h2>Who is responsible</h2>
<table>
    <tr>
        <th style="width:50%">Person</th>
        <th style="width:50%">Machine</th>
    </tr>
    <tr>
        <td>{{ $eso->human_responsibility ?: '—' }}</td>
        <td>{{ $eso->agent_responsibility ?: 'None. This task is performed by a person.' }}</td>
    </tr>
</table>

@if ($lists['steps'])
    <h2>Procedure</h2>
    <table>
        <tr>
            <th style="width:5%">#</th>
            <th style="width:13%">Who</th>
            <th style="width:47%">Step</th>
            <th style="width:17%">Tool</th>
            <th style="width:18%">Output</th>
        </tr>
        @foreach ($lists['steps'] as $i => $step)
            <tr>
                <td>{{ $step['seq'] ?? $i + 1 }}</td>
                <td class="actor">{{ $actorLabel[$step['actor'] ?? ''] ?? ($step['actor'] ?? '—') }}</td>
                <td>{{ $step['description'] ?? '' }}</td>
                <td>{{ $step['tool'] ?? '—' }}</td>
                <td>{{ $step['output'] ?? '—' }}</td>
            </tr>
        @endforeach
    </table>
@endif

@if ($lists['human_decision_points'])
    <h2>A person must decide</h2>
    <ul>
        @foreach ($lists['human_decision_points'] as $item)
            <li>{{ is_string($item) ? $item : json_encode($item) }}</li>
        @endforeach
    </ul>
@endif

@if ($lists['required_controls'])
    <h2>Required controls</h2>
    <ul>
        @foreach ($lists['required_controls'] as $item)
            <li>{{ is_string($item) ? $item : json_encode($item) }}</li>
        @endforeach
    </ul>
@endif

{{-- Boxed and in red because this is the section people skim past and the one
     that matters when something has gone wrong. --}}
<h2>Prohibited actions</h2>
@if ($lists['prohibited_actions'])
    <div class="prohibited">
        <ul>
            @foreach ($lists['prohibited_actions'] as $item)
                <li>{{ is_string($item) ? $item : json_encode($item) }}</li>
            @endforeach
        </ul>
    </div>
@else
    <p class="muted">None recorded. An execution model with no prohibitions has not been thought through.</p>
@endif

@if ($lists['escalation_triggers'])
    <h2>Stop and hand over when</h2>
    <ul>
        @foreach ($lists['escalation_triggers'] as $item)
            <li>{{ is_string($item) ? $item : json_encode($item) }}</li>
        @endforeach
    </ul>
@endif

@if ($lists['inputs'] || $lists['outputs'])
    <h2>Inputs and outputs</h2>
    <table>
        <tr><th style="width:14%">Direction</th><th style="width:28%">Name</th>
            <th style="width:30%">Source / destination</th><th style="width:28%">Format</th></tr>
        @foreach ($lists['inputs'] as $x)
            <tr>
                <td>Input</td>
                <td>{{ $x['name'] ?? '—' }}</td>
                <td>{{ $x['source'] ?? '—' }}</td>
                <td>{{ $x['format'] ?? '—' }}{{ ($x['required'] ?? false) ? ' (required)' : '' }}</td>
            </tr>
        @endforeach
        @foreach ($lists['outputs'] as $x)
            <tr>
                <td>Output</td>
                <td>{{ $x['name'] ?? '—' }}</td>
                <td>{{ $x['destination'] ?? '—' }}</td>
                <td>{{ $x['format'] ?? '—' }}</td>
            </tr>
        @endforeach
    </table>
@endif

<h2>Evidence emitted</h2>
@if ($lists['evidence_emitted'])
    <ul>
        {{-- Built as one expression rather than adjacent inline @if/@endif:
             Blade mis-parses `@endif@if` with no separator between them. --}}
        @foreach ($lists['evidence_emitted'] as $x)
            @php
                $line = $x['evidence_type'] ?? 'Evidence';
                if (!empty($x['competency_id'])) { $line .= ' — competency #' . $x['competency_id']; }
                if (!empty($x['format'])) { $line .= ' (' . $x['format'] . ')'; }
            @endphp
            <li>{{ $line }}</li>
        @endforeach
    </ul>
@else
    <p class="muted">Not yet defined. Performing this task currently proves nothing in the capability record.</p>
@endif

<div class="foot">
    ESO #{{ $eso->id }} · version {{ $eso->version }} · {{ $eso->status }} ·
    written by {{ $eso->source === 'ai-generated' ? ($eso->model ?? 'AI') : 'a person' }} ·
    exported {{ now()->format('j F Y, H:i') }}
</div>

</body>
</html>
