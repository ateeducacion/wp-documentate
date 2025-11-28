<?php
/**
 * OpenTBS integration helpers for Documentate.
 *
 * @package Documentate
 */

// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase

/**
 * Lightweight OpenTBS wrapper for Documentate.
 */
class Documentate_OpenTBS {



	/**
	 * WordprocessingML namespace used in DOCX documents.
	 */
	private const WORD_NAMESPACE = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';

	/**
	 * ODF text namespace.
	 */
	private const ODF_TEXT_NS = 'urn:oasis:names:tc:opendocument:xmlns:text:1.0';

	/**
	 * ODF table namespace.
	 */
	private const ODF_TABLE_NS = 'urn:oasis:names:tc:opendocument:xmlns:table:1.0';

	/**
	 * ODF office namespace.
	 */
	private const ODF_OFFICE_NS = 'urn:oasis:names:tc:opendocument:xmlns:office:1.0';

	/**
	 * ODF style namespace.
	 */
	private const ODF_STYLE_NS = 'urn:oasis:names:tc:opendocument:xmlns:style:1.0';

	/**
	 * ODF XSL-FO namespace.
	 */
	private const ODF_FO_NS = 'urn:oasis:names:tc:opendocument:xmlns:xsl-fo-compatible:1.0';

	/**
	 * XLink namespace used for hyperlinks in ODT documents.
	 */
	private const ODF_XLINK_NS = 'http://www.w3.org/1999/xlink';

	/**
	 * Ensure libraries are loaded.
	 *
	 * @return bool
	 */
	public static function load_libs() {
		$base = plugin_dir_path( __DIR__ ) . 'admin/vendor/tinybutstrong/';
		$tbs = $base . 'tinybutstrong/tbs_class.php';
		$otb = $base . 'opentbs/tbs_plugin_opentbs.php';
		if ( file_exists( $tbs ) && file_exists( $otb ) ) {
			require_once $tbs; // phpcs:ignore WordPressVIPMinimum.Files.IncludingFile.UsingVariable
			require_once $otb; // phpcs:ignore WordPressVIPMinimum.Files.IncludingFile.UsingVariable
			return class_exists( 'clsTinyButStrong' ) && defined( 'OPENTBS_PLUGIN' );
		}
		return false;
	}

	/**
	 * Render an ODT from template and data.
	 *
	 * @param string $template_path Absolute path to .odt template.
	 * @param array  $fields        Associative fields.
	 * @param string $dest_path     Output file path.
	 * @param array  $rich_values   Optional rich text values (unused for ODT).
	 * @return bool|WP_Error
	 */
	public static function render_odt( $template_path, $fields, $dest_path, $rich_values = array() ) {
		$result = self::render_template_to_file( $template_path, $fields, $dest_path );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$rich_result = self::apply_odt_rich_text( $dest_path, $rich_values );
		if ( is_wp_error( $rich_result ) ) {
			return $rich_result;
		}

		return $result;
	}

	/**
	 * Render a DOCX from template and data (same as ODT).
	 *
	 * @param string $template_path Template path.
	 * @param array  $fields        Fields map.
	 * @param string $dest_path     Output path.
	 * @param array  $rich_values   Rich text values detected during merge.
	 * @return bool|WP_Error
	 */
	public static function render_docx( $template_path, $fields, $dest_path, $rich_values = array() ) {
		$result = self::render_template_to_file( $template_path, $fields, $dest_path );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		$rich_result = self::apply_docx_rich_text( $dest_path, $rich_values );
		if ( is_wp_error( $rich_result ) ) {
			return $rich_result;
		}
		return $result;
	}

	/**
	 * Render a template to disk using OpenTBS.
	 *
	 * @param string $template_path Absolute template path.
	 * @param array  $fields        Merge fields map.
	 * @param string $dest_path     Output destination.
	 * @return bool|WP_Error
	 */
	private static function render_template_to_file( $template_path, $fields, $dest_path ) {
		if ( ! self::load_libs() ) {
			return new WP_Error( 'documentate_opentbs_missing', __( 'OpenTBS no está disponible.', 'documentate' ) );
		}
		if ( ! file_exists( $template_path ) ) {
			return new WP_Error( 'documentate_template_missing', __( 'Plantilla no encontrada.', 'documentate' ) );
		}
		try {
			$tbs_engine = new clsTinyButStrong();
			$tbs_engine->Plugin( TBS_INSTALL, OPENTBS_PLUGIN );
			$tbs_engine->LoadTemplate( $template_path, OPENTBS_ALREADY_UTF8 );

			if ( ! is_array( $fields ) ) {
				$fields = array();
			}

			$tbs_engine->ResetVarRef( false );

			// First merge repeater blocks (arrays), then scalar fields.
			foreach ( $fields as $k => $v ) {
				if ( ! is_string( $k ) || '' === $k ) {
					continue;
				}
				if ( is_array( $v ) ) {
					// Merge repeatable blocks with the same key as the block name.
					// TBS expects a sequential array of associative rows.
					$tbs_engine->MergeBlock( $k, $v );
				}
			}

			foreach ( $fields as $k => $v ) {
				if ( ! is_string( $k ) || '' === $k ) {
					continue;
				}
				if ( is_array( $v ) ) {
					// Arrays are handled via MergeBlock; avoid printing "Array" by skipping MergeField.
					continue;
				}
				$tbs_engine->SetVarRefItem( $k, $v );
				$tbs_engine->MergeField( $k, $v );
			}

			$tbs_engine->Show( OPENTBS_FILE, $dest_path );
			return true;
		} catch ( \Throwable $e ) {
			return new WP_Error( 'documentate_opentbs_error', $e->getMessage() );
		}
	}

	/**
	 * Post-process a DOCX file replacing HTML strings with formatted runs.
	 *
	 * @param string       $doc_path    Generated DOCX path.
	 * @param array<mixed> $rich_values Rich text values detected during merge.
	 * @return bool|WP_Error
	 */
	private static function apply_docx_rich_text( $doc_path, $rich_values ) {
			$lookup = self::prepare_rich_lookup( $rich_values );
		// Debug logging.
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( 'DOCUMENTATE apply_docx_rich_text: rich_values_count=' . count( $rich_values ) . ', lookup_count=' . count( $lookup ) );
			foreach ( $lookup as $key => $val ) {
				error_log( 'DOCUMENTATE lookup[' . strlen( $key ) . ' chars]: ' . substr( $key, 0, 100 ) . '...' );
			}
		}
		if ( empty( $lookup ) ) {
				return true;
		}
		if ( ! class_exists( 'ZipArchive' ) ) {
			return new WP_Error( 'documentate_docx_zip_missing', __( 'ZipArchive no está disponible para aplicar formato enriquecido.', 'documentate' ) );
		}
			$zip = new ZipArchive();
		if ( true !== $zip->open( $doc_path ) ) {
			return new WP_Error( 'documentate_docx_zip_open', __( 'No se pudo abrir el DOCX generado para aplicar formato.', 'documentate' ) );
		}
			$targets     = array();
			$total_files = $zip->numFiles; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
		for ( $i = 0; $i < $total_files; $i++ ) {
				$name = $zip->getNameIndex( $i );
			if ( preg_match( '/^word\/(document|header[0-9]*|footer[0-9]*).xml$/', $name ) ) {
					$targets[] = $name;
			}
		}
			$changed = false;
		foreach ( $targets as $target ) {
				$xml = $zip->getFromName( $target );
			if ( false === $xml ) {
					continue;
			}
				$relationships = self::load_relationships_for_part( $zip, $target );
				$updated       = self::convert_docx_part_rich_text( $xml, $lookup, $relationships );
			if ( $updated !== $xml ) {
					$zip->addFromString( $target, $updated );
					$changed = true;
			}
			if ( is_array( $relationships ) && ! empty( $relationships['modified'] ) && ! empty( $relationships['path'] ) ) {
					$zip->addFromString( $relationships['path'], $relationships['doc']->saveXML() );
					$changed = true;
			}
		}
			$zip->close();
			return $changed;
	}

	/**
	 * Replace HTML fragments in a DOCX XML part with formatted runs.
	 *
	 * @param string                   $xml            Original XML part contents.
	 * @param array<string,string>     $lookup         Rich text lookup table.
	 * @param array<string,mixed>|null $relationships  Relationships context, passed by reference.
	 * @return string
	 */
	public static function convert_docx_part_rich_text( $xml, $lookup, &$relationships = null ) {
			$rich_lookup = self::prepare_rich_lookup( $lookup );
		if ( empty( $rich_lookup ) ) {
				return $xml;
		}
			// Normalize line endings to match ODT processing (handles HTML with newlines between tags).
			$rich_lookup = self::normalize_lookup_line_endings( $rich_lookup );

			$dom = new DOMDocument();
			$dom->preserveWhiteSpace = false; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
			$dom->formatOutput       = false; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
			libxml_use_internal_errors( true );
			$loaded = $dom->loadXML( $xml );
			libxml_clear_errors();
		if ( ! $loaded ) {
			return $xml;
		}
			$xpath = new DOMXPath( $dom );
			$xpath->registerNamespace( 'w', self::WORD_NAMESPACE );

			// Process paragraphs instead of individual w:t nodes to handle HTML split across runs.
			$paragraphs = $xpath->query( '//w:p' );
			$modified   = false;

		if ( $paragraphs instanceof DOMNodeList ) {
			// Convert to array to avoid issues with DOM modification during iteration.
			$para_array = array();
			foreach ( $paragraphs as $para ) {
				$para_array[] = $para;
			}

			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				error_log( 'DOCUMENTATE convert_docx_part: checking ' . count( $para_array ) . ' paragraphs' );
			}

			foreach ( $para_array as $paragraph ) {
				if ( ! $paragraph instanceof DOMElement ) {
					continue;
				}

				// Collect all w:t nodes and their text within this paragraph.
				$t_nodes  = $xpath->query( './/w:t', $paragraph );
				$para_map = self::build_paragraph_text_map( $t_nodes );

				if ( empty( $para_map['text'] ) ) {
					continue;
				}

				// Decode HTML entities to match raw HTML.
				$coalesced = html_entity_decode( $para_map['text'], ENT_QUOTES | ENT_XML1, 'UTF-8' );

				// Debug: log paragraph text if it contains HTML-like content.
				if ( defined( 'WP_DEBUG' ) && WP_DEBUG && false !== strpos( $coalesced, '<' ) ) {
					$escaped = htmlspecialchars( substr( $coalesced, 0, 300 ), ENT_QUOTES, 'UTF-8' );
					error_log( 'DOCUMENTATE paragraph text [' . strlen( $coalesced ) . ' chars, ' . count( $para_map['nodes'] ) . ' nodes]: ' . $escaped );
				}

				// Find HTML match in coalesced paragraph text.
				$match = self::find_next_html_match( $coalesced, $rich_lookup, 0 );
				if ( false === $match ) {
					continue;
				}

				if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
					error_log( 'DOCUMENTATE convert_docx_part: MATCH FOUND at pos ' . $match[0] . ' in paragraph, converting HTML' );
				}

				list( $match_pos, $match_key, $match_raw ) = $match;
				$match_end = $match_pos + strlen( $match_key );

				// Find the base run properties from the first affected run.
				$base_rpr = null;
				foreach ( $para_map['nodes'] as $node_info ) {
					if ( $node_info['end'] > $match_pos ) {
						$run = $node_info['node']->parentNode; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
						if ( $run instanceof DOMElement ) {
							$base_rpr = self::clone_run_properties( $run );
						}
						break;
					}
				}

				// Build prefix text (before the HTML match).
				$prefix = substr( $coalesced, 0, $match_pos );

				// Build suffix text (after the HTML match).
				$suffix = substr( $coalesced, $match_end );

				// Collect runs that need to be removed (those containing the matched HTML).
				$runs_to_remove = array();
				foreach ( $para_map['nodes'] as $node_info ) {
					// Check if this node overlaps with the match range.
					if ( $node_info['end'] > $match_pos && $node_info['start'] < $match_end ) {
						$run = $node_info['node']->parentNode; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
						if ( $run instanceof DOMElement && ! in_array( $run, $runs_to_remove, true ) ) {
							$runs_to_remove[] = $run;
						}
					}
				}

				if ( empty( $runs_to_remove ) ) {
					continue;
				}

				// Get insertion reference point (first run to remove).
				$insert_before = $runs_to_remove[0];

				// Build prefix run if needed.
				if ( '' !== $prefix ) {
					$prefix_run = self::build_docx_text_run( $dom, $prefix, $base_rpr );
					$paragraph->insertBefore( $prefix_run, $insert_before );
				}

				// Convert the matched HTML.
				$conversion = self::build_docx_nodes_from_html( $dom, $match_raw, $base_rpr, $relationships );

				// Check if we should replace the entire paragraph with block content.
				$is_block_only = ! empty( $conversion['block'] ) && '' === trim( $prefix ) && '' === trim( $suffix );
				if ( $is_block_only ) {
					$container = $paragraph->parentNode; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
					if ( $container && ! empty( $conversion['nodes'] ) ) {
						foreach ( $conversion['nodes'] as $node_to_insert ) {
							if ( $node_to_insert instanceof DOMElement ) {
								$container->insertBefore( $node_to_insert, $paragraph );
							}
						}
						// Remove the original paragraph.
						$container->removeChild( $paragraph );
						$modified = true;
						continue;
					}
				}

				// Insert converted inline runs.
				$inline_runs = ! empty( $conversion['block'] )
					? self::build_docx_inline_runs_from_html( $dom, $match_raw, $base_rpr, $relationships )
					: ( ! empty( $conversion['nodes'] ) ? $conversion['nodes'] : array() );

				foreach ( $inline_runs as $new_run ) {
					if ( $new_run instanceof DOMElement ) {
						$paragraph->insertBefore( $new_run, $insert_before );
					}
				}

				// Build suffix run if needed.
				if ( '' !== $suffix ) {
					$suffix_run = self::build_docx_text_run( $dom, $suffix, $base_rpr );
					$paragraph->insertBefore( $suffix_run, $insert_before );
				}

				// Remove the original runs that contained the HTML.
				foreach ( $runs_to_remove as $run ) {
					if ( $run->parentNode === $paragraph ) {
						$paragraph->removeChild( $run );
					}
				}

				$modified = true;
			}
		}
			return $modified ? $dom->saveXML() : $xml;
	}

	/**
	 * Build a DOCX text run element.
	 *
	 * @param DOMDocument     $doc      Document.
	 * @param string          $text     Text content.
	 * @param DOMElement|null $base_rpr Base run properties to clone.
	 * @return DOMElement
	 */
	private static function build_docx_text_run( DOMDocument $doc, $text, $base_rpr ) {
		$run = $doc->createElementNS( self::WORD_NAMESPACE, 'w:r' );
		if ( $base_rpr instanceof DOMElement ) {
			$run->appendChild( $base_rpr->cloneNode( true ) );
		}
		$t = $doc->createElementNS( self::WORD_NAMESPACE, 'w:t' );
		$t->setAttribute( 'xml:space', 'preserve' );
		$t->appendChild( $doc->createTextNode( $text ) );
		$run->appendChild( $t );
		return $run;
	}

	/**
	 * Build a map of text content from w:t nodes within a paragraph.
	 *
	 * This coalesces text from multiple w:t nodes into a single string while
	 * tracking the position of each node within that string.
	 *
	 * @param DOMNodeList|false $t_nodes List of w:t nodes.
	 * @return array{text:string,nodes:array<int,array{node:DOMElement,start:int,end:int}>}
	 */
	private static function build_paragraph_text_map( $t_nodes ) {
		$result = array(
			'text'  => '',
			'nodes' => array(),
		);

		if ( ! $t_nodes instanceof DOMNodeList ) {
			return $result;
		}

		$position = 0;
		foreach ( $t_nodes as $node ) {
			if ( ! $node instanceof DOMElement ) {
				continue;
			}
			$text  = $node->textContent; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
			$len   = strlen( $text );
			$start = $position;
			$end   = $position + $len;

			$result['text']   .= $text;
			$result['nodes'][] = array(
				'node'  => $node,
				'start' => $start,
				'end'   => $end,
			);

			$position = $end;
		}

		return $result;
	}

	/**
	 * Prepare rich text values as a lookup table keyed by raw HTML.
	 *
	 * @param array<mixed> $values Potential rich text values.
	 * @return array<string,string>
	 */
	private static function prepare_rich_lookup( $values ) {
		$lookup = array();
		if ( ! is_array( $values ) ) {
			return $lookup;
		}
		foreach ( $values as $value ) {
			if ( ! is_string( $value ) ) {
				continue;
			}
			$value = trim( $value );
			if ( '' === $value ) {
				continue;
			}
			if ( false === strpos( $value, '<' ) || false === strpos( $value, '>' ) ) {
				continue;
			}
			$lookup[ $value ] = $value;
		}
		return $lookup;
	}

	/**
	 * Replace HTML fragments in the generated ODT archive with formatted markup.
	 *
	 * @param string       $odt_path    Generated ODT path.
	 * @param array<mixed> $rich_values Rich text values detected during merge.
	 * @return bool|WP_Error
	 */
	private static function apply_odt_rich_text( $odt_path, $rich_values ) {
		$lookup = self::prepare_rich_lookup( $rich_values );
		if ( empty( $lookup ) ) {
			return true;
		}

		if ( ! class_exists( 'ZipArchive' ) ) {
			return new WP_Error( 'documentate_odt_zip_missing', __( 'ZipArchive no está disponible para aplicar formato enriquecido en ODT.', 'documentate' ) );
		}

		$zip = new ZipArchive();
		if ( true !== $zip->open( $odt_path ) ) {
			return new WP_Error( 'documentate_odt_open_failed', __( 'No se pudo abrir el archivo ODT para aplicar formato enriquecido.', 'documentate' ) );
		}

		$targets = array( 'content.xml', 'styles.xml' );
		foreach ( $targets as $target ) {
			$xml = $zip->getFromName( $target );
			if ( false === $xml ) {
				continue;
			}

			$updated = self::convert_odt_part_rich_text( $xml, $lookup );
			if ( is_wp_error( $updated ) ) {
				$zip->close();
				return $updated;
			}

			if ( $updated !== $xml ) {
				$zip->addFromString( $target, $updated );
			}
		}

		$zip->close();
		return true;
	}

	/**
	 * Convert HTML placeholders inside an ODT XML part to styled markup.
	 *
	 * CHANGE: Expose rich text conversion for direct usage in tests and callers.
	 *
	 * @param string               $xml    Original XML contents.
	 * @param array<string,string> $lookup Rich text lookup table.
	 * @return string|WP_Error
	 */
	public static function convert_odt_part_rich_text( $xml, $lookup ) {
		$lookup = self::prepare_rich_lookup( $lookup ); // CHANGE: Normalize raw lookup values defensively.
		if ( empty( $lookup ) ) {
			return $xml;
		}

		$lookup = self::normalize_lookup_line_endings( $lookup );

		$doc = new DOMDocument();
		$doc->preserveWhiteSpace = false; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
		$doc->formatOutput       = false; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase

		libxml_use_internal_errors( true );
		$loaded = $doc->loadXML( $xml );
		libxml_clear_errors();
		if ( ! $loaded ) {
			return $xml;
		}

		$xpath = new DOMXPath( $doc );
		$xpath->registerNamespace( 'office', self::ODF_OFFICE_NS );
		$xpath->registerNamespace( 'text', self::ODF_TEXT_NS );
		$xpath->registerNamespace( 'style', self::ODF_STYLE_NS );

		$modified      = false;
		$style_require = array();
		$nodes         = $xpath->query( '//text()' );
		if ( $nodes instanceof DOMNodeList ) {
			foreach ( $nodes as $node ) {
				if ( ! $node instanceof DOMText ) {
					continue;
				}
				$changed = self::replace_odt_text_node_html( $node, $lookup, $style_require );
				if ( $changed ) {
					$modified = true;
				}
			}
		}

		if ( $modified ) {
			if ( ! empty( $style_require ) ) {
				self::ensure_odt_styles( $doc, $style_require );
			}
			return $doc->saveXML();
		}

		return $xml;
	}

	/**
	 * Replace HTML fragments inside a DOMText node with formatted ODT nodes.
	 *
	 * @param DOMText              $text_node     Text node to inspect.
	 * @param array<string,string> $lookup        Rich text lookup table.
	 * @param array<string,bool>   $style_require Styles required so far.
	 * @return bool
	 */
	private static function replace_odt_text_node_html( DOMText $text_node, $lookup, array &$style_require ) {
		$value  = self::normalize_text_newlines( $text_node->wholeText ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
		// Decode HTML entities so we can match raw HTML fragments like <table> inside text nodes that contain &lt;table&gt;.
		$value  = html_entity_decode( $value, ENT_QUOTES | ENT_XML1, 'UTF-8' );
		$doc    = $text_node->ownerDocument;
		$parent = $text_node->parentNode; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
		if ( ! $doc || ! $parent ) {
			return false;
		}

		$position = 0;
		$modified = false;

		while ( true ) {
			$match = self::find_next_html_match( $value, $lookup, $position );
			if ( false === $match ) {
				break;
			}

			list( $match_pos, $match_key, $match_raw ) = $match;
			if ( $match_pos > $position ) {
				$segment = substr( $value, $position, $match_pos - $position );
				if ( '' !== $segment ) {
					$parent->insertBefore( $doc->createTextNode( $segment ), $text_node );
				}
			}

			// Use raw HTML for parsing, not the possibly-encoded key.
			$nodes = self::build_odt_inline_nodes( $doc, $match_raw, $style_require );
			foreach ( $nodes as $node ) {
				if ( $node instanceof DOMElement && self::ODF_TABLE_NS === $node->namespaceURI && 'table' === $node->localName ) {
					$target_parent = $parent;
					$reference     = $text_node;

					if ( $parent instanceof DOMElement && self::ODF_TEXT_NS === $parent->namespaceURI && 'p' === $parent->localName && $parent->parentNode ) {
						$target_parent = $parent->parentNode;
						$reference     = $parent->nextSibling;
					}

					if ( $target_parent instanceof DOMNode ) {
						$target_parent->insertBefore( $node, $reference );
					} else {
						$parent->insertBefore( $node, $text_node );
					}
				} else {
					$parent->insertBefore( $node, $text_node );
				}
			}

			// Use key length for position calculation (matches what's in source text).
			$position = $match_pos + strlen( $match_key );
			$modified = true;
		}

		if ( $modified ) {
			$tail = substr( $value, $position );
			if ( '' !== $tail ) {
				$parent->insertBefore( $doc->createTextNode( $tail ), $text_node );
			}
			$parent->removeChild( $text_node );
		}

		return $modified;
	}

	/**
	 * Find the next HTML fragment occurrence within a text string.
	 *
	 * @param string               $text     Source text.
	 * @param array<string,string> $lookup   Lookup table.
	 * @param int                  $position Starting offset.
	 * @return array{int,string,string}|false Position, matched key for length, raw HTML for parsing.
	 */
	private static function find_next_html_match( $text, $lookup, $position ) {
		$found_pos  = false;
		$found_key  = '';
		$found_raw  = '';

		// Normalize the search text newlines to match replace_odt_text_node_html() behavior.
		$normalized_text = self::normalize_text_newlines( $text );

		foreach ( $lookup as $html => $raw ) {
			// Normalize lookup HTML to ensure CRLF/CR mismatches don't prevent matches.
			$normalized_html = self::normalize_text_newlines( $html );

			$pos = strpos( $normalized_text, $normalized_html, $position );
			if ( false === $pos ) {
				continue;
			}

			if (
				false === $found_pos
				|| $pos < $found_pos
				|| ( $pos === $found_pos && strlen( $normalized_html ) > strlen( $found_key ) )
			) {
				$found_pos = $pos;
				$found_key = $normalized_html;
				$found_raw = $raw;
			}
		}

		if ( false === $found_pos ) {
			return false;
		}

		return array( $found_pos, $found_key, $found_raw );
	}

	/**
	 * Build ODT inline nodes for a HTML fragment.
	 *
	 * @param DOMDocument        $doc           Destination document.
	 * @param string             $html          HTML fragment.
	 * @param array<string,bool> $style_require Styles required so far.
	 * @return array<int,DOMNode>
	 */
	private static function build_odt_inline_nodes( DOMDocument $doc, $html, array &$style_require ) {
		$html = trim( (string) $html );
		if ( '' === $html ) {
			return array();
		}

		$tmp = new DOMDocument();
		libxml_use_internal_errors( true );
		// Convert to HTML entities to preserve encoding, then wrap for parsing.
		$encoded = @mb_convert_encoding( $html, 'HTML-ENTITIES', 'UTF-8' );
		$wrapped = '<html><body><div>' . $encoded . '</div></body></html>';
		$loaded  = $tmp->loadHTML( $wrapped );
		libxml_clear_errors();
		if ( ! $loaded ) {
			return array( $doc->createTextNode( $html ) );
		}

		$container = $tmp->getElementsByTagName( 'div' )->item( 0 );
		if ( ! $container ) {
			return array( $doc->createTextNode( $html ) );
		}

		$list_state = array(
			'unordered' => 0,
			'ordered'   => array(),
		);

		$result = array();
		foreach ( $container->childNodes as $child ) {
			$converted = self::convert_html_node_to_odt( $doc, $child, array(), $style_require, $list_state );
			if ( ! empty( $converted ) ) {
				$result = array_merge( $result, $converted );
			}
		}

		self::trim_odt_inline_nodes( $result );
		return $result;
	}

	/**
	 * Convert an HTML node into ODT inline nodes.
	 *
	 * @param DOMDocument         $doc           Target document.
	 * @param DOMNode             $node          HTML node to convert.
	 * @param array<string,mixed> $formatting   Active formatting flags.
	 * @param array<string,bool>  $style_require Styles required so far.
	 * @param array<string,mixed> $list_state   Current list state.
	 * @return array<int,DOMNode>
	 */
	private static function convert_html_node_to_odt( DOMDocument $doc, $node, $formatting, array &$style_require, array &$list_state ) {
		if ( XML_TEXT_NODE === $node->nodeType ) {
			// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
			$text = $node->nodeValue;
			if ( '' === $text ) {
				return array();
			}

			$text_node = $doc->createTextNode( $text );
			return self::wrap_nodes_with_formatting( $doc, array( $text_node ), $formatting, $style_require );
		}

		if ( XML_ELEMENT_NODE !== $node->nodeType ) {
			return array();
		}

		$tag = strtolower( $node->nodeName );
		switch ( $tag ) {
			case 'br':
				return array( $doc->createElementNS( self::ODF_TEXT_NS, 'text:line-break' ) );
			case 'strong':
			case 'b':
				$formatting['bold'] = true;
				return self::collect_html_children_as_odt( $doc, $node, $formatting, $style_require, $list_state );
			case 'em':
			case 'i':
				$formatting['italic'] = true;
				return self::collect_html_children_as_odt( $doc, $node, $formatting, $style_require, $list_state );
			case 'u':
				$formatting['underline'] = true;
				return self::collect_html_children_as_odt( $doc, $node, $formatting, $style_require, $list_state );
			case 'h1':
			case 'h2':
			case 'h3':
			case 'h4':
			case 'h5':
			case 'h6':
				$formatting['bold'] = true;
				$spacing = array(
					$doc->createElementNS( self::ODF_TEXT_NS, 'text:line-break' ),
					$doc->createElementNS( self::ODF_TEXT_NS, 'text:line-break' ),
				);
				$heading_nodes = self::collect_html_children_as_odt( $doc, $node, $formatting, $style_require, $list_state );
				$tail_spacing   = array(
					$doc->createElementNS( self::ODF_TEXT_NS, 'text:line-break' ),
					$doc->createElementNS( self::ODF_TEXT_NS, 'text:line-break' ),
				);
				return array_merge( $spacing, $heading_nodes, $tail_spacing );
			case 'span':
				if ( $node->hasAttribute( 'style' ) ) {
					$style_attr = strtolower( $node->getAttribute( 'style' ) );
					if ( false !== strpos( $style_attr, 'font-weight:bold' ) || false !== strpos( $style_attr, 'font-weight:700' ) ) {
						$formatting['bold'] = true;
					}
					if ( false !== strpos( $style_attr, 'font-style:italic' ) ) {
						$formatting['italic'] = true;
					}
					if ( false !== strpos( $style_attr, 'text-decoration:underline' ) ) {
						$formatting['underline'] = true;
					}
				}
				return self::collect_html_children_as_odt( $doc, $node, $formatting, $style_require, $list_state );
			case 'a':
				$href = trim( $node->getAttribute( 'href' ) );
				if ( '' !== $href ) {
					$formatting['link'] = $href;
				}
				return self::collect_html_children_as_odt( $doc, $node, $formatting, $style_require, $list_state );
			case 'p':
			case 'div':
				$children = self::collect_html_children_as_odt( $doc, $node, $formatting, $style_require, $list_state );
				if ( empty( $children ) ) {
					return array();
				}
				$result = $children;
				$result[] = $doc->createElementNS( self::ODF_TEXT_NS, 'text:line-break' );
				return $result;
			case 'table':
				$row_nodes = self::extract_table_row_nodes( $node );
				if ( empty( $row_nodes ) ) {
					return array();
				}

				$table_element = $doc->createElementNS( self::ODF_TABLE_NS, 'table:table' );
				// Apply default Documentate table style with 1px black borders.
				$table_element->setAttributeNS( self::ODF_TABLE_NS, 'table:style-name', 'DocumentateRichTable' );
				$style_require['table'] = true;
				$row_elements  = array();
				$max_columns   = 0;

				foreach ( $row_nodes as $row ) {
					$row_element = $doc->createElementNS( self::ODF_TABLE_NS, 'table:table-row' );
					$column_count = 0;

					foreach ( $row->childNodes as $cell ) {
						if ( ! $cell instanceof DOMElement ) {
							continue;
						}

						$cell_tag = strtolower( $cell->nodeName );
						if ( 'td' !== $cell_tag && 'th' !== $cell_tag ) {
							continue;
						}

						$cell_formatting = $formatting;
						if ( 'th' === $cell_tag ) {
							$cell_formatting['bold'] = true;
						}

						$cell_element = $doc->createElementNS( self::ODF_TABLE_NS, 'table:table-cell' );
						$cell_element->setAttributeNS( self::ODF_TABLE_NS, 'table:style-name', 'DocumentateRichTableCell' );
						$style_require['table_cell'] = true;
						$paragraph    = $doc->createElementNS( self::ODF_TEXT_NS, 'text:p' );
						$cell_list_state = array(
							'unordered' => 0,
							'ordered'   => array(),
						);
						$cell_nodes = self::collect_html_children_as_odt( $doc, $cell, $cell_formatting, $style_require, $cell_list_state );
						if ( ! empty( $cell_nodes ) ) {
							self::trim_odt_inline_nodes( $cell_nodes );
							foreach ( $cell_nodes as $cell_node ) {
								$paragraph->appendChild( $cell_node );
							}
						} else {
							$paragraph->appendChild( $doc->createTextNode( '' ) );
						}

						$cell_element->appendChild( $paragraph );
						$row_element->appendChild( $cell_element );
						$column_count++;
					}

					if ( $column_count > 0 ) {
						$row_elements[] = $row_element;
						if ( $column_count > $max_columns ) {
							$max_columns = $column_count;
						}
					}
				}

				if ( empty( $row_elements ) ) {
					return array();
				}

				for ( $i = 0; $i < $max_columns; $i++ ) {
					$table_element->appendChild( $doc->createElementNS( self::ODF_TABLE_NS, 'table:table-column' ) );
				}

				foreach ( $row_elements as $row_element ) {
					$table_element->appendChild( $row_element );
				}

				return array(
					$doc->createElementNS( self::ODF_TEXT_NS, 'text:line-break' ),
					$table_element,
					$doc->createElementNS( self::ODF_TEXT_NS, 'text:line-break' ),
				);
			case 'ul':
				$list_state['unordered']++;
				$prev_type                   = $list_state['current_type'] ?? null;
				$list_state['current_type']  = 'ul';
				$items = array();
				foreach ( $node->childNodes as $child ) {
					if ( 'li' !== strtolower( $child->nodeName ) ) {
						continue;
					}
					if ( ! empty( $items ) ) {
						$items[] = $doc->createElementNS( self::ODF_TEXT_NS, 'text:line-break' );
					}
					$items = array_merge( $items, self::convert_html_node_to_odt( $doc, $child, $formatting, $style_require, $list_state ) );
				}
				$list_state['unordered']    = max( 0, $list_state['unordered'] - 1 );
				$list_state['current_type'] = $prev_type;
				return $items;
			case 'ol':
				$list_state['ordered'][]     = 1;
				$prev_type                   = $list_state['current_type'] ?? null;
				$list_state['current_type']  = 'ol';
				$ordered = array();
				foreach ( $node->childNodes as $child ) {
					if ( 'li' !== strtolower( $child->nodeName ) ) {
						continue;
					}
					if ( ! empty( $ordered ) ) {
						$ordered[] = $doc->createElementNS( self::ODF_TEXT_NS, 'text:line-break' );
					}
					$ordered = array_merge( $ordered, self::convert_html_node_to_odt( $doc, $child, $formatting, $style_require, $list_state ) );
				}
				array_pop( $list_state['ordered'] );
				$list_state['current_type'] = $prev_type;
				return $ordered;
			case 'li':
				$line = array();
				$current_type = $list_state['current_type'] ?? '';
				if ( 'ol' === $current_type && ! empty( $list_state['ordered'] ) ) {
					$index                     = count( $list_state['ordered'] ) - 1;
					$number                    = $list_state['ordered'][ $index ];
					$prefix                    = $number . '. ';
					$line                      = self::wrap_nodes_with_formatting( $doc, array( $doc->createTextNode( $prefix ) ), $formatting, $style_require );
					$list_state['ordered'][ $index ]++;
				} elseif ( 'ul' === $current_type || $list_state['unordered'] > 0 ) {
					$indent = str_repeat( '  ', max( 0, $list_state['unordered'] - 1 ) );
					$bullet = $indent . '• ';
					$line   = self::wrap_nodes_with_formatting( $doc, array( $doc->createTextNode( $bullet ) ), $formatting, $style_require );
				}
				$children = self::collect_html_children_as_odt( $doc, $node, $formatting, $style_require, $list_state );
				$line     = array_merge( $line, $children );
				return $line;
			default:
				return self::collect_html_children_as_odt( $doc, $node, $formatting, $style_require, $list_state );
		}
	}

	/**
	 * Convert all child nodes for the provided HTML node.
	 *
	 * @param DOMDocument         $doc           Target document.
	 * @param DOMNode             $node          HTML node.
	 * @param array<string,mixed> $formatting   Active formatting flags.
	 * @param array<string,bool>  $style_require Styles required so far.
	 * @param array<string,mixed> $list_state   Current list state.
	 * @return array<int,DOMNode>
	 */
	private static function collect_html_children_as_odt( DOMDocument $doc, DOMNode $node, $formatting, array &$style_require, array &$list_state ) {
		$result = array();
		foreach ( $node->childNodes as $child ) {
			$converted = self::convert_html_node_to_odt( $doc, $child, $formatting, $style_require, $list_state );
			if ( ! empty( $converted ) ) {
				$result = array_merge( $result, $converted );
			}
		}
		return $result;
	}

	/**
	 * Extract <tr> nodes from table-related containers preserving order.
	 *
	 * @param DOMNode $node Table DOM node.
	 * @return array<int,DOMElement>
	 */
	private static function extract_table_row_nodes( DOMNode $node ) {
		$rows = array();
		foreach ( $node->childNodes as $child ) {
			if ( ! $child instanceof DOMElement ) {
				continue;
			}
			$tag = strtolower( $child->nodeName );
			if ( 'tr' === $tag ) {
				$rows[] = $child;
				continue;
			}
			if ( in_array( $tag, array( 'thead', 'tbody', 'tfoot' ), true ) ) {
				$rows = array_merge( $rows, self::extract_table_row_nodes( $child ) );
			}
		}
		return $rows;
	}

	/**
	 * Normalize line endings for lookup keys to improve HTML fragment matching.
	 *
	 * @param array<string,string> $lookup Original lookup table.
	 * @return array<string,string>
	 */
	private static function normalize_lookup_line_endings( array $lookup ) {
		$normalized = array();
		foreach ( $lookup as $html => $raw ) {
			$normalized_html  = self::normalize_text_newlines( $html );
			$normalized_value = self::normalize_text_newlines( $raw );

			$normalized[ $normalized_html ] = $normalized_value;

			// Also add HTML-encoded version.
			$encoded = htmlspecialchars( $html, ENT_QUOTES | ENT_XML1 );
			$encoded = self::normalize_text_newlines( $encoded );
			if ( $encoded !== $normalized_html ) {
				$normalized[ $encoded ] = $normalized_value;
			}

			// Also add version with whitespace between tags removed.
			// TBS converts newlines to <w:br/> which get stripped when coalescing text.
			$collapsed = self::collapse_html_whitespace( $normalized_html );
			if ( $collapsed !== $normalized_html && ! isset( $normalized[ $collapsed ] ) ) {
				$normalized[ $collapsed ] = $normalized_value;
			}
		}
		return $normalized;
	}

	/**
	 * Collapse whitespace around HTML tags to enable matching when TBS strips newlines.
	 *
	 * When TBS places HTML with newlines into a document, it converts them to <w:br/>
	 * elements which are not included in the text content. This function creates
	 * a version of the HTML without whitespace around tags for matching purposes.
	 *
	 * @param string $html HTML string.
	 * @return string HTML with whitespace around tags collapsed.
	 */
	private static function collapse_html_whitespace( $html ) {
		// Remove whitespace between > and <
		$collapsed = preg_replace( '/>\s+</', '><', $html );
		// Remove whitespace before < (after text content).
		$collapsed = preg_replace( '/\s+</', '<', $collapsed );
		// Remove whitespace after > (before text content).
		$collapsed = preg_replace( '/>\s+/', '>', $collapsed );
		// Trim leading/trailing whitespace.
		return trim( $collapsed );
	}

	/**
	 * Normalize literal newline escape sequences and CR characters to LF.
	 *
	 * @param string $value Source value.
	 * @return string
	 */
	private static function normalize_text_newlines( $value ) {
		$value = (string) $value;
		$value = preg_replace( '/\\\\r\\\\n|\\\\n|\\\\r/', "\n", $value );
		if ( ! is_string( $value ) ) {
			$value = '';
		}
		return str_replace( array( "\r\n", "\r" ), "\n", $value );
	}

	/**
	 * Apply formatting wrappers to a list of nodes.
	 *
	 * @param DOMDocument         $doc           Target document.
	 * @param array<int,DOMNode>  $nodes         Nodes to wrap.
	 * @param array<string,mixed> $formatting   Active formatting flags.
	 * @param array<string,bool>  $style_require Styles required so far.
	 * @return array<int,DOMNode>
	 */
	private static function wrap_nodes_with_formatting( DOMDocument $doc, array $nodes, $formatting, array &$style_require ) {
		$result = $nodes;
		if ( empty( $result ) ) {
			return $result;
		}

		if ( ! empty( $formatting['bold'] ) ) {
			$style_require['bold'] = true;
			$span = $doc->createElementNS( self::ODF_TEXT_NS, 'text:span' );
			$span->setAttributeNS( self::ODF_TEXT_NS, 'text:style-name', 'DocumentateRichBold' );
			foreach ( $result as $child ) {
				$span->appendChild( $child );
			}
			$result = array( $span );
		}

		if ( ! empty( $formatting['italic'] ) ) {
			$style_require['italic'] = true;
			$span = $doc->createElementNS( self::ODF_TEXT_NS, 'text:span' );
			$span->setAttributeNS( self::ODF_TEXT_NS, 'text:style-name', 'DocumentateRichItalic' );
			foreach ( $result as $child ) {
				$span->appendChild( $child );
			}
			$result = array( $span );
		}

		if ( ! empty( $formatting['underline'] ) ) {
			$style_require['underline'] = true;
			$span = $doc->createElementNS( self::ODF_TEXT_NS, 'text:span' );
			$span->setAttributeNS( self::ODF_TEXT_NS, 'text:style-name', 'DocumentateRichUnderline' );
			foreach ( $result as $child ) {
				$span->appendChild( $child );
			}
			$result = array( $span );
		}

		if ( ! empty( $formatting['link'] ) ) {
			$href = (string) $formatting['link'];
			$link = $doc->createElementNS( self::ODF_TEXT_NS, 'text:a' );
			$link->setAttributeNS( self::ODF_XLINK_NS, 'xlink:href', $href );
			$link->setAttributeNS( self::ODF_XLINK_NS, 'xlink:type', 'simple' );
			$link->setAttributeNS( self::ODF_TEXT_NS, 'text:style-name', 'DocumentateRichLink' );
			$style_require['link'] = true;
			foreach ( $result as $child ) {
				$link->appendChild( $child );
			}
			$result = array( $link );
		}

		return $result;
	}

	/**
	 * Trim trailing line-break elements from the generated node list.
	 *
	 * @param array<int,DOMNode> $nodes Node list reference.
	 * @return void
	 */
	private static function trim_odt_inline_nodes( array &$nodes ) {
		while ( ! empty( $nodes ) ) {
			$last = end( $nodes );
			if ( $last instanceof DOMElement && self::ODF_TEXT_NS === $last->namespaceURI && 'line-break' === $last->localName ) {
				array_pop( $nodes );
				continue;
			}
			if ( $last instanceof DOMText ) {

				$value   = $last->nodeValue;
				$trimmed = rtrim( $value, "\r\n" );
				if ( $trimmed !== $value ) {
					if ( '' === $trimmed ) {
						array_pop( $nodes );
						continue;
					}
					$last->nodeValue = $trimmed;
				}
			}
			break;
		}
	}

	/**
	 * Ensure automatic styles required for HTML conversion are present.
	 *
	 * @param DOMDocument        $doc           XML document.
	 * @param array<string,bool> $style_require Styles that must exist.
	 * @return void
	 */
	private static function ensure_odt_styles( DOMDocument $doc, array $style_require ) {
		if ( empty( $style_require ) ) {
			return;
		}

		$xpath = new DOMXPath( $doc );
		$xpath->registerNamespace( 'office', self::ODF_OFFICE_NS );
		$xpath->registerNamespace( 'style', self::ODF_STYLE_NS );
		$xpath->registerNamespace( 'text', self::ODF_TEXT_NS );
		$xpath->registerNamespace( 'fo', self::ODF_FO_NS );
		$xpath->registerNamespace( 'table', self::ODF_TABLE_NS );

		$auto = $xpath->query( '/*/office:automatic-styles' )->item( 0 );
		if ( ! $auto instanceof DOMElement ) {
			$root = $doc->documentElement;
			if ( ! $root instanceof DOMElement ) {
				return;
			}
			$auto = $doc->createElementNS( self::ODF_OFFICE_NS, 'office:automatic-styles' );
			$root->insertBefore( $auto, $root->firstChild );
		}

		$styles = array(
			'bold'      => array(
				'name'   => 'DocumentateRichBold',
				'family' => 'text',
				'props'  => array(
					array(
						'ns' => self::ODF_FO_NS,
						'name' => 'fo:font-weight',
						'value' => 'bold',
					),
					array(
						'ns' => self::ODF_STYLE_NS,
						'name' => 'style:font-weight-asian',
						'value' => 'bold',
					),
					array(
						'ns' => self::ODF_STYLE_NS,
						'name' => 'style:font-weight-complex',
						'value' => 'bold',
					),
				),
			),
			'italic'    => array(
				'name'   => 'DocumentateRichItalic',
				'family' => 'text',
				'props'  => array(
					array(
						'ns' => self::ODF_FO_NS,
						'name' => 'fo:font-style',
						'value' => 'italic',
					),
					array(
						'ns' => self::ODF_STYLE_NS,
						'name' => 'style:font-style-asian',
						'value' => 'italic',
					),
					array(
						'ns' => self::ODF_STYLE_NS,
						'name' => 'style:font-style-complex',
						'value' => 'italic',
					),
				),
			),
			'underline' => array(
				'name'   => 'DocumentateRichUnderline',
				'family' => 'text',
				'props'  => array(
					array(
						'ns' => self::ODF_STYLE_NS,
						'name' => 'style:text-underline-style',
						'value' => 'solid',
					),
					array(
						'ns' => self::ODF_STYLE_NS,
						'name' => 'style:text-underline-width',
						'value' => 'auto',
					),
					array(
						'ns' => self::ODF_STYLE_NS,
						'name' => 'style:text-underline-color',
						'value' => 'font-color',
					),
				),
			),
			'link'      => array(
				'name'   => 'DocumentateRichLink',
				'family' => 'text',
				'props'  => array(
					array(
						'ns' => self::ODF_FO_NS,
						'name' => 'fo:color',
						'value' => '#0000FF',
					),
					array(
						'ns' => self::ODF_STYLE_NS,
						'name' => 'style:text-underline-style',
						'value' => 'solid',
					),
					array(
						'ns' => self::ODF_STYLE_NS,
						'name' => 'style:text-underline-width',
						'value' => 'auto',
					),
					array(
						'ns' => self::ODF_STYLE_NS,
						'name' => 'style:text-underline-color',
						'value' => 'font-color',
					),
				),
			),
			'table'     => array(
				'name'   => 'DocumentateRichTable',
				'family' => 'table',
				'props'  => array(
					array(
						'ns' => self::ODF_TABLE_NS,
						'name' => 'table:border-model',
						'value' => 'collapsing',
					),
					array(
						'ns' => self::ODF_FO_NS,
						'name' => 'fo:border',
						'value' => '0.5pt solid #000000',
					),
				),
			),
			'table_cell' => array(
				'name'   => 'DocumentateRichTableCell',
				'family' => 'table-cell',
				'props'  => array(
					array(
						'ns' => self::ODF_FO_NS,
						'name' => 'fo:border',
						'value' => '0.5pt solid #000000',
					),
				),
			),
		);

		foreach ( $style_require as $key => $flag ) {
			if ( empty( $flag ) || ! isset( $styles[ $key ] ) ) {
				continue;
			}
			$info = $styles[ $key ];
			$exists = $xpath->query( './/style:style[@style:name="' . $info['name'] . '"]', $auto );
			if ( $exists instanceof DOMNodeList && $exists->length > 0 ) {
				continue;
			}
			$style = $doc->createElementNS( self::ODF_STYLE_NS, 'style:style' );
			$style->setAttributeNS( self::ODF_STYLE_NS, 'style:name', $info['name'] );
			$style->setAttributeNS( self::ODF_STYLE_NS, 'style:family', $info['family'] );
			// Select the correct properties element depending on the family.
			if ( 'text' === $info['family'] ) {
				$props = $doc->createElementNS( self::ODF_STYLE_NS, 'style:text-properties' );
			} elseif ( 'table' === $info['family'] ) {
				$props = $doc->createElementNS( self::ODF_STYLE_NS, 'style:table-properties' );
			} elseif ( 'table-cell' === $info['family'] ) {
				$props = $doc->createElementNS( self::ODF_STYLE_NS, 'style:table-cell-properties' );
			} else {
				$props = $doc->createElementNS( self::ODF_STYLE_NS, 'style:text-properties' );
			}
			foreach ( $info['props'] as $prop ) {
				$props->setAttributeNS( $prop['ns'], $prop['name'], $prop['value'] );
			}
			$style->appendChild( $props );
			$auto->appendChild( $style );
		}
	}

	/**
	 * Build WordprocessingML runs that mimic the provided HTML fragment.
	 *
	 * @param DOMDocument              $doc      Base DOMDocument for namespace context.
	 * @param string                   $html     HTML fragment.
	 * @param DOMElement|null          $base_rpr Base run properties to clone.
	 * @param array<string,mixed>|null $relationships  Relationships context, passed by reference.
	 * @return array<int, DOMElement>
	 */
	private static function build_docx_nodes_from_html( DOMDocument $doc, $html, $base_rpr = null, &$relationships = null ) {
		$html = trim( (string) $html );
		if ( '' === $html ) {
			return array(
				'block' => false,
				'nodes' => array(),
			);
		}

		$tmp = new DOMDocument();
		libxml_use_internal_errors( true );
		// Convert to HTML entities to preserve UTF-8 encoding, then wrap for parsing.
		$encoded = @mb_convert_encoding( $html, 'HTML-ENTITIES', 'UTF-8' );
		$wrapped = '<html><body><div>' . $encoded . '</div></body></html>';
		$loaded  = $tmp->loadHTML( $wrapped );
		libxml_clear_errors();
		if ( ! $loaded ) {
			return array(
				'block' => false,
				'nodes' => array(),
			);
		}

		$body = $tmp->getElementsByTagName( 'div' )->item( 0 );
		if ( ! $body ) {
			return array(
				'block' => false,
				'nodes' => array(),
			);
		}

		$conversion = self::convert_html_children_to_docx( $doc, $body->childNodes, $base_rpr, array(), $relationships, true );
		if ( empty( $conversion['nodes'] ) ) {
			return array(
				'block' => false,
				'nodes' => array(),
			);
		}

		return $conversion;
	}

	/**
	 * Build inline run nodes from HTML without introducing block structures.
	 *
	 * @param DOMDocument              $doc           Target DOMDocument.
	 * @param string                   $html          HTML fragment.
	 * @param DOMElement|null          $base_rpr      Base run properties to clone.
	 * @param array<string,mixed>|null $relationships Relationships context.
	 * @return array<int,DOMElement>
	 */
	private static function build_docx_inline_runs_from_html( DOMDocument $doc, $html, $base_rpr = null, &$relationships = null ) {
		$html = trim( (string) $html );
		if ( '' === $html ) {
			return array();
		}

		$tmp = new DOMDocument();
		libxml_use_internal_errors( true );
		// Convert to HTML entities to preserve UTF-8 encoding, then wrap for parsing.
		$encoded = mb_convert_encoding( $html, 'HTML-ENTITIES', 'UTF-8' );
		$wrapped = '<html><body><div>' . $encoded . '</div></body></html>';
		$loaded  = $tmp->loadHTML( $wrapped );
		libxml_clear_errors();
		if ( ! $loaded ) {
			return array();
		}

		$body = $tmp->getElementsByTagName( 'div' )->item( 0 );
		if ( ! $body ) {
			return array();
		}

		$runs = self::collect_runs_from_children( $doc, $body->childNodes, $base_rpr, array(), $relationships );
		self::trim_trailing_break_runs( $runs );
		return $runs;
	}

	/**
	 * Convert a list of HTML nodes to WordprocessingML structures.
	 *
	 * @param DOMDocument              $doc                 Target DOMDocument.
	 * @param DOMNodeList              $nodes               HTML nodes to convert.
	 * @param DOMElement|null          $base_rpr            Base run properties to clone.
	 * @param array<string,bool>       $formatting          Active formatting flags.
	 * @param array<string,mixed>|null $relationships       Relationships context, passed by reference.
	 * @param bool                     $allow_inline_result Whether inline-only results are allowed.
	 * @return array{block:bool,nodes:array<int,DOMElement>}
	 */
	private static function convert_html_children_to_docx( DOMDocument $doc, $nodes, $base_rpr, array $formatting, &$relationships, $allow_inline_result ) {
		$result       = array();
		$current_runs = array();
		$has_block    = false;

		if ( ! $nodes instanceof DOMNodeList ) {
			return array(
				'block' => false,
				'nodes' => array(),
			);
		}

		foreach ( $nodes as $node ) {
			if ( XML_TEXT_NODE === $node->nodeType ) {
				$current_runs = array_merge( $current_runs, self::collect_runs_from_text( $doc, $node, $base_rpr, $formatting ) );
				continue;
			}

			if ( ! $node instanceof DOMElement ) {
				continue;
			}

			$tag = strtolower( $node->nodeName );
			if ( self::is_block_tag( $tag ) ) {
				if ( ! empty( $current_runs ) ) {
					$result[]     = self::create_paragraph_from_runs( $doc, $current_runs, $base_rpr );
					$current_runs = array();
				}

				switch ( $tag ) {
					case 'h1':
					case 'h2':
					case 'h3':
					case 'h4':
					case 'h5':
					case 'h6':
						$has_block = true;
						$result    = array_merge(
							$result,
							self::convert_heading_node_to_paragraphs( $doc, $node, $base_rpr, $relationships, $tag )
						);
						break;
					case 'table':
						$has_block = true;
						$table     = self::convert_table_node_to_docx( $doc, $node, $base_rpr, $relationships );
						if ( $table ) {
							$result[] = $table;
						}
						break;
					case 'ul':
					case 'ol':
						$has_block = true;
						$list_paragraphs = self::convert_list_to_paragraphs( $doc, $node, $base_rpr, $formatting, 'ol' === $tag, $relationships );
						if ( ! empty( $list_paragraphs ) ) {
							$result = array_merge( $result, $list_paragraphs );
						}
						break;
					default:
						$has_block        = true;
						$paragraph_runs   = self::collect_runs_from_children( $doc, $node->childNodes, $base_rpr, $formatting, $relationships );
						$block_paragraph  = self::create_paragraph_from_runs( $doc, $paragraph_runs, $base_rpr );
						$result[]         = $block_paragraph;
				}
			} else {
				$current_runs = array_merge(
					$current_runs,
					self::collect_runs_from_element( $doc, $node, $base_rpr, $formatting, $relationships )
				);
			}
		}

		if ( ! empty( $current_runs ) ) {
			if ( $has_block || ! $allow_inline_result ) {
				$result[] = self::create_paragraph_from_runs( $doc, $current_runs, $base_rpr );
				$current_runs = array();
			}
		}

		if ( $has_block || ! $allow_inline_result ) {
			return array(
				'block' => true,
				'nodes' => $result,
			);
		}

		return array(
			'block' => false,
			'nodes' => $current_runs,
		);
	}

	/**
	 * Determine whether a tag should be treated as block-level during conversion.
	 *
	 * @param string $tag Lowercase tag name.
	 * @return bool
	 */
	private static function is_block_tag( $tag ) {
		$block_tags = array( 'p', 'div', 'section', 'article', 'blockquote', 'address', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'ul', 'ol', 'table' );
		return in_array( $tag, $block_tags, true );
	}

	/**
	 * Collect runs for all children of a node.
	 *
	 * @param DOMDocument              $doc           Target DOMDocument.
	 * @param DOMNodeList              $children      Node list.
	 * @param DOMElement|null          $base_rpr      Base run properties.
	 * @param array<string,bool>       $formatting    Active formatting.
	 * @param array<string,mixed>|null $relationships Relationships context.
	 * @return array<int,DOMElement>
	 */
	private static function collect_runs_from_children( DOMDocument $doc, $children, $base_rpr, array $formatting, &$relationships ) {
		$runs = array();
		if ( ! $children instanceof DOMNodeList ) {
			return $runs;
		}

		foreach ( $children as $child ) {
			$runs = array_merge( $runs, self::collect_runs_from_node( $doc, $child, $base_rpr, $formatting, $relationships ) );
		}

		return $runs;
	}

	/**
	 * Collect runs from a single DOM node according to formatting flags.
	 *
	 * @param DOMDocument              $doc           Target DOMDocument.
	 * @param DOMNode                  $node          Node to convert.
	 * @param DOMElement|null          $base_rpr      Base run properties.
	 * @param array<string,bool>       $formatting    Formatting flags.
	 * @param array<string,mixed>|null $relationships Relationships context.
	 * @return array<int,DOMElement>
	 */
	private static function collect_runs_from_node( DOMDocument $doc, $node, $base_rpr, array $formatting, &$relationships ) {
		if ( XML_TEXT_NODE === $node->nodeType ) {
			return self::collect_runs_from_text( $doc, $node, $base_rpr, $formatting );
		}

		if ( ! $node instanceof DOMElement ) {
			return array();
		}

		return self::collect_runs_from_element( $doc, $node, $base_rpr, $formatting, $relationships );
	}

	/**
	 * Convert a text node into formatted runs.
	 *
	 * @param DOMDocument        $doc        Target DOMDocument.
	 * @param DOMText            $text_node  Text node.
	 * @param DOMElement|null    $base_rpr   Base run properties to clone.
	 * @param array<string,bool> $formatting Formatting flags.
	 * @return array<int,DOMElement>
	 */
	private static function collect_runs_from_text( DOMDocument $doc, DOMText $text_node, $base_rpr, array $formatting ) {
		$text  = str_replace( array( "\r\n", "\r" ), "\n", $text_node->wholeText );
		$parts = explode( "\n", $text );
		$runs  = array();

		foreach ( $parts as $index => $part ) {
			if ( '' !== $part ) {
				$run = self::create_text_run( $doc, $part, $base_rpr, $formatting );
				if ( $run ) {
					$runs[] = $run;
				}
			}

			if ( $index < count( $parts ) - 1 ) {
				$runs[] = self::create_break_run( $doc, $base_rpr );
			}
		}

		return $runs;
	}

	/**
	 * Convert an element node into formatted runs.
	 *
	 * @param DOMDocument              $doc           Target DOMDocument.
	 * @param DOMElement               $element       Element node.
	 * @param DOMElement|null          $base_rpr      Base run properties to clone.
	 * @param array<string,bool>       $formatting    Active formatting flags.
	 * @param array<string,mixed>|null $relationships Relationships context.
	 * @return array<int,DOMElement>
	 */
	private static function collect_runs_from_element( DOMDocument $doc, DOMElement $element, $base_rpr, array $formatting, &$relationships ) {
		$tag = strtolower( $element->nodeName );
		switch ( $tag ) {
			case 'strong':
			case 'b':
				return self::collect_runs_from_children( $doc, $element->childNodes, $base_rpr, self::with_format_flag( $formatting, 'bold', true ), $relationships );
			case 'em':
			case 'i':
				return self::collect_runs_from_children( $doc, $element->childNodes, $base_rpr, self::with_format_flag( $formatting, 'italic', true ), $relationships );
			case 'u':
				return self::collect_runs_from_children( $doc, $element->childNodes, $base_rpr, self::with_format_flag( $formatting, 'underline', true ), $relationships );
			case 'span':
				return self::collect_runs_from_children( $doc, $element->childNodes, $base_rpr, self::extract_span_formatting( $formatting, $element ), $relationships );
			case 'br':
				return array( self::create_break_run( $doc, $base_rpr ) );
			case 'a':
				$href = trim( $element->getAttribute( 'href' ) );
				$link_formatting = self::with_format_flag( $formatting, 'hyperlink', true );
				$link_formatting = self::with_format_flag( $link_formatting, 'underline', true );
				$link_runs       = self::collect_runs_from_children( $doc, $element->childNodes, $base_rpr, $link_formatting, $relationships );

				if ( empty( $link_runs ) && '' !== $href ) {
					$fallback_run = self::create_text_run( $doc, $href, $base_rpr, $link_formatting );
					if ( $fallback_run ) {
						$link_runs[] = $fallback_run;
					}
				}

				$hyperlink = self::create_hyperlink_container( $doc, $link_runs, $relationships, $href );
				if ( $hyperlink ) {
					return array( $hyperlink );
				}

				return $link_runs;
			default:
				return self::collect_runs_from_children( $doc, $element->childNodes, $base_rpr, $formatting, $relationships );
		}
	}

	/**
	 * Convert a heading element into paragraphs with spacing.
	 *
	 * @param DOMDocument              $doc           Target DOMDocument.
	 * @param DOMElement               $heading       Heading element.
	 * @param DOMElement|null          $base_rpr      Base run properties.
	 * @param array<string,mixed>|null $relationships Relationships context.
	 * @param string                   $tag           Heading tag name.
	 * @return array<int,DOMElement>
	 */
	private static function convert_heading_node_to_paragraphs( DOMDocument $doc, DOMElement $heading, $base_rpr, &$relationships, $tag ) {
		$paragraphs   = array();
		$paragraphs[] = self::create_blank_paragraph( $doc, $base_rpr );

		$formatting = self::with_format_flag( array(), 'bold', true );
		$runs       = self::collect_runs_from_children( $doc, $heading->childNodes, $base_rpr, $formatting, $relationships );
		$heading_p  = self::create_paragraph_from_runs( $doc, $runs, $base_rpr );
		$paragraphs[] = $heading_p;

		$paragraphs[] = self::create_blank_paragraph( $doc, $base_rpr );

		return $paragraphs;
	}

	/**
	 * Convert a HTML list into basic paragraphs with bullet or numeric prefixes.
	 *
	 * @param DOMDocument              $doc           Target DOMDocument.
	 * @param DOMElement               $list          List element.
	 * @param DOMElement|null          $base_rpr      Base run properties.
	 * @param array<string,bool>       $formatting    Active formatting flags.
	 * @param bool                     $ordered       Whether ordered list.
	 * @param array<string,mixed>|null $relationships Relationships context.
	 * @param int                      $depth         Nesting depth for indentation.
	 * @return array<int,DOMElement>
	 */
	private static function convert_list_to_paragraphs( DOMDocument $doc, DOMElement $list, $base_rpr, array $formatting, $ordered, &$relationships, $depth = 0 ) {
		$paragraphs = array();
		$index      = 1;
		$indent     = str_repeat( '  ', $depth );

		foreach ( $list->childNodes as $item ) {
			if ( ! $item instanceof DOMElement || 'li' !== strtolower( $item->nodeName ) ) {
				continue;
			}

			$prefix = $ordered ? $indent . $index . '. ' : $indent . '• ';
			$runs   = array();

			$prefix_run = self::create_text_run( $doc, $prefix, $base_rpr, $formatting );
			if ( $prefix_run ) {
				$runs[] = $prefix_run;
			}

			// Collect runs from inline content, but handle nested lists separately.
			$nested_lists = array();
			foreach ( $item->childNodes as $child ) {
				if ( $child instanceof DOMElement ) {
					$child_tag = strtolower( $child->nodeName );
					if ( 'ul' === $child_tag || 'ol' === $child_tag ) {
						$nested_lists[] = array(
							'node' => $child,
							'ordered' => 'ol' === $child_tag,
						);
						continue;
					}
				}
				// Collect runs from non-list child nodes.
				if ( XML_TEXT_NODE === $child->nodeType ) {
					$runs = array_merge( $runs, self::collect_runs_from_text( $doc, $child, $base_rpr, $formatting ) );
				} elseif ( $child instanceof DOMElement ) {
					$runs = array_merge( $runs, self::collect_runs_from_element( $doc, $child, $base_rpr, $formatting, $relationships ) );
				}
			}

			$paragraphs[] = self::create_paragraph_from_runs( $doc, $runs, $base_rpr );

			// Process nested lists recursively.
			foreach ( $nested_lists as $nested ) {
				$nested_paragraphs = self::convert_list_to_paragraphs( $doc, $nested['node'], $base_rpr, $formatting, $nested['ordered'], $relationships, $depth + 1 );
				$paragraphs        = array_merge( $paragraphs, $nested_paragraphs );
			}

			$index++;
		}

		return $paragraphs;
	}

	/**
	 * Convert an HTML table node into a WordprocessingML table.
	 *
	 * @param DOMDocument              $doc           Target DOMDocument.
	 * @param DOMElement               $table         Table element.
	 * @param DOMElement|null          $base_rpr      Base run properties.
	 * @param array<string,mixed>|null $relationships Relationships context.
	 * @return DOMElement|null
	 */
	private static function convert_table_node_to_docx( DOMDocument $doc, DOMElement $table, $base_rpr, &$relationships ) {
		$rows = self::extract_table_row_nodes( $table );
		if ( empty( $rows ) ) {
			return null;
		}

		$tbl = $doc->createElementNS( self::WORD_NAMESPACE, 'w:tbl' );
		// Add default table borders: 1px black for all edges and inner lines.
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- $tblPr matches WordprocessingML spec (w:tblPr).
		$tblPr    = $doc->createElementNS( self::WORD_NAMESPACE, 'w:tblPr' );
		$borders  = $doc->createElementNS( self::WORD_NAMESPACE, 'w:tblBorders' );
		$edges    = array( 'top', 'left', 'bottom', 'right', 'insideH', 'insideV' );
		foreach ( $edges as $edge ) {
			$el = $doc->createElementNS( self::WORD_NAMESPACE, 'w:' . $edge );
			$el->setAttribute( 'w:val', 'single' );
			$el->setAttribute( 'w:sz', '8' );
			$el->setAttribute( 'w:space', '0' );
			$el->setAttribute( 'w:color', '000000' );
			$borders->appendChild( $el );
		}
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- $tblPr matches WordprocessingML spec.
		$tblPr->appendChild( $borders );
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- $tblPr matches WordprocessingML spec.
		$tbl->appendChild( $tblPr );

		foreach ( $rows as $row ) {
			$tr = $doc->createElementNS( self::WORD_NAMESPACE, 'w:tr' );

			foreach ( $row->childNodes as $cell ) {
				if ( ! $cell instanceof DOMElement ) {
					continue;
				}

				$cell_tag = strtolower( $cell->nodeName );
				if ( 'td' !== $cell_tag && 'th' !== $cell_tag ) {
					continue;
				}

				$tc = $doc->createElementNS( self::WORD_NAMESPACE, 'w:tc' );

				$cell_formatting = array();
				if ( 'th' === $cell_tag ) {
					$cell_formatting['bold'] = true;
				}

				// Handle block elements (lists, nested tables) inside table cells.
				$cell_content = self::convert_cell_content_to_docx( $doc, $cell->childNodes, $base_rpr, $cell_formatting, $relationships );
				foreach ( $cell_content as $content_node ) {
					if ( $content_node instanceof DOMElement ) {
						$tc->appendChild( $content_node );
					}
				}

				// Ensure cell has at least one paragraph (required by OOXML).
				if ( 0 === $tc->childNodes->length ) {
					$tc->appendChild( self::create_blank_paragraph( $doc, $base_rpr ) );
				}

				$tr->appendChild( $tc );
			}

			if ( $tr->childNodes->length > 0 ) {
				$tbl->appendChild( $tr );
			}
		}

		if ( 0 === $tbl->childNodes->length ) {
			return null;
		}

		return $tbl;
	}

	/**
	 * Convert table cell content to DOCX nodes, handling both inline and block elements.
	 *
	 * Unlike collect_runs_from_children which only handles inline content, this method
	 * properly converts block elements like lists and nested tables inside table cells.
	 *
	 * @param DOMDocument              $doc           Target DOMDocument.
	 * @param DOMNodeList              $children      Child nodes of the cell.
	 * @param DOMElement|null          $base_rpr      Base run properties.
	 * @param array<string,bool>       $formatting    Formatting flags.
	 * @param array<string,mixed>|null $relationships Relationships context.
	 * @return array<int,DOMElement>   Array of paragraph elements.
	 */
	private static function convert_cell_content_to_docx( DOMDocument $doc, $children, $base_rpr, array $formatting, &$relationships ) {
		$result       = array();
		$current_runs = array();

		if ( ! $children instanceof DOMNodeList ) {
			return $result;
		}

		foreach ( $children as $child ) {
			if ( XML_TEXT_NODE === $child->nodeType ) {
				$current_runs = array_merge( $current_runs, self::collect_runs_from_text( $doc, $child, $base_rpr, $formatting ) );
				continue;
			}

			if ( ! $child instanceof DOMElement ) {
				continue;
			}

			$tag = strtolower( $child->nodeName );

			// Handle block elements.
			if ( 'ul' === $tag || 'ol' === $tag ) {
				// Flush any accumulated inline runs.
				if ( ! empty( $current_runs ) ) {
					$result[]     = self::create_paragraph_from_runs( $doc, $current_runs, $base_rpr );
					$current_runs = array();
				}
				// Convert list to paragraphs.
				$list_paragraphs = self::convert_list_to_paragraphs( $doc, $child, $base_rpr, $formatting, 'ol' === $tag, $relationships );
				$result          = array_merge( $result, $list_paragraphs );
				continue;
			}

			if ( 'table' === $tag ) {
				// Flush any accumulated inline runs.
				if ( ! empty( $current_runs ) ) {
					$result[]     = self::create_paragraph_from_runs( $doc, $current_runs, $base_rpr );
					$current_runs = array();
				}
				// Convert nested table.
				$nested_table = self::convert_table_node_to_docx( $doc, $child, $base_rpr, $relationships );
				if ( $nested_table ) {
					$result[] = $nested_table;
				}
				continue;
			}

			if ( 'p' === $tag || 'div' === $tag ) {
				// Flush any accumulated inline runs.
				if ( ! empty( $current_runs ) ) {
					$result[]     = self::create_paragraph_from_runs( $doc, $current_runs, $base_rpr );
					$current_runs = array();
				}
				// Convert paragraph content.
				$p_runs   = self::collect_runs_from_children( $doc, $child->childNodes, $base_rpr, $formatting, $relationships );
				$result[] = self::create_paragraph_from_runs( $doc, $p_runs, $base_rpr );
				continue;
			}

			// Handle inline elements.
			$current_runs = array_merge( $current_runs, self::collect_runs_from_element( $doc, $child, $base_rpr, $formatting, $relationships ) );
		}

		// Flush remaining inline runs.
		if ( ! empty( $current_runs ) ) {
			$result[] = self::create_paragraph_from_runs( $doc, $current_runs, $base_rpr );
		}

		return $result;
	}

	/**
	 * Create a paragraph element from a list of runs/hyperlink nodes.
	 *
	 * @param DOMDocument           $doc      Target DOMDocument.
	 * @param array<int,DOMElement> $runs     Runs to append.
	 * @param DOMElement|null       $base_rpr Base run properties reference.
	 * @return DOMElement
	 */
	private static function create_paragraph_from_runs( DOMDocument $doc, array $runs, $base_rpr ) {
		$paragraph = $doc->createElementNS( self::WORD_NAMESPACE, 'w:p' );

		if ( empty( $runs ) ) {
			$paragraph->appendChild( self::create_blank_run( $doc, $base_rpr ) );
			return $paragraph;
		}

		foreach ( $runs as $run ) {
			if ( $run instanceof DOMElement ) {
				$paragraph->appendChild( $run );
			}
		}

		if ( 0 === $paragraph->childNodes->length ) {
			$paragraph->appendChild( self::create_blank_run( $doc, $base_rpr ) );
		}

		return $paragraph;
	}

	/**
	 * Create a blank Word run preserving whitespace.
	 *
	 * @param DOMDocument     $doc      Target DOMDocument.
	 * @param DOMElement|null $base_rpr Base run properties.
	 * @return DOMElement
	 */
	private static function create_blank_run( DOMDocument $doc, $base_rpr ) {
		$run = $doc->createElementNS( self::WORD_NAMESPACE, 'w:r' );
		if ( $base_rpr instanceof DOMElement ) {
			$run->appendChild( $base_rpr->cloneNode( true ) );
		}

		$text = $doc->createElementNS( self::WORD_NAMESPACE, 'w:t' );
		$text->setAttributeNS( 'http://www.w3.org/XML/1998/namespace', 'xml:space', 'preserve' );
		$text->appendChild( $doc->createTextNode( '' ) );
		$run->appendChild( $text );

		return $run;
	}

	/**
	 * Create a blank paragraph.
	 *
	 * @param DOMDocument     $doc      Target DOMDocument.
	 * @param DOMElement|null $base_rpr Base run properties reference.
	 * @return DOMElement
	 */
	private static function create_blank_paragraph( DOMDocument $doc, $base_rpr ) {
		$paragraph = $doc->createElementNS( self::WORD_NAMESPACE, 'w:p' );
		$paragraph->appendChild( self::create_blank_run( $doc, $base_rpr ) );
		return $paragraph;
	}

	/**
	 * Check whether a paragraph contains meaningful content other than a specific run.
	 *
	 * @param DOMElement $paragraph Paragraph element to inspect.
	 * @param DOMElement $target_run Run node slated for replacement.
	 * @return bool
	 */
	private static function paragraph_contains_other_content( DOMElement $paragraph, DOMElement $target_run ) {
		foreach ( $paragraph->childNodes as $child ) {
			if ( $child === $target_run ) {
				continue;
			}

			if ( $child instanceof DOMElement ) {
				if ( self::WORD_NAMESPACE === $child->namespaceURI && ( 'r' === $child->localName || 'hyperlink' === $child->localName ) ) {
					return true;
				}

				if ( self::WORD_NAMESPACE === $child->namespaceURI && 'pPr' === $child->localName ) {
					continue;
				}

				return true;
			}
		}

		return false;
	}

	/**
	 * Merge formatting flags with additional span-based styles.
	 *
	 * @param array<string,bool> $formatting Current formatting flags.
	 * @param DOMElement         $node       Current span element.
	 * @return array<string,bool>
	 */
	private static function extract_span_formatting( array $formatting, DOMElement $node ) {
		$style = $node->getAttribute( 'style' );
		if ( $style ) {
			$styles = array_map( 'trim', explode( ';', strtolower( $style ) ) );
			foreach ( $styles as $rule ) {
				if ( '' === $rule ) {
					continue;
				}
				list( $prop, $val ) = array_map( 'trim', explode( ':', $rule ) + array( '', '' ) );
				switch ( $prop ) {
					case 'font-weight':
						if ( 'bold' === $val || '700' === $val ) {
									$formatting['bold'] = true;
						}
						break;
					case 'font-style':
						if ( 'italic' === $val ) {
							$formatting['italic'] = true;
						}
						break;
					case 'text-decoration':
						if ( false !== strpos( $val, 'underline' ) ) {
							$formatting['underline'] = true;
						}
						break;
				}
			}
		}
		return $formatting;
	}

	/**
	 * Toggle a formatting flag in a new formatting array.
	 *
	 * @param array<string,bool> $formatting Current formatting.
	 * @param string             $flag       Flag name.
	 * @param bool               $value      Flag value.
	 * @return array<string,bool>
	 */
	private static function with_format_flag( array $formatting, $flag, $value ) {
		$formatting[ $flag ] = $value;
		return $formatting;
	}

	/**
	 * Create a run containing text with the given formatting.
	 *
	 * @param DOMDocument        $doc        Target document.
	 * @param string             $text       Text content.
	 * @param DOMElement|null    $base_rpr   Base run properties to clone.
	 * @param array<string,bool> $formatting Formatting flags.
	 * @return DOMElement|null
	 */
	private static function create_text_run( DOMDocument $doc, $text, $base_rpr, array $formatting ) {
		if ( '' === $text ) {
				return null;
		}
			$run = $doc->createElementNS( self::WORD_NAMESPACE, 'w:r' );
		if ( $base_rpr instanceof DOMElement ) {
				$run->appendChild( $base_rpr->cloneNode( true ) );
		}
			$rpr = self::get_or_create_run_properties( $doc, $run );
		if ( ! empty( $formatting['bold'] ) ) {
				$rpr->appendChild( $doc->createElementNS( self::WORD_NAMESPACE, 'w:b' ) );
		}
		if ( ! empty( $formatting['italic'] ) ) {
				$rpr->appendChild( $doc->createElementNS( self::WORD_NAMESPACE, 'w:i' ) );
		}
		if ( ! empty( $formatting['underline'] ) ) {
				$u = $doc->createElementNS( self::WORD_NAMESPACE, 'w:u' );
				$u->setAttribute( 'w:val', 'single' );
				$rpr->appendChild( $u );
		}
		if ( ! empty( $formatting['hyperlink'] ) ) {
				$style = $doc->createElementNS( self::WORD_NAMESPACE, 'w:rStyle' );
				$style->setAttribute( 'w:val', 'Hyperlink' );
				$rpr->appendChild( $style );
				$color = $doc->createElementNS( self::WORD_NAMESPACE, 'w:color' );
				$color->setAttribute( 'w:val', '0000FF' );
				$rpr->appendChild( $color );
		}
		if ( 0 === $rpr->childNodes->length ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
				$run->removeChild( $rpr );
		}
			$text_node = $doc->createElementNS( self::WORD_NAMESPACE, 'w:t' );
		if ( preg_match( '/^\s|\s$/u', $text ) ) {
			$text_node->setAttributeNS( 'http://www.w3.org/XML/1998/namespace', 'xml:space', 'preserve' );
		}
			$text_node->appendChild( $doc->createTextNode( $text ) );
			$run->appendChild( $text_node );
			return $run;
	}

	/**
	 * Create a run representing a line break.
	 *
	 * @param DOMDocument     $doc      Target document.
	 * @param DOMElement|null $base_rpr Base run properties to clone.
	 * @param int             $count    Number of line breaks to append.
	 * @return DOMElement
	 */
	private static function create_break_run( DOMDocument $doc, $base_rpr, $count = 1 ) {
		$run = $doc->createElementNS( self::WORD_NAMESPACE, 'w:r' );
		if ( $base_rpr instanceof DOMElement ) {
			$run->appendChild( $base_rpr->cloneNode( true ) );
		}
		$count = max( 1, (int) $count );
		for ( $i = 0; $i < $count; $i++ ) {
			$run->appendChild( $doc->createElementNS( self::WORD_NAMESPACE, 'w:br' ) );
		}
		return $run;
	}

	/**
	 * Ensure a run has a run properties node to append formatting.
	 *
	 * @param DOMDocument $doc Document reference.
	 * @param DOMElement  $run Run element.
	 * @return DOMElement
	 */
	private static function get_or_create_run_properties( DOMDocument $doc, DOMElement $run ) {
		foreach ( $run->childNodes as $child ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
			if ( $child instanceof DOMElement && self::WORD_NAMESPACE === $child->namespaceURI && 'rPr' === $child->localName ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
				return $child;
			}
		}
		$rpr = $doc->createElementNS( self::WORD_NAMESPACE, 'w:rPr' );
		$run->insertBefore( $rpr, $run->firstChild ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
		return $rpr;
	}

	/**
	 * Clone run properties from an existing run if available.
	 *
	 * @param DOMElement $run Run element to inspect.
	 * @return DOMElement|null
	 */
	private static function clone_run_properties( DOMElement $run ) {
		foreach ( $run->childNodes as $child ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
			if ( $child instanceof DOMElement && self::WORD_NAMESPACE === $child->namespaceURI && 'rPr' === $child->localName ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
				return $child->cloneNode( true );
			}
		}
		return null;
	}

	/**
	 * Remove trailing break runs from the generated run list.
	 *
	 * @param array<int, DOMElement> $runs Run collection.
	 */
	private static function trim_trailing_break_runs( array &$runs ) {
		while ( ! empty( $runs ) ) {
			$last = end( $runs );
			if ( self::run_is_break( $last ) ) {
				array_pop( $runs );
				continue;
			}
			break;
		}
	}

	/**
	 * Determine whether a run is a break run.
	 *
	 * @param DOMElement|null $run Run element.
	 * @return bool
	 */
	private static function run_is_break( $run ) {
		if ( ! $run instanceof DOMElement ) {
				return false;
		}
		foreach ( $run->childNodes as $child ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
			if ( $child instanceof DOMElement && self::WORD_NAMESPACE === $child->namespaceURI && 'br' === $child->localName ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
				return true;
			}
		}
			return false;
	}

		/**
		 * Load the relationships XML for a given WordprocessingML part.
		 *
		 * @param ZipArchive $zip    Open archive instance.
		 * @param string     $target Target XML part path.
		 * @return array<string,mixed>|null
		 */
	private static function load_relationships_for_part( ZipArchive $zip, $target ) {
			$rel_path = self::get_relationship_part_path( $target );
		if ( '' === $rel_path ) {
				return null;
		}
			$rels_xml = $zip->getFromName( $rel_path );
			$doc      = new DOMDocument();
			$doc->preserveWhiteSpace = false; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
			$doc->formatOutput       = false; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
			$map      = array();
			$next_id  = 0;
			libxml_use_internal_errors( true );
		if ( false === $rels_xml || '' === trim( (string) $rels_xml ) || ! $doc->loadXML( $rels_xml ) ) {
				$doc->loadXML( '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships" />' );
		}
			libxml_clear_errors();
			$root = $doc->documentElement;
		if ( $root instanceof DOMElement ) {
				$relationships = $root->getElementsByTagNameNS( $root->namespaceURI, 'Relationship' );
			foreach ( $relationships as $relationship ) {
				if ( ! $relationship instanceof DOMElement ) {
					continue;
				}
					$id = $relationship->getAttribute( 'Id' );
				if ( preg_match( '/^rId(\d+)$/', $id, $matches ) ) {
						$next_id = max( $next_id, (int) $matches[1] );
				}
				if ( 'http://schemas.openxmlformats.org/officeDocument/2006/relationships/hyperlink' === $relationship->getAttribute( 'Type' ) ) {
						$target = $relationship->getAttribute( 'Target' );
					if ( '' !== $target ) {
							$map[ $target ] = $id;
					}
				}
			}
		}
			return array(
				'path'       => $rel_path,
				'doc'        => $doc,
				'next_index' => (int) $next_id,
				'map'        => $map,
				'modified'   => false,
			);
	}

		/**
		 * Determine the relationships part path for a given XML part.
		 *
		 * @param string $target Target XML part path.
		 * @return string
		 */
	private static function get_relationship_part_path( $target ) {
		if ( '' === $target ) {
				return '';
		}
			$dir  = dirname( $target );
			$file = basename( $target );
		if ( '.' === $dir ) {
				$dir = '';
		}
			$rel_dir = '' !== $dir ? $dir . '/_rels' : '_rels';
			return $rel_dir . '/' . $file . '.rels';
	}

		/**
		 * Create or reuse a hyperlink relationship and return its r:id value.
		 *
		 * @param array<string,mixed>|null $relationships Relationship context.
		 * @param string                   $target        Hyperlink URL.
		 * @return string
		 */
	private static function register_hyperlink_relationship( &$relationships, $target ) {
		if ( empty( $target ) || ! is_array( $relationships ) ) {
				return '';
		}
		if ( isset( $relationships['map'][ $target ] ) ) {
				return $relationships['map'][ $target ];
		}
		if ( empty( $relationships['doc'] ) || ! $relationships['doc'] instanceof DOMDocument ) {
				return '';
		}
			$doc  = $relationships['doc'];
			$root = $doc->documentElement;
		if ( ! $root instanceof DOMElement ) {
				return '';
		}
			$relationships['next_index'] = (int) $relationships['next_index'] + 1;
			$r_id                        = 'rId' . $relationships['next_index'];
			$relationship                = $doc->createElementNS( $root->namespaceURI, 'Relationship' );
			$relationship->setAttribute( 'Id', $r_id );
			$relationship->setAttribute( 'Type', 'http://schemas.openxmlformats.org/officeDocument/2006/relationships/hyperlink' );
			$relationship->setAttribute( 'Target', $target );
			$relationship->setAttribute( 'TargetMode', 'External' );
			$root->appendChild( $relationship );
			$relationships['map'][ $target ] = $r_id;
			$relationships['modified']       = true;
			return $r_id;
	}

		/**
		 * Wrap runs inside a Word hyperlink container when possible.
		 *
		 * @param DOMDocument              $doc           Target DOM document.
		 * @param array<int, DOMElement>   $link_runs     Runs representing the link text.
		 * @param array<string,mixed>|null $relationships Relationship context.
		 * @param string                   $href          Hyperlink URL.
		 * @return DOMElement|null
		 */
	private static function create_hyperlink_container( DOMDocument $doc, array $link_runs, &$relationships, $href ) {
		if ( empty( $link_runs ) ) {
				return null;
		}
			$relationship_id = self::register_hyperlink_relationship( $relationships, $href );
		if ( '' === $relationship_id ) {
				return null;
		}
			$hyperlink = $doc->createElementNS( self::WORD_NAMESPACE, 'w:hyperlink' );
			$hyperlink->setAttributeNS( 'http://schemas.openxmlformats.org/officeDocument/2006/relationships', 'r:id', $relationship_id );
			$hyperlink->setAttribute( 'w:history', '1' );
		foreach ( $link_runs as $run ) {
			if ( $run instanceof DOMElement ) {
					$hyperlink->appendChild( $run );
			}
		}
			return $hyperlink;
	}
}
// phpcs:enable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
