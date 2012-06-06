<?php defined('SYSPATH') or die('No direct script access.');

class Kohana_Minify_JS_Plus_Tokenizer
{
	private $cursor       = 0;
	private $source;

	public $tokens        = array();
	public $token_index   = 0;
	public $look_ahead    = 0;
	public $scan_newlines = FALSE;
	public $scan_operand  = TRUE;

	public $filename;
	public $lineno;

	private $keywords = array(
		'break',
		'case', 'catch', 'const', 'continue',
		'debugger', 'default', 'delete', 'do',
		'else', 'enum',
		'false', 'finally', 'for', 'function',
		'if', 'in', 'instanceof',
		'new', 'null',
		'return',
		'switch',
		'this', 'throw', 'true', 'try', 'typeof',
		'var', 'void',
		'while', 'with'
	);

	private $op_type_names = array(
		';', ',', '?', ':', '||', '&&', '|', '^',
		'&', '===', '==', '=', '!==', '!=', '<<', '<=',
		'<', '>>>', '>>', '>=', '>', '++', '--', '+',
		'-', '*', '/', '%', '!', '~', '.', '[',
		']', '{', '}', '(', ')', '@*/'
	);

	private $assign_ops = array('|', '^', '&', '<<', '>>', '>>>', '+', '-', '*', '/', '%');
	private $op_reg_exp;

	public function __construct()
	{
		$this->op_reg_exp = '#^(' . implode('|', array_map('preg_quote', $this->op_type_names)) . ')#';
	}

	public function init($source, $filename = '', $lineno = 1)
	{
		$this->source        = $source;
		$this->filename      = $filename ? $filename : '[inline]';
		$this->lineno        = $lineno;

		$this->cursor        = 0;
		$this->tokens        = array();
		$this->token_index   = 0;
		$this->look_ahead    = 0;
		$this->scan_newlines = FALSE;
		$this->scan_operand  = TRUE;
	}

	public function get_input($chunksize)
	{
		if ($chunksize)
			return substr($this->source, $this->cursor, $chunksize);

		return substr($this->source, $this->cursor);
	}

	public function is_done()
	{
		return $this->peek() == Minify_JS_Plus::TOKEN_END;
	}

	public function match($tt)
	{
		return $this->get() == $tt || $this->unget();
	}

	public function must_match($tt)
	{
		if (!$this->match($tt))
			throw $this->new_syntax_error('Unexpected token; token ' . $tt . ' expected');

		return $this->current_token();
	}

	public function peek()
	{
		if ($this->look_ahead)
		{
			$next = $this->tokens[($this->token_index + $this->look_ahead) & 3];
			if ($this->scan_newlines AND $next->lineno != $this->lineno)
				$tt = Minify_JS_Plus::TOKEN_NEWLINE;
			else
				$tt = $next->type;
		}
		else
		{
			$tt = $this->get();
			$this->unget();
		}

		return $tt;
	}

	public function peek_on_same_line()
	{
		$this->scan_newlines = TRUE;
		$tt = $this->peek();
		$this->scan_newlines = FALSE;

		return $tt;
	}

	public function current_token()
	{
		if (!empty($this->tokens))
			return $this->tokens[$this->token_index];
	}

	public function get($chunksize = 1000)
	{
		while($this->look_ahead)
		{
			$this->look_ahead--;
			$this->token_index = ($this->token_index + 1) & 3;
			$token = $this->tokens[$this->token_index];
			if ($token->type != Minify_JS_Plus::TOKEN_NEWLINE OR $this->scan_newlines)
				return $token->type;
		}

		$conditional_comment = FALSE;

		// strip whitespace and comments
		while(TRUE)
		{
			$input = $this->get_input($chunksize);

			// whitespace handling; gobble up \r as well (effectively we don't have support for MAC newlines!)
			$re = $this->scan_newlines ? '/^[ \r\t]+/' : '/^\s+/';
			if (preg_match($re, $input, $match))
			{
				$spaces = $match[0];
				$spacelen = strlen($spaces);
				$this->cursor += $spacelen;
				if (!$this->scan_newlines)
					$this->lineno += substr_count($spaces, "\n");

				if ($spacelen == $chunksize)
					continue; // complete chunk contained whitespace

				$input = $this->get_input($chunksize);
				if ($input == '' || $input[0] != '/')
					break;
			}

			// Comments
			if (!preg_match('/^\/(?:\*(@(?:cc_on|if|elif|else|end))?.*?\*\/|\/[^\n]*)/s', $input, $match))
			{
				if (!$chunksize)
					break;

				// retry with a full chunk fetch; this also prevents breakage of long regular expressions (which will never match a comment)
				$chunksize = NULL;
				continue;
			}

			// check if this is a conditional (JScript) comment
			if (!empty($match[1]))
			{
				$match[0] = '/*' . $match[1];
				$conditional_comment = TRUE;
				break;
			}
			else
			{
				$this->cursor += strlen($match[0]);
				$this->lineno += substr_count($match[0], "\n");
			}
		}

		if ($input == '')
		{
			$tt = Minify_JS_Plus::TOKEN_END;
			$match = array('');
		}
		else if ($conditional_comment)
		{
			$tt = Minify_JS_Plus::TOKEN_CONDCOMMENT_START;
		}
		else
		{
			switch ($input[0])
			{
				case '0':
					// hexadecimal
					if (($input[1] == 'x' OR $input[1] == 'X') AND preg_match('/^0x[0-9a-f]+/i', $input, $match))
					{
						$tt = Minify_JS_Plus::TOKEN_NUMBER;
						break;
					}
				// FALL THROUGH

				case '1': case '2': case '3': case '4': case '5':
				case '6': case '7': case '8': case '9':
					// should always match
					preg_match('/^\d+(?:\.\d*)?(?:[eE][-+]?\d+)?/', $input, $match);
					$tt = Minify_JS_Plus::TOKEN_NUMBER;
				break;

				case "'":
					if (preg_match('/^\'(?:[^\\\\\'\r\n]++|\\\\(?:.|\r?\n))*\'/', $input, $match))
					{
						$tt = Minify_JS_Plus::TOKEN_STRING;
					}
					else
					{
						if ($chunksize)
							return $this->get(NULL); // retry with a full chunk fetch

						throw $this->new_syntax_error('Unterminated string literal');
					}
				break;

				case '"':
					if (preg_match('/^"(?:[^\\\\"\r\n]++|\\\\(?:.|\r?\n))*"/', $input, $match))
					{
						$tt = Minify_JS_Plus::TOKEN_STRING;
					}
					else
					{
						if ($chunksize)
							return $this->get(NULL); // retry with a full chunk fetch

						throw $this->new_syntax_error('Unterminated string literal');
					}
				break;

				case '/':
					if ($this->scan_operand AND preg_match('/^\/((?:\\\\.|\[(?:\\\\.|[^\]])*\]|[^\/])+)\/([gimy]*)/', $input, $match))
					{
						$tt = Minify_JS_Plus::TOKEN_REGEXP;
						break;
					}
				// FALL THROUGH

				case '|':
				case '^':
				case '&':
				case '<':
				case '>':
				case '+':
				case '-':
				case '*':
				case '%':
				case '=':
				case '!':
					// should always match
					preg_match($this->op_reg_exp, $input, $match);
					$op = $match[0];
					if (in_array($op, $this->assign_ops) AND $input[strlen($op)] == '=')
					{
						$tt = Minify_JS_Plus::OP_ASSIGN;
						$match[0] .= '=';
					}
					else
					{
						$tt = $op;
						if ($this->scan_operand)
						{
							if ($op == Minify_JS_Plus::OP_PLUS)
								$tt = Minify_JS_Plus::OP_UNARY_PLUS;
							else if ($op == Minify_JS_Plus::OP_MINUS)
								$tt = Minify_JS_Plus::OP_UNARY_MINUS;
						}
						$op = NULL;
					}
				break;

				case '.':
					if (preg_match('/^\.\d+(?:[eE][-+]?\d+)?/', $input, $match))
					{
						$tt = Minify_JS_Plus::TOKEN_NUMBER;
						break;
					}
				// FALL THROUGH

				case ';':
				case ',':
				case '?':
				case ':':
				case '~':
				case '[':
				case ']':
				case '{':
				case '}':
				case '(':
				case ')':
					// these are all single
					$match = array($input[0]);
					$tt = $input[0];
				break;

				case '@':
					// check end of conditional comment
					if (substr($input, 0, 3) == '@*/')
					{
						$match = array('@*/');
						$tt = Minify_JS_Plus::TOKEN_CONDCOMMENT_END;
					}
					else
						throw $this->new_syntax_error('Illegal token');
				break;

				case "\n":
					if ($this->scan_newlines)
					{
						$match = array("\n");
						$tt = Minify_JS_Plus::TOKEN_NEWLINE;
					}
					else
						throw $this->new_syntax_error('Illegal token');
				break;

				default:
					// FIXME: add support for unicode and unicode escape sequence \uHHHH
					if (preg_match('/^[$\w]+/', $input, $match))
					{
						$tt = in_array($match[0], $this->keywords) ? $match[0] : Minify_JS_Plus::TOKEN_IDENTIFIER;
					}
					else
						throw $this->new_syntax_error('Illegal token');
			}
		}

		$this->token_index = ($this->token_index + 1) & 3;

		if (!isset($this->tokens[$this->token_index]))
			$this->tokens[$this->token_index] = new Minify_JS_Plus_Token();

		$token = $this->tokens[$this->token_index];
		$token->type = $tt;

		if ($tt == Minify_JS_Plus::OP_ASSIGN)
			$token->assign_op = $op;

		$token->start = $this->cursor;

		$token->value = $match[0];
		$this->cursor += strlen($match[0]);

		$token->end = $this->cursor;
		$token->lineno = $this->lineno;

		return $tt;
	}

	public function unget()
	{
		if (++$this->look_ahead == 4)
			throw $this->new_syntax_error('PANIC: too much look_ahead!');

		$this->token_index = ($this->token_index - 1) & 3;
	}

	public function new_syntax_error($m)
	{
		return new Kohana_Exception('Parse error: ' . $m . ' in file \'' . $this->filename . '\' on line ' . $this->lineno);
	}
} //End Kohana_Minify_JS_Plus_Tokenizer
