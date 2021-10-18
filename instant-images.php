<?php
/**
 * Plugin Name: Instant Images
 * Plugin URI: https://connekthq.com/plugins/instant-images/
 * Description: One click photo uploads directly to your media library.
 * Author: Darren Cooney
 * Twitter: @connekthq
 * Author URI: https://connekthq.com
 * Text Domain: instant-images
 * Version: 4.4.0.3
 * License: GPL
 * Copyright: Darren Cooney & Connekt Media
 *
 * @package InstantImages
 */

/*
NEW: Added button to auto-generate Photo attribution in image caption.
NEW: Added unistaller script to remove plugin settings.
UPDATE: Updated styling and fucntionality of photo detail editor.

TODO:
- Pixabay
	- API Key Option [DONE]
	- When key is not valid, need to handle 400 errors.
	- Create error state that informs the user to enter a valid API key.
	- Auto test the API key before submitting the form.
	- Create method to add API Key outside of settings. Update all settings at once.
	- If pixabay API key is empty, display a view with info on how to add a key.

- Send REST request to update API settings field for API key. (api/settings.php) [DONE]

- Add pixaby search [DONE]
	- Search By ID [DONE]

- User Search
- Add Color filters.

- Search - Load More not working. [ DONE]
- Fix reset (switchProvider) function. [ DONE]
	- This is not working because of react state

- Default Provider [ DONE]
- Add setting for setting a default provider (Unsplash/Pixabay) [ DONE]

- Initiate Load
	- What happens when Pixbay key is error.

- Gutenberg & Modals
	- Confirm everything works in all instances.
	- Confirm default and errors are displayed.

*/

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

define( 'INSTANT_IMAGES_VERSION', '4.4.0.3' );
define( 'INSTANT_IMAGES_RELEASE', 'July 30, 2021' );

/**
 * Activation hook
 *
 * @since 2.0
 * @author ConnektMedia <support@connekthq.com>
 */
function instant_images_activate() {
	// Create /instant-images directory inside /uploads to temporarily store images.
	$upload_dir = wp_upload_dir();
	$dir        = $upload_dir['basedir'] . '/instant-images';
	if ( ! is_dir( $dir ) ) {
		wp_mkdir_p( $dir );
	}
}
register_activation_hook( __FILE__, 'instant_images_activate' );

/**
 * Deactivation hook
 *
 * @since 3.2.2
 * @author ConnektMedia <support@connekthq.com>
 */
function instant_images_deactivate() {
	// Delete /instant-images directory inside /uploads to temporarily store images.
	$upload_dir = wp_upload_dir();
	$dir        = $upload_dir['basedir'] . '/instant-images';

	if ( is_dir( $dir ) ) {
		// Check for files in dir.
		foreach ( glob( $dir . '/*.*' ) as $filename ) {
			if ( is_file( $filename ) ) {
				unlink( $filename );
			}
		}
		// Delete the directory.
		rmdir( $dir );
	}
}
register_deactivation_hook( __FILE__, 'instant_images_deactivate' );

/**
 * InstantImages class
 *
 * @since 2.0
 * @author ConnektMedia <support@connekthq.com>
 */
class InstantImages {

	/**
	 * Set up plugin.
	 *
	 * @since 2.0
	 * @author ConnektMedia <support@connekthq.com>
	 */
	public function __construct() {

		add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), array( &$this, 'instant_images_add_action_links' ) );
		add_action( 'enqueue_block_editor_assets', array( &$this, 'instant_img_block_plugin_enqueue' ) );
		add_action( 'wp_enqueue_media', array( &$this, 'instant_img_wp_media_enqueue' ) );
		load_plugin_textdomain( 'instant-images', false, dirname( plugin_basename( __FILE__ ) ) . '/lang/' ); // load text domain.
		$this->includes();
		$this->constants();
	}

	/**
	 * Enqueue Gutenberg Block sidebar plugin
	 *
	 * @since 3.0
	 * @author ConnektMedia <support@connekthq.com>
	 */
	public function instant_img_block_plugin_enqueue() {

		if ( $this::instant_img_has_access() && $this::instant_img_not_current_screen( [ 'widgets' ] ) ) {

			$suffix = ( defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG ) ? '' : '.min'; // Use minified libraries for SCRIPT_DEBUG.

			wp_enqueue_script(
				'instant-images-block',
				INSTANT_IMAGES_URL . 'dist/js/instant-images-block' . $suffix . '.js',
				'',
				INSTANT_IMAGES_VERSION,
				true
			);

			wp_enqueue_style(
				'admin-instant-images',
				INSTANT_IMAGES_URL . 'dist/css/instant-images' . $suffix . '.css',
				array( 'wp-edit-post' ),
				INSTANT_IMAGES_VERSION
			);

			$this::instant_img_localize( 'instant-images-block' );

		}
	}

	/**
	 * Enqueue script for Media Modal and Blocks sidebar
	 *
	 * @since 4.0
	 * @author ConnektMedia <support@connekthq.com>
	 */
	public function instant_img_wp_media_enqueue() {

		$suffix   = ( defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG ) ? '' : '.min'; // Use minified libraries for SCRIPT_DEBUG.
		$show_tab = $this::instant_img_show_tab( 'media_modal_display' );  // Show Tab Setting.

		if ( $this::instant_img_has_access() && $show_tab ) {

			wp_enqueue_script(
				'instant-images-media-router',
				INSTANT_IMAGES_URL . 'dist/js/instant-images-media' . $suffix . '.js',
				'',
				INSTANT_IMAGES_VERSION,
				true
			);

			wp_enqueue_style(
				'admin-instant-images',
				INSTANT_IMAGES_URL . 'dist/css/instant-images' . $suffix . '.css',
				'',
				INSTANT_IMAGES_VERSION
			);
			$this::instant_img_localize( 'instant-images-media-router' );
		}
	}

	/**
	 * Localization strings and settings
	 *
	 * @param string $script id.
	 * @since 2.0
	 * @author ConnektMedia <support@connekthq.com>
	 */
	public static function instant_img_localize( $script = 'instant-images-react' ) {

		global $post;
		$options          = get_option( 'instant_img_settings' );
		$download_w       = isset( $options['unsplash_download_w'] ) ? $options['unsplash_download_w'] : 1600; // width of download file.
		$download_h       = isset( $options['unsplash_download_h'] ) ? $options['unsplash_download_h'] : 1200; // height of downloads.
		$default_provider = isset( $options['default_provider'] ) ? $options['default_provider'] : 'unsplash'; // Default provider.
		$pixabay_api      = isset( $options['pixabay_api'] ) ? $options['pixabay_api'] : ''; // Pixabay API.

		wp_localize_script(
			$script,
			'instant_img_localize',
			array(
				'instant_images'     => __( 'Instant Images', 'instant-images' ),
				'root'               => esc_url_raw( rest_url() ),
				'nonce'              => wp_create_nonce( 'wp_rest' ),
				'ajax_url'           => admin_url( 'admin-ajax.php' ),
				'admin_nonce'        => wp_create_nonce( 'instant_img_nonce' ),
				'parent_id'          => ( $post ) ? $post->ID : 0,
				'default_provider'   => $default_provider,
				'download_width'     => esc_html( $download_w ),
				'download_height'    => esc_html( $download_h ),
				'unsplash_app_id'    => INSTANT_IMAGES_DEFAULT_APP_ID,
				'unsplash_url'       => 'https://unsplash.com',
				'unsplash_api_url'   => 'https://unsplash.com/developers',
				'pixabay_app_id'     => $pixabay_api,
				'pixabay_url'        => 'https://pixabay.com',
				'pixabay_api_url'    => 'https://pixabay.com/service/about/api/',
				'pixabay_api_desc'   => __( 'Access to images from Pixabay requires a valid API key. API keys are available for free, just sign up for an account at Pixabay, enter your API key below and you\'re good to go!', 'instant-images' ),
				'error_upload'       => __( 'There was no response while attempting to the download image to your server. Check your server permission and max file upload size or try again', 'instant-images' ),
				'error_restapi'      => '<strong>' . __( 'There was an error accessing the WP REST API.', 'instant-images' ) . '</strong><br/>',
				'error_restapi_desc' => __( 'Instant Images requires access to the WP REST API via <u>POST</u> request to fetch and upload images to your media library.', 'instant-images' ),
				'photo_by'           => __( 'Photo by', 'instant-images' ),
				'view_all'           => __( 'View All Photos by', 'instant-images' ),
				'upload'             => __( 'Click Image to Upload', 'instant-images' ),
				'upload_btn'         => __( 'Click to Upload', 'instant-images' ),
				'full_size'          => __( 'View Full Size', 'instant-images' ),
				'likes'              => __( 'Like', 'instant-images' ),
				'likes_plural'       => __( 'Likes', 'instant-images' ),
				'saving'             => __( 'Downloading image...', 'instant-images' ),
				'resizing'           => __( 'Creating image sizes...', 'instant-images' ),
				'resizing_still'     => __( 'Still resizing...', 'instant-images' ),
				'no_results'         => __( 'Sorry, nothing matched your query', 'instant-images' ),
				'no_results_desc'    => __( 'Please try adjusting your search criteria', 'instant-images' ),
				'latest'             => __( 'New', 'instant-images' ),
				'oldest'             => __( 'Oldest', 'instant-images' ),
				'popular'            => __( 'Popular', 'instant-images' ),
				'views'              => __( 'Views', 'instant-images' ),
				'downloads'          => __( 'Downloads', 'instant-images' ),
				'load_more'          => __( 'Load More Images', 'instant-images' ),
				'search'             => __( 'Search for Toronto + Coffee etc...', 'instant-images' ),
				'search_label'       => __( 'Search', 'instant-images' ),
				'search_results'     => __( 'images found for', 'instant-images' ),
				'clear_search'       => __( 'Clear Search Results', 'instant-images' ),
				'view_on_unsplash'   => __( 'View on Unsplash', 'instant-images' ),
				'view_on_pixabay'    => __( 'View on Pixabay', 'instant-images' ),
				'set_as_featured'    => __( 'Set as Featured Image', 'instant-images' ),
				'insert_into_post'   => __( 'Insert Into Post', 'instant-images' ),
				'edit_filename'      => __( 'Filename', 'instant-images' ),
				'edit_title'         => __( 'Title', 'instant-images' ),
				'edit_alt'           => __( 'Alt Text', 'instant-images' ),
				'edit_caption'       => __( 'Caption', 'instant-images' ),
				'edit_upload'        => __( 'Edit Attachment Details', 'instant-images' ),
				'edit_details'       => __( 'Edit Image Details', 'instant-images' ),
				'edit_details_intro' => __( 'Update image details prior to uploading.', 'instant-images' ),
				'cancel'             => __( 'Cancel', 'instant-images' ),
				'save'               => __( 'Save', 'instant-images' ),
				'upload_now'         => __( 'Upload', 'instant-images' ),
				'orientation'        => __( 'Orientation', 'instant-images' ),
				'landscape'          => __( 'Landscape', 'instant-images' ),
				'portrait'           => __( 'Portrait', 'instant-images' ),
				'squarish'           => __( 'Squarish', 'instant-images' ),
				'horizontal'         => __( 'Horizontal', 'instant-images' ),
				'vertical'           => __( 'Vertical', 'instant-images' ),
				'attribution'        => __( 'Add Photo Attribution', 'instant-images' ),
				'btnClose'           => __( 'Close', 'instant-images' ),
				'btnVerify'          => __( 'Verify', 'instant-images' ),
				'enter_api_key'      => __( 'Enter API Key', 'instant-images' ),
				'api_key_invalid'    => __( 'The API Key is Invalid', 'instant-images' ),
				'api_success_msg'    => __( 'API key has been successfully validated!', 'instant-images' ),
				'api_invalid_msg'    => __( 'API key entered is not valid - try again.', 'instant-images' ),
				'api_ratelimit_msg'  => __( 'Your daily or hourly API rate limit has been exceeded. Try again later.', 'instant-images' ),
				'get_api_key'        => __( 'Get API Key Now', 'instant-images' ),
			)
		);
	}

	/**
	 * Include these files in the admin
	 *
	 * @since 2.0
	 * @author ConnektMedia <support@connekthq.com>
	 */
	private function includes() {
		if ( is_admin() ) {
			require_once __DIR__ . '/admin/admin.php';
			require_once __DIR__ . '/admin/includes/settings.php';
			require_once __DIR__ . '/admin/vendor/connekt-plugin-installer/class-connekt-plugin-installer.php';
		}
		// REST API Routes.
		require_once 'api/test.php';
		require_once 'api/download.php';
		require_once 'api/settings.php';
	}

	/**
	 * Show tab to upload image on post edit screens
	 *
	 * @param string $option WP Option.
	 * @return $show boolean
	 * @since 3.2.1
	 * @author ConnektMedia <support@connekthq.com>
	 */
	public static function instant_img_show_tab( $option ) {
		if ( ! $option ) {
			return true;
		}

		$options = get_option( 'instant_img_settings' );
		$show    = true;
		if ( isset( $options[ $option ] ) ) {
			if ( '1' === $options[ $option ] ) {
				$show = false;
			}
		}
		return $show;
	}

	/**
	 * Confirm user has access to instant images.
	 *
	 * @since 4.3.3
	 * @return boolean
	 * @author ConnektMedia <support@connekthq.com>
	 */
	public static function instant_img_has_access() {
		$access = false;
		if ( is_user_logged_in() && current_user_can( apply_filters( 'instant_images_user_role', 'upload_files' ) ) ) {
			$access = true;
		}
		return $access;
	}

	/**
	 * Block Instant Images from loading on some screens.
	 *
	 * @since 4.4.0.3
	 * @param array $array An array of screen IDs.
	 * @return boolean
	 * @author ConnektMedia <support@connekthq.com>
	 */
	public static function instant_img_not_current_screen( $array = [] ) {
		$access       = true;
		$admin_screen = get_current_screen();
		if ( $admin_screen && in_array( $admin_screen->id, $array, true ) ) {
			$access = false;
		}
		return $access;
	}

	/**
	 * Set up plugin constants
	 *
	 * @since 2.0
	 * @author dcooney
	 */
	private function constants() {
		define( 'INSTANT_IMAGES_TITLE', 'Instant Images' );
		$upload_dir = wp_upload_dir();
		define( 'INSTANT_IMAGES_UPLOAD_PATH', $upload_dir['basedir'] . '/instant-images' );
		define( 'INSTANT_IMAGES_UPLOAD_URL', $upload_dir['baseurl'] . '/instant-images/' );
		define( 'INSTANT_IMAGES_PATH', plugin_dir_path( __FILE__ ) );
		define( 'INSTANT_IMAGES_URL', plugins_url( '/', __FILE__ ) );
		define( 'INSTANT_IMAGES_ADMIN_URL', plugins_url( 'admin/', __FILE__ ) );
		define( 'INSTANT_IMAGES_WPADMIN_URL', admin_url( 'upload.php?page=instant-images' ) );
		define( 'INSTANT_IMAGES_NAME', 'instant-images' );
		define( 'INSTANT_IMAGES_DEFAULT_APP_ID', '5746b12f75e91c251bddf6f83bd2ad0d658122676e9bd2444e110951f9a04af8' );
	}


	/**
	 * Add custom links to plugins.php
	 *
	 * @param array $links current links.
	 * @since 2.0
	 * @return {Array} $mylinks
	 * @author dcooney
	 */
	public function instant_images_add_action_links( $links ) {
		$mylinks = array( '<a href="' . INSTANT_IMAGES_WPADMIN_URL . '">Upload Photos</a>' );
		return array_merge( $mylinks, $links );
	}

}

/**
 * The main function responsible for returning the one true InstantImages Instance.
 *
 * @since 2.0
 * @return $instant_images
 * @author dcooney
 */
function instant_images() {
	global $instant_images;
	if ( ! isset( $instant_images ) ) {
		$instant_images = new InstantImages();
	}
	return $instant_images;
}

instant_images(); // initialize.
