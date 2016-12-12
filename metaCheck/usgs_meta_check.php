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
require_once('../config.inc.php');
define('DEFAULT_CACHE_AGE',86400);       /* Define the cache age for downloaded files */
define('MAX_LOCATION_ERROR',0.1);       /* Distance used to define location error between AHPS and USGS */
define('MAX_DATUM_ERROR',0.1);          /* Distance used to define datum error between AHPS and USGS */
define('OUTPUT_FOLDER','output/');

/**
 * Include Web Function Library
 */

require_once(RESOURCES_DIRECTORY."web_functions.php");


/* Include rating library file */
//require_once '../ratings/rating_lib.php';

/**
 * Update php settings
 */
ini_set('memory_limit', '512M');
set_time_limit(300);



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
    $file = 'USGS_all_siteinfo.json';

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

$file = "USGS_all_siteinfo.json";

$usgsInfo = json_decode(file_get_contents($file),true);

/* u - update usgs site information with all active sites in the 50 states */
if(isset($opts["u"]) | !$usgsInfo){
    $logger->log("Loading USGS site information.",PEAR_LOG_INFO);
    $logger->log("Current Memory Usage: ".convert(memory_get_usage()),PEAR_LOG_INFO);
    $usgsInfo = get_all_USGS_info();
    $logger->log("Completed Updated USGS site info file: USGS_all_siteinfo.json",PEAR_LOG_INFO);
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
    $logger->log("Column and filter not defined.",PEAR_LOG_INFO);
    $logger->log("STOP",PEAR_LOG_INFO);
    echo "\nExample Usage: /usgs_meta_check.php?f=wfo&v=pafc\n\n";
    exit;
}

if(strtoupper($value) == 'ALL'){
    $filter = null;
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
$id = OUTPUT_FOLDER.$filter['column']."_".$filter['value']."_meta_check.html";


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
    'cacheFileMode' => 0777
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
    $logger->log("Creating new meta data compare table.",PEAR_LOG_INFO);

    /* Get the AHPS report and HADS Data */
    //$filter = null;
    $ahpsReport = getAHPSreport($cache_age,$filter);

    $hadsData = getHADS_NWSLID_Lookup('ALL',$cache_age);

    /* Set up the table */
    $table = "Table Created at: ".date("F j, Y, g:i a")." UTC<br>";
    $table .= "<table id=\"myTable\" class=\"tablesorter\"><thead><tr><th>NWSLID</th><th>RFC</th><th>WFO</th><th>AHPS USGS ID</th><th>HADS USGS ID</th><th>AHPS Datum</th><th>USGS Datum</th><th>AHPS Lat</th><th>USGS Lat</th><th>AHPS Lon</th><th>USGS Lon</th><th>Distance</th></tr></thead><tbody>";

    /* Process each site */
    foreach($ahpsReport['sites'] as $site){
        $array = array();
        $logger->log("Working on: ".$site['nwsshefid'],PEAR_LOG_DEBUG);
        $nwslid = strtoupper(trim($site['nwsshefid']));
        $nwsLower = strtolower($nwslid);
        $Ucolor = '';

        $array['AHPS_ID'] = $nwslid;
        $array['USGS_ID_ERROR'] = 0;
        $array['DATUM_ERROR'] = 0;
        $array['USGS_lat'] = '';
        $array['USGS_lon'] ='';
        $array['AHPS_lat'] = '';
        $array['AHPS_lon'] = '';
        $array['NWS_USGS_Distance'] = '';
        $array['USGS_datum'] = '';
        $array['NWS_datum']= '';




        /* Check to make sure the USGS id specified in the NRLBD matches the USGS ID specified by HADS.
           If this is not a Hads site continue on to the next site */
        $idClass = '';
        $array['AHPS_USGS_ID'] = $site['usgsid'];
        $nwsUSGS = $site['usgsid'];
        if(!isset($hadsData['sites'][$nwslid])){
            $logger->log("$nwslid: Not a HADS Site",PEAR_LOG_INFO);
            $array['HADS_USGS_ID'] = '';
        }else{
            $array['HADS_USGS_ID'] = $hadsData['sites'][$nwslid]['usgs'];
            $nwsUSGS = $array['HADS_USGS_ID'] ;
            if($array['HADS_USGS_ID'] != $array['AHPS_USGS_ID']){
                if(strlen($array['AHPS_USGS_ID'])>0) {
                    $array['USGS_ID_ERROR'] = true;
                    $idClass = 'error';
                }
            }
        }



        /* This assumes there is a USGS site NWIS information available */
        $usgs = array();

        /* Get USGS NWIS site informaiton */
        if(strlen($nwsUSGS) > 7){
            if(isset($usgsInfo[$nwsUSGS])){
                $array['USGS_lat'] = floatval($usgsInfo[$nwsUSGS]['dec_lat_va']);
                $array['USGS_lon'] = floatval($usgsInfo[$nwsUSGS]['dec_long_va']);
                $array['USGS_datum'] = floatval($usgsInfo[$nwsUSGS]['alt_va']);

            }
            else{
                $url = "http://waterservices.usgs.gov/nwis/site/?format=rdb,1.0&sites=".$nwsUSGS."&siteOutput=expanded";
                $usgs = getUSGS_siteInfo($url);
                $array['USGS_lat'] = floatval($usgs[$nwsUSGS]['dec_lat_va']);
                $array['USGS_lon'] = floatval($usgs[$nwsUSGS]['dec_long_va']);
                $array['USGS_datum'] = floatval($usgs[$nwsUSGS]['alt_va']);
            }

        }
        else{
            $logger->log("USGS id specified by for $nwslid is less than 8 characters NWS USGS id: $nwsUSGS",PEAR_LOG_INFO);
        }


        /* Try to get AHPS data....sometimes this takes more than one try */
         for( $i=0; $i<3; $i++ ) {
             $ahps = getAhpsData($nwslid,$logger);;
             if( $ahps !== FALSE ) {
                 break;
             }
         }


        if(!$ahps){
            $logger->log("Failed to get AHPS data for: ".$site['nwsshefid'],PEAR_LOG_ERR);
         }else{
            if($ahps[$nwslid]['inService']){
                $array['NWS_datum'] = floatval($ahps['gageDatum']);
                $array['AHPS_lat'] = floatval($site['latitude']);
                $array['AHPS_lon'] = floatval($site['longitude']);
            }else{
                $logger->log("AHPS site out of service: ".$site['nwsshefid'],PEAR_LOG_ERR);
            }
        }


        $datumClass = '';

        /* Compare USGS and NWS datums and flag if they are different */
        if($array['NWS_datum']>0 && $array['USGS_datum']>0){
            if(abs($array['NWS_datum']-$array['USGS_datum']) > MAX_DATUM_ERROR){
              $datumClass = 'error';
              $logger->log("USGS-AHPS datums are different for:".$site['nwsshefid'],PEAR_LOG_ERR);
            }
        }


        /* Compare USGS and NWS locations and flag if they are different */
        $distance = '';
        if($array['AHPS_lat'] && $array['USGS_lat']){
            $distance = calcDistance($site['latitude'],-$site['longitude'],$array['USGS_lat'],$array['USGS_lon'],"M");
            $distance = round($distance,2);
            $array['NWS_USGS_Distance'] = round($distance,2);
            if($array['NWS_USGS_Distance'] > MAX_LOCATION_ERROR){
                $logger->log("USGS-AHPS locations are different for:".$site['nwsshefid'],PEAR_LOG_ERR);
            }
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



        if(!isset($site['usgsid'])) $site['usgsid'] = '';
        if(!isset($hadsData['sites'][$nwslid]['usgs'])) $hadsData['sites'][$nwslid]['usgs'] = '';
        if(!isset($ahps['gageDatum'])) $ahps['gageDatum'] = '';
        if(!isset($usgs[$nwsUSGS]['alt_va'])) $usgs[$nwsUSGS]['alt_va'] = '';
        if(!isset($usgs[$nwsUSGS]['dec_lat_va']))  $usgs[$nwsUSGS]['dec_lat_va'] = '';
        if(!isset($usgs[$nwsUSGS]['dec_long_va'])) $usgs[$nwsUSGS]['dec_long_va'] = '';
        if(!isset($distance)) $distance = '';


        /* Create the Google static map link */
        $mapLink = "https://maps.googleapis.com/maps/api/staticmap?center=".$site['latitude'].",".-$site['longitude']."&zoom=11&size=600x300&maptype=roadmap
            &markers=color:blue%7Clabel:N%7C".$site['latitude'].",".-$site['longitude']."&markers=color:green%7Clabel:G%7C40.711614,-74.012318
            &markers=color:red%7Clabel:U%7C".$array['USGS_lat'].",".$array['USGS_lon'] ."
            &key=AIzaSyDAvZIKZZq0RfJf59QAh5ZeynMrTEF-G48";




        $table .= "<tr ><td>$nwslid</td><td>".$site['rfc']."</td><td>".$site['wfo']."</td><td class ='".$idClass."'>".$site['usgsid']."</td><td class ='".$idClass."'>".$hadsData['sites'][$nwslid]['usgs']."</td><td class='".$datumClass."'>".$ahps['gageDatum']."</td><td class='".$datumClass."'>".$array['USGS_datum'] ."</td><td>".$site['latitude']."</td><td>".$array['USGS_lat']."</td><td>".$site['longitude']."</td><td>".$array['USGS_lon']."</td><td class='dist'><a href='$mapLink'  target='_blank'>$distance</a></td></tr>";

        $qcArray['sites'][$nwslid] = $array;

    }


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

        <script type='text/javascript' src='http://cdnjs.cloudflare.com/ajax/libs/jquery/1.9.1/jquery.min.js'></script>
        <script type='text/javascript' src='http://cdnjs.cloudflare.com/ajax/libs/jquery.tablesorter/2.9.1/jquery.tablesorter.min.js'></script>




        <script>
            function checkDistance(){
                var numWrong = 0;
                $('#myTable tr td.dist').each(function() {

                    if ($(this).text() > $('#distance').val()) {
                        $(this).addClass('error');
                        numWrong = numWrong + 1;

                    }
                    else{
                        $(this).removeClass('error');

                    }

                });
                $('#distNum').text(numWrong);
            }

            function showErrors(){
                $('#myTable tbody tr:visible').each(function() {
                    showRow = false;
                    $(this).find('td').each(function(){
                        if($(this).hasClass( 'error' )){
                            showRow = true;
                        }
                    });
                    if(!showRow){
                         this.style.display = 'none';
                    }
                });
            }

           function showAll(){
                $('#myTable tbody tr').each(function() {
                         this.style.display = 'table-row';
                });
            }


            function filter(value){
                console.log(value.length);
                if(value.length <1){
                    $('#myTable tbody tr').each(function() {
                        this.style.display = '';
                    });
                    return;
                }
                $('#myTable tbody tr').each(function() {
                    if($(this).find('td').eq(1).text().indexOf(value) == 0 | $(this).find('td').eq(2).text().indexOf(value) == 0){
                        this.style.display = '';
                    }else{
                        this.style.display = 'none';
                    }
                });
            }



            $(document).ready(function()
             {
                $('#myTable').tablesorter();
                checkDistance();
                $('#myTable').fadeIn(2000);



            });



        </script>
        <style>
           #myTable {
            display: none;
            }
           /*************
            Default Theme
            *************/
            /* overall */
            .tablesorter-default {
                width: 100%;
                font: 12px/18px Arial, Sans-serif;
                color: #333;
                background-color: #fff;
                border-spacing: 0;
                margin: 10px 0 15px;
                text-align: left;
            }

            /* header */
            .tablesorter-default th,
            .tablesorter-default thead td {
                font-weight: bold;
                color: #000;
                background-color: #fff;
                border-collapse: collapse;
                border-bottom: #ccc 2px solid;
                padding: 0;
            }
            .tablesorter-default tfoot th,
            .tablesorter-default tfoot td {
                border: 0;
            }
            .tablesorter-default .header,
            .tablesorter-default .tablesorter-header {
                background-image: url(data:image/gif;base64,R0lGODlhFQAJAIAAACMtMP///yH5BAEAAAEALAAAAAAVAAkAAAIXjI+AywnaYnhUMoqt3gZXPmVg94yJVQAAOw==);
                background-position: center right;
                background-repeat: no-repeat;
                cursor: pointer;
                white-space: normal;
                padding: 4px 20px 4px 4px;
            }
            .tablesorter-default thead .headerSortUp,
            .tablesorter-default thead .tablesorter-headerSortUp,
            .tablesorter-default thead .tablesorter-headerAsc {
                background-image: url(data:image/gif;base64,R0lGODlhFQAEAIAAACMtMP///yH5BAEAAAEALAAAAAAVAAQAAAINjI8Bya2wnINUMopZAQA7);
                border-bottom: #000 2px solid;
            }
            .tablesorter-default thead .headerSortDown,
            .tablesorter-default thead .tablesorter-headerSortDown,
            .tablesorter-default thead .tablesorter-headerDesc {
                background-image: url(data:image/gif;base64,R0lGODlhFQAEAIAAACMtMP///yH5BAEAAAEALAAAAAAVAAQAAAINjB+gC+jP2ptn0WskLQA7);
                border-bottom: #000 2px solid;
            }
            .tablesorter-default thead .sorter-false {
                background-image: none;
                cursor: default;
                padding: 4px;
            }

            /* tfoot */
            .tablesorter-default tfoot .tablesorter-headerSortUp,
            .tablesorter-default tfoot .tablesorter-headerSortDown,
            .tablesorter-default tfoot .tablesorter-headerAsc,
            .tablesorter-default tfoot .tablesorter-headerDesc {
                border-top: #000 2px solid;
            }

            /* tbody */
            .tablesorter-default td {
                background-color: #fff;
                border-bottom: #ccc 1px solid;
                padding: 4px;
                vertical-align: top;
            }

            /* table processing indicator */
            .tablesorter-default .tablesorter-processing {
                background-position: center center !important;
                background-repeat: no-repeat !important;
                /* background-image: url(images/loading.gif) !important; */
                background-image: url('data:image/gif;base64,R0lGODlhFAAUAKEAAO7u7lpaWgAAAAAAACH/C05FVFNDQVBFMi4wAwEAAAAh+QQBCgACACwAAAAAFAAUAAACQZRvoIDtu1wLQUAlqKTVxqwhXIiBnDg6Y4eyx4lKW5XK7wrLeK3vbq8J2W4T4e1nMhpWrZCTt3xKZ8kgsggdJmUFACH5BAEKAAIALAcAAAALAAcAAAIUVB6ii7jajgCAuUmtovxtXnmdUAAAIfkEAQoAAgAsDQACAAcACwAAAhRUIpmHy/3gUVQAQO9NetuugCFWAAAh+QQBCgACACwNAAcABwALAAACE5QVcZjKbVo6ck2AF95m5/6BSwEAIfkEAQoAAgAsBwANAAsABwAAAhOUH3kr6QaAcSrGWe1VQl+mMUIBACH5BAEKAAIALAIADQALAAcAAAIUlICmh7ncTAgqijkruDiv7n2YUAAAIfkEAQoAAgAsAAAHAAcACwAAAhQUIGmHyedehIoqFXLKfPOAaZdWAAAh+QQFCgACACwAAAIABwALAAACFJQFcJiXb15zLYRl7cla8OtlGGgUADs=') !important;
            }

            /* Zebra Widget - row alternating colors */
            .tablesorter-default tr.odd > td {
                background-color: #dfdfdf;
            }
            .tablesorter-default tr.even > td {
                background-color: #efefef;
            }

            /* Column Widget - column sort colors */
            .tablesorter-default tr.odd td.primary {
                background-color: #bfbfbf;
            }
            .tablesorter-default td.primary,
            .tablesorter-default tr.even td.primary {
                background-color: #d9d9d9;
            }
            .tablesorter-default tr.odd td.secondary {
                background-color: #d9d9d9;
            }
            .tablesorter-default td.secondary,
            .tablesorter-default tr.even td.secondary {
                background-color: #e6e6e6;
            }
            .tablesorter-default tr.odd td.tertiary {
                background-color: #e6e6e6;
            }
            .tablesorter-default td.tertiary,
            .tablesorter-default tr.even td.tertiary {
                background-color: #f2f2f2;
            }

            /* caption */
            caption {
                background-color: #fff;
            }

            /* filter widget */
            .tablesorter-default .tablesorter-filter-row {
                background-color: #eee;
            }
            .tablesorter-default .tablesorter-filter-row td {
                background-color: #eee;
                border-bottom: #ccc 1px solid;
                line-height: normal;
                text-align: center; /* center the input */
                -webkit-transition: line-height 0.1s ease;
                -moz-transition: line-height 0.1s ease;
                -o-transition: line-height 0.1s ease;
                transition: line-height 0.1s ease;
            }
            /* optional disabled input styling */
            .tablesorter-default .tablesorter-filter-row .disabled {
                opacity: 0.5;
                filter: alpha(opacity=50);
                cursor: not-allowed;
            }
            /* hidden filter row */
            .tablesorter-default .tablesorter-filter-row.hideme td {
                /*** *********************************************** ***/
                /*** change this padding to modify the thickness     ***/
                /*** of the closed filter row (height = padding x 2) ***/
                padding: 2px;
                /*** *********************************************** ***/
                margin: 0;
                line-height: 0;
                cursor: pointer;
            }
            .tablesorter-default .tablesorter-filter-row.hideme * {
                height: 1px;
                min-height: 0;
                border: 0;
                padding: 0;
                margin: 0;
                /* don't use visibility: hidden because it disables tabbing */
                opacity: 0;
                filter: alpha(opacity=0);
            }
            /* filters */
            .tablesorter-default input.tablesorter-filter,
            .tablesorter-default select.tablesorter-filter {
                width: 95%;
                height: auto;
                margin: 4px auto;
                padding: 4px;
                background-color: #fff;
                border: 1px solid #bbb;
                color: #333;
                -webkit-box-sizing: border-box;
                -moz-box-sizing: border-box;
                box-sizing: border-box;
                -webkit-transition: height 0.1s ease;
                -moz-transition: height 0.1s ease;
                -o-transition: height 0.1s ease;
                transition: height 0.1s ease;
            }
            /* rows hidden by filtering (needed for child rows) */
            .tablesorter .filtered {
                display: none;
            }

            /* ajax error row */
            .tablesorter .tablesorter-errorRow td {
                text-align: center;
                cursor: pointer;
                background-color: #e6bf99;
            }
            td.error {
                background-color: #e6bf99;
            }

        </style>

    NWS Source: http://water-md.weather.gov/monitor/ahps_cms_report.php<br>
    USGS Source: http://waterservices.usgs.gov/rest/Site-Service.html<br>
    HADS Source: http://www.nws.noaa.gov/oh/hads/USGS/ALL_USGS-HADS_SITES.txt<br><br>
    Highlight Distance: <input type='text' id='distance' value='0.1' size = '5' onChange='checkDistance();'>miles (<span id='distNum'></span>)<br>
    WFO/RFC Filter:  <input type='text' id='filterVal' value='' size = '5' onChange='filter($(\"#filterVal\").val());'><br>
    <input id = 'btnSubmit' type='submit' value='Only Show Errors' onclick='showErrors()'/>
    <input id = 'btnSubmit' type='submit' value='Show All' onclick='showAll()'/><br>




    $table
</html>";

if(file_put_contents($id,$html)){
    $logger->log("Comparison File: $id",PEAR_LOG_INFO);
}
else{
    $logger->log("Failed to create File: $id",PEAR_LOG_INFO);
}

if(PHP_SAPI !== 'cli'){
    echo $html;
}

$logger->log("STOP",PEAR_LOG_INFO);
?>​
