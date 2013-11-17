<?php
namespace RM;

use Nette\Application\UI\Control,
	Nette\Diagnostics\IBarPanel,
	Nette\Http\Request,
	Nette\Application\Application,
	Nette\Utils\Finder,
	Nette\DateTime,
	Nette\Caching\Cache,
	Nette\Caching\IStorage;

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
 * @author Roman Mátyus
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

	public function __construct(Application $application, Request $request, FileMailer $fileMailer, IStorage $cacheStorage)
	{
		$this->application = $application;
		$this->request = $request;
		$this->fileMailer = $fileMailer;
		$this->cache = new Cache($cacheStorage, "MailPanel");
		switch($request->getQuery("mail-panel")) {
			case 'delete-all':
				$this->handleDeleteAll();
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
	 * Process all messages.
	 * @return mixed
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
				$message = self::mailParser(file_get_contents($file));
				$this->cache->save($file->getFilename(),$message);
			}
			$time = new DateTime;
			if ($message->date>$time->modify($this->newMessageTime))
				$this->countNew++;
			$this->countAll++;
			$this->messages[] = $message;
		}
		return TRUE;
	}

	/**
	 * Parser of stored files.
	 * @param  string $content
	 * @return StdClass
	 */
	public static function mailParser($content)
	{
		preg_match("/----------[a-z0-9]{10}--/", $content, $match);
		$message_id = (isset($match[0])) ? substr($match[0], 10, -2) : NULL;

		$mess = explode("\r\n\r\n----------", $content);
		preg_match_all("/[a-zA-Z-]*: .*/", $mess[0], $matches);
		$header = array();
		foreach ($matches[0] as $line) {
			$temp = explode(": ",$line);
			$header[strtolower($temp[0])] = iconv_mime_decode(substr($temp[1],0,-1));
		}
		if (isset($header["date"]))
			$header["date"] = new DateTime($header["date"]);

		$mess = explode("\r\n\r\n----------", $content);
		$mess = substr($mess[1], 10, -22);
		$mess = explode("----------", $mess);
		$temp_mess = array();
		foreach ($mess as $part) {
			if (preg_match("/text\/html/", $part))
				$temp_mess["html"] = $part;
			elseif (preg_match("/text\/plain/", $part))
				$temp_mess["plain"] = $part;
		}
		$mess = $temp_mess;
		$temp_mess = array();
		foreach ($mess as $type => $part) {
			$temp_mess[$type] = explode("\r\n",$part);
			for($i=0;$i<=3;$i++)
				unset($temp_mess[$type][$i]);
			$temp_mess[$type] = implode("\r\n",$temp_mess[$type]);
		}
		$mess = $temp_mess;

		return (object) array_merge(
				array(
					"message_id" => $message_id,
					"header" => $header,
					"plain" => $mess['plain'],
					"html" => $mess['html'],
					"raw" => $content,
				),
				$header
			);
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

}