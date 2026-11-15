<?php
/**
 * @package Strope-Translate
 */

use PHPUnit\Framework\TestCase;

/**
 * Tests of Strope_MT_Segmenter: what is split must be restored.
 */
class Segmenter_Test extends TestCase {

	/**
	 * Resets the shortcodes.
	 */
	protected function setUp(): void {
		$GLOBALS['shortcode_tags'] = array();
	}

	/**
	 * Splits a text and restores it with each segment "translated" into itself.
	 *
	 * @param string $source Text.
	 * @return string
	 */
	protected function roundtrip( $source ) {
		$out = '';

		foreach ( Strope_MT_Segmenter::split( $source ) as $part ) {
			if ( is_string( $part ) ) {
				$out .= $part;
				continue;
			}

			$this->assertArrayHasKey( 'mode', $part );
			$this->assertArrayHasKey( 'text', $part );
			$this->assertArrayHasKey( 'meta', $part );

			$restored = Strope_MT_Segmenter::restore( $part['text'], $part['text'], $part['mode'], (array) $part['meta'], 'en' );

			$this->assertIsString( $restored, is_wp_error( $restored ) ? $restored->get_error_message() : '' );
			$out .= $restored;
		}

		return $out;
	}

	/**
	 * @dataProvider provide_texts
	 *
	 * @param string $source Text.
	 */
	public function test_roundtrip_gives_the_text_back( $source ) {
		$this->assertSame( $source, $this->roundtrip( $source ) );
	}

	/**
	 * Texts.
	 *
	 * @return array[]
	 */
	public function provide_texts() {
		return array(
			'plain sentence' => array( 'Hello world.' ),
			'printf'         => array( 'You have %d new messages from %1$s today.' ),
			'seo variable'   => array( 'Our products %%sep%% %%sitename%%' ),
			'inline markup'  => array( '<p>Read <a href="https://example.com/">this <strong>long</strong> page</a> now.</p>' ),
			'two paragraphs' => array( '<p>First paragraph.</p><p>Second paragraph.</p>' ),
			'entity'         => array( 'Fish &amp; chips are served here.' ),
		);
	}

	/**
	 * A shortcode is never sent to the engine.
	 */
	public function test_shortcode_is_protected() {
		$GLOBALS['shortcode_tags'] = array( 'price' => '__return_empty_string' );
		$source                    = 'The price is [price id="3"] today.';

		foreach ( Strope_MT_Segmenter::split( $source ) as $part ) {
			if ( is_array( $part ) ) {
				$this->assertStringNotContainsString( '[price', $part['text'] );
			}
		}

		$this->assertSame( $source, $this->roundtrip( $source ) );
	}

	/**
	 * A text made of a segment is split into at least one segment.
	 */
	public function test_sentence_gives_a_segment() {
		$segments = array_filter( Strope_MT_Segmenter::split( 'Hello world.' ), 'is_array' );

		$this->assertNotEmpty( $segments );
	}

	/**
	 * An empty engine output is rejected.
	 */
	public function test_empty_output_is_rejected() {
		$result = Strope_MT_Segmenter::restore( 'Hello world.', '  ', Strope_MT_Segmenter::MODE_TEXT, array(), 'en' );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'strope_mt_empty', $result->get_error_code() );
	}

	/**
	 * An engine output which lost a protected part is rejected.
	 */
	public function test_lost_placeholder_is_rejected() {
		$segments = array_values( array_filter( Strope_MT_Segmenter::split( 'You have %d new messages today.' ), 'is_array' ) );

		$this->assertNotEmpty( $segments );

		$segment = $segments[0];

		if ( empty( $segment['meta']['t'] ) ) {
			$this->markTestSkipped( 'The placeholder is not protected inside the segment.' );
		}

		$result = Strope_MT_Segmenter::restore( $segment['text'], 'Je hebt nieuwe berichten vandaag.', $segment['mode'], (array) $segment['meta'], 'nl' );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'strope_mt_tokens', $result->get_error_code() );
	}
}
