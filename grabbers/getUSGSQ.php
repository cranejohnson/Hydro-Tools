<?php
/**
 * Php script to download and shef encode USGS discharge data.
 *
 * Example usage ' php getUSGSQ.php AK PT2H RZ'
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


/* Kludge....USGS treats HG and HP both as water levels */
$PECode= array(
      'EKLA2' => 'HP'
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


//Handle the command line arguments
$opts = getoptreq('a:p:t:fl', array());

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
    elseif(strlen($opts["a"]) == 5){
        //Location is an NWSLID
        $nwslid = strtoupper($opts["a"]);
        $location = array_search($nwslid,$lookup); // Look up the usgs id based on the NWSLID
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
    $fileName = "USGSdischarge.".date('ymdHi');
    $logger->log("Local Shef File",PEAR_LOG_INFO);
}
else{
    $fileName = "sheffile.USGS.".date('ymdHi');
    $shefFile =  "SRAK58 PACR ".date('dHi')."\n";
    $shefFile .= "ACRRR3ACR \n";
    $shefFile .= "WGET DATA REPORT \n\n";
    $shefFile = SHEF_HEADER;
    $logger->log("Shef File with Header",PEAR_LOG_INFO);
}




$logger->log("Request $period days worth of data for $location from USGS web services.",PEAR_LOG_INFO);

if(!$location){
    $logger->log("No Location specified for USGS grab.",PEAR_LOG_ERR);
    exit;
}


$usgs = getUSGS($period,$location);

$lastUpdate = array();


if(file_exists('getUSGSQ.state')){
    $lastUpdate = json_decode(file_get_contents('getUSGSQ.state'),true);
}
else{
    $logger->log("No state file. Will create one.",PEAR_LOG_INFO);
}

#.AR BGDA2 150320 Z DH2129/DC1503202129/VBIRZZ 7.53/



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

        //Check if this data is newer than the last update

        if(isset($lastUpdate[$siteid])){
            if($datekey <= strtotime($lastUpdate[$siteid])){
                if(!$force)continue;
            }
            else{
                $lastUpdate[$siteid] = date('c',$datekey);
            }
        }
        else{
            $lastUpdate[$siteid] = date('c',$datekey);
        }

        if(array_key_exists('QR',$data)){

            if(floatval($data['QR']['val']) == -9999) {
                $flow = floatval($data['QR']['val']);
            }
            else{
                $flow = floatval($data['QR']['val'])/1000;
            }
            $shefFile .= ".AR $siteid $obstime/$dc/QRIRZZ ".$flow."\n";
            $linesInShef++;
        }
        if(array_key_exists('HG',$data)){
	    $PE = 'HG';
	    if(isset($PECode[$siteid])) $PE = $PECode[$siteid];

            $shefFile .= ".AR $siteid $obstime/$dc/".$PE."I".$typesource."Z ".$data['HG']['val']."\n";
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

if ($linesInShef) file_put_contents(TO_LDAD.$fileName, $shefFile);
file_put_contents('getUSGSQ.state',json_encode($lastUpdate));
$logger->log("END",PEAR_LOG_INFO);

?>
