<!DOCTYPE html>
<html lang="en">
<head>
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>
    <title>Job Offer Letter</title>
    <style>
        /* 1. RESET */
        @page { margin: 0; }
        body {
            margin: 0;
            padding: 0;
            font-family: 'Helvetica Neue', Helvetica, Arial, sans-serif;
            font-size: 13px;
            color: #555;
            line-height: 1.7;
            background-color: #ffffff;
        }

        /* 2. THE SIDEBAR (Fixed Left Column) */
        .sidebar {
            position: fixed;
            top: 0;
            left: 0;
            width: 28%; /* Width of the dark strip */
            height: 100%;
            background-color: #1e293b; /* Dark Slate Blue */
            color: #ffffff;
            z-index: 0;
        }

        /* Sidebar Content */
        .sidebar-content {
            padding: 50px 30px;
            text-align: left;
        }

        .logo-box {
            font-size: 24px;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 2px;
            margin-bottom: 60px;
            color: #10b981; /* Emerald Green Logo */
            border-bottom: 2px solid #334155;
            padding-bottom: 20px;
        }

        .contact-group {
            margin-bottom: 40px;
        }
        .contact-label {
            font-size: 10px;
            text-transform: uppercase;
            letter-spacing: 1px;
            color: #94a3b8; /* Muted Blue/Gray */
            margin-bottom: 5px;
            font-weight: bold;
        }
        .contact-value {
            font-size: 12px;
            color: #e2e8f0;
            word-wrap: break-word;
        }

        /* 3. MAIN CONTENT AREA (Right Side) */
        .main-content {
            margin-left: 28%; /* Push content to the right of sidebar */
            padding: 60px 50px;
            position: relative;
        }

        /* 4. HEADER & TITLE */
        .top-bar {
            border-bottom: 1px solid #e2e8f0;
            padding-bottom: 20px;
            margin-bottom: 40px;
        }
        .offer-title {
            font-size: 36px;
            font-weight: 900;
            color: #1e293b;
            text-transform: uppercase;
            letter-spacing: -1px;
            line-height: 1;
        }
        .badge {
            display: inline-block;
            background-color: #ecfdf5; /* Light Green */
            color: #059669; /* Dark Green Text */
            padding: 5px 12px;
            font-size: 10px;
            font-weight: bold;
            text-transform: uppercase;
            border-radius: 20px;
            letter-spacing: 1px;
            margin-bottom: 15px;
            border: 1px solid #10b981;
        }

        /* 5. RECIPIENT INFO */
        .candidate-box {
            margin-bottom: 30px;
        }
        .candidate-name {
            font-size: 20px;
            font-weight: bold;
            color: #1e293b;
        }

        /* 6. DETAILS GRID (Using Tables for PDF safety) */
        .details-container {
            background-color: #f8fafc; /* Very light gray */
            border-radius: 8px;
            padding: 25px;
            margin: 30px 0;
            border: 1px solid #e2e8f0;
        }
        
        .details-table {
            width: 100%;
            border-collapse: collapse;
        }
        .details-table td {
            padding: 10px 0;
            vertical-align: top;
            border-bottom: 1px dashed #cbd5e1;
        }
        .details-table tr:last-child td {
            border-bottom: none;
        }
        
        .label {
            font-size: 11px;
            text-transform: uppercase;
            color: #64748b;
            font-weight: bold;
            width: 35%;
        }
        .value {
            font-size: 14px;
            font-weight: bold;
            color: #0f172a;
        }

        /* 7. CTA & SIGNATURE */
        .cta {
            background-color: #1e293b;
            color: white;
            padding: 15px;
            text-align: center;
            border-radius: 4px;
            font-size: 12px;
            margin-top: 30px;
        }

        .signature-block {
            margin-top: 50px;
        }
        .sig-img {
            font-family: 'Times New Roman', serif;
            font-style: italic;
            font-size: 32px;
            color: #10b981; /* Emerald Signature */
            margin-bottom: 5px;
        }

        /* Footer in Main Content */
        .page-footer {
            margin-top: 60px;
            font-size: 10px;
            color: #94a3b8;
            border-top: 1px solid #e2e8f0;
            padding-top: 15px;
        }

    </style>
</head>
<body>

    <div class="sidebar">
        <div class="sidebar-content">
            <div class="logo-box">
                {{ $company_name ?? 'Company Name' }}
            </div>

            <div class="contact-group">
                <div class="contact-label">Headquarters</div>
                <div class="contact-value">
                    {{ $company_address ?? 'Address' }}
                </div>
            </div>

            <div class="contact-group">
                <div class="contact-label">Contact</div>
                <div class="contact-value">
                    +1 (555) 123-4567<br>
                    hr@studioshodwe.com
                </div>
            </div>

            <div class="contact-group">
                <div class="contact-label">Website</div>
                <div class="contact-value">
                    www.studioshodwe.com
                </div>
            </div>

            <div style="position: absolute; bottom: 50px; opacity: 0.3; font-size: 80px; font-weight: bold; color: #334155; line-height: 0.8;">
                JOB<br>OFFER
            </div>
        </div>
    </div>

    <div class="main-content">

        <div class="top-bar">
            <div class="badge">Status: Offer Extended</div>
            <div class="offer-title">Job Offer</div>
            <div style="color: #64748b; margin-top: 5px; font-size: 12px;">Reference: #OFF-2026-SHD</div>
        </div>

        <p>Dear <strong>{{ $candidate_name ?? 'Adeline' }}</strong>,</p>

        <p>We are thrilled to offer you the position of <strong style="color: #10b981;">{{ $position ?? 'Graphic Designer' }}</strong> at Studio Shodwe. Your expertise and vision are exactly what we need to drive our upcoming projects forward.</p>

        <div class="details-container">
            <table class="details-table">
                <tr>
                    <td class="label">Position Title</td>
                    <td class="value">{{ $position ?? 'Graphic Designer' }}</td>
                </tr>
                <tr>
                    <td class="label">Start Date</td>
                    <td class="value">{{ $start_date ?? 'March 01, 2026' }}</td>
                </tr>
                <tr>
                    <td class="label">Monthly Salary</td>
                    <td class="value">{{ $salary ?? '$85,000 USD' }}</td>
                </tr>
                {{-- <tr>
                    <td class="label">Probation Period</td>
                    <td class="value">3 Months</td>
                </tr> --}}
                <tr>
                    <td class="label">Office Location</td>
                    <td class="value">{{ $company_address ?? 'Address' }}</td>
                </tr>
            </table>
        </div>
        <div class="cta">
            To accept this offer, please sign the attached agreement and reply by {{ $deadline ?? 'the deadline' }}.
        </div>

        <div class="signature-block">
             <div class="sig-img">{{ $signer_name ?? 'Signer' }}</div>

             <div style="font-weight: bold; color: #1e293b; font-size: 14px;">{{ $signer_name ?? 'Signer Name' }}</div>
             <div style="color: #64748b; font-size: 12px;">Head of People & Culture</div>
         </div>

        <div class="page-footer">
            &copy; {{ date('Y') }} {{ $company_name ?? 'Company Name' }} Inc. All rights reserved. | Private & Confidential
        </div>

    </div>

</body>
</html>