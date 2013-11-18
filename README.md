#FileMailer

Addon for catching mails in Nette to filesystem and showing it in debug bar.

```
After configuring this addon is e-mails not sended, but ONLY stored to filesystem and showed in Debug Bar!
```

![Demo](http://i40.tinypic.com/oh91dd.png)

##Killer features

- Simple instalation
- Extensive configuration options
- Full access to header
- Show plain text and HTML output
- Possibility download the attachments
- Parser caching

##Instalation

Addon has two parts. First is `FileMailer` replaced the standard [Nette](http://nette.org) mailer and provides storing e-mails to filesystem. Second is `MailPanel` - extension of the Debug Bar provides graphical output of stored e-mails.

###Download

####Composer

Add package to your `composer.json`:

```
"rm/filemailer": "dev-master"
```

####Direct download

Download addon from [GitHub](https://github.com/romanmatyus/FileMailer) and unpack it in place where is indexed some RobotLoader.

###Configuring

####Simple

Mails is stored to `/temp/mails` and showed in Debug Bar.


```
nette:
	debugger:
		bar:
			- RM\MailPanel
services:
	nette.mailer:
		class: RM\FileMailer
		setup:
			- $tempDir(%appDir%/../temp/mails)
```

####Advanced

As new message count only e-mails newer then `5 seconds`, automatic remove e-mails older then `1 minutes`, in tab show `Subject, From, To, Bc, Bcc` and not automatic hiding panel where should be showed no messages.


```
nette:
  debugger:
		bar:
			- @mailPanel
services:
	nette.mailer:
		class: RM\FileMailer
		setup:
			- $tempDir(%appDir%/../temp/mails)
	mailPanel:
		class: RM\MailPanel
		setup:
			- $newMessageTime(-5 seconds)
			- $autoremove(-1 minutes)
			- $show( [subject,from,to,bc,bcc] )
			- $hideEmpty(FALSE)
```

##Options

Options for addon customization.

###`FileMailer`

| Name          |  Type  | Default value | Must be set |               Note                 |
| ------------- |:------:|:-------------:| :----------:| ---------------------------------- |
| $tempDir      | string |       -       |      yes    | Path to temporary files of e-mails |

###`MailPanel`

| Name            | Type        | Default value                    | Must be set | Note                                                             |
| --------------- | ----------- | ---------------------------------| :----------:| ---------------------------------------------------------------- |
| $newMessageTime | string      | `-10 seconds`                    | no          | By this time limit is defined new messages.                      |    
| $show           | array       | `array("subject", "from", "to")` | no          | Array of default displayed headers.                               |
| $autoremove     | bool/string | `FALSE`                          | no          | Define limit for automatic remove old e-mails. E.g. `-2 minutes` |
| $hideEmpty      | bool        | `TRUE`                           | no          | Hide empty panel?                                                |