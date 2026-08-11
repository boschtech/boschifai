import typography from '@tailwindcss/typography';

/**
 * Design tokens copied from Bosch Technologies' marketing site
 * (/Users/garthbosch/projects/bosch-technologies/css/styles.css): gold/amber brand accent,
 * Inter type, 6/10/16px radius scale, soft-tinted badges, gold-glow hover shadow on cards.
 *
 * That site is dark-only (hardcoded #000/#111 etc., no light variant). To support a light/dark
 * toggle here, every surface/text/border color is a semantic token backed by a CSS custom
 * property (defined in resources/css/app.css) rather than a literal hex value — `.dark` on
 * <html> swaps the property values, so `bg-surface`/`text-fg`/`border-border` etc. resolve to
 * the right shade in both themes without needing `dark:` prefixes sprinkled through templates.
 * The brand/semantic colors below (primary, danger, warning, success) are the literal Bosch
 * values, used as-is in both themes as combined with alpha (e.g. `bg-primary/10`) — the same
 * technique Bosch's own `.score-low/-mid/-high` classes use for theme-agnostic tinted badges.
 *
 * @type {import('tailwindcss').Config}
 */
export default {
    darkMode: 'class',
    content: [
        './vendor/laravel/framework/src/Illuminate/Pagination/resources/views/*.blade.php',
        './storage/framework/views/*.php',
        './resources/**/*.blade.php',
        './resources/**/*.js',
        './resources/**/*.vue',
    ],
    theme: {
        extend: {
            fontFamily: {
                sans: ['Inter', 'system-ui', '-apple-system', 'Segoe UI', 'sans-serif'],
            },
            colors: {
                bg: 'rgb(var(--color-bg) / <alpha-value>)',
                surface: 'rgb(var(--color-surface) / <alpha-value>)',
                'surface-alt': 'rgb(var(--color-surface-alt) / <alpha-value>)',
                border: 'rgb(var(--color-border) / <alpha-value>)',
                fg: 'rgb(var(--color-fg) / <alpha-value>)',
                'fg-strong': 'rgb(var(--color-fg-strong) / <alpha-value>)',
                'fg-muted': 'rgb(var(--color-fg-muted) / <alpha-value>)',
                primary: {
                    DEFAULT: '#b8961c',
                    dark: '#96790f',
                    light: '#d4b44a',
                },
                accent: {
                    DEFAULT: '#b8961c',
                    light: '#e8d48a',
                },
                danger: '#c0392b',
                warning: '#d4a017',
                success: '#27ae60',
            },
            borderRadius: {
                sm: '6px',
                md: '10px',
                lg: '16px',
            },
            boxShadow: {
                card: '0 4px 12px rgb(0 0 0 / 0.08)',
                'card-hover': '0 10px 30px rgb(184 150 28 / 0.15)',
            },
            transitionDuration: {
                DEFAULT: '200ms',
            },
            // Re-points the typography plugin's own CSS vars at our theme tokens so
            // .prose output (MarkdownView.vue) follows light/dark automatically — same
            // token set as everything else, no separate dark-mode prose config needed.
            typography: {
                DEFAULT: {
                    css: {
                        '--tw-prose-body': 'rgb(var(--color-fg))',
                        '--tw-prose-headings': 'rgb(var(--color-fg-strong))',
                        '--tw-prose-lead': 'rgb(var(--color-fg))',
                        '--tw-prose-links': '#b8961c',
                        '--tw-prose-bold': 'rgb(var(--color-fg-strong))',
                        '--tw-prose-counters': 'rgb(var(--color-fg-muted))',
                        '--tw-prose-bullets': 'rgb(var(--color-border))',
                        '--tw-prose-hr': 'rgb(var(--color-border))',
                        '--tw-prose-quotes': 'rgb(var(--color-fg-strong))',
                        '--tw-prose-quote-borders': '#b8961c',
                        '--tw-prose-captions': 'rgb(var(--color-fg-muted))',
                        '--tw-prose-code': 'rgb(var(--color-fg-strong))',
                        '--tw-prose-pre-code': 'rgb(var(--color-fg))',
                        '--tw-prose-pre-bg': 'rgb(var(--color-surface-alt))',
                        '--tw-prose-th-borders': 'rgb(var(--color-border))',
                        '--tw-prose-td-borders': 'rgb(var(--color-border))',
                        maxWidth: 'none',
                    },
                },
            },
        },
    },
    plugins: [typography],
};
