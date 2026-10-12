<?php

namespace Tests\Unit;

use App\Enums\RunState;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class RunStateTest extends TestCase
{
    #[DataProvider('gateProvider')]
    public function test_awaits_human_gate(RunState $state, ?string $expected): void
    {
        $this->assertSame($expected, $state->awaitsHumanGate());
    }

    public static function gateProvider(): array
    {
        return [
            'gap analysis ready' => [RunState::GapAnalysisReady, 'gap_analysis'],
            'local execution complete' => [RunState::LocalExecutionComplete, 'pre_push'],
            'generation running' => [RunState::GenerationRunning, null],
            'report ready' => [RunState::ReportReady, null],
        ];
    }

    public function test_only_end_states_are_terminal(): void
    {
        $terminal = array_values(array_filter(RunState::cases(), fn (RunState $s) => $s->isTerminal()));

        $this->assertSame([
            RunState::GapAnalysisRejected,
            RunState::GapAnalysisChangesRequested,
            RunState::GenerationBlocked,
            RunState::PushRejected,
            RunState::ReportReady,
            RunState::StandaloneComplete,
            RunState::Failed,
            RunState::Cancelled,
        ], $terminal);
    }
}
