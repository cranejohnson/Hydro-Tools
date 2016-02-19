 <?php
/**
 * Php Script to Compare USGS and AHPS Data for Metadata Consistency
 * outputs a webpage with html table for review
 *
 * @package ahps_check
 * @author Crane Johnson <benjamin.johnson@noaa.gov>
 * @version 0.1
 */


/* Configuration information */
require_once('../config.inc.php');
define('DEFAULT_CACHE_AGE',3600);
define('MAX_LOCATION_ERROR',0.1);       /* Distance used to define location error between AHPS and USGS */
define('MAX_DATUM_ERROR',0.1);          /* Distance used to define datum error between AHPS and USGS */

/**
 * Include Web Function Library
 */

require_once(RESOURCES_DIRECTORY."web_functions.php");


/**
 * Update php settings
 */
ini_set('memory_limit', '512M');
set_time_limit(300);



/**
 * Load PEAR log package
 */
include_once('Log.php');




/**
 * Load PEAR cache_lite package
 */
include_once('Cache/Lite.php');


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



/**
 *  MAIN PROGRAM
 *
 *  This program downloads data from HADS, AHPS and USGS and then compares
 *  the gage datum and locations. It creates a html table and highlights data
 *  that is different between NWS and USGS.
 */


$opts = getoptreq('f:v:c:', array());

if(!isset($opts["f"])){
    $logger->log("No filter defined to check! (eg: -f rfc -v aprfc)",PEAR_LOG_WARNING);
    exit;
}

$column = strtoupper($opts["f"]);

$value = strtoupper($opts["v"]);

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





/* if HSA or TYPE is not specified kick out an error */
if((strlen($filter['value']) == 0) || (strlen($filter['column']) == 0)){
    $logger->log("Column and filter not defined.",PEAR_LOG_INFO);
    $logger->log("STOP",PEAR_LOG_INFO);
    exit;
}



/**
 * Array of hads metadata for each site
 *
 * @var array $hadsData
 */
$hadsData = array();


/**
 * Create cache id
 *
 * @var string $id
 */
$id = $filter['column']."_".$filter['value']."_meta_check.html";


/**
 * Logging object
 *
 * @var obj $logger
 */
$logger->log("Working on: {$filter['column']} = {$filter['value']} ",PEAR_LOG_INFO);


/**
 * Cache options
 *
 * @var array $options
 */
$options = array(
    'cacheDir' => CACHE_DIR,
    'lifeTime' => $cache_age,
    'fileNameProtection' => false,
    'cacheFileMode' => 0766
    );


/**
 * Caching object so that script only runs periodically and serves cached results
 *
 * @var array $options
 */
$cache = new Cache_Lite($options);


/* Get the cached table if the file has not timed out and then exit */
if (($table = $cache->get($id)) ) {
        $timestamp = date('Y-m-d H:i',$cache->lastModified($id));
        $logger->log("Using cached table created: $timestamp",PEAR_LOG_INFO);
}
else{
    /* Cache file either does not exist or is out of date.  Update information */

    /* Get the AHPS report and HADS Data */
    $ahpsReport = $ahpsReport = getAHPSreport($cache_age,$filter);
    $hadsData = getHADS_NWSLID_Lookup('ALL',$cache_age);

    /* Set up the table */
    $table = "<table border =1 class=\"sortable\"><thead><tr><th>NWSLID</th><th>WFO</th><th>NRLDB USGS</th><th>HAD USGS</th><th>AHPS Datum</th><th>USGS Datum</th><th>AHPS Lat</th><th>USGS Lat</th><th>AHPS Lon</th><th>USGS Lon</th><th>Distance</th></tr></thead><tbody>";

    /* Process each site */
    foreach($ahpsReport['sites'] as $site){
        $logger->log("Working on: ".$site['nwsshefid'],PEAR_LOG_DEBUG);
        $nws = strtoupper(trim($site['nwsshefid']));
        $nwsLower = strtolower($nws);
        $Ucolor = '';

        /* Check to make sure the USGS id specified in the NRLBD matches the USGS ID specified by HADS */
        $nwsUSGS = $site['usgsid'];
        if(!isset($hadsData['sites'][$nws])){
            $logger->log("$nws: Not a HADS Site",PEAR_LOG_INFO);
        }else{
            if($site['usgsid']!= $hadsData['sites'][$nws]['usgs']){
                $Ucolor = 'yellow';
                $logger->log("$nws: NRLDB and HADS do not match for USGS ID. NRLDB: ".$site['usgsid']." HADS: ".$hadsData['sites'][$nws]['usgs'],PEAR_LOG_WARNING);
                $nwsUSGS = $hadsData['sites'][$nws]['usgs'];
            }
        }

        /*If there is no USGS id populate the table and continue to the next site without checking distance etc. */
        if(strlen($nwsUSGS) == 0){
            $table .= "<tr><td>$nws</td><td>".$site['wfo']."</td><td>".$nwsUSGS."</td><td></td><td>".$ahps['gageDatum']."</td><td></td><td>".$site['latitude']."</td><td></td><td>".$site['longitude']."</td><td></td><td></td></tr>";
            continue;
        }

        /* This assumes there is a USGS site NWIS information available */
        $usgs = array();

        /* Get USGS NWIS site informaiton */
        if(strlen($nwsUSGS) > 7){
            $url = "http://waterservices.usgs.gov/nwis/site/?format=rdb,1.0&sites=".$nwsUSGS."&siteOutput=expanded";
            $usgs = getUSGS_siteInfo($url);
        }
        else{
            $logger->log("USGS id specified by new for $nws is less than 8 characters NWS USGS id: $nwsUSGS",PEAR_LOG_ERR);
        }

        /* Try to get AHPS data....sometimes this takes more than one try */
        for( $i=0; $i<3; $i++ ) {
            $ahps = getAhpsData($nws,$logger);;
            if( $ahps !== FALSE ) {
                break;
            }
        }


        $Dcolor = '';
        $Lcolor = '';
        $Loncolor = '';

        /* Compare USGS and NWS datums and flag if they are different */
        if(strlen($ahps['gageDatum'])>0 && strlen($usgs[$nwsUSGS]['alt_va'])>0){
            if(abs(floatval($ahps['gageDatum'])-floatval($usgs[$nwsUSGS]['alt_va']))> MAX_DATUM_ERROR){
              $logger->log("USGS-AHPS datums are different for:".$site['nwsshefid'],PEAR_LOG_ERR);
              $Dcolor = 'yellow';
            }
        }

        /* Compare USGS and NWS locations and flag if they are different */
        if(strlen($site['latitude'])>0 && strlen($usgs[$nwsUSGS]['dec_long_va'])>0){
            $distance = 0;
            $distance = calcDistance($site['latitude'],-$site['longitude'],$usgs[$nwsUSGS]['dec_lat_va'],$usgs[$nwsUSGS]['dec_long_va'],"M");
            $distance = round($distance,2);
            if($distance>MAX_LOCATION_ERROR){
              $logger->log("USGS-AHPS locations are different for:".$site['nwsshefid'],PEAR_LOG_ERR);
                $Lcolor = 'yellow';
                $Loncolor = 'yellow';
            }
        }

        /* Create the Google static map link */
        $mapLink = "https://maps.googleapis.com/maps/api/staticmap?center=".$site['latitude'].",".-$site['longitude']."&zoom=11&size=600x300&maptype=roadmap
            &markers=color:blue%7Clabel:N%7C".$site['latitude'].",".-$site['longitude']."&markers=color:green%7Clabel:G%7C40.711614,-74.012318
            &markers=color:red%7Clabel:U%7C".$usgs[$nwsUSGS]['dec_lat_va'].",".$usgs[$nwsUSGS]['dec_long_va']."
            &key=AIzaSyDAvZIKZZq0RfJf59QAh5ZeynMrTEF-G48";
        $table .= "<tr ><td>$nws</td><td>".$site['wfo']."</td><td bgcolor = '$Ucolor'>".$site['usgsid']."</td><td bgcolor = '$Ucolor'>".$hadsData['sites'][$nws]['usgs']."</td><td bgcolor = '$Dcolor'>".$ahps['gageDatum']."</td><td bgcolor = '$Dcolor'>".$usgs[$nwsUSGS]['alt_va']."</td><td bgcolor = '$Lcolor'>".$site['latitude']."</td><td bgcolor = '$Lcolor'>".$usgs[$nwsUSGS]['dec_lat_va']."</td><td bgcolor = '$Loncolor'>".$site['longitude']."</td><td bgcolor = '$Loncolor'>".$usgs[$nwsUSGS]['dec_long_va']."</td><td><a href='$mapLink'>$distance</a></td></tr>";

    }
    $table .="</tbody></table>";

    /* save the table to a cache file for future use */
    if($cache->save($table,$id)){
        $logger->log("Cache file saved.",PEAR_LOG_INFO);
    }
    else{
        $logger->log("Failed to save cache file.",PEAR_LOG_WARNING);
    }



    $logger->log("Results sent to browser.",PEAR_LOG_INFO);

}

$html = "<!DOCTYPE html>
    <html>
    <head>
        <script type='text/javascript' src='../resources/js/sorttable.js'></script>
        <style>
            /* Sortable tables */
            table.sortable thead {
                background-color:#eee;
                color:#666666;
                font-weight: bold;
                cursor: default;
            }
        </style>
    </head>
    <body>
    $table
</html>
";

if(file_put_contents($id,$html)){
    $logger->log("Comparison File: $id",PEAR_LOG_INFO);
}
else{
    $logger->log("Failed to create File: $id",PEAR_LOG_INFO);
}

$logger->log("STOP",PEAR_LOG_INFO);
?>​