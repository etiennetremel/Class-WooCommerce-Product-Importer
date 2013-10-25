Class WooCommerce Product Importer
==================================

Import products into WooCommerce: add images, variations, categories, upsells, crosssells

Work for WooCommmerce 2.0


## Usage

### Add a product

* Fetch image from remote server, first image will be added as featured image, other added into product gallery
* Add 2 variations: Blue and Red with different stock level and price


```php
require( 'class-woocommerce-product-importer.php' );

$wc_importer = new WooCommerce_Product_Importer();

$args = array(
    'name'               => 'Mobile WX6',
    'status'             => 'publish',
    'description'        => 'Lorem ipsum dolor sit amet, consectetur adipiscing elit. Nam sed ullamcorper velit. Vestibulum sodales sapien vel consectetur hendrerit. Ut lacinia felis velit, vitae tincidunt mauris ultrices et.',
    'excerpt'            => 'Wonderful product',
    'tags'               => array('mobile', 'wireless'),
    'images'             => array('http://remote.com/images/mobilewe.jpg', 'http://remote.com/images/mobilewe_back.jpg'),
    'categories'         => array('Mobile Device'),
    'fetch_image_type'   => 'remote',
    'type'               => 'simple',
    'metas'              => array(
        '_sku'                      => 'WX620013',
        '_regular_price'            => '120.95',
        '_sale_price'               => '99.99',
        '_product_attributes'       => array(
            array(
                'name'   => 'Color',
                'values' => array(
                    'Blue'  => array(
                        '_sku'              => 'WX620013B',
                        '_manage_stock'     => 'yes',
                        '_regular_price'    => '120.95',
                        '_sale_price'       => '99.99',
                        '_stock'            => '8'
                    ),
                    'Red'   => array(
                        '_sku'              => 'WX620013R',
                        '_manage_stock'     => 'yes',
                        '_regular_price'    => '120.95',
                        '_sale_price'       => '89.99',
                        '_stock'            => '34'
                    )
                )
            )
        )
    )
);

$wc_importer->add_product( $args );
```


### Add a category
```php
require( 'class-woocommerce-product-importer.php' );

$wc_importer = new WooCommerce_Product_Importer();

// Add main category
$args = array(
    'name'             => 'Bike',
    'description'      => 'This is the description of the category'
);
$category_id = $wc_importer->add_category( $args );

// Add a subcategory
$args = array(
    'name'             => 'Road Bike',
    'parent'           => $category_id,
    'description'      => 'This is the description of the sub-category'
);
$sub_category_id = $wc_importer->add_category( $args );
```


### Add image to product from remote server
```php
require( 'class-woocommerce-product-importer.php' );

$wc_importer = new WooCommerce_Product_Importer();

$images = array(
    'http://remote.com/bike_front.jpg', // First image will be defined as featured image
    'http://remote.com/bike_back.jpg',  // Other images are added as element in the product gallery
    'http://remote.com/bike_zoomed.jpg'
);
$product_id = 87;

$wc_importer->add_image_to_product( $product_id $args );
```