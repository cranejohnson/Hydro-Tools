<?php
/**
 * Description: This script reads configuration information from Redrock mysql database and then
 * gets and processes the information to convert csv files to shef messages.
 *
 *
 *

 *
 * @package get_csv
 * @author Crane Johnson <benjamin.johnson@noaa.gov>
 * @version 0.1
 */


/* Include config file for paths etc..... */
require_once('../config.inc.php');

/* Web Function Library */
require_once(RESOURCES_DIRECTORY."web_functions.php");
$mysqli->select_db("aprfc");

$debug = false;

$skipCols = array('recordTime','year','jday','hour','#');

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


function convert($val,$conv){

       $parts = explode("-",$conv);

       if($parts[0] == 'add'){
           $val = $val+$parts[1];
       }
       if($parts[0] == 'sub'){
           $val = $val-$parts[1];
       }
       if ($conv == 'm-ft'){
            $val = $val*3.28083;
        }
       if ($conv == 'cms-cfs'){
            $val = $val*35.314662;
        }
       if ($conv == 'c-f'){
            $val = ($val*1.8)+32;
        }
       if ($conv == 'mm-in'){
            $val = ($val*0.0393701);
        }
        if ($conv == 'cm-in'){
            $val = ($val*0.3937);
        }
        if ($conv == 'knots-mph'){
            $val = ($val*1.1508);
        }
        if ($conv == 'mps-mph'){
            $val = ($val*2.237);
	}

    return $val;
}



$logger->log("START",PEAR_LOG_INFO);

$shefFile =  "SRAK58 PACR ".date('dHi')."\n";
$shefFile .= "ACRRR3ACR \n";
$shefFile .= "WGET DATA REPORT \n\n";

$numLines = 0;


$over = 'R';
$query = "select id,lid,timezone,headerLines,commentLines,typeSource,url,decodes,lastIngest,active,readLines,ingestInterval,lastRecordDatetime,idLookup from csvIngest where active = 1";

$result = $mysqli->query($query) or die($mysqli->error);


#Get the date from a jday and year
function getDateFromDayHour($year,$dayOfYear,$hour) {

  $date = strtotime("$year-01-01");
  $date = $dayOfYear*86400+$hour*3600+$date;
  return $date;
}


#This takes a data object and returns the shef string
function returnShefString($data,$over = true){
    $R = '';
    if($over) $R = 'R';
    $string = '';



    foreach($data['data'] as $pe => $value){
        #Kludge below to handle river data in a file that contains all reservoir data
        if($data['lid'] == 'KPHA2') $pe = 'HG';
        $string .= ".A$R ".$data['lid']." ".$data['recordTime']."/".$data['dcTime'];
        $string .= "/".$pe."I".$data['typeSource']."Z ".$value;
        $string .= "\n";

    }
    return $string;
}



# Procces each site in the configuration table
while ($row = $result->fetch_assoc()){
    #if($row['lid'] != 'LOWA2') continue;

    $latestRecord = 0;

    //Rows beginning with the comment characters are skipped
    $comments = explode(',',$row['commentLines']);

    #Get the time correction and ingest interval
    $timeCorrection = -($row['timezone']*3600);
    $interval = strtotime("2014-1-1 ".$row['ingestInterval']) -strtotime("2014-1-1 00:00:00");

    #Skip grabbing data if the time isn't right
    if(time()<(strtotime($row['lastRecordDatetime'])+$interval)){
	$logger->log("Skipping {$row['lid']} interval not reached",PEAR_LOG_INFO);
	continue;
    }

    #Download the CSV file
    $logger->log("Fetching Data for {$row['lid']}",PEAR_LOG_INFO);
    if($debug) echo "Fetching Data for {$row['lid']}\n";
    $shefStr = '';
    $csvFile = trim(file_get_contents($row['url']));
    if(!$csvFile) {
        $logger->log("Could not download data for {$row['lid']}",PEAR_LOG_ERR);
        continue;
    }


    if($debug) echo "File size: ".strlen($csvFile)."\n";
    $logger->log("File size of ".strlen($csvFile)." for {$row['lid']}",PEAR_LOG_DEBUG);

    if(!$debug){
        $q = "update csvIngest set lastIngest = '".date('Y-m-d H:i:s')."' where lid = '{$row['lid']}'";
        $mysqli->query($q) or die($mysqli->error);
    }

    #Explode CSV file by lines
    $lineArray = explode(PHP_EOL,$csvFile);
    $lineArray = array_slice($lineArray,$row['headerLines']);
    if($row['readLines']>0){
        $lineArray = array_slice($lineArray,0,$row['readLines']);
    }
    else {
        $lineArray = array_slice($lineArray,$row['readLines']);
    }

    if($debug) echo "Lines of data to process: ".count($lineArray)."\n";

    #Explode the decodes into array
    $decodes = explode(',',$row['decodes']);

    if($debug){
        echo "decodes:\n";
        print_r($decodes);
    }

    $idLookup = array();
    if (in_array('idLookup',$decodes)){
        $temp = explode(',',$row['idLookup']);
        foreach ($temp as $a) {
            $b = explode('|', $a);
            $idLookup[trim($b[0])] = trim($b[1]);
        }
    }
    if($debug){
        echo "idLookup\n";
        print_r($idLookup);
    }


    #Process each line of the csv data file
    foreach($lineArray as $line){
        $shefData = array();
        $recordTime;
        $shefStr = '';

        $firstChar = substr($line,0,1);
        if($debug) echo $line."\n";



        //Make sure it is not a comment line, if so skip it
        if(in_array($firstChar,$comments)|(strlen($line)<5)) {
            if($debug) echo "Comment Line\n";
            continue;
        }

        //Strip double qoutes from each line
        $line = str_replace('"', "",$line);
        $line = str_replace("'", "",$line);

        $data = explode(',',trim($line));




        $dateField = array_search('recordTime',$decodes);



        if($dateField === false){
            $yearField = array_search('year',$decodes);
            $jdayField = array_search('jday',$decodes);
            $hourField = array_search('hour',$decodes);
            $recordTime = getDateFromDayHour($data[$yearField],($data[$jdayField]-1),$data[$hourField])+$timeCorrection;
            #$logger->log("No recordTime field defined for site {$row['lid']}",PEAR_LOG_ERR);
            #continue;
        }
        else{
            $recordTime = strtotime($data[$dateField])+$timeCorrection;
        }



        $shefData['lid'] = $row['lid'];
        $shefData['typeSource'] = $row['typeSource'];
        $shefData['recordTime'] = date('ymd \Z \D\HHi',$recordTime);
        $shefData['dcTime'] = date('\D\CymdHi');


        if($debug) echo $shefData['recordTime']."\n";

        #Check and see if this row is new data....if not continue to the next row of data
        if(($recordTime <= strtotime($row['lastRecordDatetime'])) && ($shefData['lid'] != 'MULTI')){
            if($debug) echo "Skipping data Already Processed\n";
            continue;

        }





        #Process each line of the csv file and create a shef string...
        $hasData = 0;

        for($i=0;$i<count($data);$i++) {
            if(!isset($decodes[$i])){
                continue;
            }
            $code = trim($decodes[$i]);
            #if($debug) echo $code;

            if($code == 'idLookup'){
                if(array_key_exists($data[$i],$idLookup)){
                    if ($debug) echo "id lookup: ".$idLookup[$data[$i]]."\n";
                    $shefData['lid'] = $idLookup[$data[$i]];
                }else{
                    $logger->log("Problem with site lookup for {$row['lid']} sitename: {$data[$i]}",PEAR_LOG_WARNING);
                    break;
                }
                continue;   #Go to next data value
            }

            if(in_array($code,$skipCols)){

                continue;   #Go to next data value
            }


            if(!isset($data[$i])){
                continue;   #Go to next data value
            }

            $value = trim($data[$i]);
            #if($debug) echo $value;
            #If the value is null replace it with missing
            if($value == 'NULL') $value = -9999;

            $pe = substr($code,0,2);

            #Get the unit conversion and convert data if required
            preg_match('/\[(.+)\]/',$code,$match);
            if(isset($match[1])) $value = convert($value,$match[1]);

            $shefData['data'][$pe] = round($value,2);
            $hasData++;

        }


        if($hasData){
            if($recordTime > $latestRecord){
                $latestRecord = $recordTime;
                if($debug) echo "Latest Record: ".date('Y-m-d H:i:s',$latestRecord);
            }

            #Update the csv data table with the most recently record time so that only new data is pushed over to LDAD.
            $q = "update csvIngest set lastRecordDatetime = '".date('Y-m-d H:i:s',$latestRecord)."' where lid = '{$row['lid']}'";
            if (!$debug) $mysqli->query($q) or die($mysqli->error);
            $numLines++;
            if(isset($shefData['data'])) $shefFile .= returnShefString($shefData,false);
            if($debug) echo "Shef String: ".returnShefString($shefData,true);
        }

    }

    $logger->log("$numLines lines processed for  {$row['lid']}",PEAR_LOG_INFO);
}



if($debug) exit;

##############Output Shef File#####################################
$fileName = "sheffile.hd.csv.".date('ymdHi');


/* Write the file to the local temporary location */
file_put_contents(TEMP_PATH.$fileName, $shefFile);

if($numLines == 0){
    echo "No Shef Data\n";
	$logger->log("No sites to ingest into AWIPS.",PEAR_LOG_INFO);

}
else{
    echo $shefFile;
    file_put_contents(TO_LDAD.$fileName, $shefFile);
    $logger->log("$numLines lines encoded into shef file.",PEAR_LOG_INFO);
}

$logger->log("END",PEAR_LOG_INFO);
?>
