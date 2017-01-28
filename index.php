<?php get_header();?>

<!-- [ パンくずリスト ] -->
<div class="container breadcrumb-section">
<ol class="breadcrumb">
  <li><a href="<?php echo home_url( '/' ); ?>"><span class="glyphicon glyphicon-home" aria-hidden="true"></span> HOME</a></li>

<?php if ( is_archive() ) { ?>
<li><?php the_archive_title(); ?></li>

<?php } else if ( is_single() ) { ?>

<li><?php the_category(','); ?></li>
<li><?php the_title();?></li>
<?php } ?>
</ol>
</div>
<!-- [ /パンくずリスト ] -->

  <div class="container">
    <div class="row">

      <!-- [ #main ] -->
      <div id="main" class="col-md-9">
      <!-- [ 記事のループ ] -->

<?php if ( is_front_page() || is_archive() || is_tax() ) { ?>

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
<td><?php $post_type = bill_get_post_type();
echo '<a href="'.esc_url($post_type['url']).'">'.$post_type['name'].'</a>';
?></td>
<td><?php echo esc_html( get_the_date("Y.m.d") );?></td>
<td>
<?php 
$client_id = $post->bill_client;
$client_name = get_post_meta( $client_id, 'client_short_name', true );
if ( !$client_name ){
  $client_name = get_the_title($client_id);
}
echo esc_html( $client_name );
?>
</td>
<td><a href="<?php the_permalink(); ?>" target="_blank"><?php the_title(); ?></a></td>
<td><?php echo bill_get_terms(); ?></td>
</tr>
<?php endwhile; ?>
</table>
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
    <?php the_posts_pagination(); ?>
    <?php } // if ( have_posts() ) { ?>

<?php } ?>

      <!-- [ /記事のループ ] -->
      </div>
      <!-- [ /#main ] -->

      <?php get_sidebar();?>

    </div>
</div>

<?php get_footer();?>