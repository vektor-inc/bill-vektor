<?php
/**
 * Class PriceTest
 *
 * @package BillVektor
 */
/*
$ cd $(wp theme path --dir bill-vektor)
$ bash bin/install-wp-tests.sh wordpress_test root 'WordPress' localhost latest

/*
cd /app
bash setup-phpunit.sh
source ~/.bashrc
cd $(wp theme path --dir bill-vektor)
phpunit
*/

/**
 * BillVektor test case.
 */
class InvoiceTest extends WP_UnitTestCase {

    public function setup_data() {

        register_post_type(
            'estimate',
            array(
                'labels'             => array(
                    'name'         => '見積書',
                    'edit_item'    => '見積書の編集',
                    'add_new_item' => '見積書の作成',
                ),
                'public'             => true,
                'publicly_queryable' => true,
                'show_ui'            => true,
                'show_in_menu'       => true,
                'has_archive'        => true,
                'supports'           => array( 'title' ),
                'menu_icon'          => 'dashicons-media-spreadsheet',
                'menu_position'      => 5,
            )
        );
        register_taxonomy(
            'estimate-cat',
            'estimate',
            array(
                'hierarchical'          => true,
                'update_count_callback' => '_update_post_term_count',
                'label'                 => '見積書カテゴリー',
                'singular_label'        => '見積書カテゴリー',
                'public'                => true,
                'show_ui'               => true,
            )
        );
    }
}