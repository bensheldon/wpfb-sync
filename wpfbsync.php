<?php
/*
Plugin Name: WPFBsync
Plugin URI: http://island94.org
Description: Syncs Facebook with your blog
Version: 1.0.0
Author: Ben Sheldon
Author URI: http://island94.org
*/

require('lib/facebook.php');


class wpfbsync {
 	
	private $version = '0.1';
   
	/**
	* Default constructor.
	*/
	function __construct() {
	  // add an admin options menu
	  add_action('admin_menu', array(&$this, 'admin_menu'));
	  
	  // setup custom routing
	  add_filter('query_vars', array(&$this, 'add_query_vars') );
	  //add_action('admin_init', 'flush_rewrite_rules');
	  add_action('generate_rewrite_rules', array(&$this, 'add_rewrite_rules'));
	  
	  // routing for custom page callbacks
	  add_action( 'parse_request', array( &$this, 'parse_wp_request' ) );
		
	}
	
	function add_query_vars($vars){
		$vars[] = 'wpfbsync';
		return $vars;
	}
	
  function add_rewrite_rules( $wp_rewrite ) {
    $new_rules = array( 
       'wpfbsync/(.+)' => 'index.php?wpfbsync=' .
         $wp_rewrite->preg_index(1) );
  
    // Add the new rewrite rule into the top of the global rules array
    $wp_rewrite->rules = $new_rules + $wp_rewrite->rules;
  }
	
	function parse_wp_request( $wp ) {
  	if ( isset( $wp->query_vars['wpfbsync'] ) ) {
  		if ( $wp->query_vars['wpfbsync'] == 'realtime' )
  		$this->realtime_callback( );
  		exit;
  	}
	}
	
	
	
	/**
	* Admin menu entry.
	*
	* @access	public
	*/
	public function admin_menu() {
		//create new Settings Menu option
		add_options_page('WPFBsync Options', 'WPFBsync Options', 'administrator', __FILE__, array(&$this,'admin_options_page'));
		//call register settings function
		add_action('admin_init', array(&$this, 'admin_options_settings'));
	} 

	/**
	* Options page.
	*
	*/
	public function admin_options_page() {
		
		// Check permissions (necessary?)
	  if (!current_user_can('administrator'))  {
    	wp_die( __('You do not have sufficient permissions to access this page.') );
  	}
		
		?>
			<div class="wrap">
				<div class="icon32" id="icon-options-general"><br></div>
				<h2>WP-FB Sync</h2>
				Some optional text here explaining the overall purpose of the options and what they relate to etc.
				<form action="options.php" method="post">
				<?php settings_fields('wpfbsync_auth'); ?>
				<?php do_settings_sections(__FILE__); ?>
				<p class="submit">
					<input name="Submit" type="submit" class="button-primary" value="<?php esc_attr_e('Save Changes'); ?>" />
				</p>
				</form>
			</div>
		<?php
	}
	/**
	 * Register our options
	 */	
	public function admin_options_settings(){
		$options = get_option('wpfbsync');
		
		register_setting('wpfbsync_auth', 'wpfbsync', array(&$this,'admin_options_validate') );
		add_settings_section('section_facebook', 'Facebook Settings', array(&$this,'admin_options_section_facebook'), __FILE__);
		add_settings_field('fb_app_id', 'Facebook App ID', array(&$this,'admin_options_fb_app_id'), __FILE__, 'section_facebook');
		add_settings_field('fb_secret_key', 'Facebook App Secret Key', array(&$this,'admin_options_fb_secret_key'), __FILE__, 'section_facebook');
		// if App is set up, show user authorization
		if ($options['fb_app_id'] && $options['fb_secret_key']) { 
			add_settings_field('session', 'Authorized Facebook Account', array(&$this,'admin_options_fb_user'), __FILE__, 'section_facebook');
			
			// if User Authorized, show list of Profile/Fan Pages / also, if the user is forwarded back from Facebook, we need to capture this.
			if ($facebook = $this->facebook_authorize()) { 
				add_settings_field('fb_access_token', 'Sync with', array(&$this,'admin_options_fb_access_token'), __FILE__, 'section_facebook');
			}
		}
				
		add_settings_section('section_publish', 'Publishing Settings', array(&$this,'admin_options_section_publishing'), __FILE__);
		
//		add_settings_field('plugin_chk1', 'Restore Defaults Upon Reactivation?', 'setting_chk1_fn', __FILE__, 'main_section');
	}
	
	/**
	 * Callback for Options Setting
	 *
	 * Section: Facebook Settings
	 */
	function admin_options_section_facebook() {
		echo '<p>This plugin requires that you set up a <a href="http://www.facebook.com/developers/apps.php">Facebook Application</a> for this website. When configuring the Facebook Application via Facebook, make sure the following settings are correct: </p>';
		echo '<p><strong>Site URL:</strong> ' . get_option('siteurl') . '/ (do not forget the ending slash); </p><p><strong>Domain:</strong> ' . parse_url(get_option('siteurl'), PHP_URL_HOST) . '</p>';

	}
	
	/**
	 * Callback for Options Setting
	 *
	 * Setting: Facebook Application ID
	 */
	function admin_options_fb_app_id() {
		$options = get_option('wpfbsync');
		echo "<input id='fb_app_id' name='wpfbsync[fb_app_id]' size='40' type='text' value='{$options['fb_app_id']}' />";
	}
	
	/**
	 * Callback for Options Setting
	 *
	 * Setting: Facebook Application Secret Key
	 */
	function admin_options_fb_secret_key() {
		$options = get_option('wpfbsync');
		echo "<input id='fb_secret_key' name='wpfbsync[fb_secret_key]' size='40' type='text' value='{$options['fb_secret_key']}' />";
	}
	
	/**
	 * Callback for Options Setting
	 *
	 * Setting: Connect to Facebook / Authorize User
	 *
	 * Note: We don't actually provide a form-item, but rather save the session
	 * data directly to the database; used mostly for formatting
	 */
	function admin_options_fb_user() {
		$options = get_option('wpfbsync');
		
		// Check authorizations, and if we're successful, save to the database
		// and display the User's name and photo
		if ($facebook = $this->facebook_authorize()) {		
			$options['session'] = $facebook->getSession();
			update_option('wpfbsync', $options);
				
			$me = $facebook->api('/me');
								
		  echo '<img src="https://graph.facebook.com/' . $me['id'] . '/picture" style="float:left;margin-right: 10px"/>';
      echo '<strong style="font-size:1.2em">' . $me['name'] . '</strong>';
      echo '<br /><small>Deauthorize <a href="http://www.facebook.com/settings/?tab=applications">this account</a> and disable the plugin. Changing your password on Facebook  will also require you to reauthorize your account.</small>';
      echo '<div style="clear:left"></div>';

		}
		// If NOT authorized, provide a "Connect to Facebook" option
		// that will allow the user to become authorized be redirected
		// back to this page, where the Session info will be saved (above)
		else {
			
			$facebook = new Facebook(array(
			  'appId'  => $options['fb_app_id'],
			  'secret' => $options['fb_secret_key'],
			  'cookie' => false,
			));
		
			// Request Extended Permissions for the Facebook User
	    $params = array(
	    	'req_perms' => 'read_stream,manage_pages,offline_access'
			);
			
	  	$loginUrl = $facebook->getLoginUrl($params);
		
		  echo "<a href='$loginUrl'><img src='http://static.ak.fbcdn.net/rsrc.php/zB6N8/hash/4li2k73z.gif' /></a>";
	    
	    // Show the error if there is already an existing session to prevent
	    // confusion to 1st time users, but provide info for trouble-shooting
	   	echo "<br />You must authorize this website with a specific Facebook user account";
	    if ($e && $options['session']) {
	    	echo '<br /><strong style="color:#ff0000;">' . $e . '</strong>';
	    }
		}
	}

	/**
	 * Callback for Options Setting
	 *
	 * Setting: Sync with Account / Fan Page
	 *
	 * Provides a list of the Authorized User's Personal Updates and Fan Pages
	 * Choosing one will save the User/Fan Page's Access Token to be used for 
	 * API calls.
	 */	
	function admin_options_fb_access_token() {
		if ($facebook = $this->facebook_authorize()) {
			$accounts = $facebook->api('/me/accounts');
			$me = $facebook->api('/me');
			$session = $facebook->getSession();

			// Set no update option
			$no_sync = array('-- Do not Sync --' => '');
			
			// Add the user's Updates too
			$my_updates = array($me['name'] . "'s updates" => $session['access_token']);
						
			$fanpages = array();
			foreach ($accounts['data'] as $account) {
				// Make sure we have Oath Tokens and don't show Applications (we only want Fan Pages)
				if ( $account['access_token'] && ($account['category'] != 'Application')) { 
					$fanpages[$account['name']] = $account['access_token'];
				}
			}
			
			//merge options together
			$accounts = array_merge($no_sync, $my_updates, $fanpages);
			$options = get_option('wpfbsync');
			echo "<select id='fb_publish_id' name='wpfbsync[fb_access_token]'>";
			
			foreach($accounts as $name => $access_token) {
				$selected = ($options['fb_access_token']==$access_token) ? 'selected="selected"' : '';
				echo "<option value='$access_token' $selected>$name</option>";
			}
		echo "</select>";
		
		print_r($options);
		}
	}
	
	/**
	 * Callback for Options Validation
	 */
	function admin_options_validate($input) {
		$options = get_option('wpfbsync');
		
		// Check to see if our App ID or Secret Key have changed. If so, delete our session key, thus requiring the user to reauthorize the account.
		if ( ($input['fb_app_id'] != $options['fb_app_id']) || ($input['fb_secret_key'] != $options['fb_secret_key']) ) {
			$options['session'] = '';
			update_option('wpfbsync', $options);
		}
		
		// Check to see if our 'Synced With' (fb_access_token) has changed. If so, we need to update our Real-Time Pub Settings
		if ( ($input['fb_access_token'] != $options['fb_access_token'])) {
		  if ($input['fb_access_token'] != '') {
		    // SUBSCRIBE
		    $VERIFY_TOKEN = md5(get_bloginfo('admin_email'));
		    $CALLBACK_URL = get_option('siteurl') . '/wpfbsync/realtime';
		    
		    $facebook = new Facebook(array(
		    	  'appId'  => $options['fb_app_id'],
		    	  'secret' => $options['fb_secret_key'],
		    	  'cookie' => false,
		    	));
		    
		    $session = $facebook->getSession();
		    		    
		    $params = array(
          'access_token' => $session['access_token'],
          'object' => 'user',
          'fields' => 'name,feed',
          'callback_url' => $CALLBACK_URL,
          'verify_token' => $VERIFY_TOKEN,
          );
          
         $subs = $facebook->api('/'.$input['fb_app_id'].'/subscriptions', 'POST', $params);
		  }
		  else {
		    // UNSUBSCRIBE
		  
		  }
		}
		
		return array_merge($options, $input); // merge and return the options
	}

	function admin_options_section_publishing() {
		$options = get_option('wpfbsync');
		
		$facebook_authed = $this->facebook_authorize();
		
		$facebook = new Facebook(array(
			  'appId'  => $options['fb_app_id'],
			  'secret' => $options['fb_secret_key'],
			  'cookie' => false,
			));
		
//		echo plugins_url('callback.php', __FILE__);

		echo '<pre>';
//		print_r($facebook->api('/me/feed', array(
//			access_token =>$options['fb_access_token']
//		)));
		echo '</pre>';

	}

	/**
	 * Function that returns an authorized Facebook object
	 * If not authorized, return NULL;
	 */
	public function facebook_authorize($session = array()) {
		$options = get_option('wpfbsync');
		$me = null;

		// make sure we have our Application Registered
		if ($options['fb_app_id'] && $options['fb_secret_key']) {
			
			// setup our Facebook object.
			$facebook = new Facebook(array(
			  'appId'  => $options['fb_app_id'],
			  'secret' => $options['fb_secret_key'],
			  'cookie' => false,
			));

			// Test the Argument session first
			if ($session) {
				$facebook->setSession($session);
				try {
	        $uid = $facebook->getUser();
	        $me = $facebook->api('/me');
	        return $facebook; // SUCCESS!
		    } catch (FacebookApiException $e) {
	        error_log($e);
		    }
			}
			
			// Next check if there is a Session passed thru the URL 
			if ($_GET['session']) {
				$session = $facebook->getSession();	
				try {
	        $uid = $facebook->getUser();
	        $me = $facebook->api('/me');
	        return $facebook; // SUCCESS!
		    } catch (FacebookApiException $e) {
	        error_log($e);
		    }
			}
			
			// Lastly, check if the session saved in options works
			if ($session = $options['session']) {
				$facebook->setSession($session);
				
				try {
	        $uid = $facebook->getUser();
	        $me = $facebook->api('/me');
	        return $facebook; // SUCCESS!
		    } catch (FacebookApiException $e) {
	        error_log($e);
		    }
			}
		}
		return NULL;
	}

  function realtime_callback () {
    $options = get_option('wpfbsync');
    
    $VERIFY_TOKEN = md5(get_bloginfo('admin_email'));
    $method = $_SERVER['REQUEST_METHOD'];
    	
    // In PHP, dots and spaces in query parameter names are converted to 
    // underscores automatically. So we need to check "hub_mode" instead
    //  of "hub.mode".                                                      
    if ($method == 'GET' && $_GET['hub_mode'] == 'subscribe' &&
        $_GET['hub_verify_token'] == $VERIFY_TOKEN) {
      echo $_GET['hub_challenge'];
      error_log('Facebook Subscribed');
      exit; 
    } elseif ($method == 'POST') {                                   
      $updates = json_decode(file_get_contents("php://input"), true); 
      error_log('updates = ' . print_r($updates, true));              
    }
    exit;
    
  }

}



// enable plugin on init
add_action('init', 'wpfb_init');

function wpfb_init() {
   $wpfbsync = new wpfbsync();
}
