<!-- [ #sub ] -->
<div id="sub" class="col-md-3">

  <nav class="sub-section section">

  <h3 class="sub-section-title"><a href="<?php echo get_post_type_archive_link( 'estimate' ); ?>">見積書</a></h3>

	<?php
	$args         = array(
		'title_li'         => '',
		'taxonomy'         => 'estimate-cat',
		'echo'             => 0,
		'show_option_none' => '',
	);
	$estimate_cat = wp_list_categories( $args );
	if ( $estimate_cat ) {
		echo '<ul>';
		echo $estimate_cat;
		echo '</ul>';
	}
	?>

  <h3 class="sub-section-title"><a href="<?php echo get_post_type_archive_link( 'post' ); ?>">請求書<i class="fa fa-angle-right" aria-hidden="true"></i></a></h3>

	<?php
	$args     = array(
		'title_li'         => '',
		'echo'             => 0,
		'show_option_none' => '',
	);
	$category = wp_list_categories( $args );
	if ( $category ) {
		echo '<ul>';
		echo $category;
		echo '</ul>';
	}
	?>
  </nav>

<?php
// ウィジェットエリアid 'sidebar-widget-area' にウィジェットアイテムが何かセットされていた時
if ( is_active_sidebar( 'sidebar-widget-area' ) ) {
	// sidebar-widget-area に入っているウィジェットアイテムを表示する
	dynamic_sidebar( 'sidebar-widget-area' );
}
?>
</div>
<!-- [ /#sub ] -->
