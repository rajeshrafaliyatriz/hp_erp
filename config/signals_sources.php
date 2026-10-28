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

        // Gujarat municipal school boards and regional press (added for Gujarat coverage; all unverified candidates, domains only, nothing fetched)
        ['Surat Municipal Corporation', 'https://www.suratmunicipal.gov.in/', 'gujarat', 'municipal_board', true, 'core,learn,insight', 'tender,circular', 'tender_portal'],
        ['Ahmedabad Municipal Corporation', 'https://ahmedabadcity.gov.in/', 'gujarat', 'municipal_board', true, 'core,learn,insight', 'tender,circular', 'tender_portal'],
        ['Vadodara Municipal Corporation', 'https://vmc.gov.in/', 'gujarat', 'municipal_board', true, 'core,learn,insight', 'tender,circular', 'tender_portal'],
        ['Rajkot Municipal Corporation', 'https://www.rmc.gov.in/', 'gujarat', 'municipal_board', true, 'core,learn,insight', 'tender,circular', 'tender_portal'],
        ['Divya Bhaskar', 'https://www.divyabhaskar.co.in/', 'gujarat', 'news', false, $all, 'news,expansion', 'news_search'],
        ['Sandesh', 'https://sandesh.com/', 'gujarat', 'news', false, $all, 'news,expansion', 'news_search'],
        ['Gujarat Samachar', 'https://www.gujaratsamachar.com/', 'gujarat', 'news', false, $all, 'news,expansion', 'news_search'],
        ['DeshGujarat', 'https://deshgujarat.com/', 'gujarat', 'news', false, $all, 'news', 'news_search'],
        ['Ahmedabad Mirror', 'https://ahmedabadmirror.com/', 'gujarat', 'news', false, $all, 'news,expansion', 'news_search'],
        ['Gujarat self-financed school management associations', null, 'gujarat', 'association', false, 'core,insight', 'association,news', 'search_only'],
        ['Gujarat school-group and trust announcements (new campus, affiliation)', null, 'gujarat', 'news', false, 'core,insight', 'expansion,affiliation,news', 'news_search'],

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
        // Added to widen direct reading. Every row is an unverified candidate until `signals:verify-sources` fetches it and records that a feed or an
        // announcement listing can be read; a wrong or blocked address simply stays "not readable". Nothing here is claimed to work.
        // Boards, departments and national programmes
        ['CBSE Academic (circulars)', 'https://cbseacademic.nic.in/', 'india', 'board', true, 'core,learn,ccb,insight', 'circular,policy', 'official_page'],
        ['NIOS', 'https://www.nios.ac.in/', 'india', 'board', true, 'learn,insight', 'circular', 'official_page'],
        ['Kendriya Vidyalaya Sangathan', 'https://kvsangathan.nic.in/', 'india', 'school_system', true, 'core,learn,insight', 'tender,circular,hiring', 'official_page'],
        ['Navodaya Vidyalaya Samiti', 'https://navodaya.gov.in/', 'india', 'school_system', true, 'core,learn,insight', 'tender,circular,hiring', 'official_page'],
        ['PM eVidya', 'https://pmevidya.education.gov.in/', 'india', 'programme', true, 'learn', 'policy', 'official_page'],
        ['ePathshala', 'https://epathshala.nic.in/', 'india', 'platform', true, 'learn', 'policy', 'official_page'],
        ['SWAYAM', 'https://swayam.gov.in/', 'india', 'platform', true, 'learn,ccb', 'policy', 'official_page'],
        ['Maharashtra State Board', 'https://www.mahahsscboard.in/', 'state', 'board', true, 'core,insight', 'circular', 'official_page'],
        ['Maharashtra Academic Authority (MAA)', 'https://www.maa.ac.in/', 'state', 'teacher_training', true, 'ccb,learn', 'circular', 'official_page'],
        ['Directorate of Education Delhi', 'https://edudel.nic.in/', 'state', 'department', true, 'core,learn,insight', 'circular,tender', 'official_page'],
        ['Karnataka School Examination Board', 'https://kseab.karnataka.gov.in/', 'state', 'board', true, 'core,insight', 'circular', 'official_page'],
        ['Tamil Nadu School Education', 'https://tnschools.gov.in/', 'state', 'department', true, 'core,learn,insight', 'circular,tender', 'official_page'],
        ['Rajasthan Board of Secondary Education', 'https://rajeduboard.rajasthan.gov.in/', 'state', 'board', true, 'core,insight', 'circular', 'official_page'],
        ['Madhya Pradesh Board of Secondary Education', 'https://mpbse.nic.in/', 'state', 'board', true, 'core,insight', 'circular', 'official_page'],
        ['Uttar Pradesh Madhyamik Shiksha Parishad', 'https://upmsp.edu.in/', 'state', 'board', true, 'core,insight', 'circular', 'official_page'],

        // School groups and school networks (expansion, new campus, leadership, technology announcements)
        ['Ryan International Group', 'https://www.ryangroup.org/', 'india', 'school_group', false, 'core,insight,ccb', 'expansion,news', 'official_page'],
        ['Podar Education Network', 'https://www.podareducation.org/', 'india', 'school_group', false, 'core,insight,ccb', 'expansion,news', 'official_page'],
        ['Amity Education Group', 'https://www.amity.edu/', 'india', 'school_group', false, 'core,insight', 'expansion,news', 'official_page'],
        ['Narayana Educational Institutions', 'https://www.narayanagroup.com/', 'india', 'school_group', false, 'core,insight', 'expansion,news', 'official_page'],
        ['Sri Chaitanya Educational Institutions', 'https://www.srichaitanya.net/', 'india', 'school_group', false, 'core,insight', 'expansion,news', 'official_page'],
        ['Euro Schools', 'https://www.euroschoolindia.com/', 'india', 'school_group', false, 'core,insight,ccb', 'expansion,news', 'official_page'],
        ['Orchids The International School', 'https://www.orchids.edu.in/', 'india', 'school_group', false, 'core,insight,ccb', 'expansion,news', 'official_page'],
        ['Vibgyor Group of Schools', 'https://www.vibgyorhigh.com/', 'india', 'school_group', false, 'core,insight,ccb', 'expansion,news', 'official_page'],
        ['Billabong High International', 'https://www.billabonghigh.com/', 'india', 'school_group', false, 'core,insight', 'expansion,news', 'official_page'],
        ['EuroKids', 'https://www.eurokidsindia.com/', 'india', 'school_group', false, 'core,insight', 'expansion,news', 'official_page'],
        ['Kangaroo Kids', 'https://www.kangarookids.in/', 'india', 'school_group', false, 'core,insight', 'expansion,news', 'official_page'],
        ['Bharatiya Vidya Bhavan', 'https://www.bhavans.info/', 'india', 'school_group', false, 'core,insight,ccb', 'expansion,news', 'official_page'],
        ['DAV College Managing Committee', 'https://davcmc.net.in/', 'india', 'school_group', false, 'core,insight,ccb', 'expansion,news,hiring', 'official_page'],
        ['Chinmaya Mission', 'https://www.chinmayamission.com/', 'india', 'school_group', false, 'core,ccb', 'expansion,news', 'official_page'],
        ['GEMS Education', 'https://www.gemseducation.com/', 'international', 'school_group', false, 'core,insight,learn', 'expansion,news', 'official_page'],
        ['Nord Anglia Education', 'https://www.nordangliaeducation.com/', 'international', 'school_group', false, 'core,insight,learn', 'expansion,news', 'official_page'],
        ['Udgam School for Children (Ahmedabad)', 'https://www.udgamschool.com/', 'gujarat', 'school', false, 'core,insight', 'expansion,news', 'official_page'],

        // International education bodies
        ['Council of International Schools', 'https://www.cois.org/', 'international', 'association', false, 'ccb,compass,insight', 'association,news', 'official_page'],
        ['ECIS', 'https://www.ecis.org/', 'international', 'association', false, 'ccb,compass', 'association,news', 'official_page'],
        ['Cognia', 'https://www.cognia.org/', 'international', 'board', false, 'insight,learn', 'policy,news', 'official_page'],
        ['ISTE', 'https://iste.org/', 'international', 'association', false, 'learn,ccb', 'association,news', 'official_page'],
        ['ASCD', 'https://ascd.org/', 'international', 'association', false, 'learn,ccb', 'association,news', 'official_page'],
        ['NAIS', 'https://www.nais.org/', 'international', 'association', false, 'core,insight', 'association,news', 'official_page'],

        // News: Gujarat cities and Indian education publications
        ['Indian Express Ahmedabad', 'https://indianexpress.com/section/cities/ahmedabad/', 'gujarat', 'news', false, $all, 'news,expansion', 'news_search'],
        ['Times of India Ahmedabad', 'https://timesofindia.indiatimes.com/city/ahmedabad', 'gujarat', 'news', false, $all, 'news,expansion', 'news_search'],
        ['Times of India Surat', 'https://timesofindia.indiatimes.com/city/surat', 'gujarat', 'news', false, $all, 'news,expansion', 'news_search'],
        ['Times of India Vadodara', 'https://timesofindia.indiatimes.com/city/vadodara', 'gujarat', 'news', false, $all, 'news,expansion', 'news_search'],
        ['Times of India Rajkot', 'https://timesofindia.indiatimes.com/city/rajkot', 'gujarat', 'news', false, $all, 'news,expansion', 'news_search'],
        ['DNA India Ahmedabad', 'https://www.dnaindia.com/ahmedabad', 'gujarat', 'news', false, $all, 'news', 'news_search'],
        ['Western Times', 'https://www.westerntimesnews.in/', 'gujarat', 'news', false, $all, 'news', 'news_search'],
        ['Deccan Herald Education', 'https://www.deccanherald.com/education', 'india', 'news', false, $all, 'news', 'news_search'],
        ['ThePrint Education', 'https://theprint.in/category/education/', 'india', 'news', false, $all, 'news,policy', 'news_search'],
        ['Business Standard Education', 'https://www.business-standard.com/education', 'india', 'news', false, $all, 'news', 'news_search'],
        ['Economic Times Education', 'https://economictimes.indiatimes.com/industry/services/education', 'india', 'news', false, $all, 'news,expansion', 'news_search'],
        ['Jagran Josh', 'https://www.jagranjosh.com/', 'india', 'news', false, 'core,insight', 'news', 'news_search'],
        ['Teacher Plus', 'https://www.teacherplus.org/', 'india', 'news', false, 'ccb,learn', 'news', 'news_search'],
        ['India Education Diary', 'https://indiaeducationdiary.in/', 'india', 'news', false, $all, 'news,expansion', 'news_search'],
        ['India Education Review', 'https://indiaeducationreview.com/', 'india', 'news', false, $all, 'news,expansion', 'news_search'],
        ['EdTech Review India', 'https://edtechreview.in/', 'india', 'news', false, 'learn,core', 'news', 'news_search'],
        ['YourStory', 'https://yourstory.com/', 'india', 'news', false, 'learn,core', 'news', 'news_search'],

        // Tender aggregators
        ['Tenders Info', 'https://www.tendersinfo.com/', 'india', 'tender_aggregator', false, 'core,learn,insight', 'tender', 'tender_portal'],
        ['Tender Wizard', 'https://www.tenderwizard.com/', 'india', 'tender_aggregator', false, 'core,learn,insight', 'tender', 'tender_portal'],
        ['Global Tenders', 'https://www.globaltenders.com/', 'international', 'tender_aggregator', false, 'core,learn,insight', 'tender', 'tender_portal'],
        ['Bid Detail', 'https://www.biddetail.com/', 'india', 'tender_aggregator', false, 'core,learn,insight', 'tender', 'tender_portal'],

        // Market context: school-software vendors' news (competitor and partner moves; never a buyer)
        ['Teachmint', 'https://www.teachmint.com/', 'india', 'vendor', false, 'core,learn', 'news', 'official_page'],
        ['Entab', 'https://www.entab.in/', 'india', 'vendor', false, 'core', 'news', 'official_page'],
        ['MyClassboard', 'https://www.myclassboard.com/', 'india', 'vendor', false, 'core,learn', 'news', 'official_page'],
    ],
];
