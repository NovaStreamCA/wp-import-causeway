<?php
/**
 * Plugin Name: Causeway Importer
 * Plugin URI: http://causeway.novastream.ca
 * Description: Import all approved listings from the Causeway backend into WordPress to display on your site.
 * Version: 1.0.0
 * Requires at least: 4.8
 * Requires PHP: 7.0
 * Author: Matthew Lewis
 * Author URI: http://matthewlewis.ca
 * License: GPL2
 */

define('CAUSEWAY_PLUGIN_INTERNAL_NAME', 'causeway-import');
define('CAUSEWAY_PLUGIN_NAME', 'Causeway Importer');
define('CAUSEWAY_PLUGIN_NAME_SHORT', 'Causeway');
define('CAUSEWAY_BACKEND_IMPORT_URL', 'https://causeway3.novastream.dev/export');
define('STARTING_IMPORT_ID', 10000);

$slugToName = array(
    'things-to-do' => 'Things to Do',
    ''
);

$causeway = new Causeway();

class Causeway {
    private $listingTaxonomies = array(
        // SINGLE LABEL => PLURAL LABEL
        'Provider' => 'Providers',
        'Type' => 'Types',
        'Status' => 'Statuses',
        'Community' => 'Communities',
        'County' => 'Counties',
        'Area' => 'Areas',
        'Featured' => 'Featured',
        'Sponsored' => 'Sponsored',
        'Category' => 'Categories',
        'Tag' => 'Tags',
    );
    private $listingNoColumnTaxonomies = array('Area', 'County');

    function __construct() {
        register_activation_hook(__FILE__, array($this, 'onActivate'));
        register_deactivation_hook(__FILE__, array($this, 'onDeactivate'));

        add_action('init', array($this, 'onInit'));
        add_action( 'admin_menu', array($this, 'adminMenu'));
    }
    /****************************************************************************************************/
    public function onActivate() {
        //add_action('generate_rewrite_rules', array($this, 'generateRewriteRules'));
        flush_rewrite_rules();
    }
    /****************************************************************************************************/
    public function onDeactivate() {
        flush_rewrite_rules();
    }
    /****************************************************************************************************/
    public function onInit() {
        $this->registerPostType();

    }
    /****************************************************************************************************/
    public function adminMenu() {
        add_menu_page(
            __(CAUSEWAY_PLUGIN_NAME_SHORT, 'textdomain'),
            CAUSEWAY_PLUGIN_NAME_SHORT,
            'manage_options',
            CAUSEWAY_PLUGIN_INTERNAL_NAME,
            array($this, 'adminOptionsPage'),
            plugin_dir_url(__FILE__) . '/images/logo.png',
            6
        );
    }
    /****************************************************************************************************/
    public function adminOptionsPage() {
        global $wpdb;

        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have sufficient permissions to access this page.', CAUSEWAY_PLUGIN_INTERNAL_NAME));
        }


        if (isset($_POST['causeway-import'])) {
            $endpoint = empty(get_option('causeway-url')) ? CAUSEWAY_BACKEND_IMPORT_URL : get_option('causeway-url');
            $json = $this->importJson(get_option('causeway-key'), boolval(get_option('causeway-import')), $endpoint);

            if ($json == false || is_string($json)) {
                echo sprintf('<p class="error-message" style="font-size: 20px;">%s</p>', ($json === false ? 'Error connecting to Causeway. Please contact support.' : $json));
            } elseif (is_array($json)) {
                $count = 0;
                $activePostIds = array();
                $errorListingIds = array();
                $messages = array();

                /* Create any category terms available to this server */
                if (!empty($json['serverCategories'])) {
                    foreach ($json['serverCategories'] as $category) {
                        if (empty($category['description'])) {
                            $category['description'] = sprintf('%s (Type: %s)', $category['categoryName'], $category['typeName']);
                        }

                        $term = get_term_by('slug', $category['termName'], 'listing-category');

                        #var_dump($term, $category);

                        if ($term === false) {
                            wp_insert_term($category['categoryName'], 'listing-category', array(
                                'description' => $category['description'],
                                'slug' => $category['termName'],
                            ));
                        } else {
                            wp_update_term($term->term_id, 'listing-category', array(
                                'description' => $category['description'],
                                'slug' => $category['termName'],
                            ));

                            if (get_option('causeway-allow-category-rename') != true) {
                                wp_update_term($term->term_id, 'listing-category', array(
                                    'name' => $category['categoryName'],
                                ));
                            }
                        }
                    }
                }

                $currentTimeLimit = ini_get('max_execution_time');
                set_time_limit(0);

                if (!empty($json['results'])) {
                    foreach ($json['results'] as $listing) {
                        $postId = $this->generatePost($listing, $messages);
                        if ($postId === false) {
                            $errorListingIds[] = $listing['id'];
                        } else {
                            $count++;
                            $activePostIds[] = $postId;
                        }
                    }
                }

                if (!empty($json['unchanged'])) {
                    foreach ($json['unchanged'] as $listing) {
                        $activePostIds[] = $this->getPostIdByMeta('CausewayId', $listing);
                    }
                }

                $activePostIds = array_filter($activePostIds);

                echo '<h2>Import Status</h2>';
                foreach ($messages as $message) {
                    echo sprintf('%s<br>', $message);
                }
                $deleted = $this->deleteInactivePosts($activePostIds, $errorListingIds);

                set_time_limit($currentTimeLimit);

                echo '<p>Finished importing <strong>' . $count . '</strong> listing(s) from Causeway. Deleted <strong>' . $deleted . '</strong> listing(s) from WordPress.</p>';
            } else {
                echo '<p>Unable to parse the feed from Causeway backend. Please contact support.</p>';
            }
        }

        include (sprintf('%s/settings.php', dirname(__FILE__)));
    }
    /****************************************************************************************************/
    private function generatePost($listing, &$messages = array()) {
        global $wpdb;
        if (!function_exists('download_url')) {
            require_once(ABSPATH . 'wp-admin/includes/media.php');
            require_once(ABSPATH . 'wp-admin/includes/file.php');
            require_once(ABSPATH . 'wp-admin/includes/image.php');
        }

        $fAutoPublish = true;
        $fNewEntry = true;

        $postCausewayId = intval($listing['id']);
        $postIsFeatured = boolval($listing['isFeatured']) === true ? 'Yes' : 'No';
        $postIsSponsored = boolval($listing['isSponsored']) === true ? 'Yes' : 'No';
        $post['post_title'] = $listing['name'];
        $post['post_content'] = $listing['description'];
        $post['post_date'] = $listing['dateAdded'];
        $post['post_date_gmt'] = get_gmt_from_date($listing['dateAdded']);
        // $post['post_modified'] = $listing['dateUpdated'];
        // $post['post_modified_gmt'] = get_gmt_from_date($listing['dateUpdated']);
        $post['import_id'] = STARTING_IMPORT_ID + $postCausewayId;

        $meta = $wpdb->get_row("SELECT post_id FROM $wpdb->postmeta WHERE meta_key = 'causewayId' AND meta_value = '{$postCausewayId}'");

        if (!is_null($meta)) {
            $fNewEntry = false;
            $post['ID'] = $meta->post_id;
        } else {
        }

        $post['post_status'] = ($fAutoPublish ? 'publish' : 'private');
        $post['comment_status'] = 'closed';
        $post['post_type'] = 'listings';

        $post['tax_input'] = array(
            'listing-provider' => $listing['provider'],
            'listing-status' => $listing['status'],
            'listing-featured' => $postIsFeatured,
            'listing-sponsored' => $postIsSponsored,
            'listing-community' => $listing['location']['community'],
            'listing-county' => $listing['location']['county'],
            'listing-area' => $listing['location']['area'],
        );

        $post['tax_input']['listing-type'] = array();
        foreach ($listing['types'] as $typeId => $typeName) {
            if (is_array($typeName)) {
                foreach ($typeName as $name) {
                    $post['tax_input']['listing-type'][] = $name;
                }
            } else {
                $post['tax_input']['listing-type'][] = $typeName;
            }
        }

        $post['tax_input']['listing-category'] = array();
        foreach ($listing['categories'] as $listingType => $category) {
            if (is_array($category)) {
                foreach ($category as $value) {
                    $post['tax_input']['listing-category'][] = $value['slug'];
                }
            } else {
                $post['tax_input']['listing-category'][] = $category['slug'];
            }
        }

        $post['tax_input']['listing-tag'] = array();
        foreach ($listing['tags'] as $tag) {
            if (is_array($tag)) {
                foreach ($tag as $key => $value) {
                    if ($key != 'slug') {
                        continue;
                    }
                    $post['tax_input']['listing-tag'][] = $value;
                }
            } else {
                $post['tax_input']['listing-tag'][] = $tag['slug'];
            }
        }

        $post['ID'] = wp_insert_post($post, true);
        if (is_wp_error($post['ID']) || $post['ID'] === 0) {
            $messages[] = sprintf('Error occurred during post creation: %s', $post['ID']->get_error_message());
            return false;
        }

        foreach ($post['tax_input'] as $tax => $term) {
            if (is_array($term)) {
                $fAppend = false;
                foreach ($term as $t) {
                    if (!term_exists($t, $tax)) {
                        wp_insert_term($t, $tax, array(
                            'description'=> '',
                            'slug' => sanitize_title($t),
                            'parent'=> 0
                        ));
                    }
                    wp_set_object_terms($post['ID'], $t, $tax, $fAppend);
                    $fAppend = true;
                }
                wp_update_term_count($t, $tax, true);
            } else {
                if (!term_exists($term, $tax)) {
                    wp_insert_term($term, $tax, array(
                        'description'=> '',
                        'slug' => sanitize_title($term),
                        'parent'=> 0
                    ));
                }
                wp_set_object_terms($post['ID'], $term, $tax, false);
                wp_update_term_count($term, $tax, true);
            }
        }

        update_post_meta($post['ID'], 'causewayId', $postCausewayId);
        $oldChecksum = get_post_meta($post['ID'], 'defaultPhotoChecksum', true);

        if (empty($listing['checksum'])) {
            $listing['checksum'] = null;
        }

        if (($oldChecksum != $listing['checksum']) || $fNewEntry) {
            $messages[] = sprintf('New "Default" Photo selected (<strong>%s</strong> from Causeway not the same as current <strong>%s</strong> stored in WordPress.)', (empty($oldChecksum) ? 'N/A' : $oldChecksum), $listing['checksum']);
            $attachmentTitle = sprintf('%s photo for %s', 'Default', $listing['name']);

            if (!empty($listing['photo'])) {
                add_filter( 'http_request_host_is_external', '__return_true' );
                $attachmentId = media_sideload_image($listing['photo'], $post['ID'], $attachmentTitle, 'id');
                remove_filter( 'http_request_host_is_external', '__return_true' );

                if (!empty($attachmentId) && !is_wp_error($attachmentId)) {
                    set_post_thumbnail($post['ID'], $attachmentId);
                    $attachmentFilePath = get_attached_file($attachmentId);
                    $checksumFile = sha1_file($attachmentFilePath);
                    update_post_meta($post['ID'], 'defaultPhotoChecksum', $checksumFile);
                    update_post_meta($post['ID'], 'defaultPhotoUrl', wp_get_attachment_url($attachmentId));
                    update_post_meta($post['ID'], 'defaultPhotoPath', $attachmentFilePath);
                } else {
                    $messages[] = sprintf('Unable to import photo from %s for <strong>%s</strong>. Error: "%s"', $listing['photo'], $listing['name'], $attachmentId->get_error_message());
                }
            } else {
                $messages[] = sprintf('"Default" Photo entry missing from Causeway for %s ', $listing['name']);
            }
        }

        if (!$fNewEntry) {
            $skipDeleteKeys = array('causewayId', 'defaultPhotoChecksum', 'defaultPhotoUrl', 'defaultPhotoPath');
            foreach (get_post_custom_keys($post['ID']) as $index => $key) {
                if ('_' == $key[0] || in_array($key, $skipDeleteKeys)) { continue; }
                delete_post_meta($post['ID'], $key);
            }
        }

        $attributeKeys = array('photo', 'checksum', 'websites', 'contacts', 'attachments');
        foreach ($attributeKeys as $key) {
            if (!empty($listing[$key])) {
                if (is_array($listing[$key])) {
                    $x = 0;

                    foreach ($listing[$key] as $a => $b) {
                        if (is_array($b)) {
                            foreach ($b as $value) {
                                $metaKey = sprintf('%s_%d', $key, $x);
                                if (is_array($value)) {
                                    update_post_meta($post['ID'], $metaKey . '_key', $a);
                                    foreach ($value as $k => $v) {
                                        update_post_meta($post['ID'], $metaKey . '_' . $k, $v);
                                    }

                                } else {
                                    update_post_meta($post['ID'], $metaKey . '_key', $a);
                                    update_post_meta($post['ID'], $metaKey . '_value', $value);
                                }

                                if ($metaKey === 'General') {
                                    update_post_meta($post['ID'], 'homepage', $value);
                                }
                                $x++;
                            }
                        }
                    }

                    update_post_meta($post['ID'], $key . '_count', $x);
                }
            }
        }

        $skipKeys = array('categories', 'types', 'location', 'photo', 'id');
        foreach (array_keys($listing) as $key) {
            if (in_array($key, $skipKeys)) {
                continue;
            }

            if (is_array($listing[$key])) {
                update_post_meta($post['ID'], $key, json_encode($listing[$key]));
            } else {
                update_post_meta($post['ID'], $key, $listing[$key]);
            }
        }

        update_post_meta($post['ID'], 'isFeatured', $postIsFeatured);
        update_post_meta($post['ID'], 'isSponsored', $postIsSponsored);

        foreach (array_keys($listing['location']) as $key) {
            update_post_meta($post['ID'], 'location_' . $key, $listing['location'][$key]);
        }

        $messages[] = sprintf('Listing <strong>%s</strong> has been successfully <strong>%s</strong>.', $listing['name'], ($fNewEntry ? 'added' : 'updated'));
        return $post['ID'];
    }
    /****************************************************************************************************/
    private function registerPostType() {
        $taxLabels = array();
        $listingLabels = array(
            'name' => __('Listings', CAUSEWAY_PLUGIN_INTERNAL_NAME),
            'singular_name' => __('Listing', CAUSEWAY_PLUGIN_INTERNAL_NAME),
            'menu_name' => __('Listings', CAUSEWAY_PLUGIN_INTERNAL_NAME),
            'name_admin_bar' => __('Listings', CAUSEWAY_PLUGIN_INTERNAL_NAME),
            'add_new' => __('Add New', CAUSEWAY_PLUGIN_INTERNAL_NAME),
            'add_new_item' => __('Add New Listing', CAUSEWAY_PLUGIN_INTERNAL_NAME),
            'new_item' => __('New Listing', CAUSEWAY_PLUGIN_INTERNAL_NAME),
            'edit_item' => __('Edit Listing', CAUSEWAY_PLUGIN_INTERNAL_NAME),
            'view_item' => __('View Listing', CAUSEWAY_PLUGIN_INTERNAL_NAME),
            'all_items' => __('All Listings', CAUSEWAY_PLUGIN_INTERNAL_NAME),
            'search_items' => __('Search Listings', CAUSEWAY_PLUGIN_INTERNAL_NAME),
            'not_found' => __('No listings found.', CAUSEWAY_PLUGIN_INTERNAL_NAME),
            'not_found_in_trash' => __('No listings found in Trash.', CAUSEWAY_PLUGIN_INTERNAL_NAME)
        );

        #$postSupport =;

        $return = register_post_type('listings', array(
            'label' => 'Listings',
            'labels' => $listingLabels,
            'has_archive' => 'listings',
            'supports' =>  array('title', 'editor', 'custom-fields', 'excerpt', 'page-attributes', 'thumbnail'),
            'public' => true,
            'show_ui' => true,
            'show_in_menu' => true,
            'show_in_admin_bar' => true,
            'query_var' => true,
            'has_archive' => true,
            'rewrite' => array('slug' => 'listings', 'with_front' => false),
            //'menu_icon' => plugin_dir_url(__FILE__) . '/images/wp_marcato_logo.png',
            //'taxonomies' => $postTaxonomies[$customTypePost]//array('category', 'post_tag')
        ));
        #var_dump(add_post_type_support('listings', $postSupport));

        foreach ($this->listingTaxonomies as $singleLabel => $multipleLabel) {
            $taxonomy = 'listing-' . strtolower($singleLabel);
            $taxLabels[$taxonomy] = array(
                'name' => _x( $multipleLabel, 'Taxonomy General Name', 'text_domain' ),
                'singular_name' => _x( $singleLabel, 'Taxonomy Singular Name', 'text_domain' ),
                'menu_name' => __( $singleLabel, 'text_domain' ),
                'all_items' => __( 'All ' . $multipleLabel, 'text_domain' ),
                'parent_item' => __( 'Parent ' . $singleLabel, 'text_domain' ),
                'parent_item_colon' => __( 'Parent '  . $singleLabel . ':', 'text_domain' ),
                'new_item_name' => __( 'New ' . $singleLabel, 'text_domain' ),
                'add_new_item' => __( 'Add New ' . $singleLabel, 'text_domain' ),
                'edit_item' => __( 'Edit ' . $singleLabel, 'text_domain' ),
                'update_item' => __( 'Update ' . $singleLabel, 'text_domain' ),
                'view_item' => __( 'View ' . $singleLabel, 'text_domain' ),
            );

            register_taxonomy($taxonomy, 'listings', array(
                'labels' => $taxLabels[$taxonomy],
                'hierarchical' => false,
                'public' => true,
                'show_ui' => true,
                'show_admin_column' => !in_array($singleLabel, $this->listingNoColumnTaxonomies, true),
                'show_in_nav_menus' => true,
                'show_tagcloud' => false,
                'query_var' => $taxonomy,
                'rewrite' => true
            ));

            register_taxonomy_for_object_type($taxonomy, 'listings');
        }


    }
    /****************************************************************************************************/
    private function importJson($secretKey, $forceReimport = true, $endpoint = CAUSEWAY_BACKEND_IMPORT_URL) {
        $serverName = str_replace(array('www.', 'http://', 'https://'), array('', '', ''), $_SERVER['HTTP_HOST']);
        $jsonUrl = sprintf('%s/%s?&server=%s&forceReimport=%d', $endpoint, $secretKey, $serverName, $forceReimport);
        if (!empty($dateImport)) {
            $jsonUrl .= '&date=' . strip_tags($dateImport);
        }

        if (defined('WP_DEBUG')) {
            echo '<p>Using URL: <strong><a rel="noopener" target="_blank" href="' . $jsonUrl . '">' . $jsonUrl . '</a></strong> for import.<br><br></p>';
        }
        if (function_exists('curl_init')) {
            $curl = curl_init($jsonUrl);
            curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($curl, CURLOPT_REFERER, $serverName);
            $curlResult = curl_exec($curl);
            curl_close($curl);

            if (empty($curlResult)) {
                return false;
            }

            $jsonData = json_decode($curlResult, true);
            return json_last_error() === JSON_ERROR_NONE ? $jsonData : $curlResult;
        } elseif (ini_get('allow_url_fopen') == true) {
            $opts = array(
                'http' => array(
                    'header' => array('Referer: ' . $serverName . "\r\n")
                )
            );
            $context = stream_context_create($opts);
            $contents = file_get_contents($jsonUrl, false, $context);
            if (empty($contents)) {
                return false;
            }

            $jsonData = json_decode($curlResult, true);
            return json_last_error() === JSON_ERROR_NONE ? $jsonData : $curlResult;
        } else {
            return false;
        }
    }
    /****************************************************************************************************/
    private function deleteInactivePosts($activePostIds, $errorListingIds) {
        global $wpdb;

        $count = 0;

        $metadata = $wpdb->get_results("SELECT post_id FROM $wpdb->postmeta WHERE meta_key = 'causewayId'", ARRAY_A);

        $postIds = array();
        if (!empty($metadata)) {
            foreach ($metadata as $meta) {
                $postIds[] = intval($meta['post_id']);
            }
        }

        $deletePostIds = array_diff($postIds, $activePostIds);

        foreach ($deletePostIds as $postId) {
            $attachmentId = get_post_thumbnail_id($postId);
            delete_post_thumbnail($postId);
            wp_delete_attachment($attachmentId, true);

            if (wp_delete_post($postId, true) == true) {
                $count++;
            }
        }

        return $count;
    }
    /****************************************************************************************************/
    private function getPostIdByMeta($metaKey, $metaValue) {
        global $wpdb;

        $postId = $wpdb->get_var( $wpdb->prepare("SELECT post_id FROM $wpdb->postmeta WHERE meta_value = %s AND meta_key = %s ORDER BY post_id DESC", $metaValue, $metaKey));

        return $postId;
    }
}
