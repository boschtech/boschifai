<template>
    <div class="max-w-3xl mx-auto">
        <h1 class="text-2xl font-bold text-fg-strong mb-2">Welcome to Boschif<span class="text-[#D4AF37]">AI</span></h1>
        <p class="text-sm text-fg-muted mb-8">
            BoschifAI turns a requirement, written by hand, or discovered by scanning your own
            codebase, into reviewed, generated, and locally-verified automated tests, with a human
            approval gate before anything is pushed to GitHub.
        </p>

        <div class="grid gap-4 sm:grid-cols-2 mb-8">
            <div class="rounded-md border border-border bg-surface p-5">
                <h2 class="text-sm font-semibold text-fg-strong mb-1">Write Requirement</h2>
                <p class="text-xs text-fg-muted mb-3">
                    Describe what a specific file or controller should do, in plain language.
                    BoschifAI reviews it, designs test cases, and generates the tests.
                </p>
                <router-link :to="{ name: 'runs.create' }" class="text-sm text-primary underline">Start here →</router-link>
            </div>
            <div class="rounded-md border border-border bg-surface p-5">
                <h2 class="text-sm font-semibold text-fg-strong mb-1">Code Requirement</h2>
                <p class="text-xs text-fg-muted mb-3">
                    Point BoschifAI at a connected repo with a plain instruction — it scans the code
                    itself, finds what's under-tested, and proposes a requirement and test cases.
                </p>
                <router-link :to="{ name: 'runs.create.coverage' }" class="text-sm text-primary underline">Start here →</router-link>
            </div>
        </div>

        <h2 class="text-sm font-semibold text-fg-strong mb-2">How a run flows</h2>
        <ol class="list-decimal list-inside text-sm text-fg-muted space-y-1 mb-8">
            <li>Gap analysis or codebase scan — BoschifAI reviews the requirement, or reads the repo itself</li>
            <li><strong class="text-fg">Gate 1</strong> — you review the analysis and proposed test cases before anything is generated</li>
            <li>Test code generation, then a real local test run against the target repo</li>
            <li><strong class="text-fg">Gate 2</strong> — you review the generated code, execution results, and a confidence score before it's pushed</li>
            <li>Push to GitHub, CI runs, and a final confidence report is produced</li>
        </ol>

        <p class="text-sm text-fg-muted">
            See <router-link :to="{ name: 'runs.index' }" class="text-primary underline">All Runs</router-link>
            for everything in progress or completed, or
            <router-link :to="{ name: 'settings.github' }" class="text-primary underline">connect a repository</router-link>
            to get started.
        </p>
    </div>
</template>

<script>
export default {
    name: 'HomePage',
};
</script>
