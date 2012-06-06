<?php defined('SYSPATH') or die('No direct script access.');

class Kohana_Minify_JS_Plus_Node
{
	private $type;
	private $value;
	private $lineno;
	private $start;
	private $end;
	
	public $tree_nodes = array();
	public $fun_decls = array();
	public $var_decls = array();

	public function __construct($t, $type=0)
	{
		if ($token = $t->current_token())
		{
			$this->type = $type ? $type : $token->type;
			$this->value = $token->value;
			$this->lineno = $token->lineno;
			$this->start = $token->start;
			$this->end = $token->end;
		}
		else
		{
			$this->type = $type;
			$this->lineno = $t->lineno;
		}

		if (($numargs = func_num_args()) > 2)
		{
			$args = func_get_args();
			for ($i = 2; $i < $numargs; $i++)
				$this->add_node($args[$i]);
		}
	}

	// we don't want to bloat our object with all kind of specific properties, so we use overloading
	public function __set($name, $value)
	{
		$this->$name = $value;
	}

	public function __get($name)
	{
		if (isset($this->$name))
			return $this->$name;

		return NULL;
	}

	public function add_node($node)
	{
		if ($node !== NULL)
		{
			if ($node->start < $this->start)
				$this->start = $node->start;
			if ($this->end < $node->end)
				$this->end = $node->end;
		}

		$this->tree_nodes[] = $node;
	}
} // End Kohana_Minify_JS_Plus_Node
