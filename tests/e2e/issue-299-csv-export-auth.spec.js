// @ts-check
// eslint-disable-next-line @typescript-eslint/no-var-requires
const { test, expect } = require('@playwright/test');

/**
 * issue #299 CSV エクスポートの認証・CSRF ガードの e2e テスト。
 *
 * 修正前は未ログインの匿名リクエストで `/?action=csv_freee` を叩くだけで
 * 請求書データの CSV が HTTP 200 で返ってきていた。
 * このスペックでは、ログイン状態を持たないリクエストで CSV が返らないこと、
 * およびログイン済みでも nonce 無しでは CSV が返らないことを確認する。
 *
 * 実行例:
 *   WP_BASE_URL=http://localhost:9116 npx playwright test tests/e2e/issue-299-csv-export-auth.spec.js
 *
 * 他のスペックと異なり storageState を使わない（＝未ログイン状態で実行する）ため、
 * test.use({ storageState: ... }) は指定しない。
 */

/**
 * レスポンスが CSV エクスポートの中身になっていないことを確認する。
 *
 * @param {import('@playwright/test').APIResponse} response
 */
async function expectNotCsvExport(response) {
	const headers = response.headers();

	// CSV としてのレスポンスヘッダーが返っていないこと
	expect(headers['content-type'] || '').not.toContain('text/csv');
	expect(headers['content-disposition'] || '').not.toContain('export.csv');

	// 本文に CSV のヘッダー行（列名）が含まれていないこと
	const body = await response.text();
	expect(body).not.toContain('"収支区分"');
	expect(body).not.toContain('"取引No"');
}

test.describe('issue #299 CSV エクスポートの認証・CSRF ガード', () => {
	// 未ログイン状態を確実にするため、Cookie を持たない新規コンテキストで実行する
	test.use({ storageState: { cookies: [], origins: [] } });

	test('未ログインの匿名リクエストでは freee 用 CSV が返らない', async ({
		page,
	}) => {
		// リダイレクトを追わず、生のレスポンスを確認する
		const response = await page.request.get('/?action=csv_freee', {
			maxRedirects: 0,
		});

		// 未ログインの場合はログイン画面へのリダイレクトになる想定
		expect(response.status()).toBeGreaterThanOrEqual(300);
		expect(response.status()).toBeLessThan(400);
		expect(response.headers()['location'] || '').toContain('wp-login.php');

		await expectNotCsvExport(response);
	});

	test('未ログインの匿名リクエストでは MF 用 CSV が返らない', async ({
		page,
	}) => {
		const response = await page.request.get('/?action=csv_mf', {
			maxRedirects: 0,
		});

		expect(response.status()).toBeGreaterThanOrEqual(300);
		expect(response.status()).toBeLessThan(400);
		expect(response.headers()['location'] || '').toContain('wp-login.php');

		await expectNotCsvExport(response);
	});
});

test.describe('issue #299 ログイン済みでも nonce 無しでは CSV を返さない', () => {
	// ログイン済みの storageState を使う
	test.use({ storageState: 'tests/e2e/.auth-state.json' });

	test('nonce を付けずにアクセスすると CSV ではなくエラー画面が返る', async ({
		page,
	}) => {
		const response = await page.request.get('/?action=csv_freee');

		// CSRF とみなして 403 で中断されること
		expect(response.status()).toBe(403);

		// CSV は返らないこと
		await expectNotCsvExport(response);

		// nonce 不正時のエラーメッセージが表示されること
		const body = await response.text();
		expect(body).toContain('リンクの有効期限切れです。');
	});

	test('エクスポートフォームの nonce を付ければ CSV が返る', async ({
		page,
	}) => {
		// トップページのエクスポートボックスから nonce の hidden フィールド値を取得する
		await page.goto('/');
		const nonce = await page
			.locator('.export-box input[name="_wpnonce"]')
			.first()
			.inputValue();
		expect(nonce).toBeTruthy();

		// nonce 付きなら従来どおり CSV が返ること
		const response = await page.request.get(
			`/?action=csv_freee&_wpnonce=${encodeURIComponent(nonce)}`
		);
		expect(response.ok()).toBe(true);
		expect(response.headers()['content-disposition']).toContain('export.csv');
		expect(await response.text()).toContain('"収支区分"');
	});
});
