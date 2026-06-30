<?php
/**
 * Phase 9.4 unit tests — Block patterns registrar.
 *
 * Ensures all 4 patterns are registered with non-empty content, and that no
 * pattern's content includes an API key/token placeholder. The
 * `tests/stubs/wp-block-api.php` stub file would fail to load under Patchwork
 * (it intercepts `require` on absolute paths), so we declare minimal WP
 * functions directly via Brain\Monkey in tests/bootstrap.
 *
 * @package VectorYT\Gallery\Tests\Unit\Render
 */

declare(strict_types=1);

namespace VectorYT\Gallery\Tests\Unit\Render;

use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use VectorYT\Gallery\Render\PatternsRegistrar;
use VectorYT\Gallery\Tests\Support\BrainHelpers;

final class PatternsRegistrarTest extends TestCase {

    /** @var array<string, array<string,mixed>> */
    private static array $patterns = array();

    protected function setUp(): void {
        parent::setUp();
        \Brain\Monkey\setUp();
        BrainHelpers::stubEscapeFunctions();
        self::$patterns = array();
        Functions\when('register_block_pattern')->alias(static function (string $slug, array $args): bool {
            self::$patterns[$slug] = $args;
            return true;
        });
        Functions\when('register_block_pattern_category')->alias(static function (string $slug, array $args): bool {
            return true;
        });
    }

    protected function tearDown(): void {
        \Brain\Monkey\tearDown();
        parent::tearDown();
    }

    public function test_register_patterns_emits_four_patterns(): void {
        $registrar = new PatternsRegistrar();
        $registrar->register_patterns();

        $expected = array(
            'vyg/channel-grid',
            'vyg/shorts-wall',
            'vyg/live-hub',
            'vyg/featured-landing',
        );
        foreach ($expected as $slug) {
            $this->assertArrayHasKey($slug, self::$patterns, "Missing pattern '{$slug}'");
            $p = self::$patterns[$slug];
            $this->assertNotEmpty($p['title']);
            $this->assertNotEmpty($p['content']);
            $this->assertContains(PatternsRegistrar::CATEGORY_SLUG, $p['categories']);
        }
    }

    public function test_no_patterns_leak_secrets(): void {
        $registrar = new PatternsRegistrar();
        $registrar->register_patterns();
        $this->assertNotEmpty(self::$patterns);
        foreach (self::$patterns as $slug => $p) {
            $this->assertStringNotContainsString('AIza', $p['content'], "Pattern '{$slug}' leaked an AIza-style key");
            $this->assertStringNotContainsString('ya29.', $p['content'], "Pattern '{$slug}' leaked an OAuth token");
            $this->assertStringNotContainsString('client_secret', $p['content'], "Pattern '{$slug}' leaked client_secret reference");
        }
    }

    public function test_register_pattern_category_wires_category_slug(): void {
        $this->assertNotEmpty(PatternsRegistrar::CATEGORY_SLUG);
        $this->assertSame('vyg-patterns', PatternsRegistrar::CATEGORY_SLUG);
    }
}
