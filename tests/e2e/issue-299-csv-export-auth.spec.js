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
 * 匿名アクセスを検証するブロックでは storageState を空にして未ログイン状態を作る。
 *
 * 権限別の検証（後半のブロック）は、環境に手で作ったユーザーへ依存しないよう
 * テスト自身が管理画面からユーザーを作成し、finally で必ず削除する。
 */

/**
 * テストが作成するユーザー名の接頭辞。
 *
 * 中断などで消し残ったユーザーをまとめて片付けるための目印にする。
 */
const TEST_USER_PREFIX = 'e2e299user';

/**
 * 後始末の削除ループの反復上限。
 *
 * 消せないユーザーが残った場合に、タイムアウトではなく
 * 「削除できていない」と分かるエラーで止めるための保険。
 */
const MAX_CLEANUP_ITERATIONS = 20;

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

/**
 * 管理者ユーザーのログイン情報。
 *
 * global-setup.js と同じ規約（環境変数、未設定ならローカル開発環境の初期値）に合わせる。
 */
const ADMIN_USERNAME = process.env.WP_TEST_USERNAME || 'admin';
const ADMIN_PASSWORD = process.env.WP_TEST_PASSWORD || 'password';

/**
 * 要素をページ内でネイティブに click する。
 *
 * Playwright の locator.click() は要素の位置が安定しているかを
 * requestAnimationFrame で確認するが、負荷の高い環境では rAF が滞って
 * この判定が終わらず、クリックがハングすることがある。
 *
 * このスペックの目的は「権限による出し分け」と「CSV が返る／返らない」の検証であって
 * クリックの当たり判定ではないため、判定を経由せず確実に click する。
 * （UI の操作性そのものは他のスペックが通常の click で担保している）
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
 * 同じページのまま、指定ユーザーへログインし直す。
 *
 * 重要: 権限別の検証では、ブラウザコンテキストとページを1つだけ使い、
 * ログインを切り替えることで管理者とテストユーザーを行き来する。
 * コンテキストやページを複数同時に開くと、背面に回ったページの
 * requestAnimationFrame が Chromium に止められ、クリックの actionability 判定
 * （安定待ち）が終わらなくなるため。
 *
 * @param {import('@playwright/test').Page} page
 * @param {string} login
 * @param {string} password
 */
async function loginAs(page, login, password) {
	// 前のユーザーのセッションを完全に捨ててからログインする
	await page.context().clearCookies();
	await page.goto('/wp-login.php', { waitUntil: 'domcontentloaded' });
	await page.locator('#user_login').fill(login);
	await page.locator('#user_pass').fill(password);
	await clickNative(page.locator('#wp-submit'));
	await page.waitForURL('**/wp-admin/**');
}

/**
 * 管理画面の「新規ユーザーを追加」からユーザーを作成する。
 *
 * wp-cli など環境側の道具に頼らず、ブラウザ操作だけで完結させる。
 *
 * パスワードは自分で入力せず、WordPress が生成した値をそのまま読み取って返す。
 * 入力欄へ直接 fill しても、WordPress 側の JS が確認用フィールドを同期するのは
 * keyup のときだけのため、値が反映されずログインできないことがあるため。
 *
 * @param {import('@playwright/test').Page} adminPage
 * @param {{ login: string, role: string }} user
 * @return {Promise<string>} 作成したユーザーのパスワード。
 */
async function createUser(adminPage, user) {
	// 管理画面は Gravatar など外部ホストの読み込みを含むため、
	// 'load' ではなく 'domcontentloaded' で待つ（外部が詰まってもテストを止めない）
	await adminPage.goto('/wp-admin/user-new.php', {
		waitUntil: 'domcontentloaded',
	});

	await adminPage.locator('#user_login').fill(user.login);
	await adminPage.locator('#email').fill(`${user.login}@example.com`);

	// パスワード欄は既定で隠れているため、「Generate password」を押して表示させる。
	// 生成された強いパスワードをそのまま使い、その値を読み取ってログインに用いる。
	const generateButton = adminPage.locator('button.wp-generate-pw');
	if (await generateButton.isVisible()) {
		await clickNative(generateButton);
	}
	const password = await adminPage.locator('#pass1').inputValue();
	expect(password).toBeTruthy();

	// テスト環境でのメール送信に失敗しないよう、通知メールは送らない
	const notify = adminPage.locator('#send_user_notification');
	if (await notify.isChecked()) {
		await clickNative(notify);
	}

	await adminPage.locator('#role').selectOption(user.role);

	// 送信して一覧へ戻る
	await Promise.all([
		adminPage.waitForNavigation({ waitUntil: 'domcontentloaded' }),
		clickNative(adminPage.locator('#createusersub')),
	]);

	// 作成できたことを確認する
	await expect(adminPage.locator('#message')).toContainText(
		/New user created|新規ユーザーを作成しました/
	);

	return password;
}

/**
 * 接頭辞に一致するテストユーザーをすべて削除する。
 *
 * 前回の中断で残ったユーザーもまとめて片付けられるよう、
 * 一致するものが無くなるまで繰り返す。
 *
 * @param {import('@playwright/test').Page} adminPage
 * @param {string} prefix
 */
async function deleteUsersByPrefix(adminPage, prefix) {
	for (let i = 0; i < MAX_CLEANUP_ITERATIONS; i++) {
		// ユーザー一覧はアバター画像を外部ホスト（Gravatar）から読むため、
		// 'load' 待ちにすると外部の遅延でテストが止まる
		await adminPage.goto(
			`/wp-admin/users.php?s=${encodeURIComponent(prefix)}`,
			{ waitUntil: 'domcontentloaded' }
		);

		// 行アクションの「削除」リンクはホバーするまで見えないため、href を直接取得する
		const deleteLink = adminPage
			.locator('#the-list tr span.delete a')
			.first();
		if ((await deleteLink.count()) === 0) {
			return;
		}
		const href = await deleteLink.getAttribute('href');
		if (!href) {
			return;
		}

		// 行アクションの href は "users.php?action=delete&..." という相対 URL のため、
		// 現在の管理画面 URL を基準に解決する。
		// （Playwright の goto() は相対パスを baseURL 基準で解決するため、
		//   そのまま渡すとフロント側の存在しない URL になってしまう）
		await adminPage.goto(new URL(href, adminPage.url()).href, {
			waitUntil: 'domcontentloaded',
		});

		// 削除確認画面に来ていること
		const submitButton = adminPage.locator('#submit');
		await expect(submitButton).toBeVisible();

		// 投稿を持つユーザーの場合は「すべてのコンテンツを削除」を選んでから送信する。
		//
		// この画面には2つの癖があり、Playwright の通常のクリックでは安定しない。
		//  - 「削除を実行」ボタンは WordPress のインライン JS が最初に無効化し、
		//    delete_option の change イベント（.one）でのみ有効化される
		//  - 別コンテキストのページを開いた後は管理画面のページが背面に回るため、
		//    Chromium が requestAnimationFrame を止めてクリックの「安定待ち」が終わらない
		//
		// 後片付けは確実さが最優先なので、ページ内でネイティブの click() を呼んで
		// ラジオ選択と送信をまとめて確定させる。
		await Promise.all([
			adminPage.waitForNavigation({ waitUntil: 'domcontentloaded' }),
			adminPage.evaluate(() => {
				const radio = document.getElementById('delete_option0');
				if (radio instanceof HTMLInputElement) {
					// click() ならネイティブに change イベントも発火する
					radio.click();
				}
				const submit = document.getElementById('submit');
				if (submit instanceof HTMLInputElement) {
					submit.disabled = false;
					submit.click();
				}
			}),
		]);
	}

	throw new Error(
		`テストユーザー（接頭辞 ${prefix}）を削除しきれませんでした。`
	);
}

/**
 * エクスポートボタンを実際に押して CSV をダウンロードし、本文を返す。
 *
 * @param {import('@playwright/test').Page} page
 * @param {RegExp} buttonName
 * @param {'utf8'|'shift_jis'} encoding
 */
async function downloadCsv(page, buttonName, encoding) {
	const [download] = await Promise.all([
		page.waitForEvent('download'),
		clickNative(page.getByRole('button', { name: buttonName })),
	]);
	const stream = await download.createReadStream();
	if (!stream) {
		throw new Error('CSV ダウンロードストリームを取得できません。');
	}
	const chunks = [];
	for await (const chunk of stream) {
		chunks.push(chunk);
	}
	const buffer = Buffer.concat(chunks);
	return encoding === 'shift_jis'
		? new TextDecoder('shift_jis').decode(buffer)
		: buffer.toString('utf8');
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

test.describe('issue #299 権限によるエクスポート欄の出し分け', () => {
	// 管理者とテストユーザーの間でログインを切り替えるため、既定の storageState は使わない
	test.use({ storageState: { cookies: [], origins: [] } });

	test('編集権限を持たないユーザーにはエクスポート欄が出ず、直接 URL でも CSV が返らない', async ({
		page,
	}) => {
		test.setTimeout(90000);

		const user = {
			login: `${TEST_USER_PREFIX}sub${Date.now()}`,
			role: 'subscriber',
		};

		try {
			// 管理者としてログインし、前回中断分を片付けてからテスト用ユーザーを作る
			await loginAs(page, ADMIN_USERNAME, ADMIN_PASSWORD);
			await deleteUsersByPrefix(page, TEST_USER_PREFIX);
			const password = await createUser(page, user);

			// 同じページのまま、作成したユーザーへログインし直す
			await loginAs(page, user.login, password);
			const response = await page.goto('/');

			/*
			 * 1. そもそも一覧画面に到達できず、403 の案内ページになること
			 *
			 * PR #319 以前は、購読者でもトップページの一覧と絞り込み検索を操作でき、
			 * 「エクスポート欄だけが出ない」状態だった。そのため以前のこのテストは
			 * 「エクスポート欄が無いこと」と「検索ボックスは従来どおり使えること」を
			 * 確認していた。PR #319 で編集権限の無いユーザーはフロント側の閲覧自体を
			 * 遮断するようになったため、検索ボックスの確認は成立しなくなっている。
			 * このテストの意図（編集権限が無ければ CSV を取得できない）は変えず、
			 * より手前で止まっていることを確認する形に更新している。
			 */
			expect(response && response.status()).toBe(403);
			await expect(
				page.getByRole('heading', {
					level: 1,
					name: 'この画面を表示する権限がありません',
				})
			).toBeVisible();

			// 2. エクスポート欄が DOM ごと存在しないこと
			await expect(page.locator('#csv-export')).toHaveCount(0);
			await expect(page.locator('.export-box')).toHaveCount(0);
			await expect(page.getByText('仕分帳データのエクスポート')).toHaveCount(0);
			await expect(
				page.getByRole('button', { name: /freee用CSVエクスポート/ })
			).toHaveCount(0);
			await expect(
				page.getByRole('button', { name: /MFクラウド会計用CSVエクスポート/ })
			).toHaveCount(0);
			// エクスポート欄と一緒に nonce フィールドも出ないこと
			await expect(page.locator('input[name="_wpnonce"]')).toHaveCount(0);

			/*
			 * 3. 直接 URL を叩いても CSV が返らないこと
			 *
			 * CSV の出力は init フックで動き、閲覧制限の wp フックより前に実行される。
			 * 403 の案内ページとは独立した保証になるため、この確認は残しておく。
			 */
			for (const action of ['csv_freee', 'csv_mf']) {
				const response = await page.request.get(`/?action=${action}`, {
					maxRedirects: 0,
				});
				await expectNotCsvExport(response);
			}
		} finally {
			// 管理者へ戻し、作成したユーザーを必ず片付ける
			await loginAs(page, ADMIN_USERNAME, ADMIN_PASSWORD);
			await deleteUsersByPrefix(page, TEST_USER_PREFIX);
		}
	});

	test('編集権限を持つユーザーにはエクスポート欄が表示され、両方の CSV を取得できる', async ({
		page,
	}) => {
		test.setTimeout(90000);

		// 寄稿者は edit_posts を持つため、エクスポートできるのが仕様
		const user = {
			login: `${TEST_USER_PREFIX}con${Date.now()}`,
			role: 'contributor',
		};

		try {
			await loginAs(page, ADMIN_USERNAME, ADMIN_PASSWORD);
			await deleteUsersByPrefix(page, TEST_USER_PREFIX);
			const password = await createUser(page, user);

			await loginAs(page, user.login, password);
			await page.goto('/');

			// 4. エクスポート欄が表示され、nonce フィールドも出ていること
			await expect(page.locator('#csv-export')).toBeVisible();
			await expect(
				page.locator('.export-box input[name="_wpnonce"]')
			).toHaveCount(1);

			// 実際にボタンを押して両方の CSV をダウンロードできること
			const freeeCsv = await downloadCsv(page, /freee用CSVエクスポート/, 'utf8');
			expect(freeeCsv).toContain('"収支区分"');

			await page.goto('/');
			const mfCsv = await downloadCsv(
				page,
				/MFクラウド会計用CSVエクスポート/,
				'shift_jis'
			);
			expect(mfCsv).toContain('"取引No"');
		} finally {
			await loginAs(page, ADMIN_USERNAME, ADMIN_PASSWORD);
			await deleteUsersByPrefix(page, TEST_USER_PREFIX);
		}
	});
});
