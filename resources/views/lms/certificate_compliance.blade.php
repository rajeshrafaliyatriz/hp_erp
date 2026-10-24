{{-- Compliance certificate.

    Rendered by Dompdf with exact A4 portrait geometry (210mm x 297mm).
    Uses strict page margins (@page { size: a4 portrait; margin: 8mm 7mm; }) and
    nested table borders to guarantee:
      1. Perfect horizontal centering with zero right-side clipping.
      2. Strictly 1 single page with zero overflow onto page 2.
      3. Crisp vector SVG star in the official certified seal.

    Note: The blade comment closes flush before the doctype to eliminate leading
    whitespace from prefixing the %PDF binary stream. --}}<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>{{ $certificate->name ?? $certificate->course_title }}</title>
<style>
    @page {
        size: a4 portrait;
        margin: 8mm 7mm;
    }
    * {
        box-sizing: border-box;
    }
    html, body {
        margin: 0 !important;
        padding: 0 !important;
        font-family: 'DejaVu Serif', 'Times-Roman', Georgia, serif;
        color: #0f172a;
        background: #ffffff;
    }
    .main-table {
        width: 100%;
        border-collapse: collapse;
        table-layout: fixed;
    }
    .outer-border {
        border: 2.5px solid #064e3b;
    }
    .inner-border {
        border: 1px solid #d4af37;
        background: #ffffff;
    }
    .content-cell {
        padding: 4mm 6mm;
        text-align: center;
    }
    .issuer-logo {
        max-height: 40px;
        max-width: 170px;
        margin-bottom: 1mm;
    }
    .issuer-title {
        font-family: 'DejaVu Sans', sans-serif;
        font-size: 8.5pt;
        letter-spacing: 2.5px;
        text-transform: uppercase;
        color: #047857;
        font-weight: bold;
    }
    .rule-bar {
        height: 2px;
        background: #047857;
        margin: 2mm 0 3mm;
    }
    .doc-category {
        font-family: 'DejaVu Sans', sans-serif;
        font-size: 8pt;
        letter-spacing: 3px;
        text-transform: uppercase;
        color: #b45309;
        font-weight: bold;
    }
    .doc-title {
        font-size: 19pt;
        font-weight: bold;
        color: #064e3b;
        margin: 1mm 0;
        letter-spacing: 0.5px;
    }
    .doc-subtitle {
        font-family: 'DejaVu Sans', sans-serif;
        font-size: 8.5pt;
        color: #64748b;
        margin-bottom: 4mm;
    }
    .awardee-panel {
        background: #f8fafc;
        border: 1px solid #cbd5e1;
        border-left: 4px solid #047857;
        padding: 3mm 5mm;
        margin-bottom: 4mm;
        text-align: left;
    }
    .awardee-label {
        font-family: 'DejaVu Sans', sans-serif;
        font-size: 6.5pt;
        text-transform: uppercase;
        letter-spacing: 1.2px;
        color: #64748b;
        font-weight: bold;
    }
    .awardee-name {
        font-size: 18pt;
        font-weight: bold;
        color: #0f172a;
        margin: 0.5mm 0 1.5mm;
    }
    table.grid {
        width: 100%;
        border-collapse: collapse;
        margin-bottom: 4mm;
    }
    table.grid td {
        border: 1px solid #e2e8f0;
        padding: 2.5mm 3.5mm;
        vertical-align: middle;
        text-align: left;
    }
    table.grid .col-key {
        width: 32%;
        font-family: 'DejaVu Sans', sans-serif;
        font-size: 7.5pt;
        text-transform: uppercase;
        letter-spacing: 0.8px;
        color: #475569;
        background: #f1f5f9;
        font-weight: 600;
    }
    table.grid .col-val {
        font-size: 9.5pt;
        font-weight: bold;
        color: #0f172a;
    }
    .expired-tag {
        color: #dc2626;
        font-weight: bold;
    }
    .compliance-statement {
        font-size: 8.5pt;
        line-height: 1.5;
        color: #334155;
        border-top: 1px solid #e2e8f0;
        padding-top: 3mm;
        margin-bottom: 3.5mm;
        font-style: italic;
        text-align: center;
    }
    .tag-pill {
        display: inline-block;
        background: #ecfdf5;
        border: 1px solid #a7f3d0;
        color: #065f46;
        font-family: 'DejaVu Sans', sans-serif;
        font-size: 7pt;
        font-weight: 600;
        padding: 0.6mm 2.2mm;
        margin-right: 1.5mm;
        border-radius: 2mm;
    }
    .sign-table {
        width: 100%;
        border-collapse: collapse;
        margin-top: 3.5mm;
    }
    .sign-cell {
        width: 40%;
        text-align: center;
        vertical-align: bottom;
    }
    .sign-line {
        width: 46mm;
        border-bottom: 1px solid #94a3b8;
        margin: 0 auto 1.5mm;
    }
    .sign-title {
        font-family: 'DejaVu Sans', sans-serif;
        font-size: 7pt;
        text-transform: uppercase;
        letter-spacing: 0.8px;
        color: #475569;
        font-weight: bold;
    }
    .audit-badge {
        width: 48px;
        height: 48px;
        margin: 0 auto;
        border: 2px solid #047857;
        border-radius: 50%;
        background: #f0fdf4;
        padding-top: 5px;
        text-align: center;
    }
    .foot-strip {
        border-top: 1px solid #cbd5e1;
        margin-top: 3.5mm;
        padding-top: 2mm;
        font-family: 'DejaVu Sans', sans-serif;
        font-size: 7pt;
        color: #64748b;
    }
</style>
</head>
<body>
<table class="main-table outer-border" cellpadding="0" cellspacing="0">
    <tr>
        <td style="padding: 2.5mm;">
            <table class="main-table inner-border" cellpadding="0" cellspacing="0">
                <tr>
                    <td class="content-cell" align="center" valign="middle">
                        
                        {{-- Issuer Header --}}
                        @if (!empty($organisation['logo']))
                            <img class="issuer-logo" src="{{ $organisation['logo'] }}" alt="Logo">
                        @endif
                        @if (!empty($organisation['name']))
                            <div class="issuer-title">{{ $organisation['name'] }}</div>
                        @endif

                        <div class="rule-bar"></div>

                        <div class="doc-category">Official Verification Record</div>
                        <h1 class="doc-title">{{ $certificate->name ?? 'Certificate of Compliance' }}</h1>
                        <div class="doc-subtitle">Mandatory Regulatory Curriculum & Certification Validation</div>

                        {{-- Awardee Details Panel --}}
                        <div class="awardee-panel">
                            <div class="awardee-label">Certified Recipient</div>
                            <div class="awardee-name">{{ $learnerName ?: 'Learner' }}</div>
                            <div class="awardee-label">Course Title</div>
                            <div style="font-size: 11pt; font-weight: bold; color: #064e3b;">{{ $certificate->course_title }}</div>
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
                            <div style="margin-bottom: 3mm;">
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
                                    <div style="font-family: 'DejaVu Serif', cursive, serif; font-size: 11pt; font-style: italic; color: #064e3b; margin-bottom: 1mm;">
                                        {{ $organisation['name'] ?? 'Compliance Office' }}
                                    </div>
                                    <div class="sign-line"></div>
                                    <div class="sign-title">Chief Compliance Officer</div>
                                </td>
                                <td width="20%" align="center" valign="middle">
                                    <div class="audit-badge">
                                        <img src="data:image/svg+xml;base64,PHN2ZyB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciIHdpZHRoPSIyNCIgaGVpZ2h0PSIyNCIgdmlld0JveD0iMCAwIDI0IDI0IiBmaWxsPSIjMDQ3ODU3Ij48cG9seWdvbiBwb2ludHM9IjEyLDIgMTUsOSAyMiw5IDE3LDE0IDE5LDIxIDEyLDE3IDUsMjEgNywxNCAyLDkgOSw5Ii8+PC9zdmc+" width="14" height="14" style="display:block; margin: 0 auto;">
                                        <div style="font-family: 'DejaVu Sans', sans-serif; font-size: 4.5pt; font-weight: bold; letter-spacing: 0.8px; text-transform: uppercase; color: #064e3b; line-height: 1.1; margin-top: 1px;">
                                            Audited<br>& Valid
                                        </div>
                                    </div>
                                </td>
                                <td class="sign-cell">
                                    <div style="font-family: 'DejaVu Serif', cursive, serif; font-size: 11pt; font-style: italic; color: #064e3b; margin-bottom: 1mm;">
                                        Internal Audit Board
                                    </div>
                                    <div class="sign-line"></div>
                                    <div class="sign-title">Lead Governance Auditor</div>
                                </td>
                            </tr>
                        </table>

                        <div class="foot-strip">
                            <table width="100%" style="border-collapse: collapse;">
                                <tr>
                                    <td style="text-align: left;">
                                        @if ($certificate->verification_code)
                                            Verification Gateway: {{ $verifyUrl }}
                                        @endif
                                    </td>
                                    <td style="text-align: right;">
                                        Cryptographically Indexed & Certified
                                    </td>
                                </tr>
                            </table>
                        </div>

                    </td>
                </tr>
            </table>
        </td>
    </tr>
</table>
</body>
</html>
