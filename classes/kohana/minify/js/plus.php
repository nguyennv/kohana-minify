<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Kohana_Minify_JS_Plus version 1.4
 *
 * Minifies a javascript file using a javascript parser
 *
 * This implements a PHP port of Brendan Eich's Narcissus open source javascript engine (in javascript)
 * References: http://en.wikipedia.org/wiki/Narcissus_(JavaScript_engine)
 * Narcissus sourcecode: http://mxr.mozilla.org/mozilla/source/js/narcissus/
 * Kohana_Minify_JS_Plus weblog: http://crisp.tweakblogs.net/blog/cat/716
 *
 * Tino Zijdel <crisp@tweakers.net>
 *
 * Usage: $minified = Kohana_Minify_JS_Plus::minify($script [, $filename])
 *
 * Versionlog (see also changelog.txt):
 * 23-07-2011 - remove dynamic creation of OP_* and KEYWORD_* defines and declare them on top
 *			  reduce memory footprint by minifying by block-scope
 *			  some small byte-saving and performance improvements
 * 12-05-2009 - fixed hook:colon precedence, fixed empty body in loop and if-constructs
 * 18-04-2009 - fixed crashbug in PHP 5.2.9 and several other bugfixes
 * 12-04-2009 - some small bugfixes and performance improvements
 * 09-04-2009 - initial open sourced version 1.0
 *
 * Latest version of this script: http://files.tweakers.net/jsminplus/jsminplus.zip
 *
 */

/* ***** BEGIN LICENSE BLOCK *****
 * Version: MPL 1.1/GPL 2.0/LGPL 2.1
 *
 * The contents of this file are subject to the Mozilla Public License Version
 * 1.1 (the "License"); you may not use this file except in compliance with
 * the License. You may obtain a copy of the License at
 * http://www.mozilla.org/MPL/
 *
 * Software distributed under the License is distributed on an "AS IS" basis,
 * WITHOUT WARRANTY OF ANY KIND, either express or implied. See the License
 * for the specific language governing rights and limitations under the
 * License.
 *
 * The Original Code is the Narcissus JavaScript engine.
 *
 * The Initial Developer of the Original Code is
 * Brendan Eich <brendan@mozilla.org>.
 * Portions created by the Initial Developer are Copyright (C) 2004
 * the Initial Developer. All Rights Reserved.
 *
 * Contributor(s): Tino Zijdel <crisp@tweakers.net>
 * PHP port, modifications and minifier routine are (C) 2009-2011
 *
 * Alternatively, the contents of this file may be used under the terms of
 * either the GNU General Public License Version 2 or later (the "GPL"), or
 * the GNU Lesser General Public License Version 2.1 or later (the "LGPL"),
 * in which case the provisions of the GPL or the LGPL are applicable instead
 * of those above. If you wish to allow use of your version of this file only
 * under the terms of either the GPL or the LGPL, and not to allow others to
 * use your version of this file under the terms of the MPL, indicate your
 * decision by deleting the provisions above and replace them with the notice
 * and other provisions required by the GPL or the LGPL. If you do not delete
 * the provisions above, a recipient may use your version of this file under
 * the terms of any one of the MPL, the GPL or the LGPL.
 *
 * ***** END LICENSE BLOCK ***** */

class Kohana_Minify_JS_Plus
{
	const TOKEN_END               = 1;
	const TOKEN_NUMBER            = 2;
	const TOKEN_IDENTIFIER        = 3;
	const TOKEN_STRING            = 4;
	const TOKEN_REGEXP            = 5;
	const TOKEN_NEWLINE           = 6;
	const TOKEN_CONDCOMMENT_START = 7;
	const TOKEN_CONDCOMMENT_END   = 8;

	const JS_SCRIPT        = 100;
	const JS_BLOCK         = 101;
	const JS_LABEL         = 102;
	const JS_FOR_IN        = 103;
	const JS_CALL          = 104;
	const JS_NEW_WITH_ARGS = 105;
	const JS_INDEX         = 106;
	const JS_ARRAY_INIT    = 107;
	const JS_OBJECT_INIT   = 108;
	const JS_PROPERTY_INIT = 109;
	const JS_GETTER        = 110;
	const JS_SETTER        = 111;
	const JS_GROUP         = 112;
	const JS_LIST          = 113;
	const JS_MINIFIED      = 999;

	const DECLARED_FORM    = 0;
	const EXPRESSED_FORM   = 1;
	const STATEMENT_FORM   = 2;

	/* Operators */
	const OP_SEMICOLON       = ';';
	const OP_COMMA           = ',';
	const OP_HOOK            = '?';
	const OP_COLON           = ':';
	const OP_OR              = '||';
	const OP_AND             = '&&';
	const OP_BITWISE_OR      = '|';
	const OP_BITWISE_XOR     = '^';
	const OP_BITWISE_AND     = '&';
	const OP_STRICT_EQ       = '===';
	const OP_EQ              = '==';
	const OP_ASSIGN          = '=';
	const OP_STRICT_NE       = '!==';
	const OP_NE              = '!=';
	const OP_LSH             = '<<';
	const OP_LE              = '<=';
	const OP_LT              = '<';
	const OP_URSH            = '>>>';
	const OP_RSH             = '>>';
	const OP_GE              = '>=';
	const OP_GT              = '>';
	const OP_INCREMENT       = '++';
	const OP_DECREMENT       = '--';
	const OP_PLUS            = '+';
	const OP_MINUS           = '-';
	const OP_MUL             = '*';
	const OP_DIV             = '/';
	const OP_MOD             = '%';
	const OP_NOT             = '!';
	const OP_BITWISE_NOT     = '~';
	const OP_DOT             = '.';
	const OP_LEFT_BRACKET    = '[';
	const OP_RIGHT_BRACKET   = ']';
	const OP_LEFT_CURLY      = '{';
	const OP_RIGHT_CURLY     = '}';
	const OP_LEFT_PAREN      = '(';
	const OP_RIGHT_PAREN     = ')';
	const OP_CONDCOMMENT_END = '@*/';

	const OP_UNARY_PLUS      = 'U+';
	const OP_UNARY_MINUS     = 'U-';

	/* Keywords */
	const KEYWORD_BREAK      = 'break';
	const KEYWORD_CASE       = 'case';
	const KEYWORD_CATCH      = 'catch';
	const KEYWORD_CONST      = 'const';
	const KEYWORD_CONTINUE   = 'continue';
	const KEYWORD_DEBUGGER   = 'debugger';
	const KEYWORD_DEFAULT    = 'default';
	const KEYWORD_DELETE     = 'delete';
	const KEYWORD_DO         = 'do';
	const KEYWORD_ELSE       = 'else';
	const KEYWORD_ENUM       = 'enum';
	const KEYWORD_FALSE      = 'false';
	const KEYWORD_FINALLY    = 'finally';
	const KEYWORD_FOR        = 'for';
	const KEYWORD_FUNCTION   = 'function';
	const KEYWORD_IF         = 'if';
	const KEYWORD_IN         = 'in';
	const KEYWORD_INSTANCEOF = 'instanceof';
	const KEYWORD_NEW        = 'new';
	const KEYWORD_NULL       = 'null';
	const KEYWORD_RETURN     = 'return';
	const KEYWORD_SWITCH     = 'switch';
	const KEYWORD_THIS       = 'this';
	const KEYWORD_THROW      = 'throw';
	const KEYWORD_TRUE       = 'true';
	const KEYWORD_TRY        = 'try';
	const KEYWORD_TYPEOF     = 'typeof';
	const KEYWORD_VAR        = 'var';
	const KEYWORD_VOID       = 'void';
	const KEYWORD_WHILE      = 'while';
	const KEYWORD_WITH       = 'with';

	private $parser;
	private $reserved = array(
		'break', 'case', 'catch', 'continue', 'default', 'delete', 'do',
		'else', 'finally', 'for', 'function', 'if', 'in', 'instanceof',
		'new', 'return', 'switch', 'this', 'throw', 'try', 'typeof', 'var',
		'void', 'while', 'with',
		// Words reserved for future use
		'abstract', 'boolean', 'byte', 'char', 'class', 'const', 'debugger',
		'double', 'enum', 'export', 'extends', 'final', 'float', 'goto',
		'implements', 'import', 'int', 'interface', 'long', 'native',
		'package', 'private', 'protected', 'public', 'short', 'static',
		'super', 'synchronized', 'throws', 'transient', 'volatile',
		// These are not reserved, but should be taken into account
		// in is_valid_identifier (See jslint source code)
		'arguments', 'eval', 'true', 'false', 'Infinity', 'NaN', 'null', 'undefined'
	);

	private $_js;
	private $_filename;
	
	public function __construct($js, $filename='')
	{
		$this->_js = $js;
		$this->_filename = $filename;
		$this->parser = new Minify_JS_Plus_Parser($this);
	}

	public static function minify($js, $filename='')
	{
		static $instance;

		// this is a singleton
		if(!$instance)
			$instance = new Minify_JS_Plus($js, $filename);

		return $instance->min();
	}

	public function min()
	{
		try
		{
			$n = $this->parser->parse($this->_js, $this->_filename, 1);
			return $this->parse_tree($n);
		}
		catch(Kohana_Exception $e)
		{
			echo $e->getMessage() . "\n";
		}

		return FALSE;
	}

	public function parse_tree($n, $no_block_grouping = FALSE)
	{
		$s = '';

		switch ($n->type)
		{
			case self::JS_MINIFIED:
				$s = $n->value;
			break;

			case self::JS_SCRIPT:
				// we do nothing yet with funDecls or varDecls
				$no_block_grouping = TRUE;
			// FALL THROUGH

			case self::JS_BLOCK:
				$childs = $n->tree_nodes;
				$last_type = 0;
				for ($c = 0, $i = 0, $j = count($childs); $i < $j; $i++)
				{
					$type = $childs[$i]->type;
					$t = $this->parse_tree($childs[$i]);
					if (strlen($t))
					{
						if ($c)
						{
							$s = rtrim($s, ';');

							if ($type == self::KEYWORD_FUNCTION AND $childs[$i]->function_form == self::DECLARED_FORM)
							{
								// put declared functions on a new line
								$s .= "\n";
							}
							elseif ($type == self::KEYWORD_VAR AND $type == $last_type)
							{
								// mutiple var-statements can go into one
								$t = ',' . substr($t, 4);
							}
							else
							{
								// add terminator
								$s .= ';';
							}
						}

						$s .= $t;

						$c++;
						$last_type = $type;
					}
				}

				if ($c > 1 AND !$no_block_grouping)
				{
					$s = '{' . $s . '}';
				}
			break;

			case self::KEYWORD_FUNCTION:
				$s .= 'function' . ($n->name ? ' ' . $n->name : '') . '(';
				$params = $n->params;
				for ($i = 0, $j = count($params); $i < $j; $i++)
					$s .= ($i ? ',' : '') . $params[$i];
				$s .= '){' . $this->parse_tree($n->body, TRUE) . '}';
			break;

			case self::KEYWORD_IF:
				$s = 'if(' . $this->parse_tree($n->condition) . ')';
				$then_part = $this->parse_tree($n->then_part);
				$else_part = $n->else_part ? $this->parse_tree($n->else_part) : NULL;

				// empty if-statement
				if ($then_part == '')
					$then_part = ';';

				if ($else_part)
				{
					// be carefull and always make a block out of the then_part; could be more optimized but is a lot of trouble
					if ($then_part != ';' AND $then_part[0] != '{')
						$then_part = '{' . $then_part . '}';

					$s .= $then_part . 'else';

					// we could check for more, but that hardly ever applies so go for performance
					if ($else_part[0] != '{')
						$s .= ' ';

					$s .= $else_part;
				}
				else
				{
					$s .= $then_part;
				}
			break;

			case self::KEYWORD_SWITCH:
				$s = 'switch(' . $this->parse_tree($n->discriminant) . '){';
				$cases = $n->cases;
				for ($i = 0, $j = count($cases); $i < $j; $i++)
				{
					$case = $cases[$i];
					if ($case->type == self::KEYWORD_CASE)
						$s .= 'case' . ($case->case_label->type != self::TOKEN_STRING ? ' ' : '') . $this->parse_tree($case->case_label) . ':';
					else
						$s .= 'default:';

					$statement = $this->parse_tree($case->statements, TRUE);
					if ($statement)
					{
						$s .= $statement;
						// no terminator for last statement
						if ($i + 1 < $j)
							$s .= ';';
					}
				}
				$s .= '}';
			break;

			case self::KEYWORD_FOR:
				$s = 'for(' . ($n->setup ? $this->parse_tree($n->setup) : '')
					. ';' . ($n->condition ? $this->parse_tree($n->condition) : '')
					. ';' . ($n->update ? $this->parse_tree($n->update) : '') . ')';

				$body  = $this->parse_tree($n->body);
				if ($body == '')
					$body = ';';

				$s .= $body;
			break;

			case self::KEYWORD_WHILE:
				$s = 'while(' . $this->parse_tree($n->condition) . ')';

				$body  = $this->parse_tree($n->body);
				if ($body == '')
					$body = ';';

				$s .= $body;
			break;

			case self::JS_FOR_IN:
				$s = 'for(' . ($n->var_decl ? $this->parse_tree($n->var_decl) : $this->parse_tree($n->iterator)) . ' in ' . $this->parse_tree($n->object) . ')';

				$body  = $this->parse_tree($n->body);
				if ($body == '')
					$body = ';';

				$s .= $body;
			break;

			case self::KEYWORD_DO:
				$s = 'do{' . $this->parse_tree($n->body, TRUE) . '}while(' . $this->parse_tree($n->condition) . ')';
			break;

			case self::KEYWORD_BREAK:
			case self::KEYWORD_CONTINUE:
				$s = $n->value . ($n->label ? ' ' . $n->label : '');
			break;

			case self::KEYWORD_TRY:
				$s = 'try{' . $this->parse_tree($n->try_block, TRUE) . '}';
				$catch_clauses = $n->catch_clauses;
				for ($i = 0, $j = count($catch_clauses); $i < $j; $i++)
				{
					$t = $catch_clauses[$i];
					$s .= 'catch(' . $t->var_name . ($t->guard ? ' if ' . $this->parse_tree($t->guard) : '') . '){' . $this->parse_tree($t->block, TRUE) . '}';
				}
				if ($n->finally_block)
					$s .= 'finally{' . $this->parse_tree($n->finally_block, TRUE) . '}';
			break;

			case self::KEYWORD_THROW:
			case self::KEYWORD_RETURN:
				$s = $n->type;
				if ($n->value)
				{
					$t = $this->parse_tree($n->value);
					if (strlen($t))
					{
						if ($this->is_word_char($t[0]) OR $t[0] == '\\')
							$s .= ' ';

						$s .= $t;
					}
				}
			break;

			case self::KEYWORD_WITH:
				$s = 'with(' . $this->parse_tree($n->object) . ')' . $this->parse_tree($n->body);
			break;

			case self::KEYWORD_VAR:
			case self::KEYWORD_CONST:
				$s = $n->value . ' ';
				$childs = $n->tree_nodes;
				for ($i = 0, $j = count($childs); $i < $j; $i++)
				{
					$t = $childs[$i];
					$s .= ($i ? ',' : '') . $t->name;
					$u = $t->initializer;
					if ($u)
						$s .= '=' . $this->parse_tree($u);
				}
			break;

			case self::KEYWORD_IN:
			case self::KEYWORD_INSTANCEOF:
				$left = $this->parse_tree($n->tree_nodes[0]);
				$right = $this->parse_tree($n->tree_nodes[1]);

				$s = $left;

				if ($this->is_word_char(substr($left, -1)))
					$s .= ' ';

				$s .= $n->type;

				if ($this->is_word_char($right[0]) OR $right[0] == '\\')
					$s .= ' ';

				$s .= $right;
			break;

			case self::KEYWORD_DELETE:
			case self::KEYWORD_TYPEOF:
				$right = $this->parse_tree($n->tree_nodes[0]);

				$s = $n->type;

				if ($this->is_word_char($right[0]) OR $right[0] == '\\')
					$s .= ' ';

				$s .= $right;
			break;

			case self::KEYWORD_VOID:
				$s = 'void(' . $this->parse_tree($n->tree_nodes[0]) . ')';
			break;

			case self::KEYWORD_DEBUGGER:
				throw new Kohana_Exception('NOT IMPLEMENTED: DEBUGGER');
			break;

			case self::TOKEN_CONDCOMMENT_START:
			case self::TOKEN_CONDCOMMENT_END:
				$s = $n->value . ($n->type == self::TOKEN_CONDCOMMENT_START ? ' ' : '');
				$childs = $n->tree_nodes;
				for ($i = 0, $j = count($childs); $i < $j; $i++)
					$s .= $this->parse_tree($childs[$i]);
			break;

			case self::OP_SEMICOLON:
				if ($expression = $n->expression)
					$s = $this->parse_tree($expression);
			break;

			case self::JS_LABEL:
				$s = $n->label . ':' . $this->parse_tree($n->statement);
			break;

			case self::OP_COMMA:
				$childs = $n->tree_nodes;
				for ($i = 0, $j = count($childs); $i < $j; $i++)
					$s .= ($i ? ',' : '') . $this->parse_tree($childs[$i]);
			break;

			case self::OP_ASSIGN:
				$s = $this->parse_tree($n->tree_nodes[0]) . $n->value . $this->parse_tree($n->tree_nodes[1]);
			break;

			case self::OP_HOOK:
				$s = $this->parse_tree($n->tree_nodes[0]) . '?' . $this->parse_tree($n->tree_nodes[1]) . ':' . $this->parse_tree($n->tree_nodes[2]);
			break;

			case self::OP_OR: case self::OP_AND:
			case self::OP_BITWISE_OR: case self::OP_BITWISE_XOR: case self::OP_BITWISE_AND:
			case self::OP_EQ: case self::OP_NE: case self::OP_STRICT_EQ: case self::OP_STRICT_NE:
			case self::OP_LT: case self::OP_LE: case self::OP_GE: case self::OP_GT:
			case self::OP_LSH: case self::OP_RSH: case self::OP_URSH:
			case self::OP_MUL: case self::OP_DIV: case self::OP_MOD:
				$s = $this->parse_tree($n->tree_nodes[0]) . $n->type . $this->parse_tree($n->tree_nodes[1]);
			break;

			case self::OP_PLUS:
			case self::OP_MINUS:
				$left = $this->parse_tree($n->tree_nodes[0]);
				$right = $this->parse_tree($n->tree_nodes[1]);

				switch ($n->tree_nodes[1]->type)
				{
					case self::OP_PLUS:
					case self::OP_MINUS:
					case self::OP_INCREMENT:
					case self::OP_DECREMENT:
					case self::OP_UNARY_PLUS:
					case self::OP_UNARY_MINUS:
						$s = $left . $n->type . ' ' . $right;
					break;

					case self::TOKEN_STRING:
						//combine concatted strings with same quotestyle
						if ($n->type == self::OP_PLUS AND substr($left, -1) == $right[0])
						{
							$s = substr($left, 0, -1) . substr($right, 1);
							break;
						}
					// FALL THROUGH

					default:
						$s = $left . $n->type . $right;
				}
			break;

			case self::OP_NOT:
			case self::OP_BITWISE_NOT:
			case self::OP_UNARY_PLUS:
			case self::OP_UNARY_MINUS:
				$s = $n->value . $this->parse_tree($n->tree_nodes[0]);
			break;

			case self::OP_INCREMENT:
			case self::OP_DECREMENT:
				if ($n->postfix)
					$s = $this->parse_tree($n->tree_nodes[0]) . $n->value;
				else
					$s = $n->value . $this->parse_tree($n->tree_nodes[0]);
			break;

			case self::OP_DOT:
				$s = $this->parse_tree($n->tree_nodes[0]) . '.' . $this->parse_tree($n->tree_nodes[1]);
			break;

			case self::JS_INDEX:
				$s = $this->parse_tree($n->tree_nodes[0]);
				// See if we can replace named index with a dot saving 3 bytes
				if (	$n->tree_nodes[0]->type == self::TOKEN_IDENTIFIER AND
					$n->tree_nodes[1]->type == self::TOKEN_STRING AND
					$this->is_valid_identifier(substr($n->tree_nodes[1]->value, 1, -1))
				)
					$s .= '.' . substr($n->tree_nodes[1]->value, 1, -1);
				else
					$s .= '[' . $this->parse_tree($n->tree_nodes[1]) . ']';
			break;

			case self::JS_LIST:
				$childs = $n->tree_nodes;
				for ($i = 0, $j = count($childs); $i < $j; $i++)
					$s .= ($i ? ',' : '') . $this->parse_tree($childs[$i]);
			break;

			case self::JS_CALL:
				$s = $this->parse_tree($n->tree_nodes[0]) . '(' . $this->parse_tree($n->tree_nodes[1]) . ')';
			break;

			case self::KEYWORD_NEW:
			case self::JS_NEW_WITH_ARGS:
				$s = 'new ' . $this->parse_tree($n->tree_nodes[0]) . '(' . ($n->type == self::JS_NEW_WITH_ARGS ? $this->parse_tree($n->tree_nodes[1]) : '') . ')';
			break;

			case self::JS_ARRAY_INIT:
				$s = '[';
				$childs = $n->tree_nodes;
				for ($i = 0, $j = count($childs); $i < $j; $i++)
				{
					$s .= ($i ? ',' : '') . $this->parse_tree($childs[$i]);
				}
				$s .= ']';
			break;

			case self::JS_OBJECT_INIT:
				$s = '{';
				$childs = $n->tree_nodes;
				for ($i = 0, $j = count($childs); $i < $j; $i++)
				{
					$t = $childs[$i];
					if ($i)
						$s .= ',';
					if ($t->type == self::JS_PROPERTY_INIT)
					{
						// Ditch the quotes when the index is a valid identifier
						if (	$t->tree_nodes[0]->type == self::TOKEN_STRING AND
							$this->is_valid_identifier(substr($t->tree_nodes[0]->value, 1, -1))
						)
							$s .= substr($t->tree_nodes[0]->value, 1, -1);
						else
							$s .= $t->tree_nodes[0]->value;

						$s .= ':' . $this->parse_tree($t->tree_nodes[1]);
					}
					else
					{
						$s .= $t->type == self::JS_GETTER ? 'get' : 'set';
						$s .= ' ' . $t->name . '(';
						$params = $t->params;
						for ($i = 0, $j = count($params); $i < $j; $i++)
							$s .= ($i ? ',' : '') . $params[$i];
						$s .= '){' . $this->parse_tree($t->body, TRUE) . '}';
					}
				}
				$s .= '}';
			break;

			case self::TOKEN_NUMBER:
				$s = $n->value;
				if (preg_match('/^([1-9]+)(0{3,})$/', $s, $m))
					$s = $m[1] . 'e' . strlen($m[2]);
			break;

			case self::KEYWORD_NULL: case self::KEYWORD_THIS: case self::KEYWORD_TRUE: case self::KEYWORD_FALSE:
			case self::TOKEN_IDENTIFIER: case self::TOKEN_STRING: case self::TOKEN_REGEXP:
				$s = $n->value;
			break;

			case self::JS_GROUP:
				if (in_array(
					$n->tree_nodes[0]->type,
					array(
						self::JS_ARRAY_INIT, self::JS_OBJECT_INIT, self::JS_GROUP,
						self::TOKEN_NUMBER, self::TOKEN_STRING, self::TOKEN_REGEXP, self::TOKEN_IDENTIFIER,
						self::KEYWORD_NULL, self::KEYWORD_THIS, self::KEYWORD_TRUE, self::KEYWORD_FALSE
					)
				))
				{
					$s = $this->parse_tree($n->tree_nodes[0]);
				}
				else
				{
					$s = '(' . $this->parse_tree($n->tree_nodes[0]) . ')';
				}
			break;

			default:
				throw new Exception('UNKNOWN TOKEN TYPE: ' . $n->type);
		}

		return $s;
	}

	private function is_valid_identifier($string)
	{
		return preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $string) AND !in_array($string, $this->reserved);
	}

	private function is_word_char($char)
	{
		return $char == '_' OR $char == '$' OR ctype_alnum($char);
	}
}

