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
 * 強制的に置き換えたうえで init を再実行し、テーマのバージョンと記録値が
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
		$this->set_permalink_structure( '/%postname%/' );
	}

	/**
	 * テスト後のクリーンアップ
	 *
	 * バージョン記録用オプションが他のテストへ持ち越されないよう削除する。
	 *
	 * @return void
	 */
	public function tear_down() {
		delete_option( $this->option_name );
		parent::tear_down();
	}

	/**
	 * bill_maybe_flush_rewrite_rules() のテスト
	 *
	 * バージョン記録の状態（未記録・記録が古い・記録が最新）ごとに、
	 * init 実行後の対応表（rewrite_rules オプション）とバージョン記録が
	 * 期待どおりになることを検証する。
	 *
	 * @return void
	 */
	public function test_bill_maybe_flush_rewrite_rules() {

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

				// カスタム投稿タイプ登録（優先度0）を含む init を再実行し、
				// 登録後にバージョン差分の判定が走る経路を通す
				do_action( 'init' );

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
