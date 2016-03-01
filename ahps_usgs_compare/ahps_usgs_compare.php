<?php
/**
 * Php Script to Compare USGS and AHPS Data for Consistency
 *
 *
 * @package Hydro_Compare
 * @author Crane Johnson <benjamin.johnson@noaa.gov>
 * @version 0.5
 */


chdir(dirname(__FILE__));


/* Include config file for paths etc..... */
require_once('../config.inc.php');

/* Directory for output graphs */
define("IMAGE_OUTPUT","/hd1apps/data/intranet/html/tools/gagecompare/ahps_usgs_graphs/");
#define("IMAGE_OUTPUT","ahps_usgs_graphs/");

//Pear log package
include_once('Log.php');

//Pear cache_lite package
require_once('Cache/Lite.php');

/* Web Function Library */
require_once(RESOURCES_DIRECTORY."web_functions.php");


//Jpgraph Library Files
require_once(RESOURCES_DIRECTORY.'jpgraph/src/jpgraph.php');
require_once(RESOURCES_DIRECTORY.'jpgraph/src/jpgraph_line.php');
require_once(RESOURCES_DIRECTORY.'jpgraph/src/jpgraph_scatter.php');
require_once(RESOURCES_DIRECTORY.'jpgraph/src/jpgraph_date.php');


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


//Setup Output Directory
if (!file_exists(IMAGE_OUTPUT)) {
    mkdir(IMAGE_OUTPUT, 0777, true);
}



/**
 * Reads in a data object and returns x and y timeseries arrays
 *
 * @param obj $dataObj  Gage compare data object for an individual site
 * @param string $param The parameter pull form the dataObj
 * @return array Returns an array containing two arrays, Xvalues and Yvalues.
 * @access public
 */
function XYarrays($dataObj,$param,$qualifier = NULL){

    $xArray = array();
    $yArray = array();
    foreach($dataObj as $date => $data){
        if(!isset($data[$param])) continue;
        if(!empty($qualifier)){
            //skip qualifiers that don't match the requested
            if($data[$param]['q'] != $qualifier){
                $xArray[] = $date;
                $yArray[] = '';
                 continue;
            }
        }
        //Check if the value is missing...if so set to 0 for plotting
        if(isset($data[$param]['val'])){
            $xArray[] = $date;
            if($data[$param]['val'] != -9999){
                $yArray[] = $data[$param]['val'];
            }
            else{
                $yArray[] = 0;
            }
        }
    }
    return array($xArray,$yArray);
}


function findDiff($ahpsSite,$usgsSite,$param){
    $diff = array();
    foreach($ahpsSite as $date => $data){
        if(isset($data[$param])& isset($usgsSite[$date][$param])){
            $nws = floatval($data[$param]['val']);
            $usgs = floatval($usgsSite[$date][$param]['val']);
            if($nws > 0) $diff[$date]['diff']['val'] = round($nws - $usgs,2);
            if($nws > 0 && $usgs > 0){
                 $diff[$date]['diff']['percent'] = round((($nws - $usgs)/$usgs)*100,1);
             }
             else{
                 $diff[$date]['diff']['percent'] = '';
             }


    }
  }
  ksort($diff);
  return $diff;
}


##########
#
#  MAIN PROGRAM
#
##########

$usgsPeriod = 'P7D';

$logger->log("START",PEAR_LOG_INFO);

$opts = getoptreq('a:s:g', array());

if(!isset($opts["a"])){
    $logger->log("No area defined to check! (eg: -a AK)",PEAR_LOG_WARNING);
    exit;
}

$state = strtoupper($opts["a"]);

$filter = array(
    'column' => 'state',
    'value'  => $state
    );


if(isset($opts["s"])){
    $siteCheck = strtoupper($opts["s"]);
    $logger->log("Only checking $siteCheck",PEAR_LOG_INFO);
}

if(isset($opts["g"])){
    $makeGraphs = false;
    $logger->log("Do not create graphs",PEAR_LOG_INFO);
}
else{
    $makeGraphs = true;
    $logger->log("Create graphs",PEAR_LOG_INFO);
}

/* Get the Statewide table of gages from HADS*/
$siteInfo = getHADS_NWSLID_Lookup($state,1);

//Get AHPS Gage Report
$ahpsReport = getAHPSreport(1,$filter);

//Get AHPS Notes
$hydroNotes = getAHPSNotes(3600);

//Object to hold error between USGS and AHPS data
$jsonError = array();

$logger->log(count($siteInfo['sites'])." sites in HADS table. ",PEAR_LOG_INFO);

$usgs = getUSGS($usgsPeriod,$state);

$logger->log(count($usgs)." sites in USGS 7-Day file. ",PEAR_LOG_INFO);


function yLabelFormat($aLabel) {
  return Date ("M j \n H:i",$aLabel);
}


//Counter for the number of sites processes
$i=0;

//Process each NWS AHPS site.........
foreach($siteInfo['sites'] as $nws => $site){
    if(isset($siteCheck)){
        if($nws != $siteCheck) continue;
    }

    $logger->log("Working on: ".$nws." - ".$site['usgs'],PEAR_LOG_DEBUG);

    //Check if this is an ahps site

    if(isset($hydroNotes['sites'][$nws]) == false && isset($ahpsReport['sites'][$nws]) == false){
        $logger->log("$nws is not an AHPS Site",PEAR_LOG_DEBUG);
        continue;
    }

    if(!$nws) continue;

    $index = intval($site['usgs']);
    $generate = false;
    $diff = 0;
    $i++;

    // Create a new timer instance
    $datemax = strtotime('today midnight')+24*3600;
    $datemin = $datemax-6*24*3600;
    $created = date("F j, Y, g:i a");


    //Get AHPS XML data for a particular site
    $ahps = getAhpsData($nws,$logger);


    //If we can't get the AHPS xml file continue to the next site....
    if(!$ahps){
        $logger->log("Failed to get AHPS XML for site:".$nws,PEAR_LOG_WARNING);
        $jsonError['warning']['sites'][$nws][] = "Failed to get AHPS XML for site:".$nws;
        continue;
    }

    if(!isset($usgs[$index])){
        $logger->log("No USGS data for site:".$nws,PEAR_LOG_WARNING);
        #$jsonError['sites'][$nws][] = "No USGS data for site:".$nws;
    }

    //print_r($usgs[$index]);
    $nwsNote = '';
    //Check if AHPS notes include a statement about ice
    if($ahps[$nws]['inService'] && isset($hydroNotes['sites'][$nws])){
        for($z=1;$z<=2;$z++){
            $nwsNote .= 'Note'.$z.": ";
            $note = 'note'.$z;
             if(($hydroNotes['sites'][$nws][$note]['active'] == 1)){
                $nwsNote .= $hydroNotes['sites'][$nws][$note]['text']."\n";
             }
        }
    }

    if(!$ahps[$nws]['inService']){
        $nwsNote= "AHPS gage 'Out of Service'";
        $logger->log("AHPS gage our of Service: ".$nws,PEAR_LOG_DEBUG);

    }


     //Check if USGS is plotting Discharge and/or stage
    $lastUSGS =array();
    $lastAHPS =array();


    if(isset($usgs[$index]['data'])){
        $lastUSGS = end($usgs[$index]['data']);
        reset($usgs[$index]['data']);
    }

    if(isset($ahps[$nws]['data'])){
        $datemin = key($ahps[$nws]['data']);
        $lastAHPS = end($ahps[$nws]['data']);
        reset($ahps[$nws]['data']);
    }


    //Assume all false and test for true condition
    $USGS_HG = False;
    $USGS_QR = False;
    $AHPS_HG = False;
    $AHPS_QR = False;

    if(array_key_exists('HG',$lastUSGS) && ($lastUSGS['HG']['q'] == "P")){
        $USGS_HG = True;
    }
    if(array_key_exists('QR',$lastUSGS) && ($lastUSGS['QR']['q'] == "P")){
        $USGS_QR = True;
    }
    if(array_key_exists('QR',$lastAHPS)){
        $AHPS_QR = True;
    }
    if(array_key_exists('HG',$lastAHPS)){
        $AHPS_HG = True;
    }


    //Get the latest Stage Values for NWS and USGS
    $stagetxt = '';
    $stageDiff = array();
    if(isset($ahps[$nws]['data']) & isset($usgs[$index]['data'])){

        //Find the difference between all USGS and AHPS stages where readings are concurrent
        $qDiff = findDiff($ahps[$nws]['data'],$usgs[$index]['data'],'QR');
        if($USGS_QR && $AHPS_QR){
            $mostRecent = end($qDiff);
            if((abs($mostRecent['diff']['percent']) > 3) && (abs($mostRecent['diff']['val']) > 3)) {
                $logger->log("Discharge difference of ".$mostRecent['diff']['percent']."% or ".$mostRecent['diff']['val']." cfs for site ".$nws,PEAR_LOG_ERR);
                $jsonError['sites'][$nws][] = "Discharge differnce of ".$mostRecent['diff']['percent']."% or ".$mostRecent['diff']['val']." cfs";
          }
        }
    }
        //Find the difference between all USGS and AHPS stages where readings are concurrent
    if($USGS_HG && $AHPS_HG){
        $stageDiff = findDiff($ahps[$nws]['data'],$usgs[$index]['data'],'HG');
        $diffDate = max(array_keys($stageDiff));
        $diff = $stageDiff[$diffDate]['diff']['val'];
        if(abs($diff) > 0.05){
            $logger->log("Stage difference of ".$diff." ft for site ".$nws,PEAR_LOG_ERR);
            $jsonError['sites'][$nws][] = "Stage difference of ".$diff." ft";
        }
        $stagetxt .=  "Latest Stage Comparision ".date('dM H:i',$diffDate)."\n";
        $stagetxt .=  "NWS = ".$ahps[$nws]['data'][$diffDate]['HG']['val']."\n";
        $stagetxt .= "USGS = ".$usgs[$index]['data'][$diffDate]['HG']['val']."\n";
        $stagetxt .= "Diff: $diff";
    }
    else{
        $stagetxt = "No overlaping Stages to Compare\n";
        if($AHPS_HG) $stagetxt .=  "NWS = ".$lastAHPS['HG']['val']."\n";
        if($USGS_HG) $stagetxt .=  "USGS = ".$lastUSGS['HG']['val']."\n";

    }

    $max = 0;
    $min = 999999;




    //Test for differences between USGS and AHPS
    if($USGS_HG && !$AHPS_HG){
        if($ahps[$nws]['inService']){
            $logger->log("USGS publishing stage and NWS is NOT for site: ".$nws,PEAR_LOG_ERR);
            $jsonError['sites'][$nws][] = "USGS publishing stage and NWS is NOT";
        }else{
            $logger->log("USGS publishing stage and NWS site is not in service: ".$nws,PEAR_LOG_ERR);
            $jsonError['sites'][$nws][] = "USGS publishing stage and NWS is not in service";
        }
    }

    if(!$USGS_HG && $AHPS_HG){
        if(!isset($usgs[$index])){
            $logger->log("NWS publishing stage and USGS $index is not valid ".$nws,PEAR_LOG_ERR);
            $jsonError['sites'][$nws][] = "NWS publishing stage and USGS $index is not valid $nws";
        }elseif($usgs[$index]['inService']){
            $logger->log("NWS publishing stage and USGS site is not: ".$nws,PEAR_LOG_ERR);
            $jsonError['sites'][$nws][] = "NWS publishing stage and USGS is not";
        }else{
            $logger->log("NWS publishing stage and USGS has discontinued this site ".$nws,PEAR_LOG_ERR);
            $jsonError['sites'][$nws][] = "NWS publishing stage and USGS has discontinued this site";
        }
    }

   if($USGS_QR && !$AHPS_QR){
        if($ahps[$nws]['inService']){
            $logger->log("USGS publishing discharge and NWS is NOT in service: ".$nws,PEAR_LOG_ERR);
            $jsonError['sites'][$nws][] = "USGS publishing discharge and NWS is NOT";
        }else{
            $logger->log("USGS publishing discharge and NWS site is not in service: ".$nws,PEAR_LOG_ERR);
            $jsonError['sites'][$nws][] = "USGS publishing discharge and NWS is not in service";
        }
    }

    if(!$USGS_QR && $AHPS_QR){
        if(!isset($usgs[$index])){
            $logger->log("NWS publishing discharge and USGS $index is not valid ".$nws,PEAR_LOG_ERR);
            $jsonError['sites'][$nws][] = "NWS publishing dischare and USGS $index is not valid $nws";
        }elseif($usgs[$index]['inService']){
            $logger->log("NWS publishing dicharge and USGS site is not: ".$nws,PEAR_LOG_ERR);
            $jsonError['sites'][$nws][] = "NWS publishing discharge and USGS is not";
        }else{
            $logger->log("NWS publishing discharge and USGS has discontinued this site ".$nws,PEAR_LOG_ERR);
            $jsonError['sites'][$nws][] = "NWS publishing discharge and USGS has discontinued this site";
        }
    }


    if($makeGraphs){

        //Delete all of the older PNG files
        $files = glob(IMAGE_OUTPUT."/*".$site['usgs']."*.png");
        foreach($files as $file) {
            unlink($file);
        }

        //Generage the graphs using JpGraph library
        $graph = new Graph(800,500);
        $graph->SetMargin(100,50,40,10);
        if($USGS_QR || $AHPS_QR){
            $graph->SetScale('datlin',0,0,$datemin,$datemax);
        }
        else{
            $graph->SetScale('datlin',0,1,$datemin,$datemax);
        }
        $graph->legend->SetFont(FF_ARIAL,FS_BOLD,12);
        $graph->img->SetAntiAliasing(false);
        $graph->title->SetFont(FF_ARIAL,FS_BOLD,12);
        $graph->title->Set($ahps['name']);
        $graph->SetBox(true,'black',2);
        $graph->SetClipping(true);
        $graph->subtitle->SetFont(FF_ARIAL,FS_BOLD,10);
        $graph->subtitle->Set($index." - ".$nws);


        $graph->footer->left->set('Created:'.$created." UTC");

        //Add AHPS notes to chart
        $txt = new Text($nwsNote);
        $txt->SetFont(FF_ARIAL,FS_BOLD,10);
        $txt->SetPos(0.1,0.97,'left','bottom');
        $txt->SetBox('lightblue','black');
        $txt->SetWordWrap(100);
        $graph->AddText($txt);



        //Add latest stage comparision to chart

        $txtb = new Text($stagetxt);
        $txtb->SetFont(FF_ARIAL,FS_NORMAL,10);
        if(abs($diff) > 0.05){
               $txtb->SetBox('lightred','black',0,0);
        }
        $txtb->SetPos(0.13,0.09,'left','top');
        $graph->AddText($txtb);

        //Configure X-axis
        $graph->xaxis->SetFont(FF_ARIAL,FS_BOLD,10);
        $graph->xaxis->SetPos('min');
        $graph->xaxis->scale->SetDateFormat("M j \n H:i");
        $graph->xaxis->scale->SetDateAlign(DAYADJ_1,DAYADJ_1);
        $graph->xgrid->SetLineStyle('dashed');
        $graph->xgrid->SetColor('gray');
        $graph->xgrid->Show();
        $graph->xscale->ticks->Set(3600*24,0);

        //Configure Y-axis
        $graph->yaxis->SetTitle('Discharge (cfs)','middle');
        $graph->yaxis->SetFont(FF_ARIAL,FS_BOLD,12);
        $graph->yaxis->title->SetFont(FF_ARIAL,FS_BOLD,14);
        $graph->yaxis->scale->SetGrace(20,0);
        $graph->yaxis->SetTitlemargin(70);
        $graph->SetFrame(true,'darkblue',0);


        $generate = false;

        //Plot AHPS Data
        if($AHPS_QR){
            $graphData = XYarrays($ahps[$nws]['data'],'QR');
            $line = new LinePlot(array(0),array(0));
            if(count($graphData[0]) > 0){
                $line = new LinePlot($graphData[1],$graphData[0]);

                $line->SetLegend('NWS');
                $graph->Add($line);
                $line->SetColor("red");
                $line->SetWeight(3);
                $generate = true;
            }
        }


        //IF USGS IS PUBLISHING DISCHARGE ADD TO GRAPH
        if(isset($usgs[$index]['qualifiers']['QR'])){
            foreach($usgs[$index]['qualifiers']['QR'] as $qualifier){

                $graphData = XYarrays($usgs[$index]['data'],'QR',$qualifier);

                if(count($graphData[0]) > 0){
                    if($qualifier == 'P'){
                        $line = new LinePlot($graphData[1],$graphData[0]);
                        $line->SetLegend('USGS '.$qualifier);
                        $graph->Add($line);
                        $line->SetColor("blue");
                        $line->SetWeight(2);
                        $generate = true;
                    }
                    else{
                        $scatter = new ScatterPlot($graphData[1],$graphData[0]);
                        $scatter->SetLegend('USGS '.$qualifier);
                        $graph->Add($scatter);
                        $scatter->mark->SetColor("brown");
                        $scatter->mark->SetFillColor("brown");
                        $scatter->mark->SetSize(10);
                        $generate = true;
                    }
                }
            }
        }

        //Plot stage data if needed
#        if(count($stageDiff)>0 && $generate){
         if(count($stageDiff)>0){
            $graphData = XYarrays($stageDiff,'diff');
            //print_r($graphData);
            $graph->SetYScale(0,'lin',-2,2);
            $graph->ynaxis[0]->SetTitle('Stage Difference [ft]','middle');
            $graph->ynaxis[0]->SetFont(FF_ARIAL,FS_BOLD,10);
            $graph->ynaxis[0]->title->SetFont(FF_ARIAL,FS_BOLD,12);
            $graph->ynaxis[0]->SetTitlemargin(40);
            $line = new LinePlot($graphData[1],$graphData[0]);
            $line->SetLegend('HG Diff');
            $line->SetColor("green");
            $line->SetWeight(3);
            $graph->AddY(0,$line);
            $generate = true;
        }

        $generate = true;
        $outfile = IMAGE_OUTPUT.$nws."_".$site['usgs']."_QR.png";
        $graph->legend->Pos(0.1,0.1);
        if($generate) {
            $graph->Stroke($outfile);
            $logger->log("Saved chart for $nws",PEAR_LOG_DEBUG);
        }
        unset($graph);
    }
}

file_put_contents(IMAGE_OUTPUT.'ahpsErrors.json',json_encode($jsonError));
$logger->log("Completed comparing $i site(s)! ",PEAR_LOG_INFO);
$logger->log("Peak memory: ".memory_get_peak_usage(true),PEAR_LOG_INFO);
$logger->log("END",PEAR_LOG_INFO);


?>​
