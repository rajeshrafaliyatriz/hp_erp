<?php

namespace App\Domain\Signals\Research;

class NullResearchProvider implements ResearchProvider
{
    public function isConfigured(): bool
    {
        return false;
    }

    public function search(array $context): array
    {
        return [];
    }
}
