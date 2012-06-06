<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Add line numbers in C-style comments for easier debugging of combined content
 *
 * @package Minify
 * @author Stephen Clay <steve@mrclay.org>
 * @author Adam Pedersen (Issue 55 fix)
 */
class Kohana_Minify_CSS_Lines {

	/**
	 * Add line numbers in C-style comments
	 *
	 * This uses a very basic parser easily fooled by comment tokens inside
	 * strings or regexes, but, otherwise, generally clean code will not be 
	 * mangled. URI rewriting can also be performed.
	 *
	 * @param string $content
	 * 
	 * @param array $options available options:
	 * 
	 * 'id': (optional) string to identify file. E.g. file name/path
	 *
	 * 'currentDir': (default null) if given, this is assumed to be the
	 * directory of the current CSS file. Using this, minify will rewrite
	 * all relative URIs in import/url declarations to correctly point to
	 * the desired files, and prepend a comment with debugging information about
	 * this process.
	 * 
	 * @return string 
	 */
	public static function minify($content, $options = array()) 
	{
		$id = (isset($options['id']) && $options['id'])
			? $options['id']
			: '';
		$content = str_replace("\r\n", "\n", $content);

		// Hackily rewrite strings with XPath expressions that are
		// likely to throw off our dumb parser (for Prototype 1.6.1).
		$content = str_replace('"/*"', '"/"+"*"', $content);
		$content = preg_replace('@([\'"])(\\.?//?)\\*@', '$1$2$1+$1*', $content);

		$lines = explode("\n", $content);
		$num_lines = count($lines);
		// determine left padding
		$pad_to = strlen((string) $num_lines); // e.g. 103 lines = 3 digits
		$in_comment = FALSE;
		$i = 0;
		$new_lines = array();
		while (NULL !== ($line = array_shift($lines)))
		{
			if (('' !== $id) AND (0 == $i % 50))
			{
				array_push($new_lines, '', "/* {$id} */", '');
			}
			++$i;
			$new_lines[] = self::_add_note($line, $i, $in_comment, $pad_to);
			$in_comment = self::_eol_in_comment($line, $in_comment);
		}
		$content = implode("\n", $new_lines) . "\n";

		// check for desired URI rewriting
		if (isset($options['current_dir']))
		{
			Minify_CSS_Uri_Rewriter::$debug_text = '';
			$content = Minify_CSS_Uri_Rewriter::rewrite(
				 $content
				,$options['current_dir']
				,isset($options['docRoot']) ? $options['docRoot'] : $_SERVER['DOCUMENT_ROOT']
				,isset($options['symlinks']) ? $options['symlinks'] : array()
			);
			$content = "/* Minify_CSS_Uri_Rewriter::\$debug_text\n\n" 
					 . Minify_CSS_Uri_Rewriter::$debug_text . "*/\n"
					 . $content;
		}
		
		return $content;
	}
	
	/**
	 * Is the parser within a C-style comment at the end of this line?
	 *
	 * @param string $line current line of code
	 * 
	 * @param bool $in_comment was the parser in a comment at the
	 * beginning of the line?
	 *
	 * @return bool
	 */
	private static function _eol_in_comment($line, $in_comment)
	{
		while (strlen($line))
		{
			$search = $in_comment
				? '*/'
				: '/*';
			$pos = strpos($line, $search);
			if (FALSE === $pos)
			{
				return $in_comment;
			}
			else
			{
				if ($pos == 0
					OR ($in_comment
						? substr($line, $pos, 3)
						: substr($line, $pos-1, 3)) != '*/*')
				{
					$in_comment = ! $in_comment;
				}
				$line = substr($line, $pos + 2);
			}
		}
		return $in_comment;
	}
	
	/**
	 * Prepend a comment (or note) to the given line
	 *
	 * @param string $line current line of code
	 *
	 * @param string $note content of note/comment
	 * 
	 * @param bool $in_comment was the parser in a comment at the
	 * beginning of the line?
	 *
	 * @param int $pad_to minimum width of comment
	 * 
	 * @return string
	 */
	private static function _add_note($line, $note, $in_comment, $pad_to)
	{
		return $in_comment
			? '/* ' . str_pad($note, $pad_to, ' ', STR_PAD_RIGHT) . ' *| ' . $line
			: '/* ' . str_pad($note, $pad_to, ' ', STR_PAD_RIGHT) . ' */ ' . $line;
	}
} // End Kohana_Minify_CSS_Lines
