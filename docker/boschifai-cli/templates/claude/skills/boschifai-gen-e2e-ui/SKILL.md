---
name: boschifai-gen-e2e-ui
description: "E2E test generation rules for browser-driven UI journeys using Playwright or Cypress"
---

# E2E Test Generation — UI (Playwright / Cypress)

Tech-specific rules for generating browser-driven E2E tests that exercise user journeys through a real frontend connected to a real backend. Extends the base `boschifai-gen-e2e` skill.

## Test Frameworks

| Type | Framework | Typical Version |
|------|-----------|-----------------|
| Primary | Playwright | 1.40+ |
| Alternative | Cypress | 13+ (if already in project) |
| Pattern | Page Object Model | — |
| State setup | REST API calls (not UI clicks) | — |

## Test File Structure

```
tests/
├── e2e/
│   ├── journeys/
│   │   ├── <journey-name>.spec.ts
│   │   └── <journey-name>.spec.ts
│   ├── pages/
│   │   ├── <PageName>.page.ts
│   │   └── <PageName>.page.ts
│   └── fixtures/
│       └── test-data.ts
```

## Page Object Template

```typescript
import { Page, Locator } from '@playwright/test';

export class ProductSearchPage {
  readonly page: Page;
  readonly searchButton: Locator;
  readonly queryInput: Locator;
  readonly categorySelect: Locator;
  readonly maxPriceInput: Locator;
  readonly productResultsList: Locator;

  constructor(page: Page) {
    this.page = page;
    this.searchButton = page.getByRole('button', { name: /search/i });
    this.queryInput = page.getByTestId('query-input');
    this.categorySelect = page.getByTestId('category-select');
    this.maxPriceInput = page.getByTestId('max-price-input');
    this.productResultsList = page.getByTestId('product-results');
  }

  async navigate() {
    await this.page.goto('/products/search');
  }

  async searchProducts(query: string, category: string, maxPrice: string) {
    await this.queryInput.fill(query);
    await this.categorySelect.selectOption(category);
    await this.maxPriceInput.fill(maxPrice);
    await this.searchButton.click();
  }

  async getFirstProductPrice(): Promise<string> {
    const firstResult = this.productResultsList.first();
    return await firstResult.getByTestId('price').textContent() ?? '';
  }
}
```

## Journey Test Template

```typescript
import { test, expect } from '@playwright/test';
import { ProductSearchPage } from '../pages/ProductSearchPage.page';

test.describe('Product Search Journey', () => {
  let searchPage: ProductSearchPage;

  test.beforeEach(async ({ page }) => {
    searchPage = new ProductSearchPage(page);
    await searchPage.navigate();
  });

  test('should display product results when a valid search is entered', async ({ page }) => {
    // Arrange: page already loaded
    // Act
    await searchPage.searchProducts('desk lamp', 'lighting', '100');

    // Assert
    await expect(page.getByTestId('product-results')).toBeVisible();
    await expect(page.getByTestId('product-results').first()).toContainText('$');
  });

  test('should show validation error when the search term is missing', async ({ page }) => {
    await searchPage.searchProducts('', 'lighting', '100');

    await expect(page.getByRole('alert')).toContainText('Search term is required');
  });

  test('should retain search inputs after navigating back', async ({ page }) => {
    await searchPage.searchProducts('desk lamp', 'lighting', '100');
    await page.goBack();

    await expect(searchPage.queryInput).toHaveValue('desk lamp');
    await expect(searchPage.categorySelect).toHaveValue('lighting');
  });

  test('should show error state when the search service is unavailable', async ({ page }) => {
    await page.route('**/api/products/search', route =>
      route.fulfill({ status: 503, body: '{}' }));

    await searchPage.searchProducts('desk lamp', 'lighting', '100');

    await expect(page.getByTestId('error-banner')).toBeVisible();
    await expect(page.getByTestId('retry-button')).toBeVisible();
  });

  test('should display correctly on mobile viewport', async ({ page }) => {
    await page.setViewportSize({ width: 320, height: 568 });
    await searchPage.navigate();

    await expect(page.getByTestId('mobile-search-form')).toBeVisible();
    await expect(page.getByTestId('desktop-search-form')).not.toBeVisible();
  });
});
```

## Required Scenarios Per UI Journey

| Category | What to Test | Min Count |
|----------|-------------|-----------|
| Happy path | Complete journey with valid inputs | 1 per journey |
| Validation | Submit with missing / invalid fields, verify error messages | 1 per required field group |
| Navigation | Forward, back, deep-link, refresh mid-journey | 1-2 per multi-step journey |
| Error recovery | API failure mid-journey, retry behavior visible to user | 1 per downstream call |
| State persistence | Data entered in step N is still present in step N+1 | 1 per multi-step journey |
| Responsive | Mobile (320px), tablet (768px), desktop (1280px) | 1 per journey if layout changes |
| Accessibility | Keyboard navigation, focus management, ARIA labels | 1 per interactive form |

## UI E2E Quality Rules

1. **No sleep/hardcoded waits** — use Playwright auto-waiting or `waitFor` conditions
2. **Role-based or test-id selectors only** — no CSS selectors, no XPath
3. **Independent** — each test resets state via API or navigation, not prior test state
4. **Fast setup** — use API calls (`request` fixture) to seed data, not UI clicks
5. **Network intercept for error cases** — use `page.route()` to simulate API failures rather than relying on environment faults
6. **One assertion focus per test** — test one behavior, not the entire page
7. **Retry-safe** — tests must be idempotent when re-run without cleanup

## Selector Priority

```typescript
// 1. Role (preferred)
page.getByRole('button', { name: /submit/i })
page.getByRole('heading', { name: 'Confirmation' })

// 2. Test ID (second choice)
page.getByTestId('product-results')

// 3. Label text (forms)
page.getByLabel('Search term')

// 4. Visible text (last resort for static content)
page.getByText('Search products')

// Never use:
// page.$('.btn-primary')       ← CSS selector
// page.$('#search-btn')        ← ID selector
// page.locator('//button[1]')  ← XPath
```

## State Setup via API

```typescript
import { test, expect, request } from '@playwright/test';

test.beforeEach(async ({ request }) => {
  // Seed booking via API — never via UI clicks
  await request.post('/api/test/seed', {
    data: { userId: 'test-user-1', bookingState: 'PENDING' }
  });
});

test.afterEach(async ({ request }) => {
  await request.delete('/api/test/seed/test-user-1');
});
```

## Playwright Config Template

```typescript
// playwright.config.ts
import { defineConfig, devices } from '@playwright/test';

export default defineConfig({
  testDir: './tests/e2e/journeys',
  fullyParallel: true,
  forbidOnly: !!process.env.CI,
  retries: process.env.CI ? 2 : 0,
  workers: process.env.CI ? 1 : undefined,
  reporter: 'html',
  use: {
    baseURL: process.env.BASE_URL ?? 'http://localhost:3000',
    trace: 'on-first-retry',
    screenshot: 'only-on-failure',
  },
  projects: [
    { name: 'chromium', use: { ...devices['Desktop Chrome'] } },
    { name: 'Mobile Chrome', use: { ...devices['Pixel 5'] } },
  ],
});
```

## Output File Naming

- Journey tests: `tests/e2e/journeys/<journey-name>.spec.ts`
- Page objects: `tests/e2e/pages/<PageName>.page.ts`
- Fixtures / test data: `tests/e2e/fixtures/test-data.ts`

## UI E2E Generation Checklist

- [ ] Page Object class per page/view involved
- [ ] Locators use roles or `data-testid` — no CSS or XPath
- [ ] Happy path: complete journey from start to confirmation
- [ ] Validation: each required field missing triggers error message
- [ ] Navigation: back/forward/refresh behaviour tested
- [ ] Error recovery: API failures intercepted with `page.route()`, retry visible
- [ ] State persistence: multi-step forms retain data between steps
- [ ] Responsive: mobile viewport test where layout differs
- [ ] State setup via API `request` fixture, not UI clicks
- [ ] No `page.waitForTimeout()` or `sleep()` calls
- [ ] `test.beforeEach` resets page or navigates fresh
- [ ] Each test has a single observable assertion goal
- [ ] Test names: `should <observable outcome> when <condition>`
