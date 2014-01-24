<?php
/**
 * Class WooCommerce Product Importer
 *
 * Import products into WooCommerce: add images, variations, categories, upsells, crosssells
 *
 * @class       WooCommerce_Product_Importer
 * @version     1.2.2
 * @author      Etienne Tremel
 * Last Update: 06/12/2013
 */
if ( ! class_exists( 'WooCommerce_Product_Importer' ) ) {
    class WooCommerce_Product_Importer {

        public $errors;


        function __construct() {
            $this->errors = new WP_Error();
        }


        /**
         * ADD PRODUCT
         *
         * Add product to WooCommerce, if product exist (SKU already used), return product id
         *
         * Check $default_args for datas
         * You can add custom field by adding new row into metas, ie:
         * $args = array( ..., 'metas'   => array( ... 'my-custom-meta-key' => 'value', ... ) )
         *
         * @access public
         * @param  array     Product details
         * @return integer   Product ID
         */
        public function add_product( $args, $update_if_sku_exist = false ) {
            global $wpdb;

            $current_user = wp_get_current_user();

            $default_args = array(
                'user_id'            => '',
                'name'               => '',
                'slug'               => '',
                'status'             => 'publish',
                'description'        => '',
                'excerpt'            => '',
                'tags'               => array(),
                'images'             => array(),
                'categories'         => array(),
                'fetch_image_type'   => 'remote', // Remote url (ext), by IDs or Title (already in DB)
                'type'               => 'simple',
                'metas'              => array(
                    '_sku'                      => '',
                    '_regular_price'            => '',
                    '_sale_price'               => '',
                    '_sale_price_dates_from'    => '',
                    '_sale_price_dates_to'      => '',
                    '_manage_stock'             => 'yes',
                    '_sold_individually'        => '',
                    '_stock'                    => '',
                    '_visibility'               => 'visible',
                    '_tax_status'               => '',
                    '_tax_class'                => '',
                    '_featured'                 => 'no',
                    '_backorders'               => 'no',
                    '_virtual'                  => 'no',
                    '_downloadable'             => 'no',
                    '_height'                   => '',
                    '_length'                   => '',
                    '_weight'                   => '',
                    '_width'                    => '',
                    '_thumbnail_id'             => null,
                    '_purchase_note'            => '',
                    '_product_attributes'       => array()
                )
            );
            $args = self::parse_args_r( $args, $default_args );

            if ( empty( $args['user_id'] ) )
                $args['user_id'] = $current_user->ID;

            if ( empty( $args['metas']['_sku'] ) )
                return $this->errors->add( 'product', 'SKU is missing' );

            if ( empty( $args['slug'] ) )
                $args['slug'] = $args['name'];

            // Check if the product is already in the DB
            $product_id_in_db = $this->product_exist( $args['metas']['_sku'] );

            // Prepare post before insertion
            $product = array(
                'post_author'       => $args['user_id'],
                'post_title'        => $args['name'],
                'post_name'         => sanitize_title( $args['slug'] ),
                'post_status'       => $args['status'],
                'comment_status'    => 'closed',
                'ping_status'       => 'closed',
                'post_content'      => $args['description'],
                'post_excerpt'      => $args['excerpt'],
                'post_type'         => 'product'
            );

            // If product exist in DB, update
            if ( $update_if_sku_exist && $product_id_in_db ) {
                $product['ID'] = $product_id_in_db;
                $product_id = wp_update_post( $product );
            } else {
                $product_id = wp_insert_post( $product, true );
            }

            if ( ! $product_id )
                return $product_id;

            // Set stock status
            if ( is_numeric( $args['metas']['_stock'] ) && $args['metas']['_stock'] === 0 )
                $args['metas']['_stock_status'] = 'outofstock';
            else
                $args['metas']['_stock_status'] = 'instock';

            $args['metas']['_price'] = $args['metas']['_regular_price'];

            // Set metas:
            foreach ( $args['metas'] as $key => $value )
                if ( $value != null || ( is_array( $value ) && count( $value ) > 0 ) )
                    update_post_meta( $product_id, $key, $value );

            // Set tags
            wp_set_object_terms( $product_id, array_map( 'trim', $args['tags'] ), 'product_tag' );

            // Set product as simple
            wp_set_object_terms( $product_id, $args['type'], 'product_type' );

            // Add images
            self::add_image_to_product( $product_id, $args['images'], $args['fetch_image_type'] );

            // Associate to category
            self::add_product_to_category( $product_id, $args['categories'] );

            return $product_id;
        }


        /**
         * DELETE PRODUCT
         *
         * Delete product and all its data
         *
         * Move product with ID = 25 to the trash
         * delete_product( 'product_id=25' )
         *
         * Delete definitively a product with SKU = 00012ES
         * delete_product( 'sku=00012ES', true )
         *
         * @access  public
         * @param   array     $args            Check $default_args for datas
         *          boolean   $force_delete    Whether to bypass trash and force deletion
         * @return  boolean                    Return true if product has been deleted, false if not
         */
        public function delete_product( $args, $force_delete = false ) {
            global $wpdb;

            $default_args = array(
                'sku'           => null,
                'product_id'    => null
            );

            $args = wp_parse_args( $args, $default_args );
            extract( $args, EXTR_SKIP );


            // Check if product exist
            if ( $sku )
                $product_id = $this->product_exist( $sku );

            if ( ! $product_id || is_null( $product_id ) )
                return false;

            // Delete post meta
            if ( $force_delete ) {
                $post_meta = get_post_custom( $product_id );

                if ( $post_meta )
                    foreach ( $post_meta as $key => $value )
                        delete_post_meta( $product_id, $key );
            }

            wp_delete_post( $product_id, $force_delete );

            return true;
        }


        /**
         * ADD IMAGE TO PRODUCT
         *
         * Add image to a specific product. Image can be present fetched from a remote server or already in the DB
         *
         * Fetch via remote server:
         * add_image_to_product( 12, array( 'http://remote.com/image-1.jpg', 'http://remote.com/image-1.jpg' ) );
         *
         * Image already in DB, association via title:
         * add_image_to_product( 12, array( 'image-1', 'image-2' ) );
         *
         * Image already in DB, association via ID:
         * add_image_to_product( 12, array( 4, 5 ) );
         *
         * @access public
         * @param  integer   $product_id    Product ID
         *         array     $images        Image list to associate with defined product. First element in array is the featured image.
         *         string    $fetch         Default: remote
         *                                  - 'remote':  image downloaded from a remote server
         *                                  - 'title':   image is already in DB, make association via Attachment Title
         *                                  - 'id':      image is already in DB, make association via Attachment ID
         * @return integer   Product ID
         */
        public function add_image_to_product( $product_id, $images = array(), $fetch = 'remote' ) {

            // Clean array:
            $images = array_values( array_filter( $images ) );

            $image_ids = array();

            $previous_image_name = '';

            foreach ( $images as $index => $image ) {
                switch ( $fetch ) {
                    case 'remote':
                        // Define image name
                        $name = apply_filters( 'woocommerce-product-importer-image-name', preg_replace( '/\.[^.]+$/', '', basename( $image ) ), $image, $product_id, $images, $previous_image_name );

                        // If name not defined, make one:
                        if ( $name == '' )
                            $name = get_the_title( $product_id );

                        // Append index to the end of the name if multiple images with same name
                        if ( $name == $previous_image_name )
                            $name .= '_' . $index;

                        $previous_image_name = $name;

                        $slug = sanitize_title( $name );

                        // Check if image is in DB
                        $attachment_in_db = get_page_by_title( $slug, 'OBJECT', 'attachment' );
                        $attachment_in_db = apply_filters( 'woocommerce-product-importer-image-in-db', $attachment_in_db, $name );

                        // Is attachment already in DB?
                        if ( $attachment_in_db ) {

                            // push attachment ID
                            $image_ids[] = $attachment_in_db->ID;

                        } else {

                            $get = wp_remote_get( $image, array( 'timeout'  => 60 ) );
                            $type = wp_remote_retrieve_header( $get, 'content-type' );

                            if ( ! $type ) {
                                $this->errors->add( 'add_image_to_product', 'Mime type not found: ' . $image );
                                continue;
                            }

                            $mime_types = array(
                                'image/jpg'     => 'jpg',
                                'image/jpeg'    => 'jpg',
                                'image/gif'     => 'gif',
                                'image/png'     => 'png'
                            );

                            if ( isset( $mime_types[ $type ] ) ) {
                                $extension = $mime_types[ $type ];
                            } else {
                                $this->errors->add( 'add_image_to_product', 'Mime type ' . $type . ' invalid: ' . $image );
                                continue;
                            }

                            $uploaded = wp_upload_bits( $slug . '.' . $extension, null, wp_remote_retrieve_body( $get ) );

                            if ( $uploaded['error'] ) {
                                $this->errors->add( 'add_image_to_product', $uploaded['error'] . ' Image:' . $image );
                                continue;
                            }

                            $attachment = array(
                                'guid'              => $uploaded['url'],
                                'post_title'        => $slug,
                                'post_mime_type'    => $type
                            );
                            $attach_id = wp_insert_attachment( $attachment, $uploaded['file'], $product_id );

                            if ( ! function_exists( 'wp_generate_attachment_metadata' ) )
                                require_once( ABSPATH . 'wp-admin/includes/image.php' );

                            $attach_data = wp_generate_attachment_metadata( $attach_id, $uploaded['file'] );
                            wp_update_attachment_metadata( $attach_id, $attach_data );

                            $image_ids[] = $attach_id;

                        }
                        break;

                    case 'title':
                        $image_name = preg_replace( '/([^.]+)\.(jpg|gif|png|bmp|tga)/', '$1', $image );
                        if ( ! is_null( $image_name ) ) {
                            $db_image = get_page_by_title( $image_name, OBJECT, 'attachment' );
                            if ( ! is_null( $db_image ) ) {
                                // Set featured image
                                if ( 0 == $index )
                                    set_post_thumbnail( $product_id, $db_image->ID );

                                $image_ids[] = $db_image->ID;
                            } else {
                                $this->errors->add( 'add_image_to_product', 'Image missing in DB' );
                                continue;
                            }
                        }
                        break;

                    case 'id':
                        $image_ids[] = $image;
                        break;
                }
            }

            // Set featured image & product image gallery
            if ( count( $image_ids ) > 0 ) {
                set_post_thumbnail( $product_id, $image_ids[0] );

                // Associate images as gallery
                update_post_meta( $product_id, '_product_image_gallery', implode( ',', $image_ids ) );
            }
        }


        /**
         * ADD PRODUCT TO CATEGORY
         *
         * Create the category with parents if not defined
         *
         * Usage with single category:
         * add_product_to_category( 12, 'My category name' );
         *
         * Usage with multiple categories:
         * add_product_to_category( 12, array(
         *     'My first category',
         *     'My second category'
         * ) );
         *
         * Usage with hierarchical categories:
         * add_product_to_category( 12, array(
         *     array(
         *         'My category',
         *         'My sub category',
         *         'My sub sub category'
         *     )
         * ) );
         *
         * @access public
         * @param  integer   $product_id    Product ID
         *         array     $categories    Category
         * @return void
         */

        public function add_product_to_category( $product_id, $categories ) {

            $category_in = array();

            // Temporarily disable terms count updates
            wp_defer_term_counting( true );

            // Check if single
            if ( ! is_array( $categories ) )
                $categories = array( $category );

            // Multiple
            foreach( $categories as $category ) {
                if ( is_array( $category ) ) {
                    // Hierarchical
                    $parent = 0;
                    foreach ( $category as $sub_category ) {

                        if ( is_numeric( $sub_category ) )
                            $category_id = $sub_category;
                        else
                            $category_id = self::add_category( array(
                                'name'      => $sub_category,
                                'parent'    => $parent
                            ) );

                        if ( ! is_wp_error( $category_id ) ) {
                            $parent = $category_id;
                            $category_in[] = $category_id;
                        }
                    }
                } else {
                    // Single category
                    if ( is_numeric( $category ) )
                        $category_id = $category;
                    else
                        $category_id = self::add_category( array(
                            'name'  => $category
                        ) );

                    if ( ! is_wp_error( $category_id ) )
                        $category_in[] = $category_id;
                }
            }

            if ( ! count( $category_in ) )
                return;

            // Make sure the terms IDs is integers:
            $category_in = array_map( 'intval', $category_in );
            $category_in = array_unique( $category_in );

            // Set categories id to Product
            wp_set_object_terms( $product_id, $category_in, 'product_cat' );

            // check out http://wordpress.stackexchange.com/questions/24498/wp-insert-term-parent-child-problem
            delete_option( 'product_cat_children' );

            // Re-enable terms count updates
            wp_defer_term_counting( false );

            // Update number of post associated to the category:
            wp_update_term_count_now( $category_in, 'product_cat' );
        }


        /**
         * ADD VARIATION TO PRODUCT
         *
         * Usage:
         *   add_variation_to_product( 12, array(
         *       'name'   => Color',
         *       'values' => array(
         *           'Blue'  => array(
         *               '_sku'              => 'P678',
         *               '_manage_stock'     => 'yes',
         *               '_regular_price'    => '20.00',
         *               '_sale_price'       => '12.00',
         *               '_visibility'       => 'visible',
         *               '_tax_status'       => 'taxable',
         *               '_backorders'       => 'no',
         *               '_virtual'          => 'no',
         *               '_downloadable'     => 'no',
         *               '_stock'            => '',
         *               '_weight'           => '',
         *               '_length'           => '',
         *               '_width'            => '',
         *               '_height'           => '',
         *               '_thumbnail_id'     => ''
         *           ),
         *           'Red'   => array(
         *               '_sku'              => 'P679',
         *               '_manage_stock'     => 'yes',
         *               '_regular_price'    => '20.00',
         *               '_sale_price'       => '12.00',
         *               '_visibility'       => 'visible',
         *               '_tax_status'       => 'taxable',
         *               '_backorders'       => 'no',
         *               '_virtual'          => 'no',
         *               '_downloadable'     => 'no',
         *               '_stock'            => '',
         *               '_weight'           => '',
         *               '_length'           => '',
         *               '_width'            => '',
         *               '_height'           => '',
         *               '_thumbnail_id'     => ''
         *           )
         *   ) );
         *
         * @access public
         * @param  integer   $product_id    Product ID
         *         array     $args          List of variations
         * @return void
         */
        public function add_variation_to_product( $product_id, $args ) {

            $default = array(
                'name'      => '',
                'values'    => array()
            );

            $args = wp_parse_args( $args, $default );

            $taxonomy = 'pa_' . sanitize_title_with_dashes( $args['name'] );

            // Clear existing values
            wp_set_object_terms( $product_id, NULL, $taxonomy );

            // Explode attributes and remove first and last white space
            $attributes_ids = array();


            // Fetch post to duplicate
            $post_to_duplicate = get_post( $product_id );

            $variation = array(
                'post_author'       => $post_to_duplicate->post_author,
                'post_title'        => $post_to_duplicate->post_title,
                'post_name'         => $post_to_duplicate->post_name,
                'post_status'       => $post_to_duplicate->post_status,
                'comment_status'    => $post_to_duplicate->comment_status,
                'ping_status'       => $post_to_duplicate->ping_status,
                'post_content'      => $post_to_duplicate->post_content,
                'post_excerpt'      => $post_to_duplicate->post_excerpt,
                'post_type'         => 'product_variation',
                'post_parent'       => $product_id
            );

            $default_metas = array(
                '_sku'              => null,
                '_regular_price'    => null,
                '_sale_price'       => null,
                '_manage_stock'     => null,
                '_stock'            => null,
                '_regular_price'    => null,
                '_visibility'       => null,
                '_tax_status'       => null,
                '_backorders'       => null,
                '_virtual'          => null,
                '_downloadable'     => null,
                '_thumbnail_id'     => null,
                '_weight'           => null,
                '_length'           => null,
                '_width'            => null,
                '_height'           => null,
                '_thumbnail_id'     => null
            );

            foreach ( $args['values'] as $variation_name => $metas ) {

                // Define new variation name:
                $variation['post_name'] .= sanitize_title( $variation_name );

                // If product exist get ID, or insert
                $variation_id = wp_insert_post( $variation );


                /* DUPLICATE PRODUCT META */
                global $wpdb;

                // Fetch product meta
                $product_meta = $wpdb->get_results( $wpdb->prepare( "SELECT meta_key, meta_value FROM $wpdb->postmeta WHERE post_id = %d", $product_id ) );

                // If metas exist
                if ( sizeof( $product_meta ) > 0 ) {

                    $metas = wp_parse_args( $metas, $default_metas );

                    // Update metas
                    foreach ( $product_meta as $col )
                        if ( in_array( $col->meta_key, $metas ) && $metas->meta_value != null )
                            update_post_meta( $variation_id, $col->meta_key, $col->meta_value );

                    // Set new attribute to post
                    update_post_meta( $variation_id, 'attribute_' . $taxonomy, sanitize_title( $variation_name ) );
                }

                $attribute = term_exists( $variation_name, $taxonomy );

                // Check attribute taxonomie exist
                if ( ! is_array( $attribute ) ) {
                    $args = array(
                        'slug'  => sanitize_title( $variation_name )
                    );
                    $attribute = wp_insert_term( $term, $taxonomy, $args );
                }

                //Set attribute to duplicated product
                wp_set_object_terms( $variation_id, (int)$attribute['term_id'], $taxonomy, true );


                $attributes_ids[] = $attribute['term_id'];
            }

            // Make sure the terms IDs is integers:
            $attributes_ids = array_map( 'intval', $attributes_ids );
            $attributes_ids = array_unique( $attributes_ids );

            // Make product as variable
            wp_set_object_terms( $product_id, 'variable', 'product_type' );
            wp_set_object_terms( $product_id, $attributes_ids, $taxonomy, true );

            //Set attribute to product
            $post_meta = get_post_meta( $product_id, '_product_attributes', false );
            $post_meta[ $taxonomy ] = array(
                'name'          => $taxonomy,
                'position'      => 0,
                'is_variation'  => 1,
                'is_taxonomy'   => 1,
                'is_visible'    => 1
            );
            update_post_meta( $product_id, '_product_attributes', $post_meta );
        }


        /**
         * ADD CATEGORY
         *
         * Add a new category into WooCommerce, if the category already exist return Category ID
         *
         * @access public
         * @param  array     $args    Category parameters
         * @return integer            Category ID
         */
        public function add_category( $args ) {

            $default = array(
                'name'             => '',
                'slug'             => '',
                'parent'           => 0,
                'meta_title'       => '',
                'meta_keywords'    => '',
                'meta_description' => '',
                'description'      => ''
            );

            $args = wp_parse_args( $args, $default );

            if ( empty( $args['name'] ) )
                return false;

            if ( empty( $args['slug'] ) )
                $args['slug'] = sanitize_title( $args['name'] );

            $category = term_exists( $args['name'], 'product_cat' );

            if ( ! is_array( $category ) )
                $category = wp_insert_term( $args['name'], 'product_cat', array(
                    'parent'        => $args['parent'],
                    'description'   => $args['description'],
                    'slug'          => $args['slug']
                ) );

            if ( ! is_wp_error( $category ) )
                return $category['term_id'];
        }


        /**
         * ASSOCIATE UPSELLS VIA SKU
         *
         * Associate to a product a list of upsells via their SKU Code
         *
         * @access public
         * @param  integer     $product_id    Product ID
         *         array       $upsell_skus   List of upsells SKU Code
         * @return void
         */
        public function associate_upsell_sku( $product_id, $upsell_skus = array() ) {
            global $wpdb;
            $upsell_ids = array();

            foreach ( $upsell_skus as $upsell_sku ) {

                $upsell_id = $this->product_exist( $upsell_sku );

                if ( $upsell_id )
                    $upsell_ids[] = $upsell_id;
            }

            // Update DB
            update_post_meta( $product_id, '_upsell_ids', $upsell_ids );
        }


        /**
         * ASSOCIATE CROSSSELLS VIA SKU
         *
         * Associate to a product a list of crosssells via their SKU Code
         *
         * @access public
         * @param  integer     $product_id    Product ID
         *         array       $upsell_skus   List of crosssell SKU Code
         * @return void
         */
        public function associate_crosssell_sku( $product_id, $crosssell_skus = array() ) {
            global $wpdb;
            $crosssell_ids = array();

            foreach ( $crosssell_skus as $crosssell_sku ) {
                $crosssell_id = $this->product_exist( $crosssell_sku );

                if ( $crosssell_id )
                    $crosssell_ids[] = $crosssell_id;
            }

            // Update DB
            update_post_meta( $product_id, '_crosssell_ids', $crosssell_ids );
        }


        /**
         * PRODUCT EXIST
         *
         * Check in the DB if a product has already a given SKU code
         *
         * @access public
         * @param  string   $sku            SKU code
         * @return mixed    $product_id     Return product ID if exist, false if not
         */
        public function product_exist( $sku ) {
            global $wpdb;
            $product_id = $wpdb->get_var( $wpdb->prepare( "SELECT post_id FROM $wpdb->postmeta WHERE meta_key='_sku' AND meta_value= %s LIMIT 1", $sku ) );

            return $product_id;
        }


        /**
         * Recursive version of wp_parse_args()
         *
         * @access private
         */
        private function parse_args_r( &$a, $b ) {
            $a = (array) $a;
            $b = (array) $b;
            $r = $b;

            foreach ( $a as $k => &$v ) {
                if ( is_array( $v ) && isset( $r[ $k ] ) ) {
                    $r[ $k ] = self::parse_args_r( $v, $r[ $k ] );
                } else {
                    $r[ $k ] = $v;
                }
            }

            return $r;
        }
    }
}
?>
