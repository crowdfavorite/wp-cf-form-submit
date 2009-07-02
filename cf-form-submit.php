<?php
/*
Plugin Name: CF Form Submit 
Plugin URI: http://crowdfavorite.com 
Description: Allows the processing of forms, utilizing such things as cf_post_meta 
Version: 0.7 
Author: Crowd Favorite
Author URI: http://crowdfavorite.com
*/

// ini_set('display_errors', '1'); ini_set('error_reporting', E_ALL);

require_once(ABSPATH . 'wp-admin/includes/admin.php');
require_once(ABSPATH . 'wp-includes/pluggable.php');
if (!defined('PLUGINDIR')) {
	define('PLUGINDIR','wp-content/plugins');
}

$cffs_error = new WP_Error;

function cffs_admin_head() {
	global $wp_version;
	$cat_slug = cffs_cat_id_to_slug(CF_FORM_CATEGORY_ID);

	if (isset($wp_version) && version_compare($wp_version, '2.7', '>=')) {
		print('
<script type="text/javascript">
jQuery(function($) {
	$("#menu-posts .wp-submenu ul").append("<li><a tabindex=\"1\" href=\"edit.php?post_status=pending&category_name='.$cat_slug.'\">Pending Review</a></li>");
});
jQuery(function($) {
	$("form#your-profile").attr("enctype","multipart/form-data");
});
</script>
		');
	}
	if(is_admin() && !current_user_can('publish_posts')) {
		print('
<script type="text/javascript">
jQuery(function($) {
	$("ul#adminmenu").after("<ul></ul>").remove();
});
</script>
		');
	}
	
	
}

if (is_admin()) {
	wp_enqueue_script('jquery');
}
add_action('admin_head', 'cffs_admin_head');

function cffs_init() {
	if (!defined('CF_FORM_CATEGORY_ID')) {
		echo '
<div class="error">
	<h3>A required setting is missing:</h3>
	<p>The Required Constant "FORM_CATEGORY_ID" is not defined.  It may be in a plugin that has not been activated.  Please activate the plugin or define the constant and try again.</p>
</div>
		';
	}
}
add_action('init','cffs_init');

function cffs_request_handler() {
	global $cffs_config;
	$cffs_config = apply_filters('cffs_add_config', $cffs_config);

	if (!empty($_POST['cf_action'])) {
		switch ($_POST['cf_action']) {
			case 'cffs_submit':
				// validate the data
				if ($valid_data = cffs_validate_data()) {
					cffs_save_data($valid_data);
					echo "<h3>We have reached the Save function successfully</h3>";
				}
				break;
		}
	}
}
add_action('init', 'cffs_request_handler', 11);

// activate the post meta plugin for use outside of the admin interface
function cffs_run_post_meta($val) {
	if (isset($_POST['cf_action']) && $_POST['cf_action'] == 'cffs_submit') {
		
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
	$data = array();
	$data = apply_filters('cffs_filter_postdata',$data);
			
	foreach ($cffs_config['required'] as $postkey => $postvalue) {
		if ((!isset($_POST[$postkey]) || empty($_POST[$postkey])) && (!isset($_FILES[$postkey]) || empty($_FILES[$postkey]['name']))) {
			$cffs_error->add($postkey,$postvalue);
		}
	}
	
	foreach ($cffs_allowed_postdata as $key) {
		if (isset($_POST[$key]) && !empty($_POST[$key])) {
			$data['postdata'][$key] = stripslashes($_POST[$key]);
		}
	}
	if (isset($cffs_config['post_meta']) && is_array($cffs_config['post_meta'])) {
				
		$i = 0;
		foreach ($cffs_config['post_meta'] as $post_meta) {
			
			if (isset($_POST[$post_meta]) && !empty($_POST[$post_meta])) {
				$data['post_meta'][$post_meta] = stripslashes(attribute_escape($_POST[$post_meta]));
			}
			elseif (isset($_POST['blocks'][$post_meta])) {			
				foreach ($cffs_config[$post_meta] as $children_name => $children_value) {
					$data['post_meta'][$post_meta][$i][$children_name] = stripslashes(attribute_escape($_POST['post_meta'][$post_meta][$i][$children_name]));
					
				}
			}
			elseif (isset($_FILES[$post_meta]) && $_FILES[$post_meta]['size'] > 0 && $_FILES[$post_meta]['temp_name'] != 'none') {
				$post_meta_image_id = cffs_process_image($_FILES[$post_meta]);
				$data['post_meta'][$post_meta] = $post_meta_image_id;
			}
		}
	}
	if (isset($cffs_config['user_meta']) && is_array($cffs_config['user_meta'])) {
		foreach ($cffs_config['user_meta'] as $user_meta) {
			if (isset($_POST[$user_meta]) && !empty($_POST[$user_meta])) {
				$data['user_meta'][$user_meta] = stripslashes(attribute_escape($_POST[$user_meta]));
			}
			elseif (isset($_FILES[$user_meta]) && $_FILES[$user_meta]['size'] > 0 && $_FILES[$user_meta]['temp_name'] != 'none') {
				$user_meta_image_id = cffs_process_image($_FILES[$user_meta]);
				$data['user_meta'][$user_meta] = $user_meta_image_id;
			}
		}
	}
	if (count($cffs_error->errors) > 0) {
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
	$file = str_replace(' ', '-', $image['name']);
	$post_date = date('Y-m-d 00:00:00');
	$postdata = array(
		'post_status' => 'publish',
		'post_type' => 'post',
		'post_content' => '',
		'post_title' => $image['name'],
		'post_date' => $post_date,
		'post_date_gmt' => get_gmt_from_date($post_date),
		'guid' => $uploaddir['url'].'/'.$file,
		'post_mime_type' => $image['type'],			
	);
	
	$filename = $uploaddir['path'].'/'.$file;
	
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
				update_usermeta($current_user->ID, $key, $value);
			}
		}
		if (isset($postdata['post_meta']) && is_array($postdata['post_meta'])) {
			foreach ($postdata['post_meta'] as $key => $value) {
				if (!add_post_meta($post_id,$key,$value)) {
					$cffs_error->add($key.'-not-saved',"Unable to save $key");
				}
			}
		}

	}
	if (count($cffs_error->errors) == 0) {
		$notify_me = get_option('admin_email');
		$notify_me = apply_filters('cffs_set_notify_email',$notify_me);
		$type = apply_filters('cffs_set_submission_type','post');
		$blogname = get_option('blogname');
		$subject = '['.$blogname.'] A new '.$type.' needs your review';
		$message = 'A new '.$type.' titled "'.$postdata['postdata']['post_title'].'" was just submitted to '.$blogname.' by '.$current_user->user_nicename."\r\n\r\n";
		$message .= 'To review this '.$type.' click here: '.admin_url('post.php?action=edit&post='.$post_id)."\r\n";
		$message .= 'To review '.$current_user->user_nicename.'\'s profile click here: '.admin_url('user-edit.php?user_id='.$current_user->ID);
		wp_mail($notify_me, $subject, $message);
		
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
		return stripslashes(htmlspecialchars($_POST[$name],ENT_QUOTES));
	}
	elseif (isset($_FILES[$name]) && is_array($_FILES[$name])) {
		return $_FILES[$name];
	}
	else {
		return null;
	}
}

/**
 * @description asembles a link tag for an image stored as an attachement of a
 * post or page
**/
function cffs_user_img_tag($user_id, $size = 'thumbnail', $user_meta) {

	$attachment_id = get_user_option($user_meta, $user_id);

	$cffs_image = wp_get_attachment_image_src($attachment_id, $size);
	
	if ($cffs_image[0] != '') {
		return '<img src="'.$cffs_image[0].'" alt="Logo for: '.$user_id.'" />';
	}

	return '';
}

/**
 * takes a cat id and converts it to the appropriate slug. 
**/
function cffs_cat_id_to_slug($id) {
	$cat = &get_category($id);
	return $cat->slug;
}

/**
 * add the user meta meta box
**/
function cffs_add_user_metabox() {
	global $post, $cffs_config;
	$user_id = get_post_meta($post->ID, '_showcase_user_id', TRUE);
	$user_metabox = '<table class="form-table">';
	
	foreach ($cffs_config['user_meta'] as $key) { 
		$type = cffs_decide_input_tupe($key);
		$key_label = cffs_make_label($key);
		$metavalue = get_usermeta($user_id, $key);
		$user_metabox .= '
	<tr>
		<th>'.$key_label.'</th>';
		
		switch ($type) {
			
			case 'image':
				$user_metabox .= '
		<td>'.cffs_user_img_tag($user_id, 'logo', $key).'</td>';
		
				break;
			case 'link':
				$user_metabox .= '
		<td><a href="'.$metavalue.'">'.$metavalue.'</a></td>';
				break;
				
			default:
				$user_metabox .= '
		<td>'.$metavalue.'</td>';
		
				break;
 		}
		$user_metabox .= '
	</tr>';
	}
	$user_metabox .= '</table><p>Click <a href="user-edit.php?user_id='.$user_id.'">here</a> to edit this information</p>';
	echo $user_metabox;
}
add_meta_box('usermetadiv', __('User Meta Data'), 'cffs_add_user_metabox', 'post', 'advanced', 'high');

/**
 * Add fields for the user meta to be edited on the profile page
**/
function cffs_user_form() {
	global $cffs_config, $profileuser;
	$user_meta_form = '<table class="form-table">';

	foreach ($cffs_config['user_meta'] as $metakey):
		$type = cffs_decide_input_tupe($metakey);
		$value = get_usermeta($profileuser->ID, $metakey);
		$user_meta_form .= '
	<tr>
		<th>
			<label for="'.$metakey.'">'.cffs_make_label($metakey).'</label>
		</th>';

		switch ($type):
			case 'image':
				$user_meta_form .= '
		<td>
			<p>'.cffs_user_img_tag($profileuser->ID, 'logo', $metakey).'</p>
			<input type="file" name="'.$metakey.'" value="" id="'.$metakey.'">
		</td>';
				break;
			case 'link':
			case 'text':
				$user_meta_form .= '
		<td><input type="text" name="'.$metakey.'" value="'.$value.'" id="'.$metakey.'"></td>';
				break;
			case 'textarea':
				$user_meta_form .= '
		<td>
			<textarea name="'.$metakey.'">'.$value.'</textarea>
		</td>';
				break;
		endswitch;
		$user_meta_form .= '
	</tr>';
	
	endforeach;
	$user_meta_form .= '</table>';
	echo $user_meta_form;
}
if (current_user_can('edit_pages')) {
	add_action('show_user_profile', 'cffs_user_form');
	add_action('edit_user_profile', 'cffs_user_form');
}


/**
 * process user meta submited from the profile page
**/
function cffs_update_user_meta($user_id, $unused = null) {
	global $cffs_config;
	foreach ($cffs_config['user_meta'] as $metakey) {
		$type = cffs_decide_input_tupe($metakey);
		if ($type == 'image') {
			$_POST[$metakey] = cffs_process_image($_FILES[$metakey]);
		}
		if (isset($_POST[$metakey])) {
			$data = trim(stripslashes($_POST[$metakey]));
			update_usermeta($user_id, $metakey, $data);
		}
	}
}
add_filter('user_register','cffs_update_user_meta');
add_filter('profile_update', 'cffs_update_user_meta');

/**
 * Decide what type of input should be used
 * 
 * @param string $name - the name of the meta field
 * @return string - the type of input field that should be used.
**/
function cffs_decide_input_tupe($name) {
	$name_array = explode('_', $name);
	$type_indecator = $name_array[count($name_array)-1];
	switch ($type_indecator) {
		case 'bio':
			$type = 'textarea';
			break;
		case 'logo':
			$type = 'image';
			break;
		case 'url':
			$type = 'link';
			break;
		default:
			$type = 'text';
			break;
	}
	
	return $type;
}

/**
 * Strips off the prefix for this plugin, replaces _ with a space and caps the
 * first letter
 * 
 * @param string $text - the name of the key that needs to be converted to a label
 * @return string $label - the lable
**/
function cffs_make_label($text) {
	$label = ucwords(str_replace('_',' ',str_replace('_cffs_', '', $text)));
	return $label;
}

//a:21:{s:11:"plugin_name";s:14:"cf-form-submit";s:10:"plugin_uri";s:24:"http://crowdfavorite.com";s:18:"plugin_description";s:69:"Allows the processing of forms, utilizing such things as cf_post_meta";s:14:"plugin_version";s:3:"0.5";s:6:"prefix";s:4:"cffs";s:12:"localization";N;s:14:"settings_title";N;s:13:"settings_link";N;s:4:"init";s:1:"1";s:7:"install";b:0;s:9:"post_edit";b:0;s:12:"comment_edit";b:0;s:6:"jquery";b:0;s:6:"wp_css";b:0;s:5:"wp_js";b:0;s:9:"admin_css";b:0;s:8:"admin_js";b:0;s:15:"request_handler";s:1:"1";s:6:"snoopy";b:0;s:11:"setting_cat";s:1:"1";s:14:"setting_author";s:1:"1";}

?>