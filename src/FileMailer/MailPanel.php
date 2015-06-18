<?php

namespace RM;

use Nette\DateTime;
use Nette\Application\Application;
use Nette\Application\UI\Control;
use Nette\Caching\Cache;
use Nette\Caching\IStorage;
use Nette\Http\Response;
use Nette\Http\Request;
use Nette\Utils\Finder;
use Tracy\IBarPanel;


/**
 * Tracy bar panel showing e-mails stored by FileMailer.
 *
 * @author    Jan Drábek, Roman Mátyus
 * @copyright (c) Jan Drábek 2013
 * @copyright (c) Roman Mátyus 2013
 * @license   MIT
 * @package   FileMailer
 */
class MailPanel extends Control implements IBarPanel {

	/** @var Request */
	private $request;

	/** @var Application */
	private $application;

	/** @var FileMailer */
	private $fileMailer;

	/** @var Cache */
	private $cache;

	/** @var Response */
	private $response;

	/** @var integer */
	private $countAll = 0;

	/** @var integer */
	private $countNew = 0;

	/** @var array */
	private $messages = array();

	/** @var bool */
	private $processed = FALSE;

	/** @var string */
	public $newMessageTime = '-2 seconds';

	/** @var array */
	public $show = array("subject", "from", "to");

	/** @var mixed */
	public $autoremove = '-15 seconds';

	/** @var bool */
	public $hideEmpty = TRUE;


	public function __construct(Application $application, Request $request, IStorage $cacheStorage, Response $response)
	{
		$this->application = $application;
		$this->request = $request;
		$this->cache = new Cache($cacheStorage, 'MailPanel');
		$this->response = $response;

		switch($request->getQuery('mail-panel')) {
			case 'download':
				$this->handleDownload($request->getQuery('mail-panel-mail'), $request->getQuery('mail-panel-file'));
				break;

			default:
				break;
		}
	}


	/**
	 * @param  FileMailer $fileMailer
	 * @return $this
	 */
	public function setFileMailer(FileMailer $fileMailer)
	{
		$this->fileMailer = $fileMailer;
		return $this;
	}


	/**
	 * Returns HTML code for Tracy bar icon.
	 * @return mixed
	 */
	public function getTab()
	{
		$this->processMessage();
		if ($this->countAll === 0 && $this->hideEmpty) {
			return;
		}

		return '<span title="FileMailer"><svg><path style="fill:#' . ( $this->countNew > 0 ? 'E90D0D' : '348AD2' ) . '" d="m 0.9 4.5 6.6 7 c 0 0 0 0 0 0 0.2 0.1 0.3 0.2 0.4 0.2 0.1 0 0.3 -0 0.4 -0.2 l 0 -0 L 15.1 4.5 0.9 4.5 z M 0 5.4 0 15.6 4.8 10.5 0 5.4 z m 16 0 L 11.2 10.5 16 15.6 16 5.4 z M 5.7 11.4 0.9 16.5 l 14.2 0 -4.8 -5.1 -1 1.1 -0 0 -0 0 c -0.4 0.3 -0.8 0.5 -1.2 0.5 -0.4 0 -0.9 -0.2 -1.2 -0.5 l -0 -0 -0 -0 -1 -1.1 z" /></svg><span class="tracy-label">'
			. ($this->countNew > 0 ? $this->countNew : NULL)
			. '</span></span>';
	}


	/**
	 * Returns HTML code of panel.
	 * @return mixed
	 */
	public function getPanel()
	{
		if ($this->countAll === 0 && $this->hideEmpty) {
			return;
		}

		$this->processMessage();

		ob_start();
		$template = clone $this->application->getPresenter()->template;
		$template->setFile(__DIR__ . '/MailPanel.latte');
		$template->messages = $this->messages;
		$template->countNew = $this->countNew;
		$template->countAll = $this->countAll;
		$template->show = $this->show;
		$template->render();
		return ob_get_clean();
	}


	/**
	 * Process all messages.
	 */
	private function processMessage()
	{
		if ($this->processed || !is_dir($this->fileMailer->tempDir)) {
			return;
		}
		$this->processed = TRUE;
		$this->autoremove();

		foreach (Finder::findFiles('*')->in($this->fileMailer->tempDir) as $file) {
			$message = $this->cache->load($file->getFilename());
			if ($message === NULL) {
				$message = FileMailer::mailParser(file_get_contents($file), $file->getFilename());
				$this->cache->save($file->getFilename(),$message);
			}

			$time = new DateTime;
			if ($message->date>$time->modify($this->newMessageTime)) {
				$this->countNew++;
			}

			$this->countAll++;
			$this->messages[] = $message;
		}

		usort($this->messages, function($a1, $a2) {
			return $a2->date->getTimestamp() - $a1->date->getTimestamp();
		});
	}


	/**
	 * Autoremove mails from filesystem an cache by argument 'autoremove'.
	 */
	private function autoremove()
	{
		if ($this->autoremove) {
			foreach (Finder::findFiles('*')->in($this->fileMailer->tempDir) as $file) {
				$now = new DateTime;
				$file_date = new DateTime('@' . filemtime($file));
				$file_date->setTimezone($now->getTimezone());
				$remove_date = $now->modify($this->autoremove);
				if ($file_date < $remove_date) {
					$this->cache->remove($file->getFilename());
					unlink($file);
				}
			}
		}
	}


	/**
	 * Download attachment from file.
	 */
	public function handleDownload($filename, $filehash)
	{
		$message = $this->cache->load($filename);
		$file = $message->attachments[$filehash];
		$this->response->setContentType($file->type, 'UTF-8');
		$this->response->setHeader('Content-Disposition', 'attachment; filename="' . $file->filename . '"');
		$this->response->setHeader('Content-Length', strlen($file->data));
		print base64_decode($file->data);
		exit;
	}

}
