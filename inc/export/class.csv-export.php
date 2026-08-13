<?php
if ( ! class_exists( 'CsvExport' ) ) {

	class CsvExport {
		public static $version = '0.0.0';

		/**
		 * フック登録
		 *
		 * init のタイミングで CSV 出力処理を実行する。
		 *
		 * @return void
		 */
		public static function init() {
			add_action( 'init', array( __CLASS__, 'export_csv' ), 10, 2 );
		}

		/**
		 * CSV の1マス分の文字列を組み立てる（CSV インジェクション対策）
		 *
		 * CSV を表計算ソフトで開いた際、値の先頭が = + - @ やタブ・CR・LF だと
		 * 数式として実行されてしまう（CSV インジェクション）。これを防ぐため、
		 * 先頭の空白・不可視文字（全角/半角スペース・BOM・ゼロ幅スペース・NBSP 等。
		 * ただしタブ・CR・LF は除く）を読み飛ばした位置を見て、そこが該当する
		 * 先頭文字であれば元の値の先頭にシングルクォート（'）を付けて無害化する。
		 * UTF-8 として解釈できず判定できなかった値は安全側に倒して一律で無害化する。
		 * ただし符号・数字・カンマ・小数点だけでできた「純粋な数値」
		 * （例: -1,234 / +1,000.50 / -500 のようにカンマや小数点が無いものも含む）
		 * は対象から除外する。金額はマイナス表記で - から始まりうるうえ、
		 * bill_vektor_invoice_total_tax() の戻り値のように number_format() を
		 * 通さない素の数値（例: -500）がそのまま渡ることもある。これに ' を付けると
		 * 会計ソフトへの取り込みで金額が文字列扱いになり壊れてしまうため、符号・数字・
		 * カンマ・小数点だけでできた値は一律で対象外にする。この形は演算子や関数呼び出し
		 * （+1+1 / -1+1 / =SUM(A1) / @SUM(A1) 等）を含まないため数式として実行されず、
		 * 対象外にしてもリスクは増えない。
		 * なお CSV は HTML ではないため esc_html() は行わない（& が &amp; になる誤りを防ぐ）。
		 * 値中の " は "" に二重化し、最後に全体を " で囲んで返す。
		 *
		 * @param mixed $value CSV に出力する値。文字列以外（数値・null 等）も許容する。
		 * @return string CSV の1マス分の文字列（" で囲んだ形式）。
		 */
		public static function format_csv_cell( $value ) {
			// 文字列以外（数値・null 等）が渡される可能性があるため、先に文字列化する
			$value = (string) $value;

			// 先頭の空白・不可視文字（全角/半角スペース・BOM・ゼロ幅スペース・NBSP・
			// 垂直タブ・改頁 等）を読み飛ばした位置で判定する。文字コード変換や
			// 取り込みの過程でこれらの文字が落ちると、後ろに隠れていた数式が
			// 先頭に出てきて無害化が外れてしまうため。
			// ただしタブ（0x09）・CR（0x0D）・LF（0x0A）はそれ自体が無害化の
			// トリガー文字（先頭に来た時点で単独で対象になる）なので読み飛ばし対象
			// からは除外する。含めてしまうと、例えば "\t危険" のようにタブの後ろに
			// 数式が無い値まで読み飛ばしてしまい、タブ自体の検出ができなくなる
			$head = preg_replace( '/^(?:(?!\t|\r|\n)[\p{Z}\p{C}])+/u', '', $value );

			if ( null === $head ) {
				// preg_replace() は不正な UTF-8 を渡されると null を返し、先頭文字の
				// 判定ができない。無害化されないまま出力する方が危険なため、判定不能な
				// 値は安全側に倒して一律で無害化する（このブロックだけで ' を付けて
				// 完結させ、下の通常判定へは進ませないことで ' が二重に付くのを防ぐ）
				$value = "'" . $value;
			} else {
				// 符号・数字・カンマ・小数点だけでできた「純粋な数値」かどうかを判定する
				// カンマ・小数点の有無は問わない（-500 のような素の数値も対象に含める）。
				// 末尾アンカーは $ ではなく \z を使い、末尾の改行の直前にもマッチしてしまう
				// PCRE の $ の挙動（"-1234\n" を数値扱いしてしまう）を避ける
				$is_pure_number = (bool) preg_match( '/^[+\-]?[0-9,]*\.?[0-9]+\z/', $head );

				// 数式として実行されうる先頭文字（= + - @ やタブ・CR・LF）を無害化する
				// ただし「純粋な数値」は対象から除外する。判定・付与のどちらも
				// 空白等を読み飛ばした $head 側の先頭文字を見る
				if ( ! $is_pure_number && preg_match( '/^[=+\-@\t\r\n]/', $head ) ) {
					$value = "'" . $value;
				}
			}

			// CSV の決まりに従い " を "" に二重化する
			$value = str_replace( '"', '""', $value );

			// 全体を " で囲んで1マス分の文字列として返す
			return '"' . $value . '"';
		}

		/**
		 * CSV エクスポートを実行してよいリクエストか判定する
		 *
		 * URL の action パラメーターがエクスポート指定で、かつ実行ユーザーが投稿の編集権限を
		 * 持つ場合のみ true を返す。権限がない場合は wp_die せず false を返し、通常のページ
		 * 描画に処理を委ねる（未ログイン時は wp フックのリダイレクトでログイン画面へ誘導される）。
		 * nonce が欠落・不正な場合は CSRF とみなし、wp_nonce_ays() でコアのエラー画面
		 * （403）を表示して処理を中断する。万一 wp_die が戻る実装だった場合に備えて
		 * false も返す（fail-closed）。
		 *
		 * @return bool エクスポート処理を続行してよい場合は true、対象外の場合は false。
		 */
		public static function can_export() {
			// action パラメーターが CSV エクスポート指定でなければ対象外
			if ( ! isset( $_GET['action'] ) || ( 'csv_mf' !== $_GET['action'] && 'csv_freee' !== $_GET['action'] ) ) {
				return false;
			}

			// 請求書データの一括出力は編集権限を持つユーザーに限定する
			// ここで wp_die せず false を返すことで、未ログイン時はログイン画面へのリダイレクトに繋げる
			if ( ! current_user_can( 'edit_posts' ) ) {
				return false;
			}

			// CSRF 対策。エクスポートボタン側は wp_nonce_field() で _wpnonce を送出する
			// sanitize_key() を通すのは、配列など非スカラーの _wpnonce を渡された際に
			// wp_verify_nonce() 側で PHP 警告が出て（WP_DEBUG 環境で）ヘッダーより先に
			// 出力が発生し、403 が 200 に化けるのを防ぐため
			if ( ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( sanitize_key( $_GET['_wpnonce'] ), 'bill_csv_export' ) ) {
				// コアの nonce エラー画面に委ねる（403・ローカライズ済み文言・復帰リンクが揃う）
				wp_nonce_ays( 'bill_csv_export' );
				// wp_nonce_ays() は内部で wp_die() を呼ぶが、wp_die_handler フィルターで
				// 処理を終了しないハンドラに差し替えられていると下まで流れてしまう。
				// 検証を通っていないリクエストで CSV を出さないよう明示的に閉じる（fail-closed）。
				// コアの check_admin_referer() も同じ理由で wp_nonce_ays() の直後に die() を置いている。
				return false;
			}

			return true;
		}

		/**
		 * CSV 出力実行
		 *
		 * 権限・nonce のガードを通過したリクエストに対してのみ、
		 * MF クラウド会計用または freee 用の CSV を出力して処理を終了する。
		 *
		 * @return void
		 */
		public static function export_csv() {
			// $admin_options = get_option( 'bill-setting' );

			// ログイン・権限・nonce のガード。条件を満たさない場合は何も出力せずに戻る
			if ( ! self::can_export() ) {
				return;
			}

			/*
			CSVに出力する項目と順番
			/*-------------------------------------------*/

			// MF
			if ( $_GET['action'] == 'csv_mf' ) {
				$sort_data = array(
					'取引No',
					'取引日',
					'借方勘定科目',
					'借方補助科目',
					'借方部門',
					'借方取引先',
					'借方税区分',
					'借方インボイス',
					'借方金額(円)',
					'借方税額',
					'貸方勘定科目',
					'貸方補助科目',
					'貸方部門',
					'貸方取引先',
					'貸方税区分',
					'貸方インボイス',
					'貸方金額(円)',
					'貸方税額',
					'摘要',
					'仕訳メモ',
					'タグ',
					'MF仕訳タイプ',
					'決算整理仕訳',
					'作成日時',
					'作成者',
					'最終更新日時',
					'最終更新者',
				);

				// freee
			} elseif ( $_GET['action'] == 'csv_freee' ) {
				$sort_data = array( '収支区分', '管理番号', '発生日', '支払期日', '取引先', '勘定科目', '税区分', '金額', '税計算区分', '税額', '備考', '品目', '部門', 'メモタグ（複数指定可、カンマ区切り）', '支払日', '支払口座', '支払金額' );
			}

			// まずは配列に入っていたデータをCSVの1マス分の文字列に変換して格納
			foreach ( $sort_data as $key => $data ) {
				$c[] = self::format_csv_cell( $data );
			}
			// 配列を . 区切りで格納する
			$csv[] = implode( ',', $c );

			$start_date = ( isset( $_GET['start_date'] ) && $_GET['start_date'] ) ? $_GET['start_date'] : '';
			$end_date   = ( isset( $_GET['end_date'] ) && $_GET['end_date'] ) ? $_GET['end_date'] . ' 23:59:59' : '';
			$args       = array(
				'post_type'      => 'post',
				'posts_per_page' => -1,
				'date_query'     => array(
					array(
						'compare' => 'BETWEEN',
						'after'   => $start_date,
						'before'  => $end_date,
					),
				),
			);
			if ( isset( $_GET['bill_client'] ) && $_GET['bill_client'] ) {
				$args['meta_query'] = array(
					// 'relation' => 'AND',
					array(
						'key'     => 'bill_client',
						'value'   => esc_html( $_GET['bill_client'] ),
						'type'    => 'NUMERIC',
						'compare' => '=',
					),
				);
			}

			// キーワードの絞り込み
			// 検索ボックスと同じフォームから送信されるため、画面の絞り込み結果と
			// エクスポート内容が食い違わないようにキーワードも抽出条件に反映する
			$bill_keyword = bill_get_search_keyword();
			// キーワードが「0」の1文字でも絞り込みが効くよう、truthy 判定ではなく空文字と比較する
			if ( '' !== $bill_keyword ) {
				// WP_Query::parse_search() 内で stripslashes() されるため wp_slash() で付け直しておく
				$args['s'] = wp_slash( $bill_keyword );
				// 画面の絞り込みと同じ結果になるよう、検索対象を書類の件名だけに限定する
				$args['search_columns'] = bill_get_search_columns();
			}

			$posts = get_posts( $args );

			// CSV の1マス分は format_csv_cell() 側で無害化されるため、ここでは
			// HTML エスケープ（esc_html）ではなく入力値の一般的なサニタイズにとどめる。
			// esc_html() のままだと & が &amp; になってしまい、この列にだけ本 PR で
			// 直したはずの不具合が残ってしまう。また number_start[] のように配列で
			// 渡された場合に esc_html() 内で TypeError になるのも避ける
			$number = ( isset( $_GET['number_start'] ) && $_GET['number_start'] ) ? sanitize_text_field( wp_unslash( $_GET['number_start'] ) ) : '';

			/*
			売掛金用のレコード出力
			/*-------------------------------------------*/
			foreach ( $posts as $key => $post ) {

				setup_postdata( $GLOBALS['post'] =& $post );

				$date            = date_i18n( 'Y/n/j', strtotime( $post->post_date ) );
				$bill_limit_date = get_post_meta( $post->ID, 'bill_limit_date', true );
				$date_pay        = date( 'Y/n/j', bill_raw_date( $bill_limit_date ) );

				$bill_total_price = bill_vektor_invoice_total_tax( $post );
				$bill_tax_each    = bill_vektor_invoice_each_tax( $post );

				// 取引先名（省略名があれば省略名で表示）
				$client_name = bill_get_client_short_name( $post );

				// $client_invoice = get_post_meta( $post->bill_client, 'client_invoice', true );

				// $own_name = '';
				// if ( ! empty( $admin_options['own-name'] ) ) {
				// 	$own_name = $admin_options['own-name'];
				// }
				// $own_invoice = '';
				// if ( ! empty( $admin_options['invoice-number'] ) ) {
				// 	$own_invoice = $admin_options['invoice-number'];
				// }

				foreach ( $bill_tax_each  as $key => $value ) {

					if ( $_GET['action'] == 'csv_mf' ) {

						$c   = array();
						$c[] = self::format_csv_cell( $number );                                    // 取引No
						$c[] = self::format_csv_cell( $date );                                      // 取引日
						$c[] = self::format_csv_cell( '売掛金' );                                    // 借方勘定科目
						$c[] = self::format_csv_cell( '' );                                          // 借方補助科目
						$c[] = self::format_csv_cell( '' );                                          // 借方部門
						$c[] = self::format_csv_cell( $client_name );                                // 借方取引先
						$c[] = self::format_csv_cell( '対象外' );                                    // 借方税区分
						$c[] = self::format_csv_cell( '' );                                          // 借方インボイス
						$c[] = self::format_csv_cell( number_format( $value['total'] ) );            // 借方金額(円)
						$c[] = self::format_csv_cell( '' );                                          // 借方税額
						$c[] = self::format_csv_cell( '売上高' );                                    // 貸方勘定科目
						$c[] = self::format_csv_cell( '' );                                          // 貸方補助科目
						$c[] = self::format_csv_cell( '' );                                          // 貸方部門
						$c[] = self::format_csv_cell( $client_name );                                // 貸方取引先
						$c[] = self::format_csv_cell( '課売 ' . $key . ' 五種' );                     // 貸方税区分
						$c[] = self::format_csv_cell( '' );                                          // 貸方インボイス
						$c[] = self::format_csv_cell( number_format( $value['total'] ) );            // 貸方金額(円)
						$c[] = self::format_csv_cell( '' );                                          // 貸方税額
						$c[] = self::format_csv_cell( '[ ' . $client_name . ' ] ' . $post->post_title );    // 摘要
						$c[] = self::format_csv_cell( '' );                                          // 仕訳メモ
						$c[] = self::format_csv_cell( 'BillVektor' );                                // タグ
						$c[] = self::format_csv_cell( '' );                                          // MF仕訳タイプ
						$c[] = self::format_csv_cell( '' );                                          // 決算整理仕訳
						$c[] = self::format_csv_cell( date( 'Y/n/j H:i:s' ) );                       // 作成日時
						$c[] = self::format_csv_cell( '' );                                          // 作成者
						$c[] = self::format_csv_cell( '' );                                          // 最終更新日時
						$c[] = self::format_csv_cell( '' );                                          // 最終更新者

						// freee
					} elseif ( $_GET['action'] == 'csv_freee' ) {

						$c   = array();
						$c[] = self::format_csv_cell( '収入' );                                  // 収支区分
						$c[] = self::format_csv_cell( $post->bill_id );                          // 管理番号
						$c[] = self::format_csv_cell( $date );                                   // 発生日
						$c[] = self::format_csv_cell( $date_pay );                               // 支払期日
						$c[] = self::format_csv_cell( $client_name );                            // 取引先
						$c[] = self::format_csv_cell( '売上高' );                                // 勘定科目
						$c[] = self::format_csv_cell( '課税' . $key );                           // 税区分
						$c[] = self::format_csv_cell( number_format( $value['total'] ) );        // 金額(円)
						$c[] = self::format_csv_cell( '内税' );                                  // 税計算区分
						$c[] = self::format_csv_cell( $key );                                    // 税額
						$c[] = self::format_csv_cell( '' );                                      // 備考
						$c[] = self::format_csv_cell( $post->post_title );                       // 品目
						$c[] = self::format_csv_cell( '' );                                      // 部門
						$c[] = self::format_csv_cell( 'BillVektor' );                            // メモタグ（複数指定可、カンマ区切り）
						$c[] = self::format_csv_cell( '' );                                      // 支払日
						$c[] = self::format_csv_cell( '' );                                      // 支払口座
						$c[] = self::format_csv_cell( '' );                                      // 支払金額

					}

					// 配列を , 区切りで格納
					$csv[] = implode( ',', $c );
					if ( $number ) {
						++$number;
					}
				}
			}

			wp_reset_postdata();

			if ( $_GET['action'] == 'csv_mf' ) {

				/*
				売掛金の入金用レコード
				/*-------------------------------------------*/
				foreach ( $posts as $key => $post ) {

					setup_postdata( $GLOBALS['post'] =& $post );

					$bill_limit_date  = get_post_meta( $post->ID, 'bill_limit_date', true );
					$date_pay         = date( 'Y/n/j', bill_raw_date( $bill_limit_date ) );
					$bill_total_price = bill_vektor_invoice_total_tax( $post );
					// 取引先名（省略名があれば省略名で表示）
					$client_name = bill_get_client_short_name( $post );

					$c   = array();
					$c[] = self::format_csv_cell( $number );                                        // 取引No
					$c[] = self::format_csv_cell( $date_pay );                                       // 取引日
					$c[] = self::format_csv_cell( '普通預金' );                                      // 借方勘定科目
					$c[] = self::format_csv_cell( '' );                                              // 借方補助科目
					$c[] = self::format_csv_cell( '' );                                              // 借方部門
					$c[] = self::format_csv_cell( '' );                                              // 借方取引先
					$c[] = self::format_csv_cell( '対象外' );                                        // 借方税区分
					$c[] = self::format_csv_cell( '' );                                              // 借方インボイス
					$c[] = self::format_csv_cell( $bill_total_price );                               // 借方金額(円)
					$c[] = self::format_csv_cell( '' );                                              // 借方税額
					$c[] = self::format_csv_cell( '売掛金' );                                        // 貸方勘定科目
					$c[] = self::format_csv_cell( '' );                                              // 貸方補助科目
					$c[] = self::format_csv_cell( '' );                                              // 貸方部門
					$c[] = self::format_csv_cell( '' );                                              // 貸方取引先
					$c[] = self::format_csv_cell( '対象外' );                                        // 貸方税区分
					$c[] = self::format_csv_cell( '' );                                              // 貸方インボイス
					$c[] = self::format_csv_cell( $bill_total_price );                               // 貸方金額(円)
					$c[] = self::format_csv_cell( '' );                                              // 貸方税額
					$c[] = self::format_csv_cell( '[ ' . $client_name . ' ] ' . $post->post_title );    // 摘要
					$c[] = self::format_csv_cell( '' );                                              // 仕訳メモ
					$c[] = self::format_csv_cell( 'BillVektor' );                                    // タグ
					$c[] = self::format_csv_cell( '未実現' );                                        // MF仕訳タイプ
					$c[] = self::format_csv_cell( '' );                                              // 決算整理仕訳
					$c[] = self::format_csv_cell( date( 'Y/n/j H:i:s' ) );                           // 作成日時
					$c[] = self::format_csv_cell( '' );                                              // 作成者
					$c[] = self::format_csv_cell( '' );                                              // 最終更新日時
					$c[] = self::format_csv_cell( '' );                                              // 最終更新者
					// 配列を , 区切りで格納
					$csv[] = implode( ',', $c );
					if ( $number ) {
						++$number;
					}
				}

				wp_reset_postdata();

			} // if ( $_GET['action'] == 'csv_mf' ){

			$full_csv = implode( "\r\n", $csv );

			// CSVで出力実行
			// 請求データは機密情報のため、CDN・プロキシ・ブラウザにキャッシュさせない
			nocache_headers();
			if ( $_GET['action'] == 'csv_mf' ) {
				header( 'Content-Type: text/csv; charset=shift_jis' );
				// SJIS に変換できない文字を "?"（0x3F）に置き換える。php.ini の
				// mbstring.substitute_character 設定に依存させないための明示指定
				mb_substitute_character( 0x3F );
				$full_csv = mb_convert_encoding( $full_csv, 'SJIS' );
			} else {
				header( 'Content-Type: text/csv; charset=utf-8' );
			}

			// header("Content-Type: text/csv; charset=utf-8");
			// ブラウザが Content-Type だけで挙動を決めてしまわないよう、ダウンロード対象
			// であることと MIME スニッフィングを行わないことを明示する
			header( 'X-Content-Type-Options: nosniff' );
			header( 'Content-Disposition: attachment; filename=export.csv' );

			echo $full_csv;

			die();

		}
	}

	CsvExport::init();
}
