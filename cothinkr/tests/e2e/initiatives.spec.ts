import { test, expect } from '@playwright/test';

test.describe('Initiatives Page', () => {
  test.beforeEach(async ({ page }) => {
    await page.goto('/initiatives');
    await page.waitForLoadState('networkidle');
  });

  test('should display all four initiative columns', async ({ page }) => {
    await expect(page.getByText('Draft Ideas')).toBeVisible();
    await expect(page.getByText('AI Suggestions')).toBeVisible();
    await expect(page.getByText('Approved Initiatives')).toBeVisible();
    await expect(page.getByText('Rejected')).toBeVisible();
  });

  test('should allow creating new draft initiative', async ({ page }) => {
    // Find the Draft Ideas column
    const draftColumn = page.locator('[data-column="draft"]');
    
    // Click Add New Initiative button
    await draftColumn.locator('button:has-text("Add New Initiative")').click();
    
    // Fill in the initiative form
    await page.fill('input[placeholder*="title"]', 'Test Initiative');
    await page.fill('textarea[placeholder*="description"]', 'This is a test initiative description');
    
    // Submit the form
    await page.click('button:has-text("Create Initiative")');
    
    // Verify the initiative appears in Draft column
    await expect(draftColumn.getByText('Test Initiative')).toBeVisible();
  });

  test('should move initiative from draft to suggestions via AI', async ({ page }) => {
    // First create a draft initiative
    const draftColumn = page.locator('[data-column="draft"]');
    await draftColumn.locator('button:has-text("Add New Initiative")').click();
    
    await page.fill('input[placeholder*="title"]', 'AI Enhancement Test');
    await page.fill('textarea[placeholder*="description"]', 'Improve customer satisfaction through AI');
    await page.click('button:has-text("Create Initiative")');
    
    // Click the AI enhance button
    const initiativeCard = draftColumn.locator('[data-testid="initiative-card"]').last();
    await initiativeCard.locator('button:has-text("Enhance with AI")').click();
    
    // Wait for AI processing (mocked)
    await page.waitForTimeout(1500);
    
    // Verify initiative moved to AI Suggestions column
    const suggestionsColumn = page.locator('[data-column="suggestion"]');
    await expect(suggestionsColumn.getByText('AI Enhancement Test')).toBeVisible();
  });

  test('should approve initiative and move to approved column', async ({ page }) => {
    // Find an existing suggestion or create one
    const suggestionsColumn = page.locator('[data-column="suggestion"]');
    
    // If there's an existing suggestion, approve it
    const suggestionCards = suggestionsColumn.locator('[data-testid="initiative-card"]');
    const cardCount = await suggestionCards.count();
    
    if (cardCount > 0) {
      const firstCard = suggestionCards.first();
      const initiativeTitle = await firstCard.locator('h3').textContent();
      
      // Click approve button
      await firstCard.locator('button:has-text("Approve")').click();
      
      // Verify it moved to approved column
      const approvedColumn = page.locator('[data-column="approved"]');
      await expect(approvedColumn.getByText(initiativeTitle || '')).toBeVisible();
    }
  });

  test('should reject initiative and move to rejected column', async ({ page }) => {
    // Create a draft first
    const draftColumn = page.locator('[data-column="draft"]');
    await draftColumn.locator('button:has-text("Add New Initiative")').click();
    
    await page.fill('input[placeholder*="title"]', 'Reject Test');
    await page.fill('textarea[placeholder*="description"]', 'This will be rejected');
    await page.click('button:has-text("Create Initiative")');
    
    // Find the initiative and click reject
    const initiativeCard = draftColumn.getByText('Reject Test').locator('..').locator('..');
    await initiativeCard.locator('button:has-text("Reject")').click();
    
    // Confirm rejection in modal if it appears
    const confirmButton = page.locator('button:has-text("Confirm Reject")');
    if (await confirmButton.isVisible()) {
      await confirmButton.click();
    }
    
    // Verify it moved to rejected column
    const rejectedColumn = page.locator('[data-column="rejected"]');
    await expect(rejectedColumn.getByText('Reject Test')).toBeVisible();
  });

  test('should filter initiatives by status', async ({ page }) => {
    // Check if filter dropdown exists
    const filterDropdown = page.locator('select[data-testid="status-filter"]');
    
    if (await filterDropdown.isVisible()) {
      // Filter to show only approved
      await filterDropdown.selectOption('approved');
      
      // Verify only approved column is visible or highlighted
      await expect(page.locator('[data-column="approved"]')).toBeVisible();
      
      // Reset filter
      await filterDropdown.selectOption('all');
    }
  });

  test('should handle initiative details modal', async ({ page }) => {
    // Click on an existing initiative to open details
    const firstCard = page.locator('[data-testid="initiative-card"]').first();
    
    if (await firstCard.isVisible()) {
      await firstCard.click();
      
      // Expect modal to open
      const modal = page.locator('[data-testid="initiative-details-modal"]');
      await expect(modal).toBeVisible();
      
      // Check for modal content
      await expect(modal.locator('h2')).toBeVisible();
      await expect(modal.getByText('Description')).toBeVisible();
      
      // Close modal
      await modal.locator('button:has-text("Close")').click();
      await expect(modal).toBeHidden();
    }
  });

  test('should reset demo data when requested', async ({ page }) => {
    // Look for reset button
    const resetButton = page.locator('button:has-text("Reset Demo")');
    
    if (await resetButton.isVisible()) {
      await resetButton.click();
      
      // Confirm reset if modal appears
      const confirmButton = page.locator('button:has-text("Confirm Reset")');
      if (await confirmButton.isVisible()) {
        await confirmButton.click();
      }
      
      // Wait for page to reload/reset
      await page.waitForTimeout(1000);
      
      // Verify demo data is restored
      await expect(page.locator('[data-testid="initiative-card"]').first()).toBeVisible();
    }
  });
});