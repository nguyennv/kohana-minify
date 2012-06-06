<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Minify Javascript using Google's Closure Compiler API
 *
 * @link http://code.google.com/closure/compiler/
 * @package Minify
 * @author Stephen Clay <steve@mrclay.org>
 *
 * @todo can use a stream wrapper to unit test this?
 */
class Kohana_Minify_JS_Closure_Compiler {
	const URL = 'http://closure-compiler.appspot.com/compile';

	/**
	 * Minify Javascript code via HTTP request to the Closure Compiler API
	 *
	 * @param string $js input code
	 * @param array $options unused at this point
	 * @return string
	 */
	public static function minify($js, array $options = array())
	{
		$compiler = new Minify_JS_Closure_Compiler($options);
		return $compiler->min($js);
	}

	/**
	 *
	 * @param array $options
	 *
	 * fallbackFunc : default array($this, 'fallback');
	 */
	public function __construct(array $options = array())
	{
		$this->_fallback_func = isset($options['fallback_minifier'])
			? $options['fallback_minifier']
			: array($this, '_fallback');
	}

	public function min($js)
	{
		$post_body = $this->_build_post_body($js);
		$bytes = (function_exists('mb_strlen') AND ((int)ini_get('mbstring.func_overload') & 2))
			? mb_strlen($post_body, '8bit')
			: strlen($post_body);
		if ($bytes > 200000)
		{
			throw new Minify_Js_Closure_Exception(
				'POST content larger than 200000 bytes'
			);
		}
		$response = $this->_get_response($post_body);
		if (preg_match('/^Error\(\d\d?\):/', $response))
		{
			if (is_callable($this->_fallback_func))
			{
				$response = "/* Received errors from Closure Compiler API:\n$response"
						  . "\n(Using fallback minifier)\n*/\n";
				$response .= call_user_func($this->_fallback_func, $js);
			}
			else
			{
				throw new Minify_Js_Closure_Exception($response);
			}
		}
		if ($response === '')
		{
			$errors = $this->_get_response($this->_build_post_body($js, TRUE));
			throw new Minify_Js_Closure_Exception($errors);
		}
		return $response;
	}
	
	protected $_fallback_func = NULL;

	protected function _get_response($post_body)
	{
		$allow_url_fopen = preg_match('/1|yes|on|true/i', ini_get('allow_url_fopen'));
		if ($allow_url_fopen)
		{
			$contents = file_get_contents(self::URL, FALSE, stream_context_create(array(
				'http' => array(
					'method' => 'POST',
					'header' => 'Content-type: application/x-www-form-urlencoded',
					'content' => $post_body,
					'max_redirects' => 0,
					'timeout' => 15,
				)
			)));
		}
		else if (defined('CURLOPT_POST'))
		{
			$ch = curl_init(self::URL);
			curl_setopt($ch, CURLOPT_POST, TRUE);
			curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
			curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-type: application/x-www-form-urlencoded'));
			curl_setopt($ch, CURLOPT_POSTFIELDS, $post_body);
			curl_setopt($ch, CURLOPT_FOLLOWLOCATION, FALSE);
			curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 15);
			$contents = curl_exec($ch);
			curl_close($ch);
		}
		else
		{
			throw new Minify_Js_Closure_Exception(
			   "Could not make HTTP request: allow_url_open is false and cURL not available"
			);
		}
		if (FALSE === $contents)
		{
			throw new Minify_Js_Closure_Exception(
			   "No HTTP response from server"
			);
		}
		return trim($contents);
	}

	protected function _build_post_body($js, $return_errors = FALSE)
	{
		return http_build_query(array(
			'js_code' => $js,
			'output_info' => ($return_errors ? 'errors' : 'compiled_code'),
			'output_format' => 'text',
			'compilation_level' => 'SIMPLE_OPTIMIZATIONS'
		), null, '&');
	}

	/**
	 * Default fallback function if CC API fails
	 * @param string $js
	 * @return string
	 */
	protected function _fallback($js)
	{
		return Minify_Js::minify($js);
	}
}
