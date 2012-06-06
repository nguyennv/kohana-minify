<?php defined('SYSPATH') or die('No direct script access.');

class Kohana_Minify_JS_Plus_Parser{
	private $t;
	private $minifier;

	private $op_precedence = array(
		';' => 0,
		',' => 1,
		'=' => 2, '?' => 2, ':' => 2,
		// The above all have to have the same precedence, see bug 330975
		'||' => 4,
		'&&' => 5,
		'|' => 6,
		'^' => 7,
		'&' => 8,
		'==' => 9, '!=' => 9, '===' => 9, '!==' => 9,
		'<' => 10, '<=' => 10, '>=' => 10, '>' => 10, 'in' => 10, 'instanceof' => 10,
		'<<' => 11, '>>' => 11, '>>>' => 11,
		'+' => 12, '-' => 12,
		'*' => 13, '/' => 13, '%' => 13,
		'delete' => 14, 'void' => 14, 'typeof' => 14,
		'!' => 14, '~' => 14, 'U+' => 14, 'U-' => 14,
		'++' => 15, '--' => 15,
		'new' => 16,
		'.' => 17,
		Minify_JS_Plus::JS_NEW_WITH_ARGS => 0,
		Minify_JS_Plus::JS_INDEX => 0,
		Minify_JS_Plus::JS_CALL => 0,
		Minify_JS_Plus::JS_ARRAY_INIT => 0,
		Minify_JS_Plus::JS_OBJECT_INIT => 0,
		Minify_JS_Plus::JS_GROUP => 0,
	);

	private $op_arity = array(
		',' => -2,
		'=' => 2,
		'?' => 3,
		'||' => 2,
		'&&' => 2,
		'|' => 2,
		'^' => 2,
		'&' => 2,
		'==' => 2, '!=' => 2, '===' => 2, '!==' => 2,
		'<' => 2, '<=' => 2, '>=' => 2, '>' => 2, 'in' => 2, 'instanceof' => 2,
		'<<' => 2, '>>' => 2, '>>>' => 2,
		'+' => 2, '-' => 2,
		'*' => 2, '/' => 2, '%' => 2,
		'delete' => 1, 'void' => 1, 'typeof' => 1,
		'!' => 1, '~' => 1, 'U+' => 1, 'U-' => 1,
		'++' => 1, '--' => 1,
		'new' => 1,
		'.' => 2,
		Minify_JS_Plus::JS_NEW_WITH_ARGS => 2,
		Minify_JS_Plus::JS_INDEX => 2,
		Minify_JS_Plus::JS_CALL => 2,
		Minify_JS_Plus::JS_ARRAY_INIT => 1,
		Minify_JS_Plus::JS_OBJECT_INIT => 1,
		Minify_JS_Plus::JS_GROUP => 1,
		Minify_JS_Plus::TOKEN_CONDCOMMENT_START => 1,
		Minify_JS_Plus::TOKEN_CONDCOMMENT_END => 1
	);

	public function __construct($minifier = null)
	{
		$this->minifier = $minifier;
		$this->t = new Minify_JS_Plus_Tokenizer();
	}

	public function parse($s, $f, $l)
	{
		// initialize tokenizer
		$this->t->init($s, $f, $l);

		$x = new Minify_JS_Plus_Compiler_Context(false);
		$n = $this->script($x);
		if (!$this->t->is_done())
			throw $this->t->new_syntax_error('Syntax error');

		return $n;
	}

	private function script($x)
	{
		$n = $this->statements($x);
		$n->type = Minify_JS_Plus::JS_SCRIPT;
		$n->fun_decls = $x->fun_decls;
		$n->var_decls = $x->var_decls;

		// minify by scope
		if ($this->minifier)
		{
			$n->value = $this->minifier->parse_tree($n);

			// clear tree from node to save memory
			$n->tree_nodes = NULL;
			$n->fun_decls = NULL;
			$n->var_decls = NULL;

			$n->type = Minify_JS_Plus::JS_MINIFIED;
		}

		return $n;
	}

	private function statements($x)
	{
		$n = new Minify_JS_Plus_Node($this->t, Minify_JS_Plus::JS_BLOCK);
		array_push($x->stmt_stack, $n);

		while (!$this->t->is_done() AND $this->t->peek() != Minify_JS_Plus::OP_RIGHT_CURLY)
			$n->add_node($this->statement($x));

		array_pop($x->stmt_stack);

		return $n;
	}

	private function block($x)
	{
		$this->t->must_match(Minify_JS_Plus::OP_LEFT_CURLY);
		$n = $this->statements($x);
		$this->t->must_match(Minify_JS_Plus::OP_RIGHT_CURLY);

		return $n;
	}

	private function statement($x)
	{
		$tt = $this->t->get();
		$n2 = NULL;

		// Cases for statements ending in a right curly return early, avoiding the
		// common semicolon insertion magic after this switch.
		switch ($tt)
		{
			case Minify_JS_Plus::KEYWORD_FUNCTION:
				return $this->function_definition(
					$x,
					true,
					count($x->stmt_stack) > 1 ? Minify_JS_Plus::STATEMENT_FORM : Minify_JS_Plus::DECLARED_FORM
				);
			break;

			case Minify_JS_Plus::OP_LEFT_CURLY:
				$n = $this->statements($x);
				$this->t->must_match(Minify_JS_Plus::OP_RIGHT_CURLY);
			return $n;

			case Minify_JS_Plus::KEYWORD_IF:
				$n = new Minify_JS_Plus_Node($this->t);
				$n->condition = $this->paren_expression($x);
				array_push($x->stmt_stack, $n);
				$n->then_part = $this->statement($x);
				$n->else_part = $this->t->match(Minify_JS_Plus::KEYWORD_ELSE) ? $this->statement($x) : NULL;
				array_pop($x->stmt_stack);
			return $n;

			case Minify_JS_Plus::KEYWORD_SWITCH:
				$n = new Minify_JS_Plus_Node($this->t);
				$this->t->must_match(Minify_JS_Plus::OP_LEFT_PAREN);
				$n->discriminant = $this->expression($x);
				$this->t->must_match(Minify_JS_Plus::OP_RIGHT_PAREN);
				$n->cases = array();
				$n->default_index = -1;

				array_push($x->stmt_stack, $n);

				$this->t->must_match(Minify_JS_Plus::OP_LEFT_CURLY);

				while (($tt = $this->t->get()) != Minify_JS_Plus::OP_RIGHT_CURLY)
				{
					switch ($tt)
					{
						case Minify_JS_Plus::KEYWORD_DEFAULT:
							if ($n->default_index >= 0)
								throw $this->t->new_syntax_error('More than one switch default');
							// FALL THROUGH
						case Minify_JS_Plus::KEYWORD_CASE:
							$n2 = new Minify_JS_Plus_Node($this->t);
							if ($tt == Minify_JS_Plus::KEYWORD_DEFAULT)
								$n->default_index = count($n->cases);
							else
								$n2->case_label = $this->expression($x, Minify_JS_Plus::OP_COLON);
								break;
						default:
							throw $this->t->new_syntax_error('Invalid switch case');
					}

					$this->t->must_match(Minify_JS_Plus::OP_COLON);
					$n2->statements = new Minify_JS_Plus_Node($this->t, Minify_JS_Plus::JS_BLOCK);
					while (($tt = $this->t->peek()) != Minify_JS_Plus::KEYWORD_CASE AND $tt != Minify_JS_Plus::KEYWORD_DEFAULT AND $tt != Minify_JS_Plus::OP_RIGHT_CURLY)
						$n2->statements->add_node($this->statement($x));

					array_push($n->cases, $n2);
				}

				array_pop($x->stmt_stack);
			return $n;

			case Minify_JS_Plus::KEYWORD_FOR:
				$n = new Minify_JS_Plus_Node($this->t);
				$n->is_loop = TRUE;
				$this->t->must_match(Minify_JS_Plus::OP_LEFT_PAREN);

				if (($tt = $this->t->peek()) != Minify_JS_Plus::OP_SEMICOLON)
				{
					$x->in_for_loop_init = TRUE;
					if ($tt == Minify_JS_Plus::KEYWORD_VAR OR $tt == Minify_JS_Plus::KEYWORD_CONST)
					{
						$this->t->get();
						$n2 = $this->variables($x);
					}
					else
					{
						$n2 = $this->expression($x);
					}
					$x->in_for_loop_init = FALSE;
				}

				if ($n2 AND $this->t->match(Minify_JS_Plus::KEYWORD_IN))
				{
					$n->type = Minify_JS_Plus::JS_FOR_IN;
					if ($n2->type == Minify_JS_Plus::KEYWORD_VAR)
					{
						if (count($n2->tree_nodes) != 1)
						{
							throw $this->t->syntax_error(
								'Invalid for..in left-hand side',
								$this->t->filename,
								$n2->lineno
							);
						}

						// NB: n2[0].type == IDENTIFIER and n2[0].value == n2[0].name.
						$n->iterator = $n2->tree_nodes[0];
						$n->var_decl = $n2;
					}
					else
					{
						$n->iterator = $n2;
						$n->var_decl = NULL;
					}

					$n->object = $this->expression($x);
				}
				else
				{
					$n->setup = $n2 ? $n2 : NULL;
					$this->t->must_match(Minify_JS_Plus::OP_SEMICOLON);
					$n->condition = $this->t->peek() == Minify_JS_Plus::OP_SEMICOLON ? NULL : $this->expression($x);
					$this->t->must_match(Minify_JS_Plus::OP_SEMICOLON);
					$n->update = $this->t->peek() == Minify_JS_Plus::OP_RIGHT_PAREN ? NULL : $this->expression($x);
				}

				$this->t->must_match(Minify_JS_Plus::OP_RIGHT_PAREN);
				$n->body = $this->nest($x, $n);
			return $n;

			case Minify_JS_Plus::KEYWORD_WHILE:
					$n = new Minify_JS_Plus_Node($this->t);
					$n->is_loop = TRUE;
					$n->condition = $this->paren_expression($x);
					$n->body = $this->nest($x, $n);
			return $n;

			case Minify_JS_Plus::KEYWORD_DO:
				$n = new Minify_JS_Plus_Node($this->t);
				$n->is_loop = TRUE;
				$n->body = $this->nest($x, $n, Minify_JS_Plus::KEYWORD_WHILE);
				$n->condition = $this->paren_expression($x);
				if (!$x->ecma_strict_mode)
				{
					// <script language="JavaScript"> (without version hints) may need
					// automatic semicolon insertion without a newline after do-while.
					// See http://bugzilla.mozilla.org/show_bug.cgi?id=238945.
					$this->t->match(Minify_JS_Plus::OP_SEMICOLON);
					return $n;
				}
			break;

			case Minify_JS_Plus::KEYWORD_BREAK:
			case Minify_JS_Plus::KEYWORD_CONTINUE:
				$n = new Minify_JS_Plus_Node($this->t);

				if ($this->t->peek_on_same_line() == Minify_JS_Plus::TOKEN_IDENTIFIER)
				{
					$this->t->get();
					$n->label = $this->t->current_token()->value;
				}

				$ss = $x->stmt_stack;
				$i = count($ss);
				$label = $n->label;
				if ($label)
				{
					do
					{
						if (--$i < 0)
							throw $this->t->new_syntax_error('Label not found');
					}
					while ($ss[$i]->label != $label);
				}
				else
				{
					do
					{
						if (--$i < 0)
							throw $this->t->new_syntax_error('Invalid ' . $tt);
					}
					while (!$ss[$i]->is_loop AND ($tt != Minify_JS_Plus::KEYWORD_BREAK OR $ss[$i]->type != Minify_JS_Plus::KEYWORD_SWITCH));
				}

				$n->target = $ss[$i];
			break;

			case Minify_JS_Plus::KEYWORD_TRY:
				$n = new Minify_JS_Plus_Node($this->t);
				$n->try_block = $this->block($x);
				$n->catch_clauses = array();

				while ($this->t->match(Minify_JS_Plus::KEYWORD_CATCH))
				{
					$n2 = new Minify_JS_Plus_Node($this->t);
					$this->t->must_match(Minify_JS_Plus::OP_LEFT_PAREN);
					$n2->var_name = $this->t->must_match(Minify_JS_Plus::TOKEN_IDENTIFIER)->value;

					if ($this->t->match(Minify_JS_Plus::KEYWORD_IF))
					{
						if ($x->ecma_strict_mode)
							throw $this->t->new_syntax_error('Illegal catch guard');

						if (count($n->catch_clauses) AND !end($n->catch_clauses)->guard)
							throw $this->t->new_syntax_error('Guarded catch after unguarded');

						$n2->guard = $this->expression($x);
					}
					else
					{
						$n2->guard = NULL;
					}

					$this->t->must_match(Minify_JS_Plus::OP_RIGHT_PAREN);
					$n2->block = $this->block($x);
					array_push($n->catch_clauses, $n2);
				}

				if ($this->t->match(Minify_JS_Plus::KEYWORD_FINALLY))
					$n->finally_block = $this->block($x);

				if (!count($n->catch_clauses) AND !$n->finally_block)
					throw $this->t->new_syntax_error('Invalid try statement');
			return $n;

			case Minify_JS_Plus::KEYWORD_CATCH:
			case Minify_JS_Plus::KEYWORD_FINALLY:
				throw $this->t->new_syntax_error($tt + ' without preceding try');

			case Minify_JS_Plus::KEYWORD_THROW:
				$n = new Minify_JS_Plus_Node($this->t);
				$n->value = $this->expression($x);
			break;

			case Minify_JS_Plus::KEYWORD_RETURN:
				if (!$x->in_function)
					throw $this->t->new_syntax_error('Invalid return');

				$n = new Minify_JS_Plus_Node($this->t);
				$tt = $this->t->peek_on_same_line();
				if ($tt != Minify_JS_Plus::TOKEN_END AND $tt != Minify_JS_Plus::TOKEN_NEWLINE AND $tt != Minify_JS_Plus::OP_SEMICOLON AND $tt != Minify_JS_Plus::OP_RIGHT_CURLY)
					$n->value = $this->expression($x);
				else
					$n->value = NULL;
			break;

			case Minify_JS_Plus::KEYWORD_WITH:
				$n = new Minify_JS_Plus_Node($this->t);
				$n->object = $this->paren_expression($x);
				$n->body = $this->nest($x, $n);
			return $n;

			case Minify_JS_Plus::KEYWORD_VAR:
			case Minify_JS_Plus::KEYWORD_CONST:
					$n = $this->variables($x);
			break;

			case Minify_JS_Plus::TOKEN_CONDCOMMENT_START:
			case Minify_JS_Plus::TOKEN_CONDCOMMENT_END:
				$n = new Minify_JS_Plus_Node($this->t);
			return $n;

			case Minify_JS_Plus::KEYWORD_DEBUGGER:
				$n = new Minify_JS_Plus_Node($this->t);
			break;

			case Minify_JS_Plus::TOKEN_NEWLINE:
			case Minify_JS_Plus::OP_SEMICOLON:
				$n = new Minify_JS_Plus_Node($this->t, Minify_JS_Plus::OP_SEMICOLON);
				$n->expression = NULL;
			return $n;

			default:
				if ($tt == Minify_JS_Plus::TOKEN_IDENTIFIER)
				{
					$this->t->scan_operand = FALSE;
					$tt = $this->t->peek();
					$this->t->scan_operand = TRUE;
					if ($tt == Minify_JS_Plus::OP_COLON)
					{
						$label = $this->t->current_token()->value;
						$ss = $x->stmt_stack;
						for ($i = count($ss) - 1; $i >= 0; --$i)
						{
							if ($ss[$i]->label == $label)
								throw $this->t->new_syntax_error('Duplicate label');
						}

						$this->t->get();
						$n = new Minify_JS_Plus_Node($this->t, Minify_JS_Plus::JS_LABEL);
						$n->label = $label;
						$n->statement = $this->nest($x, $n);

						return $n;
					}
				}

				$n = new Minify_JS_Plus_Node($this->t, Minify_JS_Plus::OP_SEMICOLON);
				$this->t->unget();
				$n->expression = $this->expression($x);
				$n->end = $n->expression->end;
			break;
		}

		if ($this->t->lineno == $this->t->current_token()->lineno)
		{
			$tt = $this->t->peek_on_same_line();
			if ($tt != Minify_JS_Plus::TOKEN_END AND $tt != Minify_JS_Plus::TOKEN_NEWLINE AND $tt != Minify_JS_Plus::OP_SEMICOLON AND $tt != Minify_JS_Plus::OP_RIGHT_CURLY)
				throw $this->t->new_syntax_error('Missing ; before statement');
		}

		$this->t->match(Minify_JS_Plus::OP_SEMICOLON);

		return $n;
	}

	private function function_definition($x, $require_name, $function_form)
	{
		$f = new Minify_JS_Plus_Node($this->t);

		if ($f->type != Minify_JS_Plus::KEYWORD_FUNCTION)
			$f->type = ($f->value == 'get') ? Minify_JS_Plus::JS_GETTER : Minify_JS_Plus::JS_SETTER;

		if ($this->t->match(Minify_JS_Plus::TOKEN_IDENTIFIER))
			$f->name = $this->t->current_token()->value;
		elseif ($require_name)
			throw $this->t->new_syntax_error('Missing function identifier');

		$this->t->must_match(Minify_JS_Plus::OP_LEFT_PAREN);
			$f->params = array();

		while (($tt = $this->t->get()) != Minify_JS_Plus::OP_RIGHT_PAREN)
		{
			if ($tt != Minify_JS_Plus::TOKEN_IDENTIFIER)
				throw $this->t->new_syntax_error('Missing formal parameter');

			array_push($f->params, $this->t->current_token()->value);

			if ($this->t->peek() != Minify_JS_Plus::OP_RIGHT_PAREN)
				$this->t->must_match(Minify_JS_Plus::OP_COMMA);
		}

		$this->t->must_match(Minify_JS_Plus::OP_LEFT_CURLY);

		$x2 = new Minify_JS_Plus_Compiler_Context(true);
		$f->body = $this->script($x2);

		$this->t->must_match(Minify_JS_Plus::OP_RIGHT_CURLY);
		$f->end = $this->t->current_token()->end;

		$f->function_form = $function_form;
		if ($function_form == Minify_JS_Plus::DECLARED_FORM)
			array_push($x->fun_decls, $f);

		return $f;
	}

	private function variables($x)
	{
		$n = new Minify_JS_Plus_Node($this->t);

		do
		{
			$this->t->must_match(Minify_JS_Plus::TOKEN_IDENTIFIER);

			$n2 = new Minify_JS_Plus_Node($this->t);
			$n2->name = $n2->value;

			if ($this->t->match(Minify_JS_Plus::OP_ASSIGN))
			{
				if ($this->t->current_token()->assign_op)
					throw $this->t->new_syntax_error('Invalid variable initialization');

				$n2->initializer = $this->expression($x, Minify_JS_Plus::OP_COMMA);
			}

			$n2->readOnly = $n->type == Minify_JS_Plus::KEYWORD_CONST;

			$n->add_node($n2);
			array_push($x->var_decls, $n2);
		}
		while ($this->t->match(Minify_JS_Plus::OP_COMMA));

		return $n;
	}

	private function expression($x, $stop=false)
	{
		$operators = array();
		$operands = array();
		$n = FALSE;

		$bl = $x->bracket_level;
		$cl = $x->curly_level;
		$pl = $x->paren_level;
		$hl = $x->hook_level;

		while (($tt = $this->t->get()) != Minify_JS_Plus::TOKEN_END)
		{
			if ($tt == $stop AND
				$x->bracket_level == $bl AND
				$x->curly_level == $cl AND
				$x->paren_level == $pl AND
				$x->hook_level == $hl
			)
			{
				// Stop only if tt matches the optional stop parameter, and that
				// token is not quoted by some kind of bracket.
				break;
			}

			switch ($tt)
			{
				case Minify_JS_Plus::OP_SEMICOLON:
					// NB: cannot be empty, Statement handled that.
					break 2;

				case Minify_JS_Plus::OP_HOOK:
					if ($this->t->scan_operand)
						break 2;

					while (	!empty($operators) AND
						$this->op_precedence[end($operators)->type] > $this->op_precedence[$tt]
					)
						$this->reduce($operators, $operands);

					array_push($operators, new Minify_JS_Plus_Node($this->t));

					++$x->hook_level;
					$this->t->scan_operand = TRUE;
					$n = $this->expression($x);

					if (!$this->t->match(Minify_JS_Plus::OP_COLON))
						break 2;

					--$x->hook_level;
					array_push($operands, $n);
				break;

				case Minify_JS_Plus::OP_COLON:
					if ($x->hook_level)
						break 2;

					throw $this->t->new_syntax_error('Invalid label');
				break;

				case Minify_JS_Plus::OP_ASSIGN:
					if ($this->t->scan_operand)
						break 2;

					// Use >, not >=, for right-associative ASSIGN
					while (	!empty($operators) AND
						$this->op_precedence[end($operators)->type] > $this->op_precedence[$tt]
					)
						$this->reduce($operators, $operands);

					array_push($operators, new Minify_JS_Plus_Node($this->t));
					end($operands)->assign_op = $this->t->current_token()->assign_op;
					$this->t->scan_operand = TRUE;
				break;

				case Minify_JS_Plus::KEYWORD_IN:
					// An in operator should not be parsed if we're parsing the head of
					// a for (...) loop, unless it is in the then part of a conditional
					// expression, or parenthesized somehow.
					if ($x->in_for_loop_init AND !$x->hook_level AND
						!$x->bracket_level AND !$x->curly_level AND
						!$x->paren_level
					)
						break 2;
				// FALL THROUGH
				case Minify_JS_Plus::OP_COMMA:
					// A comma operator should not be parsed if we're parsing the then part
					// of a conditional expression unless it's parenthesized somehow.
					if ($tt == Minify_JS_Plus::OP_COMMA AND $x->hook_level AND
						!$x->bracket_level AND !$x->curly_level AND
						!$x->paren_level
					)
						break 2;
				// Treat comma as left-associative so reduce can fold left-heavy
				// COMMA trees into a single array.
				// FALL THROUGH
				case Minify_JS_Plus::OP_OR:
				case Minify_JS_Plus::OP_AND:
				case Minify_JS_Plus::OP_BITWISE_OR:
				case Minify_JS_Plus::OP_BITWISE_XOR:
				case Minify_JS_Plus::OP_BITWISE_AND:
				case Minify_JS_Plus::OP_EQ: case Minify_JS_Plus::OP_NE: case Minify_JS_Plus::OP_STRICT_EQ: case Minify_JS_Plus::OP_STRICT_NE:
				case Minify_JS_Plus::OP_LT: case Minify_JS_Plus::OP_LE: case Minify_JS_Plus::OP_GE: case Minify_JS_Plus::OP_GT:
				case Minify_JS_Plus::KEYWORD_INSTANCEOF:
				case Minify_JS_Plus::OP_LSH: case Minify_JS_Plus::OP_RSH: case Minify_JS_Plus::OP_URSH:
				case Minify_JS_Plus::OP_PLUS: case Minify_JS_Plus::OP_MINUS:
				case Minify_JS_Plus::OP_MUL: case Minify_JS_Plus::OP_DIV: case Minify_JS_Plus::OP_MOD:
				case Minify_JS_Plus::OP_DOT:
					if ($this->t->scan_operand)
						break 2;

					while (	!empty($operators) AND
						$this->op_precedence[end($operators)->type] >= $this->op_precedence[$tt]
					)
						$this->reduce($operators, $operands);

					if ($tt == Minify_JS_Plus::OP_DOT)
					{
						$this->t->must_match(Minify_JS_Plus::TOKEN_IDENTIFIER);
						array_push($operands, new Minify_JS_Plus_Node($this->t, Minify_JS_Plus::OP_DOT, array_pop($operands), new Minify_JS_Plus_Node($this->t)));
					}
					else
					{
						array_push($operators, new Minify_JS_Plus_Node($this->t));
						$this->t->scan_operand = TRUE;
					}
				break;

				case Minify_JS_Plus::KEYWORD_DELETE: case Minify_JS_Plus::KEYWORD_VOID: case Minify_JS_Plus::KEYWORD_TYPEOF:
				case Minify_JS_Plus::OP_NOT: case Minify_JS_Plus::OP_BITWISE_NOT: case Minify_JS_Plus::OP_UNARY_PLUS: case Minify_JS_Plus::OP_UNARY_MINUS:
				case Minify_JS_Plus::KEYWORD_NEW:
					if (!$this->t->scan_operand)
						break 2;

					array_push($operators, new Minify_JS_Plus_Node($this->t));
				break;

				case Minify_JS_Plus::OP_INCREMENT: case Minify_JS_Plus::OP_DECREMENT:
					if ($this->t->scan_operand)
					{
						array_push($operators, new Minify_JS_Plus_Node($this->t));  // prefix increment or decrement
					}
					else
					{
						// Don't cross a line boundary for postfix {in,de}crement.
						$t = $this->t->tokens[($this->t->token_index + $this->t->look_ahead - 1) & 3];
						if ($t AND $t->lineno != $this->t->lineno)
							break 2;

						if (!empty($operators))
						{
							// Use >, not >=, so postfix has higher precedence than prefix.
							while ($this->op_precedence[end($operators)->type] > $this->op_precedence[$tt])
								$this->reduce($operators, $operands);
						}

						$n = new Minify_JS_Plus_Node($this->t, $tt, array_pop($operands));
						$n->postfix = TRUE;
						array_push($operands, $n);
					}
				break;

				case Minify_JS_Plus::KEYWORD_FUNCTION:
					if (!$this->t->scan_operand)
						break 2;

					array_push($operands, $this->function_definition($x, FALSE, Minify_JS_Plus::EXPRESSED_FORM));
					$this->t->scan_operand = FALSE;
				break;

				case Minify_JS_Plus::KEYWORD_NULL: case Minify_JS_Plus::KEYWORD_THIS: case Minify_JS_Plus::KEYWORD_TRUE: case Minify_JS_Plus::KEYWORD_FALSE:
				case Minify_JS_Plus::TOKEN_IDENTIFIER: case Minify_JS_Plus::TOKEN_NUMBER: case Minify_JS_Plus::TOKEN_STRING: case Minify_JS_Plus::TOKEN_REGEXP:
					if (!$this->t->scan_operand)
						break 2;

					array_push($operands, new Minify_JS_Plus_Node($this->t));
					$this->t->scan_operand = FALSE;
				break;

				case Minify_JS_Plus::TOKEN_CONDCOMMENT_START:
				case Minify_JS_Plus::TOKEN_CONDCOMMENT_END:
					if ($this->t->scan_operand)
						array_push($operators, new Minify_JS_Plus_Node($this->t));
					else
						array_push($operands, new Minify_JS_Plus_Node($this->t));
				break;

				case Minify_JS_Plus::OP_LEFT_BRACKET:
					if ($this->t->scan_operand)
					{
						// Array initialiser.  Parse using recursive descent, as the
						// sub-grammar here is not an operator grammar.
						$n = new Minify_JS_Plus_Node($this->t, Minify_JS_Plus::JS_ARRAY_INIT);
						while (($tt = $this->t->peek()) != Minify_JS_Plus::OP_RIGHT_BRACKET)
						{
							if ($tt == Minify_JS_Plus::OP_COMMA)
							{
								$this->t->get();
								$n->add_node(null);
								continue;
							}

							$n->add_node($this->expression($x, Minify_JS_Plus::OP_COMMA));
							if (!$this->t->match(Minify_JS_Plus::OP_COMMA))
								break;
						}

						$this->t->must_match(Minify_JS_Plus::OP_RIGHT_BRACKET);
						array_push($operands, $n);
						$this->t->scan_operand = FALSE;
					}
					else
					{
						// Property indexing operator.
						array_push($operators, new Minify_JS_Plus_Node($this->t, Minify_JS_Plus::JS_INDEX));
						$this->t->scan_operand = TRUE;
						++$x->bracket_level;
					}
				break;

				case Minify_JS_Plus::OP_RIGHT_BRACKET:
					if ($this->t->scan_operand OR $x->bracket_level == $bl)
						break 2;

					while ($this->reduce($operators, $operands)->type != Minify_JS_Plus::JS_INDEX)
						continue;

					--$x->bracket_level;
				break;

				case Minify_JS_Plus::OP_LEFT_CURLY:
					if (!$this->t->scan_operand)
						break 2;

					// Object initialiser.  As for array initialisers (see above),
					// parse using recursive descent.
					++$x->curly_level;
					$n = new Minify_JS_Plus_Node($this->t, Minify_JS_Plus::JS_OBJECT_INIT);
					while (!$this->t->match(Minify_JS_Plus::OP_RIGHT_CURLY))
					{
						do
						{
							$tt = $this->t->get();
							$tv = $this->t->current_token()->value;
							if (($tv == 'get' OR $tv == 'set') AND $this->t->peek() == Minify_JS_Plus::TOKEN_IDENTIFIER)
							{
								if ($x->ecma_strict_mode)
									throw $this->t->new_syntax_error('Illegal property accessor');

								$n->add_node($this->function_definition($x, TRUE, EXPRESSED_FORM));
							}
							else
							{
								switch ($tt)
								{
									case Minify_JS_Plus::TOKEN_IDENTIFIER:
									case Minify_JS_Plus::TOKEN_NUMBER:
									case Minify_JS_Plus::TOKEN_STRING:
										$id = new Minify_JS_Plus_Node($this->t);
									break;

									case Minify_JS_Plus::OP_RIGHT_CURLY:
										if ($x->ecma_strict_mode)
											throw $this->t->new_syntax_error('Illegal trailing ,');
									break 3;

									default:
										throw $this->t->new_syntax_error('Invalid property name');
								}

								$this->t->must_match(Minify_JS_Plus::OP_COLON);
								$n->add_node(new Minify_JS_Plus_Node($this->t, Minify_JS_Plus::JS_PROPERTY_INIT, $id, $this->expression($x, Minify_JS_Plus::OP_COMMA)));
							}
						}
						while ($this->t->match(Minify_JS_Plus::OP_COMMA));

						$this->t->must_match(Minify_JS_Plus::OP_RIGHT_CURLY);
						break;
					}

					array_push($operands, $n);
					$this->t->scan_operand = FALSE;
					--$x->curly_level;
				break;

				case Minify_JS_Plus::OP_RIGHT_CURLY:
					if (!$this->t->scan_operand AND $x->curly_level != $cl)
						throw new Exception('PANIC: right curly botch');
				break 2;

				case Minify_JS_Plus::OP_LEFT_PAREN:
					if ($this->t->scan_operand)
					{
						array_push($operators, new Minify_JS_Plus_Node($this->t, Minify_JS_Plus::JS_GROUP));
					}
					else
					{
						while (	!empty($operators) AND
							$this->op_precedence[end($operators)->type] > $this->op_precedence[Minify_JS_Plus::KEYWORD_NEW]
						)
							$this->reduce($operators, $operands);

						// Handle () now, to regularize the n-ary case for n > 0.
						// We must set scan_operand in case there are arguments and
						// the first one is a regexp or unary+/-.
						$n = end($operators);
						$this->t->scan_operand = TRUE;
						if ($this->t->match(Minify_JS_Plus::OP_RIGHT_PAREN))
						{
							if ($n AND $n->type == Minify_JS_Plus::KEYWORD_NEW)
							{
								array_pop($operators);
								$n->add_node(array_pop($operands));
							}
							else
							{
								$n = new Minify_JS_Plus_Node($this->t, Minify_JS_Plus::JS_CALL, array_pop($operands), new Minify_JS_Plus_Node($this->t, Minify_JS_Plus::JS_LIST));
							}

							array_push($operands, $n);
							$this->t->scan_operand = FALSE;
							break;
						}

						if ($n AND $n->type == Minify_JS_Plus::KEYWORD_NEW)
							$n->type = Minify_JS_Plus::JS_NEW_WITH_ARGS;
						else
							array_push($operators, new Minify_JS_Plus_Node($this->t, Minify_JS_Plus::JS_CALL));
					}

					++$x->paren_level;
				break;

				case Minify_JS_Plus::OP_RIGHT_PAREN:
					if ($this->t->scan_operand OR $x->paren_level == $pl)
						break 2;

					while (($tt = $this->reduce($operators, $operands)->type) != Minify_JS_Plus::JS_GROUP AND
						$tt != Minify_JS_Plus::JS_CALL AND $tt != Minify_JS_Plus::JS_NEW_WITH_ARGS
					)
					{
						continue;
					}

					if ($tt != Minify_JS_Plus::JS_GROUP)
					{
						$n = end($operands);
						if ($n->tree_nodes[1]->type != Minify_JS_Plus::OP_COMMA)
							$n->tree_nodes[1] = new Minify_JS_Plus_Node($this->t, Minify_JS_Plus::JS_LIST, $n->tree_nodes[1]);
						else
							$n->tree_nodes[1]->type = Minify_JS_Plus::JS_LIST;
					}

					--$x->paren_level;
				break;

				// Automatic semicolon insertion means we may scan across a newline
				// and into the beginning of another statement.  If so, break out of
				// the while loop and let the t.scan_operand logic handle errors.
				default:
					break 2;
			}
		}

		if ($x->hook_level != $hl)
			throw $this->t->new_syntax_error('Missing : in conditional expression');

		if ($x->paren_level != $pl)
			throw $this->t->new_syntax_error('Missing ) in parenthetical');

		if ($x->bracket_level != $bl)
			throw $this->t->new_syntax_error('Missing ] in index expression');

		if ($this->t->scan_operand)
			throw $this->t->new_syntax_error('Missing operand');

		// Resume default mode, scanning for operands, not operators.
		$this->t->scan_operand = TRUE;
		$this->t->unget();

		while (count($operators))
			$this->reduce($operators, $operands);

		return array_pop($operands);
	}

	private function paren_expression($x)
	{
		$this->t->must_match(Minify_JS_Plus::OP_LEFT_PAREN);
		$n = $this->expression($x);
		$this->t->must_match(Minify_JS_Plus::OP_RIGHT_PAREN);

		return $n;
	}

	// Statement stack and nested statement handler.
	private function nest($x, $node, $end = FALSE)
	{
		array_push($x->stmt_stack, $node);
		$n = $this->statement($x);
		array_pop($x->stmt_stack);

		if ($end)
			$this->t->must_match($end);

		return $n;
	}

	private function reduce(&$operators, &$operands)
	{
		$n = array_pop($operators);
		$op = $n->type;
		$arity = $this->op_arity[$op];
		$c = count($operands);
		if ($arity == -2)
		{
			// Flatten left-associative trees
			if ($c >= 2)
			{
				$left = $operands[$c - 2];
				if ($left->type == $op)
				{
					$right = array_pop($operands);
					$left->add_node($right);
					return $left;
				}
			}
			$arity = 2;
		}

		// Always use push to add operands to n, to update start and end
		$a = array_splice($operands, $c - $arity);
		for ($i = 0; $i < $arity; $i++)
			$n->add_node($a[$i]);

		// Include closing bracket or postfix operator in [start,end]
		$te = $this->t->current_token()->end;
		if ($n->end < $te)
			$n->end = $te;

		array_push($operands, $n);

		return $n;
	}
} //End Kohana_Minify_JS_Plus_Parser
