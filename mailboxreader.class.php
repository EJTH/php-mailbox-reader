<?php
/** POP3 / IMAP Mailbox reader
 * @author Elias Toft Hansen
 * 
 */
define('MBREADER_ATTACHMENT_DIR','/srv/www/example.com/attachments/');
define('MBREADER_ATTACHMENTS_URI_PATH', 'http://example.com/attachments/');

class MBMessage {
    /**
     *
     * @var MailboxReader
     */
    private $mailboxReader;
    
    public $msgId;
    /**
     * Subject of the message
     * @var string
     */
    public $subject;
    
    //Plain text and HTML versions of the message body.
    public $plain;
    public $html;
    
    /**
     * Other parts such as attachments and images embedded in the HTML version of the mail 
     * format is part-id => part_data
     * eg.:
     * array('1.2' => stdObj)
     * @var array
     */
    public $parts = array();
    
    public $attachments = array();
    
    public $attachmentDownloads = array();
    
    /**
     * assoc array containing the parts of the mail (But only relevant parts, multiparts will be left out.)
     * @var array
     */
    public $partIds = array();
    
    public $sender;
    public $from;
    public $to = array();
    
    public $header;
    
    /**
     *
     * @param MailboxReader $mbreader 
     */
    public function __construct($mbreader){
        $this->mailboxReader = $mbreader;
    }
   
    public function saveAttachment($partId,$filename=false,$path=MBREADER_ATTACHMENT_DIR){
        
        if(!isset($this->parts[$partId])) throw new MailboxReaderException ('Invalid partId.');
        $part = $this->parts[$partId];
        
        if($part->ifparameters)
        $params = MailboxReader::parseParametersAssoc($part->parameters);
        
        if(!$filename){
            $fname = $params['name'] 
                .($part->ifid ? md5($part->id) : ($part->bytes.'_'.rand(0,99999))).'.'.$part->subtype;
            
            $filename = md5(time().'_'.$fname).'.'.$part->subtype;
        }
        
        //Treat as tainted
        $filename = str_replace(array('/','\\',"\0"),'_',$filename);
        
        $data = imap_fetchbody($mbreader->getIMAPRessource(),$this->msgId,$partId);
        
        switch($part->encoding){
            case 3 /*'base64'*/:
                $data = imap_base64($data);
            break;
            case 4 /*'quoted-printable'*/:
                $data = imap_qprint($data);
            break;
        }
        
        file_put_contents($path.'/'.$filename, $data);
        
        return $filename;
    }
    
    /**
     * Sets flags on messages
     * Default is \\SEEN.
     * See http://php.net/manual/en/function.imap-setflag-full.php
     * @param type $flag 
     */
    public function setFlag($flag){
        $this->mailboxReader->setFlag($this->msgId,$flag);
    }
    
    /**
     * Mark a message for deletion from current mailbox
     * see http://php.net/manual/en/function.imap-delete.php
     * @param type $options 
     */
    public function delete($options = 0){
        $this->mailboxReader->delete($this->msgid,$options);
    }
    /**
     * Mark message as seen;
     */
    public function setFlagSeen(){
        $this->mailboxReader->setFlag($this->msgId, "\\SEEN");
    }
    
}

class MailboxReader {
    /**
     * IMAP Stream
     * @var ressource IMAP Stream
     */
    private $mail;
    private $options;
    private $sanitizerCallback;
    
    /**
     * 
     * @param string $mailbox
     * @param string $username
     * @param string $password
     * @param array $options
     * @throws MailboxReaderException
     */
    public function __construct($mailbox, $username, $password, $options=array()){
        $this->mail = imap_open($mailbox, $username, $password);
        if(!$this->mail){
            /* Something went wrong, throw an exception with debug info */
            $message = 'IMAP ERROR: '.print_r(imap_errors(),true);
            throw new MailboxReaderException($message, 0);
        }
        $this->options = array_merge($this->getDefaultOptions(),$options);
    }
    
    /**
     * Adds an optional callback handler for sanitizing HTML output.
     * Using something like HTMLawed or similar is strongly recommended.
     * @param callable $callbackFunc
     */
    public function addSanitizerCallback($callbackFunc){
      $this->sanitizerCallback = $callbackFunc;
    }
    
    /**
     * Defines the default options
     * @return array 
     */
    private function getDefaultOptions(){
        return array(
            'charset' =>  'UTF-8'
        );
    }
    /**
     * Returns the IMAP ressorce used in this mailboxreader.
     * @return ressource IMAP Ressource identifier
     */
    public function getIMAPRessource(){
        return $this->mail;
    }
    
    /**
     * Fetches mail headers from the IMAP/POP3 account.
     * @param bool $unread Default:true, only fetch new mails (This may, and may NOT work on POP3, depending on the daemon, so be careful)
     * @return array Returns an array of StdObject with header info. See imap_header()
     */
    public function fetchHeaders($unread=true){
        
        $headerInfo = imap_headers($this->mail);
        $return = array();
        foreach($headerInfo as $n => $str){
            $msgid = $n+1;
            $header =  imap_header($this->mail, $msgid);
            if(!$unread || ($unread && ($header->Recent == 'N' || $header->Unseen == 'U')))
                $return[$msgid] = $header;
            
        }
        
        return $return;        
    }
    /**
     * See PHP documentation for imap_search
     * http://php.net/manual/en/function.imap-search.php
     * @param string $criteria
     * @return boolean Return FALSE if it does not understand the search criteria or no messages have been found. 
     */
    public function search($criteria){
        return imap_search($this->mail, $criteria);
    }
    
    /**
     * Transforms the parameters from imap_fetchstructure into an assoc key => value type array
     * See http://php.net/manual/en/function.imap-fetchstructure.php
     * @param array $paramArray The parameters to parse.
     */
    static public function parseParametersAssoc($paramArray){
        $return = array();
        foreach($paramArray as $paramObj){
            $return[$paramObj->attribute] = $paramObj->value;
        }
        return $return;
    }
    
    /**
     * Returns child parts which is not multipart (This should filter out all the garbage and only return content elements)
     * @param type $part stdObject
     * @param mixed $prefix 
     * $param array $exludeTypes Types to exclude from the result (eg. 2 for Exclude message type.)
     * @return array Assoc array with part-id => partinfo
     */
    static private function findChildPartsRecurse($part,$prefix=false,$excludeTypes=array()){
        $parts = array();
        if($part->type == 1){
            $i = 1;
            if($prefix===false  ) $i = 1;
            foreach($part->parts as $p){
                /* Continue on excluded type, Excluding a type is useful for filtering out attached messages etc. */
                if(in_array($p->type,$excludeTypes)) continue;
                $id = ($prefix !== false ? $prefix.'.' : '') . $i;
                
                $childParts = MailboxReader::findChildPartsRecurse($p,$id,$excludeTypes);
                if($p->type != 1){
                    $parts[$id] = $childParts;
                } else {
                    $parts = $childParts;
                }
                $i++;
            }
        } else {
            /* If there is no parts at all (eg. PLAINTEXT mail), then return as an assoc array anyways. */
            $parts = $prefix === false 
                ? array('1' => $part)
                : $part;            
        }
        return $parts;
            
    }
    
    /**
     * converts HTML messages to a prettier clear text alternative.
     * @param string $document
     * @return string 
     */
    static private function html2txt($document){
        $search = array(
            '@\r\n|\n@' => ' ',
            '@<script[^>]*?>.*?</script>@si' => '',  // Strip out javascript
            '@<![\s\S]*?--[ \t\n\r]*>@' => '',        // Strip multi-line comments including CDATA
            '@<style[^>]*?>.*?</style>@siU' => '',    // Strip style tags properly,
            '@<[\/\!]*?(br|p)[^<>]*?>@si' => "\r\n",
            '@<[\/\!]*?[^<>]*?>@si' => '',            // Strip out HTML tags
            '@[ \t]+@' => ' '
        );
        $text = preg_replace(array_keys($search), array_values($search), $document);
        return $text;
    }
    
    /**
     * fetches the mail $msgid returns a MBMessage object with the message.
     * @param int $msgid
     * @param boolean $getAttachments
     * @return MBMessage 
     */
    public function fetchMail($msgid,$getAttachments=false){
        /**
         * Array used to determine the encoding. This is based of the PHP documentation
         * at http://dk1.php.net/manual/en/function.imap-fetchstructure.php
         * which states that theese may vary!
         */
        $encodings = array("7bit","8bit","binary","base64","quoted-printable","other");
        
        /**
         * Array used to determine the mime type. This is based on the documentation mentioned above
         * 
         */
        $mime = array("Text","Multipart","Message","Application","Audio","Image","Video","Other");
        
        /* Fetch mail structure */
        $structure = imap_fetchstructure($this->mail, $msgid);
        
        
        $header = imap_header($this->mail, $msgid);
         
        $parts = MailboxReader::findChildPartsRecurse($structure);
        
        $nparts = count($parts);
        
        $isMultipart = ($nparts > 0);
        
        $message = new MBMessage($this->mail);
        $message->msgId = $msgid;
        $message->header = $header;
        
        $subject = imap_utf8($header->subject);
        
        //Run sanitizer, if set.
        if($this->sanitizerCallback !== null && is_callable($this->sanitizerCallback)){
          $cb = $this->sanitizerCallback;
          $subject = $cb($subject);
        }
        
        $message->subject = $subject;

        
        foreach($parts as $pid => $part){
            $it = $mime[$part->type];
            $is = strtolower($part->subtype);
            $ie = $encodings[$part->encoding];
            $mimeType = "$it/$is";

            /* If part is mail body or alternative... */
            if(!$part->ifdisposition && ($is == 'plain' || $is == 'html')){
                $content = imap_fetchbody($this->mail,$msgid,$pid);
                if(!$content) throw new MailboxReaderException (print_r(imap_last_error (),true));
                
                //Assume default charset is used in message encoding. Used as a fallback
                $encoding = $this->options['charset'];
                if($part->ifparameters){
                    $params = MailboxReader::parseParametersAssoc($part->parameters);
                    if($params['charset']) $encoding = $params['charset'];
                }

                /*
                 * Convert transfer encoding
                 */
                switch($ie){
                    case 'base64':
                        $content = imap_base64($content);
                    break;
                    case 'quoted-printable':
                        $content = imap_qprint($content);
                    break;
                }

                /**
                 * Attemps to use iconv to convert the message body into the desired encoding.
                 * 
                 */
                if(0 !== strcasecmp($encoding,$this->options['charset']) && @!$this->options['disable_iconv']){
                    $detectedEncoding = mb_detect_encoding($content,'UTF-8, ASCII, Windows-1252, ISO-8859-1, ISO-8859-2',true);
                    if($detectedEncoding != $this->options['charset']){
                        $content = iconv($detectedEncoding, $this->options['charset'].'//TRANSLIT//IGNORE', $content);
                    }
                }

                //Run sanitizer, if set.
                if($this->sanitizerCallback !== null && is_callable($this->sanitizerCallback)){
                  $cb = $this->sanitizerCallback;
                  $content = $cb($content);
                }
                
                /**
                 * Convert plain into HTML and vice versa. So both are always availible, 
                 */
                if($is == 'plain'){
                    $message->plain = $content;
                    if(!$message->html)
                    $message->html = nl2br($content);
                } else {
                    $message->html = $content;
                    if(!$message->plain)
                    $message->plain = MailboxReader::html2txt($content);
                }


            } else {
                $message->parts[$pid] = $part;
                if($part->ifid) $message->partIds[str_replace(array('<','>'),'',$part->id)] = $pid;
                if($part->ifdisposition && $part->disposition == 'attachment'){
                    $dparams = array();
                    $params  = array();
                    
                    if($part->ifdparameters)
                        $dparams = MailboxReader::parseParametersAssoc($part->dparameters);
                    if($part->ifparameters)
                        $params = MailboxReader::parseParametersAssoc($part->parameters);
                    
                    $message->attachments[$pid] = ($dparams['filename'] 
                        ? $dparams['filename'] 
                        : ($params['name'] ? $params['name'] : 'unknown-filename'));
                    
                }
            }
        }
        
        $message->from = $header->from;
        $message->to = $header->to;
        
        if($getAttachments){
            foreach($message->attachments as $k => $attachment){
                $message->attachmentDownloads[$k] = MBREADER_ATTACHMENTS_URI_PATH
                        .$message->saveAttachment($k, false, MBREADER_ATTACHMENT_DIR);
            }
            foreach($message->partIds as $id => $part){
                $message->attachmentDownloads[$part] = MBREADER_ATTACHMENTS_URI_PATH
                        .$message->saveAttachment($part, false, MBREADER_ATTACHMENT_DIR);
            }
            
            if(count($message->partIds) > 0){
                foreach($message->partIds as $id => $part){
                    $fp=$message->attachmentDownloads[$part];
                    /**
                     *  Replace cid: attachment references with paths determined by
                     *  MBREADER_ATTACHMENTS_URI_PATH
                     */
                    $message->html = str_replace('cid:'.$id, $fp, $message->html);
                }
            }
        }
        
        
        
        return $message;
       
    }
    
    /**
     * Closes the IMAP stream
     * see http://php.net/manual/en/function.imap-close.php
     * @param int $flag
     * @return boolean 
     */
    public function close($flag = null){
        return imap_close($this->mail,$flag);
    }
    
    /**
     * Delete all messages marked for deletion
     */
    public function expunge(){
        imap_expunge($this->mail);
    }
    
    /**
     * Mark a message for deletion from current mailbox
     * see http://php.net/manual/en/function.imap-delete.php
     * @param int $msgid
     * @param int $options 
     */
    public function delete($msgid,$options=0){
        imap_delete($this->mail,trim($msgid),$options);
    }
    
    /**
     * Sets flags on messages
     * Default is \\SEEN.
     * See http://php.net/manual/en/function.imap-setflag-full.php
     * @param int $msgId
     * @param string $flag See PHP manual 
     */
    public function setFlag($msgId,$flag="\\SEEN"){
        imap_setflag_full($this->mail, (string) $msgId, $flag);
    }
}

class MailboxReaderException extends Exception {
  
}
?>