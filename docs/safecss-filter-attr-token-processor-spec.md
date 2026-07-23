# safecss_filter_attr() Token Processor Re-implementation Specification

## Purpose

Re-implement `safecss_filter_attr()` in `src/wp-includes/kses.php` on top of
`WP_CSS_Token_Processor`, replacing the current `explode( ';' )` splitting and
regex-based validation with CSS Syntax Level 3 tokenization.

The function's contract is unchanged: it receives the decoded declaration-list
contents of an HTML `style` attribute and returns a filtered declaration list,
needing HTML escaping before being written back into an attribute.

Guiding principles, shared with the style attribute processor spec:

1. follow CSS declaration-list semantics when discovering declarations;
2. preserve authored source text of accepted declarations;
3. validate structurally over decoded tokens, not over raw bytes;
4. when behavior must diverge from the current implementation, diverge only
   toward strictness, except where an existing unit test pins the looser
   behavior.

The existing PHPUnit suites are the acceptance gate and must pass unchanged:

- `data_safecss_filter_attr`
- `data_kses_style_attr_with_url`
- `data_safecss_filter_attr_filtered`
- `data_wp_kses_style_attr_decodes_entities_before_css_filtering`

## What Is Removed

- The `explode( ';', $css )` declaration splitting and its `@todo`.
- `preg_match_all( '/url\([^)]+\)/' )` and the url-pieces regex.
- The gradient matching regex.
- The recursive function-stripping regex for `var|calc|min|max|minmax|clamp|repeat`.
- The character denylist check `%[\\\(&=}]|/\*%` and the `$css_test_string`
  munging that feeds it. Validation verdicts come from token walks instead.

## What Is Preserved

- `_deprecated_argument()` handling of the second parameter.
- Preprocessing: `wp_kses_no_null( $css )` followed by removal of `\n`, `\r`,
  and `\t` bytes. This is required byte-for-byte by existing tests (for
  example, `url(\n'…')` must serialize as `url('…' )`). The tokenizer therefore
  never sees those bytes.
- The `safe_style_css` filter over the allowed property list, including the
  `--*` custom property wildcard.
- The url-allowed property list (`background`, `background-image`, `cursor`,
  `filter`, `list-style`, `list-style-image`) and gradient-allowed property
  list (`background`, `background-image`).
- The early `return $css;` when the filtered allowed-property list is empty.
- The `safecss_filter_attr_allow_css` filter (see below).
- Output assembly: the trimmed authored source of each accepted declaration,
  joined with `;`, no trailing semicolon.

## Declaration Discovery

A single `WP_CSS_Token_Processor` pass over the preprocessed CSS splits it into
declarations at top-level `semicolon-token`s. Nesting depth is tracked by
counting `function-token`/`(-token`/`[-token`/`{-token` against
`)-token`/`]-token`/`}-token` (never below zero). Semicolons at depth greater
than zero do not split; semicolons inside strings, urls, and comments are
inherently part of those tokens and never seen as `semicolon-token`s.

Each declaration is represented by its byte range in the preprocessed source.
Empty or whitespace-only segments are skipped.

Divergence: a declaration left unclosed at EOF (for example
`width: calc(3em; height: 10px`) now swallows the remainder of the input, as
CSS specifies, instead of resurrecting later segments. The whole swallowed
declaration is then rejected by value validation.

## Property Handling

The property/value boundary is the first `colon-token` in the declaration
(matching the current `explode( ':', $css_item, 2 )` since a colon cannot
legally occur earlier). The property name is the trimmed raw source before it.

- Ordinary properties: exact, case-sensitive string match against the allowed
  list (`Text-transform` remains rejected).
- Custom properties: raw source must match `/^--[a-zA-Z0-9-_]+$/`; casing is
  preserved and semantic.
- Escaped property names (`\63 olor`) do not match and are rejected, since
  matching is on raw source.

A declaration whose property is neither allowed nor a valid custom property is
a **hard rejection**: it is dropped without consulting the
`safecss_filter_attr_allow_css` filter, exactly as today.

Declarations containing no colon at all keep today's behavior: they undergo
value validation as a bare value (passthrough functions allowed, url and
gradient constructs not allowed) and are kept if they validate, with the
filter consulted.

## Value Validation

The declaration's value token stream (everything after the first colon) is
walked once. Validation produces either a **hard rejection** (declaration
dropped, filter not consulted), or a boolean verdict `$allow_css` passed
through the `safecss_filter_attr_allow_css` filter.

### URL constructs

A url construct is either a `url-token` (`url(unquoted)`) or a
`function-token` named `url` (ASCII case-insensitive) containing optional
whitespace and exactly one `string-token` before its closing `)-token`.

Every url construct, at any nesting depth and for any property, has its
decoded value trimmed and checked:

- empty value, or `wp_kses_bad_protocol()` changing the value →
  **hard rejection** of the declaration. This closes the current nesting
  loophole where `width: calc(url(javascript:…))` escapes protocol checking.
- valid value → the construct is acceptable only where url constructs are
  allowed: values of url-allowed properties and custom properties, at the top
  level or nested inside allowed functions. A valid url construct on a
  non-url-allowed ordinary property is a soft rejection (`$allow_css = false`),
  preserving today's filter-overridable outcome for `color: url(…)`.
- structurally malformed url constructs (a `bad-url-token`, or a `url(`
  function that does not close with `)` after its string) are soft rejections.

CSS escapes are permitted inside url construct values; the protocol check runs
on the decoded value.

Divergence: url constructs are recognized ASCII case-insensitively (`URL(…)`),
as CSS specifies. The old lowercase-only regex rejected uppercase variants
outright; they are now accepted when the protocol check passes.

### Gradient functions

`function-token`s named `(repeating-)?(linear|radial|conic)-gradient`
(case-sensitive, as today) are allowed in values of gradient-allowed
properties and custom properties. Inside a gradient, in addition to ordinary
component tokens, one level of balanced group nesting is allowed: nested
`function-token`s of any name or bare `(-token` groups, which must close and
must not contain further parenthesis nesting. Url constructs inside gradients
follow the url rules above. A gradient violating these rules, or a gradient
function on a non-gradient property, is a soft rejection.

Divergence: string tokens containing parentheses inside gradients are now
allowed (strings are atomic tokens); the old regex rejected them.

### Passthrough functions

`function-token`s named exactly `var`, `calc`, `min`, `max`, `minmax`,
`clamp`, or `repeat` (case-sensitive, as today) are allowed in any value.
Their contents are permissive, matching today's balanced-anything stripping:
any tokens are accepted inside, at any depth, subject only to:

- the function (and every nested group) must close before the declaration
  ends; unclosed-at-EOF constructs are soft rejections;
- url constructs inside are still subject to the url rules above;
- `bad-string-token` and `bad-url-token` are soft rejections.

### Top-level token rules

Outside url constructs, gradients, and passthrough functions, the following
tokens are accepted: `whitespace`, `ident` (without escapes), `number`,
`percentage`, `dimension` (without escapes), `string` (without escapes, and
closed before end of input — with newlines removed during preprocessing, an
unclosed string reaches end of input as a regular string token rather than a
bad-string token, so closure is checked explicitly), `hash`, `comma`, `colon`,
`[-token`, `]-token`, `)-token` (stray closing
parens remain accepted — a pinned quirk), and `delim-token`s other than `\`,
`&`, and `=`. The delim `!` remains accepted, which keeps `!important`
working.

The following are soft rejections: `comment` tokens, `bad-string-token`,
`bad-url-token`, `{-token`, `}-token`, bare `(-token` groups,
`function-token`s not covered by the allowlists above, `at-keyword-token`,
`CDO-token`, `CDC-token`, and any ident/dimension/string token whose raw
source contains a backslash (escapes stay rejected outside url values, so
`margin-top: \2px` remains rejected and remains rescuable by the filter).

Divergences at this layer, all toward strictness: `{`, at-keywords, CDO/CDC,
comments nested inside formerly-stripped function spans, bad strings, and bad
urls were previously invisible to the char check in some positions and now
soft-reject.

## The safecss_filter_attr_allow_css Filter

The filter is retained. `$allow_css` is now the verdict of token-based
validation instead of the char-check regex. The second argument,
`$css_test_string`, becomes the declaration's trimmed authored source; the
previous value was the declaration with allowed function spans textually
removed, which no longer exists in this design. This is a documented change to
the filter's second argument.

Hard rejections (unknown property, invalid custom property name, empty or
bad-protocol url values) are dropped before the filter runs, exactly matching
today's `$found = false` paths. Everything else reaches the filter and can be
rescued (`margin-top: \2px`, `height: expression(…)`, `color: rgb(…)`,
`margin-bottom: 2px}` all remain rescuable).

## Output

Accepted declarations are emitted as their trimmed authored source bytes from
the preprocessed input (after null/`\n\r\t` removal), joined with `;`. No
normalization, escape decoding, or whitespace collapsing is applied to output.

## Implementation Shape

All logic lives in `kses.php`:

- `safecss_filter_attr()` keeps its signature and orchestrates preprocessing,
  splitting, per-declaration validation, the filter, and output assembly.
- Private helper functions (prefixed `_safecss_`, marked `@access private`)
  implement declaration splitting and value validation over token streams. The
  token stream for a declaration is materialized as a small array of token
  records (type, name/value where relevant, byte range) collected during the
  single tokenizer pass, so the value walk does not re-tokenize.

No changes to `WP_CSS_Token_Processor` are expected; if a gap is found (for
example, needing the raw source of a token), prefer using existing getters
(`get_token_start()`, `get_token_length()`, `get_token_value()`,
`get_unnormalized_token()`).

## Test Requirements

Existing suites pass unchanged. New cases to add to `data_safecss_filter_attr`
(and the filtered variant where noted):

- semicolon inside a quoted string no longer splits the declaration;
- semicolon inside `url()` no longer splits the declaration;
- nested bad-protocol url is rejected: `width: calc(url(javascript:alert(1)))`;
- nested valid url on a url-allowed property passes protocol checking;
- unclosed function swallows following declarations (whole tail rejected);
- `url( )` with only whitespace is rejected;
- quoted url function form `url( "…" )` with bad protocol is rejected and is
  not rescuable by the filter;
- a string left unclosed at the end of input is rejected;
- `{` in a value is rejected;
- comment in a value is rejected;
- filter receives the declaration source as `$css_test_string` and can rescue
  soft rejections but not hard rejections.
