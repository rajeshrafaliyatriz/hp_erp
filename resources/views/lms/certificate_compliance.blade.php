{{-- Compliance certificate.

    The same data as lms.certificate, presented for a regulatory audience:
    portrait rather than landscape, the credential ID and expiry given equal
    weight to the learner's name, and an explicit compliance statement. Auditors
    read these for the dates and the credential number, so those are the
    elements the layout leads with.

    dompdf-safe CSS only: no flexbox, no grid, no external fonts.

    Note the comment closes immediately before the doctype - a newline here
    lands ahead of the %PDF header, which strict readers reject. --}}<!DOCTYPE html>
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
            width: 210mm;
            height: 292mm;
            padding: 16mm 18mm;
            box-sizing: border-box;
        }
        .rule { border-top: 3px solid #0f766e; margin-bottom: 6mm; }
        .org {
            font-size: 9pt;
            letter-spacing: 3px;
            text-transform: uppercase;
            color: #0f766e;
            font-weight: bold;
        }
        .doc-type {
            font-size: 20pt;
            font-weight: bold;
            margin: 2mm 0 1mm;
        }
        .doc-sub { font-size: 9.5pt; color: #6b7280; margin-bottom: 10mm; }

        .panel {
            border: 1px solid #d1d5db;
            border-left: 4px solid #0f766e;
            padding: 6mm 7mm;
            margin-bottom: 8mm;
        }
        .panel .label {
            font-size: 7.5pt;
            text-transform: uppercase;
            letter-spacing: 1.5px;
            color: #6b7280;
        }
        .panel .value {
            font-size: 13pt;
            font-weight: bold;
            color: #111827;
            padding-bottom: 3mm;
        }
        .panel .value-lg { font-size: 17pt; }

        table.grid { width: 100%; border-collapse: collapse; margin-bottom: 8mm; }
        table.grid td {
            border: 1px solid #e5e7eb;
            padding: 3.5mm 4mm;
            vertical-align: top;
        }
        table.grid .k {
            width: 38%;
            font-size: 8pt;
            text-transform: uppercase;
            letter-spacing: 1px;
            color: #6b7280;
            background: #f9fafb;
        }
        table.grid .v { font-size: 10.5pt; font-weight: bold; color: #111827; }

        .statement {
            font-size: 9.5pt;
            line-height: 1.6;
            color: #374151;
            border-top: 1px solid #e5e7eb;
            padding-top: 5mm;
            margin-bottom: 6mm;
        }
        .tags { font-size: 8.5pt; color: #0f766e; margin-bottom: 6mm; }
        .expired { color: #b91c1c; }

        .foot {
            position: absolute;
            bottom: 16mm; left: 18mm; right: 18mm;
            border-top: 1px solid #e5e7eb;
            padding-top: 4mm;
            font-size: 7.5pt;
            color: #6b7280;
        }
    
        /* The issuing organisation, above the document title. */
        .issuer { text-align: center; margin-bottom: 12px; }
        .issuer-logo { max-height: 46px; max-width: 190px; }
        .issuer-name {
            margin-top: 5px;
            font-size: 11px;
            letter-spacing: 1.4px;
            text-transform: uppercase;
            color: #6b7280;
        }
    </style>
</head>
<body>
<div class="sheet">
    <div class="rule"></div>
    {{--
        THE ISSUER, AT THE TOP.

        A compliance record in particular has to say who issued it: it is
        evidence shown to an auditor, and evidence with no issuer is not
        evidence. Both fields are optional so an organisation that has set
        neither still gets a valid certificate.
    --}}
    @if (!empty($organisation['logo']) || !empty($organisation['name']))
        <div class="issuer">
            @if (!empty($organisation['logo']))
                {{-- A data URI: dompdf runs with enable_remote off, so a remote
                     <img> renders as nothing at all, silently. --}}
                <img class="issuer-logo" src="{{ $organisation['logo'] }}" alt="">
            @endif
            @if (!empty($organisation['name']))
                <div class="issuer-name">{{ $organisation['name'] }}</div>
            @endif
        </div>
    @endif
    <div class="org">Certificate of Compliance</div>
    <div class="doc-type">{{ $certificate->name ?? $certificate->course_title }}</div>
    <div class="doc-sub">Issued as a record of completed mandatory training.</div>

    <div class="panel">
        <div class="label">Awarded to</div>
        <div class="value value-lg">{{ $learnerName ?: 'Learner' }}</div>
        <div class="label">Credential ID</div>
        <div class="value">{{ $certificate->certificate_number }}</div>
    </div>

    <table class="grid">
        <tr>
            <td class="k">Course</td>
            <td class="v">{{ $certificate->course_title }}</td>
        </tr>
        <tr>
            <td class="k">Issued on</td>
            <td class="v">
                {{ $certificate->issued_at ? \Carbon\Carbon::parse($certificate->issued_at)->format('d M Y') : '—' }}
            </td>
        </tr>
        <tr>
            <td class="k">Valid until</td>
            <td class="v {{ $isExpired ? 'expired' : '' }}">
                {{ $certificate->expires_at
                    ? \Carbon\Carbon::parse($certificate->expires_at)->format('d M Y') . ($isExpired ? ' — EXPIRED' : '')
                    : 'No expiry' }}
            </td>
        </tr>
        @if ($certificate->verification_code)
            <tr>
                <td class="k">Verification code</td>
                <td class="v">{{ $certificate->verification_code }}</td>
            </tr>
        @endif
    </table>

    @if ($certificate->description)
        <p class="statement">{{ $certificate->description }}</p>
    @endif

    @if (!empty($tags))
        <p class="tags">{{ implode('  •  ', $tags) }}</p>
    @endif

    <p class="statement">
        This certificate confirms that the named individual has satisfied the
        completion requirements recorded against this course. Its validity can be
        confirmed independently using the credential ID above.
    </p>

    <div class="foot">
        @if ($certificate->verification_code)
            Verify at {{ $verifyUrl }}<br>
        @endif
        This document was generated automatically and is valid without a signature.
    </div>
</div>
</body>
</html>
