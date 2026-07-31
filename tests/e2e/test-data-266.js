// @ts-check
const fs = require('fs');
const path = require('path');

/**
 * PR #266 e2e テストのテストデータ参照モジュール
 *
 * create-test-data.php が書き出すマニフェスト（.test-data-266.json）を読み込み、
 * テスト対象の投稿URLを提供する。
 *
 * 投稿IDは自動採番のため環境（既存の投稿数）によって変わる。
 * 以前は spec 側で /?p=4 のように決め打ちしていたが、環境が変わると
 * 別の投稿を開く・存在せず失敗するため、マニフェスト経由に統一した。
 *
 * spec と take-screenshots.js の両方から require して使う。
 */

// create-test-data.php が投稿IDを書き出すマニフェストのパス
const MANIFEST_PATH = path.join(__dirname, '.test-data-266.json');

// マニフェストに必ず含まれているべきキー（テスト対象の4投稿）
const REQUIRED_KEYS = [
	'tax_round_default',
	'tax_round_ceil',
	'tax_excluded',
	'tax_excluded_3333',
];

// マニフェストが無い・壊れている場合に案内する再作成コマンド
const SETUP_HINT =
	'テストデータを作成してから実行してください:\n' +
	"  npx wp-env run cli --env-cwd='wp-content/themes/bill-vektor' wp eval-file tests/e2e/create-test-data.php";

/**
 * テストデータのマニフェストを読み込み、各投稿の相対URLとタイトルを返す
 *
 * マニフェストが無い／壊れている／キーが欠けている場合は、undefined のまま
 * goto して原因の分かりにくい失敗になるのを避けるため、ここで明示的に落とす。
 *
 * @return {Record<string, {id: number, title: string, url: string}>} キーごとのテストデータ
 */
function loadTestPosts() {
	if (!fs.existsSync(MANIFEST_PATH)) {
		throw new Error(
			`テストデータのマニフェストが見つかりません: ${MANIFEST_PATH}\n${SETUP_HINT}`
		);
	}

	let manifest;
	try {
		manifest = JSON.parse(fs.readFileSync(MANIFEST_PATH, 'utf8'));
	} catch (error) {
		throw new Error(
			`テストデータのマニフェストを読み込めませんでした: ${MANIFEST_PATH}\n${error.message}\n${SETUP_HINT}`
		);
	}

	// JSON.parse は null や配列・数値もパースに成功してしまう。
	// このガードが無いと、後続の manifest[key] が TypeError になり
	// 再作成コマンドの案内が出ないまま分かりにくい失敗になる
	if (!manifest || typeof manifest !== 'object' || Array.isArray(manifest)) {
		throw new Error(
			`テストデータのマニフェストの形式が不正です: ${MANIFEST_PATH}\n${SETUP_HINT}`
		);
	}

	// 各キーが { id: 正の整数, title: 空でない文字列 } になっていることを確認する
	const invalidKeys = REQUIRED_KEYS.filter((key) => {
		const entry = manifest[key];
		if (!entry || typeof entry !== 'object' || Array.isArray(entry)) {
			return true;
		}
		if (!Number.isInteger(entry.id) || entry.id <= 0) {
			return true;
		}
		return typeof entry.title !== 'string' || entry.title === '';
	});

	if (invalidKeys.length > 0) {
		throw new Error(
			`テストデータのマニフェストの内容が不正です: ${invalidKeys.join(', ')}\n` +
				`（${MANIFEST_PATH}）\n${SETUP_HINT}`
		);
	}

	return Object.fromEntries(
		REQUIRED_KEYS.map((key) => [
			key,
			{
				id: manifest[key].id,
				title: manifest[key].title,
				// ベースURLは playwright.config.js の baseURL に任せるため相対パスで組み立てる
				url: `/?p=${manifest[key].id}`,
			},
		])
	);
}

// テストデータ（create-test-data.php で作成した投稿を参照する）
const TEST_POSTS = loadTestPosts();

/**
 * テストデータの投稿を開き、意図した投稿が表示されていることを確認する
 *
 * マニフェストは DB と紐付いていないため、wp-env clean や DB 入れ替えで
 * 投稿が消えてもファイルだけが残る。その状態で /?p=<古いID> を開くと
 * 404 や無関係な投稿が表示されるが、「6,001 が無いこと」のような
 * 否定形の検証は素通りして PASS してしまう（空振り PASS）。
 * それを防ぐため、期待する投稿タイトルがページタイトルに含まれることを先に確認する。
 *
 * @param {import('@playwright/test').Page} page   Playwright の Page。
 * @param {string}                          key    テストデータのキー。
 * @return {Promise<{id: number, title: string, url: string}>} 開いたテストデータ。
 */
async function gotoTestPost(page, key) {
	const post = TEST_POSTS[key];
	if (!post) {
		throw new Error(
			`未定義のテストデータキーです: ${key}\n` +
				`利用できるキー: ${REQUIRED_KEYS.join(', ')}`
		);
	}

	const response = await page.goto(post.url);
	const pageTitle = await page.title();

	if (!pageTitle.includes(post.title)) {
		throw new Error(
			`テストデータの投稿を開けませんでした: ${key}\n` +
				`  URL: ${post.url}（HTTPステータス: ${response ? response.status() : '不明'}）\n` +
				`  期待するタイトル: ${post.title}\n` +
				`  実際のページタイトル: ${pageTitle}\n` +
				`マニフェストの投稿IDが実際のデータベースと食い違っている可能性があります。\n${SETUP_HINT}`
		);
	}

	return post;
}

module.exports = {
	MANIFEST_PATH,
	REQUIRED_KEYS,
	SETUP_HINT,
	TEST_POSTS,
	gotoTestPost,
};
