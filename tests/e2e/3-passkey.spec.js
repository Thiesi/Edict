import { expect, test } from '@playwright/test';

import { PASSWORD, totp } from './helpers.js';

// A real browser ceremony against Chromium's virtual authenticator. Signs out at the
// end, so this file runs last.
test('adds a passkey, then signs in with it', async ({ page, context }) => {
    const problems = [];
    page.on('pageerror', (error) => problems.push(error.message));

    const cdp = await context.newCDPSession(page);
    await cdp.send('WebAuthn.enable');
    await cdp.send('WebAuthn.addVirtualAuthenticator', {
        options: {
            protocol: 'ctap2',
            transport: 'internal',
            hasResidentKey: true,
            hasUserVerification: true,
            isUserVerified: true,
            automaticPresenceSimulation: true,
        },
    });

    await page.goto('/admin/passkeys');
    await page.getByLabel('Name').fill('Virtual key');
    await page.getByLabel('Password').fill(PASSWORD);
    await page.getByLabel('Authenticator code').fill(totp(1));
    await page.getByRole('button', { name: 'Add passkey' }).click();
    await expect(page.locator('main li')).toContainText('Virtual key');

    await page.goto('/admin');
    await page.getByRole('button', { name: 'Sign out', exact: true }).click();
    await expect(page).toHaveURL(/\/admin\/login$/);

    await page.getByRole('button', { name: 'Sign in with a passkey' }).click();
    await expect(page).toHaveURL(/\/admin$/);
    await expect(page.locator('main')).toContainText('Signed in as owner');

    expect(problems).toEqual([]);
});
