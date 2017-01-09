<?php
/**
 * Php Script to Compare USGS and AHPS Data for Metadata Consistency
 * outputs a webpage with html table for review and logs errors to central
 * location.
 *
 * @package ahps_check
 * @author Crane Johnson <benjamin.johnson@noaa.gov>
 * @version 0.1
 */

/* Configuration information */
chdir(dirname(__FILE__));

require_once('../config.inc.php');
define('DEFAULT_CACHE_AGE',86400);       /* Define the cache age for downloaded files */
define('OUTPUT_FOLDER','output/');

/**
 * Include Web Function Library
 */

require_once(RESOURCES_DIRECTORY."web_functions.php");


/**
 * Update php settings
 */
ini_set('memory_limit', '512M');
set_time_limit(900);



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


/**
 * Quick distance calculation
 *
 * @param float $lat1 latitude of point 1
 * @param float $lon1 longitude of point 1
 * @param float $lat2 latitude of point 2
 * @param float $lon2 longitude of point 2
 * @param string $unit output units 'K' - kilometres 'N' - Nautical Miles 'null' - miles
 * @return float distance in requested units
 * @access public
 */
function calcDistance($lat1, $lon1, $lat2, $lon2, $unit) {

    $theta = $lon1 - $lon2;
    $dist = sin(deg2rad($lat1)) * sin(deg2rad($lat2)) +  cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * cos(deg2rad($theta));
    $dist = acos($dist);
    $dist = rad2deg($dist);
    $miles = $dist * 60 * 1.1515;
    $unit = strtoupper($unit);

    if ($unit == "K") {
        return ($miles * 1.609344);
    } else if ($unit == "N") {
        return ($miles * 0.8684);
    } else {
        return $miles;
    }
}


function get_all_USGS_info(){
    $states = array('AK'=>'Alaska', 'HI'=>'Hawaii', 'CA'=>'California', 'NV'=>'Nevada', 'OR'=>'Oregon', 'WA'=>'Washington', 'AZ'=>'Arizona', 'CO'=>'Colorada', 'ID'=>'Idaho', 'MT'=>'Montana', 'NE'=>'Nebraska', 'NM'=>'New Mexico', 'ND'=>'North Dakota', 'UT'=>'Utah', 'WY'=>'Wyoming', 'AL'=>'Alabama', 'AR'=>'Arkansas', 'IL'=>'Illinois', 'IA'=>'Iowa', 'KS'=>'Kansas', 'KY'=>'Kentucky', 'LA'=>'Louisiana', 'MN'=>'Minnesota', 'MS'=>'Mississippi', 'MO'=>'Missouri', 'OK'=>'Oklahoma', 'SD'=>'South Dakota', 'TX'=>'Texas', 'TN'=>'Tennessee', 'WI'=>'Wisconsin', 'CT'=>'Connecticut', 'DE'=>'Delaware', 'FL'=>'Florida', 'GA'=>'Georgia', 'IN'=>'Indiana', 'ME'=>'Maine', 'MD'=>'Maryland', 'MA'=>'Massachusetts', 'MI'=>'Michigan', 'NH'=>'New Hampshire', 'NJ'=>'New Jersey', 'NY'=>'New York', 'NC'=>'North Carolina', 'OH'=>'Ohio', 'PA'=>'Pennsylvania', 'RI'=>'Rhode Island', 'SC'=>'South Carolina', 'VT'=>'Vermont', 'VA'=>'Virginia', 'WV'=>'West Virginia');
    $file = 'USGS_all_active_siteinfo.json';

    foreach($states as $state => $long_name){
        $url = "http://waterservices.usgs.gov/nwis/site/?format=rdb,1.0&siteStatus=active&stateCd=".$state;
        $json = json_decode(file_get_contents($file),true);
        $usgs = getUSGS_siteInfo($url);
        foreach($usgs as $usgsID => $info){
            $json[$usgsID] = $info;
        }
        file_put_contents($file, json_encode($json,128));
    }
    return($json);
}


function convert($size)
{
    $unit=array('b','kb','mb','gb','tb','pb');
    return @round($size/pow(1024,($i=floor(log($size,1024)))),2).' '.$unit[$i];
}

/**
 *  MAIN PROGRAM
 *
 *  This program downloads data from HADS, AHPS and USGS and then compares
 *  the gage datum and locations. It creates a html table and highlights data
 *  that is different between NWS and USGS.
 */


$usgsInfo = array();
$opts = getoptreq('f:v:c:u', array());

$file = "USGS_all_active_siteinfo.json";
$usgsInfo = json_decode(file_get_contents($file),true);

/* u - update usgs site information with all active sites in the 50 states */
if(isset($opts["u"]) || !$usgsInfo){
    $logger->log("Loading USGS site information.",PEAR_LOG_INFO);
    $logger->log("Current Memory Usage: ".convert(memory_get_usage()),PEAR_LOG_INFO);
    $usgsInfo = get_all_USGS_info();
    $logger->log("Completed Updated USGS site info file: USGS_all_active_siteinfo.json",PEAR_LOG_INFO);
    $logger->log("Current Memory Usage: ".convert(memory_get_usage()),PEAR_LOG_INFO);
}


/*  f - Filter column 'rfc' or 'wfo' */
if(isset($opts["f"])){
    $column = strtoupper($opts["f"]);
}
else{
    $column = null;
}

/* v - Filter value i.e. 'pafc' or 'aprfc' */
if(isset($opts["v"])){
    $value = strtoupper($opts["v"]);
}
else{
    $value = null;
}


/* Set the default cache so that data is only generated periodically */
if(isset($opts["c"])){
    $cache_age =  $opts["c"];
}
else{
    $cache_age = DEFAULT_CACHE_AGE;
}


$filter = array(
    'column' => $column,
    'value'  => $value
    );


$qcArray = array();


/* if HSA or TYPE is not specified kick out an error */
if((strlen($filter['value']) == 0) || (strlen($filter['column']) == 0)){
    $logger->log("Column ({$filter['column']}) and filter ({$filter['value']}) not defined.",PEAR_LOG_INFO);
    $logger->log("STOP",PEAR_LOG_INFO);
    echo "\nExample Usage: /usgs_meta_check.php?f=wfo&v=pafc\n\n";
    exit;
}

/**
 * Create cache id
 *
 * @var string $id
 */
$id = OUTPUT_FOLDER.$filter['column']."_".$filter['value']."_meta_check.temp";


/**
 * Logging object
 *
 * @var obj $logger
 */
$logger->log("Working on: {$filter['column']} = {$filter['value']} ",PEAR_LOG_INFO);



if(strtoupper($value) == 'ALL'){
    $filter = null;
    $id = OUTPUT_FOLDER."All_meta_check.temp";
}

/**
 * Array of hads metadata for each site
 *
 * @var array $hadsData
 */
$hadsData = array();


$output = fopen($id, "w");


/* Cache file either does not exist or is out of date.  Update information */
$logger->log("Creating new meta data compare table.",PEAR_LOG_INFO);

/* Get the AHPS report and HADS Data */
//$filter = null;
$ahpsReport = getAHPSreport($cache_age,$filter);


$hadsData = getHADS_NWSLID_Lookup('ALL',$cache_age);


/* Process each site */
$siteNum = 0;

foreach($ahpsReport['sites'] as $site){

    $logger->log("Working on: ".$site['nwsshefid'],PEAR_LOG_DEBUG);
    $nwslid = strtoupper(trim($site['nwsshefid']));
    $nwsLower = strtolower($nwslid);


    $array = array(
        'AHPS_ID' => $nwslid,
        'rfc' => $site['rfc'],
        'wfo' => $site['wfo'],
        'AHPS_USGS_ID' => $site['usgsid'],
        'HADS_USGS_ID' => '',
        'USGS_lat' => '',
        'USGS_lon' =>'',
        'AHPS_lat' => $site['latitude'],
        'AHPS_lon' => $site['longitude'],
        'NWS_USGS_Distance' => '',
        'USGS_datum' => '',
        'USGS_datumName' => '',
        'NRLDB_datum'=> $site['nrldbverticaldatum'],
        'NRLDB_datumName' => $site['nrldbverticaldatumname'],
        'AHPSnavd88' => $site['navd88verticaldatum'],
        'AHPSngvd29' => $site['ngvd29verticaldatum'],
        'AHPSmsl' => $site['mslverticaldatum'],
        'AHPSother' => $site['otherverticaldatum'],
    );


    if($siteNum == 0){
        fputcsv($output, array_keys($array));
    }
    $siteNum++;





    /* Check to make sure the USGS id specified in the NRLBD matches the USGS ID specified by HADS.
       If this is not a Hads site continue on to the next site */

    $nwsUSGS = $site['usgsid'];
    if(!isset($hadsData['sites'][$nwslid])){
        $logger->log("$nwslid: Not a HADS Site",PEAR_LOG_DEBUG);
    }else{
        $array['HADS_USGS_ID'] = $hadsData['sites'][$nwslid]['usgs'];
        $nwsUSGS = $array['HADS_USGS_ID'] ;
    }



    /* This assumes there is a USGS site NWIS information available */
    $usgs = array();

    /* Get USGS NWIS site informaiton */
    if(strlen($nwsUSGS) > 7){
        if(isset($usgsInfo[$nwsUSGS])){
            $array['USGS_lat'] = floatval($usgsInfo[$nwsUSGS]['dec_lat_va']);
            $array['USGS_lon'] = floatval($usgsInfo[$nwsUSGS]['dec_long_va']);
            if(strlen($usgsInfo[$nwsUSGS]['alt_va'])>0){
                $array['USGS_datum'] = floatval($usgsInfo[$nwsUSGS]['alt_va']);
            }
            else{
                $array['USGS_datum'] = '';
            }
            $array['USGS_datumName'] = $usgsInfo[$nwsUSGS]['alt_datum_cd'];
            #$array['USGS_accuracy'] = floatval($usgsInfo[$nwsUSGS]['alt_acy_va']);

        }
        else{
            $url = "http://waterservices.usgs.gov/nwis/site/?format=rdb,1.0&sites=".$nwsUSGS."&siteOutput=expanded";
            if($usgs = getUSGS_siteInfo($url)){
                $array['USGS_lat'] = floatval($usgs[$nwsUSGS]['dec_lat_va']);
                $array['USGS_lon'] = floatval($usgs[$nwsUSGS]['dec_long_va']);
                if(strlen($usgsInfo[$nwsUSGS]['alt_va'])>0){
                    $array['USGS_datum'] = floatval($usgsInfo[$nwsUSGS]['alt_va']);
                }
                else{
                    $array['USGS_datum'] = '';
                }
                $array['USGS_datumName'] = trim($usgs[$nwsUSGS]['alt_datum_cd']);
                #$array['USGS_accuracy'] = floatval($usgs[$nwsUSGS]['alt_acy_va']);
            }
        }

    }
    else{
        if(strlen($nwsUSGS)>0) $logger->log("USGS id specified by for $nwslid is less than 8 characters NWS USGS id: $nwsUSGS",PEAR_LOG_INFO);
    }


    /* Compare USGS and NWS locations and flag if they are different */
    $distance = '';
    if($array['AHPS_lat'] && $array['USGS_lat']){
        $distance = calcDistance($site['latitude'],-$site['longitude'],$array['USGS_lat'],$array['USGS_lon'],"M");
        $distance = round($distance,2);
        $array['NWS_USGS_Distance'] = round($distance,2);
    }


//         $riversite = new riverSite($logger);
//         $riversite->usgs = $nwsUSGS;
//         if($riversite->getWebRating()){
//             $array['USGS_Rating_Min'] = $riversite->ratings[0]['values'][0]['stage'];
//             $max = array_pop($riversite->ratings[0]['values']);
//             $array['USGS_Rating_Max'] = $max['stage'];
//         }
//         else{
//             $array['USGS_Rating_Min'] = '';
//             $array['USGS_Rating_Max'] = '';
//         }


    fputcsv($output, array_values($array));


    $qcArray['sites'][$nwslid] = $array;

}



//Close the temporary file
fclose($output);
//rename to final file name
if(rename($id,str_replace("temp","csv",$id))){
    file_put_contents('output/All_meta_check.state',"Last Updated: ".date("F j, Y, H:i")." UTC");
}

$logger->log("STOP",PEAR_LOG_INFO);
?>
