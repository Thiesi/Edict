import { defineConfig } from '@playwright/test';

// One fixed output path, with traces kept for failed tests only (see CLAUDE.md, Disk
// hygiene). One worker in file order: the tests share one admin and one database,
// and 2-passkey signs out.
export default defineConfig({
    testDir: 'tests/e2e',
    outputDir: '.scratch/playwright',
    globalSetup: './tests/e2e/global-setup.js',
    workers: 1,
    fullyParallel: false,
    reporter: 'list',
    timeout: 60_000,
    use: {
        baseURL: 'http://localhost:8080',
        storageState: '.scratch/playwright-admin.json',
        trace: 'retain-on-failure',
    },
    webServer: {
        command: 'php -S localhost:8080 -t public public/index.php',
        url: 'http://localhost:8080/healthz',
        reuseExistingServer: true,
        env: { PHP_CLI_SERVER_WORKERS: '4' },
    },
});
