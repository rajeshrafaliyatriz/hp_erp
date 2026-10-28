<?php

namespace App\Domain\AI\Lifecycle;

/**
 * The twelve stages of a chat turn, in the order they run.
 *
 * The keys and their order are the same vocabulary LMS K-12's lifecycle uses, so a client
 * that renders one product's trace renders the other's. What each stage DOES is G2G's own:
 * its modules, its data sources, its approval ledger.
 */
enum StageKey: string
{
    case Conversation = 'conversation';
    case GenerativeAi = 'generative_ai';
    case Agent = 'agent';
    case Planning = 'planning';
    case McpToolSelection = 'mcp_tool_selection';
    case LaravelMcp = 'laravel_mcp';
    case RealData = 'real_data';
    case Evidence = 'evidence';
    case Reasoning = 'reasoning';
    case Recommendation = 'recommendation';
    case HumanApproval = 'human_approval';
    case Action = 'action';

    public function label(): string
    {
        return match ($this) {
            self::Conversation => 'Conversation',
            self::GenerativeAi => 'Generative AI',
            self::Agent => 'Agent',
            self::Planning => 'Planning',
            self::McpToolSelection => 'Tool selection',
            self::LaravelMcp => 'Data tools',
            self::RealData => 'Real data',
            self::Evidence => 'Evidence',
            self::Reasoning => 'Reasoning',
            self::Recommendation => 'Recommendation',
            self::HumanApproval => 'Human approval',
            self::Action => 'Action',
        };
    }
}
