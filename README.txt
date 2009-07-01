# CF Form Submit Plugin

This plugin allows wordpress to process forms for creating posts and pages with out exposing the admin interface


## Currently Supports
- creating pages or posts
- inserting attachments and associating them with posts/pages 
- inserting custom user and post meta data

## Basic Usage
You will need to set at least one config array, and define a number of constants as described below:

### Configuration array
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

### Constants
in addition to the config array, you will need to create a default submitter user, a submission form page, a thank you landing page and a category for the pages/posts being submited, then define constants to hold the ids for these items.

** Example **
	
	/**
	 * Define constants to hold certain important information:
	**/
	define('CF_SUBMITTER_ID',2); 		// user_ID of the default submitter user
	define('CF_SUBMISSION_PAGE_ID', 3); // page_ID of the submission form page
	define('CF_FORM_CATEGORY_ID', 3); 	// cat_ID of the category holding the pages/posts being produced
	define('CF_THANK_YOU_PAGE_ID', 5); 	// page_ID for the landing page

