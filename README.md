php-mailbox-reader
==================

Easy to use OOP implementation of the PHP IMAP extension



Usage
==================

Here is a simple example of usage. This will effectively show the newest unread mail complete with markup and embedded images:

```php
<?php
//Include mailboxreader class
include 'mailboxreader.class.php';

//Create a new instance of mailboxreader, login to the IMAP/POP3 server
$mailbox = new MailboxReader('{imap.example.com:143/novalidate-cert}INBOX', 'example@example.com', 'examplepwd');

//Return a list of UNSEEN message IDs
$newMails = $mailbox->search('UNSEEN');
$unseenCount = count($newMails);
if($unseenCount > 0){
    //Fetch mail, second parameter determines if attachments should be downloaded at this point.
    $message = $mb->fetchMail($newMails[$unseenCount-1], true);
    
    //Echo out the HTML contents of the message.
    echo '<h1>'.$message->subject.'</h1>';
    echo $message->html;
}
```
