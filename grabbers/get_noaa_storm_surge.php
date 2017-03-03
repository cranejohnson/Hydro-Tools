<?php
/**
 * Description: This script downloads NOAA storm surge predictions for Alaska and
 * parses the data into SHEF format, drops the data into the shef ingest folder for
 * AWIPS.
 *
 *
 * @package get_noaa_storm_surge
 * @author Crane Johnson <benjamin.johnson@noaa.gov>
 * @version 0.1
 */

chdir(dirname(__FILE__));

/* Include config file for paths etc..... */
require_once('../config.inc.php');
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



define('URL_BASE','http://www.nws.noaa.gov/mdl/etsurge/index.php?page=stn&region=ga&datum=mllw&list=&map=0-48&type=text&stn=');
define('FCST_COL',4);
define('DATE_COL',0);


/* Sites need to me mapped by name and NWS identifier*/
$sites = array(
	'aknome' => array(
		'name' => 'Nome, AK',
		'NWS'  => 'NMTA2'),
	'akseld' => array(
		'name' => 'Seldovia, AK',
		'NWS' =>  'OVIA2'),
	'akniki' => array(
		'name' => 'Nikiski, AK',
		'NWS'  => 'NKTA2'),
	'akanch' => array(
		'name' => 'Anchorage, AK',
		'NWS'  => 'ANTA2'),
	'akcord' => array(
		'name' => 'Cordova, AK',
		'NWS'  => 'CRVA2'),
	'akyak' => array(
		'name' => 'Yakutat, AK',
		'NWS'  => 'YATA2'),
	'akelf' => array(
		'name' => 'Elfin Cove, AK',
		'NWS'  => 'ELFA2'),
	'akskag' => array(
		'name' => 'Skagway, AK',
		'NWS'  => 'SKTA2'),
	'aksit'  => array(
		'name' => 'Sitka, AK',
		'NWS'  => 'ITKA2'),
	'akjune' => array(
		'name' => 'Juneau, AK',
		'NWS'  => 'JNEA2'),
        'akcarl' => array(
                'name' => 'Golovin Bay',
                'NWS'  => 'GLTA2'),
	'akket'  => array(
		'name' => 'Ketchikan, AK',
		'NWS'  => 'KECA2'),
        'akkotz' => array(
                'name' => 'Kotzebue',
                'NWS' => 'KZTA2'),
        'akbar' => array(
                'name' => 'Barrow',
                'NWS' => 'PBTA2'),
        'akmich' => array(
                'name' => 'St. Micheal',
                'NWS' => 'SATA2'),
	'akprud' => array(
		'name' => 'Prudhoe Bay, AK',
		'NWS'  => 'PRDA2'));



function get_noaa_data($site,$logger) {
	$shefcode = 'HM';
	$results = array();

	/* Get the site file */
	$html_file = file_get_contents(URL_BASE.$site);
	if(!$html_file){
		$logger->log("Failed to get URL data for ".$site,PEAR_LOG_ERROR);
		return false;
	}

	/* Extract the tide data located betwee <pre></pre> */

	preg_match("/<pre>([^`]*?)<\/pre>/", $html_file, $matches);
	$lines = explode("\n",$matches[1]);

	/* Process each line and convert the time to unix time.
	 * Populate a results array that contains [unix_time] -> Total Water Level
	 */

	foreach($lines as $line){
		if(strlen($line)== 0) continue;
		if($line[0] == '#') continue;
		$data = explode(',',$line);
		if(count($data)< 4) continue;
		$dateparts = preg_split('/\s+/', $data[DATE_COL]);
	 	$date = strtotime($dateparts[0])+3600*substr($dateparts[1],0, 2) ;
		$pred_TWL = $data[FCST_COL];
		if($pred_TWL == 99.9) $pred_TWL = -9999;
		$results[$date][$shefcode] = $pred_TWL;
	}
	if(count($results) == 0) return false;
	return $results;
}

function array_to_shef($site,$dataarray,$overWrite = false){
	$shefStr = "";
	$over = "";
	if($overWrite) $over = 'R';
	foreach($dataarray as $key => $values){
		$dc = date('\D\CymdHi');
		$shefStr .= ".A$over $site ". date('ymd \Z \D\HHi',$key)."/$dc/";
		foreach($values as $shefcode => $val){
			$shefStr .= $shefcode."IFZZ ".trim($val)."/";
		}
		$shefStr .= "\n";
	}
	return $shefStr;
}


/**
 *
 * 	MAIN PROGRAM LOGIC
 */

$shefFile =  "SRAK58 PACR\n";
$shefFile .= "ACRRR3ACR ".date('Hi')."\n";
$shefFile .= "WGET DATA REPORT\n\n";

foreach($sites as $site => $id){
	$data =  get_noaa_data($site,$logger);
	$logger->log("Retrieved ".count($data)." hours of data from ".$site." (".$id['name'].")",PEAR_LOG_DEBUG);
	$shef = array_to_shef($id['NWS'],$data,true);
	$shefFile .= $shef;
}


$fileName = "sheffile.hd.TWL.".date('ymdHi');


file_put_contents(TEMP_DIRECTORY.$fileName, $shefFile);
file_put_contents(TO_LDAD.$fileName, $shefFile);


$logger->log("END",PEAR_LOG_INFO);

?>

