// @ts-check
const { defineConfig, devices } = require('@playwright/test');

/**
 * Playwright の設定ファイル
 * ベース URL は環境変数 WP_BASE_URL で切り替え可能（CI 対応）
 */
module.exports = defineConfig({
	testDir: './tests/e2e',
	// テスト実行前に WordPress へのログインを済ませて storageState を保存する
	globalSetup: './tests/e2e/global-setup.js',
	timeout: 30000,
	expect: {
		timeout: 5000,
	},
	fullyParallel: false,
	forbidOnly: !!process.env.CI,
	retries: process.env.CI ? 2 : 0,
	workers: 1,
	reporter: 'list',
	use: {
		// ベース URL は環境変数で切り替え可能（CI とローカルのポート違いに対応）
		baseURL: process.env.WP_BASE_URL || 'http://localhost:8895',
		trace: 'on-first-retry',
		screenshot: 'only-on-failure',
	},
	projects: [
		{
			name: 'chromium',
			use: { ...devices['Desktop Chrome'] },
		},
	],
});
