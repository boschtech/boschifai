import { marked } from 'marked';
import DOMPurify from 'dompurify';

// Same rendering pipeline MarkdownView.vue already uses for on-screen documents — reused here
// so this standalone file renders AI-generated markdown identically (including gfm tables,
// which the score/recipe sections rely on) and stays sanitized against the same XSS surface
// (a prompt-injected or malformed artifact could otherwise smuggle a script tag into a report
// a human downloads and opens outside the app's own CSP).
marked.setOptions({ gfm: true, breaks: false });

function renderMarkdown(content) {
    if (!content) return '<p class="muted">Not available.</p>';
    return DOMPurify.sanitize(marked.parse(content));
}

function escapeHtml(value) {
    const div = document.createElement('div');
    div.textContent = value ?? '';
    return div.innerHTML;
}

function band(score) {
    if (score >= 80) return { label: 'Green', color: '#27ae60' };
    if (score >= 60) return { label: 'Yellow', color: '#d4a017' };
    return { label: 'Red', color: '#c0392b' };
}

function executionSummary(result) {
    if (!result) return '<p class="muted">Local execution has not completed.</p>';

    const rows = (result.tests ?? [])
        .map((t) => `<tr>
            <td>${escapeHtml(t.name)}</td>
            <td class="status-${escapeHtml(t.status)}">${escapeHtml(t.status)}</td>
            <td>${escapeHtml(t.message ?? '')}</td>
        </tr>`)
        .join('');

    return `
        <div class="stat-row">
            <span class="stat"><strong>${result.total}</strong> total</span>
            <span class="stat pass"><strong>${result.passed}</strong> passed</span>
            <span class="stat fail"><strong>${result.failed}</strong> failed</span>
            <span class="stat">${result.skipped} skipped</span>
            <span class="stat">${(result.duration_ms / 1000).toFixed(1)}s</span>
        </div>
        ${rows ? `<div class="table-wrap"><table><thead><tr><th>Test</th><th>Status</th><th>Message</th></tr></thead><tbody>${rows}</tbody></table></div>` : ''}
    `;
}

function confidenceSection(prePushConfidence) {
    if (!prePushConfidence) return '<p class="muted">Not available.</p>';

    const rows = [
        ['Testability / Codebase understanding', prePushConfidence.testability, 25],
        ['Test-case generation quality', prePushConfidence.test_case_quality, 20],
        ['Local execution', prePushConfidence.local_execution, 25],
    ]
        .map(([label, score, weight]) => `<tr><td>${label}</td><td>${score}%</td><td>${weight}%</td></tr>`)
        .join('');

    const { label, color } = band(prePushConfidence.composite);

    return `
        <div class="confidence-badge" style="color:${color};border-color:${color}">
            ${prePushConfidence.composite}% <span class="band-label">(${label})</span>
        </div>
        <p class="muted">
            CI has not run yet — nothing has been pushed. This score excludes the CI component and
            re-weights the other three so they still sum to 100; the final confidence report
            (generated after CI concludes) supersedes this one.
        </p>
        <div class="table-wrap"><table><thead><tr><th>Component</th><th>Score</th><th>Weight</th></tr></thead><tbody>${rows}</tbody></table></div>
    `;
}

/** Builds a self-contained HTML document (own theme-neutral CSS, no app assets) summarizing a
 * run's progress up to Gate 2 — everything a reviewer used to decide whether to push, plus a
 * pre-push confidence estimate, in a form that survives outside the app (email, ticket attachment,
 * audit record) — the requesting context motivating this is a regulated environment where a
 * human's push decision may need to be justified later. Local execution + confidence are the
 * two sections a reviewer actually decides from, so they're the first thing shown, right after
 * the identifying metadata — everything else (analysis, test cases, generated code) is
 * supporting detail below. */
export function buildPrePushReportHtml(run, generatedAt) {
    const isCoverage = run.run_type === 'coverage';

    return `<!doctype html>
<html>
<head>
<meta charset="utf-8">
<title>Boschifai Pre-Push Report — ${escapeHtml(run.repo_name)}</title>
<style>
    :root { --gold: #D4AF37; --ink: #1a1a2b; --muted: #6b6f7b; --border: #e6e6ec; --bg: #f4f5f8; }
    * { box-sizing: border-box; }
    body {
        font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
        max-width: 900px; margin: 0 auto; padding: 2.5rem 1.5rem 4rem;
        color: var(--ink); line-height: 1.55; background: var(--bg);
    }
    header.doc-header {
        border-bottom: 3px solid var(--gold);
        padding-bottom: 1.25rem; margin-bottom: 2rem;
    }
    h1 { font-size: 1.5rem; margin: 0 0 0.35rem; letter-spacing: -0.01em; }
    h1 .accent { color: var(--gold); }
    .muted { color: var(--muted); font-size: 0.9rem; }
    section.card {
        background: #fff; border: 1px solid var(--border); border-radius: 10px;
        padding: 1.5rem 1.75rem; margin-bottom: 1.5rem;
        box-shadow: 0 1px 2px rgba(20, 20, 40, 0.04);
    }
    section.card h2 {
        font-size: 1.05rem; margin: 0 0 1rem;
        display: flex; align-items: center; gap: 0.5rem;
    }
    section.card h2::before { content: ''; width: 4px; height: 1.1em; background: var(--gold); border-radius: 2px; display: inline-block; }
    .table-wrap { overflow-x: auto; border: 1px solid var(--border); border-radius: 8px; margin-top: 0.75rem; }
    table { border-collapse: collapse; width: 100%; font-size: 0.9rem; }
    th, td { text-align: left; padding: 0.55rem 0.85rem; }
    th { color: var(--muted); font-weight: 600; font-size: 0.78rem; text-transform: uppercase; letter-spacing: 0.03em; background: #fafafc; border-bottom: 1px solid var(--border); }
    tbody tr:not(:last-child) td { border-bottom: 1px solid var(--border); }
    tbody tr:nth-child(even) { background: #fbfbfd; }
    code, pre { font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-size: 0.85rem; }
    pre { background: #22222e; color: #e8e8f0; padding: 1rem 1.25rem; border-radius: 8px; overflow-x: auto; white-space: pre-wrap; word-break: break-word; }
    .meta-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 0.75rem 1.5rem; }
    .meta-grid .label { color: var(--muted); font-size: 0.78rem; text-transform: uppercase; letter-spacing: 0.03em; margin-bottom: 0.15rem; }
    .meta-grid .value { font-size: 0.92rem; word-break: break-word; }
    .stat-row { display: flex; gap: 1.5rem; flex-wrap: wrap; margin: 0 0 0.75rem; font-size: 0.9rem; }
    .stat strong { font-size: 1.05rem; }
    .stat.pass strong { color: #27ae60; }
    .stat.fail strong { color: #c0392b; }
    .status-passed { color: #27ae60; font-weight: 600; }
    .status-failed { color: #c0392b; font-weight: 600; }
    .status-skipped { color: #d4a017; font-weight: 600; }
    .confidence-badge {
        display: inline-flex; align-items: baseline; gap: 0.4rem;
        font-size: 1.6rem; font-weight: 700; border: 2px solid; border-radius: 999px;
        padding: 0.5rem 1.4rem; margin: 0 0 0.75rem;
    }
    .band-label { font-size: 0.95rem; font-weight: 600; }
    .warning { background: #fdf3e3; border: 1px solid #d4a017; border-radius: 8px; padding: 0.85rem 1.1rem; font-size: 0.9rem; margin-top: 1rem; }
    .back-to-top { display: inline-block; margin-top: 0.5rem; font-size: 0.85rem; color: var(--muted); text-decoration: none; }
    .back-to-top:hover { color: var(--ink); text-decoration: underline; }
    /* prose from marked's own output, kept theme-neutral (no external prose plugin needed for a
       standalone one-off file) */
    section.card :is(h1,h2,h3) { font-size: 1rem; margin: 1.25rem 0 0.5rem; }
    section.card > :is(h1,h2,h3):first-child { margin-top: 0; }
    section.card p { margin: 0.5rem 0; }
    section.card ul, section.card ol { padding-left: 1.25rem; }
</style>
</head>
<body>
    <header class="doc-header" id="top">
        <h1>Boschifai <span class="accent">Pre-Push</span> Report</h1>
        <p class="muted">Generated ${escapeHtml(generatedAt)} — reflects the run's state at Gate 2 (Local Execution complete, awaiting push approval).</p>
        <div class="meta-grid" style="margin-top: 1rem;">
            <div><div class="label">Repository</div><div class="value">${escapeHtml(run.repo_name)}</div></div>
            <div><div class="label">Run type</div><div class="value">${isCoverage ? 'Coverage scan' : 'Requirement'}</div></div>
            <div><div class="label">Target file</div><div class="value">${escapeHtml(run.target_file_path || '(determined by the pipeline)')}</div></div>
            <div><div class="label">Created</div><div class="value">${escapeHtml(run.created_at)}</div></div>
            <div><div class="label">Run ID</div><div class="value">${escapeHtml(run.id)}</div></div>
        </div>
    </header>

    <section class="card" id="local-execution">
        <h2>Local Execution Results</h2>
        ${executionSummary(run.execution_result)}
    </section>

    <section class="card" id="confidence">
        <h2>Pre-Push Confidence</h2>
        ${confidenceSection(run.pre_push_confidence)}
    </section>

    <section class="card" id="analysis">
        <h2>${isCoverage ? 'Codebase Analysis' : 'Testability Review'}</h2>
        ${renderMarkdown(isCoverage ? run.codebase_knowledge_base_markdown : run.testability_review_markdown)}
    </section>

    <section class="card" id="test-cases">
        <h2>${isCoverage ? 'Proposed Test Cases' : 'Test Cases'}</h2>
        ${renderMarkdown(run.test_cases_markdown)}
    </section>

    <section class="card" id="generated-code">
        <h2>Generated Code</h2>
        <p class="muted">${escapeHtml(run.generated_file_path)}</p>
        <pre><code>${escapeHtml(run.generated_code)}</code></pre>
        ${run.has_multi_tenant_test === false ? '<p class="warning"><strong>No cross-team access test detected.</strong> Review the generated file carefully before approving.</p>' : ''}
        <a class="back-to-top" href="#top">&uarr; Back to top</a>
    </section>
</body>
</html>`;
}

/** Triggers a browser download of the report as a standalone .html file — no server round-trip,
 * everything needed is already in the run object the page already fetched. */
export function downloadPrePushReport(run) {
    const html = buildPrePushReportHtml(run, new Date().toLocaleString());
    const blob = new Blob([html], { type: 'text/html' });
    const url = URL.createObjectURL(blob);

    const link = document.createElement('a');
    link.href = url;
    link.download = `boschifai-pre-push-report-${run.id}.html`;
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
    URL.revokeObjectURL(url);
}
