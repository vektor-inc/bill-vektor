<?php
function bill_bread_crumb() {
	/*
		BreadCrumb
	/*-------------------------------------------*/

	global $wp_query;

	// Get Post type info
	/*-------------------------------------------*/
	$postType = bill_get_post_type();

	// Microdata
	// http://schema.org/BreadcrumbList
	/*-------------------------------------------*/
	$microdata_li        = ' itemprop="itemListElement" itemscope itemtype="http://schema.org/ListItem"';
	$microdata_li_a      = ' itemprop="item"';
	$microdata_li_a_span = ' itemprop="name"';

	$panListHtml = '<!-- [ .breadSection ] -->
<div class="container breadcrumb-section">
<ol class="breadcrumb" itemtype="http://schema.org/BreadcrumbList">';

	$panListHtml .= '<li id="panHome"' . $microdata_li . '><a' . $microdata_li_a . ' href="' . home_url( '/' ) . '"><span' . $microdata_li_a_span . '><span class="glyphicon glyphicon-home" aria-hidden="true"></span> HOME</span></a></li>';

	/*
	Post type
	/*-------------------------------*/

	if ( is_archive() || ( is_single() && ! is_attachment() ) ) {

		if ( $postType['slug'] == 'post' || is_category() || is_tag() ) { /*
			including single-post */
			// if ( $page_for_posts['post_top_use'] ) {
			// if ( !is_home() ) {
				$panListHtml .= '<li' . $microdata_li . '><a' . $microdata_li_a . ' href="' . esc_url( $postType['url'] ) . '"><span' . $microdata_li_a_span . '>' . $postType['name'] . '</span></a></li>';
			// } else {
			// $panListHtml .= '<li><span>'.the_title('','', FALSE).'</span></li>';
			// }
			// }
		} elseif ( is_single() || is_year() || is_month() || is_day() || is_tax() || is_author() ) {
				$panListHtml .= '<li' . $microdata_li . '><a' . $microdata_li_a . ' href="' . esc_url( $postType['url'] ) . '"><span' . $microdata_li_a_span . '>' . $postType['name'] . '</span></a></li>';
		} else {
			$panListHtml .= '<li><span>' . $postType['name'] . '</span></li>';
		}
	}

	if ( is_home() ) {

		/*
		When use to post top page
		When "is_page()" that post top is don't display.
		*/
		if ( isset( $postType['name'] ) && $postType['name'] ) {
			$panListHtml .= '<li><span>' . $postType['name'] . '</span></li>';
		}
	} elseif ( is_category() ) {

		/*
		Category
		/*-------------------------------*/

		// Get category information & insert to $cat
		$cat = get_queried_object();

		// parent != 0  >>>  Parent exist
		if ( $cat->parent != 0 ) :
			// 祖先のカテゴリー情報を逆順で取得
			$ancestors = array_reverse( get_ancestors( $cat->cat_ID, 'category' ) );
			// 祖先階層の配列回数分ループ
			foreach ( $ancestors as $ancestor ) :
				$panListHtml .= '<li' . $microdata_li . '><a' . $microdata_li_a . ' href="' . get_category_link( $ancestor ) . '"><span' . $microdata_li_a_span . '>' . esc_html( get_cat_name( $ancestor ) ) . '</span></a></li>';
			endforeach;
			endif;
		$panListHtml .= '<li><span>' . $cat->cat_name . '</span></li>';

	} elseif ( is_tag() ) {

		/*
		Tag
		/*-------------------------------*/

		$tagTitle     = single_tag_title( '', false );
		$panListHtml .= '<li><span>' . $tagTitle . '</span></li>';

	} elseif ( is_tax() ) {

		/*
		term
		/*-------------------------------*/

		$now_term        = $wp_query->queried_object->term_id;
		$now_term_parent = $wp_query->queried_object->parent;
		$now_taxonomy    = $wp_query->queried_object->taxonomy;

		// parent が !0 の場合 = 親カテゴリーが存在する場合
		if ( $now_term_parent != 0 ) :
			// 祖先のカテゴリー情報を逆順で取得
			$ancestors = array_reverse( get_ancestors( $now_term, $now_taxonomy ) );
			// 祖先階層の配列回数分ループ
			foreach ( $ancestors as $ancestor ) :
				$pan_term     = get_term( $ancestor, $now_taxonomy );
				$panListHtml .= '<li' . $microdata_li . '><a' . $microdata_li_a . ' href="' . get_term_link( $ancestor, $now_taxonomy ) . '"><span' . $microdata_li_a_span . '>' . esc_html( $pan_term->name ) . '</a></li>';
			endforeach;
		endif;

		$panListHtml .= '<li><span>' . esc_html( single_cat_title( '', '', false ) ) . '</span></li>';

	} elseif ( is_author() ) {

		/*
		Author
		/*-------------------------------*/

		$userObj      = get_queried_object();
		$panListHtml .= '<li><span>' . esc_html( $userObj->display_name ) . '</span></li>';

	} elseif ( is_archive() && ( ! is_category() || ! is_tax() ) ) {

		$query = $wp_query->query_vars;

		/*
		Year / Monthly / Dayly
		/*-------------------------------*/

		if ( is_year() || is_month() || is_day() ) {

			if ( is_year() ) {
				$panListHtml .= '<li><span>' . sprintf( __( 'Yearly Archives: %s', 'lightning' ), date( _x( 'Y', 'yearly archives date format', 'lightning' ), strtotime( $query['year'] . '-01-01' ) ) ) . '</span></li>';
			} elseif ( is_month() ) {
				$month        = ( $query['monthnum'] < 10 ) ? '0' . $query['monthnum'] : $query['monthnum'];
				$panListHtml .= '<li><span>' . sprintf( __( 'Monthly Archives: %s', 'lightning' ), date( _x( 'F Y', 'monthly archives date format', 'lightning' ), strtotime( $query['year'] . '-' . $month . '-01' ) ) ) . '</span></li>';
			} elseif ( is_day() ) {
				$panListHtml .= '<li><span>' . sprintf( __( 'Daily Archives: %s', 'lightning' ), date( _x( 'F jS, Y', 'daily archives date format', 'lightning' ), strtotime( $query['year'] . '-' . $query['monthnum'] . '-' . $query['day'] ) ) ) . '</span></li>';
			}
		}
	} elseif ( is_single() ) {

		/*
		Single
		/*-------------------------------*/

		// Case of post

		if ( $postType['slug'] == 'post' ) {
			$category = get_the_category();
			// get parent category info
			$parents_str = get_category_parents( $category[0]->term_id, false, ',' );
			// Set to Array that to loop parent category
			$parents_name = explode( ',', $parents_str );
			foreach ( $parents_name as $parent_name ) {
				if ( ! empty( $parent_name ) ) {
					$parent_obj   = get_term_by( 'name', $parent_name, $category[0]->taxonomy );
					$term_url     = get_term_link( $parent_obj->term_id, $parent_obj->taxonomy );
					$panListHtml .= '<li' . $microdata_li . '><a' . $microdata_li_a . ' href="' . $term_url . '"><span' . $microdata_li_a_span . '>' . esc_html( $parent_obj->name ) . '</span></a></li>';
				}
			}

			// Case of custom post type

		} else {
			$taxonomies = get_the_taxonomies();
			$taxonomy   = key( $taxonomies );

			if ( $taxonomies ) :
				$terms = get_the_terms( get_the_ID(), $taxonomy );

				// keeps only the first term (categ)
				$term = reset( $terms );
				if ( 0 != $term->parent ) {

					// Get term ancestors info
					$ancestors = array_reverse( get_ancestors( $term->term_id, $taxonomy ) );
					// Print loop term ancestors
					foreach ( $ancestors as $ancestor ) :
						$pan_term     = get_term( $ancestor, $taxonomy );
						$panListHtml .= '<li' . $microdata_li . '><a' . $microdata_li_a . ' href="' . get_term_link( $ancestor, $taxonomy ) . '"><span' . $microdata_li_a_span . '>' . esc_html( $pan_term->name ) . '</span></a></li>';
					endforeach;
				}
				$term_url     = get_term_link( $term->term_id, $taxonomy );
				$panListHtml .= '<li' . $microdata_li . '><a' . $microdata_li_a . ' href="' . $term_url . '"><span' . $microdata_li_a_span . '>' . esc_html( $term->name ) . '</span></a></li>';
			endif;

		}

		$panListHtml .= '<li><span>' . strip_tags( get_the_title() ) . '</span></li>';

	} elseif ( is_page() ) {

		/*
		Page
		/*-------------------------------*/

		$post = $wp_query->get_queried_object();
		if ( $post->post_parent == 0 ) {
			$panListHtml .= '<li><span>' . get_the_title() . '</span></li>';
		} else {
			$ancestors = array_reverse( get_post_ancestors( $post->ID ) );
			array_push( $ancestors, $post->ID );
			foreach ( $ancestors as $ancestor ) {
				if ( $ancestor != end( $ancestors ) ) {
					$panListHtml .= '<li' . $microdata_li . '><a' . $microdata_li_a . ' href="' . get_permalink( $ancestor ) . '"><span' . $microdata_li_a_span . '>' . strip_tags( apply_filters( 'single_post_title', get_the_title( $ancestor ) ) ) . '</span></a></li>';
				} else {
					$panListHtml .= '<li><span>' . strip_tags( apply_filters( 'single_post_title', get_the_title( $ancestor ) ) ) . '</span></li>';
				}
			}
		}
	} elseif ( is_404() ) {

		/*
		404
		/*-------------------------------*/

		$panListHtml .= '<li><span>' . __( 'Not found', 'lightning' ) . '</span></li>';

	} elseif ( is_search() ) {

		/*
		Search result
		/*-------------------------------*/

		$panListHtml .= '<li><span>' . sprintf( __( 'Search Results for : %s', 'lightning' ), get_search_query() ) . '</span></li>';

	} elseif ( is_attachment() ) {

		/*
		Attachment
		/*-------------------------------*/

		$panListHtml .= '<li><span>' . the_title( '', '', false ) . '</span></li>';

	}
	$panListHtml .= '</ol>
</div>
<!-- [ /.breadSection ] -->';
	return $panListHtml;
}
$panListHtml = bill_bread_crumb();
$panListHtml = apply_filters( 'bill_panListHtml', $panListHtml );
echo $panListHtml;
