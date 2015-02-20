<?php

/*
Plugin Name: WPU Display Instagram
Description: Displays the latest image for an Instagram account
Version: 0.6
Author: Darklg
Author URI: http://darklg.me/
License: MIT License
License URI: http://opensource.org/licenses/MIT
*/

class wpu_display_instagram
{

    private $notices_categories = array(
        'updated',
        'update-nag',
        'error'
    );

    function __construct() {

        $this->options = array(
            'id' => 'wpu-display-instagram',
            'name' => 'Display Instagram'
        );

        add_filter('wpu_options_tabs', array(&$this,
            'options_tabs'
        ) , 10, 3);
        add_filter('wpu_options_boxes', array(&$this,
            'options_boxes'
        ) , 12, 3);
        add_filter('wpu_options_fields', array(&$this,
            'options_fields'
        ) , 12, 3);
        add_filter('init', array(&$this,
            'init'
        ));
        add_action('init', array(&$this,
            'check_dependencies'
        ));
        add_action('init', array(&$this,
            'register_post_types'
        ));
        add_action('admin_init', array(&$this,
            'set_token'
        ));
        add_action('admin_init', array(&$this,
            'admin_import_postAction'
        ));
        add_action('admin_menu', array(&$this,
            'add_menu_page'
        ));

        // Display notices
        add_action('admin_notices', array(&$this,
            'admin_notices'
        ));
    }

    function init() {
        global $current_user;
        $this->transient_prefix = $this->options['id'] . $current_user->ID;
        $this->nonce_import = $this->options['id'] . '__nonce_import';

        // Instagram config
        $this->client_token = trim(get_option('wpu_get_instagram__client_token'));
        $this->client_id = trim(get_option('wpu_get_instagram__client_id'));
        $this->client_secret = trim(get_option('wpu_get_instagram__client_secret'));
        $this->user_id = trim(get_option('wpu_get_instagram__user_id'));

        // Admin URL
        $this->redirect_uri = admin_url('admin.php?page=' . $this->options['id']);

        // Transient
        $this->transient_id = $this->transient_prefix . '__json_instagram_' . $this->user_id;
        $this->transient_msg = $this->transient_prefix . '__messages';
    }

    function check_dependencies() {
        include_once (ABSPATH . 'wp-admin/includes/plugin.php');

        // Check for Plugins activation
        $this->plugins = array(
            'wpuoptions' => array(
                'installed' => true,
                'path' => 'wpuoptions/wpuoptions.php',
                'message_url' => '<a target="_blank" href="https://github.com/WordPressUtilities/wpuoptions">WPU Options</a>',
            )
        );
        foreach ($this->plugins as $id => $plugin) {
            if (!is_plugin_active($plugin['path'])) {
                $this->plugins[$id]['installed'] = false;
                $this->set_message($id . '__not_installed', sprintf('The plugin %s should be installed.', $plugin['message_url']) , 'error');
            }
        }
    }

    /* ----------------------------------------------------------
      API
    ---------------------------------------------------------- */

    function set_token() {

        if (!is_admin() || !isset($_GET['page']) || $_GET['page'] != $this->options['id'] || !isset($_GET['code'])) {
            return;
        }

        $url = 'https://api.instagram.com/oauth/access_token';
        $request = new WP_Http;
        $result = wp_remote_post($url, array(
            'body' => array(
                'client_id' => $this->client_id,
                'client_secret' => $this->client_secret,
                'grant_type' => 'authorization_code',
                'redirect_uri' => $this->redirect_uri,
                'code' => $_GET['code'],
            )
        ));

        $token = '';
        $response = '{}';
        if (!isset($result['body'])) {
            $this->set_message('token_no_body', 'The response from Instagram is invalid.', 'error');
            return;
        }
        $response = json_decode($result['body']);

        if (!isset($response->access_token)) {
            $this->set_message('token_no_token', 'The access token from Instagram could not be retrieved.', 'error');
            return;
        }

        $this->user_id = $response->user->id;
        $this->client_token = $response->access_token;

        update_option('wpu_get_instagram__client_token', $this->client_token);
        update_option('wpu_get_instagram__user_id', $this->user_id);

        $this->set_message('token_success', 'The token have been successfully imported.', 'updated');
        wp_redirect($this->redirect_uri);
        exit();
    }

    function import() {
        if (empty($this->client_id)) {
            $this->set_token();
        }

        $nb_items = 10;
        $imported_items = $this->get_imported_items();
        $request_url = 'https://api.instagram.com/v1/users/' . $this->user_id . '/media/recent/?count=' . $nb_items . '&access_token=' . $this->client_token;

        // Get cached JSON
        $json_instagram = get_transient($this->transient_id);
        if (empty($json_instagram)) {
            $json_instagram = file_get_contents($request_url);
            set_transient($this->transient_id, $json_instagram, HOUR_IN_SECONDS);
        }

        // Extract and return informations
        $imginsta = json_decode($json_instagram);

        if (!is_array($imginsta->data)) {
            $this->set_message('no_array_insta', 'The datas sent by Instagram are invalid.', 'error');
            return;
        }

        // Import each post if not in database
        $count = 0;
        foreach ($imginsta->data as $item) {
            $datas = $this->get_datas_from_item($item);
            if (!in_array($datas['id'], $imported_items)) {
                $count++;
                $this->import_item($datas, $item);
            }
        }
        return $count;
    }

    /* ----------------------------------------------------------
      Import functions
    ---------------------------------------------------------- */

    function import_item($datas, $original_item) {

        // Create a new post
        $post_id = wp_insert_post(array(
            'post_title' => $datas['caption'],
            'post_content' => '',
            'guid' => sanitize_title($datas['caption'], 'Instagram post') ,
            'post_status' => 'publish',
            'post_date' => date('Y-m-d H:i:s', $datas['created_time']) ,
            'post_author' => 1,
            'post_type' => 'instagram_posts'
        ));

        // Save postid
        update_post_meta($post_id, 'instagram_post_id', $datas['id']);
        update_post_meta($post_id, 'instagram_post_link', $datas['link']);

        // Add required classes
        require_once (ABSPATH . 'wp-admin/includes/media.php');
        require_once (ABSPATH . 'wp-admin/includes/file.php');
        require_once (ABSPATH . 'wp-admin/includes/image.php');

        // Import image as an attachment
        $image = media_sideload_image($datas['image'], $post_id, $datas['caption']);

        // then find the last image added to the post attachments
        $attachments = get_posts(array(
            'numberposts' => 1,
            'post_parent' => $post_id,
            'post_type' => 'attachment',
            'post_mime_type' => 'image'
        ));

        // set image as the post thumbnail
        if (sizeof($attachments) > 0) {
            set_post_thumbnail($post_id, $attachments[0]->ID);
        }
    }

    function get_imported_items() {
        $ids = array();
        $wpq_instagram_posts = new WP_Query(array(
            'posts_per_page' => 100,
            'post_type' => 'instagram_posts'
        ));
        if ($wpq_instagram_posts->have_posts()) {
            while ($wpq_instagram_posts->have_posts()) {
                $wpq_instagram_posts->the_post();
                $ids[] = get_post_meta(get_the_ID() , 'instagram_post_id', 1);
            }
        }
        wp_reset_postdata();
        return $ids;
    }

    function get_datas_from_item($details) {
        $datas = array(
            'image' => '',
            'link' => '#',
            'created_time' => '0',
            'caption' => '',
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

        // Created time
        if (isset($details->created_time)) {
            $datas['created_time'] = $details->created_time;
        }

        // Caption
        if (isset($details->caption->text)) {
            $datas['caption'] = $details->caption->text;
        }

        return $datas;
    }

    /* ----------------------------------------------------------
      Post type
    ---------------------------------------------------------- */

    function register_post_types() {
        register_post_type('instagram_posts', array(
            'public' => true,
            'label' => 'Instagram posts',
            'supports' => array(
                'title',
                'editor',
                'thumbnail'
            )
        ));
    }

    /* ----------------------------------------------------------
      Admin page
    ---------------------------------------------------------- */

    function add_menu_page() {
        if ($this->plugins['wpuoptions']['installed']) {
            add_menu_page($this->options['name'], $this->options['name'], 'manage_options', $this->options['id'], array(&$this,
                'admin_page'
            ) , 'dashicons-admin-generic');
        }
    }

    function admin_import_postAction() {
        if (isset($_POST[$this->nonce_import]) && wp_verify_nonce($_POST[$this->nonce_import], $this->nonce_import . 'action')) {

            $count_import = $this->import();
            if ($count_import === false) {
                $this->set_message('import_error', 'The import has failed.', 'updated');
            } else {
                $this->set_message('import_success', sprintf('%s files have been imported.', $count_import) , 'updated');
            }

            wp_redirect($this->redirect_uri);
            exit();
        }
    }

    function admin_page() {
        $_plugin_ok = true;
        echo '<div class="wrap">';
        echo '<h2>' . $this->options['name'] . '</h2>';

        if (empty($this->client_token)) {
            $_plugin_ok = false;
            if (empty($this->client_id) || empty($this->client_secret) || empty($this->redirect_uri)) {
                echo '<p>Please fill in <a href="' . admin_url('admin.php?page=wpuoptions-settings&tab=instagram_tab') . '">Config details</a> or create a <a target="_blank" href="https://instagram.com/developer/clients/register/">new Instagram app</a></p>';
            } else {
                echo '<p>Please <a href="https://api.instagram.com/oauth/authorize/?client_id=' . $this->client_id . '&redirect_uri=' . urlencode($this->redirect_uri) . '&response_type=code">login here</a>.</p>';
            }
            echo '<p><strong>Request URI</strong> : <span contenteditable>' . $this->redirect_uri . '</span></p>';
        } else {
            echo '<p>The plugin is configured !</p>';
        }

        if ($_plugin_ok) {

            echo '<form action="' . $this->redirect_uri . '" method="post">
            ' . wp_nonce_field($this->nonce_import . 'action', $this->nonce_import) . '
                <p>
                    ' . get_submit_button('Import now', 'primary', $this->options['id'] . 'import-datas') . '
                </p>
            </form>';
        }

        echo '</div>';
    }

    /* ----------------------------------------------------------
      Options for config
    ---------------------------------------------------------- */

    function options_tabs($tabs) {
        $tabs['instagram_tab'] = array(
            'name' => 'Plugin : Display Instagram',
        );
        return $tabs;
    }

    function options_boxes($boxes) {
        $boxes['instagram_config'] = array(
            'tab' => 'instagram_tab',
            'name' => 'Display Instagram'
        );
        return $boxes;
    }

    function options_fields($options) {
        $options['wpu_get_instagram__client_id'] = array(
            'label' => 'Client ID',
            'box' => 'instagram_config'
        );
        $options['wpu_get_instagram__client_secret'] = array(
            'label' => 'Client Secret',
            'box' => 'instagram_config'
        );
        $options['wpu_get_instagram__client_token'] = array(
            'label' => 'Access token',
            'box' => 'instagram_config'
        );
        $options['wpu_get_instagram__user_id'] = array(
            'label' => 'User ID',
            'box' => 'instagram_config'
        );
        return $options;
    }

    /* ----------------------------------------------------------
      WordPress Utilities
    ---------------------------------------------------------- */

    /* Set notices messages */
    private function set_message($id, $message, $group = '') {
        $messages = (array)get_transient($this->transient_msg);
        if (!in_array($group, $this->notices_categories)) {
            $group = $this->notices_categories[0];
        }
        $messages[$group][$id] = $message;
        set_transient($this->transient_msg, $messages);
    }

    /* Display notices */
    function admin_notices() {
        $messages = (array)get_transient($this->transient_msg);
        if (!empty($messages)) {
            foreach ($messages as $group_id => $group) {
                if (is_array($group)) {
                    foreach ($group as $message) {
                        echo '<div class="' . $group_id . '"><p>' . $message . '</p></div>';
                    }
                }
            }
        }

        // Empty messages
        delete_transient($this->transient_msg);
    }
}

$wpu_display_instagram = new wpu_display_instagram();

// add_action('wp_loaded', 'instagram_import');
// function instagram_import() {
//     global $wpu_display_instagram;
//     $wpu_display_instagram->import();
// }
