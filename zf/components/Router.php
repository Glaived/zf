<?php

namespace zf\components;

class Router
{
	private $rules = [];
	private $module;

	public function module($module)
	{
		$this->module = $module;
	}

	public function append($method, $pattern, $handlers)
	{
		$this->rules[strtoupper($method)][] = [$pattern, $handlers, $this->module];
	}

	public function bulk($rules)
	{
		foreach($rules as $rule)
		{
			list($method, $path, $handlers) = $rule;
			$this->append($method, $path, $handlers);
		}
	}

	public function parse($pattern)
	{
		preg_match_all('/:([^\/?]+)/', $pattern, $names);
		$regexp = preg_replace(['(\/[^:\\/?]+)','(\/:[^\\/?\\(]+)'], ['(?:\0)','(?:/([^/?]+))'], $pattern);
		return [$names[1], '/^'.str_replace('/','\/', $regexp).'$/'];
	}

	public function match($pattern, $path)
	{
		list($names, $regexp) = $this->parse($pattern);
		if(preg_match($regexp, $path, $values))
		{
			foreach($names as $idx=>$name)
			{
				$params[$name] = isset($values[$idx+1]) ? $values[$idx+1] : null;
			}
			return $params;
		}
	}

	public function dispatch($method, $path)
	{
		if(!isset($this->rules[$method])) return [null, null];

		foreach($this->rules[$method] as $rule)
		{
			list($pattern, $handlers, $module) = $rule;

			if(false === strpos($pattern, '/:')) # static pattern
			{
				if($path === $pattern)
				{
					return [$handlers, null, $module];
				}
			}
			else
			{
				$staticPrefix = strstr($pattern, '/:', true);
				if(!$staticPrefix || !strncmp($staticPrefix, $path, strlen($staticPrefix)))
				{
					if($params = $this->match($pattern, $path))
					{
						return [$handlers, $params, $module];
					}
				}
			}
		}
		return [null, null, null];
	}

	public function run()
	{
		return $this->dispatch($_SERVER['REQUEST_METHOD'], parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));
	}
}
