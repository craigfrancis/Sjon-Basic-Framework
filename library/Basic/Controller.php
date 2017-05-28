<?php

class Basic_Controller
{
	public function init()
	{
		if ('cli' == PHP_SAPI)
			self::_initRequestGlobalCli();
		else
		{
			ob_start();
			self::_initRequestGlobal();
		}

		foreach (Basic::$config->Userinput as $name => $config)
			Basic::$userinput->$name = (array)$config;

		self::_initAction();

		Basic::$log->start(get_class(Basic::$action) .'::init');
		Basic::$action->init();
		Basic::$log->end();
	}

	protected static function _initRequestGlobal()
	{
		$base = trim(Basic::$config->Site->baseUrl, '/');
		$offset = ($base == '' ? 0 : count(explode('/', $base)));

		// If nginx redirects (eg by `error 405;`) the request, REQUEST_URI would still contain the original req
		// However, DOCUMENT_URI is garbled/decoded, meaning we cannot properly process REQ=/test/%2Fa%20b, DOC=/test/a b (yes, /%2F is dedupped :( )
		#FIXME?
		if (false === strpos($_SERVER['DOCUMENT_URI'], '/error/'))
			$path = ltrim($_SERVER['REQUEST_URI'], '/');
		else
			$path = ltrim($_SERVER['DOCUMENT_URI'], '/');

		if (false !== strpos($path, '?'))
			$path = strstr($path, '?', true);

		$_REQUEST = [];

		if ($path == '')
			return;

		foreach (explode('/', $path) as $idx => $value)
			$_REQUEST[ $idx - $offset ] = ('' == $value) ? null : $value;
	}

	protected static function _initRequestGlobalCli()
	{
		// Default action is most likely text/html; so present a simple menu of available actions instead
		if (1 == $_SERVER['argc'])
			die('Missing action as first argument. Actions available:'."\n* ". implode("\n* ", array_map(function($f){ return lcfirst(basename($f, '.php'));}, glob('library/*/Action/Cli/*.php'))) ."\n");

		$_REQUEST = [];

		foreach ($_SERVER['argv'] as $idx => $value)
			$_REQUEST[ $idx - 1 ] = ('' == $value) ? null : $value;

		$_REQUEST[0] = 'cli_'. $_REQUEST[0];
	}

	protected static function _initAction()
	{
		Basic::$log->start();

		$class = Basic::$config->APPLICATION_NAME .'_Action_'. ucwords(Basic::$userinput['action'], '_');
		$hasClass = class_exists($class);

		if (!$hasClass)
		{
			$class = Basic::$config->APPLICATION_NAME .'_Action';

			if (!class_exists($class))
				$class = 'Basic_Action';
		}

		Basic::$template->setExtension(explode('/', get_class_vars($class)['contentType'])[1]);
		$hasTemplate = Basic::$template->templateExists(Basic::$userinput['action']);

		try
		{
			$newAction = $class::resolve(Basic::$userinput['action'], $hasClass, $hasTemplate);
		}
		catch (Basic_Action_InvalidActionException $e)
		{
			// We need an action to render the exception
			Basic::$action = new $class;

			throw $e;
		}

		if (isset($newAction) && $newAction != Basic::$userinput['action'])
		{
			Basic::$log->end(Basic::$userinput['action'] .' > '. $newAction);
			Basic::$userinput->action->setValue($newAction);

			return self::_initAction();
		}

		Basic::$action = new $class;

		if (!Basic::$action instanceof Basic_Action)
			throw new Basic_Controller_MissingMethodsException('Class `%s` must extend Basic_Action', array($class));

		Basic::$log->end(Basic::$userinput['action'] .': '. $class);
	}

	public function run()
	{
		Basic::$log->start();

		if (Basic::$userinput->isValid())
			Basic::$action->run();
		elseif ('html' == Basic::$template->getExtension())
		{
			if ('POST' == $_SERVER['REQUEST_METHOD'])
				http_response_code(400);

			print Basic::$userinput->getHtml();
		}
		else
		{
			$missing = [];
			foreach (Basic::$userinput as $name => $value)
				if (!$value->isValid())
					array_push($missing, $name);

			throw new Basic_Controller_MissingRequiredParametersException('Missing required input: `%s`', array(implode('`, `', $missing)), 400);
		}

		Basic::$log->end();
	}

	public function end()
	{
		Basic::$action->end();
	}

	public function redirect($action = null, $permanent = false)
	{
		if ('cli' == PHP_SAPI)
			throw new Basic_Controller_CliRedirectException('Application attempted to redirect you to `%s`', [$action]);

		// Remove any output, our goal is quick redirection
		ob_end_clean();

		if (!isset($action) && !empty($_SERVER['HTTP_REFERER']))
			$action = $_SERVER['HTTP_REFERER'];
		elseif (false === strpos($action, '://'))
			$action = Basic::$action->baseHref . $action;

		if (!headers_sent())
			header('Location: '.$action, true, $permanent ? 301 : 302);
		else
			echo '<script type="text/javascript">window.location = "'.$action.'";</script>Redirecting you to <a href="'. $action .'">'. $action .'</a>';

		// Prevent any output
		ob_start();
		$this->end();
		ob_end_clean();

		die();
	}
}