<?php
/*
Plugin Name:  Soupenzo - Sequential Certificates
Plugin URI:   https://soupenzo.nl
Text Domain:  soupenzo-certificates
Domain Path:  /languages
Description:  Adds custom sequential certificate permissions per order
Author:       Jason Jeffers
Version:      1.0
Author URI:   http://ordnung.nl
*/

if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

class Soupenzo_Certificates {

  public function init()
  {
    add_action( 'plugins_loaded', array($this, 'soupenzo_load_plugin_textdomain') );
    add_action( 'woocommerce_grant_product_download_permissions', array($this, 'get_downloadable_file_names_per_order'), 10, 1 );
    add_action( 'woocommerce_grant_product_download_permissions', array($this, 'add_downloadable_files_to_product'), 20, 1 );
    add_action( 'woocommerce_grant_product_download_permissions', array($this, 'set_downloadable_file_permissions'), 30, 1 );
    add_action( 'woocommerce_admin_order_data_after_billing_address', array($this, 'certificate_numbers_display_admin_order_meta') );
    add_filter( 'woocommerce_my_account_my_orders_actions', array($this, 'add_download_zip_recent_orders_action'), 10, 2 );
    add_filter( 'woocommerce_email_attachments', array($this, 'attach_zip_to_emails'), 10, 3 ); // Always add argument numbers to filters as by default it will only pass one (the first) argument
    // add_action( 'woocommerce_thankyou', array($this, 'set_downloadable_file_permissions') );  // Test action otherwise no feedback
  }

  // We are using Download Manager to import filenames, certificate numbers and categories (5 year, 10 year) for easier querying.
  // IMPORTANT TO MATCH DLM TITLE TO FILENAME!!!

  // Load plugin text domain
  public function soupenzo_load_plugin_textdomain()
  {
    load_plugin_textdomain( 'soupenzo-certificates', FALSE, basename( dirname( __FILE__ ) ) . '/languages/' );
  }

  /**
  * Get downloadable file names per order based on start number, category and quantity/range.
  * Update parent start number.
  * Prepare zip download files based on queried file names.
  * Hook: 'woocommerce_grant_product_download_permissions'
  *
  * @param integer $order_id the ID of the order
  */

  public function get_downloadable_file_names_per_order( $order_id )
  {
    $order = wc_get_order( $order_id );
    $order_data = $order->get_data(); // The Order data
    $customer_id = $order_data['customer_id'];

    if ( sizeof( $order->get_items() ) > 0 )
    {
      //Get all items in order
      foreach ($order->get_items() as $item_id => $item)
      {
        //Product Data
        $product = $item->get_product();

        if ( $product && $product->exists() && $product->is_downloadable() )
        {

          // Variables
          $parent_product_id  = $item->get_product_id();
          $variation_id       = !empty($item['variation_id']) ? $item['variation_id'] : '';
          $user_id            = $order->get_user_id();
          $quantity           = intval($item['quantity']);
          $range              = [];
          $start_number_5     = get_post_meta( $parent_product_id, 'start_number_5', true );
          $start_number_10    = get_post_meta($parent_product_id, 'start_number_10', true );

          if ( $variation_id == 20 || $variation_id == 27 ) // 5 year. TODO Add variation IDs for subsites
          {
            $start_number     = intval($start_number_5);
            $start_field      = 'start_number_5';
            $category         = '5-year';
          }

          if ( $variation_id == 21 || $variation_id == 28) // 10 year. TODO Add variation IDs for subsites
          {
            $start_number     = intval($start_number_10);
            $start_field      = 'start_number_10';
            $category         = '10-year';
          }

          $range = $this->get_range( $quantity, $start_number );

          if (function_exists('download_monitor') )
          {
            $downloadable_files[] = $this->downloadable_files_in_range($category, $range);
          }

        }

        // Update start number - TODO: Update shared parent product start numbers on both network sites
        $start_field_new =  $start_number + $quantity;
        update_post_meta( $parent_product_id, $start_field, $start_field_new );

        $this->update_certificate_numbers_order_meta( $order_id, $category, $range ); // Update certificate numbers in admin order edit page and CSV export

      }

      // Prepare for zip creation
      foreach ( $downloadable_files as $files )
      {
        foreach ( $files as $file ) {
          $file_names[] = $file->post->post_title;
        }
      }

      $this->prepare_zip_downloadable_files( $file_names, $customer_id, $order_id );
    }
  }

    /**
  * Add downloadable zip file to all products before permissions can be set.
  * Separate add_action in init() to insure zip file (placeholder) has been set.
  * Hook: 'woocommerce_grant_product_download_permissions'
  *
  * @param integer $order_id the ID of the order
  */

  public function add_downloadable_files_to_product( $order_id )
  {

    // Order data
    $order        = wc_get_order( $order_id );
    $order_data   = $order->get_data();
    $customer_id  = $order_data['customer_id'];

    // Zip file data
    $zip_file     = get_user_meta( $customer_id, '_certificate_zip_file', true); // Placeholder
    $path_source  = trailingslashit( get_site_url() ) . 'wp-content/uploads/customer_downloads/'; // TODO: Change location in new site!
    $file_title   = __( 'Get Certificates', 'soupenzo-certificates' ); // Visible in Accounts -> Order and Accounts -> Downloads

    if ( sizeof( $order->get_items() ) > 0 )
    {
      //Get all items in order
      foreach ($order->get_items() as $item_id => $item)
      {

        $product = $item->get_product();

        if ( $product && $product->exists() && $product->is_downloadable() )
        {

          // Download data
          $downloads    = (array) $product->get_downloads();

          $file_url   = $path_source . $zip_file;
          $file_md5   = md5($file_url);

          $download  = new WC_Product_Download(); // Get an instance of the WC_Product_Download Object

          // Set the download data
          $download->set_name($file_title);
          $download->set_id($file_md5);
          $download->set_file($file_url);

          $downloads[$file_md5] = $download; // Insert the new download to the array of downloads

          $product->set_downloads($downloads); // Set new array of downloads
          $product->save(); // Save the data in database

        }
      }
    }
  }

	/**
  * Save the download permissions on the individual order.
  * This takes care of three possible order situations:
  * 1. Only 5 year certificates
  * 2. 5 year AND 10 year certificates
  * 3. Only 10 year certificates
  * The last variation in the order determines the permissions for the order. A quirk of Woocommerce downloadable_files and permissions.
  * At this point it is guaranteed that it has files in it and that it hasn't been granted permissions before.

  * Hook: 'woocommerce_grant_product_download_permissions'
  * Requires Download Monitor
  *
  * @param integer $order_id the ID of the order
  */

  public function set_downloadable_file_permissions( $order_id )
  {
    $order = wc_get_order( $order_id );
    $order_data = $order->get_data(); // The Order data
    $customer_id = $order_data['customer_id'];

    $zip_file     = get_user_meta( $customer_id, '_certificate_zip_file', true);
    $path_source  = trailingslashit( get_site_url() ) . 'wp-content/uploads/customer_downloads/'; // TODO: Change location in new site!
    $zip_file_md5 = md5($path_source . $zip_file);

    if ( sizeof( $order->get_items() ) > 0 )
    {
      //Get all items in order
      foreach ($order->get_items() as $item_id => $item)
      {
        //Product Data
        $product = $item->get_product();
        $variation_id = !empty($item['variation_id']) ? $item['variation_id'] : '';

        if ( $product && $product->exists() && $product->is_downloadable() )
        {
          $this->revoke_downloadable_file_permissions($variation_id, $order_id, $customer_id); // Test if necessary
        }
      }

      // Outside the foreach loop - see function description
      wc_downloadable_file_permission( $zip_file_md5, $variation_id, $order, 1 );
    }
  }

  // HELPER FUNCTIONS

  /**
  * Prepares and creates zip files. Update user meta for current zip file name (placeholder).
  * Used to add zip file as download to product and later to set permissions.
  *
  * @param array $file_names from get_downloadable_file_names_per_order()
  * @param integer $customer_id the customer ID attached to the order
  * @param integer $order_id the ID of the order
  */

  public function prepare_zip_downloadable_files( $file_names, $customer_id, $order_id )
  {
    $path_source      = WP_CONTENT_DIR . '/uploads/certificates/'; // TODO: Upload all PDFs here for zip creation. This is in addition to the DLM file uploads. OR use only use DLM uploads folder (wp-content/uploads/dlm_uploads/2021/06/).
    $path_destination = WP_CONTENT_DIR . '/uploads/customer_downloads/'; // TODO: Create folder in new site!
    $zip_file_name    = __('Soupenzo-Certificates', 'soupenzo-certificates') . '-order-' . $order_id . '.zip';
    $destination      = $path_destination . $zip_file_name;

    if ( $file_names)
    {
      foreach ($file_names as $file_name)
      {
        $files[] = $path_source . $file_name . '.pdf';
      }
      if ( extension_loaded( 'zip' ) )
      {
        self::create_zip_downloads( $files, $destination, false, true );
      }
      if ( file_exists( $destination ) )
      {
        update_user_meta( $customer_id, '_certificate_zip_file', $zip_file_name ); // Updates "current" user zip file. Used as a placeholder.
      }
    }
  }

  /**
  * Create downloadable zip of certificates in range per order
  * https://davidwalsh.name/create-zip-php
  *
  * @param array $files - prepared file names in order
  * @param string $destination - file path/name
  * @param bool $overwrite - create if new, overwrite if it exists
  * @param bool $distill_subdirectories - remove folder paths, return single folder of files
  */

  public static function create_zip_downloads( $files = array(), $destination = '', $overwrite = false,  $distill_subdirectories = false )
  {

    // If the zip file already exists and overwrite is false, return false
	  if ( file_exists( $destination ) && !$overwrite ) { return false; }

    $valid_files = array();

    if ( is_array( $files ) )
    {
      // Cycle through each file
      foreach ( $files as $file )
      {
        // Make sure the file exists
        if ( file_exists( $file ) )
        {
          $valid_files[] = $file;
        }
      }
    }

    // If we have valid files
    if ( count( $valid_files ) )
    {
      // Create the archive
      $zip = new ZipArchive();

      if ( $zip->open( $destination, $overwrite ? ZIPARCHIVE::OVERWRITE : ZIPARCHIVE::CREATE ) !== true )
      {
        return false;
      }

      // Add the files
      foreach ( $valid_files as $file )
      {
        if ( $distill_subdirectories )
        {
            $zip->addFile( $file, basename( $file ) );
        } else {
            $zip->addFile( $file, $file );
        }
      }

      // Debug
      // echo 'The zip archive contains ',$zip->numFiles,' files with a status of ',$zip->status;

      // Close the zip -- done!
      $zip->close();

      // Check to make sure the file exists
      return file_exists( $destination );
    }
    else
    {
      return false;
    }
  }

  /**
  * Get download files range
  * Requires Download Monitor
  *
  * @param string $quantity - each certificate
  * @param int $start_number - in product
  * @return string $range
  */

  public static function get_range( $quantity, $start_number )
  {
    $range = [];
    for ($i = $start_number; $i < ( $quantity + $start_number ); $i++)
    {
      $range[] = str_pad(strval($i), 4, "0", STR_PAD_LEFT); // Pad range with leading zeros
    }
    return $range;
  }

  /**
  * Downloadable files in range
  * Requires Download Monitor
  *
  * @param string $category certificate category
  * @return string $range range of certificates in order
  * @return array $downloadable_files
  */

  public static function downloadable_files_in_range( $category, $range )
  {

    // Get Certificates based on the order from Download Monitor
    $meta_query_args = [
      [
        'key'     => 'certificate_category',
        'value'   => $category,
        'compare' => 'IN',
      ],
      [
        'key'     => 'certificate_number',
        'value'   => $range,
        'compare' => 'IN',
      ],
    ];

    // Prepare download data
    $downloadable_files = download_monitor()->service( 'download_repository' )->retrieve(
      array(
        'meta_query' => $meta_query_args,
        'order' => 'ASC'
      )
    );

    return $downloadable_files;
  }

  /**
  * Revokes download permissions from permissions table.
  * If a product has multiple files, all permissions will be revoked from the original order.
  *
  * @param int $variation_id of the item in the order
  * @param int $order_id the ID for the original order
  * @param int $customer_id the customer associated with the order
  * @return delete product permissions
  */

  public static function revoke_downloadable_file_permissions( $variation_id, $order_id, $customer_id )
  {
		global $wpdb;

		$table = $wpdb->prefix . 'woocommerce_downloadable_product_permissions';

		$where = array(
			'product_id' => $variation_id,
			'order_id'   => $order_id,
			'user_id'    => $customer_id,
		);

		$format = array( '%d', '%d', '%d' );

    return $wpdb->delete( $table, $where, $format );

  }

  /**
  * Update _cert_numbers meta field for admin order edit page and in order for CSV export
  *
  * @param int $order_id the ID for the original order
  * @param string $category certificate category
  * @return string $range range of certificates in order
  */

  public static function update_certificate_numbers_order_meta( $order_id, $category, $range )
  {

    $cert_cat = $category == '5-year' ? '_cert_numbers_5' : '_cert_numbers_10';
    $range = implode(', ', $range);

    update_post_meta( $order_id, $cert_cat, $range );

  }

  /**
  * Display custom fields on the admin order edit page
  * Hook: 'woocommerce_admin_order_data_after_billing_address'
  *
  * @param int $order_id the ID for the original order
  */

  public static function certificate_numbers_display_admin_order_meta( $order )
  {

    $post_meta_5_year   = implode(get_post_meta( $order->get_id(), '_cert_numbers_5' ));
    $post_meta_10_year  = implode(get_post_meta( $order->get_id(), '_cert_numbers_10' ));

    if ( !empty( $post_meta_5_year ) ) { echo '<p><strong>' . __('Certificate Numbers - 5 year', 'soupenzo-certificates') . ':</strong> ' . $post_meta_5_year . '</p>'; }
    if ( !empty( $post_meta_10_year ) ) { echo '<p><strong>' . __('Certificate Numbers - 10 year', 'soupenzo-certificates') . ':</strong> ' . $post_meta_10_year . '</p>'; }

  }

  /**
  * Adds a custom order action in the "Recent Orders" table of my account
  * Hook: 'woocommerce_my_account_my_orders_actions'
  * Button 'Get Certificates' downloads custom zip file for the order
  * Requires Download Monitor
  *
  * @param array $actions the actions available for the order
  * @param \WC_Order $order the order object for this row
  * @return array the updated order actions
  */

  public static function add_download_zip_recent_orders_action( $actions, $order ) {

    // Only add our button if the order is paid for
    if ( ! $order->is_paid() )
    {
      return $actions;
    }

    // Iterating through each "line" items in the order
    foreach ( $order->get_items() as $item_id => $item ) {

      $item_downloads = $item->get_item_downloads(); // Get the item downloads

      foreach ( $item_downloads as $item_download ) {

        $actions['files'] = array(
          'url'	=> $item_download['download_url'],
          'name'	=> __( 'Get Certificates', 'soupenzo-certificates' ),
        );

      }
    }

    return $actions;
  }

  /**
  * Attach zip to emails - new order, customer completed order and customer invoice emails.
  * Hook: 'woocommerce_email_attachments'
  *
  * @param array $attachments array of email attachments
  * @param int $email_id
  * @return object $order
  */

  public static function attach_zip_to_emails( $attachments, $email_id, $order ) {

    // Avoiding errors and problems
    if ( ! is_a( $order, 'WC_Order' ) || ! isset( $email_id ) ) {
      return $attachments;
    }

    $order_data   = $order->get_data(); // The Order data
    $customer_id  = $order_data['customer_id'];
    $path_source  = WP_CONTENT_DIR . '/uploads/customer_downloads/'; // TODO: Change location in new site!
    $email_ids = array( 'new_order', 'customer_completed_order', 'customer_invoice' ); // Email triggers

    if ( $customer_zip_file = get_user_meta( $customer_id, '_certificate_zip_file', true ) )
    {
      if ( in_array ( $email_id, $email_ids ) ) {
        $zip_file = $path_source . $customer_zip_file;
        $attachments[] = $zip_file;
      }
    }

    return $attachments;
  }

}

$soupenzo_certificates = new Soupenzo_Certificates();
$soupenzo_certificates->init();