<?php
/*
  bill_form_post_value()

	8桁の数字で保存されているデータをUnixタイムスタンプに変換
  bill_raw_date()

  bill_item_number()
  bill_item_price_total()

	書類の税抜き合計
  bill_total_no_tax()

  消費税を計算
  bill_tax()


  Chack post type info
  bill_get_post_type()

  bill_get_terms()
/*-------------------------------------------*/


function bill_form_post_value( $post_field, $type = false ) {
	  $value = '';
	  global $post;
	if ( isset( $post_field ) && $post_field ) {
		if ( isset( $type ) && $type == 'textarea' ) {
			// n2brはフォームにbrがそのまま入ってしまうので入れない
			$value = esc_textarea( $post_field );
		} else {
			$value = esc_attr( $post_field );
		}
	} elseif ( isset( $post->$post_field ) && $post->$post_field ) {
		$value = $post->$post_field;
	}
	  return $value;
}



// 8桁の数字で保存されているデータをUnixタイムスタンプに変換
function bill_raw_date( $date ) {
	$year     = substr( $date, 0, 4 );
	$month    = substr( $date, 4, 2 );
	$day      = substr( $date, 6, 2 );
	$raw_date = strtotime( $year . '-' . $month . '-' . $day . ' 00:00:00' );
	return $raw_date;
}

function bill_item_number( $number = 0 ) {
	// 全角を半額に変換
	$number = mb_convert_kana( $number, 'a' );
	// , が入ってたら除去
	$number = str_replace( ',', '', $number );
	return $number;
}

function bill_item_price_total( $count = 0, $price = 0 ) {
	// 数量×単価
	$item_price_total = round( $count * $price );
	return $item_price_total;
}


// 書類の税抜き合計
function bill_total_no_tax( $post ) {
	// global $post;
	$bill_items           = get_post_meta( $post->ID, 'bill_items', true );
	$bill_item_sub_fields = array( 'name', 'count', 'unit', 'price' );
	$bill_total           = 0;

	if ( is_array( $bill_items ) ) {

		// 行のループ
		foreach ( $bill_items as $key => $value ) {
			// $item_count
			if ( $bill_item['count'] === '' ) {
				$item_count = '';
			} else {
				// intvalは小数点が切り捨てられるので使用していない
				$item_count = bill_item_number( $bill_item['count'] );
			}

			// $item_price
			if ( $bill_item['price'] === '' ) {
				$item_price       = '';
				$item_price_print = '';
			} else {
				$item_price       = bill_item_number( $bill_item['price'] );
				$item_price_print = '¥ ' . number_format( $item_price );
			}
			// $item_total
			if ( $item_count && $item_price ) {
				$item_price_total       = bill_item_price_total( $item_count, $item_price );
				$item_price_total_print = '¥ ' . number_format( $item_price_total );
			} else {
				$item_price_total       = '';
				$item_price_total_print = '';
			}

			// 小計
			$bill_total += (int) $item_price_total;

		} // foreach ($bill_items as $key => $value) {

	} // if ( is_array( $bill_items ) ) {

	return $bill_total;
}


/**
 * インボイス対応の税率ごとの合計金額
 */
function bill_vektor_invoice_each_tax( $post ) {
	// カスタムフィールドを取得
	$bill_items = get_post_meta( $post->ID, 'bill_items', true );
	// 消費税率の配列
	$tax_array = bill_vektor_tax_array();
	// 税率ごとに税込み金額・消費税額・合計金額を算出した配列を初期化
	$tax_total = array();
	// 古い消費税率
	$old_tax_rate = get_post_meta( $post->ID, 'bill_tax_rate', true );
	// 古い税込・税抜
	$old_tax_type = get_post_meta( $post->ID, 'bill_tax_type', true );
	if ( is_array( $bill_items ) ) {
		// 行のループ
		foreach ( $bill_items as $bill_item ) {
			$bill_item['tax-rate'] = ! empty( $bill_item['tax-rate'] ) ? $bill_item['tax-rate'] : $old_tax_rate . '%';
			if ( empty( $bill_item['tax-type'] ) ) {
				if ( 'tax_not_auto' === $old_tax_type ) {
					$bill_item['tax-type'] = 'tax_included';
				} else {
					$bill_item['tax-type'] = 'tax_excluded';
				}
			}
			// すべてが埋まっていない行は算出対象外に
			if ( 
				! empty( $bill_item['name'] ) &&
				! empty( $bill_item['count'] ) &&
				! empty( $bill_item['unit'] ) &&
				! empty( $bill_item['price'] ) &&
				! empty( $bill_item['tax-rate'] ) &&
				! empty( $bill_item['tax-type'] ) 
			) {
				// 税率ごとのループ
				foreach( $tax_array as $tax_rate ) {
					// 税率のループとカスタムフィールドのループが同じ値の場合
					if ( $bill_item['tax-rate'] === $tax_rate ) {

						// 税率を数値に変換
						$item_tax_rate  = 0.01 * intval( str_replace( '%', '', $bill_item['tax-rate'] ) );
						// 単価を数値に変換
						$item_price = bill_item_number( $bill_item['price'] );
						if ( 'tax_included' === $bill_item['tax-type'] ) {
							$item_price = $item_price / ( 1 + $item_tax_rate );
						}
						// 個数を数値に変換						
						$item_count = bill_item_number( $bill_item['count'] );

						// 上記３つが数値なら
						if ( is_numeric( $item_count ) && is_numeric( $item_price ) && is_numeric( $item_tax_rate ) ) {
							// 税抜か税込かで合計金額を算出
							$item_total     = $item_price * $item_count;						
							// 品目ごとの消費税額
							$item_tax_value = $item_total * $item_tax_rate;
							// 品目ごとの税込合計金額
							$item_tax_total = $item_total + $item_tax_value;

							// 税率何％の対象か
							$tax_total[$tax_rate]['rate']  = $bill_item['tax-rate'] . '対象';
							// 対象税率の税抜き合計金額
							$tax_total[$tax_rate]['price'] = ! empty( $tax_total[$tax_rate]['price'] ) ? $tax_total[$tax_rate]['price'] + $item_total : $item_total;
							// 対象税率の消費税額
							$tax_total[$tax_rate]['tax']   = ! empty( $tax_total[$tax_rate]['tax'] )   ? $tax_total[$tax_rate]['tax'] + $item_tax_value : $item_tax_value;
							// 対象税率の税込合計金額
							$tax_total[$tax_rate]['total'] = ! empty( $tax_total[$tax_rate]['total'] ) ? $tax_total[$tax_rate]['total'] + $item_tax_total : $item_tax_total;
						}
					}
				}
			}
		}
	}
	return $tax_total;
}

/**
 * インボイス対応の合計金額
 */
function bill_vektor_invoice_total_tax( $post ) {
	// カスタムフィールドを取得
	$bill_items           = get_post_meta( $post->ID, 'bill_items', true );
	// 支払い総額を初期化
	$bill_total           = 0;
	// 古い消費税率
	$old_tax_rate = get_post_meta( $post->ID, 'bill_tax_rate', true );
	// 古い税込・税抜
	$old_tax_type = get_post_meta( $post->ID, 'bill_tax_type', true );

	if ( is_array( $bill_items ) ) {
		// 行のループ
		foreach ( $bill_items as $bill_item ) {
			$bill_item['tax-rate'] = ! empty( $bill_item['tax-rate'] ) ? $bill_item['tax-rate'] : $old_tax_rate . '%';
			if ( empty( $bill_item['tax-type'] ) ) {
				if ( 'tax_not_auto' === $old_tax_type ) {
					$bill_item['tax-type'] = 'tax_included';
				} else {
					$bill_item['tax-type'] = 'tax_excluded';
				}
			}
			// すべてが埋まっていない行は算出対象外に
			if ( 
				! empty( $bill_item['name'] ) &&
				! empty( $bill_item['count'] ) &&
				! empty( $bill_item['unit'] ) &&
				! empty( $bill_item['price'] ) &&
				! empty( $bill_item['tax-rate'] ) &&
				! empty( $bill_item['tax-type'] ) 
			) {
				// 税率を数値に変換
				$item_tax_rate  = 0.01 * intval( str_replace( '%', '', $bill_item['tax-rate'] ) );
				// 単価を数値に変換
				$item_price = bill_item_number( $bill_item['price'] );
				if ( 'tax_included' === $bill_item['tax-type'] ) {
					$item_price = $item_price / ( 1 + $item_tax_rate );
				}
				// 個数を数値に変換						
				$item_count = bill_item_number( $bill_item['count'] );

				// 上記３つが数値なら合計金額を算出
				if ( is_numeric( $item_count ) && is_numeric( $item_price ) && is_numeric( $item_tax_rate ) ) {
					$bill_total += $item_price * $item_count * ( 1 + $item_tax_rate );			
				}
			}
		} // foreach ($bill_items as $key => $value) {
	} // if ( is_array( $bill_items ) ) {

	return $bill_total;
}

/*
	Chack post type info
  bill_get_post_type()
/*-------------------------------------------*/
function bill_get_post_type() {

	// Get post type slug
	/*-------------------------------------------*/
	global $wp_query;
	if ( is_post_type_archive() || $wp_query->query_vars['post_type'] ) {
		$post_type['slug'] = $wp_query->query_vars['post_type'];
	} elseif ( is_tax() || is_category() ) {
		$taxonomy = get_queried_object()->taxonomy;
		if ( $taxonomy ) {
			$post_type['slug'] = get_taxonomy( $taxonomy )->object_type[0];
		}
	} elseif ( is_front_page() ) {
		$post_type['slug'] = 'post';
	} else {
		$post_type['slug'] = 'post';
	}

	if( !post_type_exists( $post_type['slug'] ) ) {
	    $post_type['slug'] = "post";
    }

	// Get post type name
	/*-------------------------------------------*/
	$post_type_object = get_post_type_object( $post_type['slug'] );

	$post_type['name'] = esc_html( $post_type_object->labels->name );
	$post_type['url']  = home_url() . '/?post_type=' . $post_type['slug'];

	return $post_type;
}

/*
-------------------------------------------*/
/*
  bill_get_terms()
/*-------------------------------------------*/
function bill_get_terms() {
	global $post;
	$postType = get_post_type();
	if ( $postType == 'post' ) {
		$taxonomySlug = 'category';
	} else {
		$taxonomies = get_the_taxonomies();
		// print '<pre style="text-align:left">';print_r($taxonomies);print '</pre>';
		if ( $taxonomies ) {
			foreach ( $taxonomies as $taxonomySlug => $taxonomy ) {
			}
		} else {
			$taxonomySlug = '';
		}
	}

	$taxo_catelist = get_the_term_list( $post->ID, $taxonomySlug, ' ', ', ', '' );
	return $taxo_catelist;
}

function bill_get_client_name( $post ) {
	if ( $post->bill_client_name_manual ){
		$client_name = $post->bill_client_name_manual;
	} else {
		$client_name = get_the_title( $post->bill_client );
	}
	return $client_name;
}

function bill_get_client_honorific( $post ){
	if ( empty( $post->bill_client_name_manual ) ){
		$client_honorific = esc_html( get_post_meta( $post->bill_client, 'client_honorific', true ) );
		if ( $client_honorific ) {
			echo $client_honorific;
		} else {
			echo '御中';
		}
	}
}