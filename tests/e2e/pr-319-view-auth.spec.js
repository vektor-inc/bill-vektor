// @ts-check
// eslint-disable-next-line @typescript-eslint/no-var-requires
const { test, expect } = require('@playwright/test');
const { TEST_DATA, verifyDocumentVisible } = require('./test-data-319.js');
const { withAuthenticatedPage } = require('./require-test-data');

/**
 * PR #319 フロント側の書類閲覧権限に関する e2e テスト。
 *
 * 購読者への 403 表示と情報漏えい防止、ログアウトを挟む復帰導線、
 * 寄稿者・管理者・未ログイン時の回帰、狭い画面でのレイアウトを確認する。
 *
 * 参照する書類の投稿ID・確認用アカウントは決め打ちにせず、
 * create-test-data-pr-319.php が書き出すマニフェスト（test-data-319.js 経由）から読む。
 * 実行前にテストデータを作成しておくこと（未作成の場合は require 時に案内付きで落ちる）:
 *   npx wp-env run cli --env-cwd="wp-content/themes/$(basename "$PWD")" wp eval-file tests/e2e/create-test-data-pr-319.php
 *
 * 実行例:
 *   WP_BASE_URL=http://localhost:9126 npx playwright test tests/e2e/pr-319-view-auth.spec.js
 */

// ローカルの確認用アカウント。create-test-data-pr-319.php が冪等に作成した
// billsub（購読者）・billcon（寄稿者）をマニフェスト経由で参照する。
// 管理者は wp-env の既定管理者（globalSetup と同じ規約）を使う。
const USERS = {
	subscriber: TEST_DATA.users.subscriber,
	contributor: TEST_DATA.users.contributor,
	administrator: {
		login: process.env.WP_TEST_USERNAME || 'admin',
		password: process.env.WP_TEST_PASSWORD || 'password',
	},
};

// テスト対象の書類（権限確認用に create-test-data-pr-319.php が作成したもの）
const DOCUMENT = TEST_DATA.document;

// 購読者を遮断する必要があるフロント側の全経路。
const FORBIDDEN_PATHS = [
	'/',
	DOCUMENT.url,
	'/?feed=rss2',
	'/wp-sitemap.xml',
	'/?s=test',
	'/?post_type=estimate',
	`${DOCUMENT.url}&embed=true`,
];

// 書類から案内ページへ漏れてはいけない既知の文言。
// タイトル・合計金額はテストデータ次第で変わるためマニフェストから取り、
// 「合計」「御請求」「振込口座」はどの書類にも共通する frame-bill.php 上の固定文言なので直接指定する。
const DOCUMENT_LEAK_TEXTS = [
	DOCUMENT.title,
	DOCUMENT.total,
	'合計',
	'御請求',
	'振込口座',
];

// 各テストは未ログイン状態から開始し、前のテストの権限を持ち越さない。
test.use({ storageState: { cookies: [], origins: [] } });

/**
 * 正規表現の特殊文字をエスケープする。
 *
 * マニフェスト由来の値（ログイン名など）を new RegExp() に埋め込む際に使う。
 * このスクリプトが作成するログイン名は英数字のみだが、その保証はマニフェストの
 * 生成側（create-test-data-pr-319.php）にあり spec 側では検証していないため、
 * 埋め込み側でも無条件にエスケープしておく。
 *
 * @param {string} value エスケープ対象の文字列。
 * @return {string} 正規表現の特殊文字をエスケープした文字列。
 */
function escapeRegExp(value) {
	return value.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
}

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
 * 403 案内ページに書類情報や書類由来のリンクが含まれないことを確認する。
 *
 * @param {import('@playwright/test').Page} page
 * @param {import('@playwright/test').Response | null} response
 */
async function expectForbiddenPage(page, response) {
	expect(response).not.toBeNull();
	expect(response && response.status()).toBe(403);

	await expect(
		page.getByRole('heading', {
			level: 1,
			name: 'この画面を表示する権限がありません',
		})
	).toBeVisible();
	// WordPress の言語パック有無により標準ロール名は日本語／英語のどちらにもなる。
	// ログイン名はマニフェストから読んだ購読者アカウントを使う。正規表現にする必要があるのは
	// ロール名部分の (?:購読者|Subscriber) だけなので、ログイン名側はエスケープしてから埋め込む。
	await expect(page.locator('body')).toContainText(
		new RegExp(
			`現在 ${escapeRegExp(USERS.subscriber.login)}（(?:購読者|Subscriber)）としてログインしています。`
		)
	);
	await expect(
		page.getByRole('link', { name: 'ログアウトしてログインし直す' })
	).toBeVisible();

	const bodyText = await page.locator('body').innerText();
	for (const leakText of DOCUMENT_LEAK_TEXTS) {
		expect(bodyText, `書類の文言「${leakText}」が漏れていないこと`).not.toContain(
			leakText
		);
	}

	const title = await page.title();
	expect(title).toMatch(
		/^この画面を表示する権限がありません - .+/
	);
	expect(title).not.toContain(DOCUMENT.title);
	expect(title).not.toContain('御中');

	const html = await page.content();
	expect(html).not.toMatch(/rel=["']canonical["']/i);
	expect(html).not.toMatch(/(?:type=["']application\/json\+oembed["']|oembed)/i);
	expect(html).not.toMatch(/rel=["']prev["']/i);
	expect(html).not.toMatch(/rel=["']next["']/i);

	// サイドバーと、テーマで使われうるパンくずの代表的なセレクターをまとめて確認する。
	await expect(
		page.locator('#sub, #breadcrumb, .breadcrumb, .breadcrumbs')
	).toHaveCount(0);
}

/**
 * リダイレクトチェーンに含まれる 3xx 応答数を数える。
 *
 * @param {import('@playwright/test').Response | null} response
 * @return {number}
 */
function countRedirects(response) {
	let redirects = 0;
	let request = response ? response.request() : null;

	while (request && request.redirectedFrom()) {
		redirects++;
		request = request.redirectedFrom();
	}

	return redirects;
}

test.describe.serial('PR #319 書類の閲覧権限', () => {
	// このテストは「購読者に見せない」という否定形の検証が主なため、
	// 対象の書類が実在しないと DOCUMENT_LEAK_TEXTS の検証が何も比較せず素通りして
	// PASS してしまう（空振り PASS）。すべてのテストの前に、権限を持つ管理者アカウントで
	// 書類が意図した内容（タイトル・合計金額）で表示できることを確認しておく。
	test.beforeAll(async ({ browser }) => {
		await withAuthenticatedPage(browser, verifyDocumentVisible);
	});

	test('購読者は全フロント経路で 403 になり、書類情報が漏れない', async ({
		page,
	}) => {
		await loginAs(page, USERS.subscriber);

		for (const path of FORBIDDEN_PATHS) {
			const response = await page.goto(path, {
				waitUntil: 'domcontentloaded',
			});
			await expectForbiddenPage(page, response);
		}
	});

	test('案内ボタンでログアウトしてログイン画面へ復帰し、無限リダイレクトしない', async ({
		page,
	}, testInfo) => {
		await loginAs(page, USERS.subscriber);
		await page.goto('/', { waitUntil: 'domcontentloaded' });

		const [response] = await Promise.all([
			page.waitForNavigation({ waitUntil: 'domcontentloaded' }),
			page
				.getByRole('link', {
					name: 'ログアウトしてログインし直す',
				})
				.click(),
		]);

		const redirectCount = countRedirects(response);
		console.log(`ログアウト後のリダイレクト回数: ${redirectCount}`);
		await testInfo.attach('redirect-count.txt', {
			body: Buffer.from(String(redirectCount)),
			contentType: 'text/plain',
		});

		expect(page.url()).toContain('/wp-login.php');
		await expect(page.locator('#loginform')).toBeVisible();
		// 通常のログアウト遷移は少数のリダイレクトで完了する。上限を置き、往復ループも検知する。
		expect(redirectCount).toBeLessThanOrEqual(3);
	});

	test('寄稿者と管理者は一覧・明細を閲覧でき、管理者のRSSと管理画面も従来どおり', async ({
		page,
	}) => {
		for (const user of [USERS.contributor, USERS.administrator]) {
			await loginAs(page, user);

			const listResponse = await page.goto('/', {
				waitUntil: 'domcontentloaded',
			});
			expect(listResponse && listResponse.status()).toBe(200);
			await expect(page.locator('body')).toContainText(DOCUMENT.title);
			await expect(
				page.getByRole('heading', {
					name: 'この画面を表示する権限がありません',
				})
			).toHaveCount(0);

			const singleResponse = await page.goto(DOCUMENT.url, {
				waitUntil: 'domcontentloaded',
			});
			expect(singleResponse && singleResponse.status()).toBe(200);
			await expect(page.locator('body')).toContainText('合計金額');
			await expect(page.locator('body')).toContainText('御請求');
			await expect(page.locator('body')).toContainText('振込口座');
		}

		const feedResponse = await page.request.get('/?feed=rss2');
		expect(feedResponse.status()).toBe(200);
		expect(feedResponse.headers()['content-type'] || '').toContain(
			'application/rss+xml'
		);
		expect(await feedResponse.text()).toContain(DOCUMENT.title);

		const adminResponse = await page.goto('/wp-admin/', {
			waitUntil: 'domcontentloaded',
		});
		expect(adminResponse && adminResponse.status()).toBe(200);
		await expect(page.locator('body.wp-admin')).toBeVisible();
		await expect(page.locator('#wpadminbar')).toBeVisible();
	});

	test('未ログインのトップは redirect_to 付きでログイン画面へ移動する', async ({
		page,
	}) => {
		await page.context().clearCookies();
		const response = await page.goto('/', {
			waitUntil: 'domcontentloaded',
		});

		expect(response).not.toBeNull();
		expect(page.url()).toContain('/wp-login.php');
		expect(page.url()).toContain('redirect_to=');
		await expect(page.locator('#loginform')).toBeVisible();
		expect(countRedirects(response)).toBeGreaterThanOrEqual(1);
	});

	test('案内ページは 320 / 375 / 390 / 414 / 1280px で切れず、横スクロールしない', async ({
		page,
	}) => {
		await loginAs(page, USERS.subscriber);

		for (const width of [320, 375, 390, 414, 1280]) {
			await page.setViewportSize({ width, height: 900 });
			await page.goto('/', { waitUntil: 'domcontentloaded' });

			const button = page.getByRole('link', {
				name: 'ログアウトしてログインし直す',
			});
			await expect(button).toBeVisible();

			const metrics = await page.evaluate(() => {
				const link = Array.from(document.querySelectorAll('a')).find(
					(element) =>
						element.textContent?.trim() ===
						'ログアウトしてログインし直す'
				);
				const heading = document.querySelector('.page-header h1');
				if (!(link instanceof HTMLElement) || !(heading instanceof HTMLElement)) {
					throw new Error('案内ページのボタンまたは見出しを取得できませんでした。');
				}
				const linkRect = link.getBoundingClientRect();
				const headingRect = heading.getBoundingClientRect();
				const headingStyle = getComputedStyle(heading);

				return {
					documentWidth: document.documentElement.scrollWidth,
					viewportWidth: document.documentElement.clientWidth,
					buttonLeft: linkRect.left,
					buttonRight: linkRect.right,
					buttonScrollWidth: link.scrollWidth,
					buttonClientWidth: link.clientWidth,
					headingLeft: headingRect.left,
					headingFontSize: headingStyle.fontSize,
				};
			});

			expect(metrics.documentWidth).toBeLessThanOrEqual(
				metrics.viewportWidth
			);
			expect(metrics.buttonLeft).toBeGreaterThanOrEqual(0);
			expect(metrics.buttonRight).toBeLessThanOrEqual(width);
			expect(metrics.buttonScrollWidth).toBeLessThanOrEqual(
				metrics.buttonClientWidth
			);
			expect(metrics.headingLeft).toBeGreaterThanOrEqual(0);
			expect(Number.parseFloat(metrics.headingFontSize)).toBeGreaterThan(0);
		}

		// index.php と同じ見出し用ラッパーが使われ、テーマ既存のスタイルが当たることを確認する。
		await expect(page.locator('header.page-header > h1')).toHaveCount(1);
	});
});
