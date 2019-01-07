<?php get_header(); ?>

<?php $page_post_type = bill_get_post_type(); ?>

<?php get_template_part( 'template-parts/breadcrumb' ); ?>

  <div class="container">
	<div class="row">

		<?php get_sidebar(); ?>

	  <!-- [ #main ] -->
	  <div id="main" class="col-md-9">
	  <!-- [ 記事のループ ] -->

<?php if ( is_front_page() || is_archive() || is_tax() ) { ?>

<form action="" method="get">

<div class="section" id="search-box">
<?php get_template_part( 'template-parts/search-box' ); ?>
</div>

<?php $post_type = bill_get_post_type(); ?>

<div class="section">
<?php if ( have_posts() ) { ?>
<table class="table table-striped table-borderd">
<tr>
<th>書類</th>
<?php if ( $page_post_type['slug'] != 'client' ) { ?>
<th>発行日</th>
<?php } ?>

<?php if ( $post_type['slug'] != 'salary' ) { ?>
<th>取引先</th>
<?php } ?>

<?php if ( $page_post_type['slug'] != 'client' ) { ?>
	<th>件名</th>
	<?php if ( $post_type['slug'] != 'salary' ) { ?>
		<th>カテゴリー</th>
	<?php } elseif ( $post_type['slug'] == 'salary' ) { ?>
		<th>支給分</th>
	<?php } ?>
<?php } ?>
</tr>
<?php
while ( have_posts() ) :
	the_post();
?>
<tr>
<!-- [ 書類 ] -->
<td class="text-nowrap">
<?php
$post_type = bill_get_post_type();
echo '<a href="' . esc_url( $post_type['url'] ) . '">' . $post_type['name'] . '</a>';
?>
</td>

<?php if ( $page_post_type['slug'] != 'client' ) { ?>
<!-- [ 発行日 ] -->
<td><?php echo esc_html( get_the_date( 'Y.m.d' ) ); ?></td>
<?php } ?>

<?php if ( $post_type['slug'] != 'salary' ) { ?>
<!-- [ 取引先 ] -->
<td class="text-nowrap">
<?php
$client_id   = $post->bill_client;
$client_name = get_post_meta( $client_id, 'client_short_name', true );
if ( ! $client_name ) {
	$client_name = get_the_title( $client_id );
}
echo '<a href="' . get_the_permalink( $client_id ) . '" target="_blank">' . esc_html( $client_name ) . '</a>';
?>
</td>
<?php } ?>

<?php if ( $page_post_type['slug'] != 'client' ) { ?>

<!-- [ 件名 ] -->
<td><a href="<?php the_permalink(); ?>" target="_blank"><?php the_title(); ?></a></td>
<!-- [ カテゴリー ] -->
<td><?php echo bill_get_terms(); ?></td>

<?php } ?>

</tr>
<?php endwhile; ?>
</table>
<?php the_posts_pagination(); ?>
<?php
} else {
	echo '<p>該当の書類はありません。</p>';
} // if ( have_posts() ) {
?>
</div>

<div id="news" class="section">
<h3>お知らせ</h3>
<ul class="post-list" id="newsEntries">
<?php
$rss     = 'https://billvektor.com/feed/';
$content = wp_safe_remote_get( $rss );
if ( ! isset( $content->errors ) ) {
	$count = 0;
	if ( $content['response']['code'] != 200 ) {
		return;
	}
	$xml = @simplexml_load_string( $content['body'] );
	foreach ( $xml->channel->item as $entry ) {
		$rss_date = $entry->pubDate;
		date_default_timezone_set( 'Asia/Tokyo' );
		$post_date = strtotime( $rss_date );
		echo '<li>';
		echo '<span class="post-date">' . date( 'Y.m.d', $post_date ) . '</span>';
		echo '<span class="post-cate">' . esc_html( $entry->category ) . '</span>';
		echo '<span class="post-title"><a href="' . esc_url( $entry->link ) . '?rel=rss" target="_blank">' . esc_html( $entry->title ) . '</a></span>';
		echo '</li>';
		$count++;
		if ( $count > 4 ) {
			break; }
	}
} else {
	echo '<p>お知らせの取得に失敗しました。</p>';
}// if ( !isset( $content->errors ) ) {
?>
</ul>
</div>

<div id="csv-export" class="section">
<?php get_template_part( 'template-parts/export-box' ); ?>
</div>

</form>

<?php } else { ?>

	<?php if ( have_posts() ) { ?>
	<?php
	while ( have_posts() ) :
		the_post();
?>
	 <article class="section">
	  <header class="page-header">
	  <h1><a href="<?php the_permalink(); ?>"><?php the_title(); ?></a></h1>
	  <div class="wck_post_meta">
	  <span class="glyphicon glyphicon-time" aria-hidden="true"></span> <?php the_date(); ?>　
	  <span class="glyphicon glyphicon-folder-open" aria-hidden="true"></span> <?php the_category( ',' ); ?>
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

<?php get_footer(); ?>
