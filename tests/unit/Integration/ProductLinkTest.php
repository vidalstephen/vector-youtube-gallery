<?php
declare(strict_types=1);

namespace VectorYT\Gallery\Tests\Unit\Integration;

use PHPUnit\Framework\TestCase;
use VectorYT\Gallery\Integrations\WooCommerce\ProductLink;

final class ProductLinkTest extends TestCase {

    protected function setUp(): void {
        parent::setUp();
        \Brain\Monkey\setUp();
        \VectorYT\Gallery\Tests\Support\BrainHelpers::stubEscapeFunctions();
        \VectorYT\Gallery\Tests\Support\BrainHelpers::stubIntegrationFunctions();
    }

    protected function tearDown(): void {
        \Brain\Monkey\tearDown();
        parent::tearDown();
    }

    public function test_is_active_returns_false_when_wc_inactive(): void {
        // Brain Monkey doesn't load wc_get_product + product post-type by default.
        $this->assertFalse(ProductLink::is_active(), 'is_active() must default to false in unit tests.');
    }

    public function test_is_active_returns_false_when_wc_functions_present_without_post_type(): void {
        // We can simulate "function exists but post type missing" by
        // stubbing wc_get_product via Brain Monkey and not declaring
        // product post type.
        \Brain\Monkey\Functions\when('wc_get_product')->returnArg(1);
        // post_type_exists() default is false (BrainHelpers::stubIntegrationFunctions)
        $this->assertFalse(ProductLink::is_active(), 'Should remain false when product post type isn\'t registered.');
    }

    public function test_is_active_returns_true_when_both_present(): void {
        \Brain\Monkey\Functions\when('wc_get_product')->returnArg(1);
        // Stub post_type_exists() to consider "product" registered.
        \Brain\Monkey\Functions\when('post_type_exists')->alias(
            static function (string $slug): bool {
                return 'product' === $slug;
            }
        );
        $this->assertTrue(ProductLink::is_active(), 'Should be true when wc_get_product + product post-type are present.');
    }

    public function test_resolve_returns_empty_when_wc_inactive(): void {
        $this->assertSame('', ProductLink::resolve_product_url(array('products' => array('aaa' => 1)), 'aaa'));
    }

    public function test_resolve_returns_empty_for_empty_video_id(): void {
        $this->assertSame('', ProductLink::resolve_product_url(array('products' => array('aaa' => 1)), ''));
    }

    public function test_resolve_returns_empty_for_unknown_video(): void {
        $this->assertSame('', ProductLink::resolve_product_url(array('products' => array('aaa' => 1)), 'zzz'));
    }

    public function test_mapping_sanitize_drops_non_youtube_ids(): void {
        $mapping = ProductLink::mapping_from_config(array(
            'products' => array(
                'abcDEF_-123' => 7,                   // valid
                'short'       => 7,                  // too short
                'TOOLONG_______123' => 7,             // too long
                'has space!!' => 7,                   // contains spaces
                'good_id__12' => -1,                  // negative product id
                'another_OK_-' => 'not-int',          // non-int product id
                'valid_QQ___' => 0,                   // zero product id
            ),
        ));
        $this->assertSame(array('abcDEF_-123' => 7), $mapping, 'mapping must drop invalid pairs');
    }

    public function test_mapping_handles_non_array_products_field(): void {
        $this->assertSame(array(), ProductLink::mapping_from_config(array('products' => 'corrupted')));
        $this->assertSame(array(), ProductLink::mapping_from_config(array('products' => null)));
        $this->assertSame(array(), ProductLink::mapping_from_config(array()));
    }

    public function test_is_youtube_id_is_strict(): void {
        $this->assertTrue(ProductLink::is_youtube_id('aaaaaaaaaaa'));
        $this->assertTrue(ProductLink::is_youtube_id('abcDEF_-12_'));
        $this->assertFalse(ProductLink::is_youtube_id('aaaaaaaaa'));
        $this->assertFalse(ProductLink::is_youtube_id('aaaaaaaaaaaa'));
        $this->assertFalse(ProductLink::is_youtube_id('aaaaaaaaa!a'));
        $this->assertFalse(ProductLink::is_youtube_id('aaaaaaaaa a'));
        $this->assertFalse(ProductLink::is_youtube_id(''));
    }

    public function test_render_cta_returns_empty_when_wc_inactive(): void {
        $out = ProductLink::render_cta(array('products' => array('aaaaaaaaaaa' => 1)), 'aaaaaaaaaaa');
        $this->assertSame('', $out);
    }

    public function test_render_cta_returns_empty_for_unknown_video(): void {
        $out = ProductLink::render_cta(array('products' => array('aaaaaaaaaaa' => 1)), 'bbbbbbbbbbb');
        $this->assertSame('', $out);
    }
}
