
<!DOCTYPE html>
<html lang="ja">
<head>
  <meta charset="utf-8">
  <meta http-equiv="X-UA-Compatible" content="IE=edge">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <?php wp_head();?>
</head>
<body>
<header class="header">
  <div class="container">
    <div class="row">
      <div class="col-md-12">
      <h1 class="header-logo"><a href="<?php echo home_url( '/' ); ?>"><?php bloginfo( 'name' ); ?></a></h1>
      <!--
      <h2 class="header-description"></h2>
      -->
      </div>
    </div>

<?php
$args = array(
    'theme_location' => 'Header Navigation',
    'items_wrap'     => '<ul id="%1$s" class="%2$s nav navbar-nav nav">%3$s</ul>',
    'fallback_cb'    => '',
    'echo'           => false,
);
$menu = wp_nav_menu( $args ) ;
if ( $menu ) :
?>
    <div class="navbar navbar-inverse">
      <div class="navbar-header">
        <button type="button" class="navbar-toggle" data-toggle="collapse" data-target="#navbar-ex-collapse">
        <span class="sr-only">Toggle navigation</span>
        <span class="icon-bar"></span>
        <span class="icon-bar"></span>
        <span class="icon-bar"></span>
        </button>
      </div>
      <div class="collapse navbar-collapse" id="navbar-ex-collapse">
      <?php echo $menu; ?>
      </div>
    </div>
<?php endif; ?>

  </div><!-- [ /.container ] -->
</header>