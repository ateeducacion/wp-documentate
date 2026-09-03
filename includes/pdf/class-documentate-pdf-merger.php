<?php
/**
 * TinyButStrong merge for the HTML layouts the native PDF renderer draws.
 *
 * @package Documentate
 */

// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- TinyButStrong properties.

/**
 * Merges a document's field values into an HTML layout.
 *
 * The ODT path drives TinyButStrong through the OpenTBS plugin because it has
 * to write into a zip archive. An HTML layout needs none of that: plain TBS
 * loads the file, merges the fields and leaves the result in its source
 * buffer, which is what the PDF renderer reads.
 */
class Documentate_Pdf_Merger {

	/**
	 * Automatic field prefixes TBS resolves by itself when Show() runs.
	 *
	 * Clearing one of these would delete a tag that is not a leftover at all.
	 *
	 * @var string[]
	 */
	private const RESERVED_PREFIXES = array( 'onshow', 'onload', 'var' );

	/**
	 * Merge a document's fields into an HTML layout.
	 *
	 * @param string $layout_path Absolute path to the HTML layout.
	 * @param array  $fields      Merge fields: scalars, raw HTML strings and repeater row lists.
	 * @return string|WP_Error Merged HTML, or the reason the merge could not be done.
	 */
	public static function merge( $layout_path, array $fields ) {
		if ( ! file_exists( $layout_path ) ) {
			return new WP_Error( 'documentate_pdf_layout_missing', __( 'PDF layout not found.', 'documentate' ) );
		}
		if ( ! Documentate_OpenTBS::load_libs() ) {
			return new WP_Error( 'documentate_opentbs_missing', __( 'OpenTBS is not available.', 'documentate' ) );
		}

		$old_locale = Documentate_OpenTBS::push_locale();

		try {
			$tbs = new clsTinyButStrong();
			$tbs->SetOption( 'render', TBS_NOTHING );
			// TBS reports a broken template by echoing it. That would corrupt
			// the PDF stream or the JSON response it is written into, so the
			// messages are collected in ErrCount and returned as a WP_Error.
			$tbs->SetOption( 'noerr', true );
			// UTF-8 makes TBS escape merged values with htmlspecialchars() and
			// turn their line breaks into <br /> tags.
			$tbs->LoadTemplate( $layout_path, 'UTF-8' );

			// Resolve [onshow;block=begin;bloc=FIELD]...[onshow;block=end]
			// before TBS sees them, the same way the ODT path does.
			$source = Documentate_OpenTBS::process_visibility_blocks( $tbs->Source, $fields );
			if ( null === $source ) {
				return new WP_Error(
					'documentate_regex_error',
					__( 'Could not process the visibility blocks of the layout.', 'documentate' )
				);
			}
			$tbs->Source = $source;

			// A leftover belongs to the layout, not to the merged document, so
			// the candidates are read here while the source is still only the
			// markup we authored. Merged values never enter the set.
			$leftovers = self::layout_leftovers( $tbs->Source, $fields );

			$tbs->ResetVarRef( false );
			self::merge_blocks( $tbs, $fields );
			self::merge_scalars( $tbs, $fields );
			self::clear_leftovers( $tbs, $leftovers );

			// Runs the final [onshow...] and [var....] pass and leaves the
			// merged HTML in Source without echoing it or exiting.
			$tbs->Show( TBS_NOTHING );

			if ( $tbs->ErrCount > 0 ) {
				return new WP_Error( 'documentate_pdf_merge_error', __( 'The PDF layout could not be merged.', 'documentate' ) );
			}

			return (string) $tbs->Source;
		} catch ( \Throwable $e ) {
			return new WP_Error( 'documentate_pdf_merge_error', $e->getMessage() );
		} finally {
			Documentate_OpenTBS::pop_locale( $old_locale );
		}
	}

	/**
	 * Expand every repeater block, in the order the ODT path uses.
	 *
	 * Blocks go first: a scalar merge would otherwise consume the fields that
	 * sit inside a block section.
	 *
	 * @param clsTinyButStrong $tbs    Engine holding the loaded layout.
	 * @param array            $fields Merge fields.
	 * @return void
	 */
	private static function merge_blocks( clsTinyButStrong $tbs, array $fields ) {
		foreach ( $fields as $name => $value ) {
			if ( self::is_field_name( $name ) && is_array( $value ) ) {
				$tbs->MergeBlock( $name, $value );
			}
		}
	}

	/**
	 * Merge every scalar field.
	 *
	 * SetVarRefItem() also makes the value reachable as [var.NAME], which is
	 * how a layout reads a field from inside a block section.
	 *
	 * @param clsTinyButStrong $tbs    Engine holding the loaded layout.
	 * @param array            $fields Merge fields.
	 * @return void
	 */
	private static function merge_scalars( clsTinyButStrong $tbs, array $fields ) {
		foreach ( $fields as $name => $value ) {
			if ( ! self::is_field_name( $name ) || is_array( $value ) ) {
				continue;
			}
			$tbs->SetVarRefItem( $name, $value );
			$tbs->MergeField( $name, $value );
		}
	}

	/**
	 * List the layout's tags that no field is going to answer for.
	 *
	 * A layout may be richer than the schema of the document being rendered,
	 * and printing "[objeto]" into an official document is worse than printing
	 * nothing. Which tags are unanswered is decided from the layout alone, and
	 * decided before anything is merged: once values are in, a rich field's
	 * "[sic]" or a citation's brackets are indistinguishable from a tag, and
	 * clearing those would delete a person's words without saying so.
	 *
	 * @param string $source Layout source, before any value has been merged.
	 * @param array  $fields Merge fields that are about to be merged.
	 * @return array<string,bool> Unanswered tag name => whether it opens a block.
	 */
	private static function layout_leftovers( $source, array $fields ) {
		if ( ! preg_match_all( '/\[([a-z][a-z0-9_]*)(?:\.[a-z0-9_.]+)?(?:;[^\]]*)?\]/i', $source, $matches ) ) {
			return array();
		}

		$leftovers = array();
		foreach ( array_unique( $matches[1] ) as $name ) {
			if ( in_array( strtolower( $name ), self::RESERVED_PREFIXES, true ) ) {
				continue;
			}
			if ( array_key_exists( $name, $fields ) ) {
				continue;
			}
			$leftovers[ $name ] = (bool) preg_match( '/\[' . preg_quote( $name, '/' ) . ';[^\]]*block=begin/i', $source );
		}

		return $leftovers;
	}

	/**
	 * Merge away the tags layout_leftovers() found: blocks are dropped whole,
	 * fields are replaced with an empty string.
	 *
	 * Some of these names no longer appear in the source at all, because a
	 * parent repeater consumed its sub-block along the way. TBS treats both as
	 * a no-op rather than an error, so they need no guard here.
	 *
	 * @param clsTinyButStrong   $tbs       Engine holding the merged layout.
	 * @param array<string,bool> $leftovers Tag name => whether it opens a block.
	 * @return void
	 */
	private static function clear_leftovers( clsTinyButStrong $tbs, array $leftovers ) {
		foreach ( $leftovers as $name => $is_block ) {
			if ( $is_block ) {
				$tbs->MergeBlock( $name, array() );
			} else {
				$tbs->MergeField( $name, '' );
			}
		}
	}

	/**
	 * Whether an array key can name a TBS field at all.
	 *
	 * @param mixed $name Candidate field name.
	 * @return bool
	 */
	private static function is_field_name( $name ) {
		return is_string( $name ) && '' !== $name;
	}
}
