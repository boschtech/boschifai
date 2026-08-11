<?php

namespace App\Enums;

enum RunStepKey: string
{
    case GapAnalysis = 'gap_analysis';
    case TestPlan = 'test_plan';
    case TestCaseGeneration = 'test_case_generation';
    case CodeGeneration = 'code_generation';
    case LocalExecution = 'local_execution';
    case Push = 'push';
    case CiPoll = 'ci_poll';
}
