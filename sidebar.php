<!-- [ #sub ] -->
<div id="sub" class="col-md-3">
<?php 
// ウィジェットエリアid 'sidebar-widget-area' にウィジェットアイテムが何かセットされていた時
if ( is_active_sidebar( 'sidebar-widget-area' ) ) {
  // sidebar-widget-area に入っているウィジェットアイテムを表示する
  dynamic_sidebar( 'sidebar-widget-area' );
} else { ?>

  <aside class="sub-section section"">
  <h4 class="sub-section-title">カテゴリー</h4>
  <ul>
  <?php wp_list_categories('title_li='); ?>
  </ul>
  </aside>
  <aside class="sub-section section">
  <h4 class="sub-section-title">月別</h4>
  <ul>
  <?php wp_get_archives('type=monthly'); ?>
  </ul>
  </aside>

<?php } ?>
</div>
<!-- [ /#sub ] -->