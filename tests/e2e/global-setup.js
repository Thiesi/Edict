import { chromium } from '@playwright/test';

import { PASSWORD, totp } from './helpers.js';

// Signs in through the real form once. The tests reuse this session.
export default async function globalSetup(config) {
    const { baseURL, storageState } = config.projects[0].use;
    const browser = await chromium.launch();
    const page = await browser.newPage({ baseURL });

    await page.goto('/admin/login');
    await page.getByLabel('Username').fill('owner');
    await page.getByLabel('Password').fill(PASSWORD);
    await page.getByLabel('Authenticator code').fill(totp());
    await page.getByRole('button', { name: 'Sign in', exact: true }).click();
    await page.waitForURL('**/admin');

    await page.context().storageState({ path: storageState });
    await browser.close();
}
