<?php

/*
Plugin Name: WPU Import Instagram
Description: Import the latest instagram images
Plugin URI: https://github.com/WordPressUtilities/wpudisplayinstagram
Version: 0.23.4
Author: Darklg
Author URI: http://darklg.me/
License: MIT License
License URI: http://opensource.org/licenses/MIT
*/

class wpu_display_instagram {

    public $plugin_version = '0.23.4';
    public $test_user_id = 25025320;
    public $debug = false;

    public $options = array();

    public $messages = false;
    public $basecron = false;

    public $register_link = 'https://www.instagram.com/developer/clients/manage/';
    public $api_domain = 'https://api.instagram.com/';
    public $api_version = 'v1';

    public $option_user_ids_opt = 'wpu_get_instagram__user_ids_opt';

    public function __construct() {
        $this->debug = apply_filters('wpudisplayinstagram__debug', $this->debug);
        $this->options = array(
            'plugin_id' => 'wpudisplayinstagram',
            'id' => 'wpu-display-instagram',
            'name' => 'Import Instagram',
            'taxonomy' => apply_filters('wpudisplayinstagram__taxonomy_id', 'instagram_tags'),
            'post_type' => apply_filters('wpudisplayinstagram__post_type_id', 'instagram_posts')
        );

        add_filter('plugins_loaded', array(&$this,
            'plugins_loaded'
        ));
        add_action('init', array(&$this,
            'register_taxo_type'
        ));
        add_action('admin_init', array(&$this,
            'set_token'
        ));
        add_action('admin_post_' . $this->options['plugin_id'], array(&$this,
            'admin_postAction'
        ));
        add_action('admin_menu', array(&$this,
            'add_menu_page'
        ));
        add_filter("plugin_action_links_" . plugin_basename(__FILE__), array(&$this,
            'settings_link'
        ));
        add_action('wpu_display_instagram__cron_hook', array(&$this,
            'cron_action'
        ));
        add_action('template_redirect', array(&$this,
            'template_redirect'
        ));
        add_action('admin_notices', array(&$this,
            'admin_notices'
        ));

        // Listing
        add_filter('manage_edit-instagram_posts_columns', array(&$this,
            'posts_columns'
        ));
        add_action('manage_instagram_posts_posts_custom_column', array(&$this,
            'posts_column_content'
        ), 10, 2);
        add_filter('manage_edit-instagram_posts_sortable_columns', array(&$this,
            'sortable_posts_column'
        ));
        add_action('pre_get_posts', array(&$this,
            'posts_columns_orderby'
        ));
        add_filter('parse_query', array(&$this,
            'filter_admin_results'
        ));

        // Single
        add_action('add_meta_boxes', array(&$this,
            'add_meta_boxes'
        ));

        // Gmaps
        add_filter('wpugmapsautocompletebox_posttypes', array(&$this,
            'set_wpugmapsautocompletebox_posttypes'
        ), 10, 1);
        add_filter('wpugmapsautocompletebox_dim', array(&$this,
            'set_wpugmapsautocompletebox_dim'
        ), 10, 1);

        load_plugin_textdomain('wpudisplayinstagram', false, dirname(plugin_basename(__FILE__)) . '/lang/');

        // Settings
        $this->settings_details = array(
            'plugin_id' => 'wpudisplayinstagram',
            'option_id' => 'wpudisplayinstagram_options',
            'sections' => array(
                'access' => array(
                    'name' => __('API Access', 'wpudisplayinstagram')
                ),
                'settings' => array(
                    'name' => __('Settings', 'wpudisplayinstagram')
                ),
                'generated' => array(
                    'name' => __('Generated post', 'wpudisplayinstagram')
                )
            )
        );

        add_action('update_option_wpudisplayinstagram_options', array(&$this,
            'refresh'
        ));

        $this->settings = array(
            'client_id' => array(
                'section' => 'access',
                'label' => __('Client ID', 'wpudisplayinstagram')
            ),
            'client_secret' => array(
                'section' => 'access',
                'label' => __('Client Secret', 'wpudisplayinstagram')
            ),
            'client_token' => array(
                'section' => 'access',
                'label' => __('Access token', 'wpudisplayinstagram')
            ),
            'user_names' => array(
                'section' => 'settings',
                'type' => 'textarea',
                'label' => __('Usernames', 'wpudisplayinstagram'),
                'help' => __('One user name by line', 'wpudisplayinstagram')
            ),
            'display_posts_front' => array(
                'section' => 'settings',
                'label' => __('Display posts', 'wpudisplayinstagram'),
                'label_check' => __('Display posts in front', 'wpudisplayinstagram'),
                'type' => 'checkbox'
            ),
            'replace_oembed' => array(
                'section' => 'settings',
                'label' => __('Replace oembed', 'wpudisplayinstagram'),
                'label_check' => __('Use local instagram images when available instead of JS embed.', 'wpudisplayinstagram'),
                'type' => 'checkbox'
            ),
            'import_as_draft' => array(
                'section' => 'generated',
                'label' => __('Import as draft', 'wpudisplayinstagram'),
                'label_check' => __('Import image as a draft post', 'wpudisplayinstagram'),
                'type' => 'checkbox'
            ),
            'remove_tags_title' => array(
                'section' => 'generated',
                'label' => __('Remove tags', 'wpudisplayinstagram'),
                'label_check' => __('Remove tags from the post title', 'wpudisplayinstagram'),
                'type' => 'checkbox'
            ),
            'insert_image_content' => array(
                'section' => 'generated',
                'label' => __('Insert image', 'wpudisplayinstagram'),
                'label_check' => __('Insert image in the post content', 'wpudisplayinstagram'),
                'type' => 'checkbox'
            )
        );

        $this->options_values = get_option($this->settings_details['option_id']);
        if (!is_array($this->options_values)) {
            $this->options_values = array();
        }
    }

    public function cron_action() {
        $this->init_content();
        $this->import();
    }

    public function plugins_loaded() {
        $this->init_content();

        include_once 'inc/WPUBaseUpdate/WPUBaseUpdate.php';
        $this->settings_update = new \wpudisplayinstagram\WPUBaseUpdate(
            'WordPressUtilities',
            'wpudisplayinstagram',
            $this->plugin_version);

        // Messages
        include_once 'inc/WPUBaseMessages.php';
        $this->messages = new \wpudisplayinstagram\WPUBaseMessages($this->options['plugin_id']);
        add_action('wpuimporttwitter_admin_notices', array(&$this->messages,
            'admin_notices'
        ));

        // Cron
        include_once 'inc/WPUBaseCron.php';
        $this->basecron = new \wpudisplayinstagram\WPUBaseCron(array(
            'pluginname' => $this->options['name'],
            'cronhook' => 'wpu_display_instagram__cron_hook',
            'croninterval' => 3600
        ));
        $this->basesettings = false;
        if (is_admin()) {
            if ($this->sandboxmode) {
                unset($this->settings['user_names']);
            }

            include_once 'inc/WPUBaseSettings.php';
            $this->basesettings = new \wpudisplayinstagram\WPUBaseSettings($this->settings_details, $this->settings);
        }

    }

    public function init_content() {
        $this->nonce_import = $this->options['id'] . '__nonce_import';

        // Instagram config
        $this->client_token = isset($this->options_values['client_token']) ? trim($this->options_values['client_token']) : '';
        $this->client_id = isset($this->options_values['client_id']) ? trim($this->options_values['client_id']) : '';
        $this->client_secret = isset($this->options_values['client_secret']) ? trim($this->options_values['client_secret']) : '';
        $this->user_name = isset($this->options_values['user_name']) ? trim($this->options_values['user_name']) : '';
        $this->user_names = isset($this->options_values['user_names']) ? trim($this->options_values['user_names']) : '';
        $this->import_as_draft = isset($this->options_values['import_as_draft']) ? trim($this->options_values['import_as_draft']) : false;
        $this->replace_oembed = isset($this->options_values['replace_oembed']) ? trim($this->options_values['replace_oembed']) : false;
        $this->display_posts_front = isset($this->options_values['display_posts_front']) ? trim($this->options_values['display_posts_front']) : false;
        $this->remove_tags_title = isset($this->options_values['remove_tags_title']) ? trim($this->options_values['remove_tags_title']) : false;
        $this->insert_image_content = isset($this->options_values['insert_image_content']) ? trim($this->options_values['insert_image_content']) : false;

        $opt_sandboxmode = get_option('wpu_get_instagram__sandboxmode');
        $this->sandboxmode = ($opt_sandboxmode != '0') ? '1' : '0';
        if (is_null($opt_sandboxmode) || $opt_sandboxmode === false) {
            $this->test_sandbox_mode();
        }

        // Admin URL
        $this->admin_uri = 'edit.php?post_type=' . $this->options['post_type'];
        $this->redirect_uri = admin_url($this->admin_uri . '&page=' . $this->options['id']);

        $this->config_ok = !empty($this->client_id) && !empty($this->client_secret);

        if (apply_filters('wpudisplayinstagram_replace_oembed', $this->replace_oembed)) {
            add_filter('embed_oembed_html', array(&$this,
                'embed_oembed_html'
            ), 99, 4);
        }

    }

    /* ----------------------------------------------------------
      API
    ---------------------------------------------------------- */

    public function get_user_names() {
        $user_names = array();

        if ($this->sandboxmode) {
            $user_id = $this->get_user_id();
            return array(array(
                'user_id' => $user_id,
                'request_url' => $this->get_request_url($user_id)
            ));
        }

        $_baseUserNames = str_replace(array(',', ';', ' '), "\n", $this->user_names);
        $_baseUserNames = explode("\n", $_baseUserNames);
        foreach ($_baseUserNames as $user_name) {
            $user_name = trim(esc_html($user_name));
            if (!empty($user_name)) {
                $user_id = $this->get_user_id($user_name);
                $request_url = $this->get_request_url($user_id);
                $user_names[] = array(
                    'user_id' => $user_id,
                    'request_url' => $request_url
                );
            }
        }
        return $user_names;
    }

    public function get_request_url($user_id = false) {

        if (empty($this->client_token)) {
            return false;
        }

        if (!$user_id || !is_numeric($user_id)) {
            $user_id = $this->get_user_id();
        }

        if (!is_numeric($user_id)) {
            return false;
        }

        return $this->api_domain . $this->api_version . '/users/' . $user_id . '/media/recent/?count=%s&access_token=' . $this->client_token;
    }

    public function get_user_id($user_name = '') {
        $_option_user_id = 'wpu_get_instagram__user_id__' . $user_name;
        $_user_id = false;

        if (empty($user_name)) {
            $token_parts = explode('.', $this->client_token);
            if (is_numeric($token_parts[0])) {
                return $token_parts[0];
            }
        }

        /* Set master user ids function */
        $opt_user_id = get_option($this->option_user_ids_opt);
        if (!is_array($opt_user_id)) {
            $opt_user_id = array();
        }

        if (!in_array($_option_user_id, $opt_user_id)) {
            $opt_user_id[] = $_option_user_id;
            update_option($this->option_user_ids_opt, $opt_user_id);
        }

        /* Get from DB */
        if (!property_exists($this, 'user_id')) {
            $_user_id = trim(get_option($_option_user_id));
        }

        /* Test if valid */
        if (is_numeric($_user_id)) {
            return $_user_id;
        }

        /* Try to get username */
        if (empty($user_name) || !preg_match("/[A-Za-z0-9_]+/i", $user_name)) {
            return false;
        }

        /* Try to get user id from API */
        $_url = $this->api_domain . $this->api_version . "/users/search?q=" . $user_name . "&access_token=" . $this->client_token;
        $_request = wp_remote_get($_url);
        if (is_wp_error($_request)) {
            return false;
        }
        $json = json_decode(wp_remote_retrieve_body($_request));
        if (!is_object($json) || !is_array($json->data) || !isset($json->data[0])) {
            return false;
        }

        $base_username = strtolower($user_name);
        foreach ($json->data as $_user) {
            $tmp_username = strtolower($_user->username);
            if ($tmp_username == $base_username) {
                $_user_id = $_user->id;
                update_option($_option_user_id, $_user_id);
                return $_user_id;
            }
        }
        return false;
    }

    public function set_token() {

        if (!$this->is_admin_page() || !isset($_GET['code'])) {
            return;
        }

        $url = $this->api_domain . 'oauth/access_token';
        $result = wp_remote_post($url, array(
            'body' => array(
                'client_id' => $this->client_id,
                'client_secret' => $this->client_secret,
                'grant_type' => 'authorization_code',
                'redirect_uri' => $this->redirect_uri,
                'code' => $_GET['code']
            )
        ));

        $token = '';
        $response = '{}';
        if (is_wp_error($result)) {
            $this->messages->set_message('token_no_body', __('The response from Instagram is invalid.', 'wpudisplayinstagram'), 'error');
            return;
        }
        $response = json_decode(wp_remote_retrieve_body($result));

        if (!isset($response->access_token)) {
            $this->messages->set_message('token_no_token', __('The access token from Instagram could not be retrieved.', 'wpudisplayinstagram'), 'error');
            return;
        }

        $this->client_token = $response->access_token;

        $this->test_sandbox_mode();

        // Update options
        $this->basesettings->update_setting('client_token', $this->client_token);

        $this->messages->set_message('token_success', __('The token have been successfully imported.', 'wpudisplayinstagram'), 'updated');
        wp_redirect($this->redirect_uri);
        exit();
    }

    public function import() {
        if (empty($this->client_token)) {
            $this->set_token();
        }

        if ($this->sandboxmode) {
            $this->test_sandbox_mode();
        }

        $this->debug_log('import function called');

        $imported_items = $this->get_imported_items();
        $user_names = $this->get_user_names();
        $nb_items = 10;
        if (count($user_names) > 1) {
            $nb_items = 5;
        }
        $nb_items = apply_filters('wpudisplayinstagram__nb_items', $nb_items);

        if (empty($user_names)) {
            $this->debug_log('no usernames available');
            return 0;
        }
        $base_userid = $this->get_user_id();

        $total_count = 0;
        foreach ($user_names as $user_name) {
            // If sandbox, ignore if not base username
            if ($this->sandboxmode && $base_userid != $user_name['user_id']) {
                continue;
            }
            $total_count += $this->import_for_user($user_name, $imported_items, $nb_items);
        }

        return $total_count;

    }

    public function import_for_user($user_name = '', $imported_items = array(), $nb_items = 1) {
        $request_url = sprintf($user_name['request_url'], $nb_items);

        $this->debug_log('import starting for user ' . $user_name['user_id']);

        // Send request
        $request = wp_remote_get($request_url);
        if (is_wp_error($request)) {
            $_message = __('The datas sent by Instagram are invalid.', 'wpudisplayinstagram');
            $this->messages->set_message('no_array_insta', $_message, 'error');
            $this->debug_log('user ' . $user_name['user_id'] . ' : ' . $_message);
            if (!$this->sandboxmode) {
                $this->test_sandbox_mode();
            }
            return 0;
        }

        // Extract and return informations
        $imginsta = json_decode(wp_remote_retrieve_body($request));
        if (!is_object($imginsta) || !property_exists($imginsta, 'data') || !is_array($imginsta->data)) {
            $_message = __('The datas sent by Instagram are invalid.', 'wpudisplayinstagram');
            $this->messages->set_message('no_array_insta', $_message, 'error');
            $this->debug_log('user ' . $user_name['user_id'] . ' : ' . $_message);
            if (!$this->sandboxmode) {
                $this->test_sandbox_mode();
            }
            return 0;
        }

        // Import each post if not in database
        $count = 0;
        foreach ($imginsta->data as $item) {
            $datas = $this->get_datas_from_item($item);
            if (!in_array($datas['id'], $imported_items)) {
                $this->debug_log('user ' . $user_name['user_id'] . ' : importing item ' . $datas['id']);
                $count++;
                $imported_items[] = $this->import_item($datas);
            }
        }
        return $count;

    }

    /* ----------------------------------------------------------
      Import functions
    ---------------------------------------------------------- */

    public function import_item($datas = array()) {

        // Set post title
        $post_title = $datas['caption'];
        if ($this->remove_tags_title) {
            $post_title = preg_replace('/\#([A-Za-z0-9]*)/is', '', $post_title);
            $post_title = preg_replace('/\s+/', ' ', $post_title);
        }
        $post_title = wp_trim_words($post_title, 20);

        // Set post details
        $post_details = array(
            'post_title' => $post_title,
            'post_content' => $datas['caption'],
            'post_name' => preg_replace('/([^a-z0-9-$]*)/isU', '', sanitize_title($post_title)),
            'post_status' => 'publish',
            'post_date' => date('Y-m-d H:i:s', $datas['created_time']),
            'post_author' => 1,
            'post_type' => $this->options['post_type']
        );

        // Import as draft
        if ($this->import_as_draft) {
            $post_details['post_status'] = 'draft';
        }

        // Add hashtags
        $matches = array();
        preg_match_all("/#(\\w+)/", $datas['caption'], $matches);
        if (!empty($matches[1])) {
            $post_details['tags_input'] = implode(', ', $matches[1]);
        }

        // Create a new post
        $post_id = wp_insert_post($post_details);

        // Add taxonomy
        preg_match_all("/(#\w+)/", $datas['caption'], $matches);
        if (!empty($matches)) {
            $tags = array();
            foreach ($matches[0] as $tag) {
                $tag = str_replace('#', '', $tag);
                if (!in_array($tag, $tags)) {
                    $tags[] = $tag;
                }
            }
            if (!empty($tags)) {
                wp_set_post_terms($post_id, $tags, $this->options['taxonomy'], true);
            }
        }

        // Save datas
        update_post_meta($post_id, 'instagram_post_id', $datas['id']);
        update_post_meta($post_id, 'instagram_post_link', $datas['link']);
        update_post_meta($post_id, 'instagram_post_username', $datas['username']);
        update_post_meta($post_id, 'instagram_post_full_name', $datas['full_name']);
        update_post_meta($post_id, 'instagram_post_datas', $datas);

        if ($datas['location']['latitude'] != 0) {
            update_post_meta($post_id, 'instagram_post_latitude', $datas['location']['latitude']);
            update_post_meta($post_id, 'instagram_post_longitude', $datas['location']['longitude']);
        }

        // Add required classes
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';

        // Import image as an attachment
        $image = media_sideload_image($datas['image'], $post_id, $datas['caption'], 'id');

        // set image as the post thumbnail
        set_post_thumbnail($post_id, $image);

        if ($this->insert_image_content) {
            wp_update_post(array(
                'ID' => $post_id,
                'post_content' => wpautop(get_the_post_thumbnail($post_id, 'full')) . $datas['caption']
            ));
        }

        return $datas['id'];
    }

    public function get_imported_items() {
        global $wpdb;
        $wpids = $wpdb->get_col("SELECT meta_value FROM $wpdb->postmeta WHERE meta_key = 'instagram_post_id'");
        return is_array($wpids) ? $wpids : array();
    }

    public function get_datas_from_item($details) {
        $datas = array(
            'image' => '',
            'username' => '',
            'full_name' => '',
            'link' => '#',
            'created_time' => '0',
            'caption' => '',
            'location' => array(
                'latitude' => 0,
                'longitude' => 0
            ),
            'id' => 0
        );

        // Image
        if (isset($details->id)) {
            $datas['id'] = $details->id;
        }

        // Image
        if (isset($details->images->standard_resolution->url)) {
            $datas['image'] = $details->images->standard_resolution->url;
        }

        // Link
        if (isset($details->link)) {
            $datas['link'] = $details->link;
        }

        // Name
        if (isset($details->user, $details->user->username, $details->user->full_name)) {
            $datas['username'] = $details->user->username;
            $datas['full_name'] = $details->user->full_name;
        }

        // Created time
        if (isset($details->created_time)) {
            $datas['created_time'] = $details->created_time;
        }

        // Caption
        if (isset($details->caption->text)) {
            $datas['caption'] = $details->caption->text;
        }

        if (isset($details->location->name)) {
            if (!empty($datas['caption'])) {
                $datas['caption'] .= ' - ';
            }
            $datas['caption'] .= $details->location->name;
        }

        // Location
        if (isset($details->location->latitude, $details->location->longitude)) {
            $datas['location'] = array(
                'latitude' => $details->location->latitude,
                'longitude' => $details->location->longitude
            );
        }

        return $datas;
    }

    /* ----------------------------------------------------------
      Post type
    ---------------------------------------------------------- */

    public function register_taxo_type() {
        register_post_type($this->options['post_type'], apply_filters('wpudisplayinstagram__post_type_infos', array(
            'public' => $this->display_posts_front,
            'show_in_nav_menus' => true,
            'show_ui' => true,
            'label' => __('Instagram posts', 'wpudisplayinstagram'),
            'labels' => array(
                'singular_name' => __('Instagram post', 'wpudisplayinstagram')
            ),
            'taxonomies' => array($this->options['taxonomy']),
            'menu_icon' => 'dashicons-format-image',
            'supports' => array(
                'title',
                'editor',
                'thumbnail'
            )
        )));
        // create a new taxonomy
        register_taxonomy(
            $this->options['taxonomy'],
            $this->options['post_type'],
            apply_filters('wpudisplayinstagram__taxonomy_infos', array(
                'label' => __('Instagram tags', 'wpudisplayinstagram'),
                'hierarchical' => false
            ))
        );
    }

    /* ----------------------------------------------------------
      Admin page
    ---------------------------------------------------------- */

    public function add_menu_page() {
        add_submenu_page($this->admin_uri, $this->options['name'], __('Import settings', 'wpudisplayinstagram'), 'manage_options', $this->options['id'], array(&$this,
            'admin_page'
        ));
    }

    /**
     * Settings link
     */
    public function settings_link($links = array()) {
        $settings_link = '<a href="' . $this->redirect_uri . '">' . __('Settings', 'wpudisplayinstagram') . '</a>';
        array_unshift($links, $settings_link);
        return $links;
    }

    public function admin_postAction() {
        if (isset($_POST[$this->nonce_import]) && wp_verify_nonce($_POST[$this->nonce_import], $this->nonce_import . 'action')) {
            if (isset($_POST[$this->options['id'] . 'import-datas'])) {
                $this->admin_postAction_import();
            } else if (isset($_POST[$this->options['id'] . 'import-test'])) {
                $this->test_sandbox_mode();
                $returnTest = $this->admin_postAction_importTest();
                $this->messages->set_message('importtest_success', ($returnTest ? __('The API works great.', 'wpudisplayinstagram') : __('The API does not work.', 'wpudisplayinstagram')), ($returnTest ? 'updated' : 'error'));
                if (empty($this->user_names)) {
                    $this->messages->set_message('importtest_note_username', __('Please add at least one username.', 'wpudisplayinstagram'), 'error');
                }
            }
            wp_redirect($this->redirect_uri);
            exit();
        }
    }

    private function admin_postAction_importTest($id = false) {
        $_nb_items_test = 1;
        $request_url = $this->get_request_url($id);
        if (!$request_url) {
            return false;
        }

        $_request = wp_remote_get(sprintf($request_url, $_nb_items_test));
        if (is_wp_error($_request)) {
            return false;
        }

        $_body = wp_remote_retrieve_body($_request);
        $_json = json_decode($_body);
        if (!is_object($_json) || !property_exists($_json, 'data') || count($_json->data) != $_nb_items_test) {
            $_error_json = $this->get_json_error_type($_json);
            if ($_error_json == 'OAuthAccessTokenException') {
                /* Reset invalid client token */
                $this->messages->set_message('import_error', __('Invalid access token has been disabled.', 'wpudisplayinstagram'), 'error');
                $this->basesettings->update_setting('client_token', "");
            }
            return false;
        }

        return true;
    }

    private function get_json_error_type($json) {
        if (!is_object($json) || !property_exists($json, 'meta') || !is_object($json->meta) || !property_exists($json->meta, 'error_type')) {
            return '';
        }
        return $json->meta->error_type;
    }

    private function test_sandbox_mode() {
        $sandbox_mode = $this->sandboxmode;
        $test_id = $this->test_user_id;
        $token_parts = explode('.', $this->client_token);
        if (!empty($token_parts) && is_numeric($token_parts[0])) {
            $test_id = $token_parts[0];
        }
        $this->sandboxmode = $this->admin_postAction_importTest($test_id) ? 0 : 1;
        if ($sandbox_mode != $this->sandboxmode) {
            update_option('wpu_get_instagram__sandboxmode', $this->sandboxmode);
        }
    }

    private function admin_postAction_import() {
        $count_import = $this->import();
        if ($count_import === false) {
            $this->messages->set_message('import_error', __('The import has failed.', 'wpudisplayinstagram'), 'updated');
        } else {
            $msg_import = sprintf(__('%s files have been imported.', 'wpudisplayinstagram'), $count_import);
            if ($count_import < 2) {
                $msg_import = sprintf(__('%s file have been imported.', 'wpudisplayinstagram'), $count_import);
            }
            if ($count_import < 1) {
                $msg_import = sprintf(__('No file have been imported.', 'wpudisplayinstagram'), $count_import);
            }
            $this->messages->set_message('import_success', $msg_import, 'updated');
            update_option('wpudisplayinstagram_latestimport', current_time('timestamp', 1));
        }
    }

    public function admin_page() {
        $_plugin_ok = true;
        $api_link = $this->api_domain . 'oauth/authorize/?client_id=' . $this->client_id . '&redirect_uri=' . urlencode($this->redirect_uri) . '&response_type=code';
        $latestimport = get_option('wpudisplayinstagram_latestimport');

        echo '<div class="wrap">';
        echo '<h1>' . get_admin_page_title() . '</h1>';

        settings_errors($this->settings_details['option_id']);

        if (defined('WP_HTTP_BLOCK_EXTERNAL') && WP_HTTP_BLOCK_EXTERNAL) {
            $_plugin_ok = false;
            echo '<div class="notice error"><p>' . __('The WordPress external requests are disabled.', 'wpudisplayinstagram') . '</p></div>';
        }

        if (empty($this->client_token)) {
            $_plugin_ok = false;
            if (!$this->config_ok) {
                echo '<p>' . sprintf(__('Please fill in Config details or create a <a target="_blank" href="%s">new Instagram app</a>', 'wpudisplayinstagram'), $this->register_link) . '</p>';
            } else {
                echo '<p>' . sprintf(__('Please <a href="%s">login here</a>', 'wpudisplayinstagram'), $api_link) . '.</p>';
            }
            echo '<p><strong>' . __('Request URI', 'wpudisplayinstagram') . '</strong> : <span contenteditable>' . $this->redirect_uri . '</span></p>';
        }

        if ($_plugin_ok) {
            $next_scheduled = wp_next_scheduled('wpu_display_instagram__cron_hook');
            if (is_numeric($next_scheduled)) {
                echo '<p>' . sprintf(__('Next import: in %s', 'wpudisplayinstagram'), human_time_diff($next_scheduled)) . '.</p>';
            }

            echo '<form action="' . admin_url('admin-post.php') . '" method="post">';
            echo '<input type="hidden" name="action" value="' . $this->options['plugin_id'] . '" />';
            echo wp_nonce_field($this->nonce_import . 'action', $this->nonce_import);
            echo get_submit_button(__('Import now', 'wpudisplayinstagram'), 'primary', $this->options['id'] . 'import-datas', false) . ' ';
            echo get_submit_button(__('Test import', 'wpudisplayinstagram'), 'secondary', $this->options['id'] . 'import-test', false);
            echo '</form>';

            $wpq_instagram_posts = get_posts(array(
                'posts_per_page' => 5,
                'post_type' => $this->options['post_type'],
                'orderby' => 'post_date',
                'order' => 'DESC',
                'post_status' => 'any',
                'fields' => 'ids'
            ));

            if (!empty($wpq_instagram_posts)) {
                echo '<br /><hr/><h3>' . __('Latest imports', 'wpudisplayinstagram') . '</h3><ul>';
                foreach ($wpq_instagram_posts as $post_id) {
                    echo '<li style="float:left;margin-right:5px;">';
                    echo '<a href="' . get_edit_post_link($post_id) . '">' . get_the_post_thumbnail($post_id, 'thumbnail') . '</a><br />';
                    echo $this->display_author($post_id);
                    echo '</li>';
                }
                echo '</ul><div style="clear: both;"></div>';
            }
            echo '<br /><hr/>';
            wp_reset_postdata();
        }

        // Settings
        echo '<form action="' . admin_url('options.php') . '" method="post">';
        settings_fields($this->settings_details['option_id']);
        do_settings_sections($this->options['plugin_id']);
        echo submit_button(__('Save Changes', 'wpudisplayinstagram'));
        echo '</form>';

        echo '</div>';
    }

    /* ----------------------------------------------------------
      Listing
    ---------------------------------------------------------- */

    public function posts_columns($columns = array()) {
        $columns['instagram_post_tags'] = __('Tags', 'wpudisplayinstagram');
        $columns['instagram_post_username'] = __('Author', 'wpudisplayinstagram');
        return $columns;
    }

    public function display_author($post_id = 1) {
        $fullname = get_post_meta($post_id, 'instagram_post_full_name', true);
        $username = get_post_meta($post_id, 'instagram_post_username', true);
        if (empty($fullname) || empty($username)) {
            return '';
        }
        $external_url = 'https://instagram.com/' . $username;
        $url = admin_url('edit.php?post_type=' . $this->options['post_type'] . '&instagram_post_username=' . esc_attr($username));
        return sprintf('<strong><a href="%s" target="_blank"><span style="text-decoration:none;font-size:1em;vertical-align:middle;" class="dashicons dashicons-format-image"></span></a> %s</strong><br />&rarr; <a href="%s">%s</a>', $external_url, $fullname, $url, $username);
    }

    public function posts_column_content($column_name = '', $post_id = 1) {
        if ('instagram_post_tags' == $column_name) {
            /* Get the genres for the post. */
            $terms = get_the_terms($post_id, $this->options['taxonomy']);

            /* If terms were found. */
            if (!empty($terms)) {

                $out = array();

                /* Loop through each term, linking to the 'edit posts' page for the specific term. */
                foreach ($terms as $term) {
                    $out[] = sprintf('<a href="%s">%s</a>',
                        esc_url(add_query_arg(array('post_type' => $this->options['post_type'], $this->options['taxonomy'] => $term->slug), 'edit.php')),
                        esc_html(sanitize_term_field('name', $term->name, $term->term_id, $this->options['taxonomy'], 'display'))
                    );
                }

                /* Join the terms, separating them with a comma. */
                echo join(', ', $out);
            }

        }
        if ('instagram_post_username' == $column_name) {
            echo $this->display_author($post_id);
        }
    }

    public function sortable_posts_column($columns = array()) {
        $columns['instagram_post_username'] = 'instagram_post_username';
        return $columns;
    }

    public function posts_columns_orderby($query) {
        if (!is_admin()) {
            return;
        }

        // Order by
        $orderby = $query->get('orderby');
        if ('instagram_post_username' == $orderby) {
            $query->set('meta_key', 'instagram_post_username');
            $query->set('orderby', 'meta_value');
        }

    }

    public function filter_admin_results($query) {
        global $pagenow;
        $type = 'post';
        if (isset($_GET['post_type'])) {
            $type = $_GET['post_type'];
        }
        if ($this->options['post_type'] == $type && is_admin() && $pagenow == 'edit.php' && isset($_GET['instagram_post_username']) && $_GET['instagram_post_username'] != '') {
            $query->query_vars['meta_key'] = 'instagram_post_username';
            $query->query_vars['meta_value'] = $_GET['instagram_post_username'];
        }
    }

    /* ----------------------------------------------------------
      Single
    ---------------------------------------------------------- */

    public function add_meta_boxes() {
        add_meta_box('wpu_display_instagram-author', __('Author', 'wpudisplayinstagram'), array(&$this, 'meta_box_callback_author'), $this->options['post_type'], 'side');
    }

    public function meta_box_callback_author($post) {
        if (is_object($post)) {
            echo $this->display_author($post->ID);
        }
    }

    /* ----------------------------------------------------------
      Front
    ---------------------------------------------------------- */

    public function template_redirect() {
        if ($this->display_posts_front) {
            return;
        }

        if (is_singular($this->options['post_type']) || is_post_type_archive($this->options['post_type'])) {
            wp_redirect(home_url());
            die;
        }
    }

    public function embed_oembed_html($html, $url, $attr, $post_id) {
        if (is_admin()) {
            return $html;
        }

        global $wpdb;
        $query_search = "SELECT post_id FROM $wpdb->postmeta WHERE meta_key='instagram_post_link' AND meta_value='%s' LIMIT 0,1";
        $instagram_post = $wpdb->get_var($wpdb->prepare($query_search, $url));

        if (!is_numeric($instagram_post) || !has_post_thumbnail($instagram_post)) {
            return $html;
        }

        /* Remove embedded images */
        $post_content = get_post_field('post_content', $instagram_post);
        $post_content = preg_replace("/<img[^>]+\>/i", "", $post_content);

        $html = '<div class="wpuinstagram-embed">';
        $html .= '<figure>';
        $html .= get_the_post_thumbnail($instagram_post);
        $html .= '<figcaption>' . $post_content . '</figcaption>';
        $html .= '</figure>';
        $html .= '</div>';

        return $html;
    }

    /* ----------------------------------------------------------
      Admin helper
    ---------------------------------------------------------- */

    public function admin_notices() {
        if ($this->is_admin_page() && $this->sandboxmode && $this->config_ok) {
            echo '<div class="notice notice-warning"><p>' . __('Sandbox mode is enabled for this app.', 'wpudisplayinstagram') . ' ' . __('Only the images posted by the signed-in user will be imported.', 'wpudisplayinstagram') . '</p></div>';
        }
        if (!$this->is_admin_page() && empty($this->client_token) && !empty($this->client_id) && !empty($this->client_secret)) {
            echo '<div class="notice notice-warning"><p>' . sprintf(__('The access token for <strong>%s</strong> seems to be expired. Please <a href="%s">renew it here</a>.', 'wpudisplayinstagram'), $this->options['name'], $this->redirect_uri) . '</p></div>';
        }
    }

    /* ----------------------------------------------------------
      Admin
    ---------------------------------------------------------- */

    public function is_admin_page() {
        return (is_admin() && isset($_GET['page']) && $_GET['page'] == $this->options['id']);
    }

    /* ----------------------------------------------------------
      Edit with gmaps autocomplete
    ---------------------------------------------------------- */

    public function set_wpugmapsautocompletebox_posttypes($post_types) {
        $post_types[] = $this->options['post_type'];
        return $post_types;
    }

    public function set_wpugmapsautocompletebox_dim($dim) {
        if (!function_exists('get_current_screen')) {
            return $dim;
        }
        $screen = get_current_screen();
        if (is_object($screen) && $screen->base != 'post' && $screen->id != $this->options['post_type']) {
            $dim['lat']['id'] = 'instagram_post_latitude';
            $dim['lng']['id'] = 'instagram_post_longitude';
        }
        return $dim;
    }

    /* ----------------------------------------------------------
      Debug
    ---------------------------------------------------------- */

    public function debug_log($message) {
        if (!$this->debug) {
            return;
        }
        error_log('[wpudisplayinstagram] ' . $message);
    }

    /* ----------------------------------------------------------
      Activation / Deactivation
    ---------------------------------------------------------- */

    public function refresh() {
        flush_rewrite_rules();
    }

    public function activation() {
        $this->plugins_loaded();
        delete_option('wpu_get_instagram__sandboxmode');
        flush_rewrite_rules();
        $this->basecron->install();
    }

    public function deactivation() {
        $this->plugins_loaded();
        delete_option('wpu_get_instagram__sandboxmode');
        flush_rewrite_rules();
        $this->basecron->uninstall();
    }

    public function uninstall() {

        $this->basecron->uninstall();

        // Delete options
        $options_users = get_option($this->option_user_ids_opt);
        if (is_array($options_users)) {
            foreach ($options_users as $option_user_id) {
                delete_option($option_user_id);
            }
        }
        delete_option($this->option_user_ids_opt);
        delete_option('wpu_get_instagram__sandboxmode');
        delete_option('wpu_get_instagram__client_secret');
        delete_option('wpu_get_instagram__client_token');
        delete_option('wpu_get_instagram__user_id');
        delete_option('wpu_get_instagram__user_name');
        delete_option('wpudisplayinstagram_latestimport');
        delete_option($this->settings_details['option_id']);

        // Delete fields
        delete_post_meta_by_key('instagram_post_username');
        delete_post_meta_by_key('instagram_post_full_name');
        delete_post_meta_by_key('instagram_post_id');
        delete_post_meta_by_key('instagram_post_link');
        delete_post_meta_by_key('instagram_post_datas');
        delete_post_meta_by_key('instagram_post_latitude');
        delete_post_meta_by_key('instagram_post_longitude');
    }

}

$wpu_display_instagram = new wpu_display_instagram();

register_activation_hook(__FILE__, array(&$wpu_display_instagram,
    'activation'
));
register_deactivation_hook(__FILE__, array(&$wpu_display_instagram,
    'deactivation'
));

/* ----------------------------------------------------------
  Widget
---------------------------------------------------------- */

add_action('widgets_init', 'wpudisplayinstagram_register_widgets');
function wpudisplayinstagram_register_widgets() {
    register_widget('wpudisplayinstagram');
}

class wpudisplayinstagram extends WP_Widget {
    public function __construct() {
        parent::__construct(false, '[WPU] Import Instagram', array(
            'description' => 'Import Instagram'
        ));
    }

    public function wpudisplayinstagram_getnbitems($instance) {
        return isset($instance['nb_items']) && is_numeric($instance['nb_items']) ? $instance['nb_items'] : 5;
    }

    public function wpudisplayinstagram_defaultloopcontent($str, $wpq_instagram_posts) {
        ob_start();
        echo '<ul class="wpu-display-instagram__list">';
        while ($wpq_instagram_posts->have_posts()) {
            $wpq_instagram_posts->the_post();
            echo '<li class="instagram-item">';
            echo '<a class="instagram-link" target="_blank" href="' . get_post_meta(get_the_ID(), 'instagram_post_link', 1) . '">';
            the_post_thumbnail();
            echo '</a>';
            echo '</li>';
        }
        echo '</ul>';
        return ob_get_clean();
    }

    public function form($instance) {
        load_plugin_textdomain('wpudisplayinstagram', false, dirname(plugin_basename(__FILE__)) . '/lang/');
        $nb_items = $this->wpudisplayinstagram_getnbitems($instance);
        ?>
        <p>
        <label for="<?php echo $this->get_field_id('nb_items'); ?>"><?php _e('Number of pictures displayed:', 'wpudisplayinstagram');?></label>
        <input class="widefat" id="<?php echo $this->get_field_id('nb_items'); ?>" name="<?php echo $this->get_field_name('nb_items'); ?>" type="number" value="<?php echo esc_attr($nb_items); ?>">
        </p>
        <?php
}

    public function update($new_instance, $old_instance) {
        return array(
            'nb_items' => $this->wpudisplayinstagram_getnbitems($new_instance)
        );
    }

    public function widget($widget_args = array(), $instance = array()) {
        $nb_items = $this->wpudisplayinstagram_getnbitems($instance);
        global $wpu_display_instagram;
        echo $widget_args['before_widget'];
        $wpq_instagram_posts = new WP_Query(array(
            'posts_per_page' => $nb_items,
            'post_type' => $wpu_display_instagram->options['post_type'],
            'orderby' => 'ID',
            'order' => 'DESC',
            'post_status' => 'any',
            'cache_results' => true
        ));
        add_filter('wpudisplayinstagram_loopcontent', array(&$this, 'wpudisplayinstagram_defaultloopcontent'), 1, 2);

        if ($wpq_instagram_posts->have_posts()) {
            echo apply_filters('wpudisplayinstagram_loopcontent', '', $wpq_instagram_posts);
        }
        wp_reset_postdata();
        echo $widget_args['after_widget'];
    }
}
