<?php defined('SYSPATH') or die('No direct script access.');
/**
 * Minify CSS
 *
 * This class uses Kohana_Minify_Js_Min_CSS_Compressor and Kohana_Minify_Js_Min_CSS_UriRewriter to 
 * minify CSS and rewrite relative URIs.
 * 
 * @package Minify
 * @author Stephen Clay <steve@mrclay.org>
 * @author http://code.google.com/u/1stvamp/ (Issue 64 patch)
 */
class Kohana_Minify_CSS {

	private $_css;
	private $_options = array();

	public function __construct($css, array $options = array())
	{
		$this->_css     = $css;
		$this->_options = $options;
	}

	public function min()
	{
		$this->_options = array_merge(array(
			'remove_charsets' => true,
			'preserve_comments' => true,
			'current_dir' => null,
			'doc_root' => $_SERVER['DOCUMENT_ROOT'],
			'prepend_relative_path' => null,
			'symlinks' => array(),
		), $this->_options);
		
		if ($this->_options['remove_charsets'])
		{
			$this->_css = preg_replace('/@charset[^;]+;\\s*/', '', $this->_css);
		}

		if (! $this->_options['preserve_comments'])
		{
			$this->_css = Minify_CSS_Compressor::process($this->_css, $this->_options);
		}
		else
		{

			$this->_css = Minify_CSS_Comment_Preserver::process(
				$this->_css
				,array('Minify_CSS_Compressor', 'process')
				,array($this->_options)
			);
		}
		if (! $this->_options['current_dir'] && ! $this->_options['prepend_relative_path'])
		{
			return $this->_css;
		}

		if ($this->_options['current_dir'])
		{
			return Minify_CSS_Uri_Rewriter::rewrite(
				$this->_css
				,$this->_options['current_dir']
				,$this->_options['doc_root']
				,$this->_options['symlinks']
			);  
		}
		else
		{
			return Minify_CSS_Uri_Rewriter::prepend(
				$this->_css
				,$this->_options['prepend_relative_path']
			);
		}
	}
	
	/**
	 * Minify a CSS string
	 * 
	 * @param string $css
	 * 
	 * @param array $options available options:
	 * 
	 * 'preserveComments': (default true) multi-line comments that begin
	 * with "/*!" will be preserved with newlines before and after to
	 * enhance readability.
	 *
	 * 'removeCharsets': (default true) remove all @charset at-rules
	 * 
	 * 'prependRelativePath': (default null) if given, this string will be
	 * prepended to all relative URIs in import/url declarations
	 * 
	 * 'currentDir': (default null) if given, this is assumed to be the
	 * directory of the current CSS file. Using this, minify will rewrite
	 * all relative URIs in import/url declarations to correctly point to
	 * the desired files. For this to work, the files *must* exist and be
	 * visible by the PHP process.
	 *
	 * 'symlinks': (default = array()) If the CSS file is stored in 
	 * a symlink-ed directory, provide an array of link paths to
	 * target paths, where the link paths are within the document root. Because 
	 * paths need to be normalized for this to work, use "//" to substitute 
	 * the doc root in the link paths (the array keys). E.g.:
	 * <code>
	 * array('//symlink' => '/real/target/path') // unix
	 * array('//static' => 'D:\\staticStorage')  // Windows
	 * </code>
	 *
	 * 'doc_root': (default = $_SERVER['DOCUMENT_ROOT'])
	 * see Minify_CSS_Uri_Rewriter::rewrite
	 * 
	 * @return string
	 */
	public static function minify($css, array $options = array()) 
	{
		static $instance;
		// this is a singleton
		if(!$instance)
			$instance = new Minify_CSS($css, $options);

		return $instance->min();
	}
}
