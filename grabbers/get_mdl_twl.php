<?php

chdir(dirname(__FILE__));


/* Include config file for paths etc..... */
require_once('../config.inc.php');

define("LOG_TYPE","FILE");


$mysqli->select_db("aprfc");

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


$logger->log("START",PEAR_LOG_INFO);

$sites = array(

      "ULRA2"=>"9468333",
      "KZTA2"=>"9490424",
      "GLTA2"=>"0000003",
      "SFTA2"=>"9469854"
      );

$shefFile =  "SRAK58 PACR ".date('dHi')."\n";
$shefFile .= "ACRRR3ACR \n";
$shefFile .= "WGET DATA REPORT \n\n";

foreach($sites as $nwslid=>$mdlid){     
    $logger->log("Working on site: ".$nwslid." - MDLid: ".$mdlid,PEAR_LOG_INFO);
    //set POST variables
    $url = 'http://slosh.nws.noaa.gov/etss/station/etsurge2.0esri/fixed/php/getData.php';
    $fields = array(
                            'st' => urlencode($mdlid),
                    );


    $fields_string = '';

    //url-ify the data for the POST
    foreach($fields as $key=>$value) { $fields_string .= $key.'='.$value.'&'; }
    rtrim($fields_string, '&');

    //open connection
    $ch = curl_init();

    //set the url, number of POST vars, POST data
    curl_setopt($ch,CURLOPT_URL, $url);
    curl_setopt($ch,CURLOPT_POST, count($fields));
    curl_setopt($ch,CURLOPT_POSTFIELDS, $fields_string);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    //execute post
    $result = curl_exec($ch);

    //close connection
    curl_close($ch);

    $json = json_decode($result,TRUE);

    $numLines = 0;


    $string = '';
    for($i=0;$i<count($json['twl']);$i++){
        if(($json['twl'][$i] != 9999) && (strtotime($json['ts'][$i]) > time())){
           $obsTime = date('ymd \D\HHi',strtotime($json['ts'][$i]));
           $dcTime =  date('\D\CymdHi');
           $string = ".A ".$nwslid." ".$obsTime."/".$dcTime."/";
           $string .= "HMIFZ ".round($json['twl'][$i],2);
           $string .= "\n";
           $numLines++;
           $shefFile .= $string;
        }
    } 
}
##############Output Shef File#####################################
$fileName = "sheffile.mdlTWL.".date('ymdHi');




if($numLines == 0){
    echo "No Shef Data\n";
}
else{
    file_put_contents(TO_LDAD.$fileName, $shefFile);
//    file_put_contents($fileName, $shefFile);
}

$logger->log("END",PEAR_LOG_INFO);



?>
