import { test, expect, Page } from '@playwright/test';
import { execSync } from 'node:child_process';

const adminUser = process.env.WP_ADMIN_USER || 'admin';
const adminPass = process.env.WP_ADMIN_PASSWORD || 'changeme';

const adminLoginUrl = '/wp-login.php';

async function ensureHomepage(page: Page) {
  await page.goto('/', { waitUntil: 'domcontentloaded' });
  await expect(page.locator('body')).toBeVisible();
}

async function loginAsAdmin(page: Page) {
  await page.goto(adminLoginUrl);
  await page.locator(selectors.usernameInput).fill(adminUser);
  await page.locator(selectors.passwordInput).fill(adminPass);
  await page.locator(selectors.loginButton).click();
  await expect(page.locator('#wpadminbar')).toBeVisible();
}

const selectors = {
  passwordInput: '#user_pass',
  usernameInput: '#user_login',
  loginButton: '#wp-submit',
};

test.describe('Storbystand smoke tests', () => {
  test('homepage renders with default content', async ({ page }) => {
    await ensureHomepage(page);
    await expect(page.locator('body')).toContainText('Hello world!', { timeout: 5000 });
    await expect(page.locator('body')).toContainText('Storbystand Local');
  });

  test('admin login accepts default credentials', async ({ page }) => {
    await loginAsAdmin(page);
    await expect(page).toHaveURL(/\/wp-admin\//);
  });

  test('Elementor dashboards render key sections', async ({ page }) => {
    await loginAsAdmin(page);
    const publisherData = getElementorTemplateData(7);
    expect(publisherData).toContain('Upcoming Shifts');
    expect(publisherData).toContain('Open Shifts');

    const adminTemplate = getElementorTemplateData(8);
    expect(adminTemplate).toContain('Shift Control Center');
    expect(adminTemplate).toContain('Roster Overview');
  });
});

function getElementorTemplateData(postId: number): string {
  return execSync(`bin/wp.sh post meta get ${postId} _elementor_data`, {
    cwd: process.cwd(),
    encoding: 'utf-8',
  });
}
