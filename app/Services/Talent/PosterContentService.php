<?php

namespace App\Services\Talent;

use App\Support\CandidateLink;
use App\Support\Poster\JobDescriptionParser;
use App\Support\Poster\PosterFacts;
use App\Support\Poster\PosterFormat;
use App\Support\Poster\PosterText;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Everything a hiring poster says, decided once.
 *
 * ── WHY ONE SERVICE FOR TWO RENDERERS ───────────────────────────────────────
 *
 * The PNGs are drawn by Satori in the Next app; the A4 PDF is drawn by dompdf
 * here. Neither can do the other's job - dompdf cannot rasterise and Satori
 * cannot emit PDF - so two renderers is unavoidable.
 *
 * What IS avoidable is two opinions about the job advert. If each side parsed
 * the description, applied its own caps and picked its own fallbacks, the PDF
 * and the PNG would eventually disagree about what the role requires. That is
 * not a cosmetic drift, it is two different adverts for one job.
 *
 * So every content decision happens here and both renderers receive finished
 * strings. The Next route contains no caps, no truncation and no fallbacks; it
 * turns this array into pixels and nothing else.
 */
class PosterContentService
{
    public function __construct(private JobDescriptionParser $parser)
    {
    }

    /**
     * @param  object  $org       row from institute_detail (slug, name, ...)
     * @param  array<int,array<string,mixed>>  $postings  public payload rows
     */
    public function build(object $org, array $postings, string $format): array
    {
        $count = max(1, count($postings));
        $spec = PosterFormat::spec($format, $count);

        $roles = array_map(fn (array $posting) => $this->role($posting, $spec), $postings);

        return [
            'format' => [
                'name' => $spec['format'],
                'label' => $spec['label'],
                'width' => $spec['w'],
                'height' => $spec['h'],
                'headline_size' => $spec['headline'],
            ],
            'brand' => $this->brand($org),
            'copy' => config('poster.copy'),
            'palette' => config('poster.palette'),
            'roles' => $roles,
            /*
             * One poster, one destination. A multi-role poster links to the
             * careers page rather than to any one job, because a reader cannot
             * tell which of three "Apply Now" links they are looking at.
             */
            'apply_url' => $count === 1
                ? CandidateLink::careersPosting($org->careers_slug, (int) $postings[0]['id'])
                : CandidateLink::careers($org->careers_slug),
            'layout' => $this->layout($roles, $count),
        ];
    }

    /**
     * Which arrangement the renderers should draw.
     *
     * Chosen here rather than in either renderer so both make the same choice,
     * and so an empty panel becomes a DESIGNED layout rather than a gap where a
     * card should be. A poster is never drawn with an empty frame or a heading
     * over nothing.
     */
    private function layout(array $roles, int $count): string
    {
        if ($count > 1) {
            return 'multi';
        }

        $panels = count($roles[0]['panels']);

        return match ($panels) {
            0 => 'hero',            // facts and skills carry the whole poster
            1 => 'single-panel',    // one panel full width, larger facts strip
            default => 'standard',
        };
    }

    private function role(array $posting, array $spec): array
    {
        $title = $this->title((string) ($posting['title'] ?? ''));

        $panels = $spec['bullets'] > 0
            ? $this->panels($posting, $spec)
            : [];

        return [
            'id' => (int) ($posting['id'] ?? 0),
            'title' => $title,
            'title_size' => PosterFormat::titleSize($spec['title'], mb_strlen($title)),
            'department' => PosterText::tighten((string) ($posting['department'] ?? ''), 34),
            'panels' => $panels,
            'facts' => PosterFacts::build($posting, $spec['chips']),
        ];
    }

    /**
     * The two content panels, with the fallback chain.
     *
     * Requirements is the panel most often missing, and `skills` is the best
     * substitute available: it is already an array on the public payload, it is
     * already what the careers page shows, and it is set on every live posting.
     * Falling back to it is why a poster rarely has to drop to one panel.
     */
    private function panels(array $posting, array $spec): array
    {
        $parsed = $this->parser->parse(
            $posting['description'] ?? null,
            $spec['bullets'],
            $spec['width'],
        );

        $panels = [];

        if ($parsed['responsibilities'] !== []) {
            $panels[] = [
                'heading' => config('poster.copy.panel_primary'),
                'style' => 'primary',
                'kind' => 'list',
                'items' => $parsed['responsibilities'],
            ];
        }

        if ($parsed['requirements'] !== []) {
            $panels[] = [
                'heading' => config('poster.copy.panel_secondary'),
                'style' => 'secondary',
                'kind' => 'list',
                'items' => $parsed['requirements'],
            ];
        } elseif ($skills = $this->skills($posting, $spec)) {
            $panels[] = [
                'heading' => config('poster.copy.panel_skills'),
                'style' => 'secondary',
                'kind' => 'chips',
                'items' => $skills,
            ];
        } elseif ($quals = $this->qualifications($posting, $spec)) {
            $panels[] = [
                'heading' => config('poster.copy.panel_quals'),
                'style' => 'secondary',
                'kind' => 'list',
                'items' => $quals,
            ];
        }

        return $panels;
    }

    private function skills(array $posting, array $spec): array
    {
        $skills = $posting['skills'] ?? [];
        if (!is_array($skills)) {
            return [];
        }

        $out = [];
        foreach ($skills as $skill) {
            $value = PosterText::tighten((string) $skill, 26);
            if ($value !== null) {
                $out[] = $value;
            }
            if (count($out) >= $spec['bullets'] + 2) {
                break;
            }
        }

        return $out;
    }

    private function qualifications(array $posting, array $spec): array
    {
        $out = [];
        foreach (['education', 'certifications'] as $field) {
            $value = $posting[$field] ?? null;
            if (is_string($value) && trim($value) !== '') {
                $item = PosterText::tighten($value, $spec['width']);
                if ($item !== null) {
                    $out[] = $item;
                }
            }
        }

        return $out;
    }

    /**
     * Keep a title readable without lying about it.
     *
     * A trailing parenthetical or a " - " qualifier is where a long title
     * naturally divides, and cutting there gives a complete title rather than a
     * truncated one: "Senior Consultant (School ERP, North)" reads better as
     * "Senior Consultant" than as "Senior Consultant (School ERP, Nor...".
     */
    private function title(string $raw): string
    {
        $title = PosterText::flatten($raw);

        if ($title === '') {
            return 'Open role';
        }

        if (mb_strlen($title) <= PosterFormat::TITLE_MAX_CHARS) {
            return $title;
        }

        foreach ([' (', ' - ', " \u{2013} ", " \u{2014} ", ' | ', ' / '] as $divider) {
            $cut = mb_strpos($title, $divider);
            if ($cut !== false && $cut >= 18) {
                return rtrim(mb_substr($title, 0, $cut));
            }
        }

        return PosterText::tighten($title, PosterFormat::TITLE_MAX_CHARS) ?? mb_substr($title, 0, PosterFormat::TITLE_MAX_CHARS);
    }

    /**
     * The organisation's mark.
     *
     * The logo is fetched here, once, and handed to both renderers as a data
     * URI. That is not an optimisation - dompdf runs with enable_remote off and
     * draws nothing for a remote <img>, and Satori needs the bytes anyway.
     *
     * Dimensions are read from the file header because Satori throws on an
     * <img> it cannot size, and parsing PNG/JPEG headers needs no extension.
     */
    private function brand(object $org): array
    {
        $name = (string) ($org->organization_name ?? 'Careers');
        $logo = $this->logo($org);

        return [
            'name' => $name,
            'initials' => $this->initials($name),
            'website' => $org->organization_website ?? null,
            'logo' => $logo['data'] ?? null,
            'logo_width' => $logo['width'] ?? null,
            'logo_height' => $logo['height'] ?? null,
        ];
    }

    private function initials(string $name): string
    {
        preg_match_all('/\b(\p{Lu})/u', $name, $matches);
        $letters = implode('', array_slice($matches[1] ?? [], 0, 3));

        return $letters !== '' ? $letters : mb_strtoupper(mb_substr($name, 0, 2));
    }

    /** @return array{data:?string,width:?int,height:?int} */
    private function logo(object $org): array
    {
        $tenant = (int) ($org->sub_institute_id ?? 0);
        $blank = ['data' => null, 'width' => null, 'height' => null];

        if ($tenant <= 0) {
            return $blank;
        }

        return Cache::remember(
            "poster.logo.{$tenant}",
            (int) config('poster.logo.cache_seconds', 3600),
            function () use ($tenant, $blank) {
                $filename = trim((string) \Illuminate\Support\Facades\DB::table('school_setup')
                    ->where('id', $tenant)->value('Logo'));

                if ($filename === '') {
                    return $blank;
                }

                try {
                    $url = Storage::disk('digitalocean')->url('public/hp_logo/' . $filename);
                    $response = Http::timeout((int) config('poster.logo.timeout', 5))->get($url);

                    if (!$response->successful()) {
                        return $blank;
                    }

                    $bytes = $response->body();

                    if (strlen($bytes) > (int) config('poster.logo.max_bytes', 1048576)) {
                        return $blank;
                    }

                    $size = $this->imageSize($bytes);
                    if ($size === null) {
                        return $blank;
                    }

                    return [
                        'data' => 'data:' . $size['mime'] . ';base64,' . base64_encode($bytes),
                        'width' => $size['width'],
                        'height' => $size['height'],
                        'mime' => $size['mime'],
                    ];
                } catch (\Throwable $e) {
                    // A missing logo must never fail a download. Both renderers
                    // draw the initials block instead, so the layout is unchanged.
                    Log::warning('Poster logo unavailable: ' . $e->getMessage());

                    return $blank;
                }
            },
        );
    }

    /**
     * Read width, height and type from the file header.
     *
     * getimagesizefromstring() needs no GD for PNG/JPEG dimensions, and this
     * avoids depending on an extension that composer.json does not declare.
     *
     * @return array{width:int,height:int,mime:string}|null
     */
    private function imageSize(string $bytes): ?array
    {
        $info = @getimagesizefromstring($bytes);

        if ($info === false || empty($info[0]) || empty($info[1])) {
            return null;
        }

        $mime = $info['mime'] ?? 'image/png';

        if (!in_array($mime, ['image/png', 'image/jpeg', 'image/gif', 'image/webp'], true)) {
            return null;
        }

        return ['width' => (int) $info[0], 'height' => (int) $info[1], 'mime' => $mime];
    }
}
