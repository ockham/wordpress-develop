<?php

/**
 * Tests for the WP_CSS_Builder class.
 *
 * @group css-api
 *
 * @coversDefaultClass WP_CSS_Builder
 */
class Tests_CssApi_WpCssBuilder extends WP_UnitTestCase {
	/**
	 * @dataProvider data_string_escaping
	 *
	 * @covers ::string
	 */
	public function test_string_escaping( string $input, string $expected ): void {
		$this->assertSame( $expected, WP_CSS_Builder::string( $input ) );

		/**
		 * Ensure that a single, equivalent CSS string is produced.
		 *
		 * CSS pre-processing normalization is applied to the input to ensure
		 * a match can be found.
		 *
		 * @see https://www.w3.org/TR/css-syntax-3/#input-preprocessing
		 */
		$processor = WP_CSS_Token_Processor::create( WP_CSS_Builder::string( $input ) );
		$processor->next_token();
		$this->assertSame( WP_CSS_Token_Processor::TOKEN_STRING, $processor->get_token_type() );
		$expected_decoded_value = strtr(
			$input,
			array(
				"\r\n" => "\n",
				"\r"   => "\n",
				"\f"   => "\n",
				"\0"   => '�',
			)
		);
		$this->assertSame( $expected_decoded_value, $processor->get_token_value() );
		$this->assertFalse( $processor->next_token() );
	}

	/**
	 * Data provider for string escaping cases.
	 *
	 * @return array
	 */
	public static function data_string_escaping(): array {
		return array(
			// Passthrough — no escaping needed, only quoting.
			'empty string'            => array( '', '""' ),
			'simple ASCII'            => array( 'Arial', '"Arial"' ),
			'spaces preserved'        => array( 'Exo 2', '"Exo 2"' ),
			'leading/trailing spaces' => array( '  Arial  ', '"  Arial  "' ),
			'whitespace-only'         => array( '   ', '"   "' ),
			'numbers pass through'    => array( '12345', '"12345"' ),
			'non-ASCII passthrough'   => array( 'café', '"café"' ),

			// Backslash escaping — must happen first to prevent double-escaping.
			'backslash'               => array( 'Back\\Slash', '"Back\5C Slash"' ),
			'double backslash'        => array( '\\\\', '"\5C \5C "' ),
			'backslash before quote'  => array( "a\\'b", '"a\5C \27 b"' ),

			// NULL byte → U+FFFD replacement character.
			'null byte'               => array( "a\0b", '"a�b"' ),

			// Newline normalization — all variants become \A escape.
			'LF'                      => array( "a\nb", '"a\A b"' ),
			'CR'                      => array( "a\rb", '"a\A b"' ),
			'CRLF as single escape'   => array( "a\r\nb", '"a\A b"' ),
			'form feed'               => array( "a\fb", '"a\A b"' ),

			// HTML-problematic characters.
			'HTML characters < > &'   => array( 'a<b>c&d', '"a\3C b\3E c\26 d"' ),

			// CSS-problematic characters.
			'CSS syntax , ; { }'      => array( 'a,b;c{d}', '"a\2C b\3B c\7B d\7D "' ),
			'single quote'            => array( "CSS's strings", '"CSS\27 s strings"' ),
			'double quote'            => array( 'Say "Hi"', '"Say \22 Hi\22 "' ),
		);
	}

	/**
	 * Tests the example from the class docblock.
	 *
	 * @covers ::string
	 */
	public function test_docblock_example(): void {
		$value    = 'CSS & a "<style>" tag\'s strings';
		$expected = '"CSS \26  a \22 \3C style\3E \22  tag\27 s strings"';

		$this->assertSame( $expected, WP_CSS_Builder::string( $value ) );
	}

	/**
	 * Tests a string containing mixed newline types.
	 *
	 * @covers ::string
	 */
	public function test_mixed_newlines(): void {
		$input    = "line1\nline2\rline3\r\nline4\fline5";
		$expected = '"line1\A line2\A line3\A line4\A line5"';

		$this->assertSame( $expected, WP_CSS_Builder::string( $input ) );
	}

	/**
	 * Tests WP_CSS_Builder::ident() produces valid CSS ident tokens.
	 *
	 * @ticket TBD
	 *
	 * @dataProvider data_ident
	 *
	 * @covers ::ident
	 */
	public function test_ident( string $input, string $expected ): void {
		$this->assertSame( $expected, WP_CSS_Builder::ident( $input ) );
	}

	/**
	 * Data provider for ident() tests.
	 */
	public static function data_ident(): Generator {
		// Simple idents — no escaping needed.
		yield 'Simple alpha ident' => array( 'serif', 'serif' );
		yield 'Hyphenated ident' => array( 'sans-serif', 'sans-serif' );
		yield 'Underscore prefix' => array( '_foo', '_foo' );
		yield 'Single char' => array( 'a', 'a' );
		yield 'Custom property prefix' => array( '--custom', '--custom' );

		// Invalid ident starts — must be escaped.
		yield 'Leading digits' => array( '123', '\\31 23' );
		yield 'Leading digit with alpha' => array( '5foo', '\\35 foo' );
		yield 'Hyphen then digit' => array( '-5px', '-\\35 px' );
		yield 'Leading space' => array( ' leading-space', '\\20 leading-space' );
		yield 'Leading tab' => array( "\tleading-tab", '\\9 leading-tab' );

		// Whitespace within ident.
		yield 'Space within' => array( 'My Font', 'My\\20 Font' );
		yield 'Multiple spaces within' => array( 'a b c', 'a\\20 b\\20 c' );
		yield 'Tab within' => array( "has\ttab", 'has\\9 tab' );
		yield 'Newline within' => array( "has\nnewline", 'has\\A newline' );

		// Special characters.
		yield 'Apostrophe' => array( "Font's", 'Font\\27 s' );
		yield 'Angle brackets' => array( '<html>', '\\3C html\\3E ' );
		yield 'Comma' => array( 'a,b', 'a\\2C b' );
		yield 'Semicolon' => array( 'a;b', 'a\\3B b' );
	}
}
