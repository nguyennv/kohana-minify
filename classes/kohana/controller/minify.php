<?php defined('SYSPATH') or die('No direct script access.');

class Kohana_Controller_Minify extends Kohana_Controller{
	private $_config;

	public function action_js()
	{
		$group = (string) $this->request->param('group');
		if(empty($group)) $group = 'default';
		if(!$content = Kohana::cache('minify::js::' . $group))
		{
			$path = isset($this->_config['js'][$group]['path']) ? $this->_config['js'][$group]['path'] : '';
			$files = isset($this->_config['js'][$group]['files']) ? $this->_config['js'][$group]['files'] : array();
			if(!is_array($files)) $files = array();

			$content = '';
			foreach($files as $file)
			{
				$content .= file_get_contents($path . DIRECTORY_SEPARATOR . $file) . "\n";
			}
			if(!empty($content))
			{
				$pack = isset($this->_config['js'][$group]['packer']) ? (bool) $this->_config['js'][$group]['packer'] : false;
				$is_min = isset($this->_config['js'][$group]['is_min']) ? (bool) $this->_config['js'][$group]['is_min'] : false;
				if($pack)
				{
					$packer = new Minify_Packer($content, 'Normal', TRUE, FALSE);
					$content = $packer->pack();
				}
				else if(!$is_min)
				{
					$minifier = isset($this->_config['js'][$group]['minifier']) ? $this->_config['js'][$group]['minifier'] : '';
					if(!empty($minifier) && class_exists($minifier))
					{
						$class = new ReflectionClass($minifier);
						$js_min = $class->newInstance($content);
						$content = $class->getMethod('min')->invoke($js_min);
					}
					else
					{
						$content = Minify_JS::minify($content);
					}
				}
			}

			if((bool) $this->_config['cache'])
			{
				Kohana::cache('minify::js::' . $group, $content, (int) $this->_config['cache_lifetime']);
			}
		}
		$this->response->body($content);
	}

	public function action_css()
	{
		$group = (string) $this->request->param('group');
		if(empty($group)) $group = 'default';
		if(!$content = Kohana::cache('minify::css::' . $group))
		{
			$path = isset($this->_config['css'][$group]['path']) ? $this->_config['css'][$group]['path'] : '';
			$files = isset($this->_config['css'][$group]['files']) ? $this->_config['css'][$group]['files'] : array();
			if(!is_array($files)) $files = array();

			$content = '';
			foreach($files as $file)
			{
				$content .= file_get_contents($path . DIRECTORY_SEPARATOR . $file);
			}

			if(!empty($content))
			{
				$minifier = isset($this->_config['css'][$group]['minifier']) ? $this->_config['css'][$group]['minifier'] : '';
				if(class_exists($minifier))
				{
					$class = new ReflectionClass($minifier);
					$css_min = $class->newInstance($content, array('current_dir' => $path));
					$content = $class->getMethod('min')->invoke($css_min);
				}
				else
				{
					$content = Minify_CSS::minify($content, array('current_dir' => $path));
				}
			}

			if((bool) $this->_config['cache'])
			{
				Kohana::cache('minify::css::' . $group, $content, (int) $this->_config['cache_lifetime']);
			}
		}
		$this->response->body($content);
	}

	public function before()
	{
		$this->_config = Kohana::$config->load('minify');
	}
	
	public function after()
	{
		switch(strtolower($this->request->action()))
		{
			case 'css':
				$this->_headers('text/css');
				break;
			case 'js':
				$this->_headers('application/javascript');
				break;
			default:
				$this->_headers('text/html');
				break;
		}
	}

	private function _headers($ctype)
	{
		$group = (string) $this->request->param('group');
		if(!$etag = Kohana::cache('minify_cache_etag_' . $ctype . $group))
		{
			$etag = $this->request->generate_etag();
			Kohana::cache('minify_cache_etag_' . $ctype . $group, $etag);
		}
		$this->response->headers('Content-Type', $ctype)->check_cache($etag, $this->request);
	}
} // End Kohana_Controller_Minify
