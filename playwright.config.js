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
		launchOptions: {
			// Chromium の「Paint Holding」（同一オリジンの遷移後、新しい画面の初回描画を
			// 保留する機能）を無効にする。
			// このヘッドレス Chromium では、フォーム送信（POST）で遷移したタブの描画が止まったまま
			// 戻らなくなる（requestAnimationFrame が呼ばれず、goto・reload でも戻らない。
			// WordPress の設定画面の保存だけでも再現する）。この状態では、要素の「安定待ち」を
			// 伴う click()・screenshot()・ログイン後の遷移待ちなどが実行ごとに別の場所で止まり、
			// 一式を流すと落ちるテストが毎回入れ替わっていた（issue #360）。
			// 無効にすると同じ操作で描画が止まらないことを、実測で確認している。
			args: ['--disable-features=PaintHolding'],
		},
	},
	projects: [
		{
			name: 'chromium',
			use: { ...devices['Desktop Chrome'] },
		},
	],
});
