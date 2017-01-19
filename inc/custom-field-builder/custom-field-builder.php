<?php

/*
このファイルの元ファイルは
https://github.com/vektor-inc/vektor-wp-libraries
にあります。
修正の際は上記リポジトリのデータを修正してください。
編集権限を持っていない方で何か修正要望などありましたら
各プラグインのリポジトリにプルリクエストで結構です。
*/

if ( ! class_exists( 'VK_Custom_Field_Builder' ) ) {

class VK_Custom_Field_Builder {

  public static $version = '0.0.0';

  // define( 'Bill_URL', get_template_directory_uri() );

  public static function init() {
    add_action( 'admin_footer' , array( __CLASS__, 'print_script'), 10, 2);
  }

  static function admin_directory_url (){
    global $custom_field_builder_dir; // configファイルで指定
    $direcrory_url = $custom_field_builder_dir;
    return $direcrory_url;
  }

  /*-------------------------------------------*/
  /*  管理画面用共通js読み込み（記述場所によっては動作しないので注意）
  /*-------------------------------------------*/
  public static function print_script()
  {
    wp_register_script( 'datepicker', self::admin_directory_url().'js/datepicker.js', array('jquery','jquery-ui-datepicker'), self::$version, true );
    wp_enqueue_script( 'datepicker' );
    wp_register_script( 'vk_mediauploader', self::admin_directory_url().'js/mediauploader.js', array('jquery'), self::$version, true );
    wp_enqueue_script( 'vk_mediauploader' );
    wp_enqueue_style( 'cf-builder-style', self::admin_directory_url().'css/cf-builder.css', array(), self::$version, 'all' );
  }

  public static function form_post_value( $post_field, $type = false )
  {
        $value = '';
        global $post;
          if ( isset( $_POST[$post_field] ) && $_POST[$post_field] ) {
            if ( isset( $type ) && $type == 'textarea' ) {
              // n2brはフォームにbrがそのまま入ってしまうので入れない
                $value = esc_textarea( $_POST[$post_field] );
            } else {
                $value = esc_attr( $_POST[$post_field] );
            } 
          } else if ( isset( $post->$post_field ) && $post->$post_field ) {
            $value = $post->$post_field;
          }
        return $value;
  }

  public static function form_required()
  {
        $required = '<span class="required">必須</span>';
        return $required;
  }

  public static function form_table( $custom_fields_array, $befor_items = '', $echo = true )
  {

      wp_nonce_field(wp_create_nonce(__FILE__), 'noncename__fields');

      global $post;

      $form_html = '';

      $form_html .= '<table class="table-custom-field-builder table-striped table table-bordered">';

      $form_html .= $befor_items;

      foreach ($custom_fields_array as $key => $value) {
          $form_html .= '<tr class="cf_item"><th class="text-nowrap"><label>'.$value['label'].'</label>';
          $form_html .= ( isset( $value['required'] ) && $value['required'] ) ? VK_Custom_Field_Builder::form_required() : '';
          $form_html .= '</th><td>';

          if ( $value['type'] == 'text' || $value['type'] == 'url' ){
              $form_html .= '<input class="form-control" type="text" id="'.$key.'" name="'.$key.'" value="'.VK_Custom_Field_Builder::form_post_value($key).'" size="70">';

          } else if ( $value['type'] == 'datepicker' ){
              $form_html .= '<input class="form-control datepicker" type="text" id="'.$key.'" name="'.$key.'" value="'.VK_Custom_Field_Builder::form_post_value($key).'" size="70">';

          } else if ( $value['type'] == 'textarea' ){
              $form_html .= '<textarea class="form-control" class="cf_textarea_wysiwyg" name="'.$key.'" cols="70" rows="3">'.VK_Custom_Field_Builder::form_post_value($key,'textarea').'</textarea>';

          } else if ( $value['type'] == 'select' ){
              $form_html .= '<select id="'.$key.'" class="form-control" name="'.$key.'"  >';

              foreach ($value['options'] as $option_value => $option_label) {
                  if ( VK_Custom_Field_Builder::form_post_value($key) == $option_value ){
                      $selected = ' selected="selected"';
                  } else {
                      $selected = '';
                  }

                  $form_html .= '<option value="'.esc_attr( $option_value ).'"'.$selected.'>'.esc_html( $option_label ).'</option>';
              }
              $form_html .= '</select>';

          } else if ( $value['type'] == 'image' ){
              $attr = array(
                'id'    => 'thumb_'.$key,
                'src'   => '',
                'class' => 'input_thumb',
                );
              if ( isset( $_POST[$key] ) && $_POST[$key] ){
                $form_html .= wp_get_attachment_image( $_POST[$key], 'medium', false, $attr );
              } else if ( $post->$key ){
                $form_html .= wp_get_attachment_image( $post->$key, 'medium', false, $attr );
              } else {
                $form_html .= '<img src="'.VK_PORTAL_URL.'/images/no_image.png" id="thumb_'.$key.'" alt="" class="input_thumb" style="width:200px;height:auto;">';
              }
              
              $form_html .= '<input type="hidden" name="'.$key.'" id="'.$key.'" value="'.VK_Custom_Field_Builder::form_post_value($key).'" style="width:60%;" /> 
<button id="media_'.$key.'" class="media_btn btn btn-default button button-default">'.__('画像を選択', '').'</button>';
          }
          if ( $value['description'] ) {
              $form_html .= '<div class="description">'.apply_filters('the_content', $value['description'] ).'</div>';
          }
          $form_html .= '</td></tr>';
      }
      $form_html .= '</table>';
      if ( $echo ) {
        wp_enqueue_media();
        echo $form_html;
      } else {
        wp_enqueue_media();
        return $form_html;
      }

  } // public static function form_table( $custom_fields_array, $befor_items, $echo = true ){

  /*-------------------------------------------*/
  /*  入力された値の保存
  /*-------------------------------------------*/
  public static function save_cf_value( $custom_fields_array ){

      global $post;
      
      //設定したnonce を取得（CSRF対策）
      $noncename__fields = isset( $_POST['noncename__fields'] ) ? $_POST['noncename__fields'] : null;

      //nonce を確認し、値が書き換えられていれば、何もしない（CSRF対策）
      if( !wp_verify_nonce($noncename__fields, wp_create_nonce(__FILE__)) ) {
          return;
      }

      //自動保存ルーチンかどうかチェック。そうだった場合は何もしない（記事の自動保存処理として呼び出された場合の対策）
      if( defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) { return $post_id; }

          foreach ($custom_fields_array as $key => $value) {

              if ( isset($_POST[$key])){

                  $field_value = $_POST[$key];
                      // データが空だったら入れる
                      if( get_post_meta($post->ID, $key ) == ""){
                          add_post_meta($post->ID, $key , $field_value, true);
                      // 今入ってる値と違ってたらアップデートする
                      } elseif($field_value != get_post_meta($post->ID, $key , true)){
                          update_post_meta($post->ID, $key , $field_value);
                      // 入力がなかったら消す
                      } elseif($field_value == ""){
                          delete_post_meta($post->ID, $key , get_post_meta($post->ID, $key , true));
                      }
                  } // if (isset($_POST[$lang_field_title])){

          } // foreach ($custom_fields_all_array as $key => $value) {
  }



  /*-------------------------------------------*/
  /*  実行
  /*-------------------------------------------*/
  public function __construct(){
      // add_action( 'init', array( $this, 'add_post_type' ),0 );
  }

} // class Vk_custom_field_builder

VK_Custom_Field_Builder::init();

} // if ( ! class_exists( 'VK_Custom_Field_Builder' ) ) {
