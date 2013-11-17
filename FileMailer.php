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
		$message_id = self::mailParser($content)->message_id;
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
	
	/**
	 * Parser of stored files.
	 * @param  string $content
	 * @return StdClass
	 */
	public static function mailParser($content)
	{
		preg_match("/Message-ID: <[a-zA-Z0-9-]*@[a-zA-Z0-9-]*>/", $content, $match);
		$message_id = (isset($match[0])) ? substr($match[0], 13, -2) : NULL;
		$message_id = explode("@",$message_id);
		$message_id = $message_id[0];

		$mess = explode("\r\n\r\n", $content);
		preg_match_all("/[a-zA-Z-]*: .*/", $mess[0], $matches);
		$header = array();
		foreach ($matches[0] as $line) {
			$temp = explode(": ",$line);
			$header[strtolower($temp[0])] = iconv_mime_decode(str_replace(array("\r","\n","\r\n"),"",$temp[1]));
		}
		if (isset($header["date"]))
			$header["date"] = new DateTime($header["date"]);
			
		if (preg_match("/\r\n\r\n----------/", $content)) { // html mail
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
		} else {
			$mess = array(
						"plain" => $mess[1], 
						"html" => NULL,
					);
		}

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
}
