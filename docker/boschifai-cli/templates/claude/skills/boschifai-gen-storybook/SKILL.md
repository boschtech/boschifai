---
name: boschifai-gen-storybook
description: "Storybook story generation rules for component libraries using CSF3, play functions, and visual regression. Examples below use @crbn/ui (Emirates Carbon) as a reference — replace <component-library> tokens with your actual library."
---

# Storybook Test Generation — Component Library

## Storybook Configuration

- **Version**: 10.0.7
- **Framework**: `@storybook/nextjs-vite` (Next.js + Vite)
- **Config path**: `.storybook/` (`main.ts`, `preview.tsx`, `manager.ts`, `theme.ts`, `test-runner.ts`, `vitest.setup.ts`)
- **Story format**: Mixed — CSF2 (`StoryFn` + `.bind({})`) in `__stories__/*.stories.tsx` (legacy); CSF3 (`Meta`/`StoryObj` + object exports) in `__stories__/<system>/**/*.stories.tsx` (current). **All new stories MUST use CSF3.**
- **MDX stories**: Yes — `documentation/**/*.mdx` for intro/install/changelog pages
- **Addons installed**:
  - `@storybook/addon-a11y` — accessibility panel (globally applied)
  - `@storybook/addon-docs` — auto-generated docs pages
  - `@storybook/addon-designs` — Figma design link panel
  - `@storybook/addon-themes` — theme switching
  - `@storybook/addon-links` — cross-story links
  - `@storybook/addon-coverage` — code coverage
  - `@storybook/addon-vitest` — Vitest browser-mode integration
  - `@storybook/addon-postcss` — PostCSS support
  - `<visual-regression-tool>` — visual regression snapshots (e.g. Sauce Labs, Chromatic)
  - `<component-library-addon>` — library-specific addon (badges, toolbar filters)
  - `eslint-plugin-storybook` — story linting
- **Test runner**: `@storybook/test-runner`
- **Dev command**: `npm run storybook`
- **Build command**: `npm run build-storybook`
- **Visual test command**: `npm run test-storybook:visual`
- **Interaction test command**: `npm run test-storybook`

---

## Story File Convention

- **Location**: Separate folder `__stories__/` — stories are NOT co-located with components
  - Legacy components: `__stories__/*.stories.tsx`
  - Current components: `__stories__/<system>/<category>/<component>.stories.tsx`
  - Documentation pages: `documentation/**/*.mdx`
- **Pattern**: `*.stories.tsx` (primary), `*.stories.ts`, `*.stories.mdx` (docs only)
- **Shared story utilities**: Co-locate in a `utils/` subfolder next to the stories they serve

---

## Sidebar Organization

The sidebar is filtered by `tags`. Define design systems / audiences via tags:

| Tag | System | Audience |
|-----|---------|----------|
| `<system-tag>` | Current (new) | Primary design system |
| `<legacy-tag>` | Legacy | Legacy audience |
| `autodocs` | Both | Enables auto-generated docs tab |

- **Hierarchy pattern**: `<CATEGORY>/<SubCategory>/<ComponentName>`
- **All new stories MUST include `tags: ['autodocs', '<system-tag>']`.**

For Emirates Carbon (`@crbn/ui`): `tags: ['autodocs', 'carbon']`, title prefix `ATOMS/<Category>/...`

---

## Required States Per Component

Every new story file MUST cover:

| State | Required | Notes |
|-------|----------|-------|
| Default / Primary variant | **Yes** | First export, named `Default` or primary variant name |
| Variant sweep | **Yes** | One export per meaningful visual variant |
| Disabled | **Yes** for interactive | `disabled: true` arg |
| Loading | **Yes** for async actions | `loading: true` arg |
| Skeleton | **Yes** for data-driven | `skeleton: true` arg + skeleton context decorator if needed |
| Error | **Yes** for form inputs | `error: true` + `errorMessage: '...'` args |
| RTL layout | **Conditional** | Only when component has asymmetric layout |
| Interactive (play function) | **Yes** for CTA / form / selection | `play` pointing to a function in `component-tests/` |
| Boundary / long content | **Yes** | Wrap all-variants in a single render story if count > 6 |

**States commonly missing in new stories:**
- Empty / no-data state
- Focus-visible keyboard state
- Responsive breakpoint stories

---

## Args and Mock Data

- **Inline args**: Default approach — each story defines its own `args` object inline
- **Shared argTypes**: Extract to a `utils/<component>.stories.utils.tsx` file when two or more story files share controls:
  ```tsx
  export const sharedMeta = { parameters: { ... }, decorators: [...] };
  export const sharedArgTypes = { variant: { control: 'select', ... } };
  ```
- **Global webview argTypes**: Import and spread `globalArgTypes` for components that render inside a mobile webview:
  ```tsx
  import argTypes from '@common/utils/globalArgTypes.js';
  argTypes: {
    ...argTypes({ disable: true }),
    // component-specific argTypes...
  }
  ```
- **Static mock data**: Place in `__data__/<component>/` as JSON files; import directly in the story
- **ArgTypes convention**: Always include `description`, `control` type, and `table.category`. Use `table: { disable: true }` to hide internal/irrelevant props.

---

## Decorators

### Global Decorators (from `.storybook/preview.tsx`)

```tsx
import { getDecorators } from '<component-library>/storybook/preview/decorators';
import { SkeletonContext } from '../common/hooks/index';

decorators: getDecorators(SkeletonContext, {}),
```

The global decorator chain typically provides:
1. App/store provider
2. Skeleton context provider
3. RTL/LTR direction wrapper

### Story-Level Decorator Patterns

**Pattern 1 — Skeleton + App context**
```tsx
import { AppProvider } from '<component-library>/store/AppContext';
import { SkeletonContext } from '../../../common/hooks/useSkeleton';

const SkeletonDecorator = (StoryComp: any, { globals: { locale = 'en' } }: any = {}) => {
  const lang = locale.split('-')[0];
  const dir = ['ar', 'fa', 'he', 'ku', 'ps', 'ur', 'yi'].includes(lang) ? 'rtl' : 'ltr';
  return (
    <AppProvider key={`${lang}-${dir}`} initialState={{ ...initialState, lang: { lang, dir } }}>
      <SkeletonContext.Provider value={{ isSkeleton: false }}>
        <div dir={dir}><StoryComp /></div>
      </SkeletonContext.Provider>
    </AppProvider>
  );
};
```

**Pattern 2 — Background variant wrapper**
```tsx
decorators: [
  (Story, context) => {
    const backgroundColor = context.args.variant?.includes('on-dark-bg') ? '#333333' : '#ffffff';
    return (
      <div style={{ backgroundColor, padding: '15px' }}>
        <Story />
      </div>
    );
  },
],
```

**Pattern 3 — App context only (lightweight)**
```tsx
const SkeletonDecorator = (StoryComp: any) => (
  <AppProvider initialState={{ resources: {} }}>
    <StoryComp />
  </AppProvider>
);
```

---

## Interaction Tests (Play Functions)

### Architecture
Play functions are **never written inline in the story file**. They live in `component-tests/<category>/<component>-ct.js`:

```
component-tests/
  cta/
    cta-button-regular-ct.js
  forms/
  selection-control/
  indicator/
  ...
  testData.js    ← shared pixel/color constants
```

### Testing Library
```tsx
import { expect, within } from 'storybook/test';
```

### Standard play function shape
```tsx
export const PrimaryTest = async ({ canvasElement, step }) => {
  const canvas = within(canvasElement);
  const button = await canvas.findByTestId('<component-testid>');

  await step('Verify element exists and is enabled', async () => {
    await expect(button).toBeInTheDocument();
    await expect(button).toBeEnabled();
    await expect(button).toBeVisible();
  });

  await step('Verify visual styles', async () => {
    await expect(button).toHaveStyle({ height: '56px', 'background-color': 'rgb(215, 25, 33)' });
  });

  await step('Verify focus behaviour', async () => {
    button.focus();
    await expect(button).toHaveFocus();
    button.blur();
  });
};
```

### Wiring play into a story (CSF3)
```tsx
import { PrimaryTest } from '../../../component-tests/cta/cta-button-regular-ct.js';

export const Primary: ButtonStory = {
  args: { ... },
  play: PrimaryTest,
};
```

### `withPlay` utility (for arg-mutation tests)
```tsx
import { withPlay } from '../../../common/utils/with-play.js';

export const MyStory: Story = {
  play: withPlay(async ({ canvasElement, it, args }) => {
    await it('Renders default', async () => { /* ... */ });
    await it('Updates on prop change', async () => { /* ... */ });
  }),
};
```

---

## Visual Regression

- **Tool**: `<visual-regression-tool>` (e.g. Sauce Labs Visual, Chromatic)
- **Snapshot trigger**: `postVisit` hook in `.storybook/test-runner.ts`
- **Enable visual tests**: `ENABLE_VISUAL_TESTS=true` environment variable
- **Clip area**: `#storybook-root` (configured in `preview.tsx`)
- **Visual test command**: `npm run test-storybook:visual`
- **Timeout guard**: `postVisit` should have a timeout to prevent stalls

---

## Accessibility

- **Addon**: `@storybook/addon-a11y` — axe-core powered panel
- **Applied globally**: via `setProjectAnnotations` in `.storybook/vitest.setup.ts`
- **Unit test**: `jest-axe` is available for Jest-based a11y assertions in `__tests__/`
- **A11y attributes pattern**: Components expose `aria-label`, `aria-required`, loader aria props; stories MUST populate these:
  ```tsx
  args: {
    ariaLabel: 'Descriptive label for screen readers',
    loaderaria: 'The information is loading',
  }
  ```

---

## Parameters Convention

Every story `default export` MUST include:

```tsx
const meta: Meta<typeof MyComponent> = {
  title: '<CATEGORY>/<SubCategory>/<ComponentName>',
  component: MyComponent,
  tags: ['autodocs', '<system-tag>'],
  parameters: {
    backgrounds: {
      default: 'default',
      values: [
        { name: 'default', value: '#ffffff' },
        { name: 'dark', value: '#333333' },
      ],
    },
    design: {
      type: 'figma',
      url: '<figma-url-or-empty-string>',
    },
  },
  argTypes: { ... },
};
export default meta;
```

Optional but common:
```tsx
parameters: {
  badges: [BADGES.BETA],         // from '<component-library-addon>/constants'
  version: process.env.<LIB_VERSION_ENV>,
  test: {
    dangerouslyIgnoreUnhandledErrors: true,  // only for async components with known non-fatal errors
  },
}
```

For Emirates Carbon (`@crbn/ui`):
- Import `BADGES` from `@crbn/addon/constants` — use `BADGES.BETA`, `BADGES.STABLE`, or `BADGES.CRITICAL`
- `version: process.env.CRBN_UI`
- Figma URL pattern: `https://www.figma.com/design/tELlY0NsyWKCd0poI6MezJ/...`

---

## Design Token Usage in Stories

Use design system utility classes — never raw hex values in stories.

| Token type | Example | Notes |
|------------|---------|-------|
| Colors | `text-<color-token>`, `bg-<surface-token>` | From design system preset |
| Typography | `type-<scale>-<weight>` | Design system type scale |
| Spacing | Standard utility spacing | e.g. Tailwind spacing |
| Dark mode | Use `backgrounds` parameter with dark value | Avoid `dark:` utility variants in stories |

For Emirates Carbon: classes from `@crbn/tailwind-preset` (`text-ekred`, `text-txt`, `bg-surfacesunken`, etc.); CSS bundles: `carbon-theme.css`, `dnata-theme.css`.

---

## Story Generation Checklist

When generating a new story file:

- [ ] File lives at `__stories__/<system>/<category>/<component>.stories.tsx`
- [ ] Uses CSF3 (`Meta` + `StoryObj` + named object exports)
- [ ] `import type { Meta, StoryObj } from '@storybook/nextjs-vite'`
- [ ] `tags: ['autodocs', '<system-tag>']`
- [ ] `parameters.design.url` is a real Figma link (or placeholder `''`)
- [ ] `parameters.backgrounds` includes default and dark values
- [ ] All interactive components have `disabled` story
- [ ] All loading components have `loading: true` / `skeleton: true` story
- [ ] All form inputs have `error: true` + `errorMessage` story
- [ ] `ariaLabel` / `aria-*` args populated in every story
- [ ] Play function exists in `component-tests/<category>/` and is wired via `play:` prop
- [ ] Story-level decorator added if component needs App provider or Skeleton context
- [ ] Shared argTypes extracted to `utils/` if >1 story file in the same folder
- [ ] Global webview argTypes applied for webview-capable components

---

## Responsive / Viewport Stories

```tsx
export const Mobile: Story = {
  args: { ...Default.args },
  parameters: {
    viewport: { defaultViewport: 'mobile1' },  // 320px
  },
};

export const Tablet: Story = {
  args: { ...Default.args },
  parameters: {
    viewport: { defaultViewport: 'tablet' },   // 768px
  },
};

export const Desktop: Story = {
  args: { ...Default.args },
  parameters: {
    viewport: { defaultViewport: 'responsive' }, // 1280px
  },
};
```

Add viewport stories when: component has breakpoint-dependent layout changes,
hides/shows elements at certain widths, or explicitly specified in design.

---

## Empty / No-Data State Stories

```tsx
export const Empty: Story = {
  args: {
    items: [],
  },
};

export const NoResults: Story = {
  args: {
    items: [],
    emptyMessage: 'No results found',
  },
};
```

Add when component renders a list, receives async data that could be empty, or
has a conditional "no content" view.

---

## Focus-Visible / Keyboard Navigation Stories

```tsx
// component-tests/<category>/<component>-keyboard-ct.js
import { expect, within, userEvent } from 'storybook/test';

export const KeyboardFocusTest = async ({ canvasElement, step }) => {
  const canvas = within(canvasElement);

  await step('Tab navigates to component', async () => {
    await userEvent.tab();
    const element = canvas.getByRole('button');
    await expect(element).toHaveFocus();
  });

  await step('Focus ring is visible', async () => {
    const element = canvas.getByRole('button');
    const outlineStyle = window.getComputedStyle(element).outlineStyle;
    await expect(outlineStyle).not.toBe('none');
  });

  await step('Enter activates component', async () => {
    await userEvent.keyboard('{Enter}');
  });

  await step('Escape dismisses if applicable', async () => {
    await userEvent.keyboard('{Escape}');
  });
};
```

Add keyboard focus stories for all buttons, links, form inputs, selection
controls, and modal/drawer/popover components.

---

## Dark Theme Story Pattern

```tsx
export const OnDarkBackground: Story = {
  args: { ...Default.args, variant: 'on-dark-bg' },
  parameters: {
    backgrounds: { default: 'dark' },
  },
  decorators: [
    (Story) => (
      <div style={{ backgroundColor: '#333333', padding: '24px' }}>
        <Story />
      </div>
    ),
  ],
};
```

---

## Compound / Composite Component Stories

```tsx
export const ComposedForm: Story = {
  render: (args) => (
    <FormContainer {...args}>
      <InputField label="First Name" value="John" />
      <InputField label="Last Name" value="Doe" />
      <Button type="submit">Submit</Button>
    </FormContainer>
  ),
  args: {
    onSubmit: () => {},
  },
};
```

---

## Async Data / Mock API Stories

```tsx
import { http, HttpResponse, delay } from 'msw';

export const WithMockData: Story = {
  parameters: {
    msw: {
      handlers: [
        http.get('/api/items', async () => {
          await delay(500);
          return HttpResponse.json([{ id: '1', name: 'Item One' }]);
        }),
      ],
    },
  },
};
```

If MSW is not configured, use `loaders` instead:
```tsx
export const WithData: Story = {
  loaders: [async () => ({ data: await fetchMockData() })],
  render: (args, { loaded: { data } }) => <Component data={data} {...args} />,
};
```

---

## RTL Story Guidance

The global locale switcher handles RTL via the decorator. Explicit RTL stories
are needed when:
- Component has asymmetric padding/margin
- Component uses `transform: translateX()` or absolute positioning
- Component contains directional icons (arrows, chevrons) that must mirror

```tsx
export const RTL: Story = {
  args: { ...Default.args },
  globals: { locale: 'ar' },
  decorators: [
    (Story) => (
      <div dir="rtl" lang="ar">
        <Story />
      </div>
    ),
  ],
};
```

---

## Deprecation / Versioning Tags

```tsx
import { BADGES } from '<component-library-addon>/constants';

const meta: Meta<typeof OldComponent> = {
  title: '<CATEGORY>/Deprecated/OldComponent',
  tags: ['autodocs', '<system-tag>'],
  parameters: {
    badges: [BADGES.CRITICAL],
    docs: {
      description: {
        component: '⚠️ DEPRECATED — Use `NewComponent` instead. Will be removed in v3.0.',
      },
    },
  },
};
```

---

## Cross-Story Linking

```tsx
import { linkTo } from '@storybook/addon-links';

export const ButtonWithLink: Story = {
  args: {
    ...Default.args,
    onClick: linkTo('<CATEGORY>/Modal/Notification Modal', 'Default'),
  },
};
```
