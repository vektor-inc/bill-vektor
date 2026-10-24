// @ts-check

const { expect } = require('@playwright/test');

/**
 * ログイン済み storageState まわりの共通ヘルパー。
 *
 * global-setup.js が保存するログイン済み Cookie（tests/e2e/.auth-state.json）を
 * 使う場面が複数のスペックにまたがっており、パスのリテラルや
 * 「newContext(storageState) → newPage() → try/finally で close()」という
 * 定型処理が各ファイルに散らばっていたため、ここへ集約する。
 *
 * 前提データの存在確認（タイトル・件数非依存の絞り込みURLで見つかるか等）は
 * このファイルの役割ではなく require-test-data.js が担う。
 */

// global-setup.js が保存するログイン済み storageState のパス。
const AUTH_STATE_PATH = 'tests/e2e/.auth-state.json';

/**
 * ログイン済み storageState で新しい BrowserContext / Page を作り、渡された関数を実行してから
 * 必ず context を閉じる。
 *
 * `browser.newContext({ storageState: ... })` → `newPage()` → `try` / `finally` で `close()` という
 * 同じ定型処理が pr-297・pr-298・pr-319 の beforeAll に重複していたため、ここに集約した。
 *
 * @param {import('@playwright/test').Browser} browser
 * @param {(page: import('@playwright/test').Page) => Promise<void>} fn ログイン済み Page を使って行う処理。
 * @return {Promise<void>}
 */
async function withAuthenticatedPage(browser, fn) {
	const context = await browser.newContext({ storageState: AUTH_STATE_PATH });
	const page = await context.newPage();
	try {
		await fn(page);
	} finally {
		await context.close();
	}
}

/**
 * 指定ユーザーで wp-login.php からログインする（各スペック共通）。
 *
 * これまで pr-319・pr-330 が `waitForNavigation` とクリックを並べて待つ形、
 * issue-299 が別の形で、同じ処理を個別に持っていた（issue #360）。
 * 次の2点でログイン画面まわりの取りこぼしを防ぐ。
 *  - ユーザー名・パスワードを入力したあと、値が入っていることを確かめる。
 *    実行中に入力欄が空のまま／別の欄に値が入ったままになることがあったため、
 *    確認できるまで入力をやり直す。
 *  - ログイン後の遷移は、非推奨の waitForNavigation ではなく waitForURL
 *    （ログイン画面から離れたことを条件にする）で待つ。
 *
 * @param {import('@playwright/test').Page} page
 * @param {{ login: string, password: string }} user
 * @return {Promise<void>}
 */
async function loginAs(page, user) {
	// 前のユーザーのセッションを完全に捨ててからログインする
	await page.context().clearCookies();
	await page.goto('/wp-login.php', { waitUntil: 'load' });

	const userField = page.locator('#user_login');
	const passField = page.locator('#user_pass');
	await expect(async () => {
		await userField.fill(user.login);
		await passField.fill(user.password);
		await expect(userField).toHaveValue(user.login, { timeout: 1000 });
		await expect(passField).toHaveValue(user.password, { timeout: 1000 });
	}).toPass({ timeout: 15000 });

	await Promise.all([
		page.waitForURL((url) => !url.pathname.includes('wp-login.php'), {
			waitUntil: 'domcontentloaded',
		}),
		page.locator('#wp-submit').click(),
	]);

	// 認証エラーでログインフォームに留まっていないことを確認する。
	await expect(page.locator('#login_error')).toHaveCount(0);
}

module.exports = { AUTH_STATE_PATH, withAuthenticatedPage, loginAs };
