<?php
/**
 * Description: This script performs a soap request to isi-data to retrieve data for 
 * the NPS indian river site.  The script would need to be modified for multiple sites.
 * 
 *
 *
 *
 * @package soap_request 
 * @author Crane Johnson <benjamin.johnson@noaa.gov>
 * @version 0.1
 */


chdir(dirname(__FILE__));

/* Include config file for paths etc..... */
require_once('../config.inc.php');
$mysqli->select_db("aprfc");

require_once 'Log.php';

/**
 * Flag to send data to AWIPS
 */
$sendshef = 1;

/**
 * Number of hours of data to retieve
 */

$hoursback = 12;

/**
 * Setup PEAR logging utility
 */

$conf = array('dsn' => "mysqli://$User:$Passwd@$Host/aprfc",
        'identLimit' => 255);
$logger = Log::singleton('sql', 'log_table', __file__, $conf);
$logger->log("START",PEAR_LOG_INFO);



/**
 * Soap request credentials
 */
$soapUrl = "https://romcomm.net/romrding/romws.asmx?op=GetUnitData"; // asmx URL of WSDL
$User = "cjohnson";  //  username
$Pass = "noaaanc"; // password
$site = "vRID075";

/**
 * Set dates for soap request...currently data less than 2 hours old
 */
$start = date('Y-m-d\TH:i:s.00-08:00',time()-$hoursback*3600);
$end = date('Y-m-d\T00:00:00.00-00:00',time()+48*3600);


/**
 * Create soap request xml string
 */
$xml_post_string = '<?xml version="1.0" encoding="utf-8"?>
                <soap12:Envelope xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xmlns:xsd="http://www.w3.org/2001/XMLSchema" xmlns:soap12="http://www.w3.org/2003/05/soap-envelope">
                  <soap12:Body>
                    <GetUnitData xmlns="https://romcomm.net/romrding/">
                      <UserID>'.$User.'</UserID>
                      <Password>'.$Pass.'</Password>
                      <UnitIdent>'.$site.'</UnitIdent>
                      <StartDate>'.$start.'</StartDate>
                      <EndDate>'.$end.'</EndDate>
                    </GetUnitData>
                  </soap12:Body>
                </soap12:Envelope>';



$headers = array(
            "Content-Type: application/soap+xml;charset=\"utf-8\"",
            "Host: romcomm.net",
            "Content-Length: ".strlen($xml_post_string),
        ); //SOAPAction: your op URL

$url = $soapUrl;

/**
 * Use CURL to send soap request
 */
$ch = curl_init();
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
curl_setopt($ch, CURLOPT_URL, $url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 10);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, $xml_post_string); // the SOAP request
curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
$response = curl_exec($ch);
curl_close($ch);

if($response){
    $logger->log("soap request curl response size:".strlen($response),PEAR_LOG_DEBUG);
}
else{
     $logger->log("soap request failed",PEAR_LOG_ERR);
}


$shefFile =  "SRAK58 PACR ".date('dHi')."\n";
$shefFile .= "ACRRR3ACR \n";
$shefFile .= "WGET DATA REPORT \n\n";

 


if(!preg_match('#<DocumentElement.+?>(.+)</DocumentElement>#',$response,$mat)){
    echo "No data";
    exit();
}    



preg_match_all('#<Data.+?>(.+?)</Data>#',$mat[1],$matches);

$shefstring = '';

/**
 * Process the data and create shef file
 */
 
$numshef = 0;
foreach($matches[1] as $m){
#    if(preg_match('#Level (ft)#',$m)){
    if(1){   
        $over = 'R';
        preg_match('#<Local_time>(.+)</Local_time>#',$m,$mat);
        $date = $mat[1];
        preg_match('/(..):..$/',$date,$d);
        $adjust = 3600;
        $dc = date('\D\CymdHi',time());
        preg_match('#><Value>(.+)</Value>#',$m,$mat);
        $value = $mat[1];
        $shefFile .= ".A$over WSAA2 ". date('ymd \Z \D\HHi',strtotime($date)+$adjust)."/$dc/";
        $shefFile .= "HGIRZZ ".$value."/\n";
        echo ".A$over WSAA2 ". date('ymd \Z \D\HHi',strtotime($date)+$adjust)."/$dc/HGIRZZ ".$value."/\n\n";
        $numshef++;
    }            
}    

$logger->log("$numshef messages decoded with soap request",PEAR_LOG_INFO);
##############Output Shef File#####################################
$fileName = "sheffile.hd.isiData.".date('ymdHi');


/* Write the file to the local temporary location */
file_put_contents(TEMP_DIRECTORY.$fileName, $shefFile);

$path = TEMP_DIRECTORY;



if($sendshef == 0){
#	$logger->log("No sites to ingest to AWIPS, Process Complete!",PEAR_LOG_DEBUG);
 	exit();
}

/* Write the file to the LDAD transher folder */
file_put_contents(TO_LDAD.$fileName, $shefFile);

$logger->log("END",PEAR_LOG_INFO);
    

   
 ?>
