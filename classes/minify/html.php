<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Compress HTML
 *
 * This is a heavy regex-based removal of whitespace, unnecessary comments and 
 * tokens. IE conditional comments are preserved. There are also options to have
 * STYLE and SCRIPT blocks compressed by callback functions. 
 * 
 * A test suite is available.
 * 
 * @package Minify
 * @author Stephen Clay <steve@mrclay.org>
 */
class Minify_HTML {

	/**
	 * "Minify" an HTML page
	 *
	 * @param string $html
	 *
	 * @param array $options
	 *
	 * 'css_minifier' : (optional) callback function to process content of STYLE
	 * elements.
	 * 
	 * 'js_minifier' : (optional) callback function to process content of SCRIPT
	 * elements. Note: the type attribute is ignored.
	 * 
	 * 'xhtml' : (optional boolean) should content be treated as XHTML1.0? If
	 * unset, minify will sniff for an XHTML doctype.
	 * 
	 * @return string
	 */
	public static function minify($html, $options = array())
	{
		$min = new Minify_HTML($html, $options);
		return $min->process();
	}

	/**
	 * Create a minifier object
	 *
	 * @param string $html
	 *
	 * @param array $options
	 *
	 * 'css_minifier' : (optional) callback function to process content of STYLE
	 * elements.
	 * 
	 * 'js_minifier' : (optional) callback function to process content of SCRIPT
	 * elements. Note: the type attribute is ignored.
	 * 
	 * 'xhtml' : (optional boolean) should content be treated as XHTML1.0? If
	 * unset, minify will sniff for an XHTML doctype.
	 * 
	 * @return null
	 */
	public function __construct($html, $options = array())
	{
		$this->_html = str_replace("\r\n", "\n", trim($html));
		if (isset($options['xhtml']))
		{
			$this->_is_xhtml = (bool)$options['xhtml'];
		}
		if (isset($options['css_minifier']))
		{
			$this->_css_minifier = $options['css_minifier'];
		}
		if (isset($options['js_minifier']))
		{
			$this->_js_minifier = $options['js_minifier'];
		}
	}

	/**
	 * Minify the markeup given in the constructor
	 * 
	 * @return string
	 */
	public function process()
	{
		if ($this->_is_xhtml === null)
		{
			$this->_is_xhtml = (false !== strpos($this->_html, '<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML'));
		}

		$this->_replacement_hash = 'MINIFYHTML' . md5($_SERVER['REQUEST_TIME']);
		$this->_placeholders = array();
		
		// replace SCRIPTs (and minify) with placeholders
		$this->_html = preg_replace_callback(
			'/(\\s*)(<script\\b[^>]*?>)([\\s\\S]*?)<\\/script>(\\s*)/i'
			,array($this, '_remove_script_cb')
			,$this->_html);
		
		// replace STYLEs (and minify) with placeholders
		$this->_html = preg_replace_callback(
			'/\\s*(<style\\b[^>]*?>)([\\s\\S]*?)<\\/style>\\s*/i'
			,array($this, '_remove_style_cb')
			,$this->_html);
		
		// remove HTML comments (not containing IE conditional comments).
		$this->_html = preg_replace_callback(
			'/<!--([\\s\\S]*?)-->/'
			,array($this, '_comment_cb')
			,$this->_html);
		
		// replace PREs with placeholders
		$this->_html = preg_replace_callback('/\\s*(<pre\\b[^>]*?>[\\s\\S]*?<\\/pre>)\\s*/i'
			,array($this, '_remove_pre_cb')
			,$this->_html);
		
		// replace TEXTAREAs with placeholders
		$this->_html = preg_replace_callback(
			'/\\s*(<textarea\\b[^>]*?>[\\s\\S]*?<\\/textarea>)\\s*/i'
			,array($this, '_remove_textarea_cb')
			,$this->_html);
		
		// trim each line.
		// @todo take into account attribute values that span multiple lines.
		$this->_html = preg_replace('/^\\s+|\\s+$/m', '', $this->_html);
		
		// remove ws around block/undisplayed elements
		$this->_html = preg_replace('/\\s+(<\\/?(?:area|base(?:font)?|blockquote|body'
			.'|caption|center|cite|col(?:group)?|dd|dir|div|dl|dt|fieldset|form'
			.'|frame(?:set)?|h[1-6]|head|hr|html|legend|li|link|map|menu|meta'
			.'|ol|opt(?:group|ion)|p|param|t(?:able|body|head|d|h||r|foot|itle)'
			.'|ul)\\b[^>]*>)/i', '$1', $this->_html);
		
		// remove ws outside of all elements
		$this->_html = preg_replace_callback(
			'/>([^<]+)</'
			,array($this, '_outside_tag_cb')
			,$this->_html);
		
		// use newlines before 1st attribute in open tags (to limit line lengths)
		$this->_html = preg_replace('/(<[a-z\\-]+)\\s+([^>]+>)/i', "$1\n$2", $this->_html);
		
		// fill placeholders
		$this->_html = str_replace(
			array_keys($this->_placeholders)
			,array_values($this->_placeholders)
			,$this->_html
		);
		return $this->_remove_whitespace($this->_html);
	}
	
	protected function _comment_cb($m)
	{
		return (0 === strpos($m[1], '[') || false !== strpos($m[1], '<!['))
			? $m[0]
			: '';
	}
	
	protected function _reserve_place($content)
	{
		$placeholder = '%' . $this->_replacement_hash . count($this->_placeholders) . '%';
		$this->_placeholders[$placeholder] = $content;
		return $placeholder;
	}

	protected $_is_xhtml = null;
	protected $_replacement_hash = null;
	protected $_placeholders = array();
	protected $_css_minifier = null;
	protected $_js_minifier = null;

	
	protected function _outside_tag_cb($m)
	{
		return '>' . preg_replace('/^\\s+|\\s+$/', ' ', $m[1]) . '<';
	}

	protected function _remove_whitespace($content)
	{
		return preg_replace(
					array('/\s+/', '/\s*\n\s*/', '/\s*\>\s*\<\s*/',),
					array(' ', "\n", '><',),
					$content
				);
	}
	
	protected function _remove_pre_cb($m)
	{
		return $this->_reserve_place($m[1]);
	}
	
	protected function _remove_textarea_cb($m)
	{
		return $this->_reserve_place($m[1]);
	}

	protected function _remove_style_cb($m)
	{
		$open_style = $m[1];
		$css = $m[2];
		// remove HTML comments
		$css = preg_replace('/(?:^\\s*<!--|-->\\s*$)/', '', $css);
		
		// remove CDATA section markers
		$css = $this->_remove_cdata($css);
		
		// minify
		$minifier = $this->_css_minifier
			? $this->_css_minifier
			: 'trim';
		$css = call_user_func($minifier, $css);
		
		return $this->_reserve_place($this->_needs_cdata($css)
			? "{$open_style}/*<![CDATA[*/{$css}/*]]>*/</style>"
			: "{$open_style}{$css}</style>"
		);
	}

	protected function _remove_script_cb($m)
	{
		$open_script = $m[2];
		$js = $m[3];
		
		// whitespace surrounding? preserve at least one space
		$ws1 = ($m[1] === '') ? '' : ' ';
		$ws2 = ($m[4] === '') ? '' : ' ';
 
		// remove HTML comments (and ending "//" if present)
		$js = preg_replace('/(?:^\\s*<!--\\s*|\\s*(?:\\/\\/)?\\s*-->\\s*$)/', '', $js);
			
		// remove CDATA section markers
		$js = $this->_remove_cdata($js);
		
		// minify
		$minifier = $this->_js_minifier
			? $this->_js_minifier
			: 'trim'; 
		$js = call_user_func($minifier, $js);
		
		return $this->_reserve_place($this->_needs_cdata($js)
			? "{$ws1}{$open_script}/*<![CDATA[*/{$js}/*]]>*/</script>{$ws2}"
			: "{$ws1}{$open_script}{$js}</script>{$ws2}"
		);
	}

	protected function _remove_cdata($str)
	{
		return (false !== strpos($str, '<![CDATA['))
			? str_replace(array('<![CDATA[', ']]>'), '', $str)
			: $str;
	}
	
	protected function _needs_cdata($str)
	{
		return ($this->_is_xhtml && preg_match('/(?:[<&]|\\-\\-|\\]\\]>)/', $str));
	}
}
