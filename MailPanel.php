<?php
namespace RM;

use Nette\DateTime,
	Nette\Application\Application,
	Nette\Application\UI\Control,
	Nette\Diagnostics\IBarPanel,
	Nette\Caching\Cache,
	Nette\Caching\IStorage,
	Nette\Http\Response,
	Nette\Http\Request,
	Nette\Utils\Finder;

/**
 * Bar panel showing stored mails.
 *
 * Configuring Simple:
 * 	nette:
 * 		debugger:
 * 			bar:
 *    			- RM\MailPanel
 * 	services:
 * 		nette.mailer:
 * 			class: RM\FileMailer
 * 			setup:
 * 				- $tempDir(%appDir%/../temp/mails)
 * 
 * Configuring Advanced:
 * 	nette:
 * 		debugger:
 * 			bar:
 *    			- @mailPanel
 * 	services:
 * 		nette.mailer:
 * 			class: RM\FileMailer
 * 			setup:
 * 				- $tempDir(%appDir%/../temp/mails)
 * 		mailPanel:
 * 			class: RM\MailPanel
 * 			setup:
 * 				- $newMessageTime(-1 minute)
 * 				- $autoremove(-2 minutes)
 * 				- $show( [from,to] )
 * 				- $hideEmpty(TRUE)
 * 
 * @author Jan Drábek, Roman Mátyus
 * @copyright (c) Jan Drábek 2013
 * @copyright (c) Roman Mátyus 2013
 * @license MIT
 * @package FileMailer
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
	public $newMessageTime = "-10 seconds";

	/** @var array */
	public $show = array(
					"subject",
					"from",
					"to",
				);

	/** @var mixed */
	public $autoremove = FALSE;

	/** @var bool */
	public $hideEmpty = TRUE;

	public function __construct(Application $application, Request $request, FileMailer $fileMailer, IStorage $cacheStorage, Response $response)
	{
		$this->application = $application;
		$this->request = $request;
		$this->fileMailer = $fileMailer;
		$this->cache = new Cache($cacheStorage, "MailPanel");
		$this->response = $response;
		switch($request->getQuery("mail-panel")) {
			case 'delete-all':
				$this->handleDeleteAll();
				break;
			case 'download':
				$this->handleDownload($request->getQuery("mail-panel-mail"),$request->getQuery("mail-panel-file"));
				break;
			default:
				break;
		}
	}

	/**
	 * Renders HTML code for custom tab.
	 * @return mixed
	 */
	public function getTab() {
		$this->processMessage();
		if ($this->countAll===0&&$this->hideEmpty)
			return;
		$count = ($this->countNew)?'<span id="mailpanel-count"><span>'.$this->countNew.'</span></span>&nbsp;&nbsp;':NULL;
		return '<img src="data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAABAAAAAQCAYAAAAf8/9hAAAABGdBTUEAAK/INwWK6QAAABl0RVh0U29mdHdhcmUAQWRvYmUgSW1hZ2VSZWFkeXHJZTwAAAITSURBVBgZpcHLThNhGIDh9/vn7/RApwc5VCmFWBPi1mvwAlx7BW69Afeu3bozcSE7E02ILjCRhRrds8AEbKVS2gIdSjvTmf+TYqLu+zyiqszDMCf75PnnnVwhuNcLpwsXk8Q4BYeSOsWpkqrinJI6JXVK6lSRdDq9PO+19vb37XK13Hj0YLMUTVVyWY//Cf8IVwQEGEeJN47S1YdPo4npDpNmnDh5udOh1YsZRcph39EaONpnjs65oxsqvZEyTaHdj3n2psPpKDLBcuOOGUWpZDOG+q0S7751ObuYUisJGQ98T/Ct4Fuo5IX+MGZr95jKjRKLlSxXxFxOEmaaN4us1Upsf+1yGk5ZKhp8C74H5ZwwCGO2drssLZZo1ouIcs2MJikz1oPmapHlaoFXH1oMwphyTghyQj+MefG+RblcoLlaJG/5y4zGCTMikEwTctaxXq/w9kuXdm9Cuzfh9acujXqFwE8xmuBb/hCwl1GKAnGccDwIadQCfD9DZ5Dj494QA2w2qtQW84wmMZ1eyFI1QBVQwV5GiaZOpdsPaSwH5HMZULi9UmB9pYAAouBQbMHHrgQcnQwZV/KgTu1o8PMgipONu2t5KeaNiEkxgAiICDMCCFeEK5aNauAOfoXx8KR9ZOOLk8P7j7er2WBhwWY9sdbDeIJnwBjBWBBAhGsCmiZxPD4/7Z98b/0QVWUehjkZ5vQb/Un5e/DIsVsAAAAASUVORK5CYII=" id="mailpanel-icon"/>'.$count;
	}

	/**
	 * Show content of panel.
	 * @return mixed
	 */
	public function getPanel() {
		if ($this->countAll===0&&$this->hideEmpty)
			return;
		$this->processMessage();
		ob_start();
		$template = clone $this->application->getPresenter()->template;
		$template->setFile(__DIR__.'/MailPanel.latte');
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
		if ($this->processed)
			return;
		$this->processed = TRUE;
		$this->autoremove();

		foreach (Finder::findFiles('*')->in($this->fileMailer->tempDir) as $file) {
			$message = $this->cache->load($file->getFilename());
			if ($message === NULL) {
				$message = FileMailer::mailParser(file_get_contents($file),$file->getFilename());
				$this->cache->save($file->getFilename(),$message);
			}
			$time = new DateTime;
			if ($message->date>$time->modify($this->newMessageTime))
				$this->countNew++;
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
				$file_date = new DateTime("@".filemtime($file));
				$file_date->setTimezone($now->getTimezone());
				$remove_date = $now->modify($this->autoremove);
				if ($file_date<$remove_date) {
					$this->cache->remove($file->getFilename());
					unlink($file);
				}
			}
		}
	}

	/**
	 * Delete all stored mails from filesystem an cache.
	 */
	public function handleDeleteAll()
	{
		foreach (Finder::findFiles('*')->in($this->fileMailer->tempDir) as $file)
			unlink($file);
		$this->cache->clean(
			array(
				Cache::ALL => TRUE,
			));
		header("Location: ".$this->request->getReferer());
		exit;
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
