<!DOCTYPE html>
<html lang="en">
<head>
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>
    <title>Job Offer Letter</title>
    <style>
        /* 1. GLOBAL RESET */
        @page { margin: 0; } /* Zero margins for full bleed background */
        body {
            margin: 0;
            padding: 0;
            font-family: 'Helvetica', Arial, sans-serif;
            font-size: 14px;
            color: #333;
        }

        /* 2. CSS-DRAWN SHAPES (The "Images") */
        
        /* The Big Blue Top Wave */
        .header-wave {
            position: fixed;
            top: -450px;       /* Pull the circle up so only bottom shows */
            left: -20%;        /* Center it horizontally */
            width: 140%;       /* Make it wider than the page */
            height: 600px;     /* Tall enough to create a curve */
            background-color: #005b96; /* Navy Blue */
            border-radius: 50%; /* Makes it a circle */
            z-index: -2;
        }

        /* The Lighter Accent Curve (Top Right) */
        .header-accent {
            position: fixed;
            top: -150px;
            right: -100px;
            width: 400px;
            height: 400px;
            background-color: #4facfe; /* Lighter Blue */
            border-radius: 50%;
            opacity: 0.6;
            z-index: -1;
        }

        /* The Footer Wave */
        .footer-wave {
            position: fixed;
            bottom: -100px;
            left: -10%;
            width: 120%;
            height: 200px;
            background-color: #005b96;
            border-radius: 50% 50% 0 0; /* Curve the top only */
            z-index: -2;
        }

        /* 3. CONTENT CONTAINER */
        .page-content {
            padding: 180px 50px 100px 50px; /* Padding pushes text away from waves */
            position: relative;
            z-index: 10;
        }

        /* 4. TYPOGRAPHY & LAYOUT */
        .logo-text {
            position: absolute;
            top: 40px;
            right: 50px;
            color: white;
            font-size: 24px;
            font-weight: bold;
            text-transform: uppercase;
            text-align: right;
        }
        
        .main-title {
            font-size: 32px;
            font-weight: bold;
            color: #005b96;
            text-transform: uppercase;
            margin-bottom: 40px;
            border-bottom: 2px solid #eee;
            padding-bottom: 10px;
        }

        /* Table Styles */
        .w-full { width: 100%; border-collapse: collapse; }
        .info-box {
            background-color: #f8f9fa;
            border-left: 5px solid #005b96;
            padding: 20px;
            margin-bottom: 30px;
            border-radius: 4px;
        }
        
        .details-table td {
            padding: 10px 0;
            border-bottom: 1px solid #eee;
        }
        .label {
            width: 30%;
            color: #666;
            font-weight: bold;
            text-transform: uppercase;
            font-size: 12px;
        }
        .value {
            font-weight: bold;
            color: #333;
            font-size: 14px;
        }

        /* Footer Text */
        .footer-info {
            position: fixed;
            bottom: 30px;
            width: 100%;
            text-align: center;
            color: white;
            font-size: 12px;
            z-index: 10;
        }
    </style>
</head>
<body>

    <div class="header-wave"></div>
    <div class="header-accent"></div>
    <div class="footer-wave"></div>

    <div class="logo-text">
        {{ $company_name }}<br>
        <span style="font-size: 12px; font-weight: normal; opacity: 0.9;">Creative Solutions</span>
    </div>

    <div class="page-content">

        <div class="main-title">Job Offer Letter</div>

        <table class="w-full">
            <tr>
                <td width="60%">
                    <div class="info-box">
                        <div style="color:#005b96; font-weight:bold; margin-bottom:5px;">TO:</div>
                        <div style="font-size: 16px; font-weight:bold;">{{ $candidate_name ?? 'Adeline Palmerston' }}</div>
                        <div>123 Anywhere St., Any City</div>
                    </div>
                </td>
                <td width="40%" align="right" valign="top">
                    <div style="margin-bottom: 5px;"><strong>Date:</strong> {{ date('F d, Y') }}</div>
                    <div style="font-size: 12px; color: #666;">Ref ID: #OFF-2026-88</div>
                </td>
            </tr>
        </table>

        <p>Dear <strong>{{ $candidate_name ?? 'Candidate' }}</strong>,</p>

        <p>We are absolutely delighted to offer you the position of <strong style="color:#005b96;">{{ $position ?? 'Graphic Designer' }}</strong> at Studio Shodwe. We believe your skills and creativity will be a perfect match for our team.</p>

        <div style="margin-top: 30px; margin-bottom: 10px; font-weight: bold; color: #005b96;">EMPLOYMENT DETAILS</div>
        
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
                <td class="label">Month Salary</td>
                <td class="value">{{ $salary ?? '$85,000 USD' }}</td>
            </tr>
            <tr>
                <td class="label">Location</td>
                <td class="value">On-site (Studio A)</td>
            </tr>
        </table>

        <br>
        <p style="background: #fffbe6; padding: 15px; border: 1px solid #ffe58f; border-radius: 4px;">
            Please confirm your acceptance of this offer by signing and replying before <strong>{{ $deadline ?? 'February 20, 2026' }}</strong>.
        </p>

        <br><br>

        <table class="w-full">
            <tr>
                <td width="50%">
                    <div style="margin-bottom: 20px;">Sincerely,</div>
                    
                    <div style="font-family: 'Times New Roman', serif; font-style: italic; font-size: 28px; color: #005b96; margin-bottom: 5px;">
                        {{ $signer_name }}
                    </div>

                    <div style="font-weight: bold;">{{ $signer_name }}</div>
                    <div style="color: #666; font-size: 12px;">HR Manager</div>
                </td>
            </tr>
        </table>

    </div>

    <div class="footer-info">
        {{ $company_name }} &bull; {{ $company_address }} &bull; hello@reallygreatsite.com
    </div>

</body>
</html>