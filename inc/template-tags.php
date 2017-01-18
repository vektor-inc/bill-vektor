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