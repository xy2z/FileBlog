<?php

namespace xy2z\FileBlog;

use Symfony\Component\Yaml\Yaml;
use Exception;

/**
 * FileBlog
 *
 * Load files from a directory into an array of objects.
 *
 * @author Alexander Pedersen <xy2z@pm.me>
 */
class FileBlog {
	/**
	 * The directory path where the posts are stored.
	 *
	 * @var string
	 */
	private $posts_dir;

	/**
	 * The file extension for posts, eg. '.md'
	 *
	 * @var string
	 */
	private $post_extension;

	/**
	 * Cache $posts to a file instead of reading loading on each requests.
	 *
	 * @var boolean
	 */
	private $use_cache = false;

	/**
	 * Path to the cache file to use
	 *
	 * @var string
	 */
	private $cache_path;

	/**
	 * Number of posts per page used in get_posts_on_page() and get_count_pages()
	 *
	 * @var integer
	 */
	private $posts_per_page = 10;

	// Data
	/**
	 * Array of all published posts
	 *
	 * @var array
	 */
	private $posts;

	/**
	 * Total number of posts in $posts array (only published)
	 *
	 * @var int
	 */
	private $count_posts;

	/**
	 * Name of the 'published' key, eg. if you use 'is_public' instead of 'published'.
	 * If this key is set to FALSE in the post settings, the post will not be loaded.
	 *
	 * @var string
	 */
	private $published_key = 'published';

	/**
	 * Set the directory where your posts are stored
	 *
	 * @param string $dir
	 */
	public function set_posts_dir(string $dir) {
		$this->posts_dir = $dir;
	}

	/**
	 * Set the file extension for posts
	 *
	 * @param string $extension
	 */
	public function set_post_extension(string $extension) {
		$this->post_extension = $extension;
	}

	/**
	 * Use cache (recommended for production)
	 *
	 * @param bool $value
	 */
	public function use_cache(bool $value) {
		$this->use_cache = $value;
	}

	/**
	 * Set the path where to store the cache file.
	 * Only used when $use_cache is true.
	 *
	 * @param string $path
	 */
	public function set_cache_path(string $path) {
		$this->cache_path = $path;
	}

	/**
	 * Set how many posts should be shown per page.
	 *
	 * @param int $number
	 */
	public function set_posts_per_page(int $number) {
		$this->posts_per_page = $number;
	}

	/**
	 * Clear the cache file
	 *
	 */
	public function clear_cache() {
		if (file_exists($this->cache_path)) {
			unlink($this->cache_path);
		}
	}

	/**
	 * Get posts from the cache file (if enabled)
	 *
	 * @return bool True if successfull
	 */
	private function get_posts_from_cache() : bool {
		if ($this->use_cache && file_exists($this->cache_path)) {
			$this->posts = json_decode(utf8_encode(file_get_contents($this->cache_path)));

			if (!$this->posts) {
				throw new Exception('Error on loading posts from cache (no posts?).');
				return false;
			}

			$this->posts = (array) $this->posts;
			$this->count_posts = count($this->posts);
			return true;
		}

		return false;
	}

	/**
	 * Save the loaded posts array to the cache file.
	 *
	 * @return bool True on success.
	 */
	private function save_cache() : bool {
		if ($this->use_cache) {
			if (!$this->cache_path) {
				throw new Exception('Cannot save cache when $cache_path is not set.');
			}

			file_put_contents($this->cache_path, json_encode($this->posts, JSON_UNESCAPED_UNICODE));
			return true;
		}

		return false;
	}

	/**
	 * Load posts from a directory.
	 *
	 * @param  string $dir
	 * @return void
	 */
	public function load_posts() : void {
		// Get from cache.
		if ($this->get_posts_from_cache()) {
			return;
		}

		if (!$this->posts_dir) {
			throw new Exception('Posts_dir must be set before calling load_posts()');
		}

		$this->posts = [];
		foreach (glob($this->posts_dir . '/*' . $this->post_extension) as $key => $path) {
			$object = $this->prepare_post($path);
			if (!$object) {
				// Not published.
				continue;
			}

			$this->posts[basename($path)] = $object;
		}

		$this->count_posts = count($this->posts);

		// Save to cache.
		$this->save_cache();
	}

	/**
	 * Load a single post (file) either from cache, or from the loaded directory, or fetch the file.
	 *
	 * @param  string $filename
	 */
	public function load_post(string $filename) {
		$filename .= $this->post_extension;

		// Get from posts if already loaded.
		if (isset($this->posts)) {
			if (isset($this->posts[$filename])) {
				return $this->posts[$filename];
			}
		}

		// Get from cache file
		if ($this->get_posts_from_cache()) {
			// Fetch from cache
			if (isset($this->posts[$filename])) {
				return $this->posts[$filename];
			}
		}

		// Fetch from filesystem.
		$filename = str_replace(['..', '~', '|', '*', '$', '"', '?', "'"], '', $filename);
		if (!file_exists($this->posts_dir . '/' . $filename)) {
			// File not found.
			throw new Exception('Post not found.');
		}

		return $this->prepare_post($this->posts_dir . '/' . $filename);
	}

	/**
	 * Format the post file content into a post object.
	 *
	 * @param string $path File path
	 */
	private function prepare_post(string $path) {
		$file_content = file_get_contents($path);

		// Get yaml settings of the top
		$yaml_start = strpos($file_content, '---') + 3;
		$yaml_end = strpos($file_content, '---', $yaml_start);
		$yaml = substr($file_content, $yaml_start, $yaml_end - 3);

		// Set post object.
		$object = (object) Yaml::parse($yaml);

		if (isset($object->{$this->published_key}) && !$object->{$this->published_key}) {
			// Not published.
			return false;
		}

		$object->body = substr($file_content, $yaml_end + 3);
		$object->url = str_replace($this->post_extension, '', basename($path));

		return $object;
	}

	/**
	 * Sort $this->posts by key in yaml (etc. 'published_at')
	 *
	 * @param string $key [description]
	 * @param int $sort_order SORT_ASC or SORT_DESC.
	 *
	 */
	public function sort_by(string $key, int $sort_order = SORT_ASC) : void {
		if (!$this->posts) {
			throw new Exception('Cannot sort posts when no posts are loaded.');
		}

		$sortArray = array();

		foreach ($this->posts as $post) {
		    foreach ($post as $key => $value) {
		        if (!isset($sortArray[$key])) {
		            $sortArray[$key] = array();
		        }
		        $sortArray[$key][] = $value;
		    }
		}

		array_multisort($sortArray[$key], $sort_order, $this->posts);
	}

	/**
	 * Get all published posts.
	 *
	 * @return array
	 */
	public function get_all_posts() : array {
		return $this->posts;
	}

	/**
	 * Get the number of published posts.
	 *
	 * @return int
	 */
	public function get_count_posts() : int {
		return $this->count_posts;
	}

	/**
	 * Get the total number of pages
	 * Should be called AFTER set_posts_per_page()
	 *
	 * @return int
	 */
	public function get_count_pages() : int {
		return ceil(count($this->posts) / $this->posts_per_page);
	}

	/**
	 * Get posts for the current page number.
	 * Starts at 1.
	 *
	 * @param int $page
	 *
	 * @return array
	 */
	public function get_posts_on_page(int $page) : array {
		$start = ($page - 1) * $this->posts_per_page;
		return array_slice($this->posts, $start, $this->posts_per_page, true);
	}

	/**
	 * Get posts which has a specific tag.
	 *
	 * @param string $tag The tag used to filter posts.
	 * @param string $tags_key The key you use for your tags, usually it's 'tags'.
	 *
	 * @return array
	 */
	public function get_posts_by_tag(string $tag, string $tags_key = 'tags') : array {
		if (!$this->posts) {
			// No posts.
			return [];
		}

		$tag = urldecode($tag);
		$result = [];

		foreach ($this->posts as $key => $post) {
			if (isset($post->$tags_key) && in_array($tag, $post->$tags_key)) {
				$result[] = $post;
			}
		}

		return $result;
	}

}
