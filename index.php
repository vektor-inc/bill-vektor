<?php get_header();?>

<?php get_template_part('template-parts/breadcrumb');?>

  <div class="container">
    <div class="row">

      <?php get_sidebar();?>

      <!-- [ #main ] -->
      <div id="main" class="col-md-9">
      <!-- [ 記事のループ ] -->

<?php if ( is_front_page() || is_archive() || is_tax() ) { ?>

<div class="section">
<?php get_template_part('template-parts/search-box');?>
</div>

<div class="section">
<?php if ( have_posts() ) { ?>
<table class="table table-striped table-borderd">
<tr>
<th>書類</th>
<th>発行日</th>
<th>取引先</th>
<th>件名</th>
<th>カテゴリー</th>
</tr>
<?php while( have_posts() ) : the_post(); ?>
<tr>
<!-- [ 書類 ] -->
<td class="text-nowrap"><?php $post_type = bill_get_post_type();
echo '<a href="'.esc_url($post_type['url']).'">'.$post_type['name'].'</a>';
?></td>
<!-- [ 発行日 ] -->
<td><?php echo esc_html( get_the_date("Y.m.d") );?></td>
<!-- [ 取引先 ] -->
<td class="text-nowrap">
<?php 
$client_id = $post->bill_client;
$client_name = get_post_meta( $client_id, 'client_short_name', true );
if ( !$client_name ){
  $client_name = get_the_title($client_id);
}
echo '<a href="'.home_url('/').'?post_type='.$post_type['slug'].'&client='.$client_id.'">'.esc_html( $client_name ).'</a>';
?>
</td>
<!-- [ 件名 ] -->
<td><a href="<?php the_permalink(); ?>"><?php the_title(); ?></a></td>
<!-- [ カテゴリー ] -->
<td><?php echo bill_get_terms(); ?></td>
</tr>
<?php endwhile; ?>
</table>
<?php the_posts_pagination(); ?>
<?php } // if ( have_posts() ) { ?>
</div>

<?php } else { ?>

    <?php if ( have_posts() ) { ?>
    <?php while( have_posts() ) : the_post(); ?>
     <article class="section">
      <header class="page-header">
      <h1><a href="<?php the_permalink(); ?>"><?php the_title(); ?></a></h1>
      <div class="wck_post_meta">
      <span class="glyphicon glyphicon-time" aria-hidden="true"></span> <?php the_date(); ?>　 
      <span class="glyphicon glyphicon-folder-open" aria-hidden="true"></span> <?php the_category(','); ?>
      </div>
      </header>
      <div>
      <!-- [ 記事の本文 ] -->
      <?php the_content(); ?>
      <!-- [ /記事の本文 ] -->
      </div>
    </article>
    <?php endwhile; ?>
    <?php } // if ( have_posts() ) { ?>

<?php } ?>

      <!-- [ /記事のループ ] -->
      </div>
      <!-- [ /#main ] -->

    </div>
</div>

<?php get_footer();?>