<?php
/**
 * Scope-based document filtering for Documentate.
 *
 * Filters the documentate_document admin list so that non-admin users only see
 * documents assigned to their scope category (stored as user meta
 * `documentate_scope_term_id`) including all descendant terms. Admins see all
 * documents.
 *
 * @package    Documentate
 * @subpackage Documentate/includes
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Documentate_Scope_Filter
 *
 * Hooks into pre_get_posts to restrict visible documents by user scope.
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
	 * Register hooks.
	 */
	public function __construct() {
		add_action( 'pre_get_posts', array( $this, 'filter_documents_by_scope' ) );
	}

	/**
	 * Apply scope restriction to the documents admin list query.
	 *
	 * @param WP_Query $query The query object being modified.
	 * @return void
	 */
	public function filter_documents_by_scope( $query ) {
		// Only run in wp-admin on the main query for our post type.
		if ( ! is_admin() || ! $query->is_main_query() ) {
			return;
		}

		if ( self::POST_TYPE !== $query->get( 'post_type' ) ) {
			return;
		}

		// Admins see everything.
		if ( current_user_can( 'manage_options' ) ) {
			return;
		}

		$user_id    = get_current_user_id();
		$scope_term = absint( get_user_meta( $user_id, self::SCOPE_META_KEY, true ) );

		// No scope assigned: show nothing.
		if ( 0 === $scope_term ) {
			$query->set( 'post__in', array( 0 ) );
			return;
		}

		// Build list of scope term and its descendants.
		$term_ids = array( $scope_term );
		$children = get_term_children( $scope_term, self::SCOPE_TAXONOMY );
		if ( ! is_wp_error( $children ) && ! empty( $children ) ) {
			$term_ids = array_merge( $term_ids, $children );
		}

		$query->set(
			'tax_query',
			array(
				array(
					'taxonomy'         => self::SCOPE_TAXONOMY,
					'field'            => 'term_id',
					'terms'            => $term_ids,
					'include_children' => false,
				),
			)
		);
	}
}

new Documentate_Scope_Filter();
