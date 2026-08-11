// @ts-check

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

module.exports = { AUTH_STATE_PATH, withAuthenticatedPage };
