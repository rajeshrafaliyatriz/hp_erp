{{-- Printable executive certificate.

    Rendered by Dompdf, which understands CSS 2.1 (table layouts, absolute
    positioning, border effects, and built-in serif/sans typography).
    Configured for exact A4 landscape (297mm x 210mm) zero-margin page geometry
    with built-in overflow protection so it never spills into a blank page 2.

    Note: The blade tag closes flush before the doctype to eliminate leading
    whitespace from prefixing the %PDF binary stream. --}}<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>{{ $certificate->name ?? $certificate->course_title }}</title>
    <style>
        @page {
            size: 297mm 210mm landscape;
            margin: 0;
        }
        * {
            box-sizing: border-box;
            -webkit-print-color-adjust: exact;
        }
        html, body {
            width: 297mm;
            height: 210mm;
            margin: 0;
            padding: 0;
            background-color: #fdfdfd;
            font-family: 'DejaVu Serif', 'Times-Roman', Georgia, serif;
            color: #0f172a;
        }
        .page {
            position: relative;
            width: 297mm;
            height: 210mm;
            padding: 10mm 12mm;
            background: #ffffff;
            overflow: hidden;
        }

        /* Outer Guilloche-style double decorative border */
        .outer-border {
            position: absolute;
            top: 6mm;
            left: 6mm;
            right: 6mm;
            bottom: 6mm;
            border: 3px double #1e3a8a;
            pointer-events: none;
        }
        .accent-frame {
            position: absolute;
            top: 8.5mm;
            left: 8.5mm;
            right: 8.5mm;
            bottom: 8.5mm;
            border: 1px solid #d4af37;
            pointer-events: none;
        }
        .inner-frame {
            position: absolute;
            top: 11mm;
            left: 11mm;
            right: 11mm;
            bottom: 11mm;
            border: 2px solid #0f2b5c;
            background: #ffffff;
            pointer-events: none;
        }

        /* Ornamental Corner Cornerpieces */
        .corner {
            position: absolute;
            width: 14mm;
            height: 14mm;
            z-index: 10;
        }
        .corner-tl { top: 11mm; left: 11mm; border-top: 3px solid #d4af37; border-left: 3px solid #d4af37; }
        .corner-tr { top: 11mm; right: 11mm; border-top: 3px solid #d4af37; border-right: 3px solid #d4af37; }
        .corner-bl { bottom: 11mm; left: 11mm; border-bottom: 3px solid #d4af37; border-left: 3px solid #d4af37; }
        .corner-br { bottom: 11mm; right: 11mm; border-bottom: 3px solid #d4af37; border-right: 3px solid #d4af37; }

        /* Main Content Container */
        .body-container {
            position: relative;
            z-index: 20;
            padding: 5mm 14mm 2mm;
            text-align: center;
        }

        /* Header Branding */
        .header-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 2mm;
        }
        .issuer-name {
            font-family: 'DejaVu Sans', Helvetica, Arial, sans-serif;
            font-size: 8.5pt;
            letter-spacing: 3px;
            text-transform: uppercase;
            color: #b8860b;
            font-weight: bold;
            padding-top: 1mm;
        }
        .issuer-logo {
            max-height: 40px;
            max-width: 180px;
        }

        /* Certificate Eyebrow & Title */
        .eyebrow-container {
            margin: 2mm auto 1mm;
        }
        .eyebrow-text {
            font-family: 'DejaVu Sans', Helvetica, Arial, sans-serif;
            font-size: 9pt;
            letter-spacing: 5px;
            text-transform: uppercase;
            color: #1e3a8a;
            font-weight: 700;
        }
        .ornament-line {
            display: inline-block;
            width: 38mm;
            height: 1px;
            background: #d4af37;
            vertical-align: middle;
            margin: 0 4mm;
        }

        .main-heading {
            font-size: 26pt;
            font-weight: bold;
            color: #0f172a;
            letter-spacing: 1.5px;
            margin: 1mm 0 2mm;
            text-transform: capitalize;
        }

        .citation-intro {
            font-style: italic;
            font-size: 11pt;
            color: #64748b;
            margin: 0 0 2mm;
        }

        /* Learner Name Section */
        .learner-box {
            margin: 1mm auto 3mm;
            display: inline-block;
            padding: 0 16mm 1.5mm;
            border-bottom: 2px solid #d4af37;
        }
        .learner-name {
            font-size: 24pt;
            font-weight: bold;
            color: #0c1e3c;
            letter-spacing: 0.5px;
        }

        .citation-body {
            font-size: 11pt;
            color: #475569;
            margin: 2mm auto 2mm;
            max-width: 220mm;
            line-height: 1.4;
        }
        .course-title {
            font-size: 17pt;
            font-weight: bold;
            color: #1e3a8a;
            display: block;
            margin: 1.5mm 0;
            letter-spacing: 0.5px;
        }
        .course-desc {
            font-size: 9.5pt;
            color: #64748b;
            font-style: italic;
            max-width: 195mm;
            margin: 1mm auto;
            line-height: 1.35;
        }

        .tag-pill {
            display: inline-block;
            background: #f1f5f9;
            border: 1px solid #cbd5e1;
            color: #1e3a8a;
            font-family: 'DejaVu Sans', sans-serif;
            font-size: 7.5pt;
            font-weight: 600;
            letter-spacing: 0.5px;
            padding: 1mm 3mm;
            margin: 0 1.5mm;
            border-radius: 2mm;
        }

        /* Lower Signatures & Seal Section */
        .footer-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 4mm;
        }
        .signature-block {
            width: 32%;
            text-align: center;
            vertical-align: bottom;
            padding: 0 4mm;
        }
        .sig-line {
            width: 52mm;
            border-bottom: 1px solid #94a3b8;
            margin: 0 auto 1.5mm;
        }
        .sig-title {
            font-family: 'DejaVu Sans', sans-serif;
            font-size: 8pt;
            text-transform: uppercase;
            letter-spacing: 1.5px;
            color: #475569;
            font-weight: 600;
        }
        .sig-sub {
            font-family: 'DejaVu Sans', sans-serif;
            font-size: 7pt;
            color: #94a3b8;
        }

        /* Center Official Medallion Seal */
        .seal-block {
            width: 36%;
            text-align: center;
            vertical-align: middle;
        }
        .seal-rosette {
            width: 62px;
            height: 62px;
            margin: 0 auto;
            border: 3px solid #d4af37;
            border-radius: 50%;
            background: #fefce8;
            box-shadow: inset 0 0 0 2px #b8860b;
            text-align: center;
            padding-top: 10px;
        }
        .seal-star {
            color: #b8860b;
            font-size: 13pt;
            line-height: 1;
        }
        .seal-text {
            font-family: 'DejaVu Sans', sans-serif;
            font-size: 5.5pt;
            font-weight: bold;
            letter-spacing: 1.5px;
            text-transform: uppercase;
            color: #854d0e;
            margin-top: 2px;
        }

        /* Bottom Security Credentials Bar */
        .security-bar {
            width: 100%;
            border-collapse: collapse;
            margin-top: 3mm;
            padding-top: 2mm;
            border-top: 1px solid #e2e8f0;
            font-family: 'DejaVu Sans', sans-serif;
            font-size: 7.5pt;
            color: #64748b;
        }
        .sec-item {
            text-align: center;
            padding: 0.5mm 2mm;
        }
        .sec-label {
            text-transform: uppercase;
            letter-spacing: 1px;
            font-size: 6.5pt;
            color: #94a3b8;
            font-weight: 600;
        }
        .sec-value {
            font-weight: bold;
            color: #0f172a;
            font-size: 8pt;
        }
        .expired-badge {
            color: #dc2626;
            font-weight: bold;
        }
    </style>
</head>
<body>
<div class="page">
    {{-- Concentric Diplomatic Borders --}}
    <div class="outer-border"></div>
    <div class="accent-frame"></div>
    <div class="inner-frame"></div>

    {{-- Corner Flourishes --}}
    <div class="corner corner-tl"></div>
    <div class="corner corner-tr"></div>
    <div class="corner corner-bl"></div>
    <div class="corner corner-br"></div>

    <div class="body-container">
        {{-- Issuer Header --}}
        <table class="header-table">
            <tr>
                <td align="center">
                    @if (!empty($organisation['logo']))
                        <img class="issuer-logo" src="{{ $organisation['logo'] }}" alt="Logo">
                    @endif
                    @if (!empty($organisation['name']))
                        <div class="issuer-name">{{ $organisation['name'] }}</div>
                    @endif
                </td>
            </tr>
        </table>

        {{-- Eyebrow with gold accent wings --}}
        <div class="eyebrow-container">
            <span class="ornament-line"></span>
            <span class="eyebrow-text">Certificate of Completion</span>
            <span class="ornament-line"></span>
        </div>

        {{-- Main Award Heading --}}
        <h1 class="main-heading">{{ $certificate->name ?? 'Certificate of Excellence' }}</h1>

        <div class="citation-intro">This certificate is proudly awarded to</div>

        {{-- Recipient Display --}}
        <div class="learner-box">
            <span class="learner-name">{{ $learnerName ?: 'Learner' }}</span>
        </div>

        {{-- Course Citation --}}
        <div class="citation-body">
            in recognition of successfully completing all requirements and course assessments for
            <span class="course-title">{{ $certificate->course_title }}</span>
            @if ($certificate->description)
                <div class="course-desc">{{ $certificate->description }}</div>
            @endif
        </div>

        {{-- Competency & Skill Tags --}}
        @if (!empty($tags))
            <div style="margin: 1.5mm 0 1mm;">
                @foreach ($tags as $tag)
                    <span class="tag-pill">{{ $tag }}</span>
                @endforeach
            </div>
        @endif

        {{-- Authority Signatures & Official Gold Medallion --}}
        <table class="footer-table">
            <tr>
                <td class="signature-block">
                    <div style="font-family: 'DejaVu Serif', cursive, serif; font-size: 13pt; font-style: italic; color: #1e3a8a; margin-bottom: 1mm;">
                        {{ $organisation['name'] ?? 'Academic Board' }}
                    </div>
                    <div class="sig-line"></div>
                    <div class="sig-title">Authorized Signatory</div>
                    <div class="sig-sub">Director of Learning & Development</div>
                </td>
                <td class="seal-block">
                    <div class="seal-rosette">
                        <div class="seal-star">★</div>
                        <div class="seal-text">Official<br>Certified</div>
                    </div>
                </td>
                <td class="signature-block">
                    <div style="font-family: 'DejaVu Serif', cursive, serif; font-size: 13pt; font-style: italic; color: #1e3a8a; margin-bottom: 1mm;">
                        Course Verification
                    </div>
                    <div class="sig-line"></div>
                    <div class="sig-title">Program Coordinator</div>
                    <div class="sig-sub">Quality & Evaluation Council</div>
                </td>
            </tr>
        </table>

        {{-- Security, Credential ID & Verification Bar --}}
        <table class="security-bar">
            <tr>
                <td class="sec-item" style="text-align: left;">
                    <div class="sec-label">Credential ID</div>
                    <div class="sec-value">{{ $certificate->certificate_number }}</div>
                </td>
                <td class="sec-item">
                    <div class="sec-label">Date Issued</div>
                    <div class="sec-value">
                        {{ $certificate->issued_at ? \Carbon\Carbon::parse($certificate->issued_at)->format('F d, Y') : '—' }}
                    </div>
                </td>
                <td class="sec-item">
                    <div class="sec-label">Validity</div>
                    <div class="sec-value {{ $isExpired ? 'expired-badge' : '' }}">
                        {{ $certificate->expires_at
                            ? \Carbon\Carbon::parse($certificate->expires_at)->format('F d, Y') . ($isExpired ? ' (Expired)' : '')
                            : 'Lifetime Credential' }}
                    </div>
                </td>
                @if ($certificate->verification_code)
                    <td class="sec-item" style="text-align: right;">
                        <div class="sec-label">Verification Code</div>
                        <div class="sec-value" style="font-family: 'DejaVu Sans Mono', monospace; font-size: 7.5pt; color: #1e3a8a;">
                            {{ $certificate->verification_code }}
                        </div>
                    </td>
                @endif
            </tr>
        </table>
    </div>
</div>
</body>
</html>
