<!DOCTYPE html>
<html lang="ja">
<head>
  <meta charset="utf-8">
  <meta http-equiv="X-UA-Compatible" content="IE=edge">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <?php wp_head();?>
</head>
<body <?php body_class(); ?>>
<header class="header">
  <div class="container">
    <div class="row">
      <div class="col-md-12">
      <h1 class="header-logo">
      <a href="<?php echo home_url( '/' ); ?>">
      BillVektor 
      <?php // bloginfo( 'name' ); ?>        
      </a></h1>
      <h2 class="header-description">請求書管理システム</h2>
      </div>
    </div>

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
      <?php
      $args = array(
          'theme_location' => 'Header Navigation',
          'items_wrap'     => '<ul id="%1$s" class="%2$s nav navbar-nav nav">%3$s</ul>',
          'fallback_cb'    => '',
          'echo'           => false,
      );
      $menu = wp_nav_menu( $args ) ;?>
      <?php if ( $menu ) : ?>
        <?php echo $menu; ?>
      <?php else : ?>
        <div class="menu-menu-1-container">
          <ul id="menu-menu-1" class="menu nav navbar-nav nav">
          <li class="menu-item"><a href="<?php echo home_url('/');?>">ホーム</a></li>
          <li class="menu-item"><a href="<?php echo home_url('/').'?post_type=estimate';?>">見積書</a></li>
          <li class="menu-item"><a href="<?php echo home_url('/').'?post_type=post';?>">請求書</a></li>
          </ul>
          </div>
      <?php endif; ?>
      </div>
    </div>
  </div><!-- [ /.container ] -->
</header>