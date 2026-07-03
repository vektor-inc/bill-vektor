// @ts-check
// eslint-disable-next-line @typescript-eslint/no-var-requires
const { chromium } = require('@playwright/test');
const fs = require('fs');

/**
 * Playwright globalSetup
 * テスト実行前に一度だけ WordPress にログインして
 * storageState（ログイン済み Cookie）を保存する
 * 既に auth-state.json が存在する場合はスキップする
 *
 * @param {import('@playwright/test').FullConfig} config
 */
module.exports = async function globalSetup( config ) {
	const baseURL = config.projects[0].use.baseURL || 'http://localhost:8895';
	const stateFile = 'tests/e2e/.auth-state.json';

	// 既にログイン済み Cookie が保存されていればスキップ
	if ( fs.existsSync(stateFile) ) {
		return;
	}

	const browser = await chromium.launch();

	// ログイン処理中に例外が発生してもブラウザプロセスが残り続けないよう、
	// close() を finally で確実に実行する
	try {
		const context = await browser.newContext();
		const page = await context.newPage();

		// ログインページに移動
		await page.goto( baseURL + '/wp-login.php' );
		await page.waitForLoadState('domcontentloaded');

		// ログインフォームに入力して送信
		// 認証情報をコードにハードコードしないよう、環境変数から取得する
		// （未設定の場合は WordPress のローカル開発環境でよく使われる初期値にフォールバックする）
		const username = process.env.WP_TEST_USERNAME || 'admin';
		const password = process.env.WP_TEST_PASSWORD || 'password';
		await page.locator('#user_login').fill(username);
		await page.locator('#user_pass').fill(password);
		await page.locator('#wp-submit').click();

		// ダッシュボードへのリダイレクトを待つ
		await page.waitForURL('**/wp-admin/**');

		// ログイン済み Cookie を所定のパスに保存
		await context.storageState({ path: 'tests/e2e/.auth-state.json' });
	} finally {
		await browser.close();
	}
};
