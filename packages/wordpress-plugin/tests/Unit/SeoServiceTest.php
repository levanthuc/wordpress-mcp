<?php

declare(strict_types=1);

namespace JOOservices\WordPressMcp\Tests\Unit;

use JOOservices\WordPressMcp\Services\SeoService;
use JOOservices\WordPressMcp\Support\ErrorCodes;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use WP_Post;

final class SeoServiceTest extends TestCase
{
    private SeoService $service;

    protected function setUp(): void
    {
        $this->service = new SeoService();
        $GLOBALS['wp_test_posts'] = [];
        $GLOBALS['wp_test_postmeta'] = [];
        $GLOBALS['wp_test_options'] = [];
    }

    #[Test]
    public function it_reports_not_found_for_a_missing_post(): void
    {
        self::assertSame(['error' => ErrorCodes::POST_NOT_FOUND], $this->service->audit(999));
        self::assertSame(['error' => ErrorCodes::POST_NOT_FOUND], $this->service->getSeoMetadata(999));
        self::assertSame(['error' => ErrorCodes::POST_NOT_FOUND], $this->service->updateSeoMetadata(999, []));
    }

    #[Test]
    public function it_flags_missing_title_and_description(): void
    {
        $GLOBALS['wp_test_posts'][1] = new WP_Post(1, '<p>Hello world</p>');

        $result = $this->service->audit(1);
        $codes = array_column($result['findings'], 'code');

        self::assertContains('missing_title', $codes);
        self::assertContains('missing_description', $codes);
    }

    #[Test]
    public function it_does_not_flag_title_or_description_once_set(): void
    {
        $GLOBALS['wp_test_posts'][1] = new WP_Post(1, '<p>Hello world</p>');

        $this->service->updateSeoMetadata(1, ['title' => 'My Title', 'description' => 'My description']);
        $codes = array_column($this->service->audit(1)['findings'], 'code');

        self::assertNotContains('missing_title', $codes);
        self::assertNotContains('missing_description', $codes);
    }

    #[Test]
    public function it_flags_noindex(): void
    {
        $GLOBALS['wp_test_posts'][1] = new WP_Post(1, '<p>x</p>');
        $this->service->updateSeoMetadata(1, ['noindex' => true]);

        $codes = array_column($this->service->audit(1)['findings'], 'code');

        self::assertContains('noindex', $codes);

        $metadata = $this->service->getSeoMetadata(1);
        self::assertTrue($metadata['noindex']);
    }

    #[Test]
    public function it_flags_multiple_h1_headings(): void
    {
        $GLOBALS['wp_test_posts'][1] = new WP_Post(1, '<h1>One</h1><p>text</p><h1>Two</h1>');

        $codes = array_column($this->service->audit(1)['findings'], 'code');

        self::assertContains('multiple_h1', $codes);
    }

    #[Test]
    public function it_flags_a_skipped_heading_level(): void
    {
        $GLOBALS['wp_test_posts'][1] = new WP_Post(1, '<h1>One</h1><h3>Skipped to three</h3>');

        $codes = array_column($this->service->audit(1)['findings'], 'code');

        self::assertContains('skipped_heading_level', $codes);
    }

    #[Test]
    public function it_does_not_flag_sequential_headings(): void
    {
        $GLOBALS['wp_test_posts'][1] = new WP_Post(1, '<h1>One</h1><h2>Two</h2><h3>Three</h3>');

        $codes = array_column($this->service->audit(1)['findings'], 'code');

        self::assertNotContains('multiple_h1', $codes);
        self::assertNotContains('skipped_heading_level', $codes);
    }

    #[Test]
    public function it_flags_images_missing_alt_text(): void
    {
        $GLOBALS['wp_test_posts'][1] = new WP_Post(1, '<img src="a.jpg"><img src="b.jpg" alt="described">');

        $codes = array_column($this->service->audit(1)['findings'], 'code');

        self::assertContains('missing_alt_text', $codes);
    }

    #[Test]
    public function it_does_not_flag_images_that_all_have_alt_text(): void
    {
        $GLOBALS['wp_test_posts'][1] = new WP_Post(1, '<img src="a.jpg" alt="a"><img src="b.jpg" alt="b">');

        $codes = array_column($this->service->audit(1)['findings'], 'code');

        self::assertNotContains('missing_alt_text', $codes);
    }

    #[Test]
    public function it_round_trips_seo_metadata_for_the_core_provider(): void
    {
        $GLOBALS['wp_test_posts'][1] = new WP_Post(1, '');

        $updated = $this->service->updateSeoMetadata(1, [
            'title' => 'Custom title',
            'description' => 'Custom description',
            'canonical' => 'https://example.test/canonical',
        ]);

        self::assertSame('core', $updated['provider']);
        self::assertSame('Custom title', $updated['title']);
        self::assertSame('Custom description', $updated['description']);
        self::assertSame('https://example.test/canonical', $updated['canonical']);
    }

    #[Test]
    public function it_round_trips_title_and_description_together_for_yoast(): void
    {
        if (! defined('WPSEO_VERSION')) {
            define('WPSEO_VERSION', '23.0');
        }

        $GLOBALS['wp_test_posts'][1] = new WP_Post(1, '');

        $updated = $this->service->updateSeoMetadata(1, [
            'title' => 'Yoast SEO Title',
            'description' => 'Yoast SEO Description',
        ]);

        self::assertSame('yoast', $updated['provider']);
        self::assertSame('Yoast SEO Title', $updated['title']);
        self::assertSame('Yoast SEO Description', $updated['description']);
        self::assertSame('Yoast SEO Title', $GLOBALS['wp_test_postmeta'][1]['_yoast_wpseo_title']);
        self::assertSame('Yoast SEO Description', $GLOBALS['wp_test_postmeta'][1]['_yoast_wpseo_metadesc']);

        $readBack = $this->service->getSeoMetadata(1);
        self::assertSame('Yoast SEO Title', $readBack['title']);
        self::assertSame('Yoast SEO Description', $readBack['description']);
    }

    #[Test]
    public function it_keeps_yoast_description_when_title_save_clears_metadesc_in_the_same_request(): void
    {
        if (! defined('WPSEO_VERSION')) {
            define('WPSEO_VERSION', '23.0');
        }

        $GLOBALS['wp_test_yoast_clears_metadesc_on_title'] = true;
        $GLOBALS['wp_test_posts'][1] = new WP_Post(1, '');

        try {
            $updated = $this->service->updateSeoMetadata(1, [
                'title' => 'Yoast SEO Title',
                'description' => 'Yoast SEO Description',
            ]);

            self::assertSame('Yoast SEO Description', $updated['description']);
            self::assertSame('Yoast SEO Description', $GLOBALS['wp_test_postmeta'][1]['_yoast_wpseo_metadesc']);
        } finally {
            unset($GLOBALS['wp_test_yoast_clears_metadesc_on_title']);
        }
    }


    #[Test]
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function it_reads_writes_and_clears_rank_math_focus_keywords_and_pillar_content(): void
    {
        if (! class_exists('RankMath', false)) {
            class_alias(\stdClass::class, 'RankMath');
        }

        $GLOBALS['wp_test_posts'][17] = new WP_Post(17, '<h2>Test</h2>');

        $updated = $this->service->updateSeoMetadata(17, [
            'title' => 'SEO Test',
            'description' => 'Rank Math metadata',
            'focus_keywords' => ['hầm cầu đầy', 'hút hầm cầu Quảng Ngãi'],
            'pillar_content' => true,
        ]);

        self::assertSame('rank_math', $updated['provider']);
        self::assertSame(['hầm cầu đầy', 'hút hầm cầu Quảng Ngãi'], $updated['focus_keywords']);
        self::assertTrue($updated['pillar_content']);
        self::assertSame('hầm cầu đầy, hút hầm cầu Quảng Ngãi', $GLOBALS['wp_test_postmeta'][17]['rank_math_focus_keyword']);
        self::assertSame('on', $GLOBALS['wp_test_postmeta'][17]['rank_math_pillar_content']);

        $cleared = $this->service->updateSeoMetadata(17, [
            'focus_keywords' => [],
            'pillar_content' => false,
        ]);
        self::assertSame([], $cleared['focus_keywords']);
        self::assertFalse($cleared['pillar_content']);
        self::assertSame('off', $GLOBALS['wp_test_postmeta'][17]['rank_math_pillar_content']);
    }

    #[Test]
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function it_rejects_invalid_rank_math_fields_without_partial_writes(): void
    {
        if (! class_exists('RankMath', false)) {
            class_alias(\stdClass::class, 'RankMath');
        }

        $GLOBALS['wp_test_posts'][18] = new WP_Post(18, '');

        foreach ([
            ['focus_keywords' => ['one', 'two', 'three', 'four', 'five', 'six']],
            ['focus_keywords' => ['valid', 'valid']],
            ['focus_keywords' => ['contains,comma']],
            ['focus_keywords' => 'not-an-array'],
            ['pillar_content' => 'on'],
        ] as $bad) {
            $result = $this->service->updateSeoMetadata(18, [
                'title' => 'Must not be saved',
                ...$bad,
            ]);
            self::assertSame(['error' => ErrorCodes::INVALID_ARGUMENT], $result);
            self::assertArrayNotHasKey('rank_math_title', $GLOBALS['wp_test_postmeta'][18] ?? []);
        }
    }

    #[Test]
    public function it_reads_and_writes_robots_txt_virtually_when_no_physical_file_exists(): void
    {
        $before = $this->service->getRobots();
        self::assertSame('virtual', $before['source']);

        $updated = $this->service->updateRobots("User-agent: *\nDisallow: /private\n");
        self::assertSame('virtual', $updated['source']);

        $after = $this->service->getRobots();
        self::assertSame("User-agent: *\nDisallow: /private\n", $after['content']);
    }
}
