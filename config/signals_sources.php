<?php

/*
|--------------------------------------------------------------------------
| Education source inventory (candidates, NOT verified feeds)
|--------------------------------------------------------------------------
|
| One row per candidate: [name, url|null, region, category, official, products, signal types, retrieval method].
| Every row starts as `pending_verification` (or `needs_url` when the entry is a category with no address yet). Nothing here is fetched,
| crawled or enabled by being listed: App\Domain\Signals\Sources\SourceRegistry expands the rows, removes duplicate addresses and only reports a
| source as operational when a separately authorised verification step has recorded a result in `verified` below.
| The existing searches in config/signals_icp.php are untouched; this inventory only adds to them.
|
| products: core|learn|ccb|insight|compass (the five ScholarClone capabilities).  region: gujarat|state|india|international.
*/

$all = 'core,learn,ccb,insight,compass';

return [
    // source id => ['verified_at' => ISO date, 'http_status' => int, 'machine_readable' => bool]  (filled only by an authorised verification run)
    'verified' => [],

    'candidates' => [
        // Gujarat and state level
        ['Gujarat Secondary and Higher Secondary Education Board', 'https://website.gseb.org/', 'gujarat', 'board', true, 'core,insight', 'circular,policy', 'official_page'],
        ['Gujarat Education Department', 'https://gujarat-education.gov.in/', 'gujarat', 'department', true, $all, 'circular,policy,tender', 'official_page'],
        ['Gujarat COGENT circulars', 'https://gujgov.edu.in/cogent/circular', 'gujarat', 'circular', true, 'core,insight', 'circular', 'official_page'],
        ['RTE Gujarat school list', 'https://rte.orpgujarat.com/Common/SchoolList', 'gujarat', 'directory', true, 'core', 'directory', 'directory'],
        ['DIKSHA Gujarat', 'https://diksha.gov.in/gj/', 'gujarat', 'platform', true, 'learn,ccb', 'policy', 'official_page'],
        ['GCERT', 'https://gcert.gujarat.gov.in/', 'gujarat', 'teacher_training', true, 'ccb,learn', 'circular,policy', 'official_page'],
        ['Samagra Shiksha Gujarat', 'https://www.ssagujarat.org/', 'gujarat', 'programme', true, $all, 'tender,circular,policy', 'official_page'],
        ['Gujarat Government portal', 'https://gujaratindia.gov.in/', 'gujarat', 'government', true, 'core,insight', 'policy', 'official_page'],
        ['Surat District education', 'https://surat.nic.in/education/', 'gujarat', 'district', true, 'core', 'circular', 'official_page'],
        ['Gujarat public procurement (nProcure)', 'https://www.nprocure.com/', 'gujarat', 'tender_portal', true, 'core,learn,insight', 'tender', 'tender_portal'],
        ['Gujarat State Examination Board', 'https://www.sebexam.org/', 'gujarat', 'board', true, 'insight,learn', 'circular', 'official_page'],
        ['Gujarat Informatics Limited', 'https://gil.gujarat.gov.in/', 'gujarat', 'it_procurement', true, 'core,learn,insight', 'tender', 'tender_portal'],
        ['Gujarat Legislative Assembly', 'https://gujaratassembly.gov.in/', 'gujarat', 'policy', true, 'insight', 'policy', 'search_only'],
        ['Gujarat district education office portals', null, 'gujarat', 'district', true, 'core', 'circular', 'search_only'],
        ['Gujarat district-level education office pages', null, 'gujarat', 'district', true, 'core', 'circular', 'search_only'],
        ['Gujarat DIET and teacher training institute sites', null, 'gujarat', 'teacher_training', true, 'ccb', 'circular,tender', 'search_only'],
        ['Gujarat school recruitment and education notices', null, 'gujarat', 'recruitment', true, 'ccb,core', 'hiring,circular', 'search_only'],
        ['State school recognition and education programme pages', null, 'state', 'programme', true, 'core,insight', 'policy,circular', 'search_only'],

        // National authorities and directories
        ['Ministry of Education', 'https://www.education.gov.in/', 'india', 'ministry', true, $all, 'policy,circular', 'official_page'],
        ['Department of School Education & Literacy', 'https://dsel.education.gov.in/', 'india', 'ministry', true, $all, 'policy,circular', 'official_page'],
        ['UDISE+', 'https://udiseplus.gov.in/', 'india', 'directory', true, 'core,insight', 'directory', 'directory'],
        ['CBSE', 'https://www.cbse.gov.in/', 'india', 'board', true, 'core,insight,ccb,compass', 'circular,policy', 'official_page'],
        ['CBSE SARAS affiliation', 'https://saras.cbse.gov.in/', 'india', 'affiliation', true, 'core', 'affiliation', 'directory'],
        ['CBSE tender listings', 'https://www.cbse.gov.in/cbsenew/cbse_tender.html', 'india', 'tender_portal', true, 'core,learn,insight', 'tender', 'tender_portal'],
        ['CISCE', 'https://cisce.org/', 'india', 'board', true, 'core,insight,ccb', 'circular,policy', 'official_page'],
        ['NCERT', 'https://ncert.nic.in/', 'india', 'curriculum', true, 'learn,ccb', 'policy', 'official_page'],
        ['CIET-NCERT', 'https://ciet.ncert.gov.in/', 'india', 'edtech', true, 'learn,ccb', 'policy,tender', 'official_page'],
        ['NCTE', 'https://ncte.gov.in/', 'india', 'teacher_training', true, 'ccb', 'circular,policy', 'official_page'],
        ['NIEPA', 'https://niepa.ac.in/', 'india', 'research', true, 'insight,ccb', 'policy', 'official_page'],
        ['National Testing Agency', 'https://nta.ac.in/', 'india', 'assessment', true, 'learn,compass', 'circular', 'official_page'],
        ['Samagra Shiksha', 'https://samagra.education.gov.in/', 'india', 'programme', true, $all, 'tender,policy', 'official_page'],
        ['PM SHRI Schools', 'https://pmshri.education.gov.in/', 'india', 'programme', true, 'core,learn,insight', 'policy,tender', 'official_page'],
        ['DIKSHA', 'https://diksha.gov.in/', 'india', 'platform', true, 'learn,ccb', 'policy', 'official_page'],
        ['National Scholarship Portal', 'https://scholarships.gov.in/', 'india', 'programme', true, 'insight', 'policy', 'official_page'],
        ['Central Public Procurement Portal', 'https://eprocure.gov.in/', 'india', 'tender_portal', true, 'core,learn,insight', 'tender', 'tender_portal'],
        ['Government e-Marketplace (GeM)', 'https://gem.gov.in/', 'india', 'tender_portal', true, 'core,learn,insight', 'tender,procurement', 'tender_portal'],
        ['India Code', 'https://www.indiacode.nic.in/', 'india', 'legislation', true, 'insight', 'policy', 'search_only'],
        ['Press Information Bureau', 'https://pib.gov.in/', 'india', 'government', true, $all, 'policy,news', 'rss_candidate'],

        // Associations, leadership, school networks
        ['Association of Indian Principals', 'https://indianprincipals.com/', 'india', 'association', false, 'ccb,compass', 'association,news', 'official_page'],
        ["India Principals' Network", 'https://www.ipnindia.in/', 'india', 'association', false, 'ccb', 'association,news', 'official_page'],
        ['All India Private Schools Association', 'https://aipsa.in/', 'india', 'association', false, 'core,insight', 'association,policy', 'official_page'],
        ["Indian Principals' Association", 'https://indianprincipalassociation.org/', 'india', 'association', false, 'ccb', 'association', 'official_page'],
        ['National Progressive Schools Conference', 'https://www.npsc.co.in/', 'india', 'association', false, 'core,ccb', 'association', 'directory'],
        ["Indian Public Schools' Conference", 'https://ipsc.co.in/', 'india', 'association', false, 'core,ccb', 'association', 'directory'],
        ['Sahodaya School Complexes', 'https://sahodayaschools.org/', 'india', 'association', false, 'core,ccb,insight', 'association,directory', 'directory'],
        ['EducationWorld', 'https://www.educationworld.in/', 'india', 'news', false, $all, 'news,expansion', 'news_search'],
        ['Education Today', 'https://educationtoday.co/', 'india', 'news', false, $all, 'news', 'news_search'],
        ['FICCI', 'https://ficci.in/', 'india', 'industry_body', false, 'insight', 'policy,news', 'search_only'],
        ['Confederation of Indian Industry', 'https://www.cii.in/', 'india', 'industry_body', false, 'insight', 'policy,news', 'search_only'],
        ['ASSOCHAM', 'https://www.assocham.org/', 'india', 'industry_body', false, 'insight', 'policy,news', 'search_only'],
        ['State private-school associations', null, 'state', 'association', false, 'core,insight', 'association', 'search_only'],
        ['Regional Sahodaya complex sites and member directories', null, 'state', 'association', false, 'core,ccb', 'association,directory', 'directory'],

        // News
        ['Times of India Education', 'https://timesofindia.indiatimes.com/education', 'india', 'news', false, $all, 'news,expansion', 'news_search'],
        ['Indian Express Education', 'https://indianexpress.com/section/education/', 'india', 'news', false, $all, 'news', 'rss_candidate'],
        ['Hindustan Times Education', 'https://www.hindustantimes.com/education', 'india', 'news', false, $all, 'news', 'rss_candidate'],
        ['The Hindu', 'https://www.thehindu.com/', 'india', 'news', false, $all, 'news', 'rss_candidate'],
        ['NDTV Education', 'https://www.ndtv.com/education', 'india', 'news', false, $all, 'news', 'news_search'],
        ['News18', 'https://www.news18.com/', 'india', 'news', false, $all, 'news', 'news_search'],
        ['India Today Education', 'https://www.indiatoday.in/education-today', 'india', 'news', false, $all, 'news', 'news_search'],
        ['EdexLive', 'https://www.edexlive.com/', 'india', 'news', false, $all, 'news,expansion', 'news_search'],
        ['Elets', 'https://eletsnews.com/', 'india', 'news', false, 'learn,core,insight', 'news,procurement', 'news_search'],
        ['BW Education', 'https://bweducation.com/', 'india', 'news', false, $all, 'news', 'news_search'],
        ['Education Times', 'https://educationtimes.com/', 'india', 'news', false, $all, 'news', 'news_search'],
        ['Careers360', 'https://www.careers360.com/', 'india', 'directory', false, 'compass,core', 'directory,news', 'directory'],
        ['Shiksha', 'https://www.shiksha.com/', 'india', 'directory', false, 'compass,core', 'directory,news', 'directory'],

        // Tenders, procurement, hiring
        ['Maharashtra e-Tenders', 'https://mahatenders.gov.in/', 'state', 'tender_portal', true, 'core,learn,insight', 'tender', 'tender_portal'],
        ['Karnataka e-Procurement', 'https://eproc.karnataka.gov.in/', 'state', 'tender_portal', true, 'core,learn,insight', 'tender', 'tender_portal'],
        ['Tender247', 'https://www.tender247.com/', 'india', 'tender_aggregator', false, 'core,learn,insight', 'tender', 'tender_portal'],
        ['BidAssist', 'https://bidassist.com/', 'india', 'tender_aggregator', false, 'core,learn,insight', 'tender', 'tender_portal'],
        ['TenderDetail', 'https://www.tenderdetail.com/', 'india', 'tender_aggregator', false, 'core,learn,insight', 'tender', 'tender_portal'],
        ['FirstTender', 'https://www.firsttender.com/', 'india', 'tender_aggregator', false, 'core,learn,insight', 'tender', 'tender_portal'],
        ['TenderTiger', 'https://www.tendertiger.com/', 'india', 'tender_aggregator', false, 'core,learn,insight', 'tender', 'tender_portal'],
        ['LinkedIn Jobs', 'https://www.linkedin.com/jobs/', 'india', 'job_board', false, 'core,ccb', 'hiring', 'job_search'],
        ['Naukri', 'https://www.naukri.com/', 'india', 'job_board', false, 'core,ccb', 'hiring', 'job_search'],
        ['Indeed India', 'https://in.indeed.com/', 'india', 'job_board', false, 'core,ccb', 'hiring', 'job_search'],
        ['foundit', 'https://www.foundit.in/', 'india', 'job_board', false, 'core,ccb', 'hiring', 'job_search'],
        ['Shine', 'https://www.shine.com/', 'india', 'job_board', false, 'core,ccb', 'hiring', 'job_search'],

        // International and additional discovery
        ['International Baccalaureate', 'https://www.ibo.org/', 'international', 'board', true, 'learn,ccb,compass', 'affiliation,policy', 'official_page'],
        ['Cambridge International Education', 'https://www.cambridgeinternational.org/', 'international', 'board', true, 'learn,ccb,compass', 'affiliation,policy', 'official_page'],
        ['UNESCO Education', 'https://www.unesco.org/en/education', 'international', 'research', true, 'insight', 'policy', 'search_only'],
        ['OECD Education', 'https://www.oecd.org/education/', 'international', 'research', true, 'insight', 'policy', 'search_only'],
        ['UNICEF Education', 'https://www.unicef.org/education', 'international', 'research', true, 'insight', 'policy', 'search_only'],
        ['World Bank Education', 'https://www.worldbank.org/en/topic/education', 'international', 'research', true, 'insight', 'policy,procurement', 'search_only'],
        ['EdSurge', 'https://www.edsurge.com/', 'international', 'news', false, 'learn,insight', 'news', 'news_search'],
        ['eSchool News', 'https://www.eschoolnews.com/', 'international', 'news', false, 'learn,insight', 'news', 'news_search'],
        ['Times Higher Education', 'https://www.timeshighereducation.com/', 'international', 'news', false, 'insight', 'news', 'news_search'],
        ['Education Week', 'https://www.edweek.org/', 'international', 'news', false, 'learn,ccb,insight', 'news', 'news_search'],
        ['HolonIQ', 'https://www.holoniq.com/', 'international', 'market_research', false, 'insight', 'news', 'search_only'],
        ['Tech & Learning', 'https://www.techlearning.com/', 'international', 'news', false, 'learn', 'news', 'news_search'],
        ['Common Sense Education', 'https://www.commonsense.org/education', 'international', 'news', false, 'learn', 'news', 'search_only'],
        ['State education department portals across India', null, 'state', 'department', true, $all, 'circular,policy,tender', 'search_only'],
        ['State school education board portals across India', null, 'state', 'board', true, 'core,insight', 'circular,policy', 'search_only'],
        ['State tender portals across India', null, 'state', 'tender_portal', true, 'core,learn,insight', 'tender', 'tender_portal'],
        ['Public school affiliation and recognition directories', null, 'india', 'affiliation', true, 'core', 'affiliation,directory', 'directory'],
        ['Public school group and trust directories', null, 'india', 'directory', false, 'core,insight', 'directory,expansion', 'directory'],
        ['School expansion and campus announcement sources', null, 'india', 'news', false, 'core,insight', 'expansion,news', 'news_search'],
        ['Official education technology procurement notices', null, 'india', 'tender_portal', true, 'core,learn,insight', 'tender,procurement', 'tender_portal'],
        ['Official teacher and school-leader recruitment notices', null, 'india', 'recruitment', true, 'ccb,core', 'hiring', 'search_only'],
        ['School association event and conference calendars', null, 'india', 'association', false, 'ccb,core', 'association,news', 'search_only'],
        ['Official education circular, policy and notification feeds', null, 'india', 'policy', true, $all, 'circular,policy', 'rss_candidate'],
    ],
];
