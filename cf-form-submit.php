<?php
/*
Plugin Name: CF Form Submit 
Plugin URI: http://crowdfavorite.com 
Description: Allows the processing of forms, utilizing such things as cf_post_meta 
Version: 1.4
Author: Crowd Favorite
Author URI: http://crowdfavorite.com
*/

// ini_set('display_errors', '1'); ini_set('error_reporting', E_ALL);

require_once(ABSPATH . 'wp-includes/pluggable.php');
require_once(ABSPATH . 'wp-admin/includes/admin.php');

if (!defined('PLUGINDIR')) {
	define('PLUGINDIR','wp-content/plugins');
}

$cffs_error = new WP_Error;
$valid_data;

function cffs_admin_head() {
	global $wp_version;
	$cffs_config = cffs_get_config();
	$pagelink_set = FALSE;
	$postlink_set = FALSE;
	$javascript_head = '
<script type="text/javascript">';
	if (count($cffs_config)) {
		foreach ($cffs_config as $key => $value) {
			if ($value['type'] == 'page' && !$pagelink_set) {
				$parent_slug = $value['parent_id'];
				$editpage = 'edit-pages.php';
				$parentstring = 'post_parent';
				$label_name = ucwords(str_ireplace('_', ' ', $key));
				$javascript_head .= '
			jQuery(function($) {
				$("#menu-'.$value['type'].'s .wp-submenu ul").append("<li><a tabindex=\"1\" href=\"'.$editpage.'?post_status=pending&'.$parentstring.'='.$parent_slug.'\">Pending Review</a></li>");
			});
				';
				$pagelink_set = TRUE;
			}	
			else if ($value['type'] == 'post' && !$postlink_set) {
				$parent_slug = cffs_cat_id_to_slug($value['parent_id']);
				$editpage = 'edit.php';
				$parentstring = 'category_name';
				$javascript_head .= '
			jQuery(function($) {
				$("#menu-'.$value['type'].'s .wp-submenu ul").append("<li><a tabindex=\"1\" href=\"'.$editpage.'?post_status=pending&'.$parentstring.'='.$parent_slug.'\">'.$label_name.' Pending Review</a></li>");
			});
				';
				$postlink_set = TRUE;
			}
		}
	}
	$javascript_head .= '
	jQuery(function($) {
	$("form#your-profile").attr("enctype","multipart/form-data");
	});
	';
	
	if(is_admin() && !current_user_can('publish_posts')) {
		$javascript_head .= '
	jQuery(function($) {
		$("ul#adminmenu").after("<ul></ul>").remove();
	});
		';
	}
	$javascript_head .= '
</script>
	';
	
	if (isset($wp_version) && version_compare($wp_version, '2.7', '>=')) {
		print($javascript_head);
	}
	
}

if (is_admin()) {
	wp_enqueue_script('jquery');
}
add_action('admin_head', 'cffs_admin_head');

$cffs_page_to_edit = null;

function cffs_init() {
	global $cffs_page_to_edit;
	if (!empty($_REQUEST['cffs_page_to_edit'])) {
		$cffs_page_to_edit = $_REQUEST['cffs_page_to_edit'];
	}
	elseif (!empty($_POST['ID'])) {
		$cffs_page_to_edit = $_POST['ID'];
	}
	# we need to reimplement required setting/plugins checking
	// if (!defined('CF_FORM_CATEGORY_ID')) {
	// 		echo '
	// <div class="error">
	// 	<h3>A required setting is missing:</h3>
	// 	<p>The Required Constant "FORM_CATEGORY_ID" is not defined.  It may be in a plugin that has not been activated.  Please activate the plugin or define the constant and try again.</p>
	// </div>
	// 		';
	// 	}
}
add_action('init','cffs_init');

function cffs_set_config() {
	global $cffs_config;
	$cffs_config = apply_filters('cffs_add_config', array());
}
add_action('init', 'cffs_set_config', 1);

function cffs_get_config() {
	global $cffs_config;
	return $cffs_config;
}

function cffs_request_handler() {
	global $valid_data;

	if (!empty($_POST['cf_action']) && !empty($_POST['cffs_form_name'])) {
		switch ($_POST['cf_action']) {
			case 'cffs_submit':
				// validate the data
				if ($valid_data = cffs_validate_data()) {
					cffs_save_data($valid_data);
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
	else {
		return $val;
	}
}
add_filter('cf_meta_actions', 'cffs_run_post_meta', 10);

/**
 * Validates data and asigns it to the data array
 * 
 * @return array $data contains arrays of postdata, post_meta and user_meta
**/
function cffs_validate_data() {
	global $cffs_error;
	$cffs_config = cffs_get_config();
	/* Don't believe this is used anywhere
	$cffs_allowed_postdata = apply_filters(
		'cffs_get_postdata_fields',
		array(
			'post_title',
			'post_content'
		)
	);
	*/
	$data = apply_filters('cffs_filter_postdata', array());

// TODO - error handling if POST doesn't have expected data
	if (!isset($cffs_config[$_POST['cffs_form_name']])) {
		return false;
	}
	
	$items = $cffs_config[$_POST['cffs_form_name']]['items'];
	foreach ($items as $item) {
		// See if our field is a required one
		if (isset($item['required']) && !empty($item['required'])) {
			if (!is_array($item['required'])) {
				// Check out what kind of required is it?
				switch ($item['required']) {
					case 'present':
						// simply tests that a value was entered.
						$r = (
							(
								isset($_POST[$item['name']]) // we have the item as a regular input
								&& !empty($_POST[$item['name']]) // it's not empty too
							) 
							|| 
							(
								isset($_FILES[$item['name']]) // we have a file
								&& !empty($_FILES[$item['name']]['name']) // file name is not empty
							)
						);
						break;
					case 'is_category': 
						// check if it's a category
						$term = term_exists($data[$field_name], 'category');
						$r = !empty($term);
					case 'is_tag': 
						// check if it's a tag
						$term = term_exists($data[$field_name], 'post_tag');
						$r = !empty($term);
					case 'filter': // fall through
					default:
						// Run filter to return bool against current field and $_POST value
						$r = apply_filters('cffs_validate_required_field', true, $item['name'], $item);
						break;
				}
				
				// Add an error if we have an invalid item
				if (!$r) {
					$cffs_error->add($item['name'], $item['error_message']);
				}
			}
			else {
				$validation_data = $_POST;
				if (isset($item['required']['args']) && is_array($item['required']['args'])) {
					$validation_data['args'] = $item['required']['args'];
				}
				// $error code not yet implemented, but in future will be.
				list($result,$error_code,$error_message) = $item['required']['callback']($validation_data);
				if ($result === FALSE) {
					$cffs_error->add($item['name'],$error_message);
				}
			}			
		}
		if (isset($_POST[$item['name']]) && !empty($_POST[$item['name']])) {
			$data[$item['type']][$item['name']] = stripslashes(attribute_escape($_POST[$item['name']]));
		}
		elseif ($item['data_type'] == 'block' && isset($_POST['blocks'][$item['name']])) {			
			$i = 0;
			foreach ($_POST['blocks'][$item['name']] as $value) {
				if ($item['type'] == 'attachment') {
					if (isset($_FILES) && !empty($_FILES)) {
						$att_data = array(
							'name'		=> $_FILES['blocks']['name'][$item['name']][$i]['_media_file'],
							'type'		=> $_FILES['blocks']['type'][$item['name']][$i]['_media_file'],
							'tmp_name'	=> $_FILES['blocks']['tmp_name'][$item['name']][$i]['_media_file'],
							'size'		=> $_FILES['blocks']['size'][$item['name']][$i]['_media_file'],
							'error'		=> $_FILES['blocks']['error'][$item['name']][$i]['_media_file'],
						);
						if (isset($att_data['name']) && !empty($att_data['name']) && $att_data['tmp_name'] != 'none') {
							$attachment_id = cffs_process_image($att_data,$value);
							$data['attachmentdata'][] = array('ID'=>$attachment_id);
						}
						elseif(!empty($value['ID'])) {
							$data['attachmentdata'][] = $value;
						}
						elseif(!empty($value['post_title'])) {
							$cffs_error->add('upload-failed', 'The file you titled '.$value['post_title'].' failed to upload, most likely because the file type is not allowed. Allowed file types are: '.get_site_option('upload_filetypes'));
						}
					}
				}
				
				// keep items where all values are empty from being saved
				$control = '';
				foreach($value as $value_item) { $control .= $value_item; }

				if(strlen(trim($control)) > 0) { 
					$data[$item['type']][$item['name']][] = $value;
				}
			
				$i ++;
			}
		}
		elseif (isset($_FILES[$item['name']]) && $_FILES[$item['name']]['size'] > 0 && $_FILES[$item['name']]['temp_name'] != 'none') {
			$image_id = cffs_process_image($_FILES[$item['name']]);
			$data[$item['type']][$item['name']] = $image_id;
		}
	}
	
	// If we have errors return false
	if (count($cffs_error->errors) > 0) {
		return false;
	}

	$data = apply_filters('cffs_after_validate_data', $data, $cffs_config, $items);
	return $data;
}

/**
 * process uploaded images, add to the media library and prep for 
 * cf-featured-image integration
**/
function cffs_process_image($image, $data = NULL) {
	global $cffs_error;
	// @todo failier handling needs to be implemented.
	$uploaddir = wp_upload_dir();
	if (!empty($uploaddir['error'])) {
		$cffs_error->add('image-not-saved', $uploaddir['error']);
		return FALSE;
	}
	$is_allowed_filetype = wp_check_filetype($image['name']);
	$max_allowed_filesize = get_site_option('fileupload_maxk');
	if ($is_allowed_filetype['ext'] == FALSE && $is_allowed_filetype['type'] == FALSE) {
		$cffs_error->add('filetype-not-allowed', 'The file '.$image['name'].' is a '. $image['type'].' file, which is not allowed.  Allowed filetypes are: '.get_site_option('upload_filetypes'));
		return FALSE;
	}
	$size = (int) ($image['size'] / 1024);
	echo '<h1>size</h1><pre>'.print_r($size,TRUE).'</pre>';
	echo '<h1>max_allowed_filesize</h1><pre>'.print_r($max_allowed_filesize,TRUE).'</pre>';
	
	if ($size > $max_allowed_filesize) {
		$cffs_error->add('file-exceeds-max-filesize', 'The file you uploaded is '.($size).' kb, which exceeds the max file-size of '.$max_allowed_filesize.' kb');
		return FALSE;
	}
	$file = str_replace(' ', '-', $image['name']);
	$post_date = date('Y-m-d 00:00:00');
	$postdata = array(
		'post_status' => 'publish',
		'post_content' => '',
		'post_title' => $image['name'],
		'post_date' => $post_date,
		'post_date_gmt' => get_gmt_from_date($post_date),
		'guid' => $uploaddir['url'].'/'.$file,
		'post_mime_type' => $image['type'],			
	);
	if (!is_null($data)) {
		$postdata = array_merge($postdata, $data);
	}
	$filename = $uploaddir['path'].'/'.$file;
	$attachment_id = cffs_save_image($image['tmp_name'], $filename, $postdata);
	if (!$attachment_id) {
		$cffs_error->add('image-not-saved', 'Unfortunately your image, could not be saved.');
	}
	else {
		// return the attachment id so that it can be used by cffp.
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
	// If the file is successfully moved, add it and its meta data

	if (strpos($filename, trim(ABSPATH, '/')) !== false && @move_uploaded_file($tmpname, $filename)) {
		
		$attachment_id = wp_insert_attachment($postdata, $filename, 0);	
		$attachment_data = wp_generate_attachment_metadata($attachment_id, $filename);
		if (wp_update_attachment_metadata($attachment_id, $attachment_data)) {
			return $attachment_id;	
		}
	}
	return false;
}

function cffs_save_data($postdata) {
	global $current_user, $cffs_error, $cffs_page_to_edit;
	
	if (function_exists('kses_init_filters')) {
		kses_init_filters();
	}
	if (empty($cffs_page_to_edit)) {
		$post_id = wp_insert_post($postdata['postdata']);
		$insert_v_update = 'insert';
	}
	else {
		$postdata['postdata']['ID'] = $cffs_page_to_edit;
		$post_id = wp_update_post($postdata['postdata']);
		$insert_v_update = 'update';
	}
	if (!$post_id) {
		$cffs_error->add("post-not-saved","An unknown error prevented your Submission, please try again.");
	}
	else {
		if (isset($postdata['user_meta']) && is_array($postdata['user_meta']) && count($postdata['user_meta'])) {
			foreach ($postdata['user_meta'] as $key => $value) {
				update_usermeta($current_user->ID, wp_filter_nohtml_kses($key), wp_filter_post_kses($value));
			}
		}
		// get a list of current attachments to compare to what there should be...
		$previous_attachements = get_posts(array('post_type'=>'attachment', 'post_status'=>'inherit', 'post_parent'=>$post_id, 'posts_per_page'=>-1));
		if ((isset($postdata['attachmentdata']) && is_array($postdata['attachmentdata']) && count($postdata['attachmentdata'])) || count($previous_attachements)) {
			foreach ($postdata['attachmentdata'] as $attachment) {
				$current_attachments[] = $attachment['ID'];
				wp_insert_attachment($attachment, FALSE, $post_id);
			}
			foreach ($previous_attachements as $attachment) {
				// remove any that weren't saved/updated just now.
				if (!in_array($attachment->ID, $current_attachments)) {
					wp_delete_attachment($attachment->ID);
				}
			}
		}
	}
	if (count($cffs_error->errors) == 0) {
		$notify_me = apply_filters('cffs_set_notify_email', get_option('admin_email'));
		$type = apply_filters('cffs_set_submission_type','post');
		$blogname = get_option('blogname');
		if ($insert_v_update = 'insert') {
			$subject = '['.$blogname.'] '.__('A new').' '.$type.' '.__('needs your review');
			$message = __('A new').' '.$type.' '.__('titled').' "'.$postdata['postdata']['post_title'].'" '.__('was just submitted to').' '.$blogname.' '.__('by').' '.$current_user->user_nicename."\r\n\r\n";
		}
		else {
			$subject = '['.$blogname.'] '.__('An updated').' '.$type.' '.__('needs your review');
			$message = __('A').' '.$type.' '.__('titled').' "'.$postdata['postdata']['post_title'].'" '.__('was just updated to').' '.$blogname.' '.__('by').' '.$current_user->user_nicename."\r\n\r\n";
			
		}
		// Common footer to the email
		$message .= __('To review this').' '.$type.' '.__('click here').': '.admin_url('post.php?action=edit&post='.$post_id)."\r\n";
		$message .= __('To review').' '.$current_user->user_nicename.'\'s '.__('profile click here').': '.admin_url('user-edit.php?user_id='.$current_user->ID);
		
		// Add some filters to the subject and message
		$subject = apply_filters('cffs_filter_success_email_subject', $subject, compact('insert_v_update', 'blogname', 'type'));
		$message = apply_filters('cffs_filter_success_email_message', $message, compact('insert_v_update', 'blogname', 'type', 'postdata', 'current_user'));
		
		// Shoot off our email now!
		wp_mail($notify_me, $subject, $message);
		
		$page_url = apply_filters('cf_form_submit_redirect', get_option('siteurl'), $post_id);
		
		wp_redirect($page_url);
		die();
	}
}

function cffs_save_post_meta($post_ID, $data = ''){
	global $valid_data, $cffs_error;
	if (!empty($data)) {
		$valid_data = $data;
	}
	if (isset($valid_data['post_meta']) && is_array($valid_data['post_meta']) && count($valid_data['post_meta'])) {
		foreach ($valid_data['post_meta'] as $key => $value) {
			$clean_key = wp_filter_nohtml_kses($key);
			if (is_array($value)) {
				$value = cffs_kses_filter_array($value);
			}
			else {
				$value = wp_filter_post_kses($value);
			}
			// clear our existing value, so we can really get a true response next.
			update_post_meta($post_ID, $clean_key, ''); 
			if (!update_post_meta($post_ID, $clean_key, $value)) {
				$cffs_error->add($key.'-not-saved', "Unable to save $key");
			}
		}
	}
}
add_action('save_post', 'cffs_save_post_meta');

function cffs_kses_filter_array($data) {
	$sanitized = array();
	if (count($data)) {
		foreach ($data as $k => $v) {
			if (is_array($v)) {
				$v = cffs_kses_filter_array($v);
			}
			else {
				$v = wp_filter_post_kses($v);
			}
			$sanitized[wp_filter_nohtml_kses($k)] = $v;
		}
	}
	return $sanitized;
}

# form utility functions

/**
 * Placeholder function for site by site authentication implementation
 * defaults to false, ie. user can't edit.
 */
function cffs_user_can_edit($page_id, $user_id = null) {
	global $current_user;
	if (is_null($user_id)) {
		$user_id = $current_user->id;
	}
	return apply_filters('cffs_user_can_edit',FALSE, $page_id, $user_id);
}

function cffs_get_error_css_class($name,$error) {
	
	if (is_wp_error($error) && in_array($name,$error->get_error_codes())) {
		$error_class = apply_filters('cffs_error_css_class','error');
	}
	return $error_class;
}

/**
 * This function kept for compatibility purposes
 */
function cffs_error_css_class($name,$error) {
	$class = ' class="'.cffs_get_error_css_class($name,$error).'"';
	return $class;
}

/**
 * prepares a value for the for the form element to display and returns it.
 *
 * @param string $name - the name of the form elemement
 * @return string
 */
function cffs_form_element_value($name) {
	global $cffs_config, $cffs_page_to_edit;
	if (isset($_POST[$name]) && !empty($_POST[$name])) {
		return stripslashes(htmlspecialchars($_POST[$name],ENT_QUOTES));
	}
	else if (isset($_FILES[$name]) && is_array($_FILES[$name])) {
		return $_FILES[$name];
	}
	elseif (isset($cffs_page_to_edit) && !empty($cffs_page_to_edit)) {
		foreach ($cffs_config as $section => $values) {
			foreach($values['items'] as $item) {
				if ($item['name'] == $name) {
					switch ($item['type']) {
						case 'postdata':
							$value  = get_post_field($name, $cffs_page_to_edit);
							break;
						case 'post_meta':
							$value = get_post_meta($cffs_page_to_edit, $name, TRUE);
							break;
						default:
							$value = 'oops, it bwoke!';
					}
					$val = apply_filters('cffs_field_value', $value, compact('item', 'name'));
					return $val;
				}
			}
		}
	}
	else {
		return null;
	}
}
# end form utility functions

/**
 * @description asembles a link tag for an image stored as an attachment of a
 * post or page
**/
function cffs_user_img_tag($user_id, $size = 'thumbnail', $user_meta) {
	$attachment_id = get_user_option($user_meta, $user_id);
	$cffs_image = wp_get_attachment_image_src($attachment_id, $size);
	if ($cffs_image[0] != '') {
		return '<img src="'.$cffs_image[0].'" alt="Logo for: '.$user_id.'" />';
	}
	else {
		return '';
	}
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
	global $post;
	$cffs_config = cffs_get_config();
	$user_metabox = '';
	if (count($cffs_config)) {
		foreach ($cffs_config as $form_name => $form_info) {
			$user_id = get_post_meta($post->ID, '_showcase_user_id', TRUE);
			$user_metabox = '<h3>'.$form_name.'</h3>';
			$user_metabox .= '<table class="form-table">';
		
			foreach ($form_info['items'] as $item) { 
				if($item['type'] == 'user_meta') {
					$key_label = cffs_make_label($item['name']);
					$metavalue = get_usermeta($user_id, $item['name']);
					$user_metabox .= '
			<tr>
				<th>'.$key_label.'</th>';
			
					switch ($item['data_type']) {
				
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
			}
			$user_metabox .= '</table><p>Click <a href="user-edit.php?user_id='.$user_id.'">here</a> to edit this information</p>';
		}
	}
	echo $user_metabox;
}
add_meta_box('usermetadiv', __('User Meta Data'), 'cffs_add_user_metabox', 'post', 'advanced', 'high');

/**
 * Add fields for the user meta to be edited on the profile page
**/
function cffs_user_form() {
	global $profileuser;
	$cffs_config = cffs_get_config();
	if (count($cffs_config)) {
		foreach ($cffs_config as $form_name => $form_items)	{
			$user_meta_form = '<h3>'.ucwords(str_ireplace('_', ' ', $form_name)).'</h3>';
			$user_meta_form .= '<table class="form-table">';
		
			foreach ($form_items['items'] as $item):
				if ($item['type'] == 'user_meta'):
					$value = get_usermeta($profileuser->ID, $item['name']);
					$user_meta_form .= '
			<tr>
				<th>
					<label for="'.$item['name'].'">'.cffs_make_label($item['name']).'</label>
				</th>';
	
					switch ($item['data_type']):
						case 'image':
							$user_meta_form .= '
				<td>
					<p>'.cffs_user_img_tag($profileuser->ID, 'logo', $item['name']).'</p>
					<input type="file" name="'.$item['name'].'" value="" id="'.$item['name'].'">
				</td>';
							break;
						case 'link':
						case 'text':
							$user_meta_form .= '
				<td><input type="text" name="'.$item['name'].'" value="'.$value.'" id="'.$item['name'].'"></td>';
							break;
						case 'textarea':
							$user_meta_form .= '
				<td>
					<textarea name="'.$item['name'].'">'.$value.'</textarea>
				</td>';
							break;
					endswitch;
					$user_meta_form .= '
			</tr>';
				endif;
			endforeach;
			$user_meta_form .= '</table>';
		}
	}
	echo $user_meta_form;
}
if (current_user_can('edit_pages')) {
	add_action('show_user_profile', 'cffs_user_form');
	add_action('edit_user_profile', 'cffs_user_form');
}


/**
 * process user meta submited from the profile page
 * 
 * @param int $user_id the user id for the user to be updated
 * @param 
**/
function cffs_update_user_meta($user_id, $unused = null) {
	$cffs_config = cffs_get_config();
	if (count($cffs_config)) {
		foreach ($cffs_config as $form_name => $form_info) {
			foreach ($form_info['items'] as $item) {
				if ($item['type'] == 'user_meta'){
					if ($item['data_type'] == 'image') {
						$_POST[$item['name']] = cffs_process_image($_FILES[$item['name']]);
					}
					if (isset($_POST[$item['name']])) {
						$data = trim(stripslashes($_POST[$item['name']]));
						update_usermeta($user_id, $item['name'], $data);
					}
				}
			}
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
 * @return string $label - the label
**/
function cffs_make_label($text, $remove = null) {
	$label = ucwords(str_replace('_',' ',$text));
	$label = (!empty($remove))?str_ireplace($remove, '', $label):$label;
	return $label;
}

?>