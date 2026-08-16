<?php

namespace App\Services\ClaudeRunner;

/**
 * Builds the prompts for both Run pipelines (plan §4 for `requirement` mode; see the coverage-
 * pipeline plan for `coverage` mode). Paths passed in are relative to the checkout root (Claude's
 * cwd), matching how the requirement/instruction file and generated artifacts are addressed
 * inside `.boschifai/`.
 */
class PromptBuilder
{
    /** @param string[] $attachmentRelativePaths */
    public function gapAnalysis(string $requirementRelativePath, array $attachmentRelativePaths = []): string
    {
        return $this->attachmentsContextBlock($attachmentRelativePaths)."/boschifai-review --file {$requirementRelativePath}";
    }

    /**
     * Supporting files a human attached from their own machine (a spec doc, a screenshot, a
     * sample payload) — NOT part of the repo, copied by RunGapAnalysisJob into the checkout's
     * `.boschifai/attachments/` before this prompt runs. Kept as a short prefix, not folded into
     * the requirement file itself, so it reads as clearly-optional extra context rather than
     * something `/boschifai-review`'s own file-reading logic needs to parse out of the
     * requirement text.
     *
     * @param string[] $attachmentRelativePaths
     */
    private function attachmentsContextBlock(array $attachmentRelativePaths): string
    {
        if ($attachmentRelativePaths === []) {
            return '';
        }

        $list = implode("\n", array_map(fn (string $path) => "- {$path}", $attachmentRelativePaths));

        return <<<CONTEXT
        The user also attached these supporting reference files — review them for extra context
        before proceeding:
        {$list}


        CONTEXT;
    }

    public function testPlan(string $requirementRelativePath): string
    {
        return "/boschifai-test-plan --file {$requirementRelativePath}";
    }

    /**
     * Judgment call J2 (plan §3): `/boschifai-test-cases` has a HARD-GATE requiring `/boschifai-review` to
     * have run "in this session". Gate 1's human approval necessarily ends that session, so
     * this prompt must explicitly invoke the command's own documented escape hatch — without
     * it the command would either silently re-run /boschifai-review (wasted cost, and it would try to
     * rewrite a file a human already approved) or stall.
     */
    public function testCaseGeneration(string $requirementRelativePath, string $testabilityReviewRelativePath): string
    {
        return <<<PROMPT
        A testability review has already been completed and approved externally — see
        {$testabilityReviewRelativePath} in this directory. Skip the HARD-GATE re-check and
        proceed directly to test case generation.

        /boschifai-test-cases --file {$requirementRelativePath}
        PROMPT;
    }

    public function codeGeneration(string $targetFilePath, string $testCasesRelativePath): string
    {
        return <<<PROMPT
        Test cases for this requirement have already been generated and approved — see
        {$testCasesRelativePath} in this directory. Implement PHPUnit Feature tests covering
        each test case already defined there.

        /boschifai-gen-component --file {$targetFilePath}

        {$this->generatedTestFileSentinelInstruction()}
        PROMPT;
    }

    /**
     * Confirmed as a real, recurring failure mode live: RunCodeGenerationJob detects what it
     * produced by diffing `git status` before/after (see GitStatusDiffCollector) — which only
     * ever notices NEWLY untracked paths. A repo's shared, persistent checkout can carry a test
     * file left over from an earlier run against the same target, already untracked before this
     * step even starts; editing that file's CONTENT doesn't change its status line at all, so it
     * never looks "new," even though Claude did exactly the right thing. This sentinel is
     * RunCodeGenerationJob's fallback for exactly that case — asked for unconditionally (not just
     * on retries) since there's no reliable way to know in advance whether today's target already
     * has a test file sitting in the checkout from a prior run.
     */
    private function generatedTestFileSentinelInstruction(): string
    {
        return <<<TEXT
        End your response with exactly one line in this literal format, naming the real path
        (relative to this directory) of the test file you just wrote or edited — even if a test
        file for this target already existed and you edited it rather than creating a new one:
        GENERATED_TEST_FILE: <path>
        TEXT;
    }

    /**
     * Coverage mode's first step, replacing gapAnalysis() — there's no written requirement to
     * review, so this reads a plain "increase coverage for X" instruction and has Claude explore
     * the repo itself. No slash command backs this (confirmed nothing in the vendored
     * boschifai-cli skill library does codebase-understanding — boschifai-investigator is
     * explicitly Jira-requirements-only and forbidden from reading source). Deliberately reuses
     * the exact stack-detection signals `.claude/commands/boschifai-gen-component.md` already
     * defines (Next.js/Java-REST/Java-Event/PHP-Laravel file & dependency signals) rather than
     * inventing a second detection scheme that could disagree with the one code-gen actually uses.
     *
     * The "Execution Recipe" block is what makes coverage mode's local-execution step
     * framework-agnostic (see LocalTestRunner) — regardless of the repo's language, the recipe's
     * TEST_COMMAND must end up producing JUnit XML output, since JunitXmlParser (reused
     * unchanged) only understands that one format.
     */
    public function codebaseAnalysis(string $instructionRelativePath): string
    {
        return <<<PROMPT
        Read the coverage instruction in {$instructionRelativePath}. Explore this repository to
        understand it well enough to add real test coverage for the functionality it describes.

        Detect the repo's tech stack and existing test conventions using the same signal-based
        approach `.claude/commands/boschifai-gen-component.md` already defines (check for
        Next.js/Koa BFF signals, Java REST/Spring Boot signals, Java event-driven/Solace signals,
        or PHP/Laravel signals, in that command's own priority order) — reuse its detection logic
        rather than guessing independently, and fall back to scanning existing test files if
        nothing matches.

        Write a file named `codebase_knowledge_base_<name>.md`, where `<name>` is whatever comes
        after the `coverage_instruction_` prefix in the instruction file's own name (e.g. if you
        read `coverage_instruction_abc123.md`, write `codebase_knowledge_base_abc123.md`) —
        matching the same input-name-derives-output-name convention `/boschifai-review` already
        uses for `requirement_X.md` → `testability_review_X.md`. Contents, in this order:
        1. A header table with a row `| **Codebase Understanding Score** | NN% |` — your own
           honest 0-100 self-assessment of how well you understood this repo well enough to
           generate correct tests for the described functionality.
        2. **Tech Stack** — language, framework, package manager, existing test framework/runner.
        3. **Relevant Files** — the specific file(s)/module(s) implementing the described
           functionality, with brief notes on what each does.
        4. **Existing Test Conventions** — how this repo already structures/names its tests
           (with a real example file path), so generated tests match local style.
        5. **Coverage Gap Assessment** — specifically for the described functionality: what's
           already tested, what's missing, and which file most needs new/expanded coverage.
        6. An **Execution Recipe** section with exactly these two lines (a real, runnable shell
           command each — no placeholders in INSTALL_COMMAND; TEST_COMMAND must contain the
           literal placeholders `{{TEST_FILE}}` and `{{JUNIT_PATH}}` and must produce a JUnit XML
           report at `{{JUNIT_PATH}}` when run, using whatever this stack's own test runner needs
           to emit one — e.g. `--log-junit` for PHPUnit, `--junitxml` for pytest, a JUnit
           reporter for Jest, etc.). `{{TEST_FILE}}` is a FILE PATH, not a test name/method
           pattern — pass it as the runner's own "run just this file" positional argument (e.g.
           `php artisan test {{TEST_FILE}}` for PHPUnit/Laravel, `pytest {{TEST_FILE}}` for
           pytest), never through a name-matching flag like PHPUnit's `--filter` — that matches
           test method/class names via regex and will not match a file path, silently running
           zero tests instead of failing loudly:
           ```
           INSTALL_COMMAND: <command to install this repo's dependencies>
           TEST_COMMAND: <command to run only {{TEST_FILE}} and write JUnit XML to {{JUNIT_PATH}}>
           ```
        PROMPT;
    }

    /**
     * Coverage mode's second step, replacing testPlan() — produces test cases directly (no
     * separate test-plan document; there's no approval-gate reason to keep them apart the way
     * requirement mode's testability-review-vs-plan split has). Follows the same
     * boschifai-test-patterns conventions `/boschifai-test-cases` already enforces (TC-ID format,
     * 9 mandatory fields) so the resulting file is classified by the existing, unmodified
     * GitStatusDiffCollector pattern for test_cases_*.md.
     */
    public function coverageTestDesign(string $instructionRelativePath, string $knowledgeBaseRelativePath): string
    {
        return <<<PROMPT
        A codebase analysis has already been completed — see {$knowledgeBaseRelativePath} in this
        directory, and the original coverage instruction in {$instructionRelativePath}.

        Design test cases for the coverage gap identified in that analysis, following the exact
        conventions defined in the `boschifai-test-patterns` skill (TC-ID format
        `TC-<SOURCE>-<SEQ>`, the 9 mandatory fields per test case, Given/When/Then style steps).
        Write them to a file named `test_cases_<name>.md`, using the same `<name>` suffix as the
        instruction/knowledge-base files you just read (e.g. `coverage_instruction_abc123.md` →
        `test_cases_abc123.md`).

        End your response with exactly one line in this literal format, naming the single
        APPLICATION SOURCE file (the actual code under test — a controller, service, model,
        component, etc.) that most needs coverage — never a test file itself, even if one
        already exists for it (a later step edits/creates the test file from this path, so this
        must be the thing being tested, not the test):
        RECOMMENDED_TARGET_FILE: <path>
        PROMPT;
    }

    /**
     * Coverage mode's code-generation step — same shape as codeGeneration() but deliberately
     * without the "PHPUnit Feature tests" wording, since coverage mode can target any stack
     * `/boschifai-gen-component` detects. That command's own auto-detection (see
     * codebaseAnalysis()'s docblock) picks the right stack-specific skill and file-naming
     * convention — this prompt doesn't need to (and shouldn't) second-guess it.
     */
    public function coverageCodeGeneration(string $targetFilePath, string $testCasesRelativePath): string
    {
        return <<<PROMPT
        Test cases have already been designed and approved — see {$testCasesRelativePath} in this
        directory. Implement automated tests covering each test case already defined there, in
        whatever testing framework this repository already uses.

        /boschifai-gen-component --file {$targetFilePath}

        {$this->generatedTestFileSentinelInstruction()}
        PROMPT;
    }

    /**
     * Triggered by the "Fix failing tests" button at Gate 2 (local_execution_complete) — the
     * generated test file already exists and was already run locally; this asks Claude to make
     * it pass rather than regenerating it from scratch, so a small assertion/fixture mistake
     * doesn't cost a full re-generation.
     *
     * Deliberately scoped to ONLY {$generatedFilePath}, not the application code it exercises:
     * Gate 2's review (PushApprovalPanel.vue) only re-renders that one file's diff, so an edit to
     * application code here would push to production unreviewed — a real risk on a
     * bank-integrated, multi-tenant codebase. If a failure looks like a genuine application bug
     * rather than a mistake in the test itself, the instruction below asks Claude to leave that
     * assertion alone and flag it, instead of silently forcing a green run.
     *
     * @param array<int, array{name: string, status: string, message: ?string}> $failingTests
     */
    public function fixFailingTests(string $generatedFilePath, array $failingTests): string
    {
        $failureList = collect($failingTests)
            ->map(fn (array $test) => "- {$test['name']}: ".($test['message'] ?? '(no message captured)'))
            ->implode("\n");

        return <<<PROMPT
        Running the tests in {$generatedFilePath} locally just produced these failures:

        {$failureList}

        Fix ONLY {$generatedFilePath} so these tests pass — do not modify any other file. Read
        each failing assertion and the application code it exercises to understand what actually
        went wrong, then correct the test file: a wrong expected value, missing setup/fixture, a
        bad mock, an incorrect assertion, etc.

        Do not delete or weaken an assertion just to make the test report green. If a failure
        looks like it's exposing a real bug in the application code rather than a mistake in the
        test itself, leave that assertion as-is and add a comment above it explaining what you
        believe the underlying application bug is, so a human reviewer sees it at Gate 2.
        PROMPT;
    }

    /**
     * PushAndOpenPrJob's MCP-based push path (only used when github_mcp.pat is configured — see
     * that config's own docblock). Deliberately NOT an open-ended "push this and open a PR"
     * instruction: everything that matters — the branch, the one file already committed to it,
     * the exact PR title/body — is decided by deterministic PHP before this prompt is ever
     * built, and repeated back to Claude verbatim, because this is the one place in the whole
     * pipeline where an agentic session runs with live push credentials available to it (see
     * HeadlessClaudeInvoker's own docblock). Claude's only real job is to make the specific MCP
     * tool calls needed and report back what GitHub returned — not to decide what to commit, what
     * to say, or what else might be worth doing.
     *
     * Explicitly forbids `git push`/Bash for the actual push — confirmed as a real failure live:
     * without that instruction, Claude reached for the familiar `git push` first, which
     * HeadlessClaudeInvoker's `--permission-mode acceptEdits` correctly blocks as needing approval
     * (a network-mutating command, and there's no human in a headless run to approve it), and
     * Claude gave up rather than falling back to the MCP tools it actually had available. The
     * task is described as "write this file's content to a new branch" (an MCP `create_or_update_
     * file`/`push_files`-shaped operation) rather than "push my local commit", since the GitHub
     * MCP server has no tool that pushes an existing local git commit as-is — it creates commits
     * through GitHub's own API, which necessarily get a different SHA. PushAndOpenPrJob reads the
     * real head SHA back from GitHub's own PR response afterward rather than a local `git
     * rev-parse HEAD`, for exactly this reason.
     */
    public function pushViaGithubMcp(
        string $repoOwner,
        string $repoName,
        string $branchName,
        string $generatedFilePath,
        string $baseBranch,
        string $prTitle,
        string $prBody,
    ): string {
        return <<<PROMPT
        A local git commit already exists in this checkout on branch `{$branchName}`, changing
        exactly one file: `{$generatedFilePath}`. Nothing else is staged or committed.

        Do NOT run `git push`, `git`, or any other shell/Bash command to get this onto GitHub —
        this sandbox blocks unattended git network operations and there is no human available to
        approve one, so that will only stall. Use ONLY the GitHub MCP server's own tools (the
        `mcp__github__*` tools) for everything below, against `{$repoOwner}/{$repoName}` only:

        1. Read the current content of `{$generatedFilePath}` in this checkout — it already has
           the correct, final content; do not change it.
        2. Using the GitHub MCP server's tools, create a new branch named `{$branchName}` from
           base branch `{$baseBranch}`, then write that exact file content to `{$branchName}` as a
           single commit (e.g. a "create or update file" / "push files" tool) — do not touch any
           other file.
        3. Open a pull request from `{$branchName}` into `{$baseBranch}`, using EXACTLY this title
           and body, verbatim — do not edit, shorten, or add to either:

        TITLE: {$prTitle}

        BODY:
        {$prBody}

        Do not call any GitHub MCP tool other than what's needed for that one branch, that one
        file, and this pull request — no issues, no other repositories, no other branches, no
        repository or workflow settings changes.

        End your response with exactly these two lines, using the real values GitHub returned for
        the pull request you just opened:
        PR_URL: <html_url>
        PR_NUMBER: <number>
        PROMPT;
    }

    /**
     * "Build Knowledge Base" standalone action (SidebarNav) — unlike codebaseAnalysis() above,
     * there's no coverage instruction to read and no downstream test-case/code-gen steps waiting
     * on an Execution Recipe; this is a general "understand this repo" pass a human can trigger
     * on its own. Deliberately produces the SAME `codebase_knowledge_base_<id>.md` filename/
     * header-table convention as codebaseAnalysis() (reusing CodebaseKnowledgeBaseParser::
     * extractScore() and GitStatusDiffCollector's existing pattern unchanged) — just without an
     * instruction-specific gap assessment or the Execution Recipe section, which don't apply here.
     */
    public function standaloneKnowledgeBase(string $runId): string
    {
        return <<<PROMPT
        Explore this repository thoroughly enough to build a general understanding of it — not
        for any specific feature or instruction, just an overview a new team member (or a future
        Boschifai run against this repo) could use to get oriented quickly.

        Detect its tech stack and existing test conventions using the same signal-based approach
        `.claude/commands/boschifai-gen-component.md` already defines (check for Next.js/Koa BFF
        signals, Java REST/Spring Boot signals, Java event-driven/Solace signals, or PHP/Laravel
        signals, in that command's own priority order) — reuse its detection logic rather than
        guessing independently, and fall back to scanning existing test files if nothing matches.

        Write a file named `codebase_knowledge_base_{$runId}.md` containing, in this order:
        1. A header table with a row `| **Codebase Understanding Score** | NN% |` — your own
           honest 0-100 self-assessment of how well you were able to understand this repository.
        2. **Tech Stack** — language, framework, package manager, existing test framework/runner.
        3. **Architecture Overview** — the main directories/modules and what each is responsible for.
        4. **Existing Test Conventions** — how this repo already structures/names its tests, with
           a real example file path.
        5. **Coverage Gap Assessment** — a repo-wide summary of what's well-tested versus what
           looks under-tested, without inventing gaps you have no real evidence for.

        Do not modify any other file in this repository — only read, and write the one new file
        described above.
        PROMPT;
    }

    /**
     * "Build Skills" standalone action (SidebarNav) — automates the manual process
     * `.claude/skills/boschifai-project-template/SKILL.md`'s own instructions describe ("copy
     * this folder and customize for each project"): reads that template's structure at run time
     * (rather than duplicating its section list here, which would drift out of sync if the
     * template ever changes) and fills it in from a real scan of the connected repo. Reuses the
     * same understanding-score header-table convention as the knowledge-base artifact so
     * CodebaseKnowledgeBaseParser::extractScore() works unchanged for both.
     */
    public function standaloneProjectSkill(string $runId): string
    {
        return <<<PROMPT
        Read `.claude/skills/boschifai-project-template/SKILL.md` in this checkout — it defines
        the structure every Boschifai project-specific skill follows. Explore this repository
        thoroughly enough to fill that structure in with what you actually find here, rather than
        the template's own fill-in-the-blank placeholders.

        Write a file named `project_skill_{$runId}.md` containing, in this order:
        1. A header table with a row `| **Codebase Understanding Score** | NN% |` — your own
           honest 0-100 self-assessment of how well you were able to understand this repository.
        2. A YAML frontmatter block (fenced with `---`) with `name: boschifai-<a short project
           slug derived from this repo's name>` and a one-line `description`, matching the
           template's own frontmatter shape.
        3. Every section the template defines (Architecture Overview, Naming, Required Coverage,
           Environments, External Dependencies, Design System, Team Conventions, Known Patterns /
           Anti-Patterns), filled in with real findings from this repository. Where you genuinely
           find no evidence for a section, write "Not detected" rather than inventing one.

        Do not modify any other file in this repository — only read, and write the one new file
        described above.
        PROMPT;
    }
}
