<?php
/**
 * Description: This script gets messages from the specified mailbox and
 * drops the data into folder which then gets pushed over to AWIPS. 
 * Shef data will only be ingested 
 * into the local RFC shef encoder.
 *
 * This scripts requires php5 IMAP support. This was installed on redrock with
 * the following command:
 *       'zypper install php5-imap'
 *
 *

 *
 * @package get_igage
 * @author Crane Johnson <benjamin.johnson@noaa.gov>
 * @version 0.1
 */


chdir(dirname(__FILE__));

/* Include config file for paths etc..... */
require_once('../config.inc.php');
$mysqli->select_db("aprfc");


date_default_timezone_set('UTC');

//Pear log package
require_once (PROJECT_ROOT.'/resources/Pear/Log.php');
//Pear cache_lite package
require_once(PROJECT_ROOT.'/resources/Pear/Cache/Lite.php');

/**
 * Setup PEAR logging utility
 */

//Console gets more information, file and sql only get info and above errors
$consoleMask = Log::MAX(PEAR_LOG_DEBUG);
$fileMask = Log::MAX(PEAR_LOG_INFO);
$sqlMask = Log::MAX(PEAR_LOG_INFO);

$sessionId = 'ID:'.time();
if(LOG_TYPE == 'DB'){
    $conf = array('dsn' => "mysqli://".DB_USER.":".DB_PASSWORD."@".DB_HOST."/".DB_DATABASE,
            'identLimit' => 255);

    $sql = Log::factory('sql', 'log_table', __file__, $conf);
    $sql->setMask($sqlMask);
    $console = Log::factory('console','',$sessionId);
    $console->setMask($consoleMask);
    $logger = Log::singleton('composite');
    $logger->addChild($console);
    $logger->addChild($sql);
}
if(LOG_TYPE == 'FILE'){
    $script = basename(__FILE__, '.php');
    $file = Log::factory('file',LOG_DIRECTORY.$script.'.log',$sessionId);
    $file->setMask($fileMask);
    $console = Log::factory('console','',$sessionId);
    $console->setMask($consoleMask);
    $logger = Log::singleton('composite');
    $logger->addChild($console);
    $logger->addChild($file);
}
if(LOG_TYPE == 'NULL'){
    $logger = Log::singleton('null');
}


/**
 *
 *  MAIN PROGRAM LOGIC
 */

$logger->log("END",PEAR_LOG_INFO);
$sendshef = 0;


#################Mailbox Configuration Settings########################
$username = GMAIL_USERNAME;
$password = GMAIL_PASSWORD;

#//Which folders or label do you want to access? - Example: INBOX, All Mail, Trash, labelname
#//Note: It is case sensitive
$imapmainbox = "shefLocalIngest";
$messagestatus = "ALL";


//Gmail Connection String
$imapaddress = "{imap.gmail.com:993/imap/ssl}";

//Gmail host with folder
$hostname = $imapaddress . $imapmainbox;

$final_box = "trash";

$verbose = false;

$mbox = imap_open($hostname, $username,$password);

if(!$mbox){
    $logger->log("Could not open sheffile inbox ($imapmainbox in account $username) aborting....",PEAR_LOG_ERR);
        exit();
}

#####Spit out the Total Number of Messages from Iridium

$check = imap_check($mbox);

$sbdmes = $check->Nmsgs;

$logger->log("$sbdmes total messages in sheffile inbox",PEAR_LOG_INFO);

$numnew =  imap_num_recent($mbox);



######Process each message
$emails = imap_search($mbox,'ALL');
if($emails){
    arsort($emails); //JUST DO ARSORT
    foreach($emails as $email_number) {
        $sitedata = array();
        $msgno = $email_number;
        $text = "";
        ######Get the message header information
        $header = imap_header($mbox,$msgno);
        $data = ":From - ".$header->reply_toaddress."\n";
        $data .= ":Date - ".$header->date."\n";
        
        
        ######Get the file name and parse out the datestamp
        $string = imap_body($mbox,$msgno);
        $lines = preg_split('/$\R?^/m', $string);
        foreach($lines as $line){
            $line = trim($line);
            if(substr($line, 0, 3 ) === ".AR") $data .= $line."\n";
        }

        imap_delete($mbox, $msgno);
        imap_expunge($mbox);

        if($data){
            $filename = 'sheflocal.'.date('ymdHi');
            file_put_contents(TEMP_DIRECTORY.$filename, $data);
            if(file_put_contents(TO_LDAD.$filename, $data)){
                $logger->log("Moved shef data from {$header->reply_toaddress} to LDAD",PEAR_LOG_INFO);
            }    
        }  #If data loop
        else{
            $logger->log("No data file for imei: $imei",PEAR_LOG_DEBUG);
        }
    }
}  #Outer if loop



$logger->log("END",PEAR_LOG_INFO);


?>

