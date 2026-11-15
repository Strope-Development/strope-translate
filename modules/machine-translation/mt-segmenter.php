<?php
/**
 * @package Strope-Translate
 */

defined( 'ABSPATH' ) || exit;

/**
 * Splits the text of a translatable field into segments for the translation engine, protects what must
 * not be translated, then restores and checks the engine output.
 *
 * The engine (Bergamot) has two modes:
 * - HTML: keeps the markup, but aborts on malformed HTML (unclosed or stray tags, raw `<`), so only
 *   well-formed segments are sent in this mode, with `&`, `<` and `>` of the text escaped.
 * - Text: keeps tokens such as `⟦1⟧` unchanged. Used for plain fields and as a fallback for malformed HTML,
 *   where every tag becomes a token.
 *
 * Protected parts (comments, scripts, code blocks, shortcodes, printf placeholders) are replaced by empty
 * `<span data-st="N"></span>` elements in HTML mode, and by `⟦N⟧` in text mode. Words that must never be
 * translated are wrapped in `<span data-st="N">…</span>` so that the engine still sees them, and their
 * original text is put back afterwards. Glossary terms are wrapped the same way, and replaced by their
 * translation from the glossary afterwards.
 *
 * @since 4.2.0
 */
class Strope_MT_Segmenter {
	/**
	 * Segment modes.
	 */
	const MODE_TEXT = 0;
	const MODE_HTML = 1;

	/**
	 * Elements whose content is never translated, protected as a whole.
	 *
	 * @var string[]
	 */
	const RAW_ELEMENTS = array( 'script', 'style', 'pre', 'textarea', 'svg', 'math', 'noscript', 'iframe', 'object', 'template', 'video', 'audio', 'canvas' );

	/**
	 * Elements without closing tag.
	 *
	 * @var string[]
	 */
	const VOID_ELEMENTS = array( 'area', 'base', 'br', 'col', 'embed', 'hr', 'img', 'input', 'link', 'meta', 'param', 'source', 'track', 'wbr' );

	/**
	 * Block elements: segments are split around them at the top level.
	 *
	 * @var string[]
	 */
	const BLOCK_ELEMENTS = array( 'address', 'article', 'aside', 'blockquote', 'caption', 'dd', 'details', 'dialog', 'div', 'dl', 'dt', 'fieldset', 'figcaption', 'figure', 'footer', 'form', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'header', 'hgroup', 'hr', 'li', 'main', 'nav', 'ol', 'p', 'section', 'summary', 'table', 'tbody', 'td', 'tfoot', 'th', 'thead', 'tr', 'ul' );

	/**
	 * Target languages written without spaces between words.
	 *
	 * @var string[]
	 */
	const UNSPACED_LANGUAGES = array( 'ja', 'zh-Hans', 'zh-Hant', 'th', 'lo', 'km', 'my' );

	/**
	 * Pattern of the markup items, without delimiters: comments, raw elements (with their content), tags.
	 *
	 * @var string
	 */
	const MARKUP_BODY = '<!--.*?-->|<(script|style|pre|textarea|svg|math|noscript|iframe|object|template|video|audio|canvas)\b(?:[^>"\']|"[^"]*"|\'[^\']*\')*>.*?</\1\s*>|<!\[CDATA\[.*?\]\]>|<![A-Za-z][^>]*>|</?[A-Za-z][A-Za-z0-9:-]*(?:\s(?:[^>"\']|"[^"]*"|\'[^\']*\')*)?/?>';

	/**
	 * Pattern of the markup items.
	 *
	 * @var string
	 */
	const MARKUP_REGEX = '~' . self::MARKUP_BODY . '~si';

	/**
	 * Pattern of the HTML entities.
	 *
	 * @var string
	 */
	const ENTITY_BODY = '&(?:[a-zA-Z][a-zA-Z0-9]{1,31}|#\d{1,7}|#x[0-9a-fA-F]{1,6});';

	/**
	 * Pattern of the `%%name%%` variables (Yoast SEO, Rank Math...), protected as a whole.
	 *
	 * @var string
	 */
	const VARIABLE_REGEX = '/%%[A-Za-z][A-Za-z0-9_]*%%/';

	/**
	 * Pattern of the printf conversions.
	 *
	 * @var string
	 */
	const PRINTF_REGEX = '/%(?:\d+\$)?[-+0#\']*\d*(?:\.\d+)?[bcdeEfFgGosuxX]|%%/';

	/**
	 * Splits a field value into parts.
	 *
	 * @since 4.2.0
	 *
	 * @param string $source Field value.
	 * @param array  $args   {
	 *     @type string[] $keep     Words or phrases never translated.
	 *     @type string[] $glossary Translations of terms, keyed by the term in the source language (matched
	 *                              ignoring case). A term in both lists is translated with the glossary.
	 * }
	 * @return array List of parts: strings (kept as they are, with the glossary terms already translated) and
	 *               segment arrays with keys `mode`, `text` and `meta`.
	 */
	public static function split( string $source, array $args = array() ): array {
		$terms = self::get_terms( (array) ( $args['keep'] ?? array() ), (array) ( $args['glossary'] ?? array() ) );

		if ( ! preg_match( '/<[A-Za-z!\/]/', $source ) ) {
			return self::split_text( $source, $terms );
		}

		$items = self::tokenize( $source );

		if ( ! self::is_balanced( $items ) ) {
			return self::split_text( $source, $terms, true );
		}

		return self::split_html( $items, $terms );
	}

	/**
	 * Builds the list of the protected terms, longest first: kept words (matched exactly) and glossary terms
	 * (matched ignoring case).
	 *
	 * @since 4.3.0
	 *
	 * @param string[] $keep     Words never translated.
	 * @param string[] $glossary Translations keyed by source term.
	 * @return array[] Terms with keys `word` and `to` (the translation, null for a kept word).
	 */
	protected static function get_terms( array $keep, array $glossary ): array {
		$terms = array();

		foreach ( $keep as $word ) {
			$word = trim( (string) $word );
			if ( '' !== $word ) {
				$terms[ $word ] = array(
					'word' => $word,
					'to'   => null,
				);
			}
		}

		// Glossary terms replace the kept words and the other glossary terms with the same letters, whatever their case.
		$glossed = array();
		foreach ( $glossary as $word => $to ) {
			$word = trim( (string) $word );
			$to   = trim( (string) $to );
			if ( '' !== $word && '' !== $to ) {
				$glossed[ mb_strtolower( $word ) ] = array(
					'word' => $word,
					'to'   => $to,
				);
			}
		}

		foreach ( array_keys( $terms ) as $word ) {
			if ( isset( $glossed[ mb_strtolower( (string) $word ) ] ) ) {
				unset( $terms[ $word ] );
			}
		}

		$terms = array_merge( array_values( $terms ), array_values( $glossed ) );

		usort(
			$terms,
			function ( $a, $b ) {
				return strlen( $b['word'] ) - strlen( $a['word'] );
			}
		);

		return $terms;
	}

	/**
	 * Splits a markup string into items.
	 *
	 * @since 4.2.0
	 *
	 * @param string $source Markup.
	 * @return array[] Items with keys `type` (`text`, `tag`, `raw`), `str`, and for tags `name`, `closing`, `void`.
	 */
	protected static function tokenize( string $source ): array {
		$items = array();

		foreach ( self::split_by( self::MARKUP_REGEX, $source ) as list( $is_markup, $part ) ) {
			if ( ! $is_markup ) {
				$items[] = array( 'type' => 'text', 'str' => $part );
				continue;
			}

			// Comments, CDATA, doctype, and raw elements with their content and closing tag.
			if ( 0 === strpos( $part, '<!' ) || ( self::is_block_raw( $part ) && preg_match( '~</[A-Za-z]+\s*>$~', $part ) ) ) {
				$items[] = array( 'type' => 'raw', 'str' => $part );
				continue;
			}

			preg_match( '~^<(/?)([A-Za-z][A-Za-z0-9:-]*)~', $part, $m );
			$name    = strtolower( $m[2] );
			$items[] = array(
				'type'    => 'tag',
				'str'     => $part,
				'name'    => $name,
				'closing' => '/' === $m[1],
				'void'    => in_array( $name, self::VOID_ELEMENTS, true ) || '/>' === substr( $part, -2 ),
			);
		}

		return $items;
	}

	/**
	 * Splits a string by the matches of a pattern.
	 *
	 * @since 4.2.0
	 *
	 * @param string $pattern Pattern.
	 * @param string $text    Text.
	 * @return array[] List of `array( bool $is_match, string $str )`, without empty strings.
	 */
	protected static function split_by( string $pattern, string $text ): array {
		if ( '' === $text ) {
			return array();
		}

		if ( ! preg_match_all( $pattern, $text, $matches, PREG_OFFSET_CAPTURE ) || empty( $matches[0] ) ) {
			return array( array( false, $text ) );
		}

		$out = array();
		$pos = 0;

		foreach ( $matches[0] as list( $str, $offset ) ) {
			if ( '' === $str ) {
				continue;
			}
			if ( $offset > $pos ) {
				$out[] = array( false, substr( $text, $pos, $offset - $pos ) );
			}
			$out[] = array( true, $str );
			$pos   = $offset + strlen( $str );
		}

		if ( $pos < strlen( $text ) ) {
			$out[] = array( false, substr( $text, $pos ) );
		}

		return $out;
	}

	/**
	 * Tells whether the tags are balanced (the engine aborts otherwise).
	 *
	 * @since 4.2.0
	 *
	 * @param array[] $items Items.
	 * @return bool
	 */
	protected static function is_balanced( array $items ): bool {
		$stack = array();

		foreach ( $items as $item ) {
			if ( 'tag' !== $item['type'] ) {
				if ( 'text' === $item['type'] && preg_match( '/<[A-Za-z!\/]/', $item['str'] ) ) {
					return false; // Something looking like markup that could not be parsed.
				}
				continue;
			}

			if ( $item['closing'] ) {
				if ( array_pop( $stack ) !== $item['name'] ) {
					return false;
				}
			} elseif ( ! $item['void'] ) {
				$stack[] = $item['name'];
			}
		}

		return empty( $stack );
	}

	/**
	 * Splits well-formed markup into segments at the top-level block boundaries and line breaks.
	 *
	 * @since 4.2.0
	 *
	 * @param array[]  $items Items.
	 * @param array[]  $terms Protected terms, see `self::get_terms()`.
	 * @return array Parts.
	 */
	protected static function split_html( array $items, array $terms ): array {
		$parts  = array();
		$buffer = array();
		$depth  = 0;

		$flush = function () use ( &$buffer, &$parts, $terms ) {
			if ( $buffer ) {
				array_push( $parts, ...self::make_html_segment( $buffer, $terms ) );
				$buffer = array();
			}
		};

		foreach ( $items as $item ) {
			if ( 0 === $depth ) {
				if ( 'raw' === $item['type'] && self::is_block_raw( $item['str'] ) ) {
					$flush();
					$parts[] = $item['str'];
					continue;
				}

				if ( 'tag' === $item['type'] && ! $item['closing'] && in_array( $item['name'], self::BLOCK_ELEMENTS, true ) ) {
					$flush();
				}

				if ( 'text' === $item['type'] && false !== strpos( $item['str'], "\n" ) ) {
					// Top-level line breaks are meaningful (wpautop): split there and keep them.
					$lines = preg_split( '/(\r?\n)/', $item['str'], -1, PREG_SPLIT_DELIM_CAPTURE );
					foreach ( (array) $lines as $k => $line ) {
						if ( 1 === $k % 2 ) {
							$flush();
							$parts[] = $line;
						} elseif ( '' !== $line ) {
							$buffer[] = array( 'type' => 'text', 'str' => $line );
						}
					}
					continue;
				}
			}

			$buffer[] = $item;

			if ( 'tag' === $item['type'] && ! $item['void'] ) {
				$depth += $item['closing'] ? -1 : 1;
			}

			if ( 0 === $depth && 'tag' === $item['type'] && in_array( $item['name'], self::BLOCK_ELEMENTS, true ) && ( $item['closing'] || $item['void'] ) ) {
				$flush();
			}
		}

		$flush();

		return self::merge_literals( $parts );
	}

	/**
	 * Tells whether a raw item stands as a block of its own at the top level (comments, code blocks...).
	 *
	 * @since 4.2.0
	 *
	 * @param string $str Raw item.
	 * @return bool
	 */
	protected static function is_block_raw( string $str ): bool {
		return (bool) preg_match( '~^(?:<!--|<(?:script|style|pre|textarea|svg|math|noscript|iframe|object|template|video|audio|canvas)\b)~i', $str );
	}

	/**
	 * Builds the parts of one HTML segment: outer whitespace, then the segment itself (or a literal when
	 * there is nothing to translate).
	 *
	 * @since 4.2.0
	 *
	 * @param array[]  $items Items of the segment.
	 * @param array[]  $terms Protected terms, see `self::get_terms()`.
	 * @return array Parts.
	 */
	protected static function make_html_segment( array $items, array $terms ): array {
		$html   = '';
		$tokens = array();
		$kept     = array();
		$glossary = array();
		$n        = 0;
		$text     = implode( '', wp_list_pluck( wp_list_filter( $items, array( 'type' => 'text' ) ), 'str' ) );

		// HTML mode when there are tags, or kept words: the engine translates better around a visible word.
		$mode = in_array( 'tag', wp_list_pluck( $items, 'type' ), true ) || self::has_keep_words( $text, $terms ) ? self::MODE_HTML : self::MODE_TEXT;

		foreach ( $items as $item ) {
			// Raw items, and line breaks between tags (kept exactly as they are, the engine moves them around).
			if ( 'raw' === $item['type'] || ( 'text' === $item['type'] && '' === trim( $item['str'] ) && false !== strpos( $item['str'], "\n" ) ) ) {
				$tokens[ ++$n ] = $item['str'];
				$html          .= self::MODE_HTML === $mode ? sprintf( '<span data-st="%d"></span>', $n ) : sprintf( '⟦%d⟧', $n );
				continue;
			}

			if ( 'tag' === $item['type'] ) {
				$html .= $item['str'];
				continue;
			}

			$html .= self::protect_text( $item['str'], $terms, $mode, $tokens, $kept, $n, $glossary );
		}

		return self::finish_segment( $html, $mode, $tokens, $kept, $items, false, $glossary );
	}

	/**
	 * Protects the shortcodes, printf placeholders and kept words of a text, and escapes it in HTML mode.
	 *
	 * @since 4.2.0
	 *
	 * @param string   $text   Text (no markup).
	 * @param array[]  $terms  Protected terms, see `self::get_terms()`.
	 * @param int      $mode   Segment mode.
	 * @param array    $tokens Protected parts, by number.
	 * @param array    $kept     Kept words, by number.
	 * @param int      $n        Last token number.
	 * @param array    $glossary Translations of the glossary terms, by number.
	 * @return string
	 */
	protected static function protect_text( string $text, array $terms, int $mode, array &$tokens, array &$kept, int &$n, array &$glossary ): string {
		$patterns   = array( self::VARIABLE_REGEX, self::PRINTF_REGEX ); // Variables first: `%%sep%%` is not `%%` + `sep` + `%%`.
		$shortcodes = self::get_shortcode_regex();

		if ( '' !== $shortcodes ) {
			array_unshift( $patterns, $shortcodes );
		}

		$pattern = '~' . implode( '|', array_map( array( self::class, 'strip_delimiters' ), $patterns ) ) . '~';
		$out     = '';

		foreach ( self::split_by( $pattern, $text ) as list( $is_protected, $piece ) ) {
			if ( $is_protected ) {
				$tokens[ ++$n ] = $piece;
				$out           .= self::MODE_HTML === $mode ? sprintf( '<span data-st="%d"></span>', $n ) : sprintf( '⟦%d⟧', $n );
				continue;
			}

			if ( self::MODE_HTML === $mode ) {
				$piece = str_replace( array( "\r\n", "\n", "\r" ), ' ', $piece );
				// Escape `&` not starting an entity, and `<` / `>`, which make the engine abort.
				$piece = preg_replace( '/&(?!' . substr( self::ENTITY_BODY, 1 ) . ')/', '&amp;', $piece );
				$piece = str_replace( array( '<', '>' ), array( '&lt;', '&gt;' ), (string) $piece );
			}

			$out .= self::protect_keep( $piece, $terms, $mode, $tokens, $kept, $n, $glossary );
		}

		return $out;
	}

	/**
	 * Removes the delimiters of a PCRE pattern, for use in a combined pattern.
	 *
	 * @since 4.2.0
	 *
	 * @param string $pattern Pattern with `/` or `~` delimiters and no modifiers.
	 * @return string
	 */
	protected static function strip_delimiters( string $pattern ): string {
		return substr( $pattern, 1, -1 );
	}

	/**
	 * Wraps (HTML) or replaces (text) the kept words and the glossary terms.
	 *
	 * In HTML mode, the engine still sees the word in its `<span data-st="N">`, then the span is replaced by
	 * the original word (kept words) or by its translation (glossary terms). In text mode, the word becomes
	 * a `⟦N⟧` token.
	 *
	 * @since 4.2.0
	 * @since 4.3.0 Glossary terms.
	 *
	 * @param string  $text     Text.
	 * @param array[] $terms    Protected terms, see `self::get_terms()`.
	 * @param int     $mode     Segment mode.
	 * @param array   $tokens   Protected parts.
	 * @param array   $kept     Kept words.
	 * @param int     $n        Last token number.
	 * @param array   $glossary Translations of the glossary terms.
	 * @return string
	 */
	protected static function protect_keep( string $text, array $terms, int $mode, array &$tokens, array &$kept, int &$n, array &$glossary ): string {
		if ( empty( $terms ) || '' === $text ) {
			return $text;
		}

		return (string) preg_replace_callback(
			self::get_keep_regex( $terms, $mode ),
			function ( $m ) use ( $terms, $mode, &$tokens, &$kept, &$n, &$glossary ) {
				$to = self::find_translation( $m[1], $terms, $mode );

				if ( null !== $to ) {
					$glossary[ ++$n ] = self::MODE_HTML === $mode ? htmlspecialchars( $to, ENT_NOQUOTES, 'UTF-8' ) : $to;
					return self::MODE_HTML === $mode ? sprintf( '<span data-st="%d">%s</span>', $n, $m[1] ) : sprintf( '⟦%d⟧', $n );
				}

				if ( self::MODE_HTML === $mode ) {
					$kept[ ++$n ] = $m[1];
					return sprintf( '<span data-st="%d">%s</span>', $n, $m[1] );
				}
				$tokens[ ++$n ] = $m[1];
				return sprintf( '⟦%d⟧', $n );
			},
			$text
		);
	}

	/**
	 * Returns the glossary translation of a matched term, in the case of the match: a term written in capitals
	 * in the source is translated in capitals.
	 *
	 * @since 4.3.0
	 *
	 * @param string  $match Matched text (escaped in HTML mode).
	 * @param array[] $terms Protected terms, see `self::get_terms()`.
	 * @param int     $mode  Segment mode.
	 * @return string|null The translation (not escaped), null for a kept word.
	 */
	protected static function find_translation( string $match, array $terms, int $mode ) {
		$word = self::MODE_HTML === $mode ? html_entity_decode( $match, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) : $match;

		// Same order as the alternatives of the pattern: the first term matching the text is the matched one.
		foreach ( $terms as $term ) {
			if ( null === $term['to'] ) {
				if ( $term['word'] === $word ) {
					return null;
				}
				continue;
			}

			if ( mb_strtolower( $term['word'] ) !== mb_strtolower( $word ) ) {
				continue;
			}

			$letters = (string) preg_replace( '/[^\p{L}]/u', '', $word );
			if ( mb_strlen( $letters ) > 1 && mb_strtoupper( $letters ) === $letters && mb_strtoupper( $term['word'] ) !== $term['word'] ) {
				return mb_strtoupper( $term['to'] );
			}

			return $term['to'];
		}

		return null;
	}

	/**
	 * Returns the pattern matching the protected terms, longest first: kept words with their case, glossary
	 * terms in any case.
	 *
	 * @since 4.2.0
	 *
	 * @param array[] $terms Protected terms, see `self::get_terms()`.
	 * @param int     $mode  Mode: in HTML mode, the words are matched in their escaped form.
	 * @return string
	 */
	protected static function get_keep_regex( array $terms, int $mode ): string {
		static $cache = array();

		$key = md5( (string) wp_json_encode( $terms ) ) . $mode;

		if ( ! isset( $cache[ $key ] ) ) {
			$quoted = array_map(
				function ( $term ) use ( $mode ) {
					$quoted = preg_quote( self::MODE_HTML === $mode ? htmlspecialchars( $term['word'], ENT_NOQUOTES, 'UTF-8' ) : $term['word'], '~' );
					return null === $term['to'] ? $quoted : '(?i:' . $quoted . ')';
				},
				$terms
			);

			$cache[ $key ] = '~(?<![\p{L}\p{N}])(' . implode( '|', $quoted ) . ')(?![\p{L}\p{N}])~u';
		}

		return $cache[ $key ];
	}

	/**
	 * Tells whether a text contains one of the protected terms.
	 *
	 * @since 4.2.0
	 *
	 * @param string  $text  Text.
	 * @param array[] $terms Protected terms, see `self::get_terms()`.
	 * @return bool
	 */
	protected static function has_keep_words( string $text, array $terms ): bool {
		return $terms && '' !== $text && preg_match( self::get_keep_regex( $terms, self::MODE_TEXT ), $text );
	}

	/**
	 * Returns the pattern of the registered shortcode tags (opening and closing tags only, not their content).
	 *
	 * @since 4.2.0
	 *
	 * @return string Empty if no shortcodes are registered.
	 */
	protected static function get_shortcode_regex(): string {
		global $shortcode_tags;

		if ( empty( $shortcode_tags ) || ! is_array( $shortcode_tags ) ) {
			return '';
		}

		$names = implode( '|', array_map( 'preg_quote', array_keys( $shortcode_tags ) ) );
		return '~\[\[?/?(?:' . $names . ')(?![\w-])[^\]]*\]\]?~';
	}

	/**
	 * Splits plain text (or malformed markup, all of it protected) into line segments in text mode.
	 *
	 * @since 4.2.0
	 *
	 * @param string   $source    Text.
	 * @param array[]  $terms     Protected terms, see `self::get_terms()`.
	 * @param bool     $malformed Whether the source is malformed markup.
	 * @return array Parts.
	 */
	protected static function split_text( string $source, array $terms, bool $malformed = false ): array {
		$parts = array();
		$lines = preg_split( '/(\r?\n)/', $source, -1, PREG_SPLIT_DELIM_CAPTURE );

		foreach ( (array) $lines as $k => $line ) {
			if ( 1 === $k % 2 || '' === $line ) {
				$parts[] = $line;
				continue;
			}

			$tokens = array();
			$kept     = array();
			$glossary = array();
			$n        = 0;
			$text     = '';

			if ( $malformed ) {
				// Every markup item and entity becomes a token.
				foreach ( self::split_by( '~' . self::MARKUP_BODY . '|' . self::ENTITY_BODY . '|[<>]~si', $line ) as list( $is_markup, $piece ) ) {
					if ( $is_markup ) {
						$tokens[ ++$n ] = $piece;
						$text          .= sprintf( '⟦%d⟧', $n );
					} else {
						$text .= self::protect_text( $piece, $terms, self::MODE_TEXT, $tokens, $kept, $n, $glossary );
					}
				}
			} elseif ( self::has_keep_words( $line, $terms ) ) {
				// Plain text sent in HTML mode so that the kept words stay visible to the engine, unescaped afterwards.
				$text = self::protect_text( htmlspecialchars( $line, ENT_NOQUOTES, 'UTF-8' ), $terms, self::MODE_HTML, $tokens, $kept, $n, $glossary );
				array_push( $parts, ...self::finish_segment( $text, self::MODE_HTML, $tokens, $kept, array( array( 'str' => $line ) ), true, $glossary ) );
				continue;
			} else {
				$text = self::protect_text( $line, $terms, self::MODE_TEXT, $tokens, $kept, $n, $glossary );
			}

			array_push( $parts, ...self::finish_segment( $text, self::MODE_TEXT, $tokens, $kept, null, false, $glossary ) );
		}

		return self::merge_literals( $parts );
	}

	/**
	 * Separates the outer whitespace of a segment and drops segments with nothing to translate.
	 *
	 * @since 4.2.0
	 *
	 * @param string     $text   Protected segment text.
	 * @param int        $mode   Mode.
	 * @param array      $tokens Protected parts.
	 * @param array      $kept   Kept words.
	 * @param array|null $items    Original items, to rebuild a literal.
	 * @param bool       $unescape Whether the source is plain text escaped for the HTML mode.
	 * @param array      $glossary Translations of the glossary terms.
	 * @return array Parts.
	 */
	protected static function finish_segment( string $text, int $mode, array $tokens, array $kept, $items = null, bool $unescape = false, array $glossary = array() ): array {
		$original = self::restore_tokens( $text, $mode, $tokens, $kept, $glossary, self::starts_upper( $text, $mode ) );

		preg_match( '/^(\s*)(.*?)(\s*)$/su', $text, $m );
		$lead  = $m[1] ?? '';
		$core  = $m[2] ?? $text;
		$trail = $m[3] ?? '';

		// Nothing to translate: no letters outside the markup and tokens.
		$visible = self::MODE_HTML === $mode ? preg_replace( '~<span data-st="\d+">.*?</span>|<[^>]+>|&[#a-zA-Z0-9]+;~su', '', $core ) : preg_replace( '/⟦\d+⟧/u', '', $core );
		if ( ! preg_match( '/\p{L}/u', (string) $visible ) ) {
			if ( $glossary ) {
				// Only glossary terms (and kept words): translated without the engine.
				return array( $unescape ? htmlspecialchars_decode( $original, ENT_QUOTES ) : $original );
			}
			return array( null === $items ? $original : implode( '', wp_list_pluck( $items, 'str' ) ) );
		}

		$parts = array();
		if ( '' !== $lead ) {
			$parts[] = self::restore_tokens( $lead, $mode, $tokens, $kept );
		}

		$parts[] = array(
			'mode' => $mode,
			'text' => $core,
			'meta' => array(
				't' => (object) $tokens,
				'k' => (object) $kept,
				'g' => (object) $glossary,
				'u' => $unescape ? 1 : 0,
			),
		);

		if ( '' !== $trail ) {
			$parts[] = self::restore_tokens( $trail, $mode, $tokens, $kept );
		}

		return $parts;
	}

	/**
	 * Merges consecutive literal parts.
	 *
	 * @since 4.2.0
	 *
	 * @param array $parts Parts.
	 * @return array
	 */
	protected static function merge_literals( array $parts ): array {
		$out = array();

		foreach ( $parts as $part ) {
			if ( is_string( $part ) && $out && is_string( end( $out ) ) ) {
				$out[ count( $out ) - 1 ] .= $part;
			} elseif ( ! ( is_string( $part ) && '' === $part ) ) {
				$out[] = $part;
			}
		}

		return $out;
	}

	/**
	 * Puts the protected parts back in a text (no checks).
	 *
	 * A glossary translation starting with a small letter gets a capital at the start of a sentence (at the
	 * start of the segment: only when the source starts with a capital).
	 *
	 * @since 4.2.0
	 * @since 4.3.0 Glossary terms.
	 *
	 * @param string $text        Text with tokens.
	 * @param int    $mode        Mode.
	 * @param array  $tokens      Protected parts.
	 * @param array  $kept        Kept words.
	 * @param array  $glossary    Translations of the glossary terms.
	 * @param bool   $upper_start Whether the source segment starts with a capital.
	 * @return string
	 */
	protected static function restore_tokens( string $text, int $mode, array $tokens, array $kept, array $glossary = array(), bool $upper_start = true ): string {
		$translate = function ( $n, $offset ) use ( $text, $mode, $glossary, $upper_start ) {
			$to = (string) $glossary[ $n ];
			if ( self::is_sentence_start( substr( $text, 0, $offset ), $mode, $upper_start ) && preg_match( '/^\p{Ll}/u', $to ) ) {
				$to = mb_strtoupper( mb_substr( $to, 0, 1 ) ) . mb_substr( $to, 1 );
			}
			return $to;
		};

		if ( self::MODE_HTML === $mode ) {
			return (string) preg_replace_callback(
				'~<span data-st="(\d+)">(.*?)</span>~su',
				function ( $m ) use ( $tokens, $kept, $glossary, $translate ) {
					$n = $m[1][0];
					if ( isset( $glossary[ $n ] ) ) {
						return $translate( $n, $m[0][1] );
					}
					if ( isset( $kept[ $n ] ) ) {
						return $kept[ $n ];
					}
					// A protected part, with any text the engine may have moved into it put after it.
					return isset( $tokens[ $n ] ) ? $tokens[ $n ] . $m[2][0] : $m[0][0];
				},
				$text,
				-1,
				$count,
				PREG_OFFSET_CAPTURE
			);
		}

		return (string) preg_replace_callback(
			'/⟦(\d+)⟧/u',
			function ( $m ) use ( $tokens, $glossary, $translate ) {
				$n = $m[1][0];
				if ( isset( $glossary[ $n ] ) ) {
					return $translate( $n, $m[0][1] );
				}
				return $tokens[ $n ] ?? $m[0][0];
			},
			$text,
			-1,
			$count,
			PREG_OFFSET_CAPTURE
		);
	}

	/**
	 * Tells whether a protected segment starts with a capital (in HTML mode, the kept words and glossary terms
	 * count, as the engine sees them). Always true in text mode, where they are hidden from the engine.
	 *
	 * @since 4.3.0
	 *
	 * @param string $text Protected segment text.
	 * @param int    $mode Mode.
	 * @return bool
	 */
	protected static function starts_upper( string $text, int $mode ): bool {
		if ( self::MODE_HTML !== $mode ) {
			return true;
		}

		$visible = html_entity_decode( (string) preg_replace( '~<[^>]*>~', '', $text ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );

		return (bool) preg_match( '/^[^\p{L}]*\p{Lu}/u', $visible );
	}

	/**
	 * Tells whether a position in a text is the start of a sentence.
	 *
	 * @since 4.3.0
	 *
	 * @param string $before      Text before the position.
	 * @param int    $mode        Mode.
	 * @param bool   $upper_start Value at the start of the text.
	 * @return bool
	 */
	protected static function is_sentence_start( string $before, int $mode, bool $upper_start ): bool {
		$before = self::MODE_HTML === $mode
			? html_entity_decode( (string) preg_replace( '~<[^>]*>~', '', $before ), ENT_QUOTES | ENT_HTML5, 'UTF-8' )
			: (string) preg_replace( '/⟦\d+⟧/u', '', $before );

		// Nothing but spaces and opening punctuation (quotes, brackets, ¿, ¡) before it.
		if ( ! preg_match( '/[^\s\p{Ps}\p{Pi}"\x27¿¡]/u', $before ) ) {
			return $upper_start;
		}

		return (bool) preg_match( '/[.!?…。！？][\p{Pe}\p{Pf}"\x27]*\s+[\p{Ps}\p{Pi}"\x27¿¡]*$/u', $before );
	}

	/**
	 * Restores and checks the engine output of a segment.
	 *
	 * @since 4.2.0
	 *
	 * @param string $source Protected segment text, as sent to the engine.
	 * @param string $output Engine output.
	 * @param int    $mode   Mode.
	 * @param array  $meta   Segment meta: `t` tokens, `k` kept words, `g` glossary translations, `u` unescape.
	 * @param string $target Target language code.
	 * @return string|WP_Error The final text, or the reason why the output was rejected.
	 */
	public static function restore( string $source, string $output, int $mode, array $meta, string $target ) {
		$tokens = (array) ( $meta['t'] ?? array() );
		$kept     = (array) ( $meta['k'] ?? array() );
		$glossary = (array) ( $meta['g'] ?? array() );
		$output   = trim( $output );

		if ( '' === $output ) {
			return new WP_Error( 'strope_mt_empty', 'empty output' );
		}

		// A plain text segment has no markup: an output with markup is not a translation of it.
		if ( self::MODE_HTML !== $mode && preg_match( '/[<>]/', $output ) && ! preg_match( '/[<>]/', $source ) ) {
			return new WP_Error( 'strope_mt_markup', 'markup added' );
		}

		// Every token must be found exactly once.
		$pattern = self::MODE_HTML === $mode ? '~<span data-st="(\d+)">~' : '/⟦(\d+)⟧/u';
		preg_match_all( $pattern, $output, $found );
		$expected = array_merge( array_keys( $tokens ), array_keys( $kept ), array_keys( $glossary ) );
		$got      = array_map( 'intval', $found[1] );
		sort( $expected );
		sort( $got );

		if ( array_map( 'intval', $expected ) !== $got ) {
			return new WP_Error( 'strope_mt_tokens', 'protected parts lost or duplicated' );
		}

		// No broken token left over (the engine sometimes emits an extra `⟦6` next to the real `⟦6⟧`).
		$strays = function ( $text ) use ( $pattern ) {
			$rest = (string) preg_replace( $pattern, '', $text );
			return preg_match_all( '/[⟦⟧]|data-st=/u', $rest );
		};
		if ( $strays( $output ) !== $strays( $source ) ) {
			return new WP_Error( 'strope_mt_tokens', 'broken protected part' );
		}

		if ( self::MODE_HTML === $mode ) {
			if ( self::tag_signature( $source ) !== self::tag_signature( $output ) ) {
				return new WP_Error( 'strope_mt_markup', 'markup changed' );
			}

			$output = self::repair_spacing( $source, $output, $target );
		} else {
			$output = (string) preg_replace( '/ {2,}/', ' ', $output );
		}

		$check = self::check_text( $source, $output, $mode );
		if ( is_wp_error( $check ) ) {
			return $check;
		}

		$output = self::restore_tokens( $output, $mode, $tokens, $kept, $glossary, self::starts_upper( $source, $mode ) );

		// Plain text sent in HTML mode: back to plain text.
		return empty( $meta['u'] ) ? $output : htmlspecialchars_decode( $output, ENT_QUOTES );
	}

	/**
	 * Returns a sorted signature of the tags of a markup: names and attributes, ignoring their order and quoting.
	 *
	 * @since 4.2.0
	 *
	 * @param string $html Markup.
	 * @return string
	 */
	protected static function tag_signature( string $html ): string {
		preg_match_all( '~<(/?)([A-Za-z][A-Za-z0-9:-]*)((?:\s(?:[^>"\']|"[^"]*"|\'[^\']*\')*)?)/?>~s', $html, $matches, PREG_SET_ORDER );
		$tags = array();

		foreach ( $matches as $m ) {
			$name = strtolower( $m[2] );
			preg_match_all( '~([^\s=/"\']+)(?:\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s"\'>]+)))?~', $m[3], $attrs, PREG_SET_ORDER );
			$list = array();
			foreach ( $attrs as $a ) {
				$value  = ( $a[2] ?? '' ) . ( $a[3] ?? '' ) . ( $a[4] ?? '' );
				$list[] = strtolower( $a[1] ) . '=' . html_entity_decode( $value, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
			}
			sort( $list );
			$tags[] = $m[1] . $name . ' ' . implode( ' ', $list );
		}

		sort( $tags );
		return implode( "\n", $tags );
	}

	/**
	 * Fixes the whitespace that the engine moves around tags: a missing space before or after a tag which
	 * had one in the source, and double spaces.
	 *
	 * @since 4.2.0
	 *
	 * @param string $source Protected source.
	 * @param string $output Engine output.
	 * @param string $target Target language code.
	 * @return string
	 */
	protected static function repair_spacing( string $source, string $output, string $target ): string {
		$tag = '~<(?:/?[A-Za-z][^>]*)>~';

		// Whitespace around each tag of the source, by tag string, in order.
		$flags = array();
		if ( preg_match_all( $tag, $source, $matches, PREG_OFFSET_CAPTURE ) ) {
			foreach ( $matches[0] as $match ) {
				list( $str, $offset ) = $match;
				$key                  = self::normalize_tag( $str );
				$before               = $offset > 0 ? substr( $source, $offset - 1, 1 ) : '';
				$after                = substr( $source, $offset + strlen( $str ), 1 );
				$flags[ $key ][]      = array( ctype_space( $before ), ctype_space( $after ), $str );
			}
		}

		$spaced = ! in_array( $target, self::UNSPACED_LANGUAGES, true );
		$parts  = preg_split( '~(<(?:/?[A-Za-z][^>]*)>)~', $output, -1, PREG_SPLIT_DELIM_CAPTURE );
		$count  = count( (array) $parts );

		for ( $i = 1; $i < $count; $i += 2 ) {
			$key = self::normalize_tag( $parts[ $i ] );
			if ( empty( $flags[ $key ] ) ) {
				continue;
			}

			list( $space_before, $space_after, $original ) = array_shift( $flags[ $key ] );

			// The engine rewrites some tags (`<br />` becomes `<br>`): use the source tag.
			$parts[ $i ] = $original;

			if ( ! $spaced ) {
				continue;
			}

			$prev = $parts[ $i - 1 ];
			$next = $parts[ $i + 1 ] ?? '';

			if ( $space_before && '' !== $prev && preg_match( '/[\p{L}\p{N}]$/u', $prev ) ) {
				$parts[ $i - 1 ] = $prev . ' ';
			}

			if ( $space_after && '' !== $next && preg_match( '/^[\p{L}\p{N}(]/u', $next ) ) {
				$parts[ $i + 1 ] = ' ' . $next;
			}
		}

		$output = implode( '', (array) $parts );

		// Double spaces (the source never has any outside `<pre>`, which is protected).
		if ( false === strpos( $source, '  ' ) ) {
			$output = (string) preg_replace( '/ {2,}/', ' ', $output );
		}

		return trim( $output );
	}

	/**
	 * Normalizes a tag string to compare source and output tags.
	 *
	 * @since 4.2.0
	 *
	 * @param string $tag Tag.
	 * @return string
	 */
	protected static function normalize_tag( string $tag ): string {
		return strtolower( (string) preg_replace( array( '~\s*/?>$~', '~\s+~' ), array( '>', ' ' ), $tag ) );
	}

	/**
	 * Rejects outputs which are most likely broken: much shorter or longer than the source, or looping.
	 *
	 * @since 4.2.0
	 *
	 * @param string $source Protected source.
	 * @param string $output Output.
	 * @param int    $mode   Mode.
	 * @return true|WP_Error
	 */
	protected static function check_text( string $source, string $output, int $mode ) {
		$visible = function ( $text ) use ( $mode ) {
			$text = self::MODE_HTML === $mode ? wp_strip_all_tags( html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ) : preg_replace( '/⟦\d+⟧/u', '', $text );
			return trim( (string) preg_replace( '/\s+/u', ' ', (string) $text ) );
		};

		$src = $visible( $source );
		$out = $visible( $output );
		$len = mb_strlen( $src );

		if ( $len >= 30 ) {
			$ratio = mb_strlen( $out ) / $len;
			if ( $ratio < 0.3 || $ratio > 3.5 ) {
				return new WP_Error( 'strope_mt_length', 'unexpected length' );
			}
		}

		$loop = '/(?<![\p{L}\p{N}])([\p{L}\p{N}]+)(?:[\s,.;:-]+\1(?![\p{L}\p{N}])){4,}/iu';
		if ( preg_match( $loop, $out ) && ! preg_match( $loop, $src ) ) {
			return new WP_Error( 'strope_mt_loop', 'repeated words' );
		}

		return true;
	}
}
