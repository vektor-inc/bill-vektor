// @ts-check

/**
 * タイトル基準で対象行を特定する e2e スペック向けの、前提データ存在確認ヘルパー。
 *
 * PR #297（見積書一覧の取引先列）や PR #298（キーワード検索）は投稿IDの決め打ちが
 * 無く、投稿タイトルで対象行を特定しているため、マニフェスト方式（#308 / #319 と同じ
 * 投稿ID参照）へ移行する必要は無い。
 *
 * ただし、データ作成スクリプト（create-test-data-pr-297.php / create-test-data-298.php）を
 * 実行していない環境では、各テストが探している要素がいつまでも現れず、
 * アサーションごとの既定タイムアウト（数十秒）で1つずつ失敗する。
 * テストが大量に並ぶとこの待ちが積み重なり、「本当の不具合なのか環境未整備なのか」の
 * 切り分けに時間がかかってしまう。
 *
 * このヘルパーは、スペック全体の実行前に軽量な存在確認を1回だけ行い、
 * 前提データが無ければ作成コマンドを添えた明示的なエラーで即座に落とす。
 */

/**
 * 指定 URL を開き、ロケーターで1件以上見つかることを確認する。
 *
 * 見つからない場合は、通常のアサーションタイムアウトを待たせず、
 * 短時間（5秒）で「前提データが無い」と判断して案内コマンド付きのエラーを投げる。
 *
 * @param {import('@playwright/test').Page} page       確認に使う Page（権限を持つアカウントでログイン済みのものを渡すこと）。
 * @param {string} url                                  確認対象の一覧・検索結果 URL。
 * @param {(page: import('@playwright/test').Page) => import('@playwright/test').Locator} locatorFn 存在を確認したい要素のロケーターを返す関数。
 * @param {string} label                                エラーメッセージに出す前提データの説明（例: 'PR #297 の見積書「Webサイト制作見積（登録済取引先）」'）。
 * @param {string} setupHint                             見つからなかった場合に案内する作成コマンド。
 * @return {Promise<void>}
 */
async function requireTestDataPresent(page, url, locatorFn, label, setupHint) {
	await page.goto(url, { waitUntil: 'domcontentloaded' });

	const locator = locatorFn(page);

	// server-rendered な一覧・検索結果なので長くは待たず、短時間で判定する。
	// 見つからない場合は例外を捕まえ、件数0として扱う。
	let found = false;
	try {
		await locator.first().waitFor({ state: 'attached', timeout: 5000 });
		found = (await locator.count()) > 0;
	} catch (error) {
		found = false;
	}

	if (!found) {
		throw new Error(`前提データが見つかりません: ${label}\n${setupHint}`);
	}
}

module.exports = { requireTestDataPresent };
