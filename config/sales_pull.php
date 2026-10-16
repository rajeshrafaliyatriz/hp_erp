<?php

/*
|--------------------------------------------------------------------------
| SALES PULL: why might an organisation VALUE ScholarClone?
|--------------------------------------------------------------------------
| 36 human-motivation categories. They explain why a need matters to the people at a school; they are NEVER evidence that the
| school is buying. Buying intent is assessed separately (see `buying_intent` below and App\Domain\Signals\Scholar\BuyingIntent).
|
| The model is shown the definition and the examples so it can recognise a motivation from paraphrase, context and implication
| rather than from the label. It may select none, one or several, and each needs a rationale tied to the source.
| `pressure_risk` marks categories that can be abused as manipulation (fear, shame, guilt, status envy, tribal framing): they
| may describe why a need exists, and must never become the lever in a recommended action.
*/

return [
    'categories' => [
        'belonging' => ['label' => 'Belonging', 'definition' => 'Being part of a group or community.', 'examples' => 'a school joining a network or group of schools; parents wanting to feel part of the school community'],
        'identity' => ['label' => 'Identity', 'definition' => 'Being seen as a certain kind of school.', 'examples' => 'positioning as a tech-forward or premium school; a new brand for a school group'],
        'love_connection' => ['label' => 'Love and connection', 'definition' => 'Closer relationships between people.', 'examples' => 'closer parent-teacher communication; teachers knowing each student better'],
        'safety_security' => ['label' => 'Safety and security', 'definition' => 'Protection from risk and loss.', 'examples' => 'safe student records and fee data; compliance with board or data rules; continuity if staff leave'],
        'freedom_autonomy' => ['label' => 'Freedom and autonomy', 'definition' => 'Independence and choice.', 'examples' => 'not being locked into a vendor; leaders deciding without waiting for the trust office'],
        'power_control' => ['label' => 'Power and control', 'definition' => 'Oversight and command of operations.', 'examples' => 'one view across campuses; a trustee wanting control of fee collection'],
        'convenience_time' => ['label' => 'Convenience and time', 'definition' => 'Saving effort and time.', 'examples' => 'less paperwork in admissions and attendance; faster result processing; fewer manual reconciliations'],
        'pleasure_comfort' => ['label' => 'Pleasure and comfort', 'definition' => 'A calmer, more pleasant working day.', 'examples' => 'calmer front office in admission season; teachers who no longer dread data entry'],
        'pain_relief' => ['label' => 'Pain relief', 'definition' => 'Ending a present, felt problem.', 'examples' => 'parent complaints about the fee portal; staff overwhelmed by duplicate spreadsheets; a failing old system'],
        'wealth_abundance' => ['label' => 'Wealth and abundance', 'definition' => 'More revenue or lower cost.', 'examples' => 'better fee recovery; more admissions from a better parent experience; lower admin cost per student'],
        'curiosity_novelty' => ['label' => 'Curiosity and novelty', 'definition' => 'Interest in what is new.', 'examples' => 'a principal exploring new learning tools; piloting AI-assisted lessons'],
        'growth_mastery' => ['label' => 'Growth and mastery', 'definition' => 'Getting better at a craft.', 'examples' => 'teacher development and measured skills; a school raising its academic standard'],
        'purpose_meaning' => ['label' => 'Purpose and meaning', 'definition' => 'Doing work that matters.', 'examples' => 'a mission to improve outcomes for every child; career guidance that changes students\' lives'],
        'legacy_impact' => ['label' => 'Legacy and impact', 'definition' => 'Leaving something lasting.', 'examples' => 'a founder building a school group meant to last; a trust scaling a model campus'],
        'recognition_validation' => ['label' => 'Recognition and validation', 'definition' => 'Being acknowledged.', 'examples' => 'accreditation or awards; wanting board-result recognition; parents\' reviews'],
        'pride_achievement' => ['label' => 'Pride and achievement', 'definition' => 'Satisfaction in accomplishment.', 'examples' => 'results and placements the school can show; a milestone campus opening'],
        'envy_competition' => ['label' => 'Envy and competition', 'definition' => 'Keeping up with or beating rivals.', 'examples' => 'a nearby school launched a parent app; competing for admissions in the same locality', 'pressure_risk' => true],
        'guilt_redemption' => ['label' => 'Guilt and redemption', 'definition' => 'Making amends for a past failure.', 'examples' => 'a school putting right a past results or fee-handling failure', 'pressure_risk' => true],
        'shame_acceptance' => ['label' => 'Shame and acceptance', 'definition' => 'Avoiding embarrassment, wanting to be accepted.', 'examples' => 'public criticism of a school\'s systems; fear of looking behind other schools', 'pressure_risk' => true],
        'trust_authority' => ['label' => 'Trust and authority', 'definition' => 'Relying on credible, proven sources.', 'examples' => 'wanting a vendor with references in CBSE schools; a board or association recommendation'],
        'reciprocity_obligation' => ['label' => 'Reciprocity and obligation', 'definition' => 'Returning a favour or fulfilling a duty.', 'examples' => 'a duty to parents who pay fees; a trust\'s obligation to staff and students'],
        'scarcity_urgency' => ['label' => 'Scarcity and urgency', 'definition' => 'A closing window or limited time.', 'examples' => 'admissions season starting; a tender closing date; a board deadline', 'pressure_risk' => true],
        'social_proof' => ['label' => 'Social proof', 'definition' => 'Doing what peers do.', 'examples' => 'other schools in the trust already use it; peer schools publicly adopting a system'],
        'nostalgia_tradition' => ['label' => 'Nostalgia and tradition', 'definition' => 'Valuing what has long been done.', 'examples' => 'a heritage school modernising without losing its character; continuity of the founder\'s values'],
        'rebellion_nonconformity' => ['label' => 'Rebellion and nonconformity', 'definition' => 'Breaking from the norm.', 'examples' => 'a school rejecting rote learning; leaving a legacy vendor on principle'],
        'justice_fairness' => ['label' => 'Justice and fairness', 'definition' => 'Equitable treatment.', 'examples' => 'transparent fee and grading; fair teacher appraisal; equal access across campuses'],
        'health_vitality' => ['label' => 'Health and vitality', 'definition' => 'Wellbeing and energy.', 'examples' => 'staff burnout from admin load; student wellbeing tracking'],
        'simplicity_clarity' => ['label' => 'Simplicity and clarity', 'definition' => 'Fewer tools and a clear picture.', 'examples' => 'replacing five disconnected tools with one; one dashboard for fees, teaching and staff'],
        'transcendence_awe' => ['label' => 'Transcendence and awe', 'definition' => 'A sense of something larger.', 'examples' => 'a visionary plan to transform education across a region'],
        'mimetic_desire' => ['label' => 'Mimetic desire', 'definition' => 'Wanting what admired others want.', 'examples' => 'copying a leading school group\'s system choice; adopting what a respected principal endorsed'],
        'tribal_enemy' => ['label' => 'Tribal enemy', 'definition' => 'Unity against a common opponent.', 'examples' => 'a school group positioning against a dominant rival chain', 'pressure_risk' => true],
        'ritual_habit' => ['label' => 'Ritual and habit', 'definition' => 'Routines people rely on.', 'examples' => 'daily attendance, monthly fee runs and term results that should just work; a system that fits existing routines'],
        'mystery_secrecy' => ['label' => 'Mystery and secrecy', 'definition' => 'The pull of the hidden or exclusive.', 'examples' => 'confidential board or investor data kept private; an exclusive early-access pilot'],
        'certainty_closure' => ['label' => 'Certainty and closure', 'definition' => 'Resolving uncertainty and finishing things.', 'examples' => 'knowing fee dues exactly; ending a long unfinished migration; clear audit trails'],
        'moral_superiority' => ['label' => 'Moral superiority', 'definition' => 'Being on the right side.', 'examples' => 'a school proud of ethical data handling or inclusive education', 'pressure_risk' => true],
        'salvation_afterlife' => ['label' => 'Salvation and afterlife', 'definition' => 'Ultimate rescue or lasting reward.', 'examples' => 'rarely relevant to a purchase; use only when the source itself frames a mission or faith-based legacy'],
    ],

    // Most motivations kept per signal: a signal is not a personality test.
    'max_per_signal' => 3,

    /*
    | BUYING INTENT: is there evidence the organisation is moving toward acquiring or implementing a solution?
    | The model reports INDICATORS (each with a verbatim quote); the level is then DECIDED IN CODE from the indicators whose quote
    | was found in a cited source. Expansion, hiring in general, "digital transformation" talk and a possible problem are not
    | buying intent by themselves.
    */
    'buying_intent' => [
        'levels' => [
            'explicit' => 'Direct evidence of an active purchasing or vendor-selection process.',
            'strong' => 'Several relevant indicators of active evaluation or implementation.',
            'moderate' => 'A specific need or project is documented, but purchasing activity is unconfirmed.',
            'weak' => 'Only an indirect indicator of possible demand.',
            'unconfirmed' => 'Insufficient evidence to determine intent.',
        ],
        // indicator => tier. `purchase` indicators alone prove an active process; `project` indicators document a real project;
        // `context` indicators are only circumstantial.
        'indicators' => [
            'open_tender' => ['tier' => 'purchase', 'meaning' => 'an open tender, RFP, RFQ or EOI for school software'],
            'vendor_selection' => ['tier' => 'purchase', 'meaning' => 'a public request to recommend, shortlist or compare vendors'],
            'demo_or_proposal_request' => ['tier' => 'purchase', 'meaning' => 'a request for demonstrations or proposals'],
            'procurement_notice' => ['tier' => 'purchase', 'meaning' => 'a procurement or empanelment announcement for relevant technology'],
            'implementation_project' => ['tier' => 'project', 'meaning' => 'a stated project to implement or replace a school system'],
            'system_role_hiring' => ['tier' => 'project', 'meaning' => 'hiring for a role that runs or migrates a school system'],
            'partner_search' => ['tier' => 'project', 'meaning' => 'a search for a technology or implementation partner'],
            'stated_problem' => ['tier' => 'project', 'meaning' => 'the organisation itself states a problem with its current system'],
            'expansion' => ['tier' => 'context', 'meaning' => 'a new campus, branch or grades'],
            'leadership_change' => ['tier' => 'context', 'meaning' => 'new principal, director or trustee'],
            'academic_hiring' => ['tier' => 'context', 'meaning' => 'hiring academic coordinators, trainers or counsellors'],
        ],
    ],

    // Opportunity qualification. Every number here is a starting point to calibrate on real outcomes, not a probability.
    'qualification' => [
        // A dated source older than this many days is stale, whatever else it says. A tender is judged by its closing date instead.
        'max_age_days' => (int) env('SIGNALS_MAX_SOURCE_AGE_DAYS', 120),
        // Lowest buying-intent level a signal may have to reach the feed at all. Below it the candidate is rejected with a reason.
        // `unconfirmed` keeps every sourced candidate (it then sits in WATCH, flagged "needs verification").
        'min_intent_for_feed' => env('SIGNALS_MIN_INTENT_FOR_FEED', 'unconfirmed'),
        // Lowest intent that can be HOT (approach this week).
        'min_intent_for_hot' => env('SIGNALS_MIN_INTENT_FOR_HOT', 'strong'),
        // An undated source has no event to age from. It stays in the feed (on WATCH) for this many days after we FOUND it, then leaves
        // unless a later run dates it. Never longer than the trigger's own decay.
        'undated_keep_days' => (int) env('SIGNALS_UNDATED_KEEP_DAYS', 30),
    ],
];
