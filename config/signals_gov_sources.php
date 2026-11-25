<?php

/*
|--------------------------------------------------------------------------
| Government and tender source inventory (G2G and Enterprise Brain, B2G runs)
|--------------------------------------------------------------------------
|
| Separate from config/signals_sources.php, which is an education-only inventory for ScholarClone. Nothing here is read, crawled or enabled by being
| listed: this file is a REGISTER of what was checked and what the result was. A source is `usable` only when a check read its landing page without
| breaking robots.txt. A landing page that reads is NOT proof that its tender listings read: portals built on GePNIC load their tables dynamically.
|
| Row: [id, name, url, group, status, note]
|   group  : tender_portal | ministry_news | agency | aggregator
|   status : usable            landing page read on 2026-11-21 with the pipeline's own fetcher; robots.txt allows it
|            needs_review       readable, but a third-party site: its terms must be checked before it is used
|            needs_feed         robots.txt refuses our bot, the site answers HTTP 403, or the page needs JavaScript or a login; reachable only through an
|                               official feed, an authorised export or manual entry
| Check results: docs/signal-engine/gov-source-readability.json (scripts/gov-source-check.php). Re-check before relying on a row.
*/

return [
    'checked_at' => '2026-11-21',

    'sources' => [
        ['cppp', 'Central Public Procurement Portal (CPPP)', 'https://eprocure.gov.in/eprocure/app', 'tender_portal', 'usable', 'Landing page only; tender tables are dynamic.'],
        ['etenders_central', 'Central e-tenders', 'https://etenders.gov.in/eprocure/app', 'tender_portal', 'usable', 'Landing page only; tender tables are dynamic.'],
        ['gem_home', 'Government e-Marketplace (GeM)', 'https://gem.gov.in/', 'tender_portal', 'usable', ''],
        ['gem_bids', 'GeM bids', 'https://bidplus.gem.gov.in/all-bids', 'tender_portal', 'usable', 'Little listing text returned: confirm the bid list is readable before relying on it.'],
        ['tn_tenders', 'Tamil Nadu tenders', 'https://tntenders.gov.in/nicgep/app', 'tender_portal', 'usable', 'Landing page only; tender tables are dynamic.'],
        ['kerala_tenders', 'Kerala e-tenders', 'https://etenders.kerala.gov.in/nicgep/app', 'tender_portal', 'usable', 'Landing page only; tender tables are dynamic.'],
        ['digital_india', 'Digital India', 'https://www.digitalindia.gov.in/', 'ministry_news', 'usable', 'Programme news; no tender words on the page.'],
        ['cdac', 'C-DAC', 'https://www.cdac.in/', 'agency', 'usable', ''],
        ['mygov', 'MyGov', 'https://www.mygov.in/', 'agency', 'usable', 'Citizen engagement portal; low tender relevance.'],
        ['bidassist', 'BidAssist', 'https://bidassist.com/', 'aggregator', 'needs_review', 'Third-party aggregator; returned a real listing page. Check its terms before use.'],

        ['nprocure', 'Gujarat nProcure', 'https://www.nprocure.com/', 'tender_portal', 'needs_feed', 'robots.txt refuses our bot.'],
        ['mahatenders', 'Maharashtra tenders', 'https://mahatenders.gov.in/nicgep/app', 'tender_portal', 'needs_feed', 'robots.txt refuses our bot.'],
        ['ireps', 'Indian Railways e-procurement', 'https://www.ireps.gov.in/', 'tender_portal', 'needs_feed', 'robots.txt refuses our bot.'],
        ['gil', 'Gujarat Informatics Ltd', 'https://gil.gujarat.gov.in/', 'tender_portal', 'needs_feed', 'robots.txt refuses our bot.'],
        ['gujarat_portal', 'Gujarat Government portal', 'https://gujaratindia.gov.in/', 'ministry_news', 'needs_feed', 'robots.txt refuses our bot.'],
        ['nielit', 'NIELIT', 'https://www.nielit.gov.in/', 'agency', 'needs_feed', 'robots.txt refuses our bot.'],
        ['tenderdetail', 'TenderDetail', 'https://www.tenderdetail.com/', 'aggregator', 'needs_feed', 'robots.txt refuses our bot.'],
        ['tender247', 'Tender247', 'https://www.tender247.com/', 'aggregator', 'needs_feed', 'robots.txt refuses our bot.'],
        ['pib', 'PIB press releases', 'https://pib.gov.in/allRel.aspx', 'ministry_news', 'needs_feed', 'Answers HTTP 403 to our bot.'],
        ['meity', 'MeitY', 'https://www.meity.gov.in/', 'ministry_news', 'needs_feed', 'Answers HTTP 403 to our bot.'],
        ['niti', 'NITI Aayog', 'https://www.niti.gov.in/', 'ministry_news', 'needs_feed', 'Answers HTTP 403 to our bot.'],
        ['msde', 'Ministry of Skill Development (MSDE)', 'https://www.msde.gov.in/', 'ministry_news', 'needs_feed', 'Answers HTTP 403 to our bot.'],
        ['nic', 'NIC', 'https://www.nic.in/', 'agency', 'needs_feed', 'Answers HTTP 403 to our bot.'],
        ['tendersontime', 'Tenders On Time', 'https://www.tendersontime.com/', 'aggregator', 'needs_feed', 'Answers HTTP 403 to our bot.'],
        ['skillindia', 'Skill India', 'https://www.skillindia.gov.in/', 'ministry_news', 'needs_feed', 'Little readable text: probably needs JavaScript or a login.'],
        ['dopt', 'DoPT (personnel and HR policy)', 'https://dopt.gov.in/', 'ministry_news', 'needs_feed', 'Little readable text: probably needs JavaScript or a login.'],
    ],
];
