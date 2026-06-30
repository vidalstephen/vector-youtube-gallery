<?php
/**
 * Phase 11.3 unit tests — moderation bulk action behavior.
 *
 * @package VectorYT\Gallery\Tests\Unit\Admin
 */

declare(strict_types=1);

namespace VectorYT\Gallery\Tests\Unit\Admin;

use PHPUnit\Framework\TestCase;
use VectorYT\Gallery\Admin\ModerationPage;
use VectorYT\Gallery\Repository\VideoRepository;

final class ModerationPageTest extends TestCase {

    protected function setUp(): void {
        parent::setUp();
        \Brain\Monkey\setUp();
        \VectorYT\Gallery\Tests\Support\BrainHelpers::stubEscapeFunctions();
        \Brain\Monkey\Functions\when('get_current_user_id')->alias(static fn(): int => 123);
    }

    protected function tearDown(): void {
        \Brain\Monkey\tearDown();
        parent::tearDown();
    }

    public function test_approve_marks_selected_videos_approved(): void {
        $repo = new RecordingVideoRepository();
        $page = new ModerationPage($repo);
        $this->assertSame(2, $page->apply_bulk(array(10, 20), 'approve', 'looks good'));
        $this->assertSame('approved', $repo->updates[10]['moderation_status']);
        $this->assertSame('looks good', $repo->updates[10]['moderation_reason']);
        $this->assertSame(123, $repo->updates[10]['moderated_by']);
        $this->assertArrayHasKey('moderated_at', $repo->updates[10]);
        $this->assertSame('approved', $repo->updates[20]['moderation_status']);
    }

    public function test_hide_sets_hidden_and_hidden_status(): void {
        $repo = new RecordingVideoRepository();
        $page = new ModerationPage($repo);
        $this->assertSame(1, $page->apply_bulk(array(15), 'hide', 'not suitable'));
        $this->assertSame(1, $repo->updates[15]['is_hidden']);
        $this->assertSame('hidden', $repo->updates[15]['moderation_status']);
        $this->assertSame('not suitable', $repo->updates[15]['moderation_reason']);
    }

    public function test_unhide_restores_approved_status(): void {
        $repo = new RecordingVideoRepository();
        $page = new ModerationPage($repo);
        $this->assertSame(1, $page->apply_bulk(array(18), 'unhide', 'restored'));
        $this->assertSame(0, $repo->updates[18]['is_hidden']);
        $this->assertSame('approved', $repo->updates[18]['moderation_status']);
    }

    public function test_classify_requires_valid_manual_type(): void {
        $repo = new RecordingVideoRepository();
        $page = new ModerationPage($repo);
        $this->assertSame(0, $page->apply_bulk(array(30), 'classify', 'bad type', 'not-valid'));
        $this->assertSame(array(), $repo->updates);
    }

    public function test_classify_sets_manual_type_and_approves(): void {
        $repo = new RecordingVideoRepository();
        $page = new ModerationPage($repo);
        $this->assertSame(1, $page->apply_bulk(array(31), 'classify', 'operator classification', 'short_confirmed'));
        $this->assertSame('short_confirmed', $repo->updates[31]['manual_content_type']);
        $this->assertSame('approved', $repo->updates[31]['moderation_status']);
        $this->assertStringStartsWith('moderation:123:', $repo->updates[31]['manual_content_source']);
    }
}

final class RecordingVideoRepository extends VideoRepository {
    /** @var array<int,array<string,mixed>> */
    public array $updates = array();

    /** @param array<string,mixed> $updates */
    public function update_by_id(int $id, array $updates): int {
        $this->updates[$id] = $updates;
        return count($updates);
    }
}
