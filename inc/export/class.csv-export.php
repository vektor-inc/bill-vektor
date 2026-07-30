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

			// まずは配列に入っていたデータをCSV用に "" で囲んで格納
			foreach ( $sort_data as $key => $data ) {
				$c[] = '"' . $data . '"';
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

			$number = ( isset( $_GET['number_start'] ) && $_GET['number_start'] ) ? esc_html( $_GET['number_start'] ) : '';

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
				$client_name = get_post_meta( $post->bill_client, 'client_short_name', true );
				if ( ! $client_name ) {
					$client_name = bill_get_client_name( $post );
				}

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
						$c[] = '"' . $number . '"';                           // 取引No
						$c[] = '"' . $date . '"';                             // 取引日
						$c[] = '"売掛金"';                                     // 借方勘定科目
						$c[] = '""';                                          // 借方補助科目
						$c[] = '""';                                          // 借方部門
						$c[] = '"' . esc_html( $client_name ) . '"';          // 借方取引先
						$c[] = '"対象外"';                                     // 借方税区分
						$c[] = '""';                                          // 借方インボイス
						$c[] = '"' . number_format( $value['total'] ) . '"';  // 借方金額(円)
						$c[] = '""';                                          // 借方税額
						$c[] = '"売上高"';                                     // 貸方勘定科目
						$c[] = '""';                                          // 貸方補助科目
						$c[] = '""';                                          // 貸方部門
						$c[] = '"' . esc_html( $client_name ) . '"';          // 貸方取引先
						$c[] = '"課売 ' . $key . ' 五種"';                     // 貸方税区分
						$c[] = '""';                                          // 貸方インボイス
						$c[] = '"' . number_format( $value['total'] ) . '"';  // 貸方金額(円)
						$c[] = '""';                                          // 貸方税額
						$c[] = '"[ ' . esc_html( $client_name ) . ' ] ' . esc_html( $post->post_title ) . '"';    // 摘要
						$c[] = '""';                                          // 仕訳メモ
						$c[] = '"BillVektor"';                                // タグ
						$c[] = '""';                                          // MF仕訳タイプ
						$c[] = '""';                                          // 決算整理仕訳
						$c[] = '"' . date( 'Y/n/j H:i:s' ) . '"';             // 作成日時
						$c[] = '""';                                          // 作成者
						$c[] = '""';                                          // 最終更新日時
						$c[] = '""';                                          // 最終更新者

						// freee
					} elseif ( $_GET['action'] == 'csv_freee' ) {

						$c   = array();
						$c[] = '"収入"';                                  // 収支区分
						$c[] = '"' . esc_html( $post->bill_id ) . '"';        // 管理番号
						$c[] = '"' . $date . '"';                           // 発生日
						$c[] = '"' . $date_pay . '"';                       // 支払期日
						$c[] = '"' . esc_html( $client_name ) . '"';        // 取引先
						$c[] = '"売上高"';                             // 勘定科目
						$c[] = '"課税' . $key . '"';                                // 税区分
						$c[] = '"' . number_format( $value['total'] ) . '"';             // 金額(円)
						$c[] = '"内税"';                                  // 税計算区分
						$c[] = '"' . $key . '"';                            // 税額
						$c[] = '""';                                    // 備考
						$c[] = '"' . esc_html( $post->post_title ) . '"';   // 品目
						$c[] = '""';                                    // 部門
						$c[] = '"BillVektor"';                          // メモタグ（複数指定可、カンマ区切り）
						$c[] = '""';                                    // 支払日
						$c[] = '""';                                    // 支払口座
						$c[] = '""';                                    // 支払金額

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
					$client_name = get_post_meta( $post->bill_client, 'client_short_name', true );
					if ( ! $client_name ) {
						$client_name = bill_get_client_name( $post );
					}

					$c   = array();
					$c[] = '"' . $number . '"';                           // 取引No
					$c[] = '"' . $date_pay . '"';                         // 取引日
					$c[] = '"普通預金"';                                   // 借方勘定科目
					$c[] = '""';                                          // 借方補助科目
					$c[] = '""';                                          // 借方部門
					$c[] = '""';                                          // 借方取引先
					$c[] = '"対象外"';                                     // 借方税区分
					$c[] = '""';                                          // 借方インボイス
					$c[] = '"' . $bill_total_price . '"';                 // 借方金額(円)
					$c[] = '""';                                          // 借方税額
					$c[] = '"売掛金"';                                     // 貸方勘定科目
					$c[] = '""';                                          // 貸方補助科目
					$c[] = '""';                                          // 貸方部門
					$c[] = '""';                                          // 貸方取引先
					$c[] = '"対象外"';                                     // 貸方税区分
					$c[] = '""';                                          // 貸方インボイス
					$c[] = '"' . $bill_total_price . '"';                 // 貸方金額(円)
					$c[] = '""';                                          // 貸方税額
					$c[] = '"[ ' . esc_html( $client_name ) . ' ] ' . esc_html( $post->post_title ) . '"';    // 摘要
					$c[] = '""';                                          // 仕訳メモ
					$c[] = '"BillVektor"';                                // タグ
					$c[] = '"未実現"';                                          // MF仕訳タイプ
					$c[] = '""';                                          // 決算整理仕訳
					$c[] = '"' . date( 'Y/n/j H:i:s' ) . '"';             // 作成日時
					$c[] = '""';                                          // 作成者
					$c[] = '""';                                          // 最終更新日時
					$c[] = '""';                                          // 最終更新者
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
				$full_csv = mb_convert_encoding( $full_csv, 'SJIS' );
			} else {
				header( 'Content-Type: text/csv; charset=utf-8' );
			}

			// header("Content-Type: text/csv; charset=utf-8");
			header( 'Content-Disposition: filename=export.csv' );

			echo $full_csv;

			die();

		}
	}

	CsvExport::init();
}
