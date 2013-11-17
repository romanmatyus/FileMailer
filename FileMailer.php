<?php
namespace RM;

use Nette\Object,
	Nette\DateTime,
	Nette\FileNotFoundException,
	Nette\InvalidArgumentException,
	Nette\InvalidStateException,
	Nette\Utils\Strings,
	Nette\Mail\IMailer,
	Nette\Mail\Message;

/**
 * Class for storing mails to filesystem.
 *
 * Configuring:
 * 	services:
 * 		nette.mailer:
 * 			class: RM\FileMailer
 * 			setup:
 * 				- $tempDir(%appDir%/../temp/mails)
 *
 * @author Roman Mátyus
 * @copyright (c) Roman Mátyus 2013
 * @license MIT
 * @package FileMailer
 */
class FileMailer extends Object implements IMailer
{
	/** @var string */
	public $tempDir;

	/** @var string */
	private $prefix;

	public function __construct()
	{
		$now = new DateTime();
		$this->prefix = $now->format("YmdHis")."-";
	}

	/**
	 * Store mails to files.
	 * @param  Message $message
	 */
	public function send(Message $message)
	{
		$this->checkRequirements();
		$content = $message->generateMessage();
		preg_match("/----------[a-z0-9]{10}--/", $content, $match);
		$message_id = substr($match[0], 10, -2);
		$path = $this->tempDir."/".$this->prefix.$message_id;
		if ($bytes = file_put_contents($path, $content))
			return $bytes;
		else
			throw new InvalidStateException("Unable to write email to '".$path."'");
	}

	/**
	 * Check requirements.
	 */
	public function checkRequirements()
	{
		if (is_null($this->tempDir))
			throw new InvalidArgumentException("Directory for temporary files is not defined.");
		if (!is_dir($this->tempDir)) {
			mkdir($this->tempDir);
			if (!is_dir($this->tempDir)) 
				throw new FileNotFoundException("'".$this->tempDir."' is not directory.");
		}
		if (!is_writable($this->tempDir))
			throw new InvalidArgumentException("Directory '".$this->tempDir."' is not writeable.");
	}

	public function getPrefix()
	{
		return $this->prefix;
	}

}