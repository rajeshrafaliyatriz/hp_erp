<?php

/*
|--------------------------------------------------------------------------
| G2G Foundation Data — Shared Business Taxonomy
|--------------------------------------------------------------------------
|
| The controlled vocabulary for signals, offers, and partners from the
| G2G_Foundation_Data_Portfolio_Partners workbook.
| Every code and category here is source-defined.
|
*/

return [
    'needs' => [
        'N01' => 'Competency framework design / FRAC mapping',
        'N02' => 'Capability gap assessment',
        'N03' => 'Role & task library / job architecture',
        'N04' => 'Competency-linked learning & CPD',
        'N05' => 'Talent, succession & career pathing',
        'N06' => 'Core HRMS / HR digitization',
        'N07' => 'Task execution & SOP automation (ESO, human/AI/hybrid)',
        'N08' => 'Agentic / conversational AI for workforce',
        'N09' => 'Organizational intelligence / decision support',
        'N10' => 'Knowledge management / company brain',
        'N11' => 'Data consolidation from existing systems (Excel/ERP)',
        'N12' => 'School administration ERP',
        'N13' => 'Fee management & financial intelligence',
        'N14' => 'LMS & AI content generation',
        'N15' => 'Student career guidance',
        'N16' => 'Digital office / file tracking',
        'N17' => 'Teacher professional development',
        'N18' => 'Learning mastery & personalised learning',
        'N19' => 'Higher-education administration',
        'N20' => 'Regulatory / accreditation readiness (NAAC, NABH etc.)',
    ],

    'segments' => [
        'GOV-CENTRAL' => 'Central ministry / department',
        'GOV-STATE'   => 'State department',
        'GOV-ULB'     => 'Urban local body',
        'PSU'         => 'PSU / CPSE',
        'HOSPITAL'    => 'Hospital / healthcare',
        'UNIV'        => 'University / higher education',
        'TRAIN-INST'  => 'Training institute',
        'SME'         => 'SME, 100–500 employees',
        'ENTERPRISE'  => 'Enterprise, 500+ employees',
        'K12-PVT'     => 'K-12 private school',
        'K12-TRUST'   => 'K-12 trust / school chain',
        'K12-GOVT'    => 'Government school system',
        'INTL'        => 'International (GCC etc.)',
    ],

    'signals' => [
        'S-GOV-CBP'    => 'Govt CBP / Karmayogi workshop or rollout',
        'S-GOV-RFP'    => 'Empanelment RFP / EOI / tender',
        'S-INST-HRD'   => 'Institutional HR-digitization tender or news',
        'S-INST-ACCR'  => 'Accreditation cycle (NAAC, NABH etc.)',
        'S-LEAD-HIRE'  => 'New CHRO / L&D / FRAC leadership hire or role posting',
        'S-SME-FUND'   => 'SME funding / expansion (100–500 band)',
        'S-SI-WIN'     => 'SI wins govt/PSU HR-transformation contract',
        'S-COMP'       => 'Competitor win/loss in target segment',
        'S-SCH-NEW'    => 'New school / chain expansion / funding',
        'S-SCH-SCHEME' => 'Digital-education scheme or school ERP tender',
        'S-SCH-SEEK'   => 'School/trust publicly seeking ERP/LMS/FTS',
        'S-EB-KM'      => 'Enterprise seeking KM / org intelligence / AI copilot',
        'S-EB-DX'      => 'Enterprise digital-transformation announcement',
        'S-EB-ADJ'     => 'Adjacent-market tenant pattern (FiberValley-like)',
    ],

    'parent_products' => [
        'G2G'         => 'G2G Core Capability & HRIT',
        'EB'          => 'Enterprise Brain',
        'Scholar K-12'=> 'Scholar K-12',
        'HE'          => 'Higher Education',
        'Bundle'      => 'Commercial Bundle',
    ],

    'readiness_statuses' => [
        'Live – with client'                  => 'Live – with client',
        'Proven on real client data (pilot)' => 'Proven on real client data (pilot)',
        'Built – no production client'        => 'Built – no production client',
        'In development'                      => 'In development',
        'Pilot planned'                       => 'Pilot planned',
        'Prototype'                           => 'Prototype',
        'Proposal / concept'                  => 'Proposal / concept',
        'Deferred'                            => 'Deferred',
    ],

    // Source workbook rule: An offer is partner-sellable only when readiness is Live, Proven on real client data, or Built.
    'partner_sellable_readiness' => [
        'Live – with client',
        'Proven on real client data (pilot)',
        'Built – no production client',
    ],

    'deployment_models' => [
        'SaaS (multi-tenant)'               => 'SaaS (multi-tenant)',
        'On-prem'                           => 'On-prem',
        'Hybrid'                            => 'Hybrid',
        'Installable layer on client systems'=> 'Installable layer on client systems',
        'Services-led'                      => 'Services-led',
    ],

    'deal_bands' => [
        '₹0–5L'   => '₹0–5L',
        '₹5–25L'  => '₹5–25L',
        '₹25L–1Cr'=> '₹25L–1Cr',
        '₹1–10Cr' => '₹1–10Cr',
        '₹10Cr+'  => '₹10Cr+',
    ],

    'procurement_routes' => [
        'GeM'                              => 'GeM',
        'Open tender (CPPP / state portal)'=> 'Open tender (CPPP / state portal)',
        'Empanelment / EOI'                => 'Empanelment / EOI',
        'Direct purchase'                  => 'Direct purchase',
        'Via SI subcontract'               => 'Via SI subcontract',
        'Grant / scheme-funded'            => 'Grant / scheme-funded',
    ],

    'geographies' => [
        'All India'           => 'All India',
        'Gujarat'             => 'Gujarat',
        'Maharashtra'         => 'Maharashtra',
        'Rajasthan'           => 'Rajasthan',
        'Madhya Pradesh'      => 'Madhya Pradesh',
        'Delhi NCR'           => 'Delhi NCR',
        'Karnataka'           => 'Karnataka',
        'Tamil Nadu'          => 'Tamil Nadu',
        'Telangana'           => 'Telangana',
        'Uttar Pradesh'       => 'Uttar Pradesh',
        'West Bengal'         => 'West Bengal',
        'DNH & DD'            => 'Dadra and Nagar Haveli and Daman and Diu',
        'Other Indian states' => 'Other Indian states',
        'UAE / GCC'           => 'UAE / GCC',
        'Other international' => 'Other international',
    ],

    'partner_types' => [
        'System integrator'     => 'System integrator',
        'Regional reseller'     => 'Regional reseller',
        'HR / L&D consultancy'  => 'HR / L&D consultancy',
        'Education consultancy' => 'Education consultancy',
        'Implementation partner'=> 'Implementation partner',
        'Referral partner'      => 'Referral partner',
        'Technology alliance'   => 'Technology alliance',
    ],

    'delivery_capabilities' => [
        'Sales only'               => 'Sales only',
        'Sales + first-line support'=> 'Sales + first-line support',
        'Full implementation'      => 'Full implementation',
        'Needs Triz delivery team' => 'Needs Triz delivery team',
    ],

    'preferred_channels' => [
        'Email'          => 'Email',
        'WhatsApp'       => 'WhatsApp',
        'Phone'          => 'Phone',
        'Partner portal' => 'Partner portal',
    ],

    'partner_statuses' => [
        'Prospect'   => 'Prospect',
        'Onboarding' => 'Onboarding',
        'Active'     => 'Active',
        'Paused'     => 'Paused',
        'Exited'     => 'Exited',
    ],
];

