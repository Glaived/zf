<?php

namespace zf;

use Phar;
use Closure;
use Exception;
use ReflectionClass;
use FilesystemIterator;

class App extends Laziness
{
	use Request;
	use Response;
	use EventEmitter;

	private $_router;
	private $_eagerParams = [];

	function __construct()
	{
		parent::__construct();

		$this->isCli = 'cli' == PHP_SAPI;
		$basedir = $this->isCli ? dirname(realpath($_SERVER['argv'][0])) : $_SERVER['DOCUMENT_ROOT'];
		set_include_path(get_include_path() . PATH_SEPARATOR . $basedir);

		$this->config = new Config;
		$this->set(['nodebug', 'nopretty', 'fancy',
			'handlers'   => 'handlers',
			'helpers'    => 'helpers',
			'params'     => 'params',
			'views'      => 'views',
			'validators' => 'validators',
			'mappers'    => 'mappers',
			'viewext'    => '.php',
			'charset'    => 'utf-8',
			'basedir'    => $basedir,
		]);
		$this->config->load('configs.php', true);
		if(getenv('ENV'))
			$this->config->load('configs-'.getenv('ENV').'.php', true);

		$this->_router = $this->isCli ? new CliRouter() : new Router();

		$global_exception_handler = function($exception) {
			if(!$this->emit('exception', $exception)) throw $exception;
		};
		set_exception_handler($global_exception_handler->bindTo($this));

		$this->helper = function(){
			return new ClosureSet($this, $this->config->helpers);
		};
		$this->requestHandlers = function(){
			return new ClosureSet($this, $this->config->handlers);
		};
		$this->paramHandlers = function(){
			return new ClosureSet($this, $this->config->params);
		};
		$this->validators = function(){
			return new ClosureSet($this, $this->config->validators);
		};
		$this->mappers = function(){
			return new ClosureSet($this, $this->config->mappers);
		};
	}

	function __call($name, $args)
	{
		if (in_array($name, ['get', 'post', 'put', 'delete', 'patch', 'head', 'cmd'], true))
		{
			$this->_router->append($name, $args);
		}
		elseif ($this->helper->registered($name))
		{
			return $this->helper->__call($name, $args);
		}
		elseif ($this->isCli && (0 == strncmp('sig', $name, 3)))
		{
			$name = strtoupper($name);
			if (defined($name))
			{
				pcntl_signal(constant($name), $args[0]->bindTo($this));
			}
			else
			{
				throw new Exception("signal \"$name\" not found");
			}
		}
		else
		{
			throw new Exception("method \"$name\" not found");
		}
		return $this;
	}

	public function set($name, $value=null)
	{
		1 == func_num_args()
			? $this->config->set($name)
			: $this->config->set($name, $value);
		return $this;
	}

	public function param($name, $handler=null)
	{
		$this->_paramsTmp = $this->paramHandlers->register($name, $handler);
		return $this;
	}

	public function eager()
	{
		if(is_array($this->_paramsTmp))
		{
			1 == count($this->_paramsTmp)
				? $this->_eagerParams[] = $this->_paramsTmp[0]
				: $this->_eagerParams = array_merge($this->_eagerParams, $this->_paramsTmp);
		}
		return $this;
	}

	public function helper($name, $closure=null)
	{
		$this->helper->register($name, $closure);
		return $this;
	}

	public function handler($name, $closure)
	{
		$this->requestHandlers->register($name, $closure);
		return $this;
	}

	public function validator($name, $closure)
	{
		$this->validators->register($name, $closure);
		return $this;
	}

	public function map($type, $closure=null)
	{
		$this->mappers->register($type, $closure);
		return $this;
	}

	public function register($alias, $component)
	{
		if($component instanceof Closure)
		{
			$this->$alias = $component;
		}
		else
		{
			$constructArgs = array_slice(func_get_args(), 2);
			$this->$alias = function() use ($component, $constructArgs, $alias){
				if(empty($constructArgs) && isset($this->config->$alias))
				{
					$constructArgs = $this->config->$alias;
				}
				return is_array($constructArgs) && !is_assoc($constructArgs)
					? (new ReflectionClass($component))->newInstanceArgs($constructArgs)
					: (new ReflectionClass($component))->newInstance($constructArgs);
			};
		}
		return $this;
	}

	public function pass($handlerName)
	{
		$this->requestHandlers->__call($handlerName);
	}

	public function run()
	{
		$this->requestMethod = $this->isCli ? 'CLI' : strtoupper($_SERVER['REQUEST_METHOD']);

		if($this->isCli)
		{
			$this->phar();
		}

		list($handler, $params) = $this->_router->run();
		if($handler)
		{
			if ($this->config->fancy)
			{
				$this->validators->register(require __DIR__ . DIRECTORY_SEPARATOR . 'validators.php');
				$this->mappers->register(require __DIR__ . DIRECTORY_SEPARATOR . 'mappers.php');
			}
			$this->params = new Laziness($params, $this);
			if($params) $this->processParamsHandlers($params);
			if(!$this->isCli)
			{
				if($_GET) $this->processParamsHandlers($_GET);
				$this->processRequestBody($this->config->fancy);
			}
			if(is_string($handler))
			{
				$this->requestHandlers->__call($handler);
			}
			else
			{
				$handler = $handler->bindTo($this);
				$handler();
			}
		}
		else
		{
			$this->notFound();
		}
	}

	public function defaults($defaults)
	{
		$this->_router->attach($defaults);
		return $this;
	}

	public function path()
	{
		return $this->config->basedir.DIRECTORY_SEPARATOR.implode(DIRECTORY_SEPARATOR, func_get_args());
	}

	private function processParamsHandlers($input)
	{
		foreach($input as $name => $value)
		{
			if ($this->paramHandlers->registered($name))
			{
				$args = [$value];
				$this->params->$name = in_array($name, $this->_eagerParams, true)
					? $this->paramHandlers->__call($name, $args)
					: $this->paramHandlers->delayed->__call($name, $args);
			}
		}
	}

	private function phar()
	{
		if('.phar' == substr($_SERVER['SCRIPT_FILENAME'], -5))
		{
			$this->cmd('extract <path>', function(){
				try {
					$phar = new Phar($_SERVER['SCRIPT_FILENAME']);
					$phar->extractTo($this->params->path, null, true);
				} catch (Exception $e) {
					echo $e->getMessage();
					exit(1);
				}
			});
		}
		else
		{
			$this->cmd('dist <name>', function(){
				$entryScript = basename($_SERVER['SCRIPT_FILENAME']);
				$phar = new Phar($this->params->name, FilesystemIterator::CURRENT_AS_FILEINFO | FilesystemIterator::KEY_AS_FILENAME, $this->params->name);
				$phar->buildFromDirectory($this->config->basedir, '/\.php$/');
				$phar->setStub($phar->createDefaultStub($entryScript));
			});
		}
	}

}

function is_assoc($array) {
	return (bool)count(array_filter(array_keys($array), 'is_string'));
}
