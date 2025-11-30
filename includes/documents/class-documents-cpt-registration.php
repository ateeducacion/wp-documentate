<?php
/**
 * CPT Registration for Documentate documents.
 *
 * Extracted from Documentate_Documents to follow Single Responsibility Principle.
 *
 * @package Documentate
 * @subpackage Documents
 * @since 1.0.0
 */

namespace Documentate\Documents;

/**
 * Handles Custom Post Type and Taxonomy registration for documents.
 */
class Documents_CPT_Registration {

	/**
	 * Register hooks for CPT/taxonomy registration.
	 */
	public function register_hooks() {
		add_action( 'init', array( $this, 'register_post_type' ) );
		add_action( 'init', array( $this, 'register_taxonomies' ) );
		add_filter( 'use_block_editor_for_post_type', array( $this, 'disable_gutenberg' ), 10, 2 );
	}

	/**
	 * Register the Documents custom post type and attach core categories.
	 */
	public function register_post_type() {
		$labels = array(
			'name'               => __( 'Documents', 'documentate' ),
			'singular_name'      => __( 'Document', 'documentate' ),
			'menu_name'          => __( 'Documents', 'documentate' ),
			'name_admin_bar'     => __( 'Document', 'documentate' ),
			'add_new'            => __( 'Add New', 'documentate' ),
			'add_new_item'       => __( 'Add New Document', 'documentate' ),
			'new_item'           => __( 'New Document', 'documentate' ),
			'edit_item'          => __( 'Edit Document', 'documentate' ),
			'view_item'          => __( 'View Document', 'documentate' ),
			'all_items'          => __( 'All Documents', 'documentate' ),
			'search_items'       => __( 'Search Documents', 'documentate' ),
			'not_found'          => __( 'No documents found.', 'documentate' ),
			'not_found_in_trash' => __( 'No documents found in trash.', 'documentate' ),
		);

		$args = array(
			'labels'           => $labels,
			'public'           => false,
			'show_ui'          => true,
			'show_in_menu'     => true,
			'menu_position'    => 25,
			'menu_icon'        => 'dashicons-media-document',
			'capability_type'  => 'post',
			'map_meta_cap'     => true,
			'hierarchical'     => false,
			'supports'         => array( 'title', 'revisions', 'comments' ),
			'taxonomies'       => array( 'category' ),
			'has_archive'      => false,
			'rewrite'          => false,
			'show_in_rest'     => false,
		);

		register_post_type( 'documentate_document', $args );
		register_taxonomy_for_object_type( 'category', 'documentate_document' );
	}

	/**
	 * Register taxonomies used by the documents CPT.
	 */
	public function register_taxonomies() {
		$types_labels = array(
			'name'          => __( 'Document Types', 'documentate' ),
			'singular_name' => __( 'Document Type', 'documentate' ),
			'search_items'  => __( 'Search Types', 'documentate' ),
			'all_items'     => __( 'All Types', 'documentate' ),
			'edit_item'     => __( 'Edit Type', 'documentate' ),
			'update_item'   => __( 'Update Type', 'documentate' ),
			'add_new_item'  => __( 'Add New Type', 'documentate' ),
			'new_item_name' => __( 'New Type', 'documentate' ),
			'menu_name'     => __( 'Document Types', 'documentate' ),
		);

		register_taxonomy(
			'documentate_doc_type',
			array( 'documentate_document' ),
			array(
				'hierarchical'      => false,
				'labels'            => $types_labels,
				'show_ui'           => true,
				'show_admin_column' => true,
				'query_var'         => true,
				'rewrite'           => false,
				'show_in_rest'      => false,
				'meta_box_cb'       => false,
			)
		);
	}

	/**
	 * Disable block editor for this CPT (use classic meta boxes).
	 *
	 * @param bool   $use_block_editor Whether to use block editor.
	 * @param string $post_type        Post type.
	 * @return bool
	 */
	public function disable_gutenberg( $use_block_editor, $post_type ) {
		if ( 'documentate_document' === $post_type ) {
			return false;
		}
		return $use_block_editor;
	}
}
