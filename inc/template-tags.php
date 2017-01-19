<?php

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

// 8桁の数字で保存されているデータを
function bill_raw_date($date){
    $year   = substr($date,0,4);
    $month  = substr($date,4,2);
    $day    = substr($date,6,2);
    $raw_date = strtotime($year.'-'.$month.'-'.$day.' 00:00:00');
    return $raw_date;
}