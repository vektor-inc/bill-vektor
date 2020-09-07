<?php
if ( ! class_exists( 'CsvExport' ) ) {

	class CsvExport {
		public static $version = '0.0.0';

		public static function init() {
			add_action( 'init', array( __CLASS__, 'export_csv' ), 10, 2 );
		}

		// CSV 出力実行
		public static function export_csv() {
			if ( isset( $_GET['action'] ) && ( $_GET['action'] == 'csv_mf' || $_GET['action'] == 'csv_freee' ) ) {

				/*
				CSVに出力する項目と順番
				/*-------------------------------------------*/

				// MF
				if ( $_GET['action'] == 'csv_mf' ) {
					$sort_data = array( '取引No', '取引日', '借方勘定科目', '借方補助科目', '借方税区分', '借方部門', '借方金額(円)', '借方税額', '貸方勘定科目', '貸方補助科目', '貸方税区分', '貸方部門', '貸方金額(円)', '貸方税額', '摘要', '仕訳メモ', 'タグ', 'MF仕訳タイプ', '決算整理仕訳', '作成日時', '最終更新日時' );

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

				$get = $_GET;

				foreach ( $get as $key => $value ) {
					$args[ $key ] = $value;
				}

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
				if ( isset( $_GET['client'] ) && $_GET['client'] ) {
					$args['meta_query'] = array(
						// 'relation' => 'AND',
						array(
							'key'     => 'bill_client',
							'value'   => esc_html( $_GET['client'] ),
							'type'    => 'NUMERIC',
							'compare' => '=',
						),
					);
				}
				$posts = get_posts( $args );

				$number = ( isset( $_GET['number_start'] ) && $_GET['number_start'] ) ? esc_html( $_GET['number_start'] ) : '';

				/*
				売掛金用のレコード出力
				/*-------------------------------------------*/
				foreach ( $posts as $key => $post ) {

				    setup_postdata($GLOBALS['post'] =& $post);

					$date               = date_i18n( 'Y/n/j', strtotime( $post->post_date ) );
					$bill_limit_date    = get_post_meta( $post->ID, 'bill_limit_date', true );
					$date_pay           = date( 'Y/n/j', bill_raw_date( $bill_limit_date ) );
					$bill_total_add_tax = bill_total_add_tax( $post );
					$tax                = round( bill_total_no_tax( $post ) * 0.08 );

					// 取引先名（省略名があれば省略名で表示）
					$client_name = get_post_meta( $post->bill_client, 'client_short_name', true );
					if ( ! $client_name ) {
						$client_name = get_the_title( $post->bill_client );
					}

					$tax_rate = bill_tax_rate( $post->ID ) * 100;

					if ( $_GET['action'] == 'csv_mf' ) {

						$c   = array();
						$c[] = '"' . $number . '"';         // 取引No
						$c[] = '"' . $date . '"';           // 取引日
						$c[] = '"売掛金"';             // 借方勘定科目
						$c[] = '""';                    // 借方補助科目
						$c[] = '"対象外"';             // 借方税区分
						$c[] = '""';                    // 借方部門
						$c[] = '"' . $bill_total_add_tax . '"'; // 借方金額(円)
						$c[] = '""';                    // 借方税額
						$c[] = '"売上高"';             // 貸方勘定科目
						$c[] = '""';                    // 貸方補助科目
						$c[] = '"課売 ' . $tax_rate . '% 五種"';            // 貸方税区分
						$c[] = '""';                    // 貸方部門
						$c[] = '"' . $bill_total_add_tax . '"'; // 貸方金額(円)
						$c[] = '""';                    // 貸方税額
						$c[] = '"[ ' . esc_html( $client_name ) . ' ] ' . esc_html( $post->post_title ) . '"';  // 摘要
						$c[] = '""';                    // 仕訳メモ
						$c[] = '"BillVektor"';                  // タグ
						$c[] = '""';                    // MF仕訳タイプ
						$c[] = '""';                    // 決算整理仕訳
						$c[] = '"' . date( 'Y/n/j H:i:s' ) . '"'; // 作成日時
						$c[] = '""';                    // 最終更新日時

						// freee
					} elseif ( $_GET['action'] == 'csv_freee' ) {

						$c   = array();
						$c[] = '"収入"';                                  // 収支区分
						$c[] = '"' . esc_html( $post->bill_id ) . '"';        // 管理番号
						$c[] = '"' . $date . '"';                           // 発生日
						$c[] = '"' . $date_pay . '"';                       // 支払期日
						$c[] = '"' . esc_html( $client_name ) . '"';        // 取引先
						$c[] = '"売上高"';                             // 勘定科目
						$c[] = '"課税' . $tax_rate . '%"';                                // 税区分
						$c[] = '"' . $bill_total_add_tax . '"';             // 金額(円)
						$c[] = '"内税"';                                  // 税計算区分
						$c[] = '"' . $tax . '"';                            // 税額
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
						$number ++;
					}
				}

                wp_reset_postdata();

				if ( $_GET['action'] == 'csv_mf' ) {

					/*
					売掛金の入金用レコード
					/*-------------------------------------------*/
					foreach ( $posts as $key => $post ) {

                        setup_postdata($GLOBALS['post'] =& $post);

						$bill_limit_date    = get_post_meta( $post->ID, 'bill_limit_date', true );
						$date_pay           = date( 'Y/n/j', bill_raw_date( $bill_limit_date ) );
						$bill_total_add_tax = bill_total_add_tax( $post );

						// 取引先名（省略名があれば省略名で表示）
						$client_name = get_post_meta( $post->bill_client, 'client_short_name', true );
						if ( ! $client_name ) {
							$client_name = get_the_title( $post->bill_client );
						}

						$c   = array();
						$c[] = '"' . $number . '"';         // 取引No
						$c[] = '"' . $date_pay . '"';       // 取引日
						$c[] = '"普通預金"';                // 借方勘定科目
						$c[] = '""';                    // 借方補助科目
						$c[] = '"対象外"';             // 借方税区分
						$c[] = '""';                    // 借方部門
						$c[] = '"' . $bill_total_add_tax . '"'; // 借方金額(円)
						$c[] = '""';                    // 借方税額
						$c[] = '"売掛金"';             // 貸方勘定科目
						$c[] = '""';                    // 貸方補助科目
						$c[] = '"対象外"';             // 貸方税区分
						$c[] = '""';                    // 貸方部門
						$c[] = '"' . $bill_total_add_tax . '"'; // 貸方金額(円)
						$c[] = '""';                    // 貸方税額
						$c[] = '"[ ' . esc_html( $client_name ) . ' ] ' . esc_html( $post->post_title ) . '"';  // 摘要
						$c[] = '""';                    // 仕訳メモ
						$c[] = '"BillVektor"';          // タグ
						$c[] = '"未実現"';             // MF仕訳タイプ
						$c[] = '""';                    // 決算整理仕訳
						$c[] = '"' . date( 'Y/n/j H:i:s' ) . '"'; // 作成日時
						$c[] = '""';                    // 最終更新日時
						// 配列を , 区切りで格納
						$csv[] = implode( ',', $c );
						if ( $number ) {
							$number ++;
						}
					}

                    wp_reset_postdata();

				} // if ( $_GET['action'] == 'csv_mf' ){

				$full_csv = implode( "\r\n", $csv );

				// CSVで出力実行
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

			} // if( isset( $_GET['csv'] ) && $_GET['csv'] == 'y' ){
		}
	}

	CsvExport::init();
}
