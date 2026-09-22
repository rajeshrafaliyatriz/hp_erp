<?php

/**
 * The hiring poster's look and fixed copy.
 *
 * ── WHY THE PALETTE LIVES HERE AND NOWHERE ELSE ─────────────────────────────
 *
 * Two renderers draw this poster: Satori draws the PNGs in the Next app, and
 * dompdf draws the A4 PDF here. If each kept its own colours they would drift
 * apart on the first change and nobody would notice, because the two are never
 * seen side by side.
 *
 * So this file is the only place a poster colour is written down. It is echoed
 * into the JSON the Next renderer consumes, so changing a value here moves both
 * renderers at once.
 *
 * The values come from the product's own brand tokens in the frontend
 * (app/globals.css: --brand-navy #071943, --brand-navy-light #2563eb) rather
 * than being invented for the poster.
 */
return [

    'palette' => [
        'navy'        => '#071943',
        'blue'        => '#2563eb',
        'blue_soft'   => '#eff4ff',
        'green'       => '#16a34a',
        'green_soft'  => '#effaf1',
        'ink'         => '#0b1220',
        'muted'       => '#5b6880',
        'line'        => '#dbe3f0',
        'paper'       => '#ffffff',
    ],

    /*
     * Fixed copy. Deliberately free of any claim about the employer - the
     * poster downloads unreviewed, so the only words on it that this system
     * chooses are structural.
     */
    'copy' => [
        'headline'         => "WE’RE HIRING",
        'headline_multi'   => "WE’RE HIRING",
        'cta'              => 'Apply Now',
        'panel_primary'    => 'Key Responsibilities',
        'panel_secondary'  => "What We’re Looking For",
        'panel_skills'     => "Skills We're Looking For",
        'panel_quals'      => 'Qualifications',
        'facts_heading'    => 'The role at a glance',
    ],

    /* Logo fetch. Mirrors the inlineLogo() limits already used for certificates. */
    'logo' => [
        'timeout' => 5,
        'max_bytes' => 1048576,
        'cache_seconds' => 3600,
    ],
];
