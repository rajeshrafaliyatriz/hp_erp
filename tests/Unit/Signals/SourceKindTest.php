<?php

namespace Tests\Unit\Signals;

use App\Domain\Signals\Market\SourceKind;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SourceKindTest extends TestCase
{
    #[DataProvider('cases')]
    public function test_it_only_says_yes_on_a_clear_marker(?string $url, bool $expected): void
    {
        $this->assertSame($expected, SourceKind::isOpinionOrResearch($url));
    }

    #[DataProvider('directories')]
    public function test_directories_and_listicles_are_recognised(?string $url, bool $expected): void
    {
        $this->assertSame($expected, SourceKind::isDirectoryOrListicle($url));
    }

    public static function directories(): array
    {
        return [
            'manifest directory' => ['https://themanifest.com/in/systems-integration/companies/bengaluru', true],
            'clutch' => ['https://clutch.co/in/it-services', true],
            'top 10 article path' => ['https://example.com/blog/top-10-it-system-integrators-in-india', true],
            'list of companies' => ['https://example.com/articles/list-of-hrms-vendors', true],
            'builtin listicle' => ['https://builtin.com/articles/data-science-companies-in-india', true],
            'top consulting firms article' => ['https://neurons-lab.com/articles/top-ai-consulting-firms', true],
            'best HR tech vendors' => ['https://example.com/blog/best-hr-tech-vendors-for-government', true],
            'startups to watch' => ['https://example.com/articles/ai-startups-to-watch-in-india', true],
            'a company press release is not a list' => ['https://www.keystone.com/news-publications/keystone-to-launch-india-expanding-its-global-platform', false],
            'a news story about a company in India is not a list' => ['https://example.com/news/infosys-opens-new-campus-in-pune', false],
            'best practices article is not a list' => ['https://example.com/articles/best-practices-for-hr-systems', false],
            'top story section is not a list' => ['https://example.com/top-story/budget-2026', false],
            'a tender notice' => ['https://nhm.maharashtra.gov.in/en/notice/selection-of-msi', false],
            'a news report' => ['https://www.orissapost.com/odisha-expands-tribal-education-network', false],
            'a press release that mentions best' => ['https://example.com/news/bestow-awards-to-staff', false],
            'empty' => ['', false],
            'null' => [null, false],
        ];
    }

    public static function cases(): array
    {
        return [
            'journal host' => ['https://academic.oup.com/oodh/article-abstract/doi/10.1093/x', true],
            'preprint' => ['https://arxiv.org/abs/2401.00001', true],
            'researchgate' => ['https://www.researchgate.net/publication/123', true],
            'blog path' => ['https://example.com/blog/our-take-on-government-it', true],
            'opinion path' => ['https://www.thehindu.com/opinion/lead/why-it-matters/article1.ece', true],
            'university paper path' => ['https://www.iitb.ac.in/journal/paper/12', true],
            'government tender' => ['https://nhm.maharashtra.gov.in/en/notice/selection-of-msi', false],
            'news article' => ['https://www.orissapost.com/odisha-expands-tribal-education-network', false],
            'company press release' => ['https://www.smiplindia.com/cctv-system-integrator-india.html', false],
            'blogspot-like word inside a slug is not a blog path' => ['https://example.com/news/blogger-awarded-contract', false],
            'empty' => ['', false],
            'null' => [null, false],
        ];
    }
}
