<?php

/*
|--------------------------------------------------------------------------
| SCHOLARCLONE: who buys it, and what shows that they need it
|--------------------------------------------------------------------------
| The ONE definition the Signals engine works from (App\Domain\Signals\Scholar\ScholarClone reads it).
| ScholarClone is the only product; there are no other ICPs, no per-organisation copies and no admin screen.
| Change this file to change what the engine looks for; no code needs to change.
|
|   pitch          one line on what ScholarClone is (shown to the model)
|   parts          what we sell, in the words the buyer uses; a lead is built on one of these
|   must_have      hard rules, each checked ONLY when its fact is known (unknown never rejects)
|   disqualifiers  phrase lists matched against the organisation name (never raw regular expressions)
|   watch_rules    keep on WATCH (not reject) while a fact holds, e.g. a new ERP signed in the last 12 months
|   vendor_phrases who SELLS what we sell: a competitor or partner, never a buyer
|   triggers       the events that show a school needs us, where they show up, how long they stay fresh
|   queries        searches written in the BUYER's situation and words (never "AI solutions": those are our words)
|                  `domains` restricts a search to those sites (LinkedIn, job boards, tender portals)
*/

$tenderSites = ['gem.gov.in', 'eprocure.gov.in', 'nprocure.com', 'mahatenders.gov.in'];

return [
    'scholar_k12' => [
        'label' => 'ScholarClone K-12 Bundle',
        'pitch' => 'ScholarClone is one system for a school: admissions, fees, attendance and results; ready lessons and assessments; teacher development; and career guidance from Grade 6.',
        'competitors' => ['Teachmint', 'Entab', 'Fedena', 'Edunext', 'Campus365', 'MyClassboard', 'Illumine', 'Schoolpad'],
        'product_line' => 'scholar',           // stored as business_fit when it is one of the known product lines
        'industry_label' => 'private K-12 schools and school groups',
        'buyer_types' => ['school', 'school_group'],
        'regions' => ['Gujarat', 'Dadra and Nagar Haveli and Daman and Diu', 'Maharashtra'],
        // Extra instructions for the research model, in plain words.
        'guidance' => 'A school does not need to be digitised already: we digitise first. If its leaders show intent (a new campus, a new leader, visible investment in quality), that matters more than its current systems. GSEB Gujarati-medium schools count only when well established and running both mediums.',

        // What we sell, in the words the buyer uses. The model names the part a lead fits.
        'parts' => [
            'school_erp' => 'a school management system (admissions, fees, attendance, results, transport)',
            'lms_pal' => 'a learning platform with ready lessons and assessments for teachers',
            'teacher_competency' => 'teacher and staff development with capability tracking',
            'career_intelligence' => 'a career programme from Grade 6 with assessments and guidance',
            'intelligence_layer' => 'one decision view across fees, teaching, staff and careers',
        ],

        // Hard rules. Each is checked only when the fact is known, so a school whose student count is unknown is
        // "unscored", never rejected on a guess. `attribute` reads a stored fact (or a criterion's derived value);
        // `field` reads a top-level account field. `{value}` in a label becomes the fact's value. `prompt` is how the
        // rule reads to the research model.
        'must_have' => [
            ['attribute' => 'management', 'op' => 'in', 'value' => ['private', 'private trust', 'trust', 'society', 'section 8 company', 'section 8'], 'label' => 'not a private school (trust, society or Section 8 company)', 'prompt' => 'government or aided management'],
            ['attribute' => 'students', 'op' => '>=', 'value' => 2000, 'label' => 'under 2,000 students', 'prompt' => 'fewer than 2,000 students (one campus or in total)'],
            ['attribute' => 'grades_up_to', 'op' => '>=', 'value' => 10, 'label' => 'classes only up to Grade {value} (no Grade 10 or 12)', 'prompt' => 'classes only up to a grade below 10'],
            ['attribute' => 'fee_inr', 'op' => 'between', 'value' => [25000, 100000], 'label' => 'annual fee outside ₹25,000–₹1,00,000', 'prompt' => 'annual fees outside ₹25,000–₹1,00,000 per student'],
            ['field' => 'state', 'op' => 'in_regions', 'label' => 'outside the target regions', 'prompt' => 'a location outside the target regions'],
        ],

        // Any one removes an organisation, whatever else is known. PHRASES, matched as whole words/phrases
        // (case-insensitive) against the organisation name and the first fact reported.
        'disqualifiers' => [
            ['label' => 'pre-primary or play school only', 'phrases' => ['play school', 'playgroup', 'pre-primary', 'pre primary', 'nursery', 'kindergarten', 'montessori', 'anganwadi']],
            ['label' => 'coaching or tuition centre', 'phrases' => ['coaching', 'tuition', 'tutorial', 'test prep', 'iit-jee', 'iit jee', 'neet']],
            ['label' => 'college or university', 'phrases' => ['college', 'university', 'polytechnic', 'institute of technology', 'iim', 'iit', 'nit']],
            ['label' => 'edtech, ERP or LMS vendor', 'phrases' => ['edtech', 'ed-tech', 'erp vendor', 'erp provider', 'erp company', 'lms vendor', 'lms provider', 'lms company', 'software company', 'software solutions', 'software pvt', 'technologies pvt', 'technologies ltd', 'saas']],
        ],

        // Disqualifiers nobody can read off a web page: a person sets them on the account (attributes.flags).
        'manual_flags' => [
            'wants_cheapest_erp_only' => 'wants only the cheapest ERP',
            'no_sponsor' => 'no one at the top willing to sponsor the change',
            'heavy_custom_development' => 'requires heavy custom development',
            'parent_platform_mandated' => 'parent organisation mandates its own global platform',
        ],

        // Not a rejection: keep on WATCH while the rule holds. A school that signed a new ERP or LMS in the last 12 months
        // switches only at renewal. `attribute` is a date (YYYY-MM-DD) a person records on the account.
        'watch_rules' => [
            ['attribute' => 'erp_signed_on', 'op' => 'within_days', 'value' => 365, 'label' => 'signed a new ERP or LMS in the last 12 months: watch until renewal'],
        ],

        // Who sells what we sell. Such an organisation is NEVER a buyer: it is a competitor, or at most a partner, and lands in the
        // context section whatever the model said. TWO lists, because the words differ:
        //   vendor_phrases       in the organisation's NAME or SECTOR ("RSK Business Solutions", "software"). Strong words only.
        //   vendor_news_phrases  in the first FACT reported, wording only a seller's news has ("raises ... valuation", "its AI
        //                        platform"). Never plain words like "ERP": a school hiring an ERP administrator is exactly our lead.
        'vendor_phrases' => [
            'edtech', 'ed-tech', 'erp', 'lms', 'saas', 'software', 'it services', 'it consulting', 'consultancy', 'consulting',
            'technologies', 'technology solutions', 'business solutions', 'analytics', 'ai platform', 'ai startup', 'ai company',
        ],
        'vendor_news_phrases' => [
            'valuation', 'funding round', 'series a', 'series b', 'series c', 'distribution partnership', 'channel partner',
            'enterprise ai platform', 'its ai platform', 'its ai intelligence platform', 'intelligence platform', 'analytics solutions',
            'showcase practical ai',
        ],

        // Name phrases that make an organisation look like a better fit when nothing else is known, and cities where
        // we can sell and support in person. Only used to decide which accounts to watch FIRST.
        'watch_first_phrases' => ['international', 'world school', 'public school', 'english medium', 'academy', 'convent', 'global', 'cambridge', 'dps', 'ryan', 'podar', 'delhi public', 'vidya vihar', 'vidyaniketan', 'niketan', 'school trust', 'group of schools'],
        'priority_cities' => ['Ahmedabad', 'Mumbai', 'Pune', 'Surat', 'Vadodara', 'Rajkot', 'Vapi', 'Nashik'],

        // Facts about an account that earn points (stored on the account). `values` maps an exact value to points;
        // `rules` maps numeric ranges. `derive` fills the fact from another account field when it is missing;
        // `value_overrides` rewrites a stored value (null = treat as unknown) so an open question never scores either way.
        'criteria' => [
            ['key' => 'students', 'label' => 'Students', 'max' => 15, 'rules' => [['range' => [4000, 99999999], 'points' => 15], ['range' => [2000, 3999], 'points' => 10]]],
            ['key' => 'grades_up_to', 'label' => 'Highest grade', 'max' => 5, 'rules' => [['range' => [12, 12], 'points' => 5], ['range' => [10, 11], 'points' => 3]],
                'derive' => ['from' => 'level', 'map' => ['senior secondary level' => 12, 'secondary level' => 10, 'upper primary level' => 8, 'primary level' => 5]]],
            ['key' => 'campuses', 'label' => 'Campuses', 'max' => 5, 'rules' => [['range' => [2, 999], 'points' => 5], ['range' => [1, 1], 'points' => 2]]],
            // CBSE, ICSE and English-medium GSEB count. GSEB Gujarati-medium counts ONLY when well established and running both mediums.
            ['key' => 'board', 'label' => 'Board', 'max' => 5, 'values' => ['CBSE' => 5, 'ICSE' => 5, 'GSEB-ENGLISH' => 5, 'GSEB-BOTH' => 5, 'GSEB-GUJARATI' => 0],
                'value_overrides' => [
                    ['when_value' => 'GSEB', 'attribute_equals' => ['medium' => 'english'], 'then' => 'GSEB-ENGLISH'],
                    ['when_value' => 'GSEB', 'attribute_equals' => ['medium' => 'both', 'established' => 'yes'], 'then' => 'GSEB-BOTH'],
                    ['when_value' => 'GSEB', 'attribute_equals' => ['medium' => 'gujarati'], 'then' => 'GSEB-GUJARATI'],
                    ['when_value' => 'GSEB', 'then' => null],   // medium unknown: never scored either way
                ]],
        ],

        // Judgement and live evidence, added when a lead is reviewed ("award points only for verified evidence").
        'evidence_points' => [
            'leadership_willingness' => 20,   // stated plans, a new leader, visible investment in quality
            'active_triggers' => 20,          // triggers in the last 90 days
            'financial_capacity' => 15,       // fee in the upper half, recent expansion, infrastructure spending
            'need_across_parts' => 15,        // pains in several bundle parts
        ],
        // Points for the two that are COUNTED rather than judged (count of triggers / of bundle parts with a pain).
        'evidence_rules' => [
            'active_triggers' => [['min' => 2, 'points' => 20], ['min' => 1, 'points' => 10]],
            'need_across_parts' => [['min' => 3, 'points' => 15], ['min' => 2, 'points' => 10], ['min' => 1, 'points' => 5]],
        ],
        'priority' => [1 => 75, 2 => 55, 3 => 40],      // P1, P2, P3 from the full 100-point score; below 40 is not pursued
        'min_known_share' => 0.5,                        // share of stored facts that must be known to count as scored

        // How we ENTER a school. A person judges it; it is never computed from a record and is separate from the score.
        'tiers' => [
            'A' => 'Transformation-ready: already uses some software, leadership is seeking change, usually with a trigger. Full bundle, phased rollout.',
            'B' => 'Willing but not digitised: capable, forward-minded, mostly on paper or Excel. Digitise first, then switch on the rest.',
            'C' => 'One problem first: one urgent need and no appetite yet for everything. Start with the part that solves it.',
        ],

        // Each trigger: where it shows up, the pain, the part it opens, how long it stays fresh (days; null = until the
        // item's own closing date).
        'triggers' => [
            'new_campus' => ['source' => 'news', 'part' => 'school_erp', 'decay' => 90, 'pain' => 'a new campus needs systems from day one', 'phrases' => ['new campus', 'new branch', 'new CBSE school', 'admissions open for new', 'adds Grade 11']],
            'system_change' => ['source' => 'job', 'part' => 'school_erp', 'decay' => 60, 'pain' => 'they are building or replacing their school systems', 'phrases' => ['ERP administrator', 'data migration', 'school management software']],
            'leadership' => ['source' => 'leadership', 'part' => 'intelligence_layer', 'decay' => 270, 'pain' => 'new leaders review systems and want early wins', 'phrases' => ['new principal', 'appointed director', 'new CEO', 'new trustee']],
            'academic' => ['source' => 'job', 'part' => 'lms_pal', 'decay' => 60, 'pain' => 'teaching quality and results are under pressure', 'phrases' => ['academic coordinator', 'teacher trainer', 'smart classroom', 'board results']],
            'staff_development' => ['source' => 'job', 'part' => 'teacher_competency', 'decay' => 60, 'pain' => 'they need to develop and measure their teachers', 'phrases' => ['head of professional development', 'bulk teacher hiring', 'teacher training programme']],
            'career' => ['source' => 'job', 'part' => 'career_intelligence', 'decay' => 60, 'pain' => 'parents ask what after Grade 12 and there is no career programme', 'phrases' => ['career counsellor', 'Grade 11 streams', 'career fair']],
            'tender' => ['source' => 'tender', 'part' => 'school_erp', 'decay' => null, 'pain' => 'a formal purchase is open now', 'phrases' => ['school management system', 'learning management system', 'digital classroom']],
            'vendor_search' => ['source' => 'social', 'part' => 'school_erp', 'decay' => 60, 'pain' => 'they are asking for, or comparing, a school ERP or learning system', 'phrases' => ['looking for school ERP', 'which ERP', 'switching ERP', 'LMS recommendation']],
            'pain_point' => ['source' => 'news', 'part' => 'school_erp', 'decay' => 60, 'pain' => 'parents and staff are running into problems with the current system', 'phrases' => ['fee portal down', 'result delay', 'parents complain app']],
        ],

        // Searches describe the BUYER's situation, in the words on the page where it is written down (never "AI solutions"
        // or "enterprise AI": those are our words and find other sellers). Five kinds of place, so one day's run mixes kinds
        // of evidence: LinkedIn (a school asking for a system, hiring for one, opening a campus, a new principal), job boards,
        // tender portals, news, and affiliation / regulation pages. LinkedIn and the job boards are reached through the search
        // API's own index of public pages (`domains`); nothing here logs in to, or scrapes, any site.
        // 5 catalogue queries + 2 named schools per run (+ up to 2 follow-ups), so the whole set is covered in about a week.
        'queries' => [
            // Tenders: the clearest signal there is. Official portals only.
            ['id' => 'k12-tender-sms', 'source' => 'tender', 'topic' => 'general', 'days' => 90, 'domains' => $tenderSites, 'q' => 'tender "school management system" OR "school ERP" OR "learning management system"'],
            ['id' => 'k12-tender-class', 'source' => 'tender', 'topic' => 'general', 'days' => 90, 'domains' => $tenderSites, 'q' => 'EOI OR tender "digital classroom" OR "smart class" OR "ICT lab" school Gujarat OR Maharashtra'],

            // LinkedIn: a school asking for a system, comparing vendors, or unhappy with one.
            ['id' => 'k12-li-ask-erp', 'source' => 'social', 'topic' => 'general', 'days' => 45, 'domains' => ['linkedin.com'], 'q' => 'school principal OR director OR trustee "looking for" OR "recommend" OR "evaluating" OR "switching" "school ERP" OR "school management software" India'],
            ['id' => 'k12-li-ask-lms', 'source' => 'social', 'topic' => 'general', 'days' => 45, 'domains' => ['linkedin.com'], 'q' => 'school "learning management system" OR "LMS" OR "teacher training platform" implementing OR looking OR launching Gujarat OR Maharashtra'],
            ['id' => 'k12-forum-ask', 'source' => 'social', 'topic' => 'general', 'days' => 60, 'domains' => ['quora.com', 'reddit.com'], 'q' => 'best school ERP OR "school management software" India principal recommend OR alternative OR switching "CBSE"'],

            // Job posts: a school hiring to run, migrate or lead its systems.
            ['id' => 'k12-li-job-erp', 'source' => 'job', 'topic' => 'general', 'days' => 45, 'domains' => ['linkedin.com'], 'q' => 'school hiring "ERP" OR "MIS" OR "school management software" OR "data migration" administrator OR coordinator OR executive Gujarat OR Maharashtra'],
            ['id' => 'k12-board-job-erp', 'source' => 'job', 'topic' => 'general', 'days' => 45, 'domains' => ['naukri.com', 'indeed.com', 'shine.com', 'foundit.in'], 'q' => 'school "ERP" OR "MIS" coordinator OR administrator OR executive Gujarat OR Maharashtra'],
            ['id' => 'k12-li-job-academic', 'source' => 'job', 'topic' => 'general', 'days' => 45, 'domains' => ['linkedin.com'], 'q' => 'school hiring "academic coordinator" OR "teacher trainer" OR "head of academics" OR "career counsellor" Gujarat OR Maharashtra'],
            ['id' => 'k12-job-erp', 'source' => 'job', 'topic' => 'general', 'days' => 60, 'q' => 'school hiring "ERP administrator" OR "ERP coordinator" OR "school management software" Gujarat OR Maharashtra'],

            // Expansion: a new campus needs systems from day one.
            ['id' => 'k12-li-campus', 'source' => 'social', 'topic' => 'general', 'days' => 45, 'domains' => ['linkedin.com'], 'q' => 'school "new campus" OR "new branch" OR "now open" OR "admissions open" Gujarat OR Maharashtra 2027'],
            ['id' => 'k12-news-campus-gj', 'source' => 'news', 'topic' => 'news', 'days' => 30, 'q' => 'school group "new campus" OR "new branch" admissions 2027 Gujarat'],
            ['id' => 'k12-news-campus-mh', 'source' => 'news', 'topic' => 'news', 'days' => 30, 'q' => 'school trust "new campus" OR "new CBSE school" Pune OR Nashik OR Mumbai 2027'],
            ['id' => 'k12-affiliation', 'source' => 'other', 'topic' => 'general', 'days' => 90, 'domains' => ['saras.cbse.gov.in'], 'q' => 'CBSE affiliation new school Gujarat OR Maharashtra OR Daman'],

            // Leadership: new leaders review systems and want early wins.
            ['id' => 'k12-li-leader', 'source' => 'leadership', 'topic' => 'general', 'days' => 60, 'domains' => ['linkedin.com'], 'q' => '"joined as principal" OR "appointed principal" OR "new director" OR "excited to join" CBSE OR ICSE school Gujarat OR Maharashtra'],
            ['id' => 'k12-news-leader-gj', 'source' => 'leadership', 'topic' => 'news', 'days' => 30, 'q' => '"appointed principal" OR "new principal" school Ahmedabad OR Surat OR Vadodara'],
            ['id' => 'k12-news-leader-mh', 'source' => 'leadership', 'topic' => 'news', 'days' => 30, 'q' => '"appointed director" OR "new CEO" CBSE OR ICSE school group Mumbai OR Pune'],

            // CONCEPT searches: the need described without naming a purchase. A school that says these things may not use the word ERP.
            ['id' => 'k12-sem-multicampus', 'source' => 'concept', 'topic' => 'general', 'days' => 60, 'q' => 'India school group multiple campuses one fee collection OR "centralised" admissions OR "single dashboard" principals Gujarat OR Maharashtra'],
            ['id' => 'k12-sem-excel', 'source' => 'concept', 'topic' => 'general', 'days' => 60, 'q' => 'school moving from Excel OR Tally OR paper registers to software fees attendance results India'],
            ['id' => 'k12-sem-parent-comm', 'source' => 'concept', 'topic' => 'news', 'days' => 45, 'q' => 'school launches parent app OR "parent portal" OR "digital report card" OR "online fee payment" Gujarat OR Maharashtra'],
            ['id' => 'k12-sem-teacher-dev', 'source' => 'concept', 'topic' => 'general', 'days' => 60, 'q' => 'school group teacher training programme OR "teacher upskilling" OR "teacher appraisal" CBSE Gujarat OR Maharashtra'],
            ['id' => 'k12-sem-career', 'source' => 'concept', 'topic' => 'general', 'days' => 60, 'q' => 'India CBSE school launches career guidance OR "career counselling" programme OR "aptitude assessment" students Class 9 OR "Grade 9"'],
            ['id' => 'k12-sem-expansion', 'source' => 'concept', 'topic' => 'news', 'days' => 45, 'q' => 'school trust OR group plans to open campuses OR "expansion" OR "franchise" CBSE OR ICSE Gujarat OR Maharashtra'],
            ['id' => 'k12-sem-competitor', 'source' => 'concept', 'topic' => 'news', 'days' => 45, 'q' => 'school ERP OR "school management software" launches OR partners OR "signs" schools Gujarat OR Maharashtra Teachmint OR Entab OR Fedena'],

            // Problems with the current system, said in public.
            ['id' => 'k12-pain-system', 'source' => 'news', 'topic' => 'news', 'days' => 30, 'q' => 'parents complain school app OR "fee portal" OR "online fee" OR "result" glitch OR delay OR down Gujarat OR Maharashtra'],
            ['id' => 'k12-news-fee-rules', 'source' => 'regulation', 'topic' => 'news', 'days' => 30, 'q' => 'fee regulatory committee private schools Gujarat OR Maharashtra order 2026'],
        ],

        // How many catalogue queries and how many named-account searches one run uses (together within max_queries).
        'per_run' => ['catalogue' => 5, 'accounts' => 2],
        // One search per watched account: its name in quotes, so the result is about THAT account.
        // PLAIN name + city. Live testing showed that quoting the name and adding a list of event words returned one irrelevant page for
        // a school with plenty of web presence, while the plain name found its own admissions, Facebook, YouTube and LinkedIn pages.
        // What the school is doing is read from those pages by the model, not forced by the search words.
        'account_query' => '{name} {city}',
        'account_days' => 120,
        'account_recheck_days' => 7,

        // Days a lead stays worth acting on, by the trigger type the qualification gate stores; null = until the closing date.
        'decay_by_trigger_type' => ['expansion' => 90, 'hiring' => 60, 'leadership' => 270, 'vendor_search' => 60, 'pain_point' => 60, 'tender' => null, 'rfp' => null, 'eoi' => null, 'rfq' => null, 'default' => 90],

        'buying_window_months' => [12, 1, 2, 3],

        // Who is approached for what. The model may suggest these ROLES (never invent a named person).
        'buying_centre' => [
            'trustee or chairperson' => 'signs the whole bundle; cares about control, fee collection and reputation',
            'group CEO or director' => 'champions the one-view decision layer across campuses',
            'principal' => 'recommends the school system and lessons; wants less admin and calmer parents',
            'academic director or coordinator' => 'owns teaching quality, results and teacher growth',
            'HR or administrator' => 'owns staff records and development hours',
            'accounts head' => 'owns fee collection and reconciliation',
            'career counsellor' => 'owns career guidance and parent trust',
        ],
    ],
];
