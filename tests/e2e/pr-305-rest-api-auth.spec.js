// @ts-check
// eslint-disable-next-line @typescript-eslint/no-var-requires
const { test, expect } = require('@playwright/test');

/**
 * PR #305 REST API のログイン必須化に対する UI / e2e テスト。
 *
 * 匿名の第三者が REST API から請求書の件名やユーザー名を取得できないことと、
 * ログイン済みの管理画面・ブロックエディターが従来どおり利用できることを確認する。
 *
 * 実行例:
 *   WP_BASE_URL=http://localhost:9120 npx playwright test tests/e2e/pr-305-rest-api-auth.spec.js
 *
 * 匿名アクセスのテストでは globalSetup が作るログイン済み storageState を使わず、
 * Cookie が空のブラウザコンテキストを明示的に使用する。
 *
 * 漏洩確認に使う公開請求書は、手作業で用意された環境データに依存しないよう
 * テスト自身がログイン済み管理画面から作成し、終了時に必ず削除する。
 */

/**
 * テストが作成する請求書件名の接頭辞。
 *
 * 前回の中断で消し残った請求書も、次回実行時にまとめて片付ける目印にする。
 */
const TEST_INVOICE_PREFIX = 'e2e-pr305-private-invoice-';

/**
 * 匿名 REST API から漏れてはいけない、一意な公開請求書の件名。
 *
 * 同時期に別環境や別ジョブで実行しても衝突しにくい値にする。
 */
const PRIVATE_INVOICE_TITLE = `${TEST_INVOICE_PREFIX}${Date.now()}-${Math.random()
	.toString(36)
	.slice(2, 10)}`;

/**
 * 後始末の削除ループの反復上限。
 *
 * 削除できない投稿が残った場合に、無限ループではなく原因が分かるエラーで止める。
 */
const MAX_CLEANUP_ITERATIONS = 20;

/**
 * ローカル WordPress の管理者ログイン情報。
 *
 * globalSetup と同じく、環境変数が無い場合だけ wp-env の既定値を使用する。
 */
const ADMIN_USERNAME = process.env.WP_TEST_USERNAME || 'admin';
const ADMIN_PASSWORD = process.env.WP_TEST_PASSWORD || 'password';

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
 * ログイン済み管理画面から、漏洩確認用の請求書を公開する。
 *
 * 公開済みでなければ修正前の投稿 REST API にも現れず、漏洩確認が空振りするため、
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

	// 公開完了メッセージだけでなく、WordPress 内部の投稿ステータスも確認する。
	await expect(adminPage.locator('#message')).toContainText(
		/公開しました|published/i
	);
	await expect(adminPage.locator('#post_status')).toHaveValue('publish');
	await expect(adminPage.locator('#title')).toHaveValue(title);
}

/**
 * 接頭辞に一致する請求書を、公開一覧とゴミ箱の両方から完全に削除する。
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

			// 検索結果のうち、テスト用接頭辞を持つ行だけを後始末する。
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
					`テスト請求書（接頭辞 ${prefix}）を削除しきれませんでした。`
				);
			}
		}
	}
}

/**
 * クラシックエディターで書類を作成し、品目を保存できることを確認する。
 *
 * @param {import('@playwright/test').Page} page
 * @param {{ path: string, title: string, item: string }} document
 */
async function createDocumentWithItem(page, document) {
	await page.goto(document.path);

	// 請求書・見積書の編集画面と、テーマ固有の品目テーブルが表示されること。
	await expect(page.locator('#title')).toBeVisible();
	await expect(page.locator('table.admin-bill-table')).toBeVisible();

	await page.locator('#title').fill(document.title);
	await page.locator('input[name="bill_items[0][name]"]').fill(document.item);
	await page.locator('input[name="bill_items[0][count]"]').fill('2');
	await page.locator('input[name="bill_items[0][unit]"]').fill('式');
	await page.locator('input[name="bill_items[0][price]"]').fill('1500');

	// 実際に「公開」ボタンを押し、WordPress の保存完了画面まで待つ。
	await Promise.all([
		page.waitForNavigation({ waitUntil: 'domcontentloaded' }),
		page.locator('#publish').click(),
	]);
	await expect(page.locator('#message')).toBeVisible();

	// 保存後の編集画面でも入力値が保持され、REST 制限の副作用が無いこと。
	await expect(page.locator('#title')).toHaveValue(document.title);
	await expect(
		page.locator('input[name="bill_items[0][name]"]')
	).toHaveValue(document.item);
}

test.describe('PR #305 A: 未ログインの REST API から情報が漏れない', () => {
	// globalSetup のログイン Cookie を明示的に破棄し、匿名ブラウザで検証する。
	test.use({ storageState: { cookies: [], origins: [] } });

	/** @type {import('@playwright/test').BrowserContext | undefined} */
	let adminContext;
	/** @type {import('@playwright/test').Page | undefined} */
	let adminPage;

	test.beforeAll(async ({ browser }, testInfo) => {
		testInfo.setTimeout(60000);

		// A の匿名ページとは別に、globalSetup のログイン済み状態を持つ管理画面を用意する。
		adminContext = await browser.newContext({
			storageState: 'tests/e2e/.auth-state.json',
		});
		adminPage = await adminContext.newPage();

		try {
			// 前回中断時の消し残しを先に除去してから、今回専用の公開請求書を作る。
			await deleteInvoicesByPrefix(adminPage, TEST_INVOICE_PREFIX);
			await createPublishedInvoice(adminPage, PRIVATE_INVOICE_TITLE);
		} catch (error) {
			// セットアップ途中で失敗しても、作成済みデータがあれば必ず片付ける。
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

		// A の途中で assertion が失敗しても、テスト用請求書を完全削除して終了する。
		try {
			await deleteInvoicesByPrefix(adminPage, TEST_INVOICE_PREFIX);
		} finally {
			await adminContext.close();
		}
	});

	const anonymousRoutes = [
		{
			name: '投稿 API',
			path: '/wp-json/wp/v2/posts',
			forbiddenText: PRIVATE_INVOICE_TITLE,
		},
		{
			name: 'ユーザー API',
			path: '/wp-json/wp/v2/users',
			forbiddenText: ADMIN_USERNAME,
		},
		{
			name: 'REST API ルート',
			path: '/wp-json/',
			forbiddenText: PRIVATE_INVOICE_TITLE,
		},
		{
			name: '旧式 rest_route の投稿 API',
			path: '/?rest_route=/wp/v2/posts',
			forbiddenText: PRIVATE_INVOICE_TITLE,
		},
	];

	for (const route of anonymousRoutes) {
		test(`${route.name}は 401 になり、機密情報を返さない`, async ({ page }) => {
			// page.goto() で実ブラウザから直接 URL を開き、生の HTTP ステータスを確認する。
			const response = await page.goto(route.path, {
				waitUntil: 'domcontentloaded',
			});
			expect(response).not.toBeNull();
			// soft assertion にして、修正を外した red 検証でも件名漏洩の判定まで続ける。
			expect.soft(response.status()).toBe(401);

			// ブラウザに表示された JSON はテーマ固有の認証エラーだけであること。
			const body = await page.locator('body').innerText();
			expect.soft(body).toContain('bill_rest_not_logged_in');
			expect(body).not.toContain(route.forbiddenText);
		});
	}
});

test.describe('PR #305 B: ログイン済み管理画面の回帰確認', () => {
	// globalSetup で作成した管理者のログイン Cookie を使用する。
	test.use({ storageState: 'tests/e2e/.auth-state.json' });

	test('管理画面が表示される', async ({ page }) => {
		await page.goto('/wp-admin/');
		await expect(page.locator('#wpadminbar')).toBeVisible();
		await expect(page.locator('#adminmenu')).toBeVisible();
		expect(page.url()).toContain('/wp-admin/');
	});

	test('請求書の品目を入力して保存できる', async ({ page }) => {
		await createDocumentWithItem(page, {
			path: '/wp-admin/post-new.php',
			title: 'PR305 E2E 請求書 保存確認',
			item: 'PR305 請求書テスト品目',
		});
	});

	test('固定ページのブロックエディターでブロックを追加して保存でき、Console エラーが無い', async ({
		page,
	}) => {
		test.setTimeout(60000);

		// REST API が塞がれた場合に出る Console エラーとページ例外を、画面を開く前から収集する。
		const consoleErrors = [];
		const pageErrors = [];
		page.on('console', (message) => {
			if (message.type() === 'error') {
				consoleErrors.push(message.text());
			}
		});
		page.on('pageerror', (error) => pageErrors.push(error.message));

		await page.goto('/wp-admin/post-new.php?post_type=page', {
			waitUntil: 'domcontentloaded',
		});

		// 初回表示の案内モーダルがあれば閉じ、編集キャンバスを操作可能にする。
		const welcomeModal = page.locator('.components-modal__frame');
		if (await welcomeModal.isVisible()) {
			const closeButton = welcomeModal.getByRole('button', {
				name: /Close|閉じる/,
			});
			if (await closeButton.count()) {
				await closeButton.click();
			}
		}

		// WordPress の現行ブロックエディターは編集キャンバスを iframe 内に描画する。
		// iframe の中まで辿り、実際のタイトル・本文ブロックを操作する。
		const editorCanvas = page.frameLocator('iframe[name="editor-canvas"]');
		const title = editorCanvas.getByRole('textbox', { name: 'Add title' });
		await expect(title).toBeVisible({ timeout: 30000 });
		await title.fill('PR305 E2E ブロックエディター保存確認');

		// タイトルから Enter で本文ブロックへ移り、段落ブロックへ実際に文字を入力する。
		await title.press('Enter');
		await page.keyboard.type('PR305 REST 回帰確認ブロック');
		await expect(
			editorCanvas.locator('.block-editor-rich-text__editable').filter({
				hasText: 'PR305 REST 回帰確認ブロック',
			})
		).toBeVisible();

		// 公開操作を2段階ともクリックし、REST API 経由の保存完了通知を確認する。
		const publishButton = page.getByRole('button', {
			name: /^(Publish|公開)$/,
		});
		await publishButton.first().click();
		await expect(publishButton.last()).toBeVisible();
		await publishButton.last().click();
		await expect(
			page.locator('.components-snackbar__content').filter({
				hasText: /Page published\.|ページを公開しました。/,
			})
		).toBeVisible({ timeout: 30000 });

		// 保存完了までに REST 通信由来の Console エラーや JS 例外が無いこと。
		expect(pageErrors).toEqual([]);
		expect(consoleErrors).toEqual([]);
	});

	test('取引先・送付状の一覧と編集画面が表示され、保存できる', async ({
		page,
	}) => {
		await page.goto('/wp-admin/edit.php?post_type=client');
		await expect(page.locator('.wp-list-table')).toBeVisible();

		await page.goto('/wp-admin/post-new.php?post_type=client');
		await expect(page.locator('#title')).toBeVisible();
		await page.locator('#title').fill('PR305 E2E 取引先・送付状 保存確認');

		await Promise.all([
			page.waitForNavigation({ waitUntil: 'domcontentloaded' }),
			page.locator('#publish').click(),
		]);
		await expect(page.locator('#message')).toBeVisible();
		await expect(page.locator('#title')).toHaveValue(
			'PR305 E2E 取引先・送付状 保存確認'
		);
	});

	test('見積書の一覧と編集画面が表示され、品目を保存できる', async ({
		page,
	}) => {
		await page.goto('/wp-admin/edit.php?post_type=estimate');
		await expect(page.locator('.wp-list-table')).toBeVisible();

		await createDocumentWithItem(page, {
			path: '/wp-admin/post-new.php?post_type=estimate',
			title: 'PR305 E2E 見積書 保存確認',
			item: 'PR305 見積書テスト品目',
		});
	});
});

test.describe('PR #305 C: 未ログインのフロント表示とログイン', () => {
	// フロントのリダイレクトとログイン画面を、Cookie の無い状態から確認する。
	test.use({ storageState: { cookies: [], origins: [] } });

	test('未ログインでトップを開くとログイン画面へリダイレクトされる', async ({
		page,
	}) => {
		await page.goto('/');
		await expect(page.locator('#loginform')).toBeVisible();
		expect(page.url()).toContain('/wp-login.php');
	});

	test('ログイン画面から admin で実際にログインできる', async ({ page }) => {
		await page.goto('/wp-login.php');
		await expect(page.locator('#loginform')).toBeVisible();

		await page.locator('#user_login').fill(ADMIN_USERNAME);
		await page.locator('#user_pass').fill(ADMIN_PASSWORD);
		await page.locator('#wp-submit').click();

		await page.waitForURL('**/wp-admin/**');
		await expect(page.locator('#wpadminbar')).toBeVisible();
		await expect(page.locator('#adminmenu')).toBeVisible();
	});
});
