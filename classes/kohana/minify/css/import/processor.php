<?php

/**
 * Linearize a CSS/JS file by including content specified by CSS import
 * declarations. In CSS files, relative URIs are fixed.
 *
 * @imports will be processed regardless of where they appear in the source
 * files; i.e. @imports commented out or in string content will still be
 * processed!
 *
 * This has a unit test but should be considered "experimental".
 *
 * @package Minify
 * @author Stephen Clay <steve@mrclay.org>
 * @author Simon Schick <simonsimcity@gmail.com>
 */
class Kohana_Minify_CSS_Import_Processor {

	public static $files_included = array();

	public static function process($file)
	{
		self::$files_included = array();
		self::$_is_css = (strtolower(substr($file, -4)) === '.css');
		$obj = new Minify_CSS_Import_Processor(dirname($file));
		return $obj->_get_content($file);
	}

	// allows callback funcs to know the current directory
	private $_current_dir = NULL;

	// allows callback funcs to know the directory of the file that inherits this one
	private $_previews_dir = NULL;

	// allows _importCB to write the fetched content back to the obj
	private $_imported_content = '';

	private static $_is_css = NULL;

	/**
	 * @param String $current_dir
	 * @param String $previews_dir Is only used internally
	 */
	private function __construct($current_dir, $previews_dir = "")
	{
		$this->_current_dir = $current_dir;
		$this->_previews_dir = $previews_dir;
	}

	private function _get_content($file, $is_imported = FALSE)
	{
		$file = realpath($file);
		if (! $file
			OR in_array($file, self::$files_included)
			OR FALSE === ($content = @file_get_contents($file))
		)
		{
			// file missing, already included, or failed read
			return '';
		}
		self::$files_included[] = realpath($file);
		$this->_current_dir = dirname($file);

		// remove UTF-8 BOM if present
		if (pack("CCC",0xef,0xbb,0xbf) === substr($content, 0, 3))
		{
			$content = substr($content, 3);
		}
		// ensure uniform EOLs
		$content = str_replace("\r\n", "\n", $content);

		// process @imports
		$content = preg_replace_callback(
			'/
				@import\\s+
				(?:url\\(\\s*)?	  # maybe url(
				[\'"]?			   # maybe quote
				(.*?)				# 1 = URI
				[\'"]?			   # maybe end quote
				(?:\\s*\\))?		 # maybe )
				([a-zA-Z,\\s]*)?	 # 2 = media list
				;					# end token
			/x'
			,array($this, '_import_cb')
			,$content
		);

		// You only need to rework the import-path if the script is imported
		if (self::$_is_css AND $is_imported)
		{
			// rewrite remaining relative URIs
			$content = preg_replace_callback(
				'/url\\(\\s*([^\\)\\s]+)\\s*\\)/'
				,array($this, '_url_cb')
				,$content
			);
		}

		return $this->_imported_content . $content;
	}

	private function _import_cb($m)
	{
		$url = $m[1];
		$media_list = preg_replace('/\\s+/', '', $m[2]);

		if (strpos($url, '://') > 0)
		{
			// protocol, leave in place for CSS, comment for JS
			return self::$_is_css
				? $m[0]
				: "/* Minify_CSS_Import_Processor will not include remote content */";
		}
		if ('/' === $url[0])
		{
			// protocol-relative or root path
			$url = ltrim($url, '/');
			$file = realpath($_SERVER['DOCUMENT_ROOT']) . DIRECTORY_SEPARATOR
					. strtr($url, '/', DIRECTORY_SEPARATOR);
		}
		else
		{
			// relative to current path
			$file = $this->_current_dir . DIRECTORY_SEPARATOR
					. strtr($url, '/', DIRECTORY_SEPARATOR);
		}
		$obj = new Minify_CSS_Import_Processor(dirname($file), $this->_current_dir);
		$content = $obj->_get_content($file, TRUE);
		if ('' === $content) {
			// failed. leave in place for CSS, comment for JS
			return self::$_is_css
				? $m[0]
				: "/* Minify_CSS_Import_Processor could not fetch '{$file}' */";
		}
		return (!self::$_is_css || preg_match('@(?:^$|\\ball\\b)@', $media_list))
			? $content
			: "@media {$media_list} {\n{$content}\n}\n";
	}

	private function _url_cb($m)
	{
		// $m[1] is either quoted or not
		$quote = ($m[1][0] === "'" OR $m[1][0] === '"')
			? $m[1][0]
			: '';
		$url = ($quote === '')
			? $m[1]
			: substr($m[1], 1, strlen($m[1]) - 2);
		if ('/' !== $url[0])
		{
			if (strpos($url, '//') > 0)
			{
				// probably starts with protocol, do not alter
			}
			else
			{
				// prepend path with current dir separator (OS-independent)
				$path = $this->_current_dir
					. DIRECTORY_SEPARATOR . strtr($url, '/', DIRECTORY_SEPARATOR);
				// update the relative path by the directory of the file that imported this one
				$url = self::get_path_diff(realpath($this->_previews_dir), $path);
			}
		}
		return "url({$quote}{$url}{$quote})";
	}

	/**
	 * @param string $from
	 * @param string $to
	 * @param string $ps
	 * @return string
	 */
	private function get_path_diff($from, $to, $ps = DIRECTORY_SEPARATOR)
	{
		$real_from = $this->truepath($from);
		$real_to = $this->truepath($to);

		$ar_from = explode($ps, rtrim($real_from, $ps));
		$ar_to = explode($ps, rtrim($real_to, $ps));
		while (count($ar_from) AND count($ar_to) AND ($ar_from[0] == $ar_to[0]))
		{
			array_shift($ar_from);
			array_shift($ar_to);
		}
		return str_pad("", count($ar_from) * 3, '..' . $ps) . implode($ps, $ar_to);
	}

	/**
	 * This function is to replace PHP's extremely buggy realpath().
	 * @param string $path The original path, can be relative etc.
	 * @return string The resolved path, it might not exist.
	 * @see http://stackoverflow.com/questions/4049856/replace-phps-realpath
	 */
	function truepath($path)
	{
		// whether $path is unix or not
		$unipath = strlen($path) == 0 OR $path{0} != '/';
		// attempts to detect if path is relative in which case, add cwd
		if (strpos($path, ':') === FALSE AND $unipath)
			$path = $this->_current_dir . DIRECTORY_SEPARATOR . $path;

		// resolve path parts (single dot, double dot and double delimiters)
		$path = str_replace(array('/', '\\'), DIRECTORY_SEPARATOR, $path);
		$parts = array_filter(explode(DIRECTORY_SEPARATOR, $path), 'strlen');
		$absolutes = array();
		foreach ($parts as $part)
		{
			if ('.' == $part)
				continue;
			if ('..' == $part)
			{
				array_pop($absolutes);
			}
			else
			{
				$absolutes[] = $part;
			}
		}
		$path = implode(DIRECTORY_SEPARATOR, $absolutes);
		// resolve any symlinks
		if (file_exists($path) AND linkinfo($path) > 0)
			$path = readlink($path);
		// put initial separator that could have been lost
		$path = !$unipath ? '/' . $path : $path;
		return $path;
	}
}
