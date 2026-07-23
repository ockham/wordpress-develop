<?php

abstract class WP_CSS_Builder {
	/**
	 * Create a CSS ident token from a plain PHP string value.
	 *
	 * Characters not valid in CSS identifiers are hex-escaped. This uses
	 * the same safety escaping as {@see WP_CSS_Builder::string()} for HTML
	 * and CSS-sensitive characters, plus escaping of whitespace and other
	 * characters not permitted in idents.
	 *
	 * @see https://www.w3.org/TR/css-syntax-3/#escaping
	 * @see https://www.w3.org/TR/css-syntax-3/#would-start-an-identifier
	 *
	 * @param string $value Decoded string value to encode as a CSS ident.
	 * @return string CSS ident token text.
	 */
	public static function ident( string $value ): string {
		$value  = wp_scrub_utf8( $value );
		$result = '';
		$length = strlen( $value );

		for ( $i = 0; $i < $length; $i++ ) {
			$byte = ord( $value[ $i ] );

			// NULL → U+FFFD REPLACEMENT CHARACTER.
			if ( 0x00 === $byte ) {
				$result .= "\u{FFFD}";
				continue;
			}

			// Non-ASCII bytes (≥ 0x80): valid in idents, pass through.
			if ( $byte >= 0x80 ) {
				$result .= $value[ $i ];
				continue;
			}

			// ASCII letters and underscore: always valid in idents.
			if (
				( $byte >= 0x41 && $byte <= 0x5A ) || // A-Z
				( $byte >= 0x61 && $byte <= 0x7A ) || // a-z
				0x5F === $byte                         // _
			) {
				$result .= $value[ $i ];
				continue;
			}

			// Hyphen: valid in idents, but check for hyphen-digit at start.
			if ( 0x2D === $byte ) {
				// Hyphen at position 0 followed by a digit at position 1: escape the digit.
				if ( 0 === $i && $i + 1 < $length && ord( $value[ $i + 1 ] ) >= 0x30 && ord( $value[ $i + 1 ] ) <= 0x39 ) {
					$result .= '-';
					++$i;
					$result .= sprintf( '\\%X ', ord( $value[ $i ] ) );
					continue;
				}
				$result .= '-';
				continue;
			}

			// Digits: valid except at position 0.
			if ( $byte >= 0x30 && $byte <= 0x39 ) {
				if ( 0 === $i ) {
					$result .= sprintf( '\\%X ', $byte );
				} else {
					$result .= $value[ $i ];
				}
				continue;
			}

			// Everything else: hex-escape.
			$result .= sprintf( '\\%X ', $byte );
		}

		return $result;
	}

	/**
	 * Create a quoted CSS string from a plain PHP string value.
	 *
	 * Example:
	 *     $value = 'CSS & a "<style>" tag\'s strings';
	 *     $css_string = WP_CSS_Builder::string( $value );
	 *     echo "<style>*::before { content: {$css_string}; }</style>";
	 *
	 * CSS strings are quoted many characters that are problematic in HTML
	 * or may be complicated for rudimentary CSS or HTML processors to handle
	 * are encoded using Unicode escape sequences.
	 *
	 * @see https://www.w3.org/TR/css-syntax-3/#escaping
	 */
	public static function string( string $value ): string {
		$value   = wp_scrub_utf8( $value );
		$escaped = strtr(
			$value,
			array(
				// Escape existing backslashes to prevent unintentional escapes in result.
				'\\'   => '\\5C ',

				// Pre-processing replaces NULLs and some newlines. Replace and escape as necessary.
				"\0"   => "\u{FFFD}",

				// Normalize and replace newlines. https://www.w3.org/TR/css-syntax-3/#input-preprocessing
				"\r\n" => '\\A ',
				"\r"   => '\\A ',
				"\f"   => '\\A ',

				// Newlines must be escaped in CSS strings.
				"\n"   => '\\A ',

				// Arbitrary characters for Unicode escaping:

				// HTML syntax may be problematic.
				'<'    => '\\3C ',
				'>'    => '\\3E ',
				'&'    => '\\26 ',

				// CSS syntax may be problematic.
				','    => '\\2C ',
				';'    => '\\3B ',
				'{'    => '\\7B ',
				'}'    => '\\7D ',
				'"'    => '\\22 ',
				"'"    => '\\27 ',
			)
		);
		return "\"{$escaped}\"";
	}

	public static function normalize_and_escape_css( string $css ): string {
		$css       = wp_scrub_utf8( $css );
		$processor = WP_CSS_Token_Processor::create( $css );
		if ( null === $processor ) {
			return '';
		}

		$normalized_css = '';

		while ( $processor->next_token() ) {
			switch ( $processor->get_token_type() ) {

				// Basic punctuation:
				case WP_CSS_Token_Processor::TOKEN_SEMICOLON:
					$normalized_css .= ';';
					break;
				case WP_CSS_Token_Processor::TOKEN_COMMA:
					$normalized_css .= ',';
					break;
				case WP_CSS_Token_Processor::TOKEN_WHITESPACE:
					$normalized_css .= ' ';
					break;
				case WP_CSS_Token_Processor::TOKEN_COLON:
					$normalized_css .= ':';
					break;

				// Paired punctuation:
				case WP_CSS_Token_Processor::TOKEN_LEFT_BRACE:
					$normalized_css .= '{';
					break;
				case WP_CSS_Token_Processor::TOKEN_RIGHT_BRACE:
					$normalized_css .= '}';
					break;
				case WP_CSS_Token_Processor::TOKEN_LEFT_PAREN:
					$normalized_css .= '(';
					break;
				case WP_CSS_Token_Processor::TOKEN_RIGHT_PAREN:
					$normalized_css .= ')';
					break;
				case WP_CSS_Token_Processor::TOKEN_LEFT_BRACKET:
					$normalized_css .= '[';
					break;
				case WP_CSS_Token_Processor::TOKEN_RIGHT_BRACKET:
					$normalized_css .= ']';
					break;

				// "@" + ident
				case WP_CSS_Token_Processor::TOKEN_AT_KEYWORD:
					$normalized_css .= '@' . self::ident( $processor->get_token_value() );
					break;

				// ident + "("
				case WP_CSS_Token_Processor::TOKEN_FUNCTION:
					$normalized_css .= self::ident( $processor->get_token_value() ) . '(';
					break;

				/*
				 * Hash tokens are not idents but their value can be escaped as such.
				 *
				 *     ‖→ "#" →─┐   ┌──────────────────────────────┐   ┌─→‖
				 *              ├─→─┤ a-z A-Z 0-9 _ - or non-ASCII ├─→─┤
				 *              │   └──────────────────────────────┘   │
				 *              │   ┌──────────────────────────────┐   │
				 *              ├─→─┤            escape            ├─→─┤
				 *              │   └──────────────────────────────┘   │
				 *              └──────────────────←───────────────────┘
				 */
				case WP_CSS_Token_Processor::TOKEN_HASH:
					$normalized_css .= '#' . self::ident( $processor->get_token_value() );
					break;

				case WP_CSS_Token_Processor::TOKEN_DIMENSION:
					$normalized_css .= $processor->get_token_value() . $processor->get_token_unit();
					break;

				case WP_CSS_Token_Processor::TOKEN_PERCENTAGE:
					$normalized_css .= "%{$processor->get_token_value()}";
					break;

				case WP_CSS_Token_Processor::TOKEN_NUMBER:
					$normalized_css .= $processor->get_token_value();
					break;

				case WP_CSS_Token_Processor::TOKEN_DELIM:
					$normalized_css .= $processor->get_token_value();
					break;

				case WP_CSS_Token_Processor::TOKEN_IDENT:
					$normalized_css .= self::ident( $processor->get_token_value() );
					break;

				case WP_CSS_Token_Processor::TOKEN_STRING:
					var_dump( $processor->get_token_value() );
					$normalized_css .= self::string( $processor->get_token_value() );
					break;

				// Keep or strip comments?
				case WP_CSS_Token_Processor::TOKEN_COMMENT:
					$normalized_css .= substr( $css, $processor->get_token_start(), $processor->get_token_length() );
					break;

				/**
				 * A <bad-string-token> is an open string that reaches a newline.
				 *
				 * @see https://www.w3.org/TR/css-syntax-3/#consume-string-token
				 *
				 * @see https://www.w3.org/TR/css-syntax-3/#preserved-tokens
				 * > Note: The tokens <}-token>s, <)-token>s, <]-token>, <bad-string-token>, and <bad-url-token> are always parse errors, but they are preserved in the token stream by this specification to allow other specs, such as Media Queries, to define more fine-grained error-handling than just dropping an entire declaration or block.
				 */
				case WP_CSS_Token_Processor::TOKEN_BAD_STRING:
					$normalized_css .= substr( $css, $processor->get_token_start(), $processor->get_token_length() ) . "\n";
					break;

				case WP_CSS_Token_Processor::TOKEN_URL:
				case WP_CSS_Token_Processor::TOKEN_BAD_URL:
				case WP_CSS_Token_Processor::TOKEN_CDC:
				case WP_CSS_Token_Processor::TOKEN_CDO:
				default:
					throw new Error( 'unhandled token type ' . $processor->get_token_type() . ' with value ' . var_export( $processor->get_token_value(), true ) );
			}
		}

		return strtr(
			$normalized_css,
			array(
				' '  => '␠',
				"\t" => "␉\t",
				"\n" => "␊\n",
			)
		);
	}
}
