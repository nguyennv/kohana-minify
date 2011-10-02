<?php defined('SYSPATH') or die('No direct script access.');
ini_set('zlib.output_compression', '0');

Route::set('minify_js_default', 'min/js(/<group>)')
	->defaults(array(
		'controller' => 'minify',
		'action' => 'js'
	));

Route::set('minify_js', 'min/js(/<group>).js')
	->defaults(array(
		'controller' => 'minify',
		'action' => 'js'
	));

Route::set('minify_css_default', 'min/css(/<group>)')
	->defaults(array(
		'controller' => 'minify',
		'action' => 'css'
	));

Route::set('minify_css', 'min/css(/<group>).css')
	->defaults(array(
		'controller' => 'minify',
		'action' => 'css'
	));