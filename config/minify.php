<?php defined('SYSPATH') or die('No direct script access.');

return array(
	'cache' => Kohana::$environment === Kohana::PRODUCTION,
	'cache_lifetime' => 43200,
	'expires' => 31536000,

	'js' => array(
		'default' => array(
			'minifier' => 'Minify_JS',
			'packer' => FALSE,
			'is_min' => TRUE,
			'path' => DOCROOT . 'scripts',
			'files' => array(),
		),
	),

	'css' => array(
		'default' => array(
			'minifier' => 'Minify_CSS',
			'path' => DOCROOT . 'contents',
			'files' => array(),
		),
	),
);