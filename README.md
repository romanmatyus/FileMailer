# FileMailer
E-mails catching addon for [Nette Framework](http://nette.org). Emails are stored into files and shown in Tracy bar.

**!WARNING!** - After setting up this addon, all e-mails are not sent, but ONLY stored on filesystem and shown in Tracy bar.

![Demo](http://i41.tinypic.com/t9uuzq.png)


## Killer features
- Simple instalation
- Extensive configuration options
- Full access to headers
- Plain text and HTML output
- Possibility to download the attachments
- Parser caching


## Installation
Add package to your project by the [Composer](https://getcomposer.org/):
```
composer require rm/filemailer
```

or download addon manually from [GitHub](https://github.com/romanmatyus/FileMailer/releases) and unpack it in place indexed by RobotLoader.


## Configuration
The addon consists of two parts. The first one is the `FileMailer` which replaces the [IMailer](api.nette.org/Nette.Mail.IMailer.html) service and stores e-mails to filesystem. The second one is the `MailPanel` which is the Tracy bar panel and shows e-mails stored by FileMailer.

Default options are used in following examples.

### Setup by extension
Register new compiler extension in `config.neon` and optionally configure:
```
extensions:
	mailer: RM\MailPanel\DI\MailPanelExtension

mailer:
	newMessageTime: '-2 seconds'    # how long consider email as new
	show: [subject, from, to]       # which headers show in overview
	autoremove: '-5 seconds'        # how old emails are purged
	hideEmpty: yes                  # hide bar icon when no emails?
	tempDir: '%tempDir%/mails       # where to store emails
	debugger: yes                   # enable Tracy bar
```

### Manual setup
Replace the Nette's default IMailer service and register Tracy bar panel:
```
services:
	mail.mailer:
		class: RM\FileMailer
		setup:
			- $tempDir(%tempDir%/mails)

	mailerPanel:
		class: RM\MailPanel
		autowired: no
		setup:
			- setFileMailer(@mail.mailer)	# required
			- $newMessageTime('-5 seconds')
			- $show([subject, from, to])
			- $autoremove('-5 seconds')
			- $hideEmpty(yes)

tracy:
	bar:
		- @mailerPanel
```
