// @ts-check
// eslint-disable-next-line @typescript-eslint/no-var-requires
const { test, expect } = require('@playwright/test');

/**
 * PR #330 REST API の閲覧制限を「ログイン必須」から「書類の閲覧権限（寄稿者以上）必須」へ
 * 格上げしたことに対する UI / e2e テスト。
 *
 * 確認する観点:
 *   - 未ログインは従来どおり 401 のまま（403 に変わっていないこと。回帰確認）
 *   - ログイン済みでも書類の閲覧権限が無い購読者は 403 になり、書類の件名やユーザー名が
 *     レスポンスに含まれないこと
 *   - 寄稿者（閲覧権限あり）は従来どおり 200 で書類を取得できること（デグレしていないこと）
 *   - 例外1「本人自身のアプリケーションパスワード」エンドポイントは、購読者でも通ること
 *     （プロフィール画面の新規発行・削除操作を壊さないため）
 *   - 例外2「本人自身のユーザー情報（/wp/v2/users/me の完全一致）」は、購読者でも通ること
 *     （B-2案。麗美の実機確認で、コアの user-profile 系 JS が管理画面のどのページでも
 *     /wp/v2/users/me?context=edit・PUT /wp/v2/users/me を呼んでおり、この例外が無いと
 *     購読者が管理画面のどのページを開いてもコンソールに 403 と TypeError が出続けることが
 *     判明したため追加した）
 *
 * 実行例:
 *   WP_BASE_URL=http://localhost:9113 npx playwright test tests/e2e/pr-330-rest-api-permission.spec.js
 *
 * 購読者・寄稿者アカウント（billsub / billcon）は PR #319 の e2e スペックと共通の
 * ローカル確認用アカウントを使う。存在しない環境では事前に用意しておく必要がある。
 *
 * テストデータ（書類）は投稿 ID を決め打ちせず、テスト自身が一意な件名で作成・削除する
 * （過去に投稿 ID の決め打ちで環境依存の失敗が起きた経緯があるため）。
 *
 * このファイルは麗美（UIテスト担当）が別 worktree で作成したものを取り込み、
 * B-2案（/wp/v2/users/me の例外追加）に伴う「購読者で /wp/v2/users/me が通る」ケースを追記した。
 */

// ローカルの確認用アカウント。PR #319 の e2e スペックで用意済みのものと共通。
const USERS = {
	subscriber: { login: 'billsub', password: 'password' },
	contributor: { login: 'billcon', password: 'password' },
};

/**
 * ローカル WordPress の管理者ログイン情報。
 *
 * globalSetup と同じく、環境変数が無い場合だけ wp-env の既定値を使用する。
 */
const ADMIN_USERNAME = process.env.WP_TEST_USERNAME || 'admin';
const ADMIN_PASSWORD = process.env.WP_TEST_PASSWORD || 'password';

/**
 * テストが作成する書類（投稿）件名の接頭辞。
 *
 * 前回の中断で消し残った投稿も、次回実行時にまとめて片付ける目印にする。
 */
const TEST_INVOICE_PREFIX = 'e2e-pr330-invoice-';

/**
 * REST API から漏れてはいけない、一意な書類の件名。
 *
 * 同時期に別環境や別ジョブで実行しても衝突しにくい値にする。
 */
const TEST_INVOICE_TITLE = `${TEST_INVOICE_PREFIX}${Date.now()}-${Math.random()
	.toString(36)
	.slice(2, 10)}`;

/**
 * 後始末の削除ループの反復上限。
 *
 * 削除できない投稿が残った場合に、無限ループではなく原因が分かるエラーで止める。
 */
const MAX_CLEANUP_ITERATIONS = 20;

/**
 * 指定ユーザーでログインする。
 *
 * @param {import('@playwright/test').Page} page
 * @param {{ login: string, password: string }} user
 */
async function loginAs(page, user) {
	await page.context().clearCookies();
	await page.goto('/wp-login.php', { waitUntil: 'domcontentloaded' });
	await page.locator('#user_login').fill(user.login);
	await page.locator('#user_pass').fill(user.password);

	await Promise.all([
		page.waitForNavigation({ waitUntil: 'domcontentloaded' }),
		page.locator('#wp-submit').click(),
	]);

	// 認証エラーでログインフォームに留まっていないことを先に確認する。
	await expect(page.locator('#login_error')).toHaveCount(0);
}

/**
 * 要素をページ内でネイティブにクリックする。
 *
 * 管理画面の行アクションはホバー前に見えないため、後始末では表示状態に依存せず
 * リンク本来の動作を実行する。
 *
 * @param {import('@playwright/test').Locator} locator
 */
async function clickNative(locator) {
	await locator.evaluate((element) => {
		if (element instanceof HTMLElement) {
			element.click();
		}
	});
}

/**
 * ログイン済み管理画面から、REST API 確認用の書類（投稿）を公開する。
 *
 * 公開済みでなければ REST API の投稿一覧にも現れず、確認が空振りするため、
 * 保存後の投稿ステータスが publish であることまで明示的に確認する。
 *
 * @param {import('@playwright/test').Page} adminPage
 * @param {string} title
 */
async function createPublishedInvoice(adminPage, title) {
	await adminPage.goto('/wp-admin/post-new.php', {
		waitUntil: 'domcontentloaded',
	});

	await expect(adminPage.locator('#title')).toBeVisible();
	await adminPage.locator('#title').fill(title);

	await Promise.all([
		adminPage.waitForNavigation({ waitUntil: 'domcontentloaded' }),
		adminPage.locator('#publish').click(),
	]);

	await expect(adminPage.locator('#message')).toContainText(
		/公開しました|published/i
	);
	await expect(adminPage.locator('#post_status')).toHaveValue('publish');
	await expect(adminPage.locator('#title')).toHaveValue(title);
}

/**
 * 接頭辞に一致する書類を、公開一覧とゴミ箱の両方から完全に削除する。
 *
 * 前回の中断で残った投稿も対象にし、各一覧が空になるまで反復する。
 *
 * @param {import('@playwright/test').Page} adminPage
 * @param {string} prefix
 */
async function deleteInvoicesByPrefix(adminPage, prefix) {
	const cleanupTargets = [
		{
			path: `/wp-admin/edit.php?post_status=all&s=${encodeURIComponent(
				prefix
			)}`,
			action: 'span.trash a',
		},
		{
			path: `/wp-admin/edit.php?post_status=trash&s=${encodeURIComponent(
				prefix
			)}`,
			action: 'span.delete a',
		},
	];

	for (const target of cleanupTargets) {
		for (let i = 0; i < MAX_CLEANUP_ITERATIONS; i++) {
			await adminPage.goto(target.path, {
				waitUntil: 'domcontentloaded',
			});

			const matchingRow = adminPage
				.locator('#the-list tr')
				.filter({
					has: adminPage.locator('.row-title', {
						hasText: prefix,
					}),
				})
				.first();

			if ((await matchingRow.count()) === 0) {
				break;
			}

			const actionLink = matchingRow.locator(target.action);
			await expect(actionLink).toHaveCount(1);
			await Promise.all([
				adminPage.waitForNavigation({ waitUntil: 'domcontentloaded' }),
				clickNative(actionLink),
			]);

			if (i === MAX_CLEANUP_ITERATIONS - 1) {
				throw new Error(
					`テスト書類（接頭辞 ${prefix}）を削除しきれませんでした。`
				);
			}
		}
	}
}

test.describe.serial('PR #330 REST API の閲覧権限', () => {
	// 各テストは未ログイン状態から開始し、前のテストの権限を持ち越さない。
	test.use({ storageState: { cookies: [], origins: [] } });

	/** @type {import('@playwright/test').BrowserContext | undefined} */
	let adminContext;
	/** @type {import('@playwright/test').Page | undefined} */
	let adminPage;

	test.beforeAll(async ({ browser }, testInfo) => {
		testInfo.setTimeout(60000);

		adminContext = await browser.newContext({
			storageState: 'tests/e2e/.auth-state.json',
		});
		adminPage = await adminContext.newPage();

		try {
			// 前回中断時の消し残しを先に除去してから、今回専用の公開書類を作る。
			await deleteInvoicesByPrefix(adminPage, TEST_INVOICE_PREFIX);
			await createPublishedInvoice(adminPage, TEST_INVOICE_TITLE);
		} catch (error) {
			try {
				await deleteInvoicesByPrefix(adminPage, TEST_INVOICE_PREFIX);
			} finally {
				await adminContext.close();
			}
			throw error;
		}
	});

	test.afterAll(async ({}, testInfo) => {
		testInfo.setTimeout(60000);

		if (!adminPage || !adminContext) {
			return;
		}

		try {
			await deleteInvoicesByPrefix(adminPage, TEST_INVOICE_PREFIX);
		} finally {
			await adminContext.close();
		}
	});

	test('未ログインで投稿APIにアクセスすると401になり、403には変わっていない（回帰確認）', async ({
		page,
	}) => {
		const response = await page.goto('/wp-json/wp/v2/posts', {
			waitUntil: 'domcontentloaded',
		});
		expect(response).not.toBeNull();
		expect(response && response.status()).toBe(401);

		const body = await page.locator('body').innerText();
		expect(body).toContain('bill_rest_not_logged_in');
		expect(body).not.toContain(TEST_INVOICE_TITLE);
	});

	const subscriberForbiddenRoutes = [
		{
			name: '投稿API',
			path: '/wp-json/wp/v2/posts',
			forbiddenText: TEST_INVOICE_TITLE,
		},
		{
			name: '検索API',
			path: '/wp-json/wp/v2/search?search=',
			forbiddenText: TEST_INVOICE_TITLE,
		},
		{
			name: 'ユーザーAPI',
			path: '/wp-json/wp/v2/users',
			forbiddenText: ADMIN_USERNAME,
		},
	];

	for (const route of subscriberForbiddenRoutes) {
		test(`購読者で${route.name}にアクセスすると403になり、機密情報を返さない`, async ({
			page,
		}) => {
			await loginAs(page, USERS.subscriber);

			const response = await page.goto(route.path, {
				waitUntil: 'domcontentloaded',
			});
			expect(response).not.toBeNull();
			expect(response && response.status()).toBe(403);

			const body = await page.locator('body').innerText();
			expect(body).toContain('bill_rest_forbidden');
			expect(body).not.toContain(route.forbiddenText);
		});
	}

	test('寄稿者で投稿APIにアクセスすると200で書類を取得できる（デグレしていないこと）', async ({
		page,
	}) => {
		await loginAs(page, USERS.contributor);

		const response = await page.goto('/wp-json/wp/v2/posts', {
			waitUntil: 'domcontentloaded',
		});
		expect(response).not.toBeNull();
		expect(response && response.status()).toBe(200);

		const body = await page.locator('body').innerText();
		expect(body).toContain(TEST_INVOICE_TITLE);
	});

	test('購読者が自分自身のアプリケーションパスワード一覧APIにアクセスすると200になる（例外1）', async ({
		page,
	}) => {
		await loginAs(page, USERS.subscriber);

		// プロフィール画面の「アプリケーションパスワード」欄は wp.apiFetch（nonce ミドルウェア付き）
		// 経由で REST API を呼び出す。X-WP-Nonce を付けない素の GET だと、コアの
		// rest_cookie_check_errors() が「nonce の無いCookie認証リクエスト」を匿名扱いに
		// リセットしてしまい、本 PR の変更と無関係に 401 になってしまう
		// （wp_set_current_user(0) される）。実際の画面と同じ経路を再現するため、
		// プロフィール画面が読み込む nonce（wpApiSettings.nonce）を使って呼び出す。
		await page.goto('/wp-admin/profile.php', {
			waitUntil: 'domcontentloaded',
		});
		const nonce = await page.evaluate(() => {
			// @ts-ignore wpApiSettings はコアがプロフィール画面にインラインで出力するグローバル
			return window.wpApiSettings && window.wpApiSettings.nonce;
		});
		expect(nonce).toBeTruthy();

		const response = await page.request.get(
			'/wp-json/wp/v2/users/me/application-passwords',
			{ headers: { 'X-WP-Nonce': nonce } }
		);
		expect(response.status()).toBe(200);

		const body = await response.text();
		expect(body).not.toContain('bill_rest_forbidden');
		expect(body).not.toContain('rest_not_logged_in');
	});

	test('購読者が自分自身のユーザー情報（/wp/v2/users/me）にアクセスすると200になる（例外2・B-2案）', async ({
		page,
	}) => {
		// 麗美の実機確認で発覚した不具合の再現テスト。コアの
		// wp-includes/js/dist/preferences-persistence.js が、購読者を含む全ての
		// ログインユーザーの管理画面で GET /wp/v2/users/me?context=edit を常時呼んでおり、
		// この例外が無いと購読者は管理画面のどのページを開いてもコンソールに 403 と
		// TypeError が出続けていた。ここではダッシュボードを開いて実際にその挙動が
		// 再現しないことまで確認する（アプリケーションパスワードのケースと違い、
		// wpApiSettings.nonce を使わずコアと同じ context=edit 付きの素のGETで検証できる。
		// /wp/v2/users/me の GET は permission_callback が '__return_true' で
		// nonce 検証の対象外のため、匿名扱いへのリセットが起きない）。
		await loginAs(page, USERS.subscriber);

		await page.goto('/wp-admin/index.php', {
			waitUntil: 'domcontentloaded',
		});

		const response = await page.request.get(
			'/wp-json/wp/v2/users/me?context=edit'
		);
		expect(response.status()).toBe(200);

		const body = await response.text();
		expect(body).not.toContain('bill_rest_forbidden');
		expect(body).not.toContain('rest_not_logged_in');

		// 一覧（/wp/v2/users）が引き続き塞がれていることも同じテスト内で固定化する。
		// 一覧までB-2案の例外に含めてしまう後退が最も避けたい事故のため、
		// 「meは通る」だけでなく「一覧は通らない」を対で確認する。
		const listResponse = await page.request.get('/wp-json/wp/v2/users');
		expect(listResponse.status()).toBe(403);
	});
});
