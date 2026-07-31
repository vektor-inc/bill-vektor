// @ts-check
// eslint-disable-next-line @typescript-eslint/no-var-requires
const { test, expect } = require('@playwright/test');

/**
 * PR #319 フロント側の書類閲覧権限に関する e2e テスト。
 *
 * 購読者への 403 表示と情報漏えい防止、ログアウトを挟む復帰導線、
 * 寄稿者・管理者・未ログイン時の回帰、狭い画面でのレイアウトを確認する。
 *
 * 実行例:
 *   WP_BASE_URL=http://localhost:9126 npx playwright test tests/e2e/pr-319-view-auth.spec.js
 */

// ローカルの確認用アカウント。依頼で用意済みのユーザーだけを使い、テスト中にDBは変更しない。
const USERS = {
	subscriber: { login: 'billsub', password: 'password' },
	contributor: { login: 'billcon', password: 'password' },
	administrator: { login: 'admin', password: 'password' },
};

// 購読者を遮断する必要があるフロント側の全経路。
const FORBIDDEN_PATHS = [
	'/',
	'/?p=4',
	'/?feed=rss2',
	'/wp-sitemap.xml',
	'/?s=test',
	'/?post_type=estimate',
	'/?p=4&embed=true',
];

// 書類から案内ページへ漏れてはいけない既知の文言。
const DOCUMENT_LEAK_TEXTS = [
	'テスト請求書',
	'10,998',
	'合計',
	'御請求',
	'振込口座',
];

// 各テストは未ログイン状態から開始し、前のテストの権限を持ち越さない。
test.use({ storageState: { cookies: [], origins: [] } });

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
	await expect(page.locator('body')).toContainText(
		/現在 billsub（(?:購読者|Subscriber)）としてログインしています。/
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
	expect(title).not.toContain('テスト請求書');
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
			await expect(page.locator('body')).toContainText('テスト請求書');
			await expect(
				page.getByRole('heading', {
					name: 'この画面を表示する権限がありません',
				})
			).toHaveCount(0);

			const singleResponse = await page.goto('/?p=4', {
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
		expect(await feedResponse.text()).toContain('テスト請求書');

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
