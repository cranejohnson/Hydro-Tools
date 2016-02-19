<?php
/**
 * Php script to download and shef encode USGS discharge data.
 *
 * Example usage 'php getUSGSQ.php AK 1 RZ'
 *       Get data for Alaska for 1 day and set typesource as RZ
 *
 *
 * @package getUSGS
 * @author Crane Johnson <benjamin.johnson@noaa.gov>
 * @version 0.1
 * @param string state initials (2 characters) to get or NWSLID of data to get
 * @param string period of data to grab see 'https://en.wikipedia.org/wiki/ISO_8601#Durations' for definitions
 */


/* Include config file for paths etc..... */
require_once('../config.inc.php');



/* Web Function Library */
require_once(RESOURCES_DIRECTORY."web_functions.php");

ini_set('memory_limit', '512M');

//Pear log package
require_once 'Log.php';
//Pear cache_lite package
require_once('Cache/Lite.php');


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




/* Get the Statewide table of gages from HADS*/
$siteInfo = getHADS_NWSLID_Lookup('ALL',3600);

/* Develop a lookup table between the USGS id and NWSLID */

$lookup = array();
foreach($siteInfo['sites'] as $nwslid=>$site){
    $lookup[$site['usgs']] = $nwslid;
    }

if(strlen($argv[1]) == 2){
    //Location is a state
    $location = $argv[1];
}
elseif(strlen($argv[1]) == 5){
    //Location is an NWSLID
    $nwslid = $argv[1];
    $location = array_search($nwslid,$lookup); // Look up the usgs id based on the NWSLID
}
else{
    $logger->log("A State or NWSLID was not specified",PEAR_LOG_ERR);
}


$period = $argv[2];

if(isset($argv[3])) {
    $typesource = $argv[3];
}
else{
    $typesource = 'RZ';
}

$fileName = "USGSdischarge.".date('ymdHi');


$logger->log("Request $period days worth of data for $location from USGS web services.",PEAR_LOG_INFO);



$usgs = getUSGS($period,$location);

#.AR BGDA2 150320 Z DH2129/DC1503202129/VBIRZZ 7.53/

$shefFile = '';

$numSites = 0;
$linesInShef = 0;

foreach($usgs as $key => $value){

    $siteid = 'Empty';
    if(!isset($lookup[$key])) continue;
    $numSites++;
    $siteid = $lookup[$key];


    if(!isset($value['data'])) continue;
    foreach ($value['data'] as $datekey=>$data){
        $str = '';
        if ($datekey == 'name') continue;
        $dc = date('\D\CymdHi',time());
        $obstime = date('ymd \Z \D\HHi',$datekey);

        if(array_key_exists('QR',$data)){
            $flow = floatval($data['QR'])/1000;
            $shefFile .= ".AR $siteid $obstime/$dc/QRIRZZ ".$flow."\n";
            $linesInShef++;
        }
        if(array_key_exists('HG',$data)){
            $shefFile .= ".AR $siteid $obstime/$dc/HGI".$typesource."Z ".$data['HG']['val']."\n";
            $linesInShef++;
        }

    }
}

$logger->log("$numSites sites available in the USGS web services file.",PEAR_LOG_INFO);
$logger->log("$linesInShef lines in the USGS discharge shef file.",PEAR_LOG_INFO);



//Setup Output Directory
if (!file_exists(TO_LDAD)) {
    mkdir(TO_LDAD, 0777, true);
}
file_put_contents(TO_LDAD.$fileName, $shefFile);
$logger->log("Complete",PEAR_LOG_INFO);

?>
