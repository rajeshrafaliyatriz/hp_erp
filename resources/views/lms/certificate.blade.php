{{-- Printable certificate.

    Rendered by dompdf, which only understands a conservative subset of CSS —
    no flexbox, no grid, no external fonts. Layout is therefore absolute
    positioning inside a fixed A4 landscape page, which dompdf handles reliably.

    Note the comment closes immediately before the doctype: a newline here ends
    up ahead of the %PDF header in the response body, which strict PDF readers
    reject. --}}<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>{{ $certificate->name ?? $certificate->course_title }}</title>
    <style>
        @page { margin: 0; }
        body {
            margin: 0;
            font-family: DejaVu Sans, sans-serif;
            color: #1f2937;
        }
        .sheet {
            position: relative;
            width: 297mm;
            height: 200mm;
            padding: 18mm 20mm;
            box-sizing: border-box;
        }
        .frame {
            position: absolute;
            top: 8mm; left: 8mm; right: 8mm; bottom: 8mm;
            border: 2px solid #2563eb;
        }
        .frame-inner {
            position: absolute;
            top: 11mm; left: 11mm; right: 11mm; bottom: 11mm;
            border: 1px solid #93c5fd;
        }
        .content { position: relative; text-align: center; padding-top: 10mm; }
        .eyebrow {
            font-size: 11pt;
            letter-spacing: 4px;
            text-transform: uppercase;
            color: #2563eb;
            margin-bottom: 4mm;
        }
        .title { font-size: 30pt; font-weight: bold; margin: 0 0 6mm; }
        .awarded { font-size: 11pt; color: #6b7280; margin-bottom: 3mm; }
        .learner {
            font-size: 22pt;
            font-weight: bold;
            border-bottom: 1px solid #d1d5db;
            display: inline-block;
            padding: 0 14mm 2mm;
            margin-bottom: 6mm;
        }
        .course { font-size: 14pt; margin-bottom: 4mm; }
        .course strong { color: #111827; }
        .description {
            font-size: 10pt;
            color: #4b5563;
            max-width: 190mm;
            margin: 0 auto 6mm;
            line-height: 1.5;
        }
        .tags { font-size: 9pt; color: #2563eb; margin-bottom: 8mm; }
        .meta {
            position: absolute;
            bottom: 20mm; left: 20mm; right: 20mm;
            font-size: 9pt;
            color: #6b7280;
        }
        .meta td { padding: 1mm 0; }
        .meta .label { text-transform: uppercase; letter-spacing: 1px; font-size: 7.5pt; }
        .meta .value { font-weight: bold; color: #111827; font-size: 10pt; }
        .expired { color: #b91c1c; }
    </style>
</head>
<body>
<div class="sheet">
    <div class="frame"></div>
    <div class="frame-inner"></div>

    <div class="content">
        <div class="eyebrow">Certificate of Completion</div>
        <h1 class="title">{{ $certificate->name ?? $certificate->course_title }}</h1>

        <p class="awarded">This is to certify that</p>
        <div class="learner">{{ $learnerName ?: 'Learner' }}</div>

        <p class="course">
            has successfully completed <strong>{{ $certificate->course_title }}</strong>
        </p>

        @if ($certificate->description)
            <p class="description">{{ $certificate->description }}</p>
        @endif

        @if (!empty($tags))
            <p class="tags">{{ implode('  •  ', $tags) }}</p>
        @endif
    </div>

    <table class="meta" width="100%">
        <tr>
            <td class="label">Credential ID</td>
            <td class="label">Issued On</td>
            <td class="label">Valid Until</td>
            @if ($certificate->verification_code)
                <td class="label">Verify At</td>
            @endif
        </tr>
        <tr>
            <td class="value">{{ $certificate->certificate_number }}</td>
            <td class="value">
                {{ $certificate->issued_at ? \Carbon\Carbon::parse($certificate->issued_at)->format('d M Y') : '—' }}
            </td>
            <td class="value {{ $isExpired ? 'expired' : '' }}">
                {{ $certificate->expires_at
                    ? \Carbon\Carbon::parse($certificate->expires_at)->format('d M Y') . ($isExpired ? ' (expired)' : '')
                    : 'No expiry' }}
            </td>
            @if ($certificate->verification_code)
                <td class="value">{{ $verifyUrl }}</td>
            @endif
        </tr>
    </table>
</div>
</body>
</html>
