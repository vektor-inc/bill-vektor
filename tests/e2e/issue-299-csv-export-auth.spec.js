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
		// コアの nonce エラー画面に復帰リンクが出る条件（同一サイトのリファラー）を満たすため、
		// 先にトップページを開いてその URL をリファラーとして送る
		await page.goto('/');
		const referer = page.url();

		const response = await page.request.get('/?action=csv_freee', {
			headers: { referer },
		});

		// CSRF とみなして 403 で中断されること
		expect(response.status()).toBe(403);

		// CSV は返らないこと
		await expectNotCsvExport(response);

		// wp_nonce_ays() によるコア標準のエラー画面が返ること
		// （サイトのロケールに依存しないよう、英語・日本語のどちらでも通るようにする）
		const body = await response.text();
		expect(body).toMatch(
			/The link you followed has expired\.|リンクの有効期限切れです。/
		);

		// 袋小路にならないよう、元のページへ戻る復帰リンクが含まれること
		expect(body).toMatch(/Please try again\.|もう一度お試しください。/);
	});

	test('不正な nonce を付けた場合も CSV ではなく 403 が返る', async ({
		page,
	}) => {
		const response = await page.request.get(
			'/?action=csv_mf&_wpnonce=invalidnonce'
		);

		expect(response.status()).toBe(403);
		await expectNotCsvExport(response);
	});

	/**
	 * エクスポート経路ごとの期待値。
	 *
	 * MF 経路は mb_convert_encoding() で SJIS へ変換してから出力するため、
	 * 文字コードと本文の検証方法が freee 経路と異なる。
	 * Cache-Control（nocache_headers()）は両経路で検証する。
	 */
	const EXPORT_ROUTES = [
		{
			label: 'freee',
			action: 'csv_freee',
			charset: 'utf-8',
			// SJIS 変換を挟まないのでそのままデコードできる
			decode: (buffer) => buffer.toString('utf-8'),
			firstColumn: '"収支区分"',
		},
		{
			label: 'MF',
			action: 'csv_mf',
			charset: 'shift_jis',
			// mb_convert_encoding() で SJIS になっているため SJIS としてデコードする
			decode: (buffer) => new TextDecoder('shift_jis').decode(buffer),
			firstColumn: '"取引No"',
		},
	];

	for (const route of EXPORT_ROUTES) {
		test(`エクスポートフォームの nonce を付ければ ${route.label} 用 CSV が返り、キャッシュ抑止ヘッダーが付く`, async ({
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
				`/?action=${route.action}&_wpnonce=${encodeURIComponent(nonce)}`
			);
			expect(response.ok()).toBe(true);
			expect(response.headers()['content-disposition']).toContain('export.csv');
			expect(response.headers()['content-type']).toContain(route.charset);

			// 経路ごとの文字コードでデコードし、ヘッダー行が出力されていること
			const csv = route.decode(await response.body());
			expect(csv).toContain(route.firstColumn);

			// 請求データがキャッシュされないよう nocache_headers() が効いていること
			// （MF 経路は mb_convert_encoding() を挟むため、両経路とも検証する）
			const cacheControl = response.headers()['cache-control'] || '';
			expect(cacheControl).toContain('no-store');
			expect(cacheControl).toContain('no-cache');
		});
	}
});
