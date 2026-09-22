{{--
    The A4 hiring poster — the printable one.

    ── WHY THIS LOOKS DIFFERENT FROM THE SOCIAL SIZES ──────────────────────────

    The three PNG sizes are drawn by Satori in the Next app; this one is drawn by
    dompdf, because dompdf cannot rasterise and Satori cannot emit PDF. Two
    renderers is unavoidable.

    What is avoidable is two opinions about the advert. Every string below is
    already final: PosterContentService parsed the description, applied the A4
    caps, truncated, chose the fallbacks and picked the layout. This template
    contains no caps, no truncation and no content rules — only `@if(count(...))`
    on arrays that were already decided. That is what keeps the PDF and the PNGs
    saying the same thing about the job.

    dompdf supports a small subset of CSS: no flexbox, no grid, no custom
    properties. Everything here is tables and blocks, with hex colours passed
    down from config/poster.php.

    The logo arrives as a data URI because dompdf runs with enable_remote off
    and silently draws nothing for a remote <img>. It can also be null — GD is
    not a declared dependency of this project and dompdf throws on a PNG without
    it, so the service withholds the image rather than risk a 500 on a download.
--}}
@php
    $p = $poster['palette'];
    $copy = $poster['copy'];
    $role = $poster['roles'][0] ?? null;
    $multi = count($poster['roles']) > 1;
@endphp
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>{{ $role['title'] ?? 'We are hiring' }}</title>
    <style>
        @page { margin: 0; }
        body { font-family: DejaVu Sans, sans-serif; margin: 0; padding: 0;
               color: {{ $p['ink'] }}; background: {{ $p['paper'] }}; }

        .sheet { padding: 16mm 15mm 0 15mm; }

        .band { background: {{ $p['navy'] }}; color: #ffffff; padding: 9mm 15mm 8mm 15mm; }
        .brandrow { width: 100%; }
        .brandrow td { vertical-align: middle; padding: 0; }
        .orgname { font-size: 13px; font-weight: bold; color: #ffffff; }
        .orgweb { font-size: 9px; color: #b9c7e4; }
        .mark { width: 34px; height: 34px; background: {{ $p['blue'] }}; color: #ffffff;
                font-size: 13px; font-weight: bold; text-align: center; line-height: 34px; }

        .headline { font-size: 40px; font-weight: bold; color: #ffffff;
                    letter-spacing: -0.5px; margin: 7mm 0 1mm 0; }
        .kicker { font-size: 11px; color: {{ $p['blue'] }}; letter-spacing: 2px;
                  text-transform: uppercase; font-weight: bold; }

        .rolebox { margin-top: 7mm; }
        .eyebrow { font-size: 9px; letter-spacing: 1.6px; text-transform: uppercase;
                   color: {{ $p['muted'] }}; font-weight: bold; margin-bottom: 2mm; }
        .title { font-weight: bold; color: {{ $p['navy'] }}; line-height: 1.15; }

        /* The facts strip. A table because dompdf has no flexbox. */
        .facts { width: 100%; margin-top: 6mm; border-collapse: separate; border-spacing: 3px; }
        .facts td { background: {{ $p['blue_soft'] }}; padding: 3mm 3mm; width: 33%;
                    vertical-align: top; }
        .flabel { font-size: 7.5px; letter-spacing: 1.2px; text-transform: uppercase;
                  color: {{ $p['muted'] }}; font-weight: bold; }
        .fvalue { font-size: 11px; color: {{ $p['navy'] }}; font-weight: bold; padding-top: 1mm; }

        .panel { margin-top: 7mm; padding: 5mm 5mm 4mm 5mm; }
        .panel-primary { background: {{ $p['blue_soft'] }}; border-left: 3px solid {{ $p['blue'] }}; }
        .panel-secondary { background: {{ $p['green_soft'] }}; border-left: 3px solid {{ $p['green'] }}; }
        .phead { font-size: 13px; font-weight: bold; color: {{ $p['navy'] }}; margin-bottom: 3mm; }

        .items { width: 100%; }
        .items td { padding: 0 0 2.6mm 0; vertical-align: top; font-size: 10.5px; line-height: 1.45; }
        .tick { width: 6mm; color: {{ $p['green'] }}; font-weight: bold; font-size: 11px; }
        .tick-blue { color: {{ $p['blue'] }}; }

        .chips td { padding: 0 2mm 2mm 0; }
        .chip { background: #ffffff; border: 1px solid {{ $p['line'] }}; padding: 1.6mm 3mm;
                font-size: 9.5px; color: {{ $p['navy'] }}; }

        .cta { margin-top: 8mm; background: {{ $p['green'] }}; padding: 5mm; }
        .ctaword { font-size: 15px; font-weight: bold; color: #ffffff; }
        .ctaurl { font-size: 9.5px; color: #eafbee; padding-top: 1.5mm; }

        .foot { margin-top: 7mm; border-top: 1px solid {{ $p['line'] }}; padding-top: 3mm;
                font-size: 8.5px; color: {{ $p['muted'] }}; }

        .rolecard { border: 1px solid {{ $p['line'] }}; border-left: 3px solid {{ $p['blue'] }};
                    padding: 4mm 5mm; margin-bottom: 4mm; }
    </style>
</head>
<body>

{{-- Masthead: the organisation, then the one thing the poster is for. --}}
<div class="band">
    <table class="brandrow">
        <tr>
            <td style="width: 46px;">
                @if (!empty($poster['brand']['logo']))
                    {{-- Sized from the file header: an <img> dompdf cannot size lays out wrong. --}}
                    <img src="{{ $poster['brand']['logo'] }}" style="height: 34px;" alt="">
                @else
                    {{-- No logo is a normal state, not a failure. The slot stays filled so the
                         masthead is the same height either way. --}}
                    <div class="mark">{{ $poster['brand']['initials'] }}</div>
                @endif
            </td>
            <td>
                <div class="orgname">{{ $poster['brand']['name'] }}</div>
                @if (!empty($poster['brand']['website']))
                    <div class="orgweb">{{ preg_replace('~^https?://~', '', $poster['brand']['website']) }}</div>
                @endif
            </td>
        </tr>
    </table>

    <div class="headline">{{ $copy['headline'] }}</div>
    <div class="kicker">{{ $multi ? count($poster['roles']) . ' open roles' : 'Join our team' }}</div>
</div>

<div class="sheet">

@if ($multi)
    {{-- Several roles: titles and facts only. The multi-role poster never
         carries parsed description content, so every parser failure mode is
         simply absent from this path. --}}
    @foreach ($poster['roles'] as $r)
        <div class="rolecard">
            @if (!empty($r['department']))
                <div class="eyebrow">{{ $r['department'] }}</div>
            @endif
            <div class="title" style="font-size: {{ $r['title_size'] }}px;">{{ $r['title'] }}</div>
            @if (count($r['facts']))
                <table class="facts">
                    <tr>
                        @foreach (array_slice($r['facts'], 0, 3) as $fact)
                            <td>
                                <div class="flabel">{{ $fact['label'] }}</div>
                                <div class="fvalue">{{ $fact['value'] }}</div>
                            </td>
                        @endforeach
                    </tr>
                </table>
            @endif
        </div>
    @endforeach
@elseif ($role)
    <div class="rolebox">
        @if (!empty($role['department']))
            <div class="eyebrow">{{ $role['department'] }}</div>
        @endif
        <div class="title" style="font-size: {{ $role['title_size'] }}px;">{{ $role['title'] }}</div>
    </div>

    @if (count($role['facts']))
        <table class="facts">
            @foreach (array_chunk($role['facts'], 3) as $row)
                <tr>
                    @foreach ($row as $fact)
                        <td>
                            <div class="flabel">{{ $fact['label'] }}</div>
                            <div class="fvalue">{{ $fact['value'] }}</div>
                        </td>
                    @endforeach
                    {{-- Keep the last row's columns even width. --}}
                    @for ($i = count($row); $i < 3; $i++)
                        <td style="background: transparent;"></td>
                    @endfor
                </tr>
            @endforeach
        </table>
    @endif

    {{-- Panels are only ever drawn when they have content: an empty card frame
         with a heading over nothing is the one thing a poster must not show. --}}
    @foreach ($role['panels'] as $panel)
        <div class="panel panel-{{ $panel['style'] }}">
            <div class="phead">{{ $panel['heading'] }}</div>

            @if ($panel['kind'] === 'chips')
                <table class="chips">
                    @foreach (array_chunk($panel['items'], 3) as $row)
                        <tr>
                            @foreach ($row as $item)
                                <td><span class="chip">{{ $item }}</span></td>
                            @endforeach
                        </tr>
                    @endforeach
                </table>
            @else
                <table class="items">
                    @foreach ($panel['items'] as $item)
                        <tr>
                            <td class="tick {{ $panel['style'] === 'primary' ? 'tick-blue' : '' }}">&#10003;</td>
                            <td>{{ $item }}</td>
                        </tr>
                    @endforeach
                </table>
            @endif
        </div>
    @endforeach
@endif

    <div class="cta">
        <div class="ctaword">{{ $copy['cta'] }}</div>
        <div class="ctaurl">{{ $poster['apply_url'] }}</div>
    </div>

    <div class="foot">
        {{ $poster['brand']['name'] }}
        @if (!empty($poster['brand']['website']))
            &nbsp;·&nbsp; {{ preg_replace('~^https?://~', '', $poster['brand']['website']) }}
        @endif
    </div>

</div>
</body>
</html>
