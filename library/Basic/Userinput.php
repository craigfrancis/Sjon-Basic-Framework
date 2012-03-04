<?php

class Basic_Userinput implements ArrayAccess, IteratorAggregate
{
	protected $_config = array();

	public function __construct()
	{
		foreach (Basic::$config->Userinput as $name => $config)
		{
			$config->source = (array)$config->source;
			$this->_config[$name] = (array)$config;
		}
	}

	public function init()
	{
		foreach ($this->_config as $name => $config)
			$this->$name = $config;
	}

	public function run()
	{
		foreach (Basic::$action->getUserinputConfig() as $name => $config)
			$this->$name = $config;
	}

	public function isValid()
	{
		foreach ($this as $value)
			if ('POST' == $value->source['superglobal'] && 'POST' != $_SERVER['REQUEST_METHOD'] || !$value->isValid())
				return false;

		return true;
	}

	public function __isset($name)
	{
		return false;
	}

	public function __get($name)
	{
		throw new Basic_Userinput_UndefinedException('The specified input `%s` is not configured', array($name));
	}

	public function __set($name, array $config)
	{
		if ('_' == $name[0])
			throw new Basic_Userinput_InvalidNameException('`%s` has an invalid name', array($name));

		$this->$name = new Basic_UserinputValue($name, $config);
	}

	public function __unset($name)
	{
		throw new Basic_Userinput_UndefinedException('The specified input `%s` is not configured', array($name));
	}

	protected function _getFormData()
	{
		if (substr($_SERVER['REQUEST_URI'], 0, strlen(Basic::$config->Site->baseUrl)) != Basic::$config->Site->baseUrl)
			throw new Basic_Userinput_IncorrectRequestUrlException('Current URL does not start with baseHref');

		$data = array(
			'method' => 'post',
			'action' => substr($_SERVER['REQUEST_URI'], strlen(Basic::$config->Site->baseUrl)),
			'inputs' => array(),
			'submitted' => ('POST' == $_SERVER['REQUEST_METHOD']),
		);

		foreach ($this as $name => $value)
		{
			if (!isset($value->inputType))
				continue;

			$input = array_merge($value->getConfig(), $value->getFormData());

			// Determine the state of the input
			if (!$value->isPresent(false) || ($input['validates'] || !$input['required']))
				$input['state'] = 'valid';
			else
				$input['state'] = 'invalid';

			// Special 'hack' for showing selects without keys
			if (!empty($value->values) && in_array('valuesToKeys', $value->options, true))
				$input['values'] = array_combine($value->values, $value->values);

			// When a file is uploaded, the form.enctype must be changed
			if ('file' == $input['inputType'])
				$data['containsFile'] = true;

			//FIXME: can't we assign to $value here? Would be nice, no getConfig needed plus less data copying
			$data['inputs'][ $name ] = $input;
		}

		if ('POST' == $_SERVER['REQUEST_METHOD'] && empty($data['inputs']))
			throw new Basic_Userinput_CannotCreateFormException('Missing data; cannot create a form');

		return $data;
	}

	public function createForm()
	{
		if ('html' != Basic::$template->getExtension())
		{
			$missing = array();
			foreach ($this as $name => $value)
				if (!$value->isValid())
					array_push($missing, $name);

			throw new Basic_Userinput_UnsupportedContentTypeException('ContentType `%s` is not supported, missing inputs: `%s`', array(Basic::$template->getExtension(), implode('`, `', $missing)));
		}

		// Make sure the templateparser can find the data
		Basic::$template->formData = $this->_getFormData();

		$classParts = array_map('ucfirst', explode('_', Basic::$controller->action));
		$paths = array();

		do
			array_push($paths, 'Userinput/'. implode('/', $classParts) .'/Form');
		while (null !== array_pop($classParts));

		array_push($paths, FRAMEWORK_PATH .'/templates/userinput_form');

		Basic::$template->showFirstFound($paths);
	}

	public function asArray($addGlobals = true)
	{
		$output = array();
		foreach ($this as $name => $value)
			if ($addGlobals || !$value->isGlobal())
				$output[$name] = $value->getValue();

		return $output;
	}

	// Accessing the Userinput as array will act as shortcut to the value
	public function offsetExists($name){		return $this->$name->isPresent();			}
	public function offsetGet($name){			return $this->$name->getValue();			}
	public function offsetSet($name, $value){	throw new Basic_NotSupportedException('');	}
	public function offsetUnset($name){			throw new Basic_NotSupportedException('');	}

	public function getIterator()
	{
		return new ArrayIterator($this);
	}
}