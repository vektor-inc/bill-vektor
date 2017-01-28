<!DOCTYPE html>
<html lang="ja">
<head>
  <meta charset="utf-8">
  <meta http-equiv="X-UA-Compatible" content="IE=edge">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <?php wp_head();?>
</head>
<body <?php body_class(); ?>>
<div class="bill-wrap">
<?php if ( have_posts() ) { ?>
<?php while( have_posts() ) : the_post(); ?>
<?php if ( get_post_type() == 'post' ) {
	get_template_part('template-parts/bill/frame-bill');
} else if ( get_post_type() == 'estimate' ){
	get_template_part('template-parts/bill/frame-estimate');
} ?>
<?php endwhile; ?>
<?php } ?>
</div>

<div class="bill-no-print">
<div class="container">
<p>以このエリアは印刷されません。</p>
<div class="row">
<?php get_template_part('template-parts/breadcrumb');?>
</div>
</div>
</div>

<?php wp_footer();?>
</body>
</html>