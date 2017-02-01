<!-- [ #sub ] -->
<div id="sub" class="col-md-3">

  <nav class="sub-section section"">
  <h4 class="sub-section-title"><a href="<?php echo home_url('/').'?post_type=estimate';?>">見積書</a></h4>
  <ul>
  <?php wp_list_categories('title_li=&taxonomy=estimate-cat'); ?>
  </ul>
  <h4 class="sub-section-title"><a href="<?php echo home_url('/').'?post_type=post';?>">請求書<i class="fa fa-angle-right" aria-hidden="true"></i></a></h4>
  <ul>
  <?php wp_list_categories('title_li='); ?>
  </ul>
  </nav>

<?php 
// ウィジェットエリアid 'sidebar-widget-area' にウィジェットアイテムが何かセットされていた時
if ( is_active_sidebar( 'sidebar-widget-area' ) ) {
  // sidebar-widget-area に入っているウィジェットアイテムを表示する
  dynamic_sidebar( 'sidebar-widget-area' );
} ?>
</div>
<!-- [ /#sub ] -->