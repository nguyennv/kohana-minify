<?php defined('SYSPATH') or die('No direct script access.');

return array(
	'cache' => TRUE,// Kohana::$environment === Kohana::PRODUCTION,
	'cache_lifetime' => 43200,
	'expires' => 31536000,
	'compression' => TRUE,

	'js' => array(
		'login' => array(
			'packer' => TRUE,
			'path' => DOCROOT . 'scripts',
			'files' => array(
				'jquery.js',
				'jquery-validate.js',
				'md5.js',
			),
		),
		'admin' => array(
			'packer' => TRUE,
			'path' => DOCROOT . 'scripts',
			'files' => array(
				'jquery.js',
				'jquery-validate.js',
				'jquery-inputValue.js',
				'jquery-maskedinput.js',
				'jquery-menu.js',
			),
		),
		'uploadify' => array(
			'packer' => TRUE,
			'path' => DOCROOT . 'scripts',
			'files' => array(
				'swfobject.js',
				'jquery-uploadify/uploadify.js',
				'jquery-lightbox/js/jquery.lightbox.js',
			),
		),		
		'front' => array(
			'packer' => FALSE,
			'path' => DOCROOT . 'scripts',
			'files' => array(
				'jquery.js',
				'jquery-ui.js',
				'jquery-validate.js',
				'jquery-inputValue.js',
				'bxGallery/jquery.bxGallery.js',
				'fancybox/jquery.fancybox.js',
				'jquery.equalheights.js',
				'presente.js',
				'imagepreloader.js',
			),
		),
		'tiny_mce' => array(
			'packer' => FALSE,
			'path' => DOCROOT . 'scripts/tiny_mce',
			'files' => array(
				'tiny_mce.js',
			),
		),
	),

	'css' => array(
		'login' => array(
			'path' => DOCROOT . 'contents/admin/css',
			'files' => array(
				'system.css',
				'general.css',
				'login.css',
				'rounded.css',
			),
		),
		'uploadify' => array(
			'path' => DOCROOT . 'scripts',
			'files' => array(
				'jquery-uploadify/uploadify.css',
				'jquery-lightbox/css/jquery.lightbox.css',
			),
		),
		'admin' => array(
			'path' => DOCROOT . 'contents/admin/css',
			'files' => array(
				'system.css',
				'general.css',
				'icon.css',
				'menu.css',
				'component.css',
				'rounded.css',
			),
		),
		'front' => array(
			'path' => DOCROOT . 'contents/front',
			'files' => array(
				'960_60_col.css',
				'jquery-ui.css',
				'stylesheet.css',
				'constants.css',
				'style.css',
				'style_boxes.css',
				'css3.css',
				'fancybox.css',
			),
		),
	),
);