<?php

namespace Tests\Unit\Services;

use App\Enums\ArtifactKind;
use App\Services\ArtifactCollection\GitStatusDiffCollector;
use Tests\TestCase;

class GitStatusDiffCollectorTest extends TestCase
{
    private GitStatusDiffCollector $collector;

    protected function setUp(): void
    {
        parent::setUp();
        $this->collector = new GitStatusDiffCollector();
    }

    public function test_new_paths_only_returns_paths_absent_from_before(): void
    {
        $before = ['.claude/', '.boschifai/requirement_x.md'];
        $after = ['.claude/', '.boschifai/requirement_x.md', '.boschifai/testability_review_x.md'];

        $this->assertSame(
            ['.boschifai/testability_review_x.md'],
            $this->collector->newPaths($before, $after)
        );
    }

    /** @dataProvider classificationProvider */
    public function test_classify_matches_the_binding_naming_convention(string $path, ?ArtifactKind $expected): void
    {
        $this->assertSame($expected, $this->collector->classify($path));
    }

    public static function classificationProvider(): array
    {
        return [
            'testability review' => ['.boschifai/testability_review_custom-field.md', ArtifactKind::TestabilityReview],
            'test plan' => ['.boschifai/test_plan_custom-field.md', ArtifactKind::TestPlan],
            'test cases md' => ['.boschifai/test_cases_custom-field.md', ArtifactKind::TestCasesMarkdown],
            'test cases json' => ['.boschifai/test_cases_custom-field.json', ArtifactKind::TestCasesJson],
            'generated feature test' => ['tests/Feature/CustomFieldControllerTest.php', ArtifactKind::GeneratedTestPhp],
            'generated unit test' => ['tests/Unit/CustomFieldServiceTest.php', ArtifactKind::GeneratedTestPhp],
            'unrelated boschifai-init scaffolding' => ['.claude/commands/boschifai-review.md', null],
            'unrelated repo file' => ['app/Http/Controllers/CustomFieldController.php', null],
            // Confirms a php file outside tests/Feature|Unit is never misclassified as generated —
            // a stray edit anywhere else in the worktree must never be picked up as an artifact.
            'php file outside tests dir' => ['app/SomeTest.php', null],
        ];
    }

    public function test_classify_all_excludes_unmatched_paths_silently(): void
    {
        $newPaths = [
            '.boschifai/testability_review_x.md',
            '.claude/commands/boschifai-review.md', // boschifai init's own scaffolding — must not be misattributed
        ];

        $classified = $this->collector->classifyAll($newPaths);

        $this->assertSame(
            ['.boschifai/testability_review_x.md' => 'testability_review'],
            $classified
        );
    }
}
