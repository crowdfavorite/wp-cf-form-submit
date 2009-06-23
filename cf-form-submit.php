<?php
/*
Plugin Name: cf-form-submit 
Plugin URI: http://crowdfavorite.com 
Description: Allows the processing of forms, utilizing such things as cf_post_meta 
Version: 0.7 
Author: Crowd Favorite
Author URI: http://crowdfavorite.com
*/

// ini_set('display_errors', '1'); ini_set('error_reporting', E_ALL);

//include some debug code
require_once(ABSPATH . 'wp-admin/includes/admin.php');
if (!defined('PLUGINDIR')) {
	define('PLUGINDIR','wp-content/plugins');
}

$cffs_error = new WP_Error;

if (!function_exists('is_admin_page')) {
        function is_admin_page() {
                if (function_exists('is_admin')) {
                        return is_admin();
                }
                if (function_exists('check_admin_referer')) {
                        return true;
                }
                else {
                        return false;
                }
        }
}

function cffs_admin_head() {
        global $wp_version;
        if (isset($wp_version) && version_compare($wp_version, '2.7', '>=')) {
                print("
<script type=\"text/javascript\">
jQuery(function($) {
        $('#menu-posts .wp-submenu ul').append('<li><a tabindex=\"1\" href=\"edit.php?post_status=pending&cat_ID=".CF_SHOWCASE_ID."\">Pending Approval</a></li>');
});
</script>
                ");
        }
}

if (is_admin_page()) {
        wp_enqueue_script('jquery');
}
add_action('admin_head', 'cffs_admin_head');

function cffs_init() {
// TODO
}
add_action('init', 'cffs_init');


function cffs_request_handler() {
	global $cffs_config;
	$cffs_config = apply_filters('cffs_add_config',$cffs_config);
	if (!empty($_GET['cf_action'])) {
		switch ($_GET['cf_action']) {

		}
	}
	if (!empty($_POST['cf_action'])) {
		switch ($_POST['cf_action']) {
			case 'cffs_submit':
				// validate the data
				
				if ($valid_data = cffs_validate_data()) {

					if (cffs_save_data($valid_data)) {
						echo "<h3>We have reached the Save function successfully</h3>";
					}
				}
		}
	}
}
add_action('init', 'cffs_request_handler', 11);

// activate the post meta plugin for use outside of the admin interface
function cffs_run_post_meta($val) {
	if ($_POST['cf_action'] == 'cffs_submit') {
		
		return true;
	}
	return $val;
}
add_filter('cf_meta_actions', 'cffs_run_post_meta', 10);

/**
 * @todo implement more explicit validation based on configs
**/
function cffs_validate_data() {
	global $cffs_error, $cffs_config;
	$cffs_allowed_postdata = apply_filters('cffs_get_postdata_fields',array('post_title','post_content'));
	
	$data = apply_filters('cffs_filter_postdata',$data);
		
	foreach ($cffs_config['required'] as $postkey => $postvalue) {
		if (!isset($_POST[$postkey]) || empty($_POST[$postkey])) {
			$cffs_error->add($postkey,"You must enter a value for $postvalue");
		}
	}
	
	foreach ($cffs_allowed_postdata as $key) {
		if (isset($_POST[$key]) && !empty($_POST[$key])) {
			$data['postdata'][$key] = $_POST[$key];
		}
	}
	if (isset($cffs_config['post_meta']) && is_array($cffs_config['post_meta'])) {
				
		$i = 0;
		foreach ($cffs_config['post_meta'] as $post_meta) {
			
			if (isset($_POST[$post_meta]) && !empty($_POST[$post_meta])) {
				$data['post_meta'][$post_meta] = attribute_escape($_POST[$post_meta]);
			}
			elseif (isset($_POST['blocks'][$post_meta])) {			
				foreach ($cffs_config[$post_meta] as $children_name => $children_value) {
					$data['post_meta'][$post_meta][$i][$children_name] = attribute_escape($_POST['post_meta'][$post_meta][$i][$children_name]);
					
				}
			}
			elseif (/*isset($_FILES[$post_meta]) && */$_FILES[$post_meta]['size'] > 0 && $_FILES[$post_meta]['temp_name'] != 'none') {
				$post_meta_image_id = cffs_process_image($_FILES[$post_meta]);
				$data['post_meta'][$post_meta] = $post_meta_image_id;
			}
		}
	}
	if (isset($cffs_config['user_meta']) && is_array($cffs_config['user_meta'])) {
		foreach ($cffs_config['user_meta'] as $user_meta) {
			if (isset($_POST[$user_meta]) && !empty($_POST[$user_meta])) {
				$data['user_meta'][$user_meta] = attribute_escape($_POST[$user_meta]);
			}
			elseif (isset($_FILES[$user_meta]) && $_FILES[$user_meta]['size'] > 0 && $_FILES[$user_meta]['temp_name'] != 'none') {
				$user_meta_image_id = cffs_process_image($_FILES[$user_meta]);
				$data['user_meta'][$user_meta] = $user_meta_image_id;
			}
		}
	}
	
	if (count($cffs_error->errors) > 0) {
		echo "What is this? ".count($cffs_error->errors);
		return false;
	}
	return $data;
	
}

/**
 * process uploaded images, add to the media library and prep for 
 * cf-featured-image integration
**/
function cffs_process_image($image) {
	global $cffs_error;
	// @todo failier handling needs to be implemented.
	$uploaddir = wp_upload_dir();
	
	$post_date = date('Y-m-d 00:00:00');
	$postdata = array(
		'post_status' => 'publish',
		'post_type' => 'post',
		'post_content' => '',
		'post_title' => $image['name'],
		'post_date' => $post_date,
		'post_date_gmt' => get_gmt_from_date($post_date),
		'guid' => $uploaddir['url'].'/'.$image['name'],
		'post_mime_type' => $image['type'],			
	);
	
	$filename = $uploaddir['path'].'/'.$image['name'];
	
	$attachment_id = cffs_save_image($image['tmp_name'], $filename, $postdata);
	
	if (!$attachment_id) {
		$cffs_error->add('image-not-saved', 'Unfortunately your image, '.$image['name'].', could not be saved.');
	}
	else {
		// return the attachement id so that it can be used by cffp.
		return $attachment_id;
	}
}

/**
 * Move the image to the uploads folder, insert the attachments and attachment
 * meta
 * @todo error handling.
**/
function cffs_save_image($tmpname, $filename, $postdata) {
	global $cffs_error;
	// If the file is successfully moved, add it and it's meta data
	if(move_uploaded_file($tmpname, $filename)){
		
		$attachment_id = wp_insert_attachment($postdata, $filename, 0);	
		$attachment_data = wp_generate_attachment_metadata($attachment_id, $filename);
		if (wp_update_attachment_metadata($attachment_id, $attachment_data)) {
			return $attachment_id;
		}
		else {
			$cffs_error->add('media-not-inserted', 'We were unable to add your image to the media Library');
		}
	}
	else {
		$cffs_error->add('image-not-moved', 'We were unable to move your image to the upload directory');
	}
}

function cffs_save_data($postdata) {

	global $cffs_config, $current_user, $cffs_error;
	if (function_exists('kses_init_filters')) {
		kses_init_filters();
		
	}
	
	$post_id = wp_insert_post($postdata['postdata']);
	if (!$post_id) {
		$cffs_error->add("post-not-saved","An unknown error prevented your Submission, please try again.");
	}
	else {
		
		if (isset($postdata['user_meta']) && is_array($postdata['user_meta'])) {
			foreach ($postdata['user_meta'] as $key => $value) {
				if (!update_usermeta($current_user->ID, $key, $value)) {
					wp_die("Unable to save $key");
				}
			}
		}
		if (isset($postdata['post_meta']) && is_array($postdata['post_meta'])) {
			foreach ($postdata['post_meta'] as $key => $value) {
				if (!add_post_meta($post_id,$key,$value)) {
					wp_die("Unable to save $key");
				}
			}
		}
		// // if the insert worked, we need to add the post_id so that cffp works
		// $_REQUEST['post_ID'] = $post_id;
		// // @todo we need to remove this and make a remove/add action function 
		// // to replace it
		// if (function_exists("cffp_request_handler")) {
		// 	cffp_request_handler();
		// }
	}
	if (count($cffs_error->errors) == 0) {
		$page_url = get_option('siteurl');
		$page_url = apply_filters('cf_form_submit_redirect',$page_url);
		
		wp_redirect($page_url);
		die();
	}
	
	
}

function cffs_error_css_class($name,$error) {
	
	if (in_array($name,$error->get_error_codes())) {
		$class = ' class="error"';
		return $class;
	}

	$class = '';
	return $class;
}


function cffs_form_element_value($name) {
	if (isset($_POST[$name]) && !empty($_POST[$name])) {
		return attribute_escape($_POST[$name]);
	}
	else {
		return null;
	}
}

/**
 * @todo make this one specific, with a more generic one for future use
 * remove the $key and hard code the value for this one.
**/
function cffs_user_img_tag($user_id, $size = 'thumbnail', $user_meta) {

	$attachment_id = get_user_option($user_meta, $user_id);

	$cffs_image = wp_get_attachment_image_src($attachment_id, $size);
	
	if ($cffs_image[0] != '') {
		return '<img src="'.$cffs_image[0].'" alt="Logo for: '.$user_id.'" />';
	}

	return '';
}


//a:21:{s:11:"plugin_name";s:14:"cf-form-submit";s:10:"plugin_uri";s:24:"http://crowdfavorite.com";s:18:"plugin_description";s:69:"Allows the processing of forms, utilizing such things as cf_post_meta";s:14:"plugin_version";s:3:"0.5";s:6:"prefix";s:4:"cffs";s:12:"localization";N;s:14:"settings_title";N;s:13:"settings_link";N;s:4:"init";s:1:"1";s:7:"install";b:0;s:9:"post_edit";b:0;s:12:"comment_edit";b:0;s:6:"jquery";b:0;s:6:"wp_css";b:0;s:5:"wp_js";b:0;s:9:"admin_css";b:0;s:8:"admin_js";b:0;s:15:"request_handler";s:1:"1";s:6:"snoopy";b:0;s:11:"setting_cat";s:1:"1";s:14:"setting_author";s:1:"1";}

?>