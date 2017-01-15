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
      <div id="main" class="col-md-8">
      <!-- [ 記事のループ ] -->

<?php if ( is_singular()) { ?>
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
<?php } else { ?>

<div class="section">
<?php if ( have_posts() ) { ?>
<table class="table table-striped">
<tr>
<th class="text-center">件名</th>
<th class="text-center">カテゴリー</th>
</tr>
<?php while( have_posts() ) : the_post(); ?>
<tr>
<td><a href="<?php the_permalink(); ?>"><?php the_title(); ?></a></td>
<td><?php the_category(' , '); ?></td>
</tr>
<?php endwhile; ?>
</table>
<?php } // if ( have_posts() ) { ?>
</div>

<?php } ?>



      <!-- [ /記事のループ ] -->
      </div>
      <!-- [ /#main ] -->

      <?php get_sidebar();?>

    </div>
</div>

<?php get_footer();?>