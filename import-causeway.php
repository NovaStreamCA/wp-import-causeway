<?php
/**
 * Plugin Name: Causeway Importer
 * Plugin URI: https://causewayapp.ca
 * Description: Import all approved listings from the Causeway backend into WordPress to display on your site.
 * Version: 1.0.9
 * Requires at least: 4.8
 * Requires PHP: 7.1
 * Author: NovaStream
 * Author URI: https://novastream.ca
 * License: GPL2
 */

define('CAUSEWAY_PLUGIN_INTERNAL_NAME', 'causeway-import');
define('CAUSEWAY_PLUGIN_NAME', 'Causeway Importer');
define('CAUSEWAY_PLUGIN_NAME_SHORT', 'Causeway');
define('CAUSEWAY_BACKEND_IMPORT_URL', 'https://causewayapp.ca/export');
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
        wp_clear_scheduled_hook('cron_import_causeway_morning');
        wp_clear_scheduled_hook('cron_import_causeway_afternoon');
    }
    /****************************************************************************************************/
    public function onInit() {
        $this->registerPostType();

        add_action('cron_import_causeway', array($this, 'importJson'));

        // 13:00:00 UTC is 10am Atlantic
        if (! wp_next_scheduled ( 'cron_import_causeway_morning' )) {
            wp_schedule_event(strtotime('13:00:00'), 'daily', 'cron_import_causeway');
        }


        // 19:00:00 UTC is 4pm Atlantic
        if (! wp_next_scheduled ( 'cron_import_causeway_afternoon' )) {
            wp_schedule_event(strtotime('19:00:00'), 'daily', 'cron_import_causeway');
        }
    }
    /****************************************************************************************************/
    public function adminMenu() {
        add_menu_page(
            __(CAUSEWAY_PLUGIN_NAME_SHORT, 'textdomain'),
            CAUSEWAY_PLUGIN_NAME_SHORT,
            'edit_posts',
            CAUSEWAY_PLUGIN_INTERNAL_NAME,
            array($this, 'adminOptionsPage'),
            plugin_dir_url(__FILE__) . '/images/logo.png',
            6
        );
    }
    /****************************************************************************************************/
    public function adminOptionsPage() {
        global $wpdb;

        if (!current_user_can('edit_posts')) {
            wp_die(__('You do not have sufficient permissions to access this page.', CAUSEWAY_PLUGIN_INTERNAL_NAME));
        }


        if (isset($_POST['causeway-import'])) {
            $endpoint = empty(get_option('causeway-url')) ? CAUSEWAY_BACKEND_IMPORT_URL : get_option('causeway-url');
            $json = $this->importJson(get_option('causeway-key'), boolval(get_option('causeway-import')), $endpoint);


        }

        include (sprintf('%s/settings.php', dirname(__FILE__)));
    }
    /****************************************************************************************************/
    private function generatePost($listing, &$messages = array()) {
        global $wpdb;
        global $EventsManager;
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
            $postExists = $wpdb->get_row("SELECT id FROM $wpdb->posts WHERE id = '" . intval($meta->post_id) . "'");
            if ($postExists) {
                $fNewEntry = false;
                $post['ID'] = $postId = $meta->post_id;
            } else {
                unset($post['ID']);
                #echo "Missing post {$meta->post_id} {$listing['name']} found in postmeta\n";
            }
        } else {
            #$messages[] = sprintf("Could not find post ID for %s using %d", $post['post_title'], $postCausewayId);
            unset($post['ID']);
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
                    if ($name == 'Event') {
                        $post['post_type'] = 'events';
                    }
                }
            } else {
                $post['tax_input']['listing-type'][] = $typeName;
                if ($typeName == 'Event') {
                    $post['post_type'] = 'events';
                }
            }
        }

        // FIXME: Temporarily disable any other post types for import, we only want events
        if ($post['post_type'] != 'events') {
            return -1;
        }

        $translateCategories = [
            'event-music' => 'music',
            'event-food-drink' => 'food-drink',
            'event-community' => 'community-events',
            'event-festivals' => 'festivals',
            'event-heritage' => 'heritage',
            'event-ceilidh-kitchen-parties' => 'ceilidhs-kitchen-parties',
            'event-arts-culture' => 'performing-arts-culture',
        ];

        $post['tax_input']['listing-category'] = array();
        foreach ($listing['categories'] as $listingType => $category) {
            if (is_array($category)) {
                foreach ($category as $value) {
                    $post['tax_input']['listing-category'][] = $value['slug'];

                    if (array_key_exists($value['slug'], $translateCategories)) {
                        $post['tax_input']['events_category'][] = $translateCategories[$value['slug']];
                    }
                }
            } else {
                $post['tax_input']['listing-category'][] = $category['slug'];

                if (array_key_exists($value['slug'], $translateCategories)) {
                    $post['tax_input']['events_category'][] = $translateCategories[$category['slug']];
                }
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
            #$messages[] = sprintf('Error occurred during post %s (%s), creation: %s', $post['post_title'], $postId, $post['ID']->get_error_message());
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
                    $res = wp_set_object_terms($post['ID'], $t, $tax, $fAppend);
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
        update_post_meta($post['ID'], 'defaultPhotoUrl', $listing['photo']);

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

        if (class_exists('ACF') && $post['post_type'] === 'events') {
            $address = '';
            if (!empty($listing['location']['name'])) {
                update_field('venue', $listing['location']['name'], $post['ID']);
            } else {
                update_field('venue', '', $post['ID']);
            }
            if (!empty($listing['location']['street'])) {
                $address .= $listing['location']['street'] . ', ';
            }
            if (!empty($listing['location']['community'])) {
                $address .= $listing['location']['community'] . ', ';

                $communityId = (int)$wpdb->get_var( $wpdb->prepare("SELECT ID FROM $wpdb->posts WHERE post_title LIKE '%s' AND post_parent != 0", $wpdb->esc_like($listing['location']['community'])) );

                if ($communityId) {
                    $region = get_field('region', $communityId);
                    update_field('community', $communityId, $post['ID']);
                } else {
                    $region = null;
                    $errorMessages[] = sprintf('Could not find community %s in communities CPT for ', $listing['location']['community'], $listing['name']);
                }
                update_field('feed_community', $listing['location']['community'], $post['ID']);
                update_field('region', $region, $post['ID']);
            } else {
                update_field('community', '', $post['ID']);
            }
            $address = substr($address, 0, -2);
            update_field('address', $address, $post['ID']);
            update_field('province', 'Nova Scotia', $post['ID']);
            update_field('postal_code', $listing['location']['postalCode'], $post['ID']);
            update_field('latitude', $listing['location']['lat'], $post['ID']);
            update_field('longitude', $listing['location']['lng'], $post['ID']);
            update_field('description', $listing['description'], $post['ID']);
            update_field('admission_price', '', $post['ID']);
            update_field('date_description', '', $post['ID']);
            update_field('feed_region', '', $post['ID']);
            update_field('product_id', '', $post['ID']);
            update_field('product_images', '', $post['ID']);
            update_field('product_type', '', $post['ID']);
            update_field('tripadvisor_id', '', $post['ID']);
            update_field('featured', '0', $post['ID']);
            update_field('patio_lantern', '0', $post['ID']);
            update_field('tunes_town', '0', $post['ID']);

            $frequency = strtoupper($listing['dateFrequency']);
            $row = array(
                'add_or_exclude_date' => true,
                'start_date' => $listing['dateStart'],
                'repeating_date' => empty($listing['dateFrequency']) ? '0' : '1',
                'end_date' => empty($listing['dateEnd']) ? null : $listing['dateEnd'],
                'repeat_interval' => empty($listing['dateInterval']) ? null : $listing['dateInterval'],
                'repeat_frequency' => $frequency,
            );

            $totalSchedules = count((array)get_field('event_schedule', $post['ID']));

            // Remove any saved schedules for this post
            for ($x = 1; $x < $totalSchedules; $x++) {
                delete_row('event_schedule', $x, $post['ID']);
            }

            if (!have_rows('event_schedule', $post['ID'])) {
                add_row('event_schedule', $row, $post['ID']);
            } else {
                // this will never happen since we delete_row()
                update_row('event_schedule', 1, $row, $post['ID']);
            }

            //if (!empty($listing['contacts']['Phone (Primary)'])) {
                update_field('telephone_1', $listing['contacts']['Phone (Primary)'][0] ?? '', $post['ID']);
            //}
            //if (!empty($listing['contacts']['Email'])) {
                update_field('email', $listing['contacts']['Email'][0] ?? '', $post['ID']);
                update_field('fax', $listing['contacts']['Fax'][0] ?? '', $post['ID']);
            //}
            //if (!empty($listing['websites']['General'])) {
                update_field('website', $listing['websites']['General'][0] ?? '', $post['ID']);
            //}
            //if (!empty($listing['websites']['Facebook'])) {
                update_field('facebook', $listing['websites']['Facebook'][0] ?? '', $post['ID']);
            //}
            //if (!empty($listing['websites']['Facebook'])) {
                update_field('twitter', $listing['websites']['Twitter'][0] ?? '', $post['ID']);
            //}
            //if (!empty($listing['websites']['Instagram'])) {
                update_field('instagram', $listing['websites']['Instagram'][0] ?? '', $post['ID']);
            //}
            //if (!empty($listing['websites']['YouTube'])) {
                update_field('youtube', $listing['websites']['YouTube'][0] ?? '', $post['ID']);
            //}

            $args = array(
                'post_type' => 'attachment',
                'post_mime_type'=>'image',
                'post_status' => 'publish',
                'posts_per_page' => 1,
                'meta_query' => array(
                    array(
                        'key' => '_source_url',
                        'value' => $listing['photo'],
                    ),
                ),
            );


            $image = $wpdb->get_var($wpdb->prepare(
                "SELECT `post_id` as ID FROM {$wpdb->prefix}postmeta WHERE `meta_key` = %s AND `meta_value` = %s",
                '_source_url',
                $listing['photo']
            ));

            if (empty($image)) {
                $image = media_sideload_image($listing['photo'], $post['ID'], $post['post_title'], $return = 'id');
            }

            update_field('images', [ 'image' => $image ], $post['ID']);

            //do_action('save_post', $post['ID'], $post, true);
            $a = new stdClass();
            $a->post_type = 'events';

            $EventsManager->saveRepeatingEventData($post['ID'], $a, true);

        }

        #$messages[] = sprintf('Listing <strong>%s</strong> has been successfully <strong>%s</strong>.', $listing['name'], ($fNewEntry ? 'added' : 'updated'));
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
    public function importJson() {

        $endpoint = empty(get_option('causeway-url')) ? CAUSEWAY_BACKEND_IMPORT_URL : get_option('causeway-url');
        if (empty($_SERVER) || empty($_SERVER['HTTP_HOST'])) {
            $hostName = 'unknown.tld';
        } else {
            $hostName = $_SERVER['HTTP_HOST'] ?? 'unknown.tld';
        }
        $serverName = str_replace(array('www.', 'http://', 'https://'), array('', '', ''), $hostName);
        $jsonUrl = sprintf(
            '%s/%s?&server=%s&forceReimport=%d',
            $endpoint,
            get_option('causeway-key'),
            $serverName,
            boolval(get_option('causeway-import'))
        );

        if (!empty($dateImport)) {
            $jsonUrl .= '&date=' . strip_tags($dateImport);
        }

        if (defined('WP_DEBUG')) {
            #echo '<p>Using URL: <strong><a rel="noopener" target="_blank" href="' . $jsonUrl . '">' . $jsonUrl . '</a></strong> for import.<br><br></p>';
        }
        if (function_exists('curl_init')) {
            $curl = curl_init($jsonUrl);
            curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($curl, CURLOPT_REFERER, $serverName);
            $curlResult = curl_exec($curl);
            curl_close($curl);

            if (empty($curlResult)) {
                return false;
                die;
            }

            $json = json_decode($curlResult, true);
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
            $json = json_decode($contents, true);
        } else {
            return false;
        }

        if ($json !== false) {
            $count = 0;
            $activePostIds = array();
            $errorListingIds = array();
            $messages = array();

            /* Create any category terms available to this server */
            foreach ($json['serverCategories'] as $category) {
                if (empty($category['description'])) {
                    $category['description'] = sprintf('%s (Type: %s)', $category['categoryName'], $category['typeName']);
                }

                $term = get_term_by('slug', $category['termName'], 'listing-category');


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

            $currentTimeLimit = ini_get('max_execution_time');
            set_time_limit(0);


            foreach ($json['results'] as $listing) {
                $postId = $this->generatePost($listing, $messages);
                if ($postId === false) {
                    $errorListingIds[] = $listing['id'];
                } elseif ($postId === -1) {
                    // Skipped
                } else {
                    $count++;
                    $activePostIds[] = $postId;
                }
            }

            foreach ($json['unchanged'] as $listing) {
                $activePostIds[] = $this->getPostIdByMeta('CausewayId', $listing);
            }

            $activePostIds = array_filter($activePostIds);

            #echo '<h2>Import Status</h2>';
            foreach ($messages as $message) {
                #echo sprintf("%s\n", $message);
            }
            $deleted = $this->deleteInactivePosts($activePostIds, $errorListingIds);

            set_time_limit($currentTimeLimit);

            echo 'Finished importing <strong>' . $count . '</strong> listing(s) from Causeway. Deleted <strong>' . $deleted . '</strong> listing(s) from WordPress.';
        } else {
            #echo '<p>Unable to parse the feed from Causeway backend. Please contact support.</p>';
        }
        exit;
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
