<?php

namespace RM\MailPanel\DI;

use Nette\DI\Configurator;
use Nette\DI\Compiler;
use Nette\DI\CompilerExtension;
use Nette\DI\Helpers;
use Nette\DI\ServiceDefinition;


/**
 * Nette DI extension for FileMailer and MailPanel.
 *
 * @author    Roman Mátyus
 * @copyright (c) Roman Mátyus 2012
 * @license   MIT
 */
class MailPanelExtension extends CompilerExtension
{
	/** @var [] */
	public $defaults = array(
		'newMessageTime' => '-2 seconds',
		'show' => array('subject', 'from', 'to'),
		'autoremove' => '-5 seconds',
		'hideEmpty' => TRUE,
		'tempDir' => '%tempDir%/mails',
		'debugger' => TRUE,
	);


	/**
	 * Method setings extension.
	 */
	public function beforeCompile()
	{
		$builder = $this->getContainerBuilder();

		$this->validateConfig($this->defaults);
		$config = $this->getConfig($this->defaults);

		foreach ($builder->findByType('Nette\Mail\IMailer') as $name => $def) {
			$builder->removeDefinition($name);
		}

		if ($config['debugger'] && interface_exists('Tracy\IBarPanel')) {
			$builder->addDefinition($this->prefix('panel'))
				->setClass('RM\MailPanel')
				->addSetup('$newMessageTime', array($config['newMessageTime']))
				->addSetup('$show', array(array_unique($config['show'])))
				->addSetup('$autoremove', array($config['autoremove']))
				->addSetup('$hideEmpty', array($config['hideEmpty']));
		}

		$mailer = $builder->addDefinition($this->prefix('mailer'))
			->setClass('RM\FileMailer')
			->addSetup('$tempDir', array(Helpers::expand($config['tempDir'], $builder->parameters)))
			->addSetup('@RM\MailPanel::setFileMailer', array('@self'))
			->addSetup('@Tracy\Bar::addPanel', array($this->prefix('@panel')));
	}


	/**
	 * Register extension to DI Container.
	 * @param Configurator $config
	 */
	public static function register(Configurator $config)
	{
		$config->onCompile[] = function (Configurator $config, Compiler $compiler) {
			$compiler->addExtension('mailPanel', new MailPanelExtension());
		};
	}

}
