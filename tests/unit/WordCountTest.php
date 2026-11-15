<?php
/**
 * @package Strope-Translate
 */

use PHPUnit\Framework\TestCase;

/**
 * Tests of Strope_Word_Count::count().
 */
class Word_Count_Test extends TestCase {

	/**
	 * @dataProvider provide_texts
	 *
	 * @param string $text     Text.
	 * @param int    $expected Number of words.
	 */
	public function test_count( $text, $expected ) {
		$this->assertSame( $expected, Strope_Word_Count::count( $text ) );
	}

	/**
	 * Texts and their number of words.
	 *
	 * @return array[]
	 */
	public function provide_texts() {
		return array(
			'empty'            => array( '', 0 ),
			'spaces'           => array( "  \n ", 0 ),
			'only a url'       => array( 'https://example.com/image.png', 0 ),
			'two words'        => array( 'Hello world', 2 ),
			'inner characters' => array( "It's an e-mail, 3.5 and 1,000", 6 ),
			'markup'           => array( '<p>Hello <strong>big</strong> world</p>', 3 ),
			'shortcode'        => array( '[gallery ids="1,2"] Photo', 1 ),
			'url in a text'    => array( 'Visit https://example.com today', 2 ),
			'entities'         => array( 'caf&eacute; ol&eacute;', 2 ),
			'japanese'         => array( '日本語のテキスト', 8 ),
			'script removed'   => array( '<script>var a = 1;</script>Done', 1 ),
		);
	}
}
