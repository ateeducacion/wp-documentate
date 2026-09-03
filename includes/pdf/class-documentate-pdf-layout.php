<?php
/**
 * A PDF layout: the HTML file a document type is drawn from.
 *
 * A layout is an ordinary HTML file under `templates/pdf/`. Its `<head>` says
 * how the page furniture is drawn — letterhead, addresses, folio, crest,
 * margins, font — and its `<body>` carries the TinyButStrong tags the merger
 * fills in. This class reads the head; the writer draws the body.
 *
 * Only the file reader lives here for now. Resolving the layout of a document
 * type from its term meta, and listing the shipped layouts, is Task 7.
 *
 * @package Documentate
 */

defined( 'ABSPATH' ) || exit();

/**
 * Reads a layout file and hands over its title and validated options.
 */
class Documentate_Pdf_Layout {

	/**
	 * Term meta of `documentate_doc_type` that names the layout of a type.
	 */
	const META_KEY = 'documentate_type_pdf_layout';

	/**
	 * Layout used by a document type that names none.
	 */
	const DEFAULT_SLUG = 'generic';

	/**
	 * Prefix every option-carrying `<meta name>` starts with.
	 */
	const META_PREFIX = 'documentate-';

	/**
	 * Document options a layout that sets nothing ends up with.
	 *
	 * The list is the one `Documentate_Pdf_Document` accepts, so `options()`
	 * can be handed to its constructor as it stands.
	 */
	const DEFAULTS = array(
		'letterhead'         => 'none',
		'addresses'          => 'none',
		'folio'              => 'none',
		'crest'              => false,
		'margins'            => array( 20.0, 20.0, 20.0, 20.0 ),
		'first_page_margins' => null,
		'font'               => 'times',
		'font_size'          => 11.0,
	);

	/**
	 * Letterheads the document knows how to draw.
	 */
	const LETTERHEADS = array( 'none', 'standard', 'large' );

	/**
	 * Places the document knows how to print the addresses in.
	 */
	const ADDRESSES = array( 'none', 'band', 'header', 'footer' );

	/**
	 * Places the document knows how to print the folio in.
	 */
	const FOLIOS = array( 'none', 'header', 'footer' );

	/**
	 * Core fonts the document can be typeset in.
	 */
	const FONTS = array( 'times', 'helvetica', 'courier' );

	/**
	 * Values a boolean meta is switched on by.
	 */
	const TRUTHY = array( '1', 'true', 'yes', 'on' );

	/**
	 * Absolute path of the layout file.
	 *
	 * @var string
	 */
	private $path;

	/**
	 * File name without the `.html` extension.
	 *
	 * @var string
	 */
	private $slug;

	/**
	 * Contents of the `<title>` element, empty when there is none.
	 *
	 * @var string
	 */
	private $title = '';

	/**
	 * Raw `documentate-*` meta values, keyed without the prefix.
	 *
	 * @var array<string,string>
	 */
	private $meta = array();

	/**
	 * Read a layout from a file.
	 *
	 * A file that does not exist yields a layout on the default options rather
	 * than an error, so a document type pointing at a deleted layout still
	 * renders.
	 *
	 * @param string $path Absolute path of the layout file.
	 * @return self
	 */
	public static function for_file( $path ) {
		return new self( (string) $path );
	}

	/**
	 * Absolute path of the layout file.
	 *
	 * @return string
	 */
	public function path() {
		return $this->path;
	}

	/**
	 * File name without the `.html` extension.
	 *
	 * @return string
	 */
	public function slug() {
		return $this->slug;
	}

	/**
	 * Name the layout gives itself, for the document type select.
	 *
	 * @return string
	 */
	public function title() {
		return $this->title;
	}

	/**
	 * Document options the layout asks for, validated and completed.
	 *
	 * Every value is checked against the closed list the document accepts, and
	 * anything else falls back to the default, so a mistyped layout renders on
	 * plain A4 instead of failing.
	 *
	 * @return array<string,mixed>
	 */
	public function options() {
		$options = self::DEFAULTS;

		$options['letterhead']         = $this->enum( 'letterhead', self::LETTERHEADS, $options['letterhead'] );
		$options['addresses']          = $this->enum( 'addresses', self::ADDRESSES, $options['addresses'] );
		$options['folio']              = $this->enum( 'folio', self::FOLIOS, $options['folio'] );
		$options['font']               = $this->enum( 'font', self::FONTS, $options['font'] );
		$options['crest']              = $this->flag( 'crest' );
		$options['margins']            = $this->margins( $options['margins'] );
		$options['font_size']          = $this->number( 'font-size', $options['font_size'] );
		$options['first_page_margins'] = $this->first_page_margins( $options['margins'] );

		return $options;
	}

	/**
	 * Absolute path of an image the layout is allowed to embed.
	 *
	 * Only a bare file name that exists under `templates/pdf/img` resolves:
	 * a layout must not be able to reach an arbitrary file on the server, so
	 * anything carrying a directory separator, and anything that resolves
	 * outside the image directory, is refused.
	 *
	 * @param string $src `src` attribute of an `<img>` in the layout.
	 * @return string Absolute path, or an empty string when the image is refused.
	 */
	public function image_path( $src ) {
		$src = (string) $src;
		if ( '' === $src || basename( $src ) !== $src ) {
			return '';
		}

		$directory = realpath( self::image_dir() );
		$file      = realpath( self::image_dir() . $src );

		if ( false === $directory || false === $file || 0 !== strpos( $file, $directory . DIRECTORY_SEPARATOR ) ) {
			return '';
		}

		return $file;
	}

	/**
	 * Keep the path and read the head of the file.
	 *
	 * @param string $path Absolute path of the layout file.
	 */
	private function __construct( $path ) {
		$this->path = $path;
		$this->slug = basename( $path, '.html' );

		$this->read_head();
	}

	/**
	 * Directory the layout images live in.
	 *
	 * @return string
	 */
	private static function image_dir() {
		return DOCUMENTATE_PLUGIN_DIR . 'templates/pdf/img/';
	}

	/**
	 * Read the title and the `documentate-*` metas out of the file.
	 */
	private function read_head() {
		$html = is_readable( $this->path ) ? (string) file_get_contents( $this->path ) : '';
		if ( '' === $html ) {
			return;
		}

		$dom      = new DOMDocument();
		$reported = libxml_use_internal_errors( true );
		$dom->loadHTML( $html, LIBXML_HTML_NODEFDTD );
		libxml_clear_errors();
		libxml_use_internal_errors( $reported );

		$titles = $dom->getElementsByTagName( 'title' );
		if ( $titles->length > 0 ) {
			$this->title = trim( $titles->item( 0 )->textContent ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- DOM property.
		}

		foreach ( $dom->getElementsByTagName( 'meta' ) as $meta ) {
			$name = strtolower( trim( $meta->getAttribute( 'name' ) ) );
			if ( 0 === strpos( $name, self::META_PREFIX ) ) {
				$this->meta[ substr( $name, strlen( self::META_PREFIX ) ) ] = trim( $meta->getAttribute( 'content' ) );
			}
		}
	}

	/**
	 * Value of a meta, or an empty string when the layout does not set it.
	 *
	 * @param string $name Meta name without the `documentate-` prefix.
	 * @return string
	 */
	private function raw( $name ) {
		return isset( $this->meta[ $name ] ) ? $this->meta[ $name ] : '';
	}

	/**
	 * A meta whose value has to belong to a closed list.
	 *
	 * @param string   $name    Meta name without the prefix.
	 * @param string[] $allowed Accepted values.
	 * @param string   $default Value used when the meta is missing or unknown.
	 * @return string
	 */
	private function enum( $name, array $allowed, $default ) {
		$value = strtolower( $this->raw( $name ) );

		return in_array( $value, $allowed, true ) ? $value : $default;
	}

	/**
	 * A meta that switches something on.
	 *
	 * @param string $name Meta name without the prefix.
	 * @return bool
	 */
	private function flag( $name ) {
		return in_array( strtolower( $this->raw( $name ) ), self::TRUTHY, true );
	}

	/**
	 * A meta holding one positive number.
	 *
	 * @param string $name    Meta name without the prefix.
	 * @param float  $default Value used when the meta is missing or not a positive number.
	 * @return float
	 */
	private function number( $name, $default ) {
		$value = $this->raw( $name );

		return ( is_numeric( $value ) && (float) $value > 0 ) ? (float) $value : (float) $default;
	}

	/**
	 * The `margins` meta as (top, right, bottom, left) in millimetres.
	 *
	 * @param array<int,float> $default Value used when the meta is missing or malformed.
	 * @return array<int,float>
	 */
	private function margins( array $default ) {
		$parts = preg_split( '/\s+/', $this->raw( 'margins' ), -1, PREG_SPLIT_NO_EMPTY );

		if ( ! is_array( $parts ) || 4 !== count( $parts ) || 4 !== count( array_filter( $parts, 'is_numeric' ) ) ) {
			return $default;
		}

		return array_map( 'floatval', $parts );
	}

	/**
	 * The page margins of the first page, when the layout gives it a deeper
	 * foot to leave room for a signature block or a footer letterhead.
	 *
	 * @param array<int,float> $margins Page margins the layout resolved to.
	 * @return array<int,float>|null Null when the layout keeps the same margins throughout.
	 */
	private function first_page_margins( array $margins ) {
		$bottom = $this->number( 'first-page-bottom', 0.0 );
		if ( $bottom <= 0 ) {
			return null;
		}

		$margins[2] = $bottom;

		return $margins;
	}
}
