import { test, expect } from '@playwright/test';

test.describe('Dashboard Page', () => {
  test.beforeEach(async ({ page }) => {
    await page.goto('/dashboard');
    await page.waitForLoadState('networkidle');
  });

  test('should load and display dashboard elements', async ({ page }) => {
    // Check for main dashboard title
    await expect(page.getByText('Strategic Dashboard')).toBeVisible();
    
    // Check for gauge components
    await expect(page.locator('[data-testid="vision-gauge"]')).toBeVisible();
    await expect(page.locator('[data-testid="progress-gauge"]')).toBeVisible();
    await expect(page.locator('[data-testid="budget-gauge"]')).toBeVisible();
    
    // Check for budget chart
    await expect(page.locator('[data-testid="budget-chart"]')).toBeVisible();
    
    // Check for journal panel
    await expect(page.getByText('AI Strategic Journal')).toBeVisible();
  });

  test('should navigate to different sections from dashboard', async ({ page }) => {
    // Navigate to Vision
    await page.click('text=Vision');
    await expect(page.locator('h1')).toHaveText('Strategic Vision');
    
    // Navigate back to Dashboard
    await page.click('text=Dashboard');
    await expect(page.getByText('Strategic Dashboard')).toBeVisible();
    
    // Navigate to Budget
    await page.click('text=Budget');
    await expect(page.locator('h1')).toHaveText('Annual Budget Overview');
  });

  test('should allow journal input and submission', async ({ page }) => {
    // Find and fill the journal textarea
    const journalInput = page.locator('textarea[placeholder*="strategic context"]');
    await journalInput.fill('Testing strategic insights generation...');
    
    // Submit the journal entry
    await page.click('button:has-text("Generate Insights")');
    
    // Wait for response (mocked)
    await page.waitForTimeout(1000);
    
    // Check that insights were generated
    const insightsPanel = page.locator('[data-testid="insights-panel"]');
    await expect(insightsPanel).toBeVisible();
  });

  test('should be responsive on mobile', async ({ page }) => {
    // Resize to mobile viewport
    await page.setViewportSize({ width: 375, height: 667 });
    
    // Check that mobile navigation is visible
    await expect(page.locator('[data-testid="mobile-menu-button"]')).toBeVisible();
    
    // Desktop sidebar should be hidden
    await expect(page.locator('[data-testid="desktop-sidebar"]')).toBeHidden();
    
    // Open mobile menu
    await page.click('[data-testid="mobile-menu-button"]');
    await expect(page.locator('[data-testid="mobile-sidebar"]')).toBeVisible();
  });

  test('should persist gauge data changes', async ({ page }) => {
    // Navigate to Vision page to change vision completion
    await page.click('text=Vision');
    
    // Fill in some vision content
    const peopleTextarea = page.locator('textarea[placeholder*="People"]').first();
    await peopleTextarea.fill('Updated vision for our people and culture...');
    
    // Go back to dashboard
    await page.click('text=Dashboard');
    
    // Check that vision gauge reflects the change (should be > 0%)
    const visionGauge = page.locator('[data-testid="vision-gauge"]');
    await expect(visionGauge).toBeVisible();
    
    // The exact percentage will depend on implementation, but should not be 0
    await expect(visionGauge.locator('text=0%')).not.toBeVisible();
  });
});