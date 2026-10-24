{{-- Printable executive certificate.

    Rendered by Dompdf with exact A4 landscape geometry (297mm x 210mm).
    Uses strict page margins (@page { size: 297mm 210mm landscape; margin: 8mm 10mm; })
    and a fixed concentric frame with proportional vertical spacing to guarantee:
      1. Mathematically identical margins on all 4 sides (8mm top/bottom, 10mm left/right).
      2. Zero right-side border clipping and zero page overflow.
      3. Strictly 1 single page (no empty second page).
      4. Crisp vector SVG star in the official certified medallion seal.
      5. High-resolution inlined organization profile logo.

    Note: The blade comment closes flush before the doctype to eliminate leading
    whitespace from prefixing the %PDF binary stream. --}}<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>{{ $certificate->name ?? $certificate->course_title }}</title>
<style>
    @page {
        size: 297mm 210mm landscape;
        margin: 8mm 10mm;
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

    /* Fixed Full-Page Concentric Frame (Zero-Spill Guarantee) */
    .frame-outer {
        position: fixed;
        top: 0;
        left: 0;
        right: 0;
        bottom: 0;
        border: 4px double #1e3a8a;
    }
    .frame-gold {
        position: fixed;
        top: 2.2mm;
        left: 2.2mm;
        right: 2.2mm;
        bottom: 2.2mm;
        border: 1.5px solid #c59b27;
    }
    .frame-inner {
        position: fixed;
        top: 4.4mm;
        left: 4.4mm;
        right: 4.4mm;
        bottom: 4.4mm;
        border: 1.5px solid #0c1e3c;
    }

    /* Fixed Security Metadata Footer at Bottom of the Frame */
    .footer-bar {
        position: fixed;
        bottom: 6mm;
        left: 7mm;
        right: 7mm;
        border-top: 1px solid #e2e8f0;
        padding-top: 2.5mm;
    }
    .meta-label {
        font-family: 'DejaVu Sans', sans-serif;
        text-transform: uppercase;
        letter-spacing: 0.8px;
        font-size: 6.5pt;
        color: #94a3b8;
        font-weight: bold;
    }
    .meta-value {
        font-family: 'DejaVu Sans', sans-serif;
        font-weight: bold;
        color: #0f172a;
        font-size: 8pt;
    }
    .expired-badge {
        color: #dc2626;
        font-weight: bold;
    }

    /* Main Certificate Content */
    .cert-content {
        padding: 6.5mm 14mm 0;
        text-align: center;
    }
    .issuer-logo {
        max-height: 38px;
        max-width: 160px;
        display: block;
        margin: 0 auto 1.5mm;
    }
    .issuer-title {
        font-family: 'DejaVu Sans', sans-serif;
        font-size: 10.5pt;
        letter-spacing: 3.5px;
        text-transform: uppercase;
        color: #b8860b;
        font-weight: bold;
    }
    .eyebrow {
        font-family: 'DejaVu Sans', sans-serif;
        font-size: 9pt;
        letter-spacing: 4px;
        text-transform: uppercase;
        color: #1e3a8a;
        font-weight: bold;
        margin: 3.5mm 0 1.5mm;
    }
    .rule-gold {
        display: inline-block;
        width: 38mm;
        height: 1px;
        background: #c59b27;
        vertical-align: middle;
        margin: 0 3mm;
    }
    .cert-title {
        font-size: 25pt;
        font-weight: bold;
        color: #0f172a;
        margin: 1.5mm 0 2mm;
        letter-spacing: 0.5px;
    }
    .awarded-to {
        font-style: italic;
        font-size: 11.5pt;
        color: #64748b;
        margin: 2mm 0;
    }
    .learner-name {
        font-size: 30pt;
        font-weight: bold;
        color: #0c1e3c;
        display: inline-block;
        border-bottom: 2.5px solid #c59b27;
        padding: 0 20mm 2mm;
        margin-bottom: 3.5mm;
    }
    .citation {
        font-size: 11pt;
        color: #475569;
        line-height: 1.45;
        max-width: 225mm;
        margin: 0 auto;
    }
    .course-name {
        font-size: 17pt;
        font-weight: bold;
        color: #1e3a8a;
        margin: 2mm 0 1mm;
    }
    .course-desc {
        font-size: 9pt;
        color: #64748b;
        font-style: italic;
        margin-bottom: 3mm;
    }
    .tag-chip {
        display: inline-block;
        background: #f1f5f9;
        border: 1px solid #cbd5e1;
        color: #1e3a8a;
        font-family: 'DejaVu Sans', sans-serif;
        font-size: 7.5pt;
        font-weight: 600;
        padding: 0.8mm 3.2mm;
        margin: 0 1.5mm;
        border-radius: 2mm;
    }

    /* Endorsement Signatures & Seal Table */
    .endorsement-table {
        width: 100%;
        margin-top: 11mm;
    }
    .sig-line {
        width: 54mm;
        border-bottom: 1.2px solid #94a3b8;
        margin: 0 auto 2mm;
    }
    .sig-name {
        font-family: 'DejaVu Serif', cursive, serif;
        font-size: 12.5pt;
        font-style: italic;
        color: #1e3a8a;
        margin-bottom: 1mm;
    }
    .sig-role {
        font-family: 'DejaVu Sans', sans-serif;
        font-size: 7.5pt;
        text-transform: uppercase;
        letter-spacing: 1px;
        color: #475569;
        font-weight: bold;
    }
    .sig-org {
        font-family: 'DejaVu Sans', sans-serif;
        font-size: 6.5pt;
        color: #94a3b8;
    }
    .seal-badge {
        width: 62px;
        height: 62px;
        margin: 0 auto;
        border: 2.5px solid #c59b27;
        border-radius: 50%;
        background: #fefce8;
        text-align: center;
        padding-top: 5px;
    }
    .seal-text {
        font-family: 'DejaVu Sans', sans-serif;
        font-size: 5.2pt;
        font-weight: bold;
        letter-spacing: 0.8px;
        color: #854d0e;
        text-transform: uppercase;
        line-height: 1.1;
        margin-top: 2px;
    }
</style>
</head>
<body>

<div class="frame-outer"></div>
<div class="frame-gold"></div>
<div class="frame-inner"></div>

<div class="cert-content">
    @if (!empty($organisation['logo']))
        <img class="issuer-logo" src="{{ $organisation['logo'] }}" alt="Logo">
    @endif
    @if (!empty($organisation['name']))
        <div class="issuer-title">{{ $organisation['name'] }}</div>
    @endif

    <div class="eyebrow">
        <span class="rule-gold"></span>
        Certificate of Completion
        <span class="rule-gold"></span>
    </div>

    <div class="cert-title">{{ $certificate->name ?? 'Certificate of Excellence' }}</div>

    <div class="awarded-to">This certificate is proudly awarded to</div>

    <div>
        <span class="learner-name">{{ $learnerName ?: 'Learner' }}</span>
    </div>

    <div class="citation">
        in recognition of successfully completing all requirements and course assessments for
        <div class="course-name">{{ $certificate->course_title }}</div>
        @if ($certificate->description)
            <div class="course-desc">{{ $certificate->description }}</div>
        @endif
    </div>

    @if (!empty($tags))
        <div>
            @foreach ($tags as $tag)
                <span class="tag-chip">{{ $tag }}</span>
            @endforeach
        </div>
    @endif

    <table class="endorsement-table" cellpadding="0" cellspacing="0">
        <tr>
            <td width="35%" align="center" valign="bottom">
                <div class="sig-name">{{ $organisation['name'] ?? 'Authorized Signatory' }}</div>
                <div class="sig-line"></div>
                <div class="sig-role">Authorized Signatory</div>
                <div class="sig-org">Director of Learning & Development</div>
            </td>
            <td width="30%" align="center" valign="middle">
                <div class="seal-badge">
                    <img src="data:image/svg+xml;base64,PHN2ZyB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciIHdpZHRoPSIyNCIgaGVpZ2h0PSIyNCIgdmlld0JveD0iMCAwIDI0IDI0IiBmaWxsPSIjYjg4NjBiIj48cG9seWdvbiBwb2ludHM9IjEyLDIgMTUsOSAyMiw5IDE3LDE0IDE5LDIxIDEyLDE3IDUsMjEgNywxNCAyLDkgOSw5Ii8+PC9zdmc+" width="18" height="18" style="display:block; margin: 0 auto;">
                    <div class="seal-text">OFFICIAL<br>CERTIFIED</div>
                </div>
            </td>
            <td width="35%" align="center" valign="bottom">
                <div class="sig-name">Course Verification</div>
                <div class="sig-line"></div>
                <div class="sig-role">Program Coordinator</div>
                <div class="sig-org">Quality & Evaluation Council</div>
            </td>
        </tr>
    </table>
</div>

<div class="footer-bar">
    <table width="100%" cellpadding="0" cellspacing="0">
        <tr>
            <td width="28%" align="left">
                <div class="meta-label">Credential ID</div>
                <div class="meta-value">{{ $certificate->certificate_number }}</div>
            </td>
            <td width="24%" align="center">
                <div class="meta-label">Date Issued</div>
                <div class="meta-value">
                    {{ $certificate->issued_at ? \Carbon\Carbon::parse($certificate->issued_at)->format('F d, Y') : '—' }}
                </div>
            </td>
            <td width="24%" align="center">
                <div class="meta-label">Validity</div>
                <div class="meta-value {{ $isExpired ? 'expired-badge' : '' }}">
                    {{ $certificate->expires_at
                        ? \Carbon\Carbon::parse($certificate->expires_at)->format('F d, Y') . ($isExpired ? ' (Expired)' : '')
                        : 'Lifetime Credential' }}
                </div>
            </td>
            @if ($certificate->verification_code)
                <td width="24%" align="right">
                    <div class="meta-label">Verification Code</div>
                    <div class="meta-value" style="font-family: 'DejaVu Sans Mono', monospace; font-size: 7.5pt; color: #1e3a8a;">
                        {{ $certificate->verification_code }}
                    </div>
                </td>
            @endif
        </tr>
    </table>
</div>

</body>
</html>
