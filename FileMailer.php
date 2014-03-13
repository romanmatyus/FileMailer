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
 * @author Jan Drábek, Roman Mátyus
 * @copyright (c) Jan Drábek 2013
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

		preg_match('/Message-ID: <(?<message_id>\w+)[^>]+>/', $content, $matches);

		$path = $this->tempDir."/".$this->prefix.$matches['message_id'];
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

	/**
	 * Parser of stored files.
	 * @param  string $content
	 * @param  string $filename
	 * @return StdClass
	 */
	public static function mailParser($content, $filename = NULL)
	{
		$message = explode("\r\n\r\n", $content);
		preg_match_all("/[a-zA-Z-]*: .*/", $message[0], $matches);
		$header = array();
		foreach ($matches[0] as $line) {
			$temp = explode(": ",$line);
			$header[strtolower($temp[0])] = iconv_mime_decode(Strings::trim($temp[1]));
		}
		if (isset($header["date"]))
			$header["date"] = new DateTime($header["date"]);

		$message_id = explode("@",$header['message-id']);
		$message_id = substr($message_id[0], 1);

		$attachments = array();
		$mess = array(
					"plain" => NULL,
					"html" => NULL,
				);
		if (preg_match("/multipart\/mixed/", $content)) { // mail with attachments
			foreach (explode("----------",$content) as $part) {
				if (preg_match("/Content-Type: text\/plain; charset=UTF-8/", $part)) {
					$tmp = explode("\r\n\r\n", $part);
					$mess["plain"] = Strings::trim($tmp[1]);
				} elseif (preg_match("/Content-Type: text\/html; charset=UTF-8/", $part)) {
					$tmp = explode("\r\n\r\n", $part);
					$mess["html"] = Strings::trim($tmp[1]);
				} elseif (preg_match("/Content-Disposition: attachment;/", $part)) {
					$tmp = explode("\r\n", $part);
					unset($tmp[0]);
					$part = implode("\r\n", $tmp);
					$tmp = explode("\r\n\r\n", $part);
					$tmp_header = explode("\r\n", $tmp[0]);
					$output = array(
							"type" => substr($tmp_header[0], 14),
							"encoding" => substr($tmp_header[1], 27),
							"filename" => substr($tmp_header[2], 43, -1),
							"data" => $tmp[1],
						);
					$attachments[md5($output['type'].$output['encoding'].$output['filename'].$output['data'])] = (object) $output;
				}
			}
		} elseif (preg_match("/multipart\/alternative/", $content)) { // html mail
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
		} elseif (preg_match("/text\/plain/", $content)) { // plaintext mail
			$mess = array(
						"plain" => $message[1],
						"html" => NULL,
					);
		}

		return (object) array_merge(
				array(
					"filename" => $filename,
					"message_id" => $message_id,
					"header" => $header,
					"plain" => $mess['plain'],
					"html" => $mess['html'],
					"raw" => $content,
					"attachments" => $attachments,
				),
				$header
			);
	}

	public function getPrefix()
	{
		return $this->prefix;
	}
}
