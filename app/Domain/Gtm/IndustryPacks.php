<?php

namespace App\Domain\Gtm;

/**
 * Industry packs: sales and marketing guidance per industry, stored as gtm_playbooks of kind
 * `industry_pack` (platform defaults; an organisation customises by copying, like any playbook).
 *
 * WHAT A PACK IS - AND IS NOT
 *
 * A pack is a set of HYPOTHESES to test with a buyer: pains an organisation in that industry often has,
 * the questions that confirm or rule them out, angles for a message, objections to expect, and the
 * evidence worth collecting. It contains no statistics, no named companies and no claims about any real
 * account. Its instruction says so, and an agent that receives a pack must treat every item as "to be
 * verified", never as a fact about the specific company in front of it.
 *
 * The industries are the ones in the product profile's own target list; a tenant adds or edits packs
 * through Playbooks like any other content.
 */
final class IndustryPacks
{
    private const RULES = ' This pack is a set of HYPOTHESES, not facts. Use it only to decide what to ask and which angle to try. '
        .'Never state any item as true of a specific company unless that company\'s own recorded signal or notes support it, and cite that source. '
        .'No figure, customer name or result may come from this pack.';

    /** @return array<int, array<string, mixed>> */
    public static function all(): array
    {
        return [
            self::pack('education', 'Education', 'Schools, school groups, colleges and education networks.', [
                'pains' => ['Administrative work (attendance, fees, reports) consuming teacher and office time', 'Learning, assessment and administration data held in separate systems', 'Pressure to show learning outcomes to boards, parents or funders', 'Uneven adoption of digital tools across staff and campuses', 'Procurement that runs through committees or tenders'],
                'questions' => ['Which routine tasks take staff the most time each week, and who owns them?', 'Where does learning or attendance data live today, and who combines it?', 'What outcome reporting is required, to whom, and how often?', 'Who must approve a new system, and what is the buying process and timeline?', 'What was tried before, and why did it stall?'],
                'angles' => ['Time returned to teachers', 'One view of learner and operational data', 'Evidence for boards and funders'],
                'objections' => [['objection' => 'Staff will not adopt another tool', 'approach' => 'Ask what drove past adoption or failure; propose a small pilot with one team'], ['objection' => 'No budget this year', 'approach' => 'Ask when the next budget or tender cycle opens and what must be shown to qualify'], ['objection' => 'We already have an ERP/LMS', 'approach' => 'Ask what it does not do today and where staff work around it']],
                'evidence' => ['Named decision maker and approval route', 'Current systems and their renewal dates', 'Tender or funding window', 'The specific recorded signal that prompted outreach'],
            ]),
            self::pack('technology', 'Information technology, software and enterprise technology', 'Software vendors, IT services and technology-led enterprises.', [
                'pains' => ['Knowledge and customer data scattered across tools and teams', 'Manual analysis and reporting that slows decisions', 'Difficulty turning signals (tenders, hiring, funding) into timely action', 'Integration effort between existing platforms', 'Security and data-residency review slowing procurement'],
                'questions' => ['Which decisions are slowed by gathering information from several systems?', 'What do you already use for this, and where does it fall short?', 'Who owns the budget and who runs the technical evaluation?', 'What security or compliance review must a vendor pass, and how long does it take?', 'What event is driving this priority now?'],
                'angles' => ['Faster decisions from existing data', 'Fits alongside current platforms', 'Evidence-backed recommendations'],
                'objections' => [['objection' => 'We will build it ourselves', 'approach' => 'Ask what the build needs in people and time, and what else that team would not do'], ['objection' => 'Security review will take too long', 'approach' => 'Ask for the checklist early and what has unblocked past vendors'], ['objection' => 'Too many tools already', 'approach' => 'Ask which tools it could replace or connect']],
                'evidence' => ['Technical owner and economic buyer', 'Integration points named by the buyer', 'Security requirements stated in writing', 'The recorded trigger event'],
            ]),
            self::pack('healthcare', 'Healthcare', 'Hospitals, clinics, diagnostic and healthcare service organisations.', [
                'pains' => ['Documentation and administrative load on clinical staff', 'Information spread across clinical, billing and operational systems', 'Compliance and audit requirements around patient data', 'Capacity and scheduling pressure', 'Long, risk-averse procurement'],
                'questions' => ['Where does administrative work interrupt clinical work most?', 'Which systems hold the data this would need, and who controls access?', 'What compliance or privacy requirements apply, and who signs off?', 'Who sponsors a change like this, and how are pilots approved?', 'What would count as success in the first 90 days?'],
                'angles' => ['Less time on paperwork', 'Auditable handling of information', 'Start with a contained pilot'],
                'objections' => [['objection' => 'Patient data cannot leave our environment', 'approach' => 'Ask for the exact constraint and whether a pilot can use non-patient data'], ['objection' => 'Clinicians have no time for training', 'approach' => 'Ask which workflow to start with that adds no steps'], ['objection' => 'We need regulatory approval first', 'approach' => 'Ask what approval and timeline apply and who owns it']],
                'evidence' => ['Privacy/compliance owner identified', 'Named clinical or operational sponsor', 'Pilot scope agreed in writing', 'The recorded trigger event'],
            ]),
            self::pack('government', 'Government and public sector', 'Government departments, agencies and public bodies.', [
                'pains' => ['Procurement governed by tenders and formal evaluation criteria', 'Fragmented records across departments', 'Need to demonstrate outcomes and value for public money', 'Constraints on where data may be hosted', 'Projects delivered through multi-party programmes'],
                'questions' => ['Is there a tender, panel or framework this would be bought through, and what are its dates?', 'What evaluation criteria and compliance documents are required?', 'Which department owns the programme and who is the accountable officer?', 'What hosting or data-residency rules apply?', 'Who else is in the delivery chain, and where would we fit?'],
                'angles' => ['Meets stated evaluation criteria', 'Clear audit trail', 'Fits an existing programme'],
                'objections' => [['objection' => 'We can only buy through the tender', 'approach' => 'Ask for the tender or framework reference and timeline; do not pursue outside it'], ['objection' => 'Local presence is required', 'approach' => 'Ask what the requirement is and whether a partner meets it'], ['objection' => 'Budget is already allocated', 'approach' => 'Ask what the allocation covers and when the next allocation is decided']],
                'evidence' => ['Tender/programme reference and dates', 'Eligibility and compliance requirements', 'Accountable officer', 'The recorded source document'],
            ]),
            self::pack('manufacturing', 'Manufacturing', 'Manufacturers and industrial operations.', [
                'pains' => ['Operational data locked in plant systems and spreadsheets', 'Slow reporting between shop floor and management', 'Quality, compliance and supplier documentation burden', 'Difficulty spotting issues early across sites', 'Change that must not interrupt production'],
                'questions' => ['Which reports does management wait longest for, and why?', 'Which systems hold production, quality and supplier data today?', 'What happens when the data is wrong or late?', 'Who decides on systems that touch the plant, and what trial is acceptable?', 'What downtime window, if any, can a rollout use?'],
                'angles' => ['Earlier visibility of problems', 'Less manual reporting', 'Rollout without disrupting production'],
                'objections' => [['objection' => 'Production cannot be disturbed', 'approach' => 'Ask which site or line is least critical for a read-only pilot'], ['objection' => 'Our data is too messy', 'approach' => 'Ask which one dataset matters most and start there'], ['objection' => 'IT and operations disagree', 'approach' => 'Ask who has final say and what each side needs to see']],
                'evidence' => ['Operations and IT owners', 'Named system(s) holding the data', 'Pilot site and success measure', 'The recorded trigger event'],
            ]),
            self::pack('professional-services', 'Professional and business services', 'Consultancies, agencies and service providers.', [
                'pains' => ['Knowledge held by individuals instead of the organisation', 'Time spent assembling client reports and proposals', 'Utilisation and delivery visibility across engagements', 'Winning work depends on spotting opportunities early', 'Growth constrained by manual processes'],
                'questions' => ['How is knowledge from past engagements found and reused today?', 'Which recurring documents take the most time to produce?', 'How do you currently find and qualify new opportunities?', 'Who decides on internal tooling, and what has been tried?', 'What would freeing up a day a week per person allow?'],
                'angles' => ['Reuse what the firm already knows', 'Faster proposals and reports', 'Earlier sight of opportunities'],
                'objections' => [['objection' => 'Billable work comes first', 'approach' => 'Ask what a pilot must not cost in billable time and size it to that'], ['objection' => 'Partners decide by consensus', 'approach' => 'Ask who the sponsor is and what the partners need to see'], ['objection' => 'Confidentiality of client data', 'approach' => 'Ask for the exact policy and how data would be handled']],
                'evidence' => ['Sponsoring partner/director', 'Current tools and process', 'Confidentiality requirements in writing', 'The recorded trigger event'],
            ]),
        ];
    }

    /**
     * @param  array{pains: list<string>, questions: list<string>, angles: list<string>, objections: list<array{objection: string, approach: string}>, evidence: list<string>}  $d
     * @return array<string, mixed>
     */
    private static function pack(string $slug, string $industry, string $description, array $d): array
    {
        return [
            'kind' => 'industry_pack', 'role' => 'all', 'stage' => null, 'slug' => 'pack-'.$slug,
            'title' => "Industry pack: {$industry}", 'description' => $description,
            'body' => "Guidance for selling into: {$industry}.".self::RULES.' Use "pains_to_validate" and "discovery_questions" to prepare questions, and "messaging_angles" to choose an angle that the account\'s own signal supports.',
            'inputs' => ['account', 'signals', 'campaign'],
            'definition' => ['industry' => $industry, 'pains_to_validate' => $d['pains'], 'discovery_questions' => $d['questions'], 'messaging_angles' => $d['angles'], 'common_objections' => $d['objections'], 'evidence_to_collect' => $d['evidence']],
        ];
    }
}
