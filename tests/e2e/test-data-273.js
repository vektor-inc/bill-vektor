// @ts-check
const fs = require('fs');
const path = require('path');

/**
 * PR #273 e2e テストのテストデータ参照モジュール
 *
 * create-test-data-pr-273.php が書き出すマニフェスト（.test-data-273.json）を読み込み、
 * テスト対象の投稿編集画面のURLを提供する。
 *
 * 投稿IDは自動採番のため環境（既存の投稿数）によって変わる。
 * 以前は spec 側で post=37 のように決め打ちしていたが、環境が変わると
 * 別の投稿を開く・存在せず失敗するため、マニフェスト経由に統一した。
 *
 * pr-273-flexible-table-touch.spec.js から require して使う。
 */

// create-test-data-pr-273.php が投稿IDを書き出すマニフェストのパス
const MANIFEST_PATH = path.join(__dirname, '.test-data-273.json');

// マニフェストに必ず含まれているべきキー（品目テーブルの操作確認用の投稿）
const REQUIRED_KEYS = ['flexible_table'];

// マニフェストが無い・壊れている場合に案内する再作成コマンド
// （$(basename "$PWD") はカレントディレクトリ名＝テーマのディレクトリ名。
//   worktree ではディレクトリ名が bill-vektor 以外になるため決め打ちにしない）
const SETUP_HINT =
	'テストデータを作成してから実行してください:\n' +
	'  npx wp-env run cli --env-cwd="wp-content/themes/$(basename "$PWD")" wp eval-file tests/e2e/create-test-data-pr-273.php';

// 投稿編集画面のタイトル入力欄。
// クラシックエディター（#title）とブロックエディター（.editor-post-title__input /
// .wp-block-post-title）のどちらでも拾えるように併記している
const TITLE_FIELD_SELECTOR =
	'#title, .editor-post-title__input, .wp-block-post-title';

/**
 * テストデータのマニフェストを読み込み、各投稿の編集画面URLとタイトルを返す
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
				url: `/wp-admin/post.php?post=${manifest[key].id}&action=edit`,
			},
		])
	);
}

// テストデータ（create-test-data-pr-273.php で作成した投稿を参照する）
const TEST_POSTS = loadTestPosts();

/**
 * 投稿編集画面に表示されているタイトルを読み取る
 *
 * 管理画面の編集ページは document.title に投稿タイトルを含まないため、
 * タイトル入力欄の値（クラシックエディター）または編集中のタイトル
 * （ブロックエディター）から取得する。
 *
 * @param {import('@playwright/test').Page} page Playwright の Page。
 * @return {Promise<string>} 読み取れたタイトル。読み取れない場合は空文字。
 */
async function readEditorPostTitle(page) {
	return page.evaluate(() => {
		// クラシックエディターのタイトル入力欄
		const classicTitle = document.getElementById('title');
		if (
			classicTitle instanceof HTMLInputElement ||
			classicTitle instanceof HTMLTextAreaElement
		) {
			return classicTitle.value;
		}

		// ブロックエディター: 編集中のタイトルをデータストアから取得する
		const wpGlobal = /** @type {any} */ (window).wp;
		if (wpGlobal && wpGlobal.data && typeof wpGlobal.data.select === 'function') {
			const editorStore = wpGlobal.data.select('core/editor');
			if (
				editorStore &&
				typeof editorStore.getEditedPostAttribute === 'function'
			) {
				const storeTitle = editorStore.getEditedPostAttribute('title');
				if (typeof storeTitle === 'string' && storeTitle !== '') {
					return storeTitle;
				}
			}
		}

		// ブロックエディターのタイトル入力欄
		// （textarea の場合と contenteditable の場合があるため両方に対応する）
		const blockTitle = document.querySelector(
			'.editor-post-title__input, .wp-block-post-title'
		);
		if (blockTitle instanceof HTMLTextAreaElement) {
			return blockTitle.value;
		}
		if (blockTitle) {
			return blockTitle.textContent || '';
		}

		return '';
	});
}

/**
 * テストデータの投稿の編集画面を開き、意図した投稿を開けていることを確認する
 *
 * マニフェストは DB と紐付いていないため、wp-env clean や DB 入れ替えで
 * 投稿が消えてもファイルだけが残る。その状態で post=<古いID> を開くと
 * 別の投稿の編集画面が表示されるが、「入力欄にフォーカスが当たる」
 * 「行数が1増える」のような検証は別の投稿でも偶然通ってしまい PASS する
 * （空振り PASS）。それを防ぐため、期待する投稿タイトルが
 * 編集画面のタイトル欄に入っていることを先に確認する。
 *
 * @param {import('@playwright/test').Page} page Playwright の Page。
 * @param {string}                          key  テストデータのキー。
 * @return {Promise<{id: number, title: string, url: string}>} 開いたテストデータ。
 */
async function gotoEditPost(page, key) {
	const post = TEST_POSTS[key];
	if (!post) {
		throw new Error(
			`未定義のテストデータキーです: ${key}\n` +
				`利用できるキー: ${REQUIRED_KEYS.join(', ')}`
		);
	}

	const response = await page.goto(post.url);
	const httpStatus = response ? response.status() : '不明';

	// タイトル欄が描画されるまで待つ。
	// 投稿が存在しない場合は「無効な投稿です」等の画面になり
	// タイトル欄が現れないため、ここで待ちきれずに落ちる
	try {
		await page.waitForSelector(TITLE_FIELD_SELECTOR, {
			state: 'attached',
			timeout: 10000,
		});
	} catch (error) {
		throw new Error(
			`テストデータの投稿の編集画面を開けませんでした: ${key}\n` +
				`  URL: ${post.url}（HTTPステータス: ${httpStatus}）\n` +
				`  タイトル入力欄が見つかりませんでした（投稿が存在しない可能性があります）\n` +
				`マニフェストの投稿IDが実際のデータベースと食い違っている可能性があります。\n${SETUP_HINT}`
		);
	}

	// ブロックエディターは入力欄の描画とタイトルの反映にわずかな差があるため、
	// タイトルが読み取れるようになるまで短時間だけ待つ
	let actualTitle = '';
	const deadline = Date.now() + 5000;
	while (Date.now() < deadline) {
		actualTitle = await readEditorPostTitle(page);
		if ('' !== actualTitle) {
			break;
		}
		await page.waitForTimeout(100);
	}

	if (actualTitle.trim() !== post.title) {
		throw new Error(
			`意図したテストデータの投稿を開けていません: ${key}\n` +
				`  URL: ${post.url}（HTTPステータス: ${httpStatus}）\n` +
				`  期待するタイトル: ${post.title}\n` +
				`  実際のタイトル: ${actualTitle}\n` +
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
	gotoEditPost,
};
