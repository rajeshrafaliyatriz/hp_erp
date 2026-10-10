<?php

namespace App\Domain\Gtm;

/**
 * Which playbook an agent follows for one organisation: the organisation's own active copy
 * of the slug if it has one, otherwise the platform default. An archived or draft copy is
 * ignored, so archiving a customised playbook falls back to the default rather than to nothing.
 */
final class PlaybookResolver
{
    public function resolve(int $tenantId, string $slug): GtmPlaybook
    {
        $own = GtmPlaybook::where('sub_institute_id', $tenantId)->where('slug', $slug)->where('status', 'active')->first();
        $playbook = $own ?? GtmPlaybook::whereNull('sub_institute_id')->where('slug', $slug)->where('status', 'active')->first();

        if (! $playbook) {
            throw new \DomainException("The playbook '{$slug}' is not available. Run the GTM playbook migration or restore it under GTM → Playbooks.");
        }

        return $playbook;
    }

    /** The instruction an agent sends: the playbook body plus the exact JSON shape required. */
    public function systemPrompt(GtmPlaybook $playbook): string
    {
        $shape = $playbook->output_schema ? "\n\nReturn ONLY a JSON object with these keys: ".json_encode($playbook->output_schema, JSON_UNESCAPED_UNICODE) : "\n\nReturn ONLY a JSON object.";

        return trim((string) $playbook->body).$shape;
    }
}
