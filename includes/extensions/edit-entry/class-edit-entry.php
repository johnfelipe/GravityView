<?php
/**
 * The GravityView Edit Entry Extension
 *
 * Easily edit entries in GravityView.
 *
 * @package   GravityView-DataTables-Ext
 * @license   GPL2+
 * @author    Katz Web Services, Inc.
 * @link      http://gravityview.co
 * @copyright Copyright 2014, Katz Web Services, Inc.
 */

if ( ! defined( 'WPINC' ) ) {
	die;
}

class GravityView_Edit_Entry {

	static $file;
	static $nonce_key;
	static $instance;
	var $entry;
	var $form;
	var $view_id;
	var $is_valid = NULL;

	function __construct() {

		self::$instance = &$this;

		self::$file = plugin_dir_path( __FILE__ );

		// Stop Gravity Forms processing what is ours!
		add_filter( 'wp', array( $this, 'prevent_maybe_process_form'), 8 );

		add_filter( 'gravityview_is_edit_entry', array( $this, 'is_edit_entry') );

		add_action( 'gravityview_edit_entry', array( $this, 'init' ) );

		add_filter( 'gravityview_additional_fields', array( $this, 'add_available_field' ));

		add_filter( 'gravityview_entry_default_fields', array( $this, 'add_default_field'), 10, 3 );

		// For the Edit Entry Link, you don't want visible to all users.
		add_filter( 'gravityview_field_visibility_caps', array( $this, 'modify_visibility_caps'), 10, 5 );

		// Add fields expected by GFFormDisplay::validate()
		add_filter( 'gform_pre_validation', array( $this, 'gform_pre_validation') );

		// Modify the field options based on the name of the field type
		add_filter( 'gravityview_template_edit_link_options', array( $this, 'field_options' ), 10, 5 );

		// add template path to check for field
		add_filter( 'gravityview_template_paths', array( $this, 'add_template_path' ) );

		// Add front-end access to Gravity Forms delete file action
		add_action('wp_ajax_nopriv_rg_delete_file', array('RGForms', 'delete_file'));
	}

	static function getInstance() {

		if( !empty( self::$instance ) ) {
			return self::$instance;
		} else {
			self::$instance = new GravityView_Edit_Entry;
			return self::$instance;
		}
	}

	function setup_vars( $entry = null ) {
		global $gravityview_view;

		$this->entry = empty( $entry ) ? $gravityview_view->entries[0] : $entry;
		$this->form = $gravityview_view->form;
		$this->form_id = $gravityview_view->form_id;
		$this->view_id = $gravityview_view->view_id;

		self::$nonce_key = sprintf( 'edit_%d_%d_%d', $this->view_id, $this->form_id, $this->entry['id'] );
	}

	/**
	 * The edit entry link creates a secure link with a nonce
	 *
	 * It also mimics the URL structure Gravity Forms expects to have so that
	 * it formats the display of the edit form like it does in the backend, like
	 * "You can edit this post from the post page" fields, for example.
	 *
	 * @filter default text
	 * @action default text
	 * @param  [type]      $entry [description]
	 * @param  [type]      $field [description]
	 * @return [type]             [description]
	 */
	static function get_edit_link( $entry, $field ) {
		global $gravityview_view;

		self::getInstance()->setup_vars( $entry );

		$base = gv_entry_link( $entry, $field );

		$url = add_query_arg( array(
			'page' => 'gf_entries', // Needed for GFForms::get_page()
			'view' => 'entry', // Needed for GFForms::get_page()
			'edit' => wp_create_nonce( self::$nonce_key )
		), $base );

		return $url;
	}

	/**
	 * Include this extension templates path
	 * @param array $file_paths List of template paths ordered
	 */
	function add_template_path( $file_paths ) {

		// Index 100 is the default GravityView template path.
		$file_paths[ 110 ] = self::$file;

		return $file_paths;
	}

	/**
	 * Add "Edit Link Text" setting to the edit_link field settings
	 * @param  [type] $field_options [description]
	 * @param  [type] $template_id   [description]
	 * @param  [type] $field_id      [description]
	 * @param  [type] $context       [description]
	 * @param  [type] $input_type    [description]
	 * @return [type]                [description]
	 */
	function field_options( $field_options, $template_id, $field_id, $context, $input_type ) {

		// Always a link, never a filter
		unset( $field_options['show_as_link'], $field_options['search_filter'] );

		// Edit Entry link should only appear to visitors capable of editing entries
		unset( $field_options['only_loggedin'], $field_options['only_loggedin_cap'] );

		$add_option['edit_link'] = array(
			'type' => 'text',
			'label' => __( 'Edit Link Text', 'gravity-view' ),
			'desc' => NULL,
			'default' => __('Edit Entry', 'gravity-view'),
			'merge_tags' => true,
		);

		return array_merge( $add_option, $field_options );
	}

	/**
	 * Add Edit Link as a default field, outside those set in the Gravity Form form
	 * @param array $entry_default_fields Existing fields
	 * @param  string|array $form form_ID or form object
	 * @param  string $zone   Either 'single', 'directory', 'header', 'footer'
	 */
	function add_default_field( $entry_default_fields, $form = array(), $zone = '' ) {

		$entry_default_fields['edit_link'] = array(
			'label' => __('Edit Entry', 'gravity-view'),
			'type' => 'edit_link'
		);

		return $entry_default_fields;
	}

	/**
	 * Add Edit Entry Link to the Add Field dialog
	 * @param array $available_fields
	 */
	function add_available_field( $available_fields = array() ) {

		$available_fields['edit_link'] = array(
			'label_text' => __( 'Edit Entry', 'gravity-view' ),
			'field_id' => 'edit_link',
			'label_type' => 'field',
			'input_type' => 'edit_link',
			'field_options' => NULL
		);

		return $available_fields;
	}

	/**
	 * Only show edit link to logged-in users.
	 *
	 * @param  array 	   $caps        Array of capabilities to display in field dropdown.
	 * @param  string      $field_type  Type of field options to render (`field` or `widget`)
	 * @param  string      $template_id Table slug
	 * @param  float       $field_id    GF Field ID - Example: `3`, `5.2`, `entry_link`, `created_by`
	 * @param  string      $context     What context are we in? Example: `single` or `directory`
	 * @param  string      $input_type  (textarea, list, select, etc.)
	 * @return array                   Array of field options with `label`, `value`, `type`, `default` keys
	 */
	function modify_visibility_caps( $caps, $template_id = '', $field_id = '', $context = '', $input_type = '' ) {

		if( $field_id === 'edit_link' || $input_type === 'edit_link' ) {
			foreach ($caps as $key => $cap) {
				if( $cap['value'] === 'read' ) {
					unset($caps[$key]);
				}
			}
		}

		return $caps;
	}

	/**
	 * Force Gravity Forms to output scripts as if it were in the admin
	 * @return [type]      [description]
	 */
	function print_scripts( $css_only = false ) {
		global $gravityview_view;

		wp_enqueue_style('gravityview-edit-entry', plugins_url('/assets/css/gv-edit-entry-admin.css', __FILE__ ) );

		if( $css_only ) { return; }

		wp_register_script( 'gform_gravityforms', GFCommon::get_base_url().'/js/gravityforms.js', array( 'jquery', 'gform_json', 'gform_placeholder', 'sack','plupload-all' ) );

		GFFormDisplay::enqueue_form_scripts($gravityview_view->form, false);
		GFForms::print_scripts();
	}

	/**
	 * Load required files and trigger edit flow
	 *
	 * Run when the is_edit_entry returns true.
	 * @return void
	 */
	function init() {
		global $gravityview_view;

		require_once(GFCommon::get_base_path() . "/form_display.php");
		require_once(GFCommon::get_base_path() . "/entry_detail.php");
		require_once( self::$file . '/class-gv-gfcommon.php' );


		if( !class_exists( 'GFEntryDetail' )) {
			do_action( 'gravityview_log_error', 'GravityView_Edit_Entry[init] GFEntryDetail does not exist' );
		}

		$this->setup_vars();

		// Sorry, you're not allowed here.
		if( false === $this->user_can_edit_entry( true ) ) {
			$this->print_scripts( true );
			return;
		}

		$this->print_scripts();

		$this->process_save();

		// Override the output of the fields so we can re-process using our own class
		add_filter("gform_field_content", array( 'GravityView_Edit_Entry', 'gform_field_content' ), 10, 5 );

			$this->edit_entry_form();

		// Remove the filter so it doesn't mess with other plugins.
		remove_filter("gform_field_content", array( 'GravityView_Edit_Entry', 'gform_field_content' ), 10, 5 );

	}

	/**
	 * Output table rows with error messages and labels
	 * @param  [type]  $content [description]
	 * @param  [type]  $field   [description]
	 * @param  string  $value   [description]
	 * @param  integer $lead_id [description]
	 * @param  integer $form_id [description]
	 * @return [type]           [description]
	 */
	static function gform_field_content( $content, $field, $value = '', $lead_id = 0, $form_id = 0 ) {

		// There's something not right...
		if( empty( $field['id'] )) { return $content; }

		$td_id = "field_" . $form_id . "_" . $field['id'];

		$label = esc_html(GFCommon::get_label($field));

		$input = GV_GFCommon::get_field_input($field, $value, $lead_id, $form_id ) ;

		$error_class = rgget("failed_validation", $field) ? "gfield_error" : "";

		$validation_message = (rgget("failed_validation", $field) && !empty($field["validation_message"])) ? sprintf("<div class='gfield_description validation_message'>%s</div>", $field["validation_message"]) : "";

		if( rgar($field, "descriptionPlacement") == "above" ) {
			$input = $validation_message . $input;
		} else {
			$input = $input . $validation_message;
		}

		$content = "
		<tr valign='top'>
			<td class='detail-view {$error_class}' id='{$td_id}'>
				<label class='detail-label'>" . $label . "</label>" . $input . "
			</td>
		</tr>";

		return apply_filters( 'gravityview_edit_entry_field_content', $content, $field, $value, $lead_id, $form_id );
	}

	/**
	 * Because we're mimicking being a front-end Gravity Forms form while using a Gravity Forms
	 * backend form, we need to prevent them from saving twice.
	 * @return void
	 */
	function prevent_maybe_process_form() {
		global $post;

		if( !empty( $_POST['is_gv_edit_entry'] ) && wp_verify_nonce( $_POST['is_gv_edit_entry'], 'is_gv_edit_entry' ) ) {
			remove_action('wp',  array('RGForms', 'maybe_process_form'), 9);
		}
	}

	function process_save() {
		global $gravityview_view;
		 // If the form is submitted
		if(RGForms::post("action") === "update") {

	        // Make sure the entry, view, and form IDs are all correct
	        check_admin_referer( self::$nonce_key, self::$nonce_key );

	        $lead_id = absint( $_POST['lid'] );

	        //Loading files that have been uploaded to temp folder
	        $files = GFCommon::json_decode(stripslashes(RGForms::post("gform_uploaded_files")));
	        if(!is_array($files)) {
	            $files = array();
	        }

	        GFFormsModel::$uploaded_files[$this->form_id] = $files;


	        $this->validate();


	        if( $this->is_valid ) {

	        	do_action('gravityview_log_debug', 'GravityView_Edit_Entry[process_save] Submission is valid.' );

		        GFFormsModel::save_lead( $this->form, $this->entry );

		        do_action("gform_after_update_entry", $this->form, $this->entry["id"]);
		        do_action("gform_after_update_entry_{$this->form["id"]}", $this->form, $this->entry["id"]);

		        // Re-define the entry now that we've updated it.
		        $this->entry = RGFormsModel::get_lead( $this->entry["id"] );
				$this->entry = GFFormsModel::set_entry_meta( $this->entry, $this->form );

				// We need to clear the cache because Gravity Forms caches the field values, which
				// we have just updated.
				foreach ($this->form['fields'] as $key => $field) {
					GFFormsModel::refresh_lead_field_value( $this->entry['id'], $field['id'] );
				}
			}
		}
	}

	/**
	 * Add field keys that Gravity Forms expects.
	 *
	 * @param  array $form GF Form
	 * @return array       Modified GF Form
	 */
	function gform_pre_validation( $form ) {

		if( !self::verify_nonce() ) {
			return $form;
		}

		// Fix PHP warning regarding undefined index.
		foreach ($form['fields'] as &$field) {

			// This is because we're doing admin form pretending to be front-end
			foreach ( array( 'noDuplicates', 'adminOnly', 'inputType' ) as $key ) {
				$field[ $key ] = isset( $field[ $key ] ) ? $field[ $key ] : NULL;
			}

			// unset emailConfirmEnabled for email type fields
			if( 'email' === $field['type'] && !empty( $field['emailConfirmEnabled'] ) ) {
				$field['emailConfirmEnabled'] = '';
			}

		}

		return $form;
	}

	function validate() {
		/**
		 * For some crazy reason, Gravity Forms doesn't validate Edit Entry form submissions.
		 * You can enter whatever you want!
		 * We try validating, and customize the results using `self::custom_validation()`
		 */
		add_filter( 'gform_validation_'.$this->form_id, array( &$this, 'custom_validation'), 10, 4);

		// Needed by the validate funtion
		$failed_validation_page = NULL;
		$field_values = RGForms::post("gform_field_values");

		$this->is_valid = GFFormDisplay::validate( $this->form, $field_values, 1, $failed_validation_page );

		remove_filter( 'gform_validation_'.$this->form_id, array( &$this, 'custom_validation'), 10 );
	}

	/**
	 * Make validation work for Edit Entry
	 *
	 * Because we're calling the GFFormDisplay::validate() in an unusual way (as a front-end
	 * form pretending to be a back-end form), validate() doesn't know we _can't_ edit post
	 * fields. This goes through all the fields and if they're an invalid post field, we
	 * set them as valid. If there are still issues, we'll return false.
	 *
	 * @param  [type] $validation_results [description]
	 * @return [type]                     [description]
	 */
	function custom_validation( $validation_results ) {

		// We don't need to process if this is valid
		if( !empty( $validation_results['is_valid'] ) ) {
			return $validation_results;
		}

		$gv_valid = true;

		foreach ($validation_results['form']['fields'] as $key => &$field ) {
			if( !empty( $field['failed_validation'] ) ) {

				if( preg_match('/post_/ism', $field['type'] )) {
					$field['failed_validation'] = false;
					continue;
				}

				$gv_valid = false;
			}
		}

		$validation_results['is_valid'] = $gv_valid;

		return $validation_results;
	}

	/**
	 * Is the current page an Edit Entry page?
	 * @return boolean
	 */
	function is_edit_entry() {

		$gf_page = ( 'entry' === RGForms::get("view") );

		return ( $gf_page && isset( $_GET['edit'] ) || RGForms::post("action") === "update" );
	}

	/**
	 * Is the current nonce valid for editing the entry?
	 * @return boolean
	 */
	function verify_nonce() {

		// Verify form submitted for editing single
		if( !empty( $_POST['is_gv_edit_entry'] ) ) {
			return wp_verify_nonce( $_POST['is_gv_edit_entry'], 'is_gv_edit_entry' );
		}

		// Verify
		if( !self::is_edit_entry() ) { return false; }

		return wp_verify_nonce( $_GET['edit'], self::$nonce_key );

	}

	function user_can_edit_entry( $echo = false ) {

		$error = NULL;

		if( ! $this->verify_nonce() ) {
			$error = __( 'The link to edit this entry is not valid; it may have expired.', 'gravity-view');
		}

		if( ! self::check_user_cap_edit_entry( $this->entry ) ) {
			$error = __( 'You do not have permission to edit this entry.', 'gravity-view');
		}

		if( $this->entry['status'] === 'trash' ) {
			$error = __('You cannot edit the entry; it is in the trash.', 'gravity-view' );
		}

		// No errors; everything's fine here!
		if( empty( $error ) ) {
			return true;
		}

		if( $echo ) {
			echo $this->generate_notice( wpautop( esc_html( $error ) ), 'gv-error error');
		}

		do_action('gravityview_log_error', 'GravityView_Edit_Entry[user_can_edit_entry]' . $error );

		return false;
	}

	/**
	 * checks if user has permissions to edit a specific entry
	 *
	 * Needs to be used combined with GravityView_Edit_Entry::user_can_edit_entry for maximum security!!
	 *
	 * @param  [type] $entry [description]
	 * @return bool
	 */
	public static function check_user_cap_edit_entry( $entry ) {
		global $gravityview_view;

		if( !isset( $entry['created_by'] ) ) {
			return false;
		}

		$user_edit = !empty( $gravityview_view->atts['user_edit'] );
		$current_user = wp_get_current_user();

		// If the logged-in user is the same as the user who created the entry, we're good.
		if( $user_edit && is_user_logged_in() && intval( $current_user->ID ) === intval( $entry['created_by'] ) ) {
			return true;
		}

		// Or if they can edit any entries (as defined in Gravity Forms), we're good.
		if( GFCommon::current_user_can_any("gravityforms_edit_entries") ) {
			return true;
		}

		return false;
	}


	function generate_notice( $notice, $class = '' ) {
		return '<div class="gv-notice '.esc_attr( $class ) .'">'. $notice .'</div>';
	}

	/**
	 * Display the Edit Enrty form
	 *
	 * @filter gravityview_edit_entry_title Modfify the edit entry title
	 * @return [type] [description]
	 */
	public function edit_entry_form() {

		$back_link = remove_query_arg( array( 'page', 'view', 'edit' ) );

	?>

	<div class="gv-edit-entry-wrapper">

		<?php include_once( self::$file .'/inline-javascript.php'); ?>

		<h2 class="gv-edit-entry-title">
			<span><?php echo esc_attr( apply_filters('gravityview_edit_entry_title', __('Edit Entry', 'gravity-view'), $this ) ); ?></span>
		</h2>

		<?php

		// Display the sucess message
		if( rgpost('action') === 'update' ) {

			if( ! $this->is_valid ){

				// Keeping this compatible with Gravity Forms.
			    $validation_message = "<div class='validation_error'>" . __("There was a problem with your submission.", "gravity-view") . " " . __("Errors have been highlighted below.", "gravity-view") . "</div>";
			    $message = apply_filters("gform_validation_message_{$this->form["id"]}", apply_filters("gform_validation_message", $validation_message, $this->form), $this->form);

			    echo $this->generate_notice( $message , 'gv-error' );

			} else {
				echo $this->generate_notice( sprintf( esc_attr__('Entry Updated. %sReturn to Entry%s', ''), '<a href="'.$back_link.'">', '</a>' ) );
			}

		}

		// The ID of the form needs to be `gform_{form_id}` for the pluploader ?>
		<form method="post" id="gform_<?php echo $this->form_id; ?>" enctype='multipart/form-data'>

		    <?php

		    wp_nonce_field( self::$nonce_key, self::$nonce_key );

		    wp_nonce_field( 'is_gv_edit_entry', 'is_gv_edit_entry', false );

		    // Most of this is needed for GFFormDisplay::validate(), but `gform_unique_id` is needed for file cleanup.
		    echo "
		    <input type='hidden' name='action' id='action' value='update' />
		    <input type='hidden' class='gform_hidden' name='is_submit_{$this->form_id}' value='1' />
	        <input type='hidden' class='gform_hidden' name='gform_submit' value='{$this->form_id}' />
	        <input type='hidden' class='gform_hidden' name='gform_unique_id' value='" . esc_attr(GFFormsModel::get_form_unique_id($this->form_id)) . "' />
	        <input type='hidden' class='gform_hidden' name='state_{$this->form_id}' value='" . GFFormDisplay::get_state( $this->form, NULL ) . "' />
	        <input type='hidden' name='gform_field_values' value='' />
			<input type='hidden' name='screen_mode' id='screen_mode' value='view' />
			<input type='hidden' name='lid' value='{$this->entry['id']}' />
	        ";

	        // Print the actual form HTML
			GFEntryDetail::lead_detail_edit( $this->form, $this->entry );
	?>
		<div id="publishing-action">
		    <input class="btn btn-lg button button-large button-primary" type="submit" tabindex="4" value="<?php esc_attr_e( 'Update', 'gravity-view'); ?>" name="save" />

            <a class="btn btn-sm button button-small" tabindex="5" href="<?php echo $back_link ?>"><?php esc_attr_e( 'Cancel', 'gravity-view' ); ?></a>
		</div>
<?php
		GFFormDisplay::footer_init_scripts($this->form_id);
?>
	</div>
<?php
	}

}

//add_action( 'plugins_loaded', array('GravityView_Edit_Entry', 'getInstance'), 6 );
new GravityView_Edit_Entry;

