<?php
class ZIAI_Handler
{
    private $access_token;
    private $post_author;
    private $post_type;
    private $taxonomy;
    private $ziai_added = 0;
    private $ziai_imported = array();

    public function __construct($zl_external_article)
    {
        $this->access_token = $zl_external_article['access_token'];
        $this->post_type    = $zl_external_article['import_post_type'];
        $this->post_author  = $zl_external_article['import_author'];
        $this->taxonomy     = $zl_external_article['import_taxonomy'];
    }

    public function sync_articles(){
        $response = $this->get_articles(1, 25);
        if($response['pages']['total_pages'] > 1){
            $this->get_more_articles($response['pages']['page']+1, $response['pages']['total_pages']);
        }
    }

    private function get_more_articles($current_page, $max_page) {
        if ($current_page > $max_page) {
            return;
        }

        $this->get_articles($current_page, $current_page + 1,
            function($current_page, $max_page) {
                $current_page++;
                $this->get_more_articles($current_page, $max_page);
            });
    }

    public function get_articles($page=null, $per_page=null, $callback=false){
        $endpoint = 'https://api.intercom.io/articles/';
        $params = http_build_query(array(
            'page' => $page,
            'per_page' => $per_page
        ));
        if($params){
            $endpoint .= '?'.$params;
        }
        $response = wp_remote_get( $endpoint,
            array(
                'method' => 'GET',
                'headers' => array(
                    'Authorization' => 'Bearer ' . $this->access_token
                )
            )
        );

        $response = json_decode($response['body'], true);
        if(!isset($response['errors'])){
            $imported_articles = $this->ziai_import_articles($response);
            if($callback){
                $callback($page, $response['pages']['total_pages']);
            } else {
                return $response;
            }
        } else {
            return array(
                'status'   => 'errors',
                'message'  => $response['errors'][0]->message,
            );
        }
    }

    public function ziai_import_articles($response)
    {
        if (isset($response['errors'])) {
            return [
                'status' => 'errors',
                'message' => $response['errors'][0]->message,
            ];
        } else {
            // Get collection data from api for given article parent_id
            foreach($response['data'] as $data){
                // If the article exists, we don't need to call for the collection data
                $article_exists = $this->is_article_imported($data['id']);
                $collection = null;
                if(!$article_exists){
                    $this->create_update_article($data);
                } else {
                    //$article_wp_object = get_post($article_exists);
                    $out_of_date = $this->is_article_outdated($article_exists, $data);
                    if($out_of_date){
                        $this->create_update_article($data);
                    }
                }
            }
            return array(
                'status'   => 'success',
                'message'  => 'Articles settings updated successfully!!',
                'count'    => $this->ziai_added,
                'episodes' => $this->ziai_imported,
            );
        }
    }

    /**
     * Checks if a WordPress article is out of date with Intercom.
     *
     * @param int $article_id The post ID of the article.
     * @param array $data An array of article data from the Intercom API.
     * @return bool True if the article is outdated, false otherwise.
     */
    public function is_article_outdated(int $article_id, array $data): bool {
        $article_wp_date_modified = get_post_meta($article_id, 'intercom_updated_at', true);
        return ($data['updated_at'] > $article_wp_date_modified);
    }

    public function get_collection_data($data){
        $collection = wp_remote_get( 'https://api.intercom.io/help_center/collections/'.$data['parent_id'].'',
            array(
                'method' => 'GET',
                'headers' => array(
                    'Authorization' => 'Bearer ' . $this->access_token
                )
            )
        );

        return json_decode($collection['body'], true);
    }

    /**
     * Checks to see if an article has been previously imported into WordPress
     *
     * @param int $article_id The article_id as provided by the Intercom API
     * @return int|bool The post ID of the matching post if found, or false if not found.
     */
    public function is_article_imported(int $article_id): bool|int
    {
        $exists = false;
        $args = array(
            'post_type' => $this->post_type,
            'post_status' => array('publish', 'draft'),
            'meta_query' => array(
                array(
                    'key' => 'zl_ziai_id',
                    'value' => $article_id,
                    'compare' => '=',
                )
            )
        );

        $already_added = get_posts($args);
        if ($already_added) {
            $exists = $already_added[0]->ID;
        }

        return $exists;
    }

    public function create_update_article($data){
        if(isset($data['parent_id'])){
            $collection = $this->get_collection_data($data);
        }

        $article = array(
            'id' => $data['id'],
            'title' => $data['title'],
            'content' => $data['body'],
            'status'  => $data['state'],
            'created_at'  => $data['created_at'],
            'updated_at'  => $data['updated_at'],
            'collection'=> $collection['name'] ?? null,
        );

        $this->ziai_create_article($article);
    }
    protected function ziai_create_article($article)
    {
        $post_data  = $this->ziai_get_post_data($article);
        $post_id    = wp_insert_post($post_data);
        /**
         * If an error occurring adding a post, continue the loop
         */
        if (is_wp_error($post_id)) {
            return;
        }

        if (!empty($post_data['post_category'])) {
            wp_set_post_terms($post_id, $post_data['post_category'][0], $this->taxonomy);
        }
        
        $this->ziai_added++;
        $this->ziai_imported[] = $post_data['post_title'];
    }


    public function ziai_get_post_data($article)
    {
        $term_a = false;
        $post_data                  = array();
        if(isset($article['collection'])){
            $term_a      = term_exists($article['collection'], $this->taxonomy);
            $term_a_id   = $term_a['term_id'];
            if(empty($term_a_id)){
                wp_insert_term(
                    $article['collection'],
                    $this->taxonomy,
                    array(
                        // 'description'=> 'Some description.',
                        'slug' => str_replace(" ", "-", $article['collection']),
                    )
                );
            }
            $term_a                     = term_exists($article['collection'], $this->taxonomy);
            $term_id                    = $term_a['term_id'];
            $post_data['post_category'] = array($term_id);
        }

        $status                     = ($article['status'] == 'published') ? $article['status'] = 'publish' : $article['status'];
        $post_data['post_content']  = $article['content'];
        $post_data['post_title']    = $article['title'];
        $post_data['post_status']   = $status;
        $post_data['post_author']   = $this->post_author;
        $post_data['post_type']     = $this->post_type;

        $post_data['meta_input']    = array(
            'zl_ziai_id' => $article['id'],
            'intercom_created_at' => $article['created_at'],
            'intercom_updated_at' => $article['updated_at']
        );

        // Find the article in WP
        $article_exists = $this->is_article_imported($article['id']);
        if($article_exists ){
            $post_data['ID'] = $article_exists;
        }

        return $post_data;
    }
}