import { chromium } from '@playwright/test';

const browser = await chromium.launch({ headless: true });
const page = await browser.newPage({ viewport: { width: 1920, height: 1080 } });

// Login first via API
await page.request.post('http://localhost:4321/api/auth/login', {
  data: { email: 'admin@reisinger.pictures', password: 'your-password' }
});

await page.goto('http://localhost:4321/settings');
await page.waitForTimeout(3000);
await page.screenshot({ path: 'settings.png', fullPage: true });
await browser.close();
