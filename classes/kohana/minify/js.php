<?php defined('SYSPATH') or die('No direct script access.');
/**
 * Kohana_Minify_JS.php - modified PHP implementation of Douglas Crockford's Kohana_Minify_JS.
 *
 * <code>
 * $minifiedJs = Kohana_Minify_JS::minify($js);
 * </code>
 *
 * This is a modified port of jsmin.c. Improvements:
 * 
 * Does not choke on some regexp literals containing quote characters. E.g. /'/
 * 
 * Spaces are preserved after some add/sub operators, so they are not mistakenly 
 * converted to post-inc/dec. E.g. a + ++b -> a+ ++b
 *
 * Preserves multi-line comments that begin with /*!
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
 * @package Kohana_Minify_JS
 * @author Ryan Grove <ryan@wonko.com> (PHP port)
 * @author Steve Clay <steve@mrclay.org> (modifications + cleanup)
 * @author Andrea Giammarchi <http://www.3site.eu> (spaceBeforeRegExp)
 * @copyright 2002 Douglas Crockford <douglas@crockford.com> (jsmin.c)
 * @copyright 2008 Ryan Grove <ryan@wonko.com> (PHP port)
 * @license http://opensource.org/licenses/mit-license.php MIT License
 * @link http://code.google.com/p/jsmin-php/
 */

class Kohana_Minify_JS {
	const ORD_LF             = 10;
	const ORD_SPACE          = 32;
	const ACTION_KEEP_A      = 1;
	const ACTION_DELETE_A    = 2;
	const ACTION_DELETE_A_B  = 3;

	protected $a             = "\n";
	protected $b             = '';
	protected $input         = '';
	protected $input_index   = 0;
	protected $input_length  = 0;
	protected $look_ahead    = NULL;
	protected $output        = '';
	protected $last_byte_out = '';

	/**
	 * Minify Javascript.
	 *
	 * @param string $js Javascript to be minified
	 *
	 * @return string
	 */
	public static function minify($js)
	{
		$jsmin = new Minify_JS($js);
		return $jsmin->min();
	}

	/**
	 * @param string $input
	 */
	public function __construct($input)
	{
		$this->input = $input;
	}

	/**
	 * Perform minification, return result
	 *
	 * @return string
	 */
	public function min()
	{
		if ($this->output !== '') // min already run
		{
			return $this->output;
		}

		$mb_int_enc = NULL;
		if (function_exists('mb_strlen') AND ((int)ini_get('mbstring.func_overload') & 2))
		{
			$mb_int_enc = mb_internal_encoding();
			mb_internal_encoding('8bit');
		}
		$this->input = str_replace("\r\n", "\n", $this->input);
		$this->input_length = strlen($this->input);

		$this->action(self::ACTION_DELETE_A_B);

		while ($this->a !== NULL)
		{
			// determine next command
			$command = self::ACTION_KEEP_A; // default
			if ($this->a === ' ')
			{
				if (($this->last_byte_out === '+' OR $this->last_byte_out === '-') 
					AND ($this->b === $this->last_byte_out))
				{
					// Don't delete this space. If we do, the addition/subtraction
					// could be parsed as a post-increment
				}
				else if (! $this->is_alpha_num($this->b))
				{
					$command = self::ACTION_DELETE_A;
				}
			}
			else if ($this->a === "\n")
			{
				if ($this->b === ' ')
				{
					$command = self::ACTION_DELETE_A_B;
				// in case of mbstring.func_overload & 2, must check for NULL b,
				// otherwise mb_strpos will give WARNING
				}
				else if ($this->b === NULL OR (FALSE === strpos('{[(+-', $this->b)
						AND ! $this->is_alpha_num($this->b)))
				{
					$command = self::ACTION_DELETE_A;
				}
			}
			else if (! $this->is_alpha_num($this->a))
			{
				if ($this->b === ' ' OR ($this->b === "\n" 
					AND (FALSE === strpos('}])+-"\'', $this->a))))
				{
					$command = self::ACTION_DELETE_A_B;
				}
			}
			$this->action($command);
		}
		$this->output = trim($this->output);

		if ($mb_int_enc !== NULL)
		{
			mb_internal_encoding($mb_int_enc);
		}
		return $this->output;
	}

	/**
	 * ACTION_KEEP_A = Output A. Copy B to A. Get the next B.
	 * ACTION_DELETE_A = Copy B to A. Get the next B.
	 * ACTION_DELETE_A_B = Get the next B.
	 *
	 * @param int $command
	 * @throws Minify_JS_Exception|Minify_JS_Exception
	 */
	protected function action($command)
	{
		if ($command === self::ACTION_DELETE_A_B AND $this->b === ' '
			AND ($this->a === '+' OR $this->a === '-'))
		{
			// Note: we're at an addition/substraction operator; the input_index
			// will certainly be a valid index
			if ($this->input[$this->input_index] === $this->a)
			{
				// This is "+ +" or "- -". Don't delete the space.
				$command = self::ACTION_KEEP_A;
			}
		}
		switch ($command)
		{
			case self::ACTION_KEEP_A:
				$this->output .= $this->a;
				$this->last_byte_out = $this->a;
				
				// fallthrough
			case self::ACTION_DELETE_A:
				$this->a = $this->b;
				if ($this->a === "'" OR $this->a === '"') // string literal
				{
					$str = $this->a; // in case needed for exception
					while (TRUE)
					{
						$this->output .= $this->a;
						$this->last_byte_out = $this->a;
						
						$this->a	   = $this->get();
						if ($this->a === $this->b) // end quote
						{
							break;
						}
						if (ord($this->a) <= self::ORD_LF)
						{
							throw new Minify_JS_Exception(
								"Kohana_Minify_JS: Unterminated String at byte "
								. $this->input_index . ": {$str}");
						}
						$str .= $this->a;
						if ($this->a === '\\')
						{
							$this->output .= $this->a;
							$this->last_byte_out = $this->a;
							
							$this->a	   = $this->get();
							$str .= $this->a;
						}
					}
				}
				// fallthrough
			case self::ACTION_DELETE_A_B:
				$this->b = $this->next();
				if ($this->b === '/' AND $this->is_regexp_literal()) { // RegExp literal
					$this->output .= $this->a . $this->b;
					$pattern = '/'; // in case needed for exception
					while (TRUE)
					{
						$this->a = $this->get();
						$pattern .= $this->a;
						if ($this->a === '/') // end pattern
						{
							break; // while (TRUE)
						}
						else if ($this->a === '\\')
						{
							$this->output .= $this->a;
							$this->a	   = $this->get();
							$pattern	  .= $this->a;
						}
						else if (ord($this->a) <= self::ORD_LF)
						{
							throw new Minify_JS_Exception(
								"Kohana_Minify_JS: Unterminated RegExp at byte "
								. $this->input_index .": {$pattern}");
						}
						$this->output .= $this->a;
						$this->last_byte_out = $this->a;
					}
					$this->b = $this->next();
				}
			// end case ACTION_DELETE_A_B
		}
	}

	/**
	 * @return bool
	 */
	protected function is_regexp_literal()
	{
		if (FALSE !== strpos("\n{;(,=:[!&|?", $this->a)) // we aren't dividing
		{
			return TRUE;
		}
		if (' ' === $this->a)
		{
			$length = strlen($this->output);
			if ($length < 2) // weird edge case
			{
				return TRUE;
			}
			// you can't divide a keyword
			if (preg_match('/(?:case|else|in|return|typeof)$/', $this->output, $m))
			{
				if ($this->output === $m[0]) // odd but could happen
				{
					return TRUE;
				}
				// make sure it's a keyword, not end of an identifier
				$char_before_keyword = substr($this->output, $length - strlen($m[0]) - 1, 1);
				if (! $this->is_alpha_num($char_before_keyword))
				{
					return TRUE;
				}
			}
		}
		return FALSE;
	}

	/**
	 * Get next char. Convert ctrl char to space.
	 *
	 * @return string
	 */
	protected function get()
	{
		$c = $this->look_ahead;
		$this->look_ahead = NULL;
		if ($c === NULL)
		{
			if ($this->input_index < $this->input_length)
			{
				$c = $this->input[$this->input_index];
				$this->input_index += 1;
			}
			else
			{
				return NULL;
			}
		}
		if ($c === "\r" OR $c === "\n")
		{
			return "\n";
		}
		if (ord($c) < self::ORD_SPACE) // control char
		{
			return ' ';
		}
		return $c;
	}

	/**
	 * Get next char. If is ctrl character, translate to a space or newline.
	 *
	 * @return string
	 */
	protected function peek()
	{
		$this->look_ahead = $this->get();
		return $this->look_ahead;
	}

	/**
	 * Is $c a letter, digit, underscore, dollar sign, escape, or non-ASCII?
	 *
	 * @param string $c
	 *
	 * @return bool
	 */
	protected function is_alpha_num($c)
	{
		return (preg_match('/^[0-9a-zA-Z_\\$\\\\]$/', $c) OR ord($c) > 126);
	}

	/**
	 * @return string
	 */
	protected function single_line_comment()
	{
		$comment = '';
		while (TRUE)
		{
			$get = $this->get();
			$comment .= $get;
			if (ord($get) <= self::ORD_LF) // EOL reached
			{
				// if IE conditional comment
				if (preg_match('/^\\/@(?:cc_on|if|elif|else|end)\\b/', $comment))
				{
					return "/{$comment}";
				}
				return $get;
			}
		}
	}

	/**
	 * @return string
	 * @throws Minify_JS_Exception
	 */
	protected function multiple_line_comment()
	{
		$this->get();
		$comment = '';
		while (TRUE)
		{
			$get = $this->get();
			if ($get === '*')
			{
				if ($this->peek() === '/') // end of comment reached
				{
					$this->get();
					// if comment preserved by YUI Compressor
					if (0 === strpos($comment, '!'))
					{
						return "\n/*!" . substr($comment, 1) . "*/\n";
					}
					// if IE conditional comment
					if (preg_match('/^@(?:cc_on|if|elif|else|end)\\b/', $comment))
					{
						return "/*{$comment}*/";
					}
					return ' ';
				}
			}
			elseif ($get === NULL)
			{
				throw new Minify_JS_Exception(
					"Kohana_Minify_JS: Unterminated comment at byte "
					. $this->input_index . ": /*{$comment}");
			}
			$comment .= $get;
		}
	}

	/**
	 * Get the next character, skipping over comments.
	 * Some comments may be preserved.
	 *
	 * @return string
	 */
	protected function next()
	{
		$get = $this->get();
		if ($get !== '/')
		{
			return $get;
		}
		switch ($this->peek())
		{
			case '/': return $this->single_line_comment();
			case '*': return $this->multiple_line_comment();
			default: return $get;
		}
	}
} // End Kohana_Minify_JS
