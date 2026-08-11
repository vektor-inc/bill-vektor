// @ts-check
const fs = require('fs');
const path = require('path');

/**
 * PR #319 e2e テストのテストデータ参照モジュール
 *
 * create-test-data-pr-319.php が書き出すマニフェスト（.test-data-319.json）を読み込み、
 * テスト対象の書類の投稿URLと、権限確認用アカウント（購読者・寄稿者）を提供する。
 *
 * 投稿IDは自動採番のため環境（既存の投稿数）によって変わる。
 * 以前は spec 側で /?p=4 のように決め打ちしていたため、環境が変わると
 * 別の投稿を開く・存在せず失敗していた。マニフェスト経由に統一することで、
 * どの環境でも create-test-data-pr-319.php を実行するだけで揃うようにしている。
 *
 * 確認用アカウント（billsub / billcon）も同様に、以前は「依頼で用意済み」の
 * 前提で決め打ちしていたため、未整備の環境ではテストがすべて失敗していた。
 * create-test-data-pr-319.php が冪等に作成するようになったため、
 * ログイン名・パスワードもここでマニフェストから読み込む。
 *
 * pr-319-view-auth.spec.js から require して使う。
 */

// create-test-data-pr-319.php が投稿ID・アカウント情報を書き出すマニフェストのパス
const MANIFEST_PATH = path.join(__dirname, '.test-data-319.json');

// マニフェストが無い・壊れている場合に案内する再作成コマンド
// （$(basename "$PWD") はカレントディレクトリ名＝テーマのディレクトリ名。
//   worktree ではディレクトリ名が bill-vektor 以外になるため決め打ちにしない）
const SETUP_HINT =
	'テストデータを作成してから実行してください:\n' +
	'  npx wp-env run cli --env-cwd="wp-content/themes/$(basename "$PWD")" wp eval-file tests/e2e/create-test-data-pr-319.php';

// マニフェストの users に必ず含まれているべきロール
const REQUIRED_USER_ROLES = ['subscriber', 'contributor'];

/**
 * オブジェクトが { login: 空でない文字列, password: 空でない文字列 } の形かを判定する
 *
 * @param {unknown} entry 判定対象。
 * @return {boolean} 形が正しければ true。
 */
function isValidUserEntry(entry) {
	if (!entry || typeof entry !== 'object' || Array.isArray(entry)) {
		return false;
	}
	const user = /** @type {{login?: unknown, password?: unknown}} */ (entry);
	return (
		typeof user.login === 'string' &&
		user.login !== '' &&
		typeof user.password === 'string' &&
		user.password !== ''
	);
}

/**
 * テストデータのマニフェストを読み込み、書類データと確認用アカウントを返す
 *
 * マニフェストが無い／壊れている／キーが欠けている場合は、undefined のまま
 * goto・login して原因の分かりにくい失敗になるのを避けるため、ここで明示的に落とす。
 *
 * @return {{
 *   document: {id: number, title: string, total: string, url: string},
 *   users: Record<string, {login: string, password: string}>,
 * }} テストデータ。
 */
function loadTestData() {
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
	// このガードが無いと、後続のプロパティアクセスが TypeError になり
	// 再作成コマンドの案内が出ないまま分かりにくい失敗になる
	if (!manifest || typeof manifest !== 'object' || Array.isArray(manifest)) {
		throw new Error(
			`テストデータのマニフェストの形式が不正です: ${MANIFEST_PATH}\n${SETUP_HINT}`
		);
	}

	// document が { id: 正の整数, title: 空でない文字列, total: 空でない文字列 } になっているか確認する
	const document = manifest.document;
	const documentValid =
		document &&
		typeof document === 'object' &&
		!Array.isArray(document) &&
		Number.isInteger(document.id) &&
		document.id > 0 &&
		typeof document.title === 'string' &&
		document.title !== '' &&
		typeof document.total === 'string' &&
		document.total !== '';

	if (!documentValid) {
		throw new Error(
			`テストデータのマニフェストの内容が不正です: document\n` +
				`（${MANIFEST_PATH}）\n${SETUP_HINT}`
		);
	}

	// users に必要なロールが揃っているか確認する
	const users = manifest.users;
	if (!users || typeof users !== 'object' || Array.isArray(users)) {
		throw new Error(
			`テストデータのマニフェストの内容が不正です: users\n` +
				`（${MANIFEST_PATH}）\n${SETUP_HINT}`
		);
	}

	const invalidRoles = REQUIRED_USER_ROLES.filter(
		(role) => !isValidUserEntry(users[role])
	);
	if (invalidRoles.length > 0) {
		throw new Error(
			`テストデータのマニフェストの内容が不正です: users.${invalidRoles.join(', users.')}\n` +
				`（${MANIFEST_PATH}）\n${SETUP_HINT}`
		);
	}

	return {
		document: {
			id: document.id,
			title: document.title,
			total: document.total,
			// ベースURLは playwright.config.js の baseURL に任せるため相対パスで組み立てる
			url: `/?p=${document.id}`,
		},
		users: {
			subscriber: {
				login: users.subscriber.login,
				password: users.subscriber.password,
			},
			contributor: {
				login: users.contributor.login,
				password: users.contributor.password,
			},
		},
	};
}

// テストデータ（create-test-data-pr-319.php で作成した書類・ユーザーを参照する）
const TEST_DATA = loadTestData();

/**
 * 対象の書類ページを開き、意図した書類が表示されていることを確認する
 *
 * マニフェストは DB と紐付いていないため、wp-env clean や DB 入れ替えで
 * 投稿が消えてもファイルだけが残る。その状態で /?p=<古いID> を開くと
 * 404 や無関係な投稿が表示されるが、「書類の内容が漏れていないこと」のような
 * 否定形の検証は素通りして PASS してしまう（空振り PASS）。
 * それを防ぐため、期待するタイトル・合計金額が本文に含まれることを先に確認する。
 *
 * 権限を持つユーザー（寄稿者・管理者・globalSetup の管理者セッションなど）で
 * ログインした状態の page で呼び出すこと。購読者は403になるため、この確認には使えない。
 *
 * @param {import('@playwright/test').Page} page Playwright の Page。
 * @return {Promise<{id: number, title: string, total: string, url: string}>} 確認できた書類データ。
 */
async function verifyDocumentVisible(page) {
	const document = TEST_DATA.document;
	const response = await page.goto(document.url, {
		waitUntil: 'domcontentloaded',
	});
	const httpStatus = response ? response.status() : '不明';
	const bodyText = await page.locator('body').innerText();

	if (!bodyText.includes(document.title) || !bodyText.includes(document.total)) {
		throw new Error(
			`テストデータの書類を確認できませんでした\n` +
				`  URL: ${document.url}（HTTPステータス: ${httpStatus}）\n` +
				`  期待するタイトル: ${document.title}\n` +
				`  期待する合計金額: ${document.total}\n` +
				`マニフェストの投稿IDが実際のデータベースと食い違っている可能性があります。\n${SETUP_HINT}`
		);
	}

	return document;
}

module.exports = {
	MANIFEST_PATH,
	SETUP_HINT,
	REQUIRED_USER_ROLES,
	TEST_DATA,
	verifyDocumentVisible,
};
