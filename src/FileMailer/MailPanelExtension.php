<?php

namespace RM\MailPanel\DI;

use Nette\Configurator;
use Nette\DI\Compiler;
use Nette\DI\CompilerExtension;
use Nette\DI\Helpers;


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
	public $defaults = [
		'newMessageTime' => '-2 seconds',
		'show' => ['subject', 'from', 'to'],
		'autoremove' => '-5 seconds',
		'hideEmpty' => TRUE,
		'tempDir' => '%tempDir%/mails',
		'debugger' => TRUE,
	];


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
				->addSetup('$newMessageTime', [$config['newMessageTime']])
				->addSetup('$show', [array_unique($config['show'])])
				->addSetup('$autoremove', [$config['autoremove']])
				->addSetup('$hideEmpty', [$config['hideEmpty']]);
		}

		$builder->addDefinition($this->prefix('mailer'))
			->setClass('RM\FileMailer')
			->addSetup('$tempDir', [Helpers::expand($config['tempDir'], $builder->parameters)])
			->addSetup('@RM\MailPanel::setFileMailer', ['@self'])
			->addSetup('@Tracy\Bar::addPanel', [$this->prefix('@panel')]);
	}


	/**
	 * Register extension to DI Container.
	 * @param  Configurator $config
	 */
	public static function register(Configurator $config)
	{
		$config->onCompile[] = function (Configurator $config, Compiler $compiler) {
			$compiler->addExtension('mailPanel', new MailPanelExtension());
		};
	}

}
