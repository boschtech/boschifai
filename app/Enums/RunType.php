<?php

namespace App\Enums;

/**
 * `requirement` is today's only flow: a written requirement + a human-specified
 * `target_file_path`. `coverage` is the new flow: a plain "increase coverage for X"
 * instruction with no known target file — the pipeline works that out itself (see
 * RunTestPlanJob's `RECOMMENDED_TARGET_FILE:` extraction).
 *
 * `build_skills`/`build_knowledge_base` are the "Standalone Actions" (SidebarNav) — a single
 * Claude invocation that reads a repo and writes one artifact, with no approval gate and no
 * push: see RunStandaloneActionJob. Modeled as RunType/Run rows (not a separate table) so they
 * get the existing Runs list/history/reporting pages for free, same reasoning as `coverage`.
 */
enum RunType: string
{
    case Requirement = 'requirement';
    case Coverage = 'coverage';
    case BuildSkills = 'build_skills';
    case BuildKnowledgeBase = 'build_knowledge_base';
}
