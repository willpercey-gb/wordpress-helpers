<?php


namespace Standard;


class Duplicator
{
    protected $excludeList = [];

    public function __construct()
    {
        add_action('admin_action_rd_duplicate_post_as_draft', [$this, 'register']);
        add_filter('post_row_actions', [$this, '_register'], 10, 2);
        add_filter('page_row_actions', [$this, '_register'], 10, 2);

    }

    public function exclude(array $list): void
    {
        $this->excludeList = $list;
    }

    public function _register($actions, $post)
    {
        if (current_user_can('edit_posts')) {
            if (!isset($_GET['post_type']) || (isset($_GET['post_type']) && !in_array($_GET['post_type'], $this->excludeList, false))) {
                $actions['duplicate'] = '<a href="' . wp_nonce_url('admin.php?action=rd_duplicate_post_as_draft&post=' .
                        $post->ID, basename(__FILE__), 'duplicate_nonce') . '" title="Duplicate this item" rel="permalink">Duplicate</a>';
            }
        }
        return $actions;
    }

    public function register(): void
    {
        global $wpdb;
        if (!(isset($_GET['post']) || isset($_POST['post']) || (isset($_REQUEST['action']) && 'rd_duplicate_post_as_draft' === $_REQUEST['action']))) {
            wp_die('No post to duplicate has been supplied!');
        }

        if (!isset($_GET['duplicate_nonce']) || !wp_verify_nonce($_GET['duplicate_nonce'], basename(__FILE__)))
            return;


        $post_id = (isset($_GET['post']) ? absint($_GET['post']) : absint($_POST['post']));
        $post = get_post($post_id);

        $current_user = wp_get_current_user();
        $new_post_author = $current_user->ID;

        if (isset($post) && $post !== null) {
            $args = [
                'comment_status' => $post->comment_status,
                'ping_status' => $post->ping_status,
                'post_author' => $new_post_author,
                'post_content' => $post->post_content,
                'post_excerpt' => $post->post_excerpt,
                'post_name' => $post->post_name,
                'post_parent' => $post->post_parent,
                'post_password' => $post->post_password,
                'post_status' => 'draft',
                'post_title' => $post->post_title,
                'post_type' => $post->post_type,
                'to_ping' => $post->to_ping,
                'menu_order' => $post->menu_order
            ];
            $new_post_id = wp_insert_post($args);

            $taxonomies = get_object_taxonomies($post->post_type);

            foreach ($taxonomies as $taxonomy) {
                $post_terms = wp_get_object_terms($post_id, $taxonomy, ['fields' => 'slugs']);
                wp_set_object_terms($new_post_id, $post_terms, $taxonomy, false);
            }

            $post_meta_infos = $wpdb->get_results('SELECT meta_key, meta_value FROM $wpdb->postmeta WHERE post_id=' . $post_id);
            if (count($post_meta_infos) != 0) {
                $sql_query = 'INSERT INTO $wpdb->postmeta (post_id, meta_key, meta_value)';
                foreach ($post_meta_infos as $meta_info) {
                    $meta_key = $meta_info->meta_key;
                    if ($meta_key === '_wp_old_slug') {
                        continue;
                    }
                    $meta_value = addslashes($meta_info->meta_value);
                    $sql_query_sel[] = "SELECT {$new_post_id}, '{$meta_key}', '{$meta_value}'";
                }
                if (isset($sql_query_sel)) {
                    $sql_query .= implode(' UNION ALL ', $sql_query_sel);
                    $wpdb->query($sql_query);
                }
            }

            wp_redirect(admin_url('post.php?action=edit&post=' . $new_post_id));
            exit;
        }

        wp_die('Post creation failed, could not find original post: ' . $post_id);
    }
}