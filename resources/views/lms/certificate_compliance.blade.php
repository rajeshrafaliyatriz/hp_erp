{{-- Compliance certificate.

    Rendered by Dompdf with exact A4 portrait geometry (210mm x 297mm).
    Built with deep corporate emerald (#065f46), slate (#0f172a), and gold accenting,
    dual security framing, credential audit tables, and tamper-resistant verification.

    Note: The blade tag closes flush before the doctype to eliminate leading
    whitespace from prefixing the %PDF binary stream. --}}<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>{{ $certificate->name ?? $certificate->course_title }}</title>
    <style>
        @page {
            size: 210mm 297mm portrait;
            margin: 0;
        }
        * {
            box-sizing: border-box;
            -webkit-print-color-adjust: exact;
        }
        html, body {
            width: 210mm;
            height: 297mm;
            margin: 0;
            padding: 0;
            background: #ffffff;
            font-family: 'DejaVu Serif', 'Times-Roman', Georgia, serif;
            color: #0f172a;
        }
        .sheet {
            position: relative;
            width: 210mm;
            height: 297mm;
            padding: 12mm 14mm;
            overflow: hidden;
            background: #ffffff;
        }

        /* Concentric Audit & Compliance Security Borders */
        .outer-border {
            position: absolute;
            top: 6mm;
            left: 6mm;
            right: 6mm;
            bottom: 6mm;
            border: 2.5px solid #064e3b;
            pointer-events: none;
        }
        .inner-border {
            position: absolute;
            top: 8.5mm;
            left: 8.5mm;
            right: 8.5mm;
            bottom: 8.5mm;
            border: 1px solid #d4af37;
            pointer-events: none;
        }

        .corner {
            position: absolute;
            width: 12mm;
            height: 12mm;
            z-index: 10;
        }
        .corner-tl { top: 8.5mm; left: 8.5mm; border-top: 3px solid #064e3b; border-left: 3px solid #064e3b; }
        .corner-tr { top: 8.5mm; right: 8.5mm; border-top: 3px solid #064e3b; border-right: 3px solid #064e3b; }
        .corner-bl { bottom: 8.5mm; left: 8.5mm; border-bottom: 3px solid #064e3b; border-left: 3px solid #064e3b; }
        .corner-br { bottom: 8.5mm; right: 8.5mm; border-bottom: 3px solid #064e3b; border-right: 3px solid #064e3b; }

        .content-wrap {
            position: relative;
            z-index: 20;
            padding: 2mm 6mm;
        }

        /* Top Issuer Header */
        .issuer-header {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 4mm;
        }
        .issuer-logo {
            max-height: 44px;
            max-width: 180px;
        }
        .issuer-title {
            font-family: 'DejaVu Sans', sans-serif;
            font-size: 8.5pt;
            letter-spacing: 2px;
            text-transform: uppercase;
            color: #047857;
            font-weight: bold;
            margin-top: 1mm;
        }

        .rule-bar {
            height: 3px;
            background: #047857;
            margin-bottom: 4mm;
        }

        /* Document Category & Eyebrow */
        .doc-category {
            font-family: 'DejaVu Sans', sans-serif;
            font-size: 8.5pt;
            letter-spacing: 3px;
            text-transform: uppercase;
            color: #b45309;
            font-weight: bold;
            text-align: center;
        }
        .doc-title {
            font-size: 20pt;
            font-weight: bold;
            color: #064e3b;
            text-align: center;
            margin: 1.5mm 0;
            letter-spacing: 0.5px;
        }
        .doc-subtitle {
            font-family: 'DejaVu Sans', sans-serif;
            font-size: 9pt;
            color: #64748b;
            text-align: center;
            margin-bottom: 5mm;
        }

        /* Awardee Featured Panel */
        .awardee-panel {
            background: #f8fafc;
            border: 1px solid #cbd5e1;
            border-left: 5px solid #047857;
            padding: 4mm 6mm;
            margin-bottom: 5mm;
        }
        .awardee-label {
            font-family: 'DejaVu Sans', sans-serif;
            font-size: 7pt;
            text-transform: uppercase;
            letter-spacing: 1.5px;
            color: #64748b;
            font-weight: 600;
        }
        .awardee-name {
            font-size: 19pt;
            font-weight: bold;
            color: #0f172a;
            margin: 1mm 0 2mm;
        }

        /* Audit Grid */
        table.grid {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 5mm;
        }
        table.grid td {
            border: 1px solid #e2e8f0;
            padding: 3mm 4mm;
            vertical-align: middle;
        }
        table.grid .col-key {
            width: 32%;
            font-family: 'DejaVu Sans', sans-serif;
            font-size: 7.5pt;
            text-transform: uppercase;
            letter-spacing: 1px;
            color: #475569;
            background: #f1f5f9;
            font-weight: 600;
        }
        table.grid .col-val {
            font-size: 10pt;
            font-weight: bold;
            color: #0f172a;
        }
        .expired-tag {
            color: #dc2626;
            font-weight: bold;
        }

        .compliance-statement {
            font-size: 9pt;
            line-height: 1.55;
            color: #334155;
            border-top: 1px solid #e2e8f0;
            padding-top: 3.5mm;
            margin-bottom: 4mm;
            font-style: italic;
        }

        .tag-pill {
            display: inline-block;
            background: #ecfdf5;
            border: 1px solid #a7f3d0;
            color: #065f46;
            font-family: 'DejaVu Sans', sans-serif;
            font-size: 7.5pt;
            font-weight: 600;
            padding: 0.8mm 2.5mm;
            margin-right: 1.5mm;
            border-radius: 2mm;
        }

        /* Signatures and Seal */
        .sign-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 4mm;
        }
        .sign-cell {
            width: 40%;
            text-align: center;
            vertical-align: bottom;
        }
        .sign-line {
            width: 48mm;
            border-bottom: 1px solid #94a3b8;
            margin: 0 auto 1.5mm;
        }
        .sign-title {
            font-family: 'DejaVu Sans', sans-serif;
            font-size: 7.5pt;
            text-transform: uppercase;
            letter-spacing: 1px;
            color: #475569;
            font-weight: bold;
        }

        .seal-cell {
            width: 20%;
            text-align: center;
            vertical-align: middle;
        }
        .audit-badge {
            width: 52px;
            height: 52px;
            margin: 0 auto;
            border: 2px solid #047857;
            border-radius: 50%;
            background: #f0fdf4;
            padding-top: 8px;
            text-align: center;
        }
        .badge-star {
            color: #047857;
            font-size: 11pt;
            line-height: 1;
        }
        .badge-text {
            font-family: 'DejaVu Sans', sans-serif;
            font-size: 5pt;
            font-weight: bold;
            letter-spacing: 1px;
            text-transform: uppercase;
            color: #064e3b;
        }

        /* Footer Audit Strip */
        .foot-strip {
            position: absolute;
            bottom: 10mm;
            left: 14mm;
            right: 14mm;
            border-top: 1px solid #cbd5e1;
            padding-top: 2.5mm;
            font-family: 'DejaVu Sans', sans-serif;
            font-size: 7pt;
            color: #64748b;
        }
    </style>
</head>
<body>
<div class="sheet">
    <div class="outer-border"></div>
    <div class="inner-border"></div>

    <div class="corner corner-tl"></div>
    <div class="corner corner-tr"></div>
    <div class="corner corner-bl"></div>
    <div class="corner corner-br"></div>

    <div class="content-wrap">
        {{-- Issuer Header --}}
        <table class="issuer-header">
            <tr>
                <td align="center">
                    @if (!empty($organisation['logo']))
                        <img class="issuer-logo" src="{{ $organisation['logo'] }}" alt="Logo">
                    @endif
                    @if (!empty($organisation['name']))
                        <div class="issuer-title">{{ $organisation['name'] }}</div>
                    @endif
                </td>
            </tr>
        </table>

        <div class="rule-bar"></div>

        <div class="doc-category">Official Verification Record</div>
        <h1 class="doc-title">{{ $certificate->name ?? 'Certificate of Compliance' }}</h1>
        <div class="doc-subtitle">Mandatory Regulatory Curriculum & Certification Validation</div>

        {{-- Awardee Details Panel --}}
        <div class="awardee-panel">
            <div class="awardee-label">Certified Recipient</div>
            <div class="awardee-name">{{ $learnerName ?: 'Learner' }}</div>
            <div class="awardee-label">Course Title</div>
            <div style="font-size: 12pt; font-weight: bold; color: #064e3b;">{{ $certificate->course_title }}</div>
        </div>

        {{-- Audit & Validation Grid --}}
        <table class="grid">
            <tr>
                <td class="col-key">Credential ID</td>
                <td class="col-val" style="font-family: 'DejaVu Sans Mono', monospace; color: #064e3b;">
                    {{ $certificate->certificate_number }}
                </td>
            </tr>
            <tr>
                <td class="col-key">Issue Date</td>
                <td class="col-val">
                    {{ $certificate->issued_at ? \Carbon\Carbon::parse($certificate->issued_at)->format('d F Y') : '—' }}
                </td>
            </tr>
            <tr>
                <td class="col-key">Expiration Date</td>
                <td class="col-val {{ $isExpired ? 'expired-tag' : '' }}">
                    {{ $certificate->expires_at
                        ? \Carbon\Carbon::parse($certificate->expires_at)->format('d F Y') . ($isExpired ? ' — EXPIRED' : '')
                        : 'Permanent / Non-Expiring' }}
                </td>
            </tr>
            @if ($certificate->verification_code)
                <tr>
                    <td class="col-key">Security Hash</td>
                    <td class="col-val" style="font-family: 'DejaVu Sans Mono', monospace; font-size: 8pt;">
                        {{ $certificate->verification_code }}
                    </td>
                </tr>
            @endif
        </table>

        @if ($certificate->description)
            <p class="compliance-statement">{{ $certificate->description }}</p>
        @endif

        @if (!empty($tags))
            <div style="margin-bottom: 3.5mm;">
                @foreach ($tags as $tag)
                    <span class="tag-pill">{{ $tag }}</span>
                @endforeach
            </div>
        @endif

        <p class="compliance-statement">
            This certifies that the recipient above has satisfied all mandatory statutory syllabus criteria,
            evaluations, and competency verification standards set forth for this certification.
        </p>

        {{-- Regulatory Signatories & Audit Seal --}}
        <table class="sign-table">
            <tr>
                <td class="sign-cell">
                    <div style="font-family: 'DejaVu Serif', cursive, serif; font-size: 12pt; font-style: italic; color: #064e3b; margin-bottom: 1mm;">
                        {{ $organisation['name'] ?? 'Compliance Office' }}
                    </div>
                    <div class="sign-line"></div>
                    <div class="sign-title">Chief Compliance Officer</div>
                </td>
                <td class="seal-cell">
                    <div class="audit-badge">
                        <div class="badge-star">★</div>
                        <div class="badge-text">Audited<br>& Valid</div>
                    </div>
                </td>
                <td class="sign-cell">
                    <div style="font-family: 'DejaVu Serif', cursive, serif; font-size: 12pt; font-style: italic; color: #064e3b; margin-bottom: 1mm;">
                        Internal Audit Board
                    </div>
                    <div class="sign-line"></div>
                    <div class="sign-title">Lead Governance Auditor</div>
                </td>
            </tr>
        </table>
    </div>

    <div class="foot-strip">
        <table width="100%" style="border-collapse: collapse;">
            <tr>
                <td style="text-align: left;">
                    @if ($certificate->verification_code)
                        Verification Gateway: {{ $verifyUrl }}
                    @endif
                </td>
                <td style="text-align: right;">
                    Cryptographically Indexed & Bullion Certified
                </td>
            </tr>
        </table>
    </div>
</div>
</body>
</html>
