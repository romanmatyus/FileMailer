<?php

namespace RM;

use Nette\Object,
	Nette\Utils\DateTime,
	Nette\FileNotFoundException,
	Nette\InvalidArgumentException,
	Nette\InvalidStateException,
	Nette\Utils\Strings,
	Nette\Mail\IMailer,
	Nette\Mail\Message;


/**
 * Class for storing mails to filesystem.
 *
 * @author    Jan Drábek, Roman Mátyus
 * @copyright (c) Jan Drábek 2013
 * @copyright (c) Roman Mátyus 2013
 * @license   MIT
 * @package   FileMailer
 */
class FileMailer extends Object implements IMailer
{
	/** @var string */
	public $tempDir;

	/** @var string */
	private $prefix;


	public function __construct()
	{
		$this->prefix = date('YmdHis');
	}


	/**
	 * Store mail to file.
	 * @param  Message $message
	 * @return int
	 */
	public function send(Message $message)
	{
		$this->checkRequirements();
		$content = $message->generateMessage();

		preg_match('~Message-ID: <(?<message_id>\w+)[^>]+>~', $content, $matches);

		$path = $this->tempDir . '/'. $this->prefix . $matches['message_id'];
		if (($bytes = file_put_contents($path, $content)) === FALSE) {
			throw new InvalidStateException("Unable to write email to '$path'.");
		}

		return $bytes;
	}


	/**
	 * Check requirements.
	 */
	public function checkRequirements()
	{
		if ($this->tempDir === NULL) {
			throw new InvalidArgumentException('Directory for temporary files is not defined.');

		} elseif (!is_dir($this->tempDir) && @mkdir($this->tempDir) === FALSE && !is_dir($this->tempDir)) {
			throw new FileNotFoundException("Directory '$this->tempDir' is not a directory or cannot be created.");

		} elseif (!is_writable($this->tempDir)) {
			throw new InvalidArgumentException("Directory '$this->tempDir' is not writable.");
		}
	}


	/**
	 * Stored e-mail file parser.
	 * @param  string $content
	 * @param  string $filename
	 * @return \stdClass
	 */
	public static function mailParser($content, $filename = NULL)
	{
		$message = explode("\r\n\r\n", $content);
		preg_match_all('~[a-zA-Z-]*: .*~', $message[0], $matches);
		$headers = [];
		foreach ($matches[0] as $line) {
			list($name, $value) = explode(': ', $line) + ['', ''];
			$headers[strtolower($name)] = iconv_mime_decode(Strings::trim($value), 0, 'UTF-8');
		}
		if (isset($headers['date'])) {
			$headers['date'] = new DateTime($headers['date']);
		}

		$message_id = explode('@', $headers['message-id']);
		$message_id = substr($message_id[0], 1);

		$attachments = [];
		$mess = [
			'plain' => NULL,
			'html' => NULL,
		];
		if (preg_match('~multipart/mixed~', $content)) { // mail with attachments
			foreach (explode('----------',$content) as $part) {
				if (preg_match('~Content-Type: text/plain; charset=UTF-8~', $part)) {
					list(, $body) = explode("\r\n\r\n", $part);
					$mess['plain'] = Strings::trim($body);

				} elseif (preg_match('~Content-Type: text/html; charset=UTF-8~', $part)) {
					list(, $body) = explode("\r\n\r\n", $part);
					$mess['html'] = Strings::trim($body);

				} elseif (preg_match('~Content-Disposition: attachment;~', $part)) {
					list(, $part) = explode("\r\n", $part, 2);
					$tmp = explode("\r\n\r\n", $part);
					$tmp_header = explode("\r\n", $tmp[0]);
					$output = [
						'type' => substr($tmp_header[0], 14),
						'encoding' => substr($tmp_header[1], 27),
						'filename' => substr($tmp_header[2], 43, -1),
						'data' => $tmp[1],
					];
					$attachments[md5(serialize($output))] = (object) $output;
				}
			}

		} elseif (preg_match('~multipart/alternative~', $content)) { // html mail
			$mess = explode("\r\n\r\n----------", $content);
			$mess = substr($mess[1], 10, -22);
			$mess = explode('----------', $mess);
			$temp_mess = [];
			foreach ($mess as $part) {
				if (preg_match('~text/html~', $part)) {
					$temp_mess['html'] = $part;
				} elseif (preg_match('~text/plain~', $part)) {
					$temp_mess['plain'] = $part;
				}
			}
			$mess = $temp_mess;
			$temp_mess = [];
			foreach ($mess as $type => $part) {
				$temp_mess[$type] = explode("\r\n", $part);
				for ($i=0; $i <= 3; $i++) {
					unset($temp_mess[$type][$i]);
				}
				$temp_mess[$type] = implode("\r\n", $temp_mess[$type]);
			}
			$mess = $temp_mess;

		} elseif (preg_match('~text/plain~', $content)) { // plaintext mail
			$mess = [
				'plain' => $message[1],
				'html' => NULL,
			];
		}

		return (object) array_merge(
			[
				'filename' => $filename,
				'message_id' => $message_id,
				'header' => $headers,
				'plain' => $mess['plain'],
				'html' => $mess['html'],
				'raw' => $content,
				'attachments' => $attachments,
			],
			$headers
		);
	}


	/**
	 * @return string
	 */
	public function getPrefix()
	{
		return $this->prefix;
	}

}
