<?php

namespace App\Enums;

enum ArtifactKind: string
{
    case TestabilityReview = 'testability_review';
    case TestPlan = 'test_plan';
    case TestCasesMarkdown = 'test_cases_md';
    case TestCasesJson = 'test_cases_json';
    case GeneratedTestPhp = 'generated_test_php';
}
