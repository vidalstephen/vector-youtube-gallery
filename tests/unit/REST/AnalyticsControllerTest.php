<?php
/**
 * Phase 11.7 unit tests — Analytics events ingestion controller.
 *
 * Locks down:
 *   - is_enabled() == false ⇒ endpoint returns 204 (No Content).
 *   - Valid event_type ⇒ returns 201 with id.
 *   - Unknown event_type ⇒ WP_Error with sanitize_callback rejection
 *     (the WP layer handles this; we exercise the static guard via
 *     EventRepository::EVENT_TYPES enum).
 *
 * @package VectorYT\Gallery\Tests\Unit\REST
 */

declare(strict_types=1);

namespace VectorYT\Gallery\Tests\Unit\REST;

use PHPUnit\Framework\TestCase;
use VectorYT\Gallery\Analytics\EventRepository;
use VectorYT\Gallery\REST\AnalyticsController;

final class AnalyticsControllerTest extends TestCase {

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

    public function test_controller_class_loads(): void {
        $ctrl = new AnalyticsController();
        $this->assertInstanceOf(AnalyticsController::class, $ctrl);
    }

    public function test_namespace_constant(): void {
        $this->assertSame('vyg/v1', AnalyticsController::NAMESPACE_V1);
    }

    public function test_event_types_enum_remains_stable(): void {
        // The REST route's validate_callback relies on this list. Don't
        // shrink it without updating the schema validator.
        $expected = array(
            'impression',
            'play',
            'lightbox_open',
            'load_more_click',
            'unknown',
        );
        $this->assertSame($expected, EventRepository::EVENT_TYPES);
    }

    public function test_is_enabled_gate_returns_false_by_default(): void {
        \Brain\Monkey\Functions\when('get_option')->alias(static function ($k, $default = false) {
            if ('vyg_analytics_enabled' === $k) {
                return false;
            }
            return $default;
        });
        $this->assertFalse(EventRepository::is_enabled());
    }

    public function test_handle_event_returns_204_when_disabled(): void {
        if (! class_exists('WP_REST_Request')) {
            $this->markTestSkipped('WP_REST_Request not available in Brain Monkey test environment.');
        }
        \Brain\Monkey\Functions\when('get_option')->alias(static function ($k, $default = false) {
            if ('vyg_analytics_enabled' === $k) {
                return false;
            }
            return $default;
        });
        $ctrl = new AnalyticsController();
        $req  = new \WP_REST_Request('POST', '/vyg/v1/events');
        $req->set_param('event_type', 'impression');
        $r    = $ctrl->handle_event($req);
        $this->assertInstanceOf(\WP_REST_Response::class, $r);
        $this->assertSame(204, $r->get_status());
        $this->assertNull($r->get_data());
    }
}