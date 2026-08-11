<?php
/**
 * Class RewriteRulesFlushTest
 *
 * リライトルール（URLとページの対応表）の自動作り直しのテスト（issue #35）
 *
 * @package BillVektor
 */

/**
 * bill_maybe_flush_rewrite_rules()（functions.php）のテストケース
 *
 * このテーマはカスタム投稿タイプ（client・estimate）とタクソノミー（estimate-cat）を
 * 登録しているが、登録するだけではリライトルール（URLとページの対応表。DBの
 * rewrite_rules オプションにキャッシュされる）は自動で作り直されない。
 * そのため対応表が古いままだと、見積書の個別ページが404になる不具合（issue #35）が発生する。
 *
 * この不具合を再現するため、対応表を「見積書のルールを含まない古い状態」に
 * 強制的に置き換えたうえで対象関数を直接呼び出し、テーマのバージョンと記録値が
 * 食い違う場合にのみ対応表が作り直されることを検証する。
 */
class RewriteRulesFlushTest extends WP_UnitTestCase {

	/**
	 * バージョン記録用オプション名（functions.php の実装と合わせる）
	 *
	 * @var string
	 */
	private $option_name = 'billvektor_rewrite_rules_version';

	/**
	 * set_up() で変更する前のパーマリンク構造（tear_down() での復元用）
	 *
	 * @var string
	 */
	private $original_permalink_structure;

	/**
	 * テスト前のセットアップ
	 *
	 * リライトルールはパーマリンク構造が「基本（プレーン）」だと生成されないため、
	 * 「見積書」等のカスタム投稿タイプ用ルールが実際に効くよう、パーマリンク構造を
	 * 「投稿名」に変更しておく。
	 *
	 * @return void
	 */
	public function set_up() {
		parent::set_up();

		global $wp_rewrite;
		$this->original_permalink_structure = $wp_rewrite->permalink_structure;
		$this->set_permalink_structure( '/%postname%/' );

		// WP_Post_Type::add_rewrite_rules()（register_post_type() の内部処理）は、
		// is_admin() が偽で permalink_structure オプションが空（プレーンパーマリンク）
		// の場合、リライト構造（$wp_rewrite->extra_permastructs）を一切登録しない
		// （wp-includes/class-wp-post-type.php の
		// `if ( false !== $this->rewrite && ( is_admin() || get_option( 'permalink_structure' ) ) )`）。
		// このテーマの estimate・client 投稿タイプは、プロセス起動時（bootstrap の
		// 一度きりの init。permalink_structure がまだ空のタイミング）に登録されているため、
		// 投稿タイプ自体は $wp_post_types に存在するが、リライト構造は最初から
		// 一度も登録されていない。上の set_permalink_structure() で permalink_structure を
		// 非空にした後にもう一度登録し直すことで、リライト構造を実際に作らせる
		bill_add_post_type_client();
		bill_add_post_type_estimate();
	}

	/**
	 * テスト後のクリーンアップ
	 *
	 * このテーマの wp-phpunit（WP_RUN_CORE_TESTS 未定義）は $wp_rewrite を
	 * 自動リセットしないため、set_up() で変更したパーマリンク構造を明示的に
	 * 元へ戻す。DBはトランザクションで巻き戻るが、$wp_rewrite はプロセス内で
	 * 使い回されるメモリ上のオブジェクトのため、戻さないと同一プロセスで後に走る
	 * 他のテスト（go_to() を使う test-search-keyword.php 等）に影響してしまう。
	 * 先頭で戻してから、バージョン記録用オプションを削除する。
	 *
	 * @return void
	 */
	public function tear_down() {
		$this->set_permalink_structure( $this->original_permalink_structure );
		delete_option( $this->option_name );
		parent::tear_down();
	}

	/**
	 * bill_maybe_flush_rewrite_rules() のテスト
	 *
	 * バージョン記録の状態（未記録・記録が古い・記録が最新）ごとに、
	 * 対象関数の実行後の対応表（rewrite_rules オプション）とバージョン記録が
	 * 期待どおりになることを検証する。
	 *
	 * @return void
	 */
	public function test_bill_maybe_flush_rewrite_rules() {

		// bill_maybe_flush_rewrite_rules() が wp_loaded フックに登録されていることを確認する。
		// init ではなく wp_loaded を使う理由は functions.php 側のコメントを参照
		// （init だと flush_rewrite_rules() の内部で wp_loaded まで実行が遅延するため）。
		$this->assertNotFalse(
			has_action( 'wp_loaded', 'bill_maybe_flush_rewrite_rules' ),
			'bill_maybe_flush_rewrite_rules() が wp_loaded フックに登録されていること'
		);

		// 見積書（estimate）のルールを一切含まない「古い対応表」を模した固定値。
		// 実際のバグは、estimate 投稿タイプ追加前に生成された対応表が
		// 作り直されないまま残ってしまうことで発生する。
		$stale_rules = array(
			'stale-marker/?$' => 'index.php?stale=1',
		);

		$test_cases = array(
			array(
				'test_condition_name' => 'バージョン記録が存在しない（新規有効化）場合 => 対応表が作り直され見積書のルールが復元される',
				'stored_version'      => false,
				'expect_flush'        => true,
			),
			array(
				'test_condition_name' => 'バージョン記録が現在のテーマバージョンより古い（テーマ更新直後）場合 => 対応表が作り直され見積書のルールが復元される',
				'stored_version'      => '0.0.1',
				'expect_flush'        => true,
			),
			array(
				'test_condition_name' => 'バージョン記録が現在のテーマバージョンと一致する場合 => 対応表は作り直されず古いままになる（毎回flushしないこと）',
				'stored_version'      => BILLVEKTOR_THEME_VERSION,
				'expect_flush'        => false,
			),
		);

		// 各ケースの失敗をここに集約する。
		// PHPUnit のアサーション失敗は例外として送出されるため、何もせずループ内で
		// assert*() を呼ぶと最初に失敗したケースでメソッド全体が止まり、
		// それ以降のケース（この配列の後ろの要素）が実行されないまま「未検証」になる。
		// 1ケースずつ try/catch で失敗を捕まえて記録し、必ず全ケースを実行してから
		// まとめて失敗させることで、ケースごとに独立して結果を検証する。
		$failures = array();

		foreach ( $test_cases as $case ) {
			try {
				global $wp_rewrite;

				// バージョン記録をケースの前提条件に合わせる
				if ( false === $case['stored_version'] ) {
					delete_option( $this->option_name );
				} else {
					update_option( $this->option_name, $case['stored_version'] );
				}

				// 対応表（DBオプション）を「見積書のルールを含まない古い状態」に強制的に置き換える。
				// $wp_rewrite の内部キャッシュ（メモリ上の $rules プロパティ）も合わせて
				// 古い状態にしないと、DBオプションを書き換えても内部キャッシュが優先されてしまう。
				update_option( 'rewrite_rules', $stale_rules );
				$wp_rewrite->rules = $stale_rules;

				// init 全体（他のコールバックも含む）を再実行するのではなく、対象関数を
				// 直接呼び出す。投稿タイプ・タクソノミーのリライト構造は set_up() で
				// 登録し直し済みのため、ここでの再登録は不要。
				// フックの登録先が wp_loaded であることは上のアサーションで別途検証済み
				bill_maybe_flush_rewrite_rules();

				$rules_after = get_option( 'rewrite_rules' );

				if ( $case['expect_flush'] ) {
					// 古いマーカールールが残っていないこと（作り直されたこと）
					$this->assertArrayNotHasKey(
						'stale-marker/?$',
						$rules_after,
						$case['test_condition_name'] . '（古いルールが破棄されていること）'
					);

					// 見積書（estimate）投稿タイプ用のルールが対応表に含まれていること
					$estimate_rules = array_filter(
						array_keys( (array) $rules_after ),
						function ( $rule ) {
							return false !== strpos( $rule, 'estimate' );
						}
					);
					$this->assertNotEmpty(
						$estimate_rules,
						$case['test_condition_name'] . '（見積書のルールが対応表に含まれること）'
					);

					// バージョン記録が現在のテーマバージョンに更新されていること
					$this->assertSame(
						BILLVEKTOR_THEME_VERSION,
						get_option( $this->option_name ),
						$case['test_condition_name'] . '（バージョン記録が更新されること）'
					);
				} else {
					// バージョンが一致している間は毎リクエストの重い作り直しを避けるため、
					// 古いままの対応表が維持されること
					$this->assertSame(
						$stale_rules,
						$rules_after,
						$case['test_condition_name'] . '（対応表が変更されず維持されること）'
					);
				}
			} catch ( \Throwable $e ) {
				// このケースの失敗を記録し、次のケースの検証を続行する
				$failures[] = $case['test_condition_name'] . "\n  " . $e->getMessage();
			}
		}

		// 収集した失敗が1件でもあれば、全件まとめて失敗理由を表示する
		$this->assertSame( array(), $failures, "以下のケースで失敗しました:\n\n" . implode( "\n\n", $failures ) );
	}
}
