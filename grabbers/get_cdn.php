<?php

/**
 * Description: This script reads data from canadian stations and converts it from xml
 * to shef messages.
 *
 *
 * @package get_cdn
 * @author Crane Johnson <benjamin.johnson@noaa.gov>
 * @version 0.1
 */

 $debug = false;

/* Include config file for paths etc..... */
require_once('../config.inc.php');

$mysqli->select_db("aprfc");

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


$logger->log("START",PEAR_LOG_INFO);


$latest_dir = "http://dd.weather.gc.ca/observations/swob-ml/latest/";

$stations = array(
    "CVBR" =>	"Burwash RCS",
    "CVFN" =>	"Fort Nelson",
    "CVFS" =>	"Fort St. JamesAUTO",
    "CVXY" =>	"Whitehorse AUTO",
    "CVYD" =>	"Smithers Arpt AUTO",
    "CVZY" =>	"Mackenzie Arpt Auto",
    "CWEK" =>	"Grey Islet",
    "CWHL" =>	"Holland Rock",
    "CWHT" =>	"Haines Jct",
    "CWJU" =>	"Langara Island",
    "CWKX" =>	"Dease Lake Arpt AUTO",
    "CWNV" =>	"MacMillam Pass",
    "CWOI" =>	"Ivvavik",
    "CWON" =>	"Dawson City",
    "CWPZ" =>	"Burns Lake",
    "CWRO" =>	"Rose Spit",
    "CWRR" =>	"Rock River",
    "CWUM" =>	"Faro RCS",
    "CWZW" =>	"Teslin Arpt AUTO",
    "CXCK" =>	"Carmacks Arpt",
    "CXQH" =>	"Watson Lake",
    "CXTV" =>	"Trail Valley Creek",
    "CZEV" =>	"Inuvik Climate",
    "CZGH" =>	"Fort Good Hope",
    "CZOC" =>	"Old Crow Arpt RCS",
    "CYPR" =>   "Prince Rupert"
    );


 $grabValues = array(
    "air_temp"              =>  "TA",
    "pcpn_amt_pst1hr"       =>  "PP",
    "snw_dpth"              =>  "SD"
    );



$shefFile =  "SRAK58 PACR ".date('dHi')."\n";
$shefFile .= "ACRRR3ACR \n";
$shefFile .= "WGET DATA REPORT \n\n";

$numLines = 0;


$over = 'R';



#This takes a data object and returns the shef string
function returnShefString($data,$over = true){
    $R = '';
    if($over) $R = 'R';
    $string = '';
    $duration = "I";

    if(!isset($data['dcTime'])) $data['dcTime'] = date('\D\CymdHi');


    foreach($data['data'] as $pe => $value){
        if($pe  == 'PP') $duration = 'H';
        #Kludge below to handle river data in a file that contains all reservoir data
        if($data['lid'] == 'KPHA2') $pe = 'HG';
        $string .= ".A$R ".$data['lid']." ".$data['recordTime']."/".$data['dcTime'];
        $string .= "/".$pe.$duration.$data['typeSource']."Z ".round($value,1);
        $string .= "\n";

    }
    return $string;
}

foreach($stations as $cdnId => $name){

        $tempData = array();
        $xmlFile = $cdnId.'-AUTO-swob.xml';

        $cdnXML = @file_get_contents($latest_dir.$xmlFile);
        if(!$cdnXML) {
            $logger->log("Failed to get data for $cdnId from $xmlFile",PEAR_LOG_WARNING);
            continue;
        }
        $xml = new SimpleXMLElement($cdnXML);
        //Get the metadata and stick it into a temporary data array
        foreach ($xml->children('om', true)->member->Observation->metadata->children()->set->{'identification-elements'}->children() as $children) {
           $tempData[(string)$children['name']] = (string)$children['value'];
        }
        //Get all the measured observations and stick
       foreach ($xml->children('om', true)->member->Observation->result->children()->elements->children() as $child){
            $tempData[(string)$child['name']] = (string)$child['value'];
       }

       $data = array();
       $data['lid'] = $cdnId;
       $data['recordTime'] = date('ymd \Z \D\HHi',strtotime($tempData['date_tm']));
       $data['typeSource'] = 'RR';
       foreach($grabValues as $cdnId => $shefId){
            if(!isset($tempData[$cdnId])) continue;
            if($tempData[$cdnId] == 'MSNG') {
                $data['data'][$shefId] = -9999;
                continue;
            }
            if($cdnId == 'air_temp'){
                $data['data'][$shefId] = ((9/5)* $tempData[$cdnId] + 32);
            }
            if($cdnId == 'snw_dpth'){
                $data['data'][$shefId] = $tempData[$cdnId]*0.393701;
            }
            if($cdnId == 'pcpn_amt_pst1hr'){
                $data['data'][$shefId] = $tempData[$cdnId]*0.0393701;
            }

        }



        if(isset($data['data'])){
            $shefFile .= returnShefString($data,true);
  //          $logger->log("Grabbed data for $cdnId",PEAR_LOG_INFO);
            $numLines++;
        }
        else{
           $logger->log("Failed to grab data for $cdnId",PEAR_LOG_WARNING);
        }

}



$logger->log("$numLines sites process for Canadian sites ingest",PEAR_LOG_INFO);



if($debug) {
    echo $shefFile;
    exit;
}

##############Output Shef File#####################################
$fileName = "sheflocal.cdn.csv.".date('ymdHi');




if($numLines == 0){
    echo "No Shef Data\n";
	$logger->log("No sites to ingest into AWIPS.",PEAR_LOG_INFO);

}
else{
    file_put_contents(TO_LDAD.$fileName, $shefFile);
    $logger->log("$numLines lines encoded into shef file.",PEAR_LOG_INFO);
}

$logger->log("END",PEAR_LOG_INFO);



/* $matches = array();


#preg_match_all("/(a href\=\")([^\?\"]*.xml)(\")/i",file_get_contents("$latest_dir"), $matches);

$allData = array();

$table = array("id","stn_nam","stn_elev","lat","long","date_tm","air_temp","avg_cum_pcpn_gag_wt_fltrd_mt55","pcpn_amt_pst1hr","rnfl_amt_pst1hr","rnfl_snc_last_syno_hr","pcpn_snc_last_syno_hr","snw_dpth");





foreach($matches[2] as $match)
   {
       $data = array();
       $cdnXML = file_get_contents($latest_dir.$match);
       $xml = new SimpleXMLElement($cdnXML);
       foreach ($xml->children('om', true)->member->Observation->metadata->children()->set->{'identification-elements'}->children() as $children) {
           $data[(string)$children['name']] = (string)$children['value'];
        }
       foreach ($xml->children('om', true)->member->Observation->result->children()->elements->children() as $child){
            $data[(string)$child['name']] = (string)$child['value'];
       }
       if(isset($data['tc_id'])) $data['id'] = $data['tc_id'];
       if(isset($data['icao_stn_id'])) $data['id'] = $data['icao_stn_id'];
       if($data['lat'] < 54) continue;
       if($data['long'] > -120) continue;
       $allData[$data['id']] = $data;
   }

echo "<table border = '1'><tr><td></td>";
foreach ($table as $col){
    echo "<td>$col</td>";
}
echo "</tr>";

foreach ($allData as $site => $data){
    echo "<tr><td><a href='$latest_dir$match'>File</a></td>";
    foreach ($table as $col){
        if(!isset($data[$col])) {
            echo "<td>NA</td>";
        }
        else{
            echo "<td>$data[$col]</td>";
        }
    }
    echo "</tr>";
}
echo "</table>";
*/
?>