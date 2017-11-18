<?php
/*-------------------------------------------*/
/*  bill_form_post_value()
/*-------------------------------------------*/
/*  bill_raw_date()
/*-------------------------------------------*/
/*  bill_item_number()
/*-------------------------------------------*/
/*  bill_item_price_total()
/*-------------------------------------------*/
/*  bill_total_no_tax()
/*-------------------------------------------*/
/*  消費税を計算
/*  bill_tax()
/*-------------------------------------------*/
/*  消費税込みの書類の合計金額
/*  bill_total_add_tax()
/*-------------------------------------------*/
/*  Chack post type info
/*	bill_get_post_type()
/*-------------------------------------------*/
/*	bill_get_terms()
/*-------------------------------------------*/

/*-------------------------------------------*/
/*  bill_form_post_value()
/*-------------------------------------------*/
function bill_form_post_value( $post_field, $type = false ){
      $value = '';
      global $post;
        if ( isset( $post_field ) && $post_field ) {
          if ( isset( $type ) && $type == 'textarea' ) {
            // n2brはフォームにbrがそのまま入ってしまうので入れない
              $value = esc_textarea( $post_field );
          } else {
              $value = esc_attr( $post_field );
          }
        } else if ( isset( $post->$post_field ) && $post->$post_field ) {
          $value = $post->$post_field;
        }
      return $value;
}

/*-------------------------------------------*/
/*  bill_raw_date()
/*-------------------------------------------*/
// 8桁の数字で保存されているデータを
function bill_raw_date($date){
    $year   = substr($date,0,4);
    $month  = substr($date,4,2);
    $day    = substr($date,6,2);
    $raw_date = strtotime($year.'-'.$month.'-'.$day.' 00:00:00');
    return $raw_date;
}

/*-------------------------------------------*/
/*  bill_item_number()
/*-------------------------------------------*/
function bill_item_number( $number = 0 ){
  // 全角を半額に変換
  $number = mb_convert_kana( $number, 'a' );
  $number = str_replace(',','',$number);
  return $number;
}
/*-------------------------------------------*/
/*  bill_item_price_total()
/*-------------------------------------------*/
function bill_item_price_total( $count = 0, $price = 0 ){
  // 数量×単価
  $item_price_total = round( $count * $price );
  return $item_price_total;
}

/*-------------------------------------------*/
/*  bill_total_no_tax()
/*-------------------------------------------*/
// 書類の税抜き合計
function bill_total_no_tax( $post ) {
  // global $post;
  $bill_items = get_post_meta( $post->ID, 'bill_items', true );
  $bill_item_sub_fields = array( 'name', 'count', 'unit', 'price' );
  $bill_total = 0;

  if ( is_array( $bill_items ) ) {

  // 行のループ
  foreach ($bill_items as $key => $value) {
    // $item_count
    if ( $bill_items[$key]['count'] === '' ){
      $item_count = '';
    } else {
      // intvalは小数点が切り捨てられるので使用していない
      $item_count = bill_item_number( $bill_items[$key]['count'] );
    }

    // $item_price
    if ( $bill_items[$key]['price'] === '' ) {
      $item_price = '';
      $item_price_print = '';
    } else {
      $item_price = bill_item_number( $bill_items[$key]['price'] );
      $item_price_print = '¥ '.number_format( $item_price );
    }
    // $item_total
    if ( $item_count && $item_price ) {
      $item_price_total = bill_item_price_total( $item_count, $item_price );
      $item_price_total_print = '¥ '.number_format( $item_price_total );
    } else {
      $item_price_total = '';
      $item_price_total_print = '';
    }

    // 小計
    $bill_total += $item_price_total;

  } // foreach ($bill_items as $key => $value) {

  } // if ( is_array( $bill_items ) ) {

  return $bill_total;
}

/*-------------------------------------------*/
/*  消費税を計算
/*  bill_tax()
/*-------------------------------------------*/
function bill_tax( $price = 0 ){
  $tax = floor( $price * 0.08 );
  return $tax;
}

/*-------------------------------------------*/
/*  消費税込みの書類の合計金額
/*  bill_total_add_tax()
/*-------------------------------------------*/
function bill_total_add_tax( $post ) {

  // 消費税抜きの合計金額
  $bill_total_no_tax = bill_total_no_tax( $post );

  // 税込合計金額 = 消費税抜きの合計金額 + 消費税
  $bill_total_add_tax = $bill_total_no_tax + bill_tax( $bill_total_no_tax );

  return $bill_total_add_tax;
}

/*-------------------------------------------*/
/*  Chack post type info
/*	bill_get_post_type()
/*-------------------------------------------*/
function bill_get_post_type() {

  // Get post type slug
  /*-------------------------------------------*/
  $post_type['slug'] = get_post_type();
  if ( ! $post_type['slug'] ) {
    global $wp_query;
    if ( is_front_page() ) {
      $post_type['slug'] = 'post';
    } elseif ( $wp_query->query_vars['post_type'] ) {
      $post_type['slug'] = $wp_query->query_vars['post_type'];
    } else {
      // Case of tax archive and no posts
      $taxonomy = get_queried_object()->taxonomy;
      if ( $taxonomy ){
        $post_type['slug'] = get_taxonomy( $taxonomy )->object_type[0];
      }
    }
  }

  // Get post type name
  /*-------------------------------------------*/
  $post_type_object = get_post_type_object( $post_type['slug'] );

  $post_type['name'] = esc_html( $post_type_object->labels->name );
  $post_type['url'] = home_url().'/?post_type='.$post_type['slug'];

  return $post_type;
}

/*-------------------------------------------*/
/*	bill_get_terms()
/*-------------------------------------------*/
function bill_get_terms(){
  global $post;
  $postType = get_post_type();
  if ($postType == 'post') {
    $taxonomySlug = 'category';
  } else {
    $taxonomies = get_the_taxonomies();
    // print '<pre style="text-align:left">';print_r($taxonomies);print '</pre>';
    if ($taxonomies) {
      foreach ( $taxonomies as $taxonomySlug => $taxonomy ) {}
    } else {
      $taxonomySlug = '';
    }
  }

  $taxo_catelist = get_the_term_list( $post->ID, $taxonomySlug, ' ', ', ' ,'' );
  return $taxo_catelist;
}
