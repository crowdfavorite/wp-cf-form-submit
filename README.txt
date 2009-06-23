# CF Form Submit Plugin

This plugin allows wordpress to process forms for creating posts and pages with out exposing the admin interface


## Currently Supports
- creating pages or posts
- inserting attachments and associating them with posts/pages 
- inserting custom user and post meta data

## Basic Usage
a configuration array is used to set which form fields are required:

** Example **

	/**
	 * Define what fields are allowed from a certain form
	 * 
	 * @param array $config
	 * @return array
	**/
	function my_add_fields($config) {
		$config = array(
			// Required fields.  Set as many as needed
			'required' => array(
				'post_title' => 'You must include a Post Title' // name => error message
			),
			// names of any post_meta fields.  If you use cf_post_meta plugin these should match up with the names of those post_meta items
			'post_meata' => array(
				'_meta_field_name' 
			),
			// names of any user_meta fields you want to include
			'user_meta' => array(
				'_meta_field_name'
			),
		);
	}
	

