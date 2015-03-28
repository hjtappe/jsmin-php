<?php

namespace JSMin;

/**
 * JSMin.php - modified PHP implementation of Douglas Crockford's JSMin.
 *
 * <code>
 * $minifiedJs = JSMin::minify($js);
 * </code>
 *
 * This is a modified port of JSMin.php which is a port of jsmin.c. Improvements:
 *
 * Change from character-wise processing to block-wise processing.
 * Use of built-in, optimized string parsers
 * This improves performance significantly.
 *
 * PHP 5 or higher is required.
 *
 * Permission is hereby granted to use this version of the library under the
 * same terms as jsmin.c, which has the following license:
 *
 * --
 * Copyright (c) 2002 Douglas Crockford  (www.crockford.com)
 *
 * Permission is hereby granted, free of charge, to any person obtaining a copy of
 * this software and associated documentation files (the "Software"), to deal in
 * the Software without restriction, including without limitation the rights to
 * use, copy, modify, merge, publish, distribute, sublicense, and/or sell copies
 * of the Software, and to permit persons to whom the Software is furnished to do
 * so, subject to the following conditions:
 *
 * The above copyright notice and this permission notice shall be included in all
 * copies or substantial portions of the Software.
 *
 * The Software shall be used for Good, not Evil.
 *
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
 * IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
 * FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
 * AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
 * LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
 * OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE
 * SOFTWARE.
 * --
 *
 */

/**
 *
 * @package JSMin
 * @author Ryan Grove <ryan@wonko.com> (PHP port)
 * @author Steve Clay <steve@mrclay.org> (modifications + cleanup)
 * @author Andrea Giammarchi <http://www.3site.eu> (spaceBeforeRegExp)
 * @author Hans-Jürgen Tappe for the eGroupware project
 * @copyright 2002 Douglas Crockford <douglas@crockford.com> (jsmin.c)
 * @copyright 2008 Ryan Grove <ryan@wonko.com> (PHP port)
 * @copyright 2014 Hans-Jürgen Tappe (performance-centric re-write)
 * @license http://opensource.org/licenses/mit-license.php MIT License
 *
 */
class JSMinPhp
{
	/**
	 * Input JS for this instance
	 *
	 * @var string
	 */
	private $input  = '';

	/**
	 * Output cache for this instance
	 *
	 * @var string
	 */
	private $output = '';

	/**
	 * Enable / disable debugging
	 *
	 * @var boolean
	 */
	public static $debug = false;

	/**
	 * Reg Exp Character Class: a-z0-9A-Z_$\
	 *
	 * @var string
	 */
	private $alphanumRegexp;

	/**
	 * Special marker for multiline comments to distinguish from regular expressions.
	 *
	 * @var boolean
	 */
	private $in_comment = false;

	/**
	 * Checks if the parser is currently handling a quote or regular expression
	 * which shall not be modified.
	 *
	 * @var boolean
	 */
	private $in_quote = false;

	/**
	 * Current Separator
	 *
	 * @var string
	 */
	private $curr_sep = null;

	/**
	 * Line Count
	 *
	 * @var integer
	 */
	private $lineCount = 0;

	/**
	 * Marker for cleanup after the last comment block
	 *
	 * @var boolean
	 */
	private $last_block_in_comment = false;

	/**
	 * Last block was in quote.
	 *
	 * @var boolean
	 */
	private $last_block_in_quote = false;

	/**
	 * Flag to indicate if to keep the current multiline comment or not.
	 *
	 * @var boolean
	 */
	private $keep_comment = false;

	/**
	 * Previous separator
	 *
	 * @var string
	 */
	private $prev_sep = null;

	/**
	 * Next Separator
	 *
	 * @var string
	 */
	private $next_sep = null;

	/**
	 * Last line returned by this function.
	 *
	 * @var string
	 */
	private $last_line_out = '';

	/**
	 * Last processed non-empty line
	 * @var string
	 */
	private $last_line = null;

	/**
	 * Last processed line was a comment
	 *
	 * @var boolean
	 */
	private $last_line_comment = false;

	/**
	 * Current line is in comment.
	 *
	 * @var boolean
	 */
	private $line_in_comment = false;

	/**
	 * Current line is an IE comment.
	 *
	 * @var boolean
	 */
	private $line_comment_ie = false;

	/**
	 * Flag to distinguish single line comments
	 *
	 * @var boolean
	 */
	private $line_single_comment;


	/**
	 * @param string $input
	 */
	public function __construct($input)
	{
		$this->input = $input;

	}
	/**
	 * Minify Javascript.
	 *
	 * @param string $js Javascript to be minified
	 *
	 * @return string
	 */
	public static function minify($js)
	{
		$jsmin = new JSMin($js);
		$out = $jsmin->min();

		return $out;
	}

	/**
	 * Initialize data arrays.
	 */
	protected function initialize()
	{
		if (isset($this->alphanumRegexp)) {
			return;
		}

		// Initialize re-usable character array
		$this->alphanumRegexp = '';

		// _
		$this->alphanumRegexp .= '_';
		// \
		$this->alphanumRegexp .= '\\';
		// $
		$this->alphanumRegexp .= '$';

		$this->alphanumRegexp = preg_quote($this->alphanumRegexp, '/');

		// 0-9
		$this->alphanumRegexp .= '0-9';
		// a-z
		$this->alphanumRegexp .= 'a-z';
		$this->alphanumRegexp .= 'A-Z';

		// self::debug('Regexp   is "'.$this->alphanumRegexp.'"');
	}

	/**
	 * Perform minification, return result
	 *
	 * @return string
	 */
	public function min()
	{
		$this->initialize();

		// Re-use cached values.
		if ($this->output !== '') {
			// min already run on this instance.
			return $this->output;
		}

		// Remove the utf-8 BOM to save transfer bytes.
		// Otherwise, line breaks before the 2nd comment are kept and
		// lots of zero bytes stay, leading to additional waste.
		$first2 = substr($this->input, 0, 2);
		$first3 = substr($this->input, 0, 3);
		$first4 = substr($this->input, 0, 4);
		$encoding = 'UTF-8';
		// Unicode BOM is U+FEFF, but after encoded, it will look like this.
		if ($first3 == chr(0xEF).chr(0xBB).chr(0xBF)) {
			$this->input = substr($this->input, 3);
		} elseif ($first4 == chr(0x00).chr(0x00).chr(0xFE).chr(0xFF)) {
			$encoding = 'UTF-32BE';
			$this->input = substr($this->input, 4);
		} elseif ($first4 == chr(0xFF).chr(0xFE).chr(0x00).chr(0x00)) {
			$encoding = 'UTF-32LE';
			$this->input = substr($this->input, 4);
		} elseif ($first2 == chr(0xFE).chr(0xFF)) {
			$encoding = 'UTF-16BE';
			$this->input = substr($this->input, 2);
		} elseif ($first2 == chr(0xFF).chr(0xFE)) {
			$encoding = 'UTF-16LE';
			$this->input = substr($this->input, 2);
		}
		// Convert only non-8-bit files.
		if ($encoding != 'UTF-8') {
			// self::debug('Converting to UTF-8 from '.$encoding);
			$this->input = mb_convert_encoding($this->input, 'UTF-8', $encoding);
		}

		$mbIntEnc = null;
		if (function_exists('mb_internal_encoding')) {
			$mbIntEnc = mb_internal_encoding();
			mb_internal_encoding('UTF-8');
		}

		// Replace windows line breaks / carriage returns (otherwise the escaped LF are not recognized)
		$this->input = str_replace("\r\n", "\n", $this->input);

		//@ replace all \r\n by \n as a preparation
		//@ Convert all characters not in ordinal range '\n' and ' ' to '~' to spaces.
		//@ Convert all \r to \n. (or to space, see below?)
		// Replace all remaining CR with a line break to be nice to bad editors.
		// Otherwise, the single-line comments are not terminated at single CR.
		$this->input = str_replace("\r", "\n", $this->input);

		// Replace all non-ASCII characters in the low range (0-127)
		$c = '';
		for ($i = 0; $i <= 127; $i++) {
			// accept non-control characters between space (incl) and DEL (excl) and LF
			if (($i < ord(' ') || $i > ord('~')) &&
					($i != ord("\n"))) {
				$c .= chr($i);
			}
		}
		$this->input = str_replace(str_split($c), ' ', $this->input);

		// trim all lines and handle whitespaces
		// TODO: Replace map with loops to propagate exceptions.
		$this->output = '';
		foreach (explode("\n", $this->input) as $line) {
			$this->output .= $this->process_line($line);
		}

		// Trim the overall JS.
		//@ The output shall be trimmed before returning it.
		$this->output = trim($this->output);

		// Reset the mb encoding
		if ($mbIntEnc !== null) {
			mb_internal_encoding($mbIntEnc);
		}

		// Check if the comments and quotes are finished.
		if ($this->in_quote) {
			if ($this->in_comment) {
				throw new JSMin_UnterminatedCommentException(
						"Line ".$this->quotestart.": Unbalanced Comment.");
			} elseif ($this->curr_sep == '/') {
				throw new JSMin_UnterminatedRegExpException(
						"Line ".$this->quotestart.": Unbalanced Regular Expression.");
			} else {
				throw new JSMin_UnterminatedStringException(
						"Line ".$this->quotestart.": Unbalanced Quote.");
			}
		}

		// Return the minified JS
		return $this->output;
	}

	/**
	 * Process each line
	 *
	 * @param string $line
	 */
	protected function process_line($line)
	{
		$single_line_comment = false;
		$in_regexp = 0;

		$this->lineCount++;

		// self::debug('IN '.$this->lineCount.': '.trim($line));

		// Split the lines into blocks which need to be analyzed at their borders.
		$la = preg_split('/(["\'\/])/', $line, -1, PREG_SPLIT_DELIM_CAPTURE);
		$out = '';
		foreach ($la as $k => &$block) {
			// self::debug('BLOCK '.$k.' IN: "'.$block.'"');
			if ($single_line_comment && !$this->keep_comment) {
				// Omit the rest
				unset($la[$k]);
				// self::debug('DROP '.$k.': "'.$block.'"');
				continue;
			}

			// Check for single-line comments - but not from quoted..
			if (($k % 2 != 0) && (!$this->in_quote)) {
				// Create a block with all double backslashes removed.
				$block_escaped = self::escape_block($la[$k - 1]);
				// self::debug('BLOCK '.($k - 1).' ESCAPED: "'.$block_escaped.'"');

				// Remove comments
				// |//      ''  "/" '' '/'
				// |[^\]//  '.' "/" '' '/'
				//@ IE Conditional comments start with '@' followed by cc_on|if|elif|else|end, followed by \b or @, case insensitive
				if (($k == 1 || ($k > 0 && substr($block_escaped, -1) != '\\')) &&
						$la[$k] == '/') {
					if (isset($la[$k + 2]) && $la[$k + 1] == '' && $la[$k + 2] == '/') {
						// Single-line comments.
						$single_line_comment = true;
						$this->in_comment = true;
						// self::debug('Found single-line comment.');
						if (isset($la[$k + 3]) &&
								preg_match('/^@(?:cc_on|if|elif|else|end)\\b/', $la[$k + 3])) {
							$this->keep_comment = true;
							$this->in_quote = true;
							$this->curr_sep = '//';
						} else {
							$this->keep_comment = false;
							// Set a marker so all the remaining line will be dropped.
						}
					} else if (isset($la[$k + 1]) && substr($la[$k + 1], 0, 1) == '*') {
						// Multi-line comments
						$this->in_quote = true;
						$this->in_comment = true;
						// self::debug('MULTILINE COMMENT START: "'.$la[$k + 1].'"');
						if (preg_match('/^\*{1,2}(?:!|@(?:cc_on|if|elif|else|end)\\b)/', $la[$k + 1])) {
							//@ IE Conditional comments start with '@' followed by cc_on|if|elif|else|end, followed by \b or @, case insensitive
							// self::debug('KEEP. MATCH.');
							$this->keep_comment = true;
						} else {
							// self::debug('DONT KEEP. NO MATCH.');
							$this->keep_comment = false;
						}
						$this->curr_sep = $la[$k];
					} elseif (!$this->last_block_in_comment && !$this->last_block_in_quote) {
						// regular expressions
						// self::debug('line='.$this->lineCount.' k='.$k.' ps='.(is_null($this->prev_sep)?'NULL':$this->prev_sep));
						// self::debug('$la[$k - 1]         : "'.$la[$k - 1].'"');
						// self::debug('$this->last_line_out: "'.$this->last_line_out.'"');
						if (// Check if the file starts with a regular expression.
								($k == 1 && trim($la[$k - 1]) == '' &&
										(($this->lineCount == 1 && $this->prev_sep == NULL) ||
												$this->checkRegexpStart($this->last_line_out))
								) ||
								$this->checkRegexpStart($la[$k - 1])) {
							// self::debug('  Regexp start.');
							$this->in_quote = true;
							$this->curr_sep = $la[$k];
							$in_regexp = 1;
						}
					}
				}
			}

			// Take the separators as-is.
			if ($k % 2 == 0) {
				if ($k > 0) {
					$this->prev_sep = $la[$k - 1];
				} else {
					$this->prev_sep = null;
				}
				if (isset($la[$k + 1])) {
					$this->next_sep = $la[$k + 1];
				} else {
					$this->next_sep = null;
				}

				// self::debug('Quote '.$k.': '.($this->in_quote?'quote'.' with '.$this->curr_sep:'none'));
				// Do the whitespace work in the remaining code.
				if (!$this->in_quote) {
					$block = $this->handle_whitespace($block, $this->prev_sep, $this->next_sep);
					// self::debug('BLOCK '.$k.' OUT : "'.$block.'"');
				} else {
					// self::debug('block '.$k.' orig: "'.$block.'"');
				}
			}

// 			if (self::$debug) {
// 				$msg = 'in_comment='.($this->in_comment ? 'true' : 'false').
// 						', keep_comment='.($this->keep_comment ? 'true' : 'false').
// 						', last_block_in_comment='.($this->last_block_in_comment ? 'true' : 'false').
// 						', last_block_in_quote='.($this->last_block_in_quote ? 'true' : 'false').
// 						', in_quote='.($this->in_quote ? 'true' : 'false').
// 						', single_line_comment='.($single_line_comment ? 'true' : 'false');
				// self::debug($msg);
// 			}
			// Handle the removal of multiline comments.
			if (!$this->in_comment || $this->keep_comment) {
				if ($this->keep_comment && !$this->last_block_in_comment) {
					if ($this->in_comment && ($k > 2 || ($k == 1 && $la[0] != ''))) {
						// Force a line break before comments - if it is not the start of line anyway.
						$out .= "\n";
						// self::debug('ADD LF before');
					} else {
						// self::debug('No LF before '.$k);
					}
				}
				$out .= $block;
				if ($this->keep_comment) {
					if (!$this->last_block_in_comment) {
						$this->last_block_in_comment = true;
					}
				} elseif ($this->last_block_in_comment) {
					$out .= "\n";
					// self::debug('ADD LF after');
					$this->last_block_in_comment = false;
				}
			} else {
				// self::debug('BLOCK '.$k.' DROP: '.$block);
			}

			// Remember the quoting state
			$this->last_block_in_quote = $this->in_quote;

			if ($k % 2 == 0) {
				// Handle regular expression calculations
				if ($in_regexp != 0) {
					// '[/] is a valid expression. The / is not escaped like in other languages...
					$fragments = preg_split('/([\[\]])/', $block, -1, PREG_SPLIT_DELIM_CAPTURE);
					for ($f = 0; $f < count($fragments); $f++) {
						// self::debug('REX FRAG: "'.$fragments[$f].'"');
						if ($f % 2 != 0) {
							$previous_escaped = self::escape_block($fragments[$f - 1]);
							$previous_escaped = (substr($previous_escaped, -1) == '\\');
							// Set the character class increment only if not escaped or not inside a character class
							if ($in_regexp == 1 && !$previous_escaped && $fragments[$f] == '[') {
								$in_regexp ++;
								// self::debug('REX FRAG INC ('.$in_regexp.')');
							}
							if ($in_regexp == 2 && !$previous_escaped && $fragments[$f] == ']') {
								$in_regexp --;
								// self::debug('REX FRAG DEC ('.$in_regexp.')');
							}
						}
					}
					// self::debug('REX COUNT: '.$in_regexp.' after rex parsing');
					if ($in_regexp == 1) {
						$in_regexp = 0;
					} else {
						// self::debug('QUOTE '.$k.' REGEX CONTINUED: '.$this->curr_sep);
					}
				}

				// Toggle if no escaped delimiters are found
				$block_escaped = self::escape_block($block);
				if (!$single_line_comment &&
						!is_null($this->next_sep) && (substr($block_escaped, -1) != '\\')) {
					if (!$this->in_quote) {
						if (($this->next_sep != '/') && !$this->in_comment) {
							$this->in_quote = true;
							$this->curr_sep = $this->next_sep;
							// self::debug('QUOTE START: '.$this->curr_sep);
						}
					} elseif ($this->next_sep == $this->curr_sep) {
						if ($this->in_comment) {
							if (substr($block, -1) == '*') {
								if (!$this->keep_comment) {
									// delete the upcoming delimiter
									$la[$k+1] = '';
									$block = '';
								}
								// self::debug('Multiline Comment end: '.$this->curr_sep);
								$this->in_quote = false;
								$this->in_comment = false;
								$this->keep_comment = false;
								$this->curr_sep = null;
							}
						} elseif ($in_regexp == 0) {
							// self::debug('QUOTE '.$k.' END: '.$this->curr_sep);
							$this->in_quote = false;
							$this->curr_sep = null;
						}
					}
				}
			}
		}

		// reset the global flag for single line comments
		if ($single_line_comment) {
			$this->in_comment = false;
			$this->keep_comment = false;
			$this->in_quote = false;
			$this->curr_sep = null;
		}

		// Trim white spaces from line
		if ($this->in_quote) {
			if (!$this->in_comment && substr($out, -1) != '\\') {
				throw new JSMin_UnterminatedStringException(
						"Line ".$this->lineCount.": Unfinished unescaped multi-line quoted string: ".$out);
			}
		} else {
			$out = rtrim($out);
		}
		// at least, convert tab to spaces
		$out = str_replace("\t", ' ', $out);

		// self::debug('OUT: "'.$out.'"');

		// Take care of added line feeds and call the respective handler.
		$lines = '';
		foreach (explode("\n", $out) as $line) {
			$lines .= $this->handle_linefeed($line);
		}

		// Remember the last line returned
		$o = trim($lines);
		if (!empty($o)) {
			$this->last_line_out = $lines;
		}

		return $lines;
	}

	/**
	 * Omit line feeds wherever possible. Only add / keep where necessary.
	 *
	 * @param string $line
	 */
	private function handle_linefeed($line)
	{
		$out = '';

		// self::debug('LF IN: "'.$line.'"');
		// This function needs it's own comment handling.
		// Through the blocks, new comment lines might be introduced.
		// $this->in_comment is only true for multiline comments.
		if (!$this->line_in_comment && preg_match('/(?:(^\/\/)|^\/\*)(.?)/', $line, $m)) {
			$this->line_in_comment = true;
			if (isset($m[1]) && $m[1] != '') {
				$this->line_single_comment = true;
				// self::debug('Found Single Line Comment for linefeeds.');
			}
			if (isset($m[2]) && $m[2] == '@') {
				$this->line_comment_ie = true;
				// self::debug('Detected IE Comment.');
			} else {
				$this->line_comment_ie = false;
			}
		}
// 		if (self::$debug) {
// 			$msg = 'LF IN / line_comment_ie: '.($this->line_comment_ie?'true':'false').
// 			', line_in_comment: '.($this->line_in_comment?'true':'false').
// 			', last_line_comment: '.($this->last_line_comment?'true':'false').
// 			', single_line_comment: '.($this->line_single_comment?'true':'false');
			// self::debug($msg);
// 		}

		if (!is_null($this->last_line)) {
			if (!is_null($this->last_line)) {
				$last_char = substr($this->last_line, -1);
			} else {
				$last_char = '';
			}
			$next_char = substr($line, 0, 1);
			if (preg_match('/^['.preg_quote('![{(\'"').$this->alphanumRegexp.']$/', $next_char) &&
					preg_match('/^['.preg_quote('})]\'"').$this->alphanumRegexp.']$/', $last_char)) {
				//@ LF shall be kept before NULL or any character not {[(+-!~ or alphanumeric.
				// self::debug("New block");
				$out .= "\n";
			} elseif ($this->line_in_comment) {
				// self::debug("Comment start or continued");
				if (($this->line_comment_ie && !$this->last_line_comment)) {
					// self::debug('Omitting line break at start');
				} else {
					$out .= "\n";
				}
			} elseif ($this->last_line_comment) {
				// self::debug('Last line: "'.$this->last_line.'" ('.substr($this->last_line, -3).')');
				if ($this->line_comment_ie && !$this->line_single_comment) {
					// self::debug('Omitting line break at end');
					$this->line_comment_ie = false;
				} elseif ((! empty($line)) && (substr($this->last_line, -3) != '@*/')) {
					// self::debug("LF before last line of comment");
					$out .= "\n";
				} else {
					// self::debug('LF Omitted at end of IE comment.');
				}
			} elseif (($next_char == '+' || $next_char == '-') &&
					preg_match('/^['.preg_quote('+-})]\'"').$this->alphanumRegexp.']$/', $last_char)) {
				//@ LF shall be kept before NULL or any character not {[(+-!~ or alphanumeric.
				// self::debug("Avoid a+++b from a+\n++b (1)");
				$out .= "\n";
			} elseif (preg_match('/^['.preg_quote('+-{([').$this->alphanumRegexp.']$/', $next_char) &&
					($last_char == '+' || $last_char == '-')) {
				// self::debug("Avoid a+++b from a+\n++b (2)");
				$out .= "\n";
			} elseif ($last_char == '\\') {
				// self::debug("Forced line break");
				$out .= "\n";
			}
			//@ LF shall be removed before whitespace
		}

		if ($line != '') {
			$this->last_line = $line;

			$out .= $line;

			if ($this->line_in_comment && $this->line_single_comment) {
				$out .= "\n";
				// self::debug("Single Line Comment LB");
				$this->line_single_comment = false;
				$this->line_in_comment = false;
			}
			$this->last_line_comment = $this->line_in_comment;
			if ($this->line_in_comment && preg_match('/(?:^|[^\\\\])\*\/$/', $line)) {
				// self::debug('End of line in comment.');
				$this->line_in_comment = false;
			}
		}
// 		if (self::$debug) {
// 			$msg = 'LF OUT / line_comment_ie: '.($this->line_comment_ie?'true':'false').
// 					', line_in_comment: '.($this->line_in_comment?'true':'false').
// 					', last_line_comment: '.($this->last_line_comment?'true':'false').
// 					', single_line_comment: '.($this->line_single_comment?'true':'false');
			// self::debug($msg);
// 		}

		// self::debug('LF OUT: "'.$out.'"');

		return $out;
	}

	/**
	 * Try to find out if this is the block before a regexp, not a devision or comment.
	 *
	 * @param string $in
	 */
	private function checkRegexpStart($in)
	{
		// Try to find out if this is the block before a regexp, not a devision or comment.
		$regexp = false;

		if (preg_match('/['.preg_quote('(,=:\[!&|?+-~*{;').']/', substr($in, -1))) {
			// Not a Division at the next split. Is a regular expression.
			$regexp = true;
			// self::debug('    Found regexp start: '.substr($in, -1).' ('.$in.')');
		} elseif (preg_match('/(.*)(?:case|else|in|return|typeof)$/', $in, $m)) {
			if (! isset($m[1]) || preg_match('/(?:^|[^'.$this->alphanumRegexp.'])$/', $m[1])) {
				// Not in a Division at the next split. Is a regular expression.
				$regexp = true;
				// self::debug('    Found regexp start block: '.$in);
			}
		}
		// self::debug('    REX Start: '.($regexp?'true':'false').' ('.$in.')');

		return $regexp;
	}

	/**
	 * Handle whitespaces
	 *
	 * @param string $s
	 * @param string $prev_sep
	 * @param string $next_sep
	 * @return string
	 */
	private function handle_whitespace($s, $prev_sep, $next_sep)
	{
		// self::debug('WS REX: /[\t ]+([^'.$this->alphanumRegexp.'])/');
		// self::debug('WS  IN: "'.$s.'"');
		// Replace double white space
		$s = preg_replace("/[\t ]+/", " ", $s);
		// self::debug('WS   1: "'.$s.'"');

		//@ Spaces shall be deleted if the next character is not in the range of alphanumeric or pre/ post increment
		//@ The expressions "a + ++b" etc. shall not be compressed to "a+++b",
		$a = preg_split('/([+-]+[ ]+[+-]+)/', $s, -1, PREG_SPLIT_DELIM_CAPTURE);
		for ($k = 0; $k < count($a) ;$k++) {
			// self::debug('WS    : "'.$a[$k].'" (a['.$k.'])');
			// Leave the concatenations of ++ and --, for all others...
			if ($k % 2 == 0 || !preg_match('/(\+ *\+ *\+|- *- *-)/', $a[$k])) {
				// Omit whitespace around certain characters
				$a[$k] = preg_replace('/[\t ]+([^'.$this->alphanumRegexp.'])/', '$1', $a[$k]);
				// self::debug('WS   2: "'.$a[$k].'"');
				$a[$k] = preg_replace('/([^'.$this->alphanumRegexp.'])[\t ]+/', '$1', $a[$k]);
				// self::debug('WS   3: "'.$a[$k].'"');
			}
			$a[$k] = trim($a[$k]);
		}
		$s = join('', $a);

		// Delete spaces before and after the quoted strings.
		// self::debug('PSEP: '.(isset($prev_sep)?$prev_sep:'null').' NSEP: '.(isset($next_sep)?$next_sep:'null'));
		if (!isset($prev_sep) || !isset($next_sep) ||
				!(($prev_sep == $next_sep) && ($prev_sep == '/') && ($s == ' '))) {
			// self::debug('TRIM from: "'.$s.'"');
			$s = trim($s);
			// self::debug('TRIM to  : "'.$s.'"');
		}
		// self::debug('WS OUT: "'.$s.'"');

		return $s;
	}

	/**
	 * Print a debug message if debug is enabled.
	 * @param unknown_type $msg
	 */
	private static function debug($msg)
	{
		if (self::$debug) {
			echo $msg."\n";
		}
	}

	/**
	 * Escape all escaped backslashes, leaving only unescaped ones
	 *
	 * @param string $in
	 */
	private static function escape_block($in)
	{
		return str_replace('\\\\', '_', $in);
	}
}
