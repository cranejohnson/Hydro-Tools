<?php
/**
 * Php script to download and shef encode USGS discharge data.
 *
 * Example usage ' php getUSGSQ.php -a AK -p P1D -t RZ'
 *       Get data for Alaska the last 2 hours and set typesource as RZ
 *
 * The lastUpdate state is stored in a local file getUSGSQ.state
 *
 *
 * @package getUSGS
 * @author Crane Johnson <benjamin.johnson@noaa.gov>
 * @version 0.1
 * @param string state initials (2 characters) to get or NWSLID of data to get
 * @param string period of data to grab see 'https://en.wikipedia.org/wiki/ISO_8601#Durations' for definitions
 */


/* Set the current directory */
chdir(dirname(__FILE__));

/* Include config file for paths etc..... */
require_once('../config.inc.php');


/* Kludge to get additional lookups into table
   These are sites that would not be included in the
   HADS lookup tables */

$customLookup = array( 1505248590 => 'JSBA2');


/* Kludge....USGS treats HG and HP both as water levels */
$PECode= array(
      'EKLA2' => 'HP',
      'JSBA2' => 'HP'
);

$typeSource= array(
      'MXYA2' => 'R2'
);


/* Web Function Library */
require_once(RESOURCES_DIRECTORY."web_functions.php");

ini_set('memory_limit', '512M');

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

/* Get the Statewide table of gages from HADS*/
$siteInfo = getHADS_NWSLID_Lookup('ALL',3600);


/* Develop a lookup table between the USGS id and NWSLID */

$lookup = array();
foreach($siteInfo['sites'] as $nwslid=>$site){
    $lookup[$site['usgs']] = $nwslid;
    }

foreach($customLookup as $usgs => $nwslid){
   $lookup[$usgs] = $nwslid;
}


//Handle the command line arguments
$opts = getoptreq('a:p:t:flr', array());

if(!isset($opts["p"])){
    $period = 'P1D';
    $logger->log("No period defined, set to default 'P1D'",PEAR_LOG_INFO);
}
else{
    $period = strtoupper($opts["p"]);
}

if(!isset($opts["a"])){
    $logger->log("No area defined to check! (eg: -a AK -p P1D -t RZ)",PEAR_LOG_INFO);
    $location = 'AK';
    $logger->log("No area defined, set to default 'AK'",PEAR_LOG_INFO);
}
else{
    if(strlen($opts["a"]) == 2){
        $location = strtoupper($opts["a"]);
    }
    elseif(strlen($opts["a"]) >= 5){
        $nwsLocations = explode(',',strtoupper($opts["a"]));
        $usgsArray = array();
        foreach($nwsLocations as $nwslid){

            if(array_search($nwslid,$lookup)){
                $usgsArray[] = array_search($nwslid,$lookup);
            }
        }
        $location = implode(',',$usgsArray);
    }
    else{
        $logger->log("A State or NWSLID was not specified",PEAR_LOG_ERR);
        exit;
    }
}

if(isset($opts["f"])){
    $force = true;
}
else{
    $force = false;
}

#If -r set then add the 'R' to the shef string to replace existing data in DB
if(isset($opts["r"])){
    $shefReplace = 'R';
}
else{
    $shefReplace = '';
}

if(!isset($opts["t"])){
    $logger->log("No type source defined, set to default 'RZ'",PEAR_LOG_INFO);
    $typesource = 'RZ';
}
else{
   $typesource = strtoupper($opts["t"]);
}

$shefFile = '';

//Check if the -l (local) option is flagged
//if not add the correct header to the file.
if(isset($opts["l"])){
    $fileName = "USGSdischarge.".date('ymdHis');
    $logger->log("Local Shef File",PEAR_LOG_INFO);
}
else{
    $fileName = "sheffile.USGS.".date('ymdHis');
    $shefFile = SHEF_HEADER;
    $logger->log("Shef File with Header",PEAR_LOG_INFO);
}




$logger->log("Request $period days worth of data for $location from USGS web services.",PEAR_LOG_INFO);

if(!$location){
    $logger->log("No Location specified for USGS grab.",PEAR_LOG_ERR);
    exit;
}


$usgs = getUSGS($period,$location);

if(!$usgs){
    $logger->log("No USGS Data Exiting.",PEAR_LOG_ERR);
    exit;
}


$lastUpdate = array();


if(file_exists('getUSGSQ.state')){
    $lastUpdate = json_decode(file_get_contents('getUSGSQ.state'),true);
}
else{
    $logger->log("No state file. Will create one.",PEAR_LOG_INFO);
}

function calculate_median($arr) {
    sort($arr);
    $count = count($arr); //total numbers in array
    $middleval = floor(($count-1)/2); // find the middle value, or the lowest middle value
    if($count % 2) { // odd number, middle is the median
        $median = $arr[$middleval];
    } else { // even number, calculate avg of 2 medians
        $low = $arr[$middleval];
        $high = $arr[$middleval+1];
        $median = (($low+$high)/2);
    }
    return $median;
}

//Subroutine to find missing data in the USGS data object
function findMissingUSGS($USGS){
    $dataInt = array();
    $missingDates = array();
    ksort($USGS['data']);
    reset($USGS['data']);
    $lastDate = key($USGS['data']);
    foreach($USGS['data'] as $recordTime=>$dataVal){
        $dataInt[] = $recordTime - $lastDate;
        $lastDate= $recordTime;
    }

    $interval = calculate_median($dataInt);
    ksort($USGS['data']);
    reset($USGS['data']);
    $lastDate = key($USGS['data']);
    foreach($USGS['data'] as $recordTime=>$dataVal){
        while(($recordTime - $lastDate) > $interval){
            $missingDates[] = $lastDate + $interval;
            $lastDate = $lastDate + $interval;
        }
        $lastDate = $lastDate + $interval;
    }
    return $missingDates;
}




$numSites = 0;
$linesInShef = 0;

//Loop through and process each site
foreach($usgs as $key => $value){

    $siteid = 'Empty';

    //Continue to the next site if we don't have a usgs to nwslid conversion
    if(!isset($lookup[$key])) continue;
    $numSites++;
    $siteid = $lookup[$key];

    if(!isset($value['data'])) continue;

    foreach ($value['data'] as $datekey=>$data){
        $str = '';
        if ($datekey == 'name') continue;
        $dc = date('\D\CymdHi',time());
        $obstime = date('ymd \Z \D\HHi',$datekey);

        //Check if this data is newer than the last update

        if(isset($lastUpdate[$siteid])){
            if($datekey <= strtotime($lastUpdate[$siteid])){
                if(!$force)continue;
            }
        }


        if(array_key_exists('QR',$data)){

            if(floatval($data['QR']['val']) == -9999) {
                $flow = floatval($data['QR']['val']);
            }
            else{
                $flow = floatval($data['QR']['val'])/1000;
            }
            $shefFile .= ".A".$shefReplace." $siteid $obstime/$dc/QRIRZZ ".$flow."\n";
            $linesInShef++;
        }
        if(array_key_exists('HG',$data)){
            $PE = 'HG';
            if(isset($PECode[$siteid])) $PE = $PECode[$siteid];
            $type = $typesource;
            if(isset($typeSource[$siteid])) $type = $typeSource[$siteid];
            $shefFile .= ".A".$shefReplace." $siteid $obstime/$dc/".$PE."I".$type."Z ".$data['HG']['val']."\n";
            $linesInShef++;
        }
        $lastUpdate[$siteid] = date('c',$datekey);
    }
}

$logger->log("$numSites sites available in the USGS web services file.",PEAR_LOG_INFO);
$logger->log("$linesInShef lines in the USGS discharge shef file.",PEAR_LOG_INFO);


//Setup Output Directory
if (!file_exists(TO_LDAD)) {
    mkdir(TO_LDAD, 0777, true);
}

if ($linesInShef) {
    if(file_put_contents(TO_LDAD.$fileName, $shefFile)){
        $logger->log("File (".$fileName.") saved in LDAD directory",PEAR_LOG_INFO);
    }
    else{
        $logger->log("Failed (".$fileName.")to save in TOLDAD directory...try again!",PEAR_LOG_ERR);
    }
}

if(file_put_contents('getUSGSQ.state',json_encode($lastUpdate))){
	$logger->log("USGS state file updated",PEAR_LOG_INFO);
}
else{
	$logger->log("USGS state file failed to updated!",PEAR_LOG_ERR);
}

$logger->log("END",PEAR_LOG_INFO);

?>
