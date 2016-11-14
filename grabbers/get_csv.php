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

chdir(dirname(__FILE__));



/* Include config file for paths etc..... */
require_once('../config.inc.php');

/* Web Function Library */
require_once(RESOURCES_DIRECTORY."web_functions.php");
$mysqli->select_db("aprfc");

$skipCols = array('recordTime','year','jday','hour','date','time','#','hhmm');

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


function convert($val,$conv){

       $parts = explode(":",$conv);

       if($parts[0] == 'add'){
           $val = $val+$parts[1];
       }
       if($parts[0] == 'sub'){
           $val = $val-$parts[1];
       }
       if($parts[0] == '0gage'){
           $val = $parts[1]-$val;
       }
       if ($conv == 'm:ft'){
            $val = $val*3.28083;
        }
       if ($conv == 'cms:cfs'){
            $val = $val*35.314662;
        }
       if ($conv == 'c:f'){
            $val = ($val*1.8)+32;
        }
       if ($conv == 'mm:in'){
            $val = ($val*0.0393701);
        }
        if ($conv == 'cm:in'){
            $val = ($val*0.3937);
        }
        if ($conv == 'knots:mph'){
            $val = ($val*1.1508);
        }
        if ($conv == 'mps:mph'){
            $val = ($val*2.237);
	}

    return $val;
}





#Get the date from a jday and year
function getDateFromDayHour($year,$dayOfYear,$hour,$minute=0) {

  $date = strtotime("$year-01-01");
  $date = $dayOfYear*86400+$hour*3600+$minute*60+$date;
  return $date;
}


#This takes a data object and returns the shef string
function returnShefString($data,$over = true){
    $R = '';
    if($over) $R = 'R';
    $string = '';
     
 


    foreach($data['data'] as $pe => $value){
        #Kludge below to handle river data in a file that contains all reservoir data
        if($data['lid'] == 'KPHA2' && $pe =='HP') $pe = 'HG';

        $dataElement = '';
        if(strlen($pe)>2){
            #Type source is in PE code
            $dataElement = $pe;
        }
        #else use the main type source for the site 
        else{   
            $dataElement = $pe."I".$data['typeSource']."Z";
        }    
        $string .= ".A$R ".$data['lid']." ".$data['recordTime']."/".$data['dcTime'];
        $string .= "/".$dataElement." ".$value;
        $string .= "\n";

    }
    return $string;
}


$logger->log("START",PEAR_LOG_INFO);

#Setup shef product file
$shefFile =  "SRAK58 PACR ".date('dHi')."\n";
$shefFile .= "ACRRR3ACR \n";
$shefFile .= "WGET DATA REPORT \n\n";

$numLines = 0;

$over = 'R';
$query = "select delimiter,id,lid,timezone,headerLines,commentLines,typeSource,url,decodes,lastIngest,active,readLines,ingestInterval,lastRecordDatetime from csvIngest where active = 1";

$result = $mysqli->query($query) or die($mysqli->error);


//Handle the command line arguments
$opts = getoptreq('s:d', array());

if(!isset($opts["s"])){
    $site = false;
    $logger->log("Checking all csv sites",PEAR_LOG_INFO);
}
else{
    $site = $opts["s"];
    $logger->log("Only checking site: ".$site,PEAR_LOG_INFO);
}

if(isset($opts["d"])){
    $debug = true;
}
else{
    $debug = false;
}



# Procces each site in the configuration table
while ($row = $result->fetch_assoc()){
    //Limit checking to just one site if selected with command line argument
    if($site){
        if($site != $row['lid']) continue;
    }   

    //Check data for the next site from the DB table
    try{    
    $latestRecord = 0;

    //Rows beginning with the comment characters are skipped
    $comments = explode(',',$row['commentLines']);

    //File delimiter
    $dataDelimiter = $row['delimiter'];
    
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
    $logger->log("Url:  {$row['url']}",PEAR_LOG_INFO);
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
        $q = "update csvIngest set lastIngest = '".date('Y-m-d H:i:s')."' where id = '{$row['id']}'";
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

    //Check to see if this is a multi site download location
    $idLookup = array();
    if(preg_match('/idLookup\((.*?)\)/',$row['decodes'],$match)){
        $pairs = explode(':',$match[1]);
        foreach($pairs as $pair){
            $keyval = explode('|',$pair);
            $idLookup[$keyval[0]] = $keyval[1];
        } 
    }

    if(($row['lid'] == 'MULTI') && (count($idLookup)==0)){
        $logger->log("MULTI site with no lookup sites defined!!!",PEAR_LOG_ERR);
        continue;
    }    
        
    if($debug){
        echo "idLookup\n";
        print_r($idLookup);
    }


    #Process each line of the csv data file
    $siteLines = 0;
    $numSkip = 0;
    foreach($lineArray as $line){
        $shefData = array();
        $recordTime;
        $shefStr = '';

        $firstChar = substr($line,0,1);
        if($debug) echo $line."\n";


        
        //Strip double qoutes from each line
        $line = str_replace('"', "",$line);
        $line = str_replace("'", "",$line);

        $data = explode($dataDelimiter,trim($line));
 
        //Make sure it is not a comment line, if so skip it
        //Check the First Character first
        if(in_array($firstChar,$comments)|(strlen($line)<5)) {
            if($debug) echo "Comment Line\n";
            continue;
        } 
        //Check the first data value and see if it is a comment string
        if(in_array($data[0],$comments)) {
            if($debug) echo "Comment Line - indicated by data value\n";
            continue;
        }


        $dateField = array_search('recordTime',$decodes);



        if($dateField === false){
            $yearField = array_search('year',$decodes);
            $jdayField = array_search('jday',$decodes);
            $hourField = array_search('hour',$decodes);
            $hhmmField = array_search('hhmm',$decodes);
            $hours = $data[$hourField];
            $min = 0;
            $year = $data[$yearField];
            $jday = $data[$jdayField]-1;
            if($hhmmField){
                
                $hours = substr($data[$hhmmField],0,-2);
                $min = substr($data[$hhmmField],-2);
            }
            $recordTime = getDateFromDayHour($year,$jday,$hours,$min)+$timeCorrection;
            #$logger->log("No recordTime field defined for site {$row['lid']}",PEAR_LOG_ERR);
            #continue;
        }
        else{
            $recordTime = strtotime($data[$dateField])+$timeCorrection;
        }

        
        if($recordTime > (time()+86400)) {
            #This is a bogus record....continue to the next line
            $numSkip++;
            continue;
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

            #If the code is for idLookup process to get the id based on the idLookup field   
            if(preg_match('/idLookup/',$code,$match)){
#            if($code == 'idLookup'){
                if(array_key_exists($data[$i],$idLookup)){
                    if ($debug) echo "id lookup: ".$idLookup[$data[$i]]."\n";
                    $shefData['lid'] = $idLookup[$data[$i]];
                }else{
                    $logger->log("Problem with site lookup for {$row['url']} site in file not configured: {$data[$i]}",PEAR_LOG_WARNING);
                    break;
                }
                continue;   #Go to next data value
            }

            #If the code is a skip column character move on to the next value in the data string
            if(in_array($code,$skipCols)){

                continue;   #Go to next data value
            }

            #If there is no value move on the the next value in the data string
            if(!isset($data[$i])){
                continue;   #Go to next data value
            }

            $value = trim($data[$i]);
            #if($debug) echo $value;
            #If the value is null replace it with missing
            if($value == 'NULL') $value = -9999;
 
            preg_match('/[^\[]*/',$code,$peMatch);
                
            $pe = $peMatch[0];
            
            
            #$pe = substr($code,0,2);

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
            $q = "update csvIngest set lastRecordDatetime = '".date('Y-m-d H:i:s',$latestRecord)."' where id = '{$row['id']}'";
            if (!$debug) $mysqli->query($q) or die($mysqli->error);
            $numLines++;
            $siteLines++;
            if(isset($shefData['data'])) $shefFile .= returnShefString($shefData,false);
            if($debug) echo "\nShef String: ".returnShefString($shefData,true);
        }

    }

    if($numSkip) $logger->log("$numSkip lines of data from {$row['lid']} skipped because the data is in the future",PEAR_LOG_WARNING);

    $logger->log("$siteLines lines processed for  {$row['lid']}",PEAR_LOG_INFO);
    } catch(Exception $e){
	$logger->log("Exception for {$row['lid']}:".$e,PEAR_LOG_ERR);
    }

}





##############Output Shef File#####################################
$fileName = "sheffile.hd.csv.".date('ymdHi');


/* Write the file to the local temporary location */
file_put_contents(TEMP_DIRECTORY.$fileName, $shefFile);

if($debug) exit;

if($numLines == 0){
	$logger->log("No sites to ingest into AWIPS.",PEAR_LOG_INFO);

}
else{
    file_put_contents(TO_LDAD.$fileName, $shefFile);
    $logger->log("$numLines lines encoded into shef file.",PEAR_LOG_INFO);
}

$logger->log("END",PEAR_LOG_INFO);
?>
