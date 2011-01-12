<?php
// Adds usermeta defined in config file
function cffs_add_user_meta($data) {
	extract($data);
	
	if (isset($postdata['user_meta']) && is_array($postdata['user_meta']) && count($postdata['user_meta'])) {
		foreach ($postdata['user_meta'] as $key => $value) {
			update_usermeta(get_current_user_id(), wp_filter_nohtml_kses($key), wp_filter_post_kses($value));
		}
	}
	
}
add_action('cffs_post_inserted', 'cffs_add_user_meta');


// Save new attachments, prune old ones.
function cffs_handle_attachments($data) {
	extract($data);
	
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
add_action('cffs_post_inserted', 'cffs_handle_attachments');

function cffs_handle_image_uploads($data) {
	extract($data);
	$cffs_config = cffs_get_config();
	foreach ($cffs_config as $group_name => $config_group) {
		foreach ($config_group['items'] as $config_item) {
			// If our config item is an image, then attach it to the post
			if ($config_item['data_type'] == 'image') {
				extract($config_item);
				if (
					is_array($_FILES[$name]) // We have a file name
					&& !empty($_FILES[$name]['size']) // We have a file size
					) {
					/** WordPress Media Administration API */
					require_once(ABSPATH . 'wp-admin/includes/media.php');
					
					/** WordPress Administration File API */
					require_once(ABSPATH . 'wp-admin/includes/file.php');
					
					/** WordPress Image Administration API */
					require_once(ABSPATH . 'wp-admin/includes/image.php');

					$image_attachment_id = media_handle_upload($name, $post_id, array(
						'post_excerpt' => $_POST[$name.'_credit'],
					));

					if (is_wp_error($image_attachment_id)) {
						$cffs_error->add($image_attachment_id->get_error_code(), $image_attachment_id->get_error_message());
						return;
					}
				}

			}
		}
	}
}
add_action('cffs_post_inserted', 'cffs_handle_image_uploads');
?>