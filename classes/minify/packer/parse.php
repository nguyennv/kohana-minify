<?php defined('SYSPATH') or die('No direct script access.');

class Minify_Packer_Parse {
	public $ignore_case = false;
	public $escape_char = '';
	
	// constants
	const EXPRESSION = 0;
	const REPLACEMENT = 1;
	const LENGTH = 2;
	
	// used to determine nesting levels
	private $_GROUPS = '/\\(/';//g
	private $_SUB_REPLACE = '/\\$\\d/';
	private $_INDEXED = '/^\\$\\d+$/';
	private $_TRIM = '/([\'"])\\1\\.(.*)\\.\\1\\1$/';
	private $_ESCAPE = '/\\\./';//g
	private $_QUOTE = '/\'/';
	private $_DELETED = '/\\x01[^\\x01]*\\x01/';//g
	
	public function add($expression, $replacement = '') {
		// count the number of sub-expressions
		//  - add one because each pattern is itself a sub-expression
		$length = 1 + preg_match_all($this->_GROUPS, $this->_internal_escape((string)$expression), $out);
		
		// treat only strings $replacement
		if (is_string($replacement)) {
			// does the pattern deal with sub-expressions?
			if (preg_match($this->_SUB_REPLACE, $replacement)) {
				// a simple lookup? (e.g. "$2")
				if (preg_match($this->_INDEXED, $replacement)) {
					// store the index (used for fast retrieval of matched strings)
					$replacement = (int)(substr($replacement, 1)) - 1;
				} else { // a complicated lookup (e.g. "Hello $2 $1")
					// build a function to do the lookup
					$quote = preg_match($this->_QUOTE, $this->_internal_escape($replacement))
					         ? '"' : "'";
					$replacement = array(
						'fn' => '_back_references',
						'data' => array(
							'replacement' => $replacement,
							'length' => $length,
							'quote' => $quote
						)
					);
				}
			}
		}
		// pass the modified arguments
		if (!empty($expression)) $this->_add($expression, $replacement, $length);
		else $this->_add('/^$/', $replacement, $length);
	}
	
	public function exec($string) {
		// execute the global replacement
		$this->_escaped = array();
		
		// simulate the _patterns.toSTring of Dean
		$regexp = '/';
		foreach ($this->_patterns as $reg) {
			$regexp .= '(' . substr($reg[self::EXPRESSION], 1, -1) . ')|';
		}
		$regexp = substr($regexp, 0, -1) . '/';
		$regexp .= ($this->ignore_case) ? 'i' : '';
		
		$string = $this->_escape($string, $this->escape_char);
		$string = preg_replace_callback(
			$regexp,
			array(
				&$this,
				'_replacement'
			),
			$string
		);
		$string = $this->_unescape($string, $this->escape_char);
		
		return preg_replace($this->_DELETED, '', $string);
	}
		
	public function reset() {
		// clear the patterns collection so that this object may be re-used
		$this->_patterns = array();
	}

	// private
	private $_escaped = array();  // escaped characters
	private $_patterns = array(); // patterns stored by index
	
	// create and add a new pattern to the patterns collection
	private function _add() {
		$arguments = func_get_args();
		$this->_patterns[] = $arguments;
	}
	
	// this is the global replace function (it's quite complicated)
	private function _replacement($arguments) {
		if (empty($arguments)) return '';
		
		$i = 1; $j = 0;
		// loop through the patterns
		while (isset($this->_patterns[$j])) {
			$pattern = $this->_patterns[$j++];
			// do we have a result?
			if (isset($arguments[$i]) && ($arguments[$i] != '')) {
				$replacement = $pattern[self::REPLACEMENT];
				
				if (is_array($replacement) && isset($replacement['fn'])) {
					
					if (isset($replacement['data'])) $this->buffer = $replacement['data'];
					return call_user_func(array(&$this, $replacement['fn']), $arguments, $i);
					
				} elseif (is_int($replacement)) {
					return $arguments[$replacement + $i];
				
				}
				$delete = ($this->escape_char == '' ||
				           strpos($arguments[$i], $this->escape_char) === false)
				        ? '' : "\x01" . $arguments[$i] . "\x01";
				return $delete . $replacement;
			
			// skip over references to sub-expressions
			} else {
				$i += $pattern[self::LENGTH];
			}
		}
	}
	
	private function _back_references($match, $offset) {
		$replacement = $this->buffer['replacement'];
		$quote = $this->buffer['quote'];
		$i = $this->buffer['length'];
		while ($i) {
			$replacement = str_replace('$'.$i--, $match[$offset + $i], $replacement);
		}
		return $replacement;
	}
	
	private function _replace_name($match, $offset){
		$length = strlen($match[$offset + 2]);
		$start = $length - max($length - strlen($match[$offset + 3]), 0);
		return substr($match[$offset + 1], $start, $length) . $match[$offset + 4];
	}
	
	private function _replace_encoded($match, $offset) {
		return $this->buffer[$match[$offset]];
	}
	
	
	// php : we cannot pass additional data to preg_replace_callback,
	// and we cannot use &$this in create_function, so let's go to lower level
	private $buffer;
	
	// encode escaped characters
	private function _escape($string, $escape_char) {
		if ($escape_char) {
			$this->buffer = $escape_char;
			return preg_replace_callback(
				'/\\' . $escape_char . '(.)' .'/',
				array(&$this, '_escape_bis'),
				$string
			);
			
		} else {
			return $string;
		}
	}
	private function _escape_bis($match) {
		$this->_escaped[] = $match[1];
		return $this->buffer;
	}
	
	// decode escaped characters
	private function _unescape($string, $escape_char) {
		if ($escape_char) {
			$regexp = '/'.'\\'.$escape_char.'/';
			$this->buffer = array('escape_char'=> $escape_char, 'i' => 0);
			return preg_replace_callback
			(
				$regexp,
				array(&$this, '_unescape_bis'),
				$string
			);
			
		} else {
			return $string;
		}
	}
	private function _unescape_bis() {
		if (isset($this->_escaped[$this->buffer['i']])
			&& $this->_escaped[$this->buffer['i']] != '')
		{
			 $temp = $this->_escaped[$this->buffer['i']];
		} else {
			$temp = '';
		}
		$this->buffer['i']++;
		return $this->buffer['escape_char'] . $temp;
	}
	
	private function _internal_escape($string) {
		return preg_replace($this->_ESCAPE, '', $string);
	}
}
