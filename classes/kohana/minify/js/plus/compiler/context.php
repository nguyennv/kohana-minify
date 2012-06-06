<?php defined('SYSPATH') or die('No direct script access.');

class Kohana_Minify_JS_Plus_Compiler_Context
{
	public $in_function      = FALSE;
	public $in_for_loop_init = FALSE;
	public $ecma_strict_mode = FALSE;
	public $bracket_level    = 0;
	public $curly_level      = 0;
	public $paren_level      = 0;
	public $hook_level       = 0;

	public $stmt_stack       = array();
	public $fun_decls        = array();
	public $var_decls        = array();

	public function __construct($in_function)
	{
		$this->in_function = $in_function;
	}
} // End Kohana_Minify_JS_Plus_Compiler_Context
