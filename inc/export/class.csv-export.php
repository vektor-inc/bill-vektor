<?php
if ( ! class_exists( 'CsvExport' ) ) {

    class CsvExport {
		public static $version = '0.0.0';

		public static function init() {
			add_action( 'init' , array( __CLASS__, 'export_csv'), 10, 2);
		}

        // CSV 出力実行
        public static function export_csv()
        {
			if ( isset( $_GET['action'] ) && $_GET['action'] == 'csv_mf' ){	

			// CSVに出力する項目と順番
			$sort_data = array( '取引No', '取引日', '借方勘定科目', '借方補助科目', '借方税区分', '借方部門', '借方金額(円)', '借方税額', '貸方勘定科目', '貸方補助科目', '貸方税区分', '貸方部門', '貸方金額(円)', '貸方税額', '摘要', '仕訳メモ', 'タグ', 'MF仕訳タイプ', '決算整理仕訳', '作成日時', '最終更新日時' );

			// まずは配列に入っていたデータをCSV用に "" で囲んで格納
			foreach ($sort_data as $key => $data) {
				$c[] = '"'.$data.'"';
			}
			// 配列を . 区切りで格納する
			$csv[] = implode(',', $c);

			$get = $_GET;

			foreach ( $get as $key => $value ) {
				$args[$key] = $value;
			}

			$start_date = ( isset( $_GET['start_date'] ) && $_GET['start_date'] ) ? $_GET['start_date'] : '';
			$end_date = ( isset( $_GET['end_date'] ) && $_GET['end_date'] ) ? $_GET['end_date'].' 23:59:59' : '';
			$args = array(
				'post_type'      => 'post',
				'posts_per_page' => -1,
				'date_query'     => array( array(
							'compare' =>'BETWEEN',
							'after'   => $start_date,
							'before'  => $end_date
					) )
				);
			if ( isset( $_GET['client'] ) && $_GET['client'] ) {
				$args['meta_query'] = array(
					// 'relation' => 'AND', 
					array(
						'key' => 'bill_client',
						'value' => esc_html( $_GET['client'] ),
						'type' => 'NUMERIC',
						'compare' => '='
					),
				);
			}
			$posts = get_posts( $args );

			// 売掛金
			$number = ( isset( $_GET['number_start'] ) && $_GET['number_start'] ) ? esc_html( $_GET['number_start'] ) : '' ;
			foreach ( $posts as $key => $post ) { 
				$date = date_i18n( "Y/n/j", strtotime( $post->post_date ) );
				$bill_total_add_tax = bill_total_add_tax($post);
				$bill_client = get_the_title( $post->bill_client );
				$c = '';
				$c[] = '"'.$number.'"';			// 取引No
				$c[] = '"'.$date.'"';			// 取引日
				$c[] = '"売掛金"';				// 借方勘定科目
				$c[] = '""';					// 借方補助科目
				$c[] = '"対象外"';				// 借方税区分
				$c[] = '""';					// 借方部門
				$c[] = '"'.$bill_total_add_tax.'"';	// 借方金額(円)
				$c[] = '""';					// 借方税額
				$c[] = '"売上高"';				// 貸方勘定科目
				$c[] = '""';					// 貸方補助科目
				$c[] = '"課売 8% 五種"';			// 貸方税区分
				$c[] = '""';					// 貸方部門
				$c[] = '"'.$bill_total_add_tax.'"';	// 貸方金額(円)
				$c[] = '""';					// 貸方税額
				$c[] = '"[ '. $bill_client .' ] '.$post->post_title.'"';	// 摘要
				$c[] = '""';					// 仕訳メモ
				$c[] = '"billvektor"';					// タグ
				$c[] = '""';					// MF仕訳タイプ
				$c[] = '""';					// 決算整理仕訳
				$c[] = '"'.date("Y/n/j H:i:s").'"';	// 作成日時
				$c[] = '""';					// 最終更新日時
				// 配列を , 区切りで格納
				$csv[] = implode(',', $c);
				if ( $number ) $number ++;		
			}

			// 売掛金の入金用レコード
			foreach ( $posts as $key => $post ) {
				$bill_limit_date = get_post_meta( $post->ID, 'bill_limit_date', true );
				$date_pay = date("Y/n/j", bill_raw_date( $bill_limit_date ) );
				$bill_total_add_tax = bill_total_add_tax($post);
				$bill_client = get_the_title( $post->bill_client );
				$c = '';
				$c[] = '"'.$number.'"';			// 取引No
				$c[] = '"'.$date_pay.'"';		// 取引日
				$c[] = '"普通預金"';				// 借方勘定科目
				$c[] = '""';					// 借方補助科目
				$c[] = '"対象外"';				// 借方税区分
				$c[] = '""';					// 借方部門
				$c[] = '"'.$bill_total_add_tax.'"';	// 借方金額(円)
				$c[] = '""';					// 借方税額
				$c[] = '"売掛金"';				// 貸方勘定科目
				$c[] = '""';					// 貸方補助科目
				$c[] = '"対象外"';				// 貸方税区分
				$c[] = '""';					// 貸方部門
				$c[] = '"'.$bill_total_add_tax.'"';	// 貸方金額(円)
				$c[] = '""';					// 貸方税額
				$c[] = '"[ '. $bill_client .' ] '.$post->post_title.'"';	// 摘要
				$c[] = '""';					// 仕訳メモ
				$c[] = '"billvektor"';			// タグ
				$c[] = '"未実現"';				// MF仕訳タイプ
				$c[] = '""';					// 決算整理仕訳
				$c[] = '"'.date("Y/n/j H:i:s").'"';	// 作成日時
				$c[] = '""';					// 最終更新日時
				// 配列を , 区切りで格納
				$csv[] = implode(',', $c);
				if ( $number ) $number ++;
			}

			// CSVで出力実行
			header("Content-Type: text/csv; charset=shift_jis");
			// header("Content-Type: text/csv; charset=utf-8");
			header("Content-Disposition: filename=export.csv");
			$full_csv = implode("\r\n", $csv);

			echo mb_convert_encoding( $full_csv, "SJIS" );

			die();

			} // if( isset( $_GET['csv'] ) && $_GET['csv'] == 'y' ){	
        }
    }

    CsvExport::init();
}