<?php

namespace App\Console\Commands;

use App\Models\tblmenumaster_g2gModel;
use Illuminate\Console\Command;

class GenerateG2gAccessLinks extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'g2g:generate-access-links';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Backfill tblmenumaster_g2g.access_link with /module/... paths derived from menu_name, replacing legacy internal codes. External (page_type=link) rows are left untouched.';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $rows = tblmenumaster_g2gModel::orderBy('level')->orderBy('sort_order')->orderBy('id')->get();

        $byId = [];
        foreach ($rows as $row) {
            $byId[$row->id] = $row;
        }

        $slugsByParent = [];
        $updated = 0;
        $skippedLinks = 0;

        foreach ($rows as $row) {
            if (strtolower((string) $row->page_type) === 'link') {
                $skippedLinks++;

                continue;
            }

            $segments = $this->buildSlugSegments($row, $byId, $slugsByParent);
            $accessLink = '/module/'.implode('/', $segments);

            if ($row->access_link !== $accessLink) {
                $row->access_link = $accessLink;
                $row->save();
                $updated++;
            }
        }

        $this->info("Updated {$updated} row(s). Left {$skippedLinks} external link row(s) untouched.");

        return self::SUCCESS;
    }

    /**
     * Builds the slug path segments for a row (module[, menu[, submenu]]),
     * de-duplicating siblings that slugify to the same value.
     */
    private function buildSlugSegments($row, array $byId, array &$slugsByParent): array
    {
        $chain = [$row];
        $current = $row;

        while ($current->parent_id && isset($byId[$current->parent_id])) {
            $current = $byId[$current->parent_id];
            array_unshift($chain, $current);
        }

        $segments = [];
        $parentKey = 0;

        foreach ($chain as $node) {
            $slug = $this->uniqueSlug($node, $parentKey, $slugsByParent);
            $segments[] = $slug;
            $parentKey = $node->id;
        }

        return $segments;
    }

    private function uniqueSlug($node, $parentKey, array &$slugsByParent): string
    {
        $base = $this->slugify($node->menu_name);
        $cacheKey = $parentKey.'|'.$node->id;

        if (isset($slugsByParent[$parentKey]['byId'][$cacheKey])) {
            return $slugsByParent[$parentKey]['byId'][$cacheKey];
        }

        $slug = $base;
        $suffix = 2;
        while (isset($slugsByParent[$parentKey]['taken'][$slug])) {
            $slug = $base.'-'.$suffix;
            $suffix++;
        }

        $slugsByParent[$parentKey]['taken'][$slug] = true;
        $slugsByParent[$parentKey]['byId'][$cacheKey] = $slug;

        return $slug;
    }

    private function slugify(string $text): string
    {
        $text = str_replace('&', ' and ', $text);
        $text = strtolower(trim($text));
        $text = preg_replace('/[^a-z0-9]+/', '-', $text);
        $text = trim($text, '-');

        return $text === '' ? 'item' : $text;
    }
}
