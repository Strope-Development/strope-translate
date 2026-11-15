<?php
/**
 * @package Strope-Translate
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Don't access directly.
}

if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * List table of the strings discovered in the active themes and plugins.
 *
 * @since 4.1.0
 */
class Strope_Table_Discovered_Strings extends WP_List_Table {
	/**
	 * Languages in which the user can translate.
	 *
	 * @var Strope_Language[]
	 */
	protected $languages;

	/**
	 * Translations of the displayed strings, keyed by string id and language slug.
	 *
	 * @var array
	 */
	protected $translations = array();

	/**
	 * Number of plural forms per language slug.
	 *
	 * @var int[]
	 */
	protected $nplurals = array();

	/**
	 * Scanned components.
	 *
	 * @var array[]
	 */
	protected $components;

	/**
	 * Current filters.
	 *
	 * @var array
	 */
	protected $filters;

	/**
	 * Constructor.
	 *
	 * @since 4.1.0
	 *
	 * @param Strope_Language[] $languages Languages displayed.
	 * @param array             $filters   Current filters.
	 */
	public function __construct( array $languages, array $filters ) {
		parent::__construct(
			array(
				'singular' => 'discovered-string',
				'plural'   => 'discovered-strings',
				'ajax'     => false,
				'screen'   => 'languages_page_mlang_strings',
			)
		);

		$this->languages  = $languages;
		$this->filters    = $filters;
		$this->components = Strope_String_Scanner::get_components();

		foreach ( $languages as $language ) {
			$this->nplurals[ $language->slug ] = Strope_String_Discovery::get_nplurals( $language );
		}
	}

	/**
	 * Returns the list of columns.
	 *
	 * @since 4.1.0
	 *
	 * @return string[]
	 */
	public function get_columns() {
		return array(
			'string'       => esc_html__( 'String', 'strope-translate' ),
			'domain'       => esc_html__( 'Text domain', 'strope-translate' ),
			'translations' => esc_html__( 'Translations', 'strope-translate' ),
		);
	}

	/**
	 * Returns the sortable columns.
	 *
	 * @since 4.1.0
	 *
	 * @return array
	 */
	public function get_sortable_columns() {
		return array(
			'string' => array( 'string', false ),
			'domain' => array( 'domain', false ),
		);
	}

	/**
	 * Returns the name of the primary column.
	 *
	 * @since 4.1.0
	 *
	 * @return string
	 */
	protected function get_default_primary_column_name() {
		return 'string';
	}

	/**
	 * Message displayed when there are no strings.
	 *
	 * @since 4.1.0
	 *
	 * @return void
	 */
	public function no_items() {
		if ( empty( $this->components ) ) {
			esc_html_e( 'No strings found yet. Scan the active theme and plugins to discover their strings.', 'strope-translate' );
		} else {
			esc_html_e( 'No strings found.', 'strope-translate' );
		}
	}

	/**
	 * Returns the current page number.
	 *
	 * @since 4.1.0
	 *
	 * @return int
	 */
	public function get_pagenum() {
		$pagenum = Strope_String_Discovery_Admin::get_pagenum();

		if ( isset( $this->_pagination_args['total_pages'] ) && $pagenum > $this->_pagination_args['total_pages'] ) {
			$pagenum = max( 1, (int) $this->_pagination_args['total_pages'] );
		}

		return $pagenum;
	}

	/**
	 * Prepares the items.
	 *
	 * @since 4.1.0
	 *
	 * @return void
	 */
	public function prepare_items() {
		$per_page = $this->get_items_per_page( 'strope_strings_per_page', 20 );
		$orderby  = isset( $_GET['orderby'] ) ? sanitize_key( $_GET['orderby'] ) : 'string'; // phpcs:ignore WordPress.Security.NonceVerification
		$order    = isset( $_GET['order'] ) && 'desc' === $_GET['order'] ? 'desc' : 'asc'; // phpcs:ignore WordPress.Security.NonceVerification

		$result = Strope_String_Discovery_Store::query_strings(
			array_merge(
				$this->filters,
				array(
					'status_languages' => $this->get_status_languages(),
					'orderby'          => $orderby,
					'order'            => $order,
					'per_page'         => $per_page,
					'page'             => $this->get_pagenum(),
				)
			)
		);

		$this->items        = $result['items'];
		$this->translations = Strope_String_Discovery_Store::get_translations( wp_list_pluck( $this->items, 'id' ), wp_list_pluck( $this->languages, 'slug' ) );

		$this->_column_headers = array( $this->get_columns(), array(), $this->get_sortable_columns() );

		$this->set_pagination_args(
			array(
				'total_items' => $result['total'],
				'per_page'    => $per_page,
				'total_pages' => (int) ceil( $result['total'] / $per_page ),
			)
		);
	}

	/**
	 * Returns the languages used for the translated / untranslated status: the displayed languages
	 * except the default one (which doesn't need a translation), unless it's the only one displayed.
	 *
	 * @since 4.1.0
	 *
	 * @return string[]
	 */
	protected function get_status_languages(): array {
		$slugs   = array();
		$default = Strope()->options['default_lang'] ?? '';

		foreach ( $this->languages as $language ) {
			if ( $language->slug !== $default ) {
				$slugs[] = $language->slug;
			}
		}

		return empty( $slugs ) ? wp_list_pluck( $this->languages, 'slug' ) : $slugs;
	}

	/**
	 * Displays the string column.
	 *
	 * @since 4.1.0
	 *
	 * @param object $item String.
	 * @return string
	 */
	public function column_string( $item ) {
		$out = '<span class="strope-sd-singular">' . esc_html( $item->singular ) . '</span>';

		if ( '' !== $item->plural ) {
			$out .= '<br /><span class="strope-sd-plural"><span class="strope-sd-label">' . esc_html__( 'Plural:', 'strope-translate' ) . '</span> ' . esc_html( $item->plural ) . '</span>';
		}

		if ( '' !== $item->context ) {
			$out .= '<br /><span class="strope-sd-context"><span class="strope-sd-label">' . esc_html__( 'Context:', 'strope-translate' ) . '</span> ' . esc_html( $item->context ) . '</span>';
		}

		if ( '' !== $item->sources ) {
			$out .= sprintf(
				'<details class="strope-sd-sources"><summary>%s</summary><code>%s</code></details>',
				esc_html__( 'Sources', 'strope-translate' ),
				nl2br( esc_html( $item->sources ) )
			);
		}

		return $out;
	}

	/**
	 * Displays the text domain column.
	 *
	 * @since 4.1.0
	 *
	 * @param object $item String.
	 * @return string
	 */
	public function column_domain( $item ) {
		$out = '<code>' . esc_html( $item->domain ) . '</code>';

		if ( isset( $this->components[ $item->component ]['name'] ) ) {
			$out .= '<br /><span class="strope-sd-component">' . esc_html( $this->components[ $item->component ]['name'] ) . '</span>';
		} elseif ( '' === $item->component ) {
			$out .= '<br /><span class="strope-sd-component">' . esc_html__( 'Other', 'strope-translate' ) . '</span>';
		}

		return $out;
	}

	/**
	 * Displays the translations fields.
	 *
	 * @since 4.1.0
	 *
	 * @param object $item String.
	 * @return string
	 */
	public function column_translations( $item ) {
		$out     = '';
		$id      = (int) $item->id;
		$default = Strope()->options['default_lang'] ?? '';
		$long    = strlen( $item->singular ) > 80 || false !== strpos( $item->singular, "\n" );

		foreach ( $this->languages as $language ) {
			$forms    = isset( $this->translations[ $id ][ $language->slug ] ) ? $this->translations[ $id ][ $language->slug ] : array();
			$nplurals = '' === $item->plural ? 1 : $this->nplurals[ $language->slug ];
			$label    = $language->name;

			if ( $language->slug === $default ) {
				/* translators: %s is a language name */
				$label = sprintf( __( '%s (override)', 'strope-translate' ), $language->name );
			}

			$out .= sprintf(
				'<input type="hidden" name="strope_sd_orig[%1$d][%2$s]" value="%3$s" />',
				$id,
				esc_attr( $language->slug ),
				esc_attr( md5( (string) wp_json_encode( array_slice( $forms, 0, $nplurals ) ) ) )
			);

			for ( $i = 0; $i < $nplurals; $i++ ) {
				$field_id    = sprintf( 'strope-sd-%d-%s-%d', $id, $language->slug, $i );
				$value       = isset( $forms[ $i ] ) ? $forms[ $i ] : '';
				$placeholder = 0 === $i ? $item->singular : $item->plural;
				$form_label  = $label;

				if ( 2 === $nplurals ) {
					$form_label = 0 === $i ?
						/* translators: %s is a language name */
						sprintf( __( '%s, singular', 'strope-translate' ), $label ) :
						/* translators: %s is a language name */
						sprintf( __( '%s, plural', 'strope-translate' ), $label );
				} elseif ( $nplurals > 2 ) {
					/* translators: 1: language name, 2: plural form number */
					$form_label = sprintf( __( '%1$s, form %2$d', 'strope-translate' ), $label, $i + 1 );
				}

				/*
				 * The stored value is printed exactly, entities included (`esc_textarea()` encodes them again, contrary to `esc_attr()`),
				 * so that it is submitted unchanged: an encoded `&lt;script&gt;` must not come back as a real tag.
				 */
				$field = $long ?
					sprintf( '<textarea name="strope_sd[%1$d][%2$s][%3$d]" id="%4$s" placeholder="%5$s" rows="3">%6$s</textarea>', $id, esc_attr( $language->slug ), $i, esc_attr( $field_id ), esc_attr( $placeholder ), esc_textarea( $value ) ) :
					sprintf( '<input type="text" name="strope_sd[%1$d][%2$s][%3$d]" id="%4$s" placeholder="%5$s" value="%6$s" />', $id, esc_attr( $language->slug ), $i, esc_attr( $field_id ), esc_attr( $placeholder ), esc_textarea( $value ) );

				$out .= sprintf(
					'<div class="translation"><label for="%1$s">%2$s</label>%3$s</div>' . "\n",
					esc_attr( $field_id ),
					esc_html( $form_label ),
					$field
				);
			}
		}

		return $out;
	}

	/**
	 * Default column.
	 *
	 * @since 4.1.0
	 *
	 * @param object $item        String.
	 * @param string $column_name Column name.
	 * @return string
	 */
	public function column_default( $item, $column_name ) {
		return isset( $item->$column_name ) ? esc_html( (string) $item->$column_name ) : '';
	}

	/**
	 * Displays the filters, attached to the filters form through the `form` attribute.
	 *
	 * @since 4.1.0
	 *
	 * @param string $which 'top' or 'bottom'.
	 * @return void
	 */
	protected function extra_tablenav( $which ) {
		if ( 'top' !== $which ) {
			return;
		}

		echo '<div class="alignleft actions">';

		// Components.
		printf( '<label class="screen-reader-text" for="strope-sd-component">%s</label>', esc_html__( 'Filter by theme or plugin', 'strope-translate' ) );
		echo '<select id="strope-sd-component" name="component" form="strope-sd-filters">';
		printf( '<option value="">%s</option>', esc_html__( 'All themes and plugins', 'strope-translate' ) );
		foreach ( $this->components as $id => $component ) {
			printf( '<option value="%s"%s>%s</option>', esc_attr( $id ), selected( $this->filters['component'], $id, false ), esc_html( $component['name'] ) );
		}
		printf( '<option value="-"%s>%s</option>', selected( $this->filters['component'], '-', false ), esc_html__( 'Other', 'strope-translate' ) );
		echo '</select>';

		// Text domains.
		printf( '<label class="screen-reader-text" for="strope-sd-domain">%s</label>', esc_html__( 'Filter by text domain', 'strope-translate' ) );
		echo '<select id="strope-sd-domain" name="domain" form="strope-sd-filters">';
		printf( '<option value="">%s</option>', esc_html__( 'All text domains', 'strope-translate' ) );
		foreach ( Strope_String_Discovery_Store::get_domains() as $domain ) {
			printf( '<option value="%s"%s>%s</option>', esc_attr( $domain ), selected( $this->filters['domain'], $domain, false ), esc_html( $domain ) );
		}
		echo '</select>';

		// Status.
		printf( '<label class="screen-reader-text" for="strope-sd-status">%s</label>', esc_html__( 'Filter by status', 'strope-translate' ) );
		echo '<select id="strope-sd-status" name="status" form="strope-sd-filters">';
		foreach (
			array(
				''             => __( 'All strings', 'strope-translate' ),
				'untranslated' => __( 'Missing translations', 'strope-translate' ),
				'translated'   => __( 'Translated in all languages', 'strope-translate' ),
			) as $value => $text
		) {
			printf( '<option value="%s"%s>%s</option>', esc_attr( $value ), selected( $this->filters['status'], $value, false ), esc_html( $text ) );
		}
		echo '</select>';

		printf( '<button type="submit" class="button" form="strope-sd-filters">%s</button>', esc_html__( 'Filter', 'strope-translate' ) );
		echo '</div>';
	}
}
