<?php

/**
 * Scope-based document filtering for Documentate.
 *
 * Filters the documentate_document admin list so that non-admin users only see
 * documents assigned to their scope category (stored as user meta
 * `documentate_scope_term_id`) including all descendant terms. Admins see all
 * documents.
 *
 * The same visibility rules are reused to recalculate the admin list "views"
 * counters (All, Mine, Published, Drafts, Pending, ...) so the counters always
 * match the rows the list table actually shows.
 *
 * @package    Documentate
 * @subpackage Documentate/includes
 */

defined('ABSPATH') || exit();

/**
 * Class Documentate_Scope_Filter
 *
 * Hooks into pre_get_posts to restrict visible documents by user scope and into
 * views_edit-documentate_document to keep the view counters consistent.
 */
class Documentate_Scope_Filter {
	/**
	 * The post type to filter.
	 *
	 * @var string
	 */
	const POST_TYPE = 'documentate_document';

	/**
	 * The taxonomy used for the scope filter.
	 *
	 * @var string
	 */
	const SCOPE_TAXONOMY = 'category';

	/**
	 * User meta key storing the scope term ID.
	 *
	 * @var string
	 */
	const SCOPE_META_KEY = 'documentate_scope_term_id';

	/**
	 * Statuses shown in the default "All"/"Mine" admin list views.
	 *
	 * Mirrors the default post_status set applied to the main list query in
	 * Documentate_Documents::apply_admin_filters() (archived and trash are
	 * intentionally excluded; they have their own views).
	 *
	 * @var string[]
	 */
	const ALL_LIST_STATUSES = array('publish', 'future', 'draft', 'pending', 'private');

	/**
	 * Register hooks.
	 */
	public function __construct() {
		add_action('pre_get_posts', array($this, 'filter_documents_by_scope'));
		// Recalculate the admin list view counters using the same scope rules.
		// Priority 20 so it runs after add_archived_view() has added its link.
		add_filter('views_edit-' . self::POST_TYPE, array($this, 'filter_view_counts'), 20);
	}

	/**
	 * Resolve the scope term IDs that constrain the current user's documents.
	 *
	 * @return int[]|null Array of term IDs (scope term plus descendants) the user
	 *                    is restricted to; an empty array when the user is
	 *                    restricted but has no scope assigned (sees nothing); or
	 *                    null when the user is unrestricted (administrator).
	 */
	public function get_scope_term_ids() {
		// Administrators (anyone who can manage options) are unrestricted.
		if (current_user_can('manage_options')) {
			return null;
		}

		$user_id = get_current_user_id();
		$scope_term = absint(get_user_meta($user_id, self::SCOPE_META_KEY, true));

		// Restricted user without a scope assigned: nothing is visible.
		if (0 === $scope_term) {
			return array();
		}

		$term_ids = array($scope_term);
		$children = get_term_children($scope_term, self::SCOPE_TAXONOMY);
		if (!is_wp_error($children) && !empty($children)) {
			$term_ids = array_merge($term_ids, $children);
		}

		return array_map('absint', $term_ids);
	}

	/**
	 * Apply scope restriction to the documents admin list query.
	 *
	 * @param WP_Query $query The query object being modified.
	 * @return void
	 */
	public function filter_documents_by_scope($query) {
		// Only run in wp-admin on the main query for our post type.
		if (!is_admin() || !$query->is_main_query()) {
			return;
		}

		if (self::POST_TYPE !== $query->get('post_type')) {
			return;
		}

		$term_ids = $this->get_scope_term_ids();

		// Unrestricted user (administrator): leave the query untouched.
		if (null === $term_ids) {
			return;
		}

		// Restricted user without a scope assigned: show nothing.
		if (empty($term_ids)) {
			$query->set('post__in', array(0));
			return;
		}

		// Merge the scope restriction with any taxonomy filter already on the
		// query (e.g. an explicitly selected category) using AND, so explicit
		// filters narrow the scope instead of replacing it.
		$tax_query = $query->get('tax_query');
		if (!is_array($tax_query)) {
			$tax_query = array();
		}

		$tax_query[] = array(
			'taxonomy' => self::SCOPE_TAXONOMY,
			'field' => 'term_id',
			'terms' => $term_ids,
			'include_children' => false,
		);

		// If more than one taxonomy clause is present (besides any existing
		// 'relation' key), combine them with AND so the scope always applies.
		$clause_count = count($tax_query) - (isset($tax_query['relation']) ? 1 : 0);
		if ($clause_count > 1) {
			$tax_query['relation'] = 'AND';
		}

		$query->set('tax_query', $tax_query);
	}

	/**
	 * Count the documents visible to the current user for a given status set.
	 *
	 * Applies exactly the same scope restriction used by the admin list query,
	 * so the resulting count matches the rows the list table would show.
	 *
	 * @param string|string[] $post_status Status or statuses to count.
	 * @param int             $author      Optional author ID to restrict to (0 = any author).
	 * @return int Number of visible documents.
	 */
	public function count_visible_documents($post_status, $author = 0) {
		$term_ids = $this->get_scope_term_ids();

		// Restricted user without a scope assigned: nothing is visible.
		if (is_array($term_ids) && empty($term_ids)) {
			return 0;
		}

		$args = array(
			'post_type' => self::POST_TYPE,
			'post_status' => $post_status,
			'fields' => 'ids',
			'posts_per_page' => 1,
			'no_found_rows' => false,
			'ignore_sticky_posts' => true,
			'update_post_meta_cache' => false,
			'update_post_term_cache' => false,
		);

		if ($author > 0) {
			$args['author'] = (int) $author;
		}

		// Apply the scope restriction (null = unrestricted, so no tax_query).
		if (!empty($term_ids)) {
			$args['tax_query'] = array(
				array(
					'taxonomy' => self::SCOPE_TAXONOMY,
					'field' => 'term_id',
					'terms' => $term_ids,
					'include_children' => false,
				),
			);
		}

		$query = new WP_Query($args);

		return (int) $query->found_posts;
	}

	/**
	 * Recalculate the admin list "views" counters using the scope rules.
	 *
	 * For unrestricted users (administrators) the native WordPress counters are
	 * returned untouched. For scoped users each counter is recomputed so it
	 * matches the rows the list table shows; status views that drop to zero are
	 * removed (mirroring how WordPress hides empty status filters), except the
	 * "All" view and whichever view is currently selected.
	 *
	 * @param string[] $views View links keyed by status/view name.
	 * @return string[] Filtered view links.
	 */
	public function filter_view_counts($views) {
		// Unrestricted users keep WordPress' native counters.
		if (null === $this->get_scope_term_ids()) {
			return $views;
		}

		// Fetch every scoped status count in a single grouped query instead of
		// running one WP_Query per view (previously up to 9 queries per render).
		$counts = $this->get_scoped_status_counts();

		$status_map = array(
			'all' => self::ALL_LIST_STATUSES,
			'mine' => self::ALL_LIST_STATUSES,
			'publish' => 'publish',
			'future' => 'future',
			'draft' => 'draft',
			'pending' => 'pending',
			'private' => 'private',
			'archived' => 'archived',
			'trash' => 'trash',
		);

		foreach ($views as $key => $view) {
			if (!isset($status_map[$key])) {
				continue;
			}

			// "Mine" is restricted to the current user's documents.
			$source = ('mine' === $key) ? $counts['mine'] : $counts['any'];
			$count = $this->sum_status_counts($source, $status_map[$key]);

			// Drop empty status views, but keep "All" and the active view.
			if (0 === $count && 'all' !== $key && !$this->is_current_view($key)) {
				unset($views[$key]);
				continue;
			}

			$views[$key] = $this->replace_view_count($view, $count);
		}

		return $views;
	}

	/**
	 * Fetch document counts grouped by post status for the current user's scope.
	 *
	 * Runs a single grouped query that mirrors the scope restriction applied by
	 * count_visible_documents(): documents in any of the user's scope terms,
	 * de-duplicated across terms. Replaces the previous one-WP_Query-per-view
	 * pattern (up to nine queries) with a single database round-trip.
	 *
	 * @return array{any: array<string, int>, mine: array<string, int>} Counts
	 *               keyed by post status: 'any' across all authors and 'mine'
	 *               limited to the current user.
	 */
	private function get_scoped_status_counts() {
		global $wpdb;

		$empty = array('any' => array(), 'mine' => array());

		$term_ids = $this->get_scope_term_ids();

		// Unrestricted (null) or restricted-without-scope (empty): count nothing.
		if (empty($term_ids)) {
			return $empty;
		}

		$current_user = get_current_user_id();
		$placeholders = implode(', ', array_fill(0, count($term_ids), '%d'));

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Only table names and %d placeholders are interpolated; values are bound via wpdb::prepare() below.
		$sql = "SELECT p.post_status AS status, p.post_author AS author, COUNT(DISTINCT p.ID) AS num
			FROM {$wpdb->posts} p
			INNER JOIN {$wpdb->term_relationships} tr ON tr.object_id = p.ID
			INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
			WHERE p.post_type = %s
			AND tt.taxonomy = %s
			AND tt.term_id IN ($placeholders)
			GROUP BY p.post_status, p.post_author";

		$params = array_merge(array(self::POST_TYPE, self::SCOPE_TAXONOMY), $term_ids);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Aggregated admin counters via a prepared statement, executed once per list render.
		$rows = $wpdb->get_results($wpdb->prepare($sql, $params));

		$any = array();
		$mine = array();
		foreach ((array) $rows as $row) {
			$status = (string) $row->status;
			$num = (int) $row->num;

			$any[$status] = (isset($any[$status]) ? $any[$status] : 0) + $num;

			if ((int) $row->author !== $current_user) {
				continue;
			}

			$mine[$status] = (isset($mine[$status]) ? $mine[$status] : 0) + $num;
		}

		return array('any' => $any, 'mine' => $mine);
	}

	/**
	 * Sum the counts for a set of statuses from a status => count map.
	 *
	 * @param array<string, int> $counts   Status => count map.
	 * @param string|string[]    $statuses Status or statuses to sum.
	 * @return int Total count across the requested statuses.
	 */
	private function sum_status_counts($counts, $statuses) {
		if (!is_array($statuses)) {
			$statuses = array($statuses);
		}

		$total = 0;
		foreach ($statuses as $status) {
			if (!isset($counts[$status])) {
				continue;
			}

			$total += (int) $counts[$status];
		}

		return $total;
	}

	/**
	 * Determine whether the given view is the one currently being displayed.
	 *
	 * @param string $key View key (all, mine, draft, ...).
	 * @return bool
	 */
	private function is_current_view($key) {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$status = isset($_GET['post_status']) ? sanitize_key(wp_unslash($_GET['post_status'])) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$author = isset($_GET['author']) ? absint(wp_unslash($_GET['author'])) : 0;

		if ('mine' === $key) {
			return 0 !== $author && $author === get_current_user_id();
		}

		if ('all' === $key) {
			return '' === $status && 0 === $author;
		}

		return $status === $key;
	}

	/**
	 * Replace the numeric counter inside a view link with a new value.
	 *
	 * @param string $view  The view link HTML.
	 * @param int    $count The new count.
	 * @return string Updated view link.
	 */
	private function replace_view_count($view, $count) {
		$replacement = '<span class="count">(' . number_format_i18n($count) . ')</span>';
		$updated = preg_replace('#<span class="count">\([^)]*\)</span>#', $replacement, $view, 1);

		return null === $updated ? $view : $updated;
	}
}

new Documentate_Scope_Filter();
