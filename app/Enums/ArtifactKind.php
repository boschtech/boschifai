<?php

namespace App\Enums;

enum ArtifactKind: string
{
    case TestabilityReview = 'testability_review';
    case TestPlan = 'test_plan';
    case TestCasesMarkdown = 'test_cases_md';
    case TestCasesJson = 'test_cases_json';
    // Renamed from GeneratedTestPhp: coverage-mode runs can generate tests in whatever
    // framework the target repo actually uses, not just PHPUnit — see
    // GitStatusDiffCollector::classify()'s widened pattern matching for this kind.
    case GeneratedTest = 'generated_test';
    // Coverage-mode's gap_analysis step AND the standalone "Build Knowledge Base" action both
    // produce this same kind/filename convention — see PromptBuilder's codebaseAnalysis() vs
    // standaloneKnowledgeBase() and CodebaseKnowledgeBaseParser.
    case CodebaseKnowledgeBase = 'codebase_knowledge_base';
    // Standalone "Build Skills" action only — a filled-in boschifai-project-template, customized
    // from a real repo scan. See PromptBuilder::standaloneProjectSkill().
    case ProjectSkill = 'project_skill';
}
