<?php


/**
* Get options from the command line or web request
*
* @param string $options
* @param array $longopts
* @return array
*/

function getoptreq ($options, $longopts)
{
   if (PHP_SAPI === 'cli' || empty($_SERVER['REMOTE_ADDR']))  // command line
   {
      return getopt($options, $longopts);
   }
   else if (isset($_REQUEST))  // web script
   {
      $found = array();

      $shortopts = preg_split('@([a-z0-9][:]{0,2})@i', $options, 0, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY);
      $opts = array_merge($shortopts, $longopts);

      foreach ($opts as $opt)
      {
         if (substr($opt, -2) === '::')  // optional
         {
            $key = substr($opt, 0, -2);

            if (isset($_REQUEST[$key]) && !empty($_REQUEST[$key]))
               $found[$key] = $_REQUEST[$key];
            else if (isset($_REQUEST[$key]))
               $found[$key] = false;
         }
         else if (substr($opt, -1) === ':')  // required value
         {
            $key = substr($opt, 0, -1);

            if (isset($_REQUEST[$key]) && !empty($_REQUEST[$key]))
               $found[$key] = $_REQUEST[$key];
         }
         else if (ctype_alnum($opt))  // no value
         {
            if (isset($_REQUEST[$opt]))
               $found[$opt] = false;
         }
      }

      return $found;
   }

   return false;
}

/**
 * Handles requesting data from the USGS web service as a zipped file, deflates and returns file contents
 *
 * @param string $url Url of the site to get data from, includes parameters
 * @param obj $logger PEAR event logging object
 * @return string Text string of the data returned from the url
 * @access public
 */
function get_usgs_zipped($logger,$url){

    $opts = array(
         'http'=>array(
             'method'=>"GET",
             'header'=>"Accept-Encoding: gzip, compress")
      );

    $context = stream_context_create($opts);
    $start = time();
    $content = file_get_contents($url ,false,$context);
    if ($content === false) {
       $logger->log("Failed to get USGS Data from $url",PEAR_LOG_ERR);
       return false;
    }

    $downloadTime = time()-$start;
    $logger->log("USGS data downloaded in $downloadTime seconds.",PEAR_LOG_INFO);

    //If http response header mentions that content is gzipped, then uncompress it
    foreach($http_response_header as $c => $h)
    {
        if(stristr($h, 'content-encoding') and stristr($h, 'gzip'))
        {
            //Now lets uncompress the compressed data
            $content = gzinflate( substr($content,10,-8) );
        }
    }

    return $content;
}


function get_URL_Cache($url,$options){

    global $logger;  //Global pear logger class

    $Cache_Lite = new Cache_Lite($options);

    if($cache_data = $Cache_Lite->get($id)){
        $timestamp = date('Y-m-d H:i',$Cache_Lite->lastModified($id));
        $logger->log("Using cached($timestamp) date from:$url",PEAR_LOG_INFO);
    }
    else{
        $cache_data = file_get_contents($url);
        if ($cache_data === false) {
            $logger->log("Failed to get $url",PEAR_LOG_ERR);
            //Extend Life of Old file
            $Cache_Lite->extendLife();
            $cache_data = $Cache_Lite->get($id);
            if(!$cache_data){
                $logger->log("No data: $url",PEAR_LOG_ERR);
                return false;
            }

            $logger->log("Using old cached version:$url",PEAR_LOG_WARNING);
        }
    }
    return $cache_data;
}



/**
 * Get HADS USGS-NWSLI Cross Reference Table
 *
 * @param obj $logger PEAR event logging object
 * @param string $stat Two letter state abbreviation or 'ALL' for all sites
 * @param int $age If > 0 use cache with this age, if 0 do not use cache, defualt: 1day
 * @return array Array of current USGS sites with corresponding NWSLI id
 * @access public
 */
function getHADS_NWSLID_Lookup($state,$age=86400){

    $url = URL_HADSIDLOOKUP;

    global $logger;  //Global pear logger class

    $state = strtoupper($state);
    $id = 'HADS_LOOKUP_'.$state;
    $options = array(
        'cacheDir' => CACHE_DIR,
        'lifeTime' =>$age,
        'fileNameProtection' => false
    );

    $Cache_Lite = new Cache_Lite($options);
    $textdata = '';

    $sites = array();

    if($cache_data = $Cache_Lite->get($id)){
        $timestamp = date('Y-m-d H:i',$Cache_Lite->lastModified($id));
        $sites = json_decode($cache_data,true);
        $logger->log("Using HADS lookup table cached: $timestamp",PEAR_LOG_INFO);
        $sites['notes'] = "Using HADS lookup table cached: $timestamp";
    }
    else{
        $sites = array();
        $start = time();
        $url .= $state."_USGS-HADS_SITES.txt";
        $textdata = file_get_contents($url);
        if ($textdata === false) {
            $logger->log("Failed to get HADS Crosswalk Table from $url",PEAR_LOG_ERR);
            //Extend Life of Old file
            $Cache_Lite->extendLife();
            $cache_data = $Cache_Lite->get($id);
            $sites = json_decode($cache_data,true);
            $logger->log("Using HADS lookup table cached: $timestamp",PEAR_LOG_INFO);
            $sites['notes'] = "Using HADS lookup table cached: $timestamp";
        }
        else{
            $downloadTime = time()-$start;
            $logger->log("HADS Lookup Download in $downloadTime seconds.",PEAR_LOG_INFO);
        }
        $sites = array();
        $sites['columns'] = array('usgs','name','hsa','lat','lon');

        //Remove the first four lines of the file that are header information and place
        // remaining lines into an array.
        $siteInfo = array_slice(explode("\n", $textdata), 4);
        $i=0;
        foreach($siteInfo as $site){
            $parts = explode("|",$site);
            if (count($parts) < 6) continue;
            $sites['sites'][$parts[0]]['usgs'] =  trim($parts[1]);
            $sites['sites'][$parts[0]]['name'] = $parts[6];
            $sites['sites'][$parts[0]]['hsa'] = $parts[3];
            $vals = explode(' ',trim($parts[4]));
            $sites['sites'][$parts[0]]['lat'] = intval($vals[0])+(intval($vals[1])+intval($vals[2])/60)/60;
            $vals = explode(' ',trim($parts[5]));
            $sites['sites'][$parts[0]]['lon'] = intval($vals[0])+(intval($vals[1])+intval($vals[2])/60)/60;
            $i++;
        }

        $timestamp = date('Y-m-d H:i');
        $sites['cached'] = $timestamp;
        if($Cache_Lite->save(json_encode($sites),$id)){
        }
        else{
            $logger->log("HADS Cache failed to save.",PEAR_LOG_ERR);

        }
    }

    return $sites;
}


/**
 * Get USGS Data using water web services.  Data requested as a zip file to minimize
 * bandwidth.
 *
 * @param int $days The number of days to request
 * @param obj $logger PEAR event logging object.
 * @param string $location two letter state abbreviation or usgs location code
 * @return array Associative array of USGS data for each site.
 * @access public
 */
function getUSGS($period,$location){

    $url = URL_USGSINSTANTVAL;
    $url .= "?format=waterml,1.1";
    $url .= "&period=$period&parameterCd=00060,00065&siteStatus=active";

    global $logger;  //Global pear logger class

    $usgs = array();

    if(strlen($location) == 2){
        $url .=  "&stateCd=$location";
    }
    elseif(is_numeric($location)){
        $url = "&sites=$location";
    }

    //Get the usgs data via a zipped file from NWIS
    $data = get_usgs_zipped($logger,$url);

    // Remove the namespace prefix for easier parsing
    $data = str_replace('ns1:','', $data);

    // Load the XML returned into an object for easy parsing
    $xml_tree = simplexml_load_string($data);
    if ($xml_tree === FALSE)
    {
        $logger->log("Failed to get USGS Data from $url",PEAR_LOG_ERR);
        return false;
    }


    foreach ($xml_tree->timeSeries as $site_data)
    {
        $varcode = $site_data->variable->variableCode;
        if($varcode == '00060') $shef = 'QR';
        if($varcode == '00065') $shef = 'HG';
        $siteid = intval($site_data->sourceInfo->siteCode);
        $noDataVal = floatval($site_data->variable->noDataValue);
        $usgs[$siteid]['name'] = (string)$site_data->sourceInfo->siteName;
        $usgs[$siteid]['inService'] = true;
        $usgs[$siteid]['qualifiers'][$shef] = array();
        foreach($site_data->values->qualifier as $qualifier){
            if(preg_match('/discontinued/',$qualifier->qualifierDescription,$match)){
                $usgs[$siteid]['inService'] = false;
            }
        }

        foreach($site_data->values->value as $val){
            $date;


            if ($val['dateTime'] <> '')
            {
                $tzoff = 0;
                $date = substr($val['dateTime'],0,10);
                $time = substr($val['dateTime'],11,15);
                $date = strtotime($date." ".$time) - $tzoff*3600;
            }

            if(floatval($val) != $noDataVal){
                $usgs[$siteid]['data'][$date][$shef]['val'] = floatval($val);
            }
            else{
                $usgs[$siteid]['data'][$date][$shef]['val'] = 0;
            }

            $qualifier = (string)$val['qualifiers'];
            $usgs[$siteid]['data'][$date][$shef]['q'] = $qualifier;


            if(!isset($usgs[$siteid]['qualifiers'][$shef])){
                echo "$shef...";
                $usgs[$siteid]['qualifiers'][$shef] = array($qualifier);
            }
            else{
                if(!in_array($qualifier, $usgs[$siteid]['qualifiers'][$shef]))  $usgs[$siteid]['qualifiers'][$shef][] = $qualifier;
            }

        }
        if(isset($usgs[$siteid]['data'])) ksort($usgs[$siteid]['data']);
    }


    return $usgs;
}


/**
 * Filters strings that begin with '#'
 *
 * @param string $string string to apply filter too
 * @return string returns the string if if does not begin with '#' or false if it does
 * @access public
 */
function myFilter($string) {
    return (substr($string,0,1) != '#');
}

/**
 * Read USGS RDB File and return array
 *
 * @param string $rdbString rdb string
 * @return array Associative array of USGS data for each site.
 * @access public
 */
function getUSGS_siteInfo($url){

    global $logger;  //Global pear logger class


    $rdbArray =array();
    $textData = get_usgs_zipped($logger,$url);
    $lines = explode("\n", trim($textData));
    $datalines = array_filter($lines, 'myFilter');
    $columns = explode("\t",array_shift($datalines));

    $usgsCol = array_search('site_no',$columns);
    array_shift($datalines);

    foreach($datalines as $line){
        $data = array();
        $values = explode("\t",$line);
        $i=0;
        foreach($columns as $col){
            $data[$col] = $values[$i];
            $i++;
        }
        $rdbArray[$values[$usgsCol]] = $data;
    }

    return $rdbArray;
}


/**
 * Get AHPS data for one particular site using the AHPS xml data pages.
 *
 * @param string $siteid  NWS site indentification code
 * @param obj $logger Event logging object.
 * @return array Associative array of AHPS data for each site.
 * @access public
 */
function getAhpsData($siteid){

    global $logger;  //Global pear logger class

    $ahps = array();
    if(!$siteid) return;

    $url = URL_AHPSXML;

    $url .="?gage=".$siteid."&output=xml";

    $xmlstr = file_get_contents($url);

    if ($xmlstr === false) {
        $logger->log("Failed to get NWS data for $siteid",PEAR_LOG_ERR);
        return false;
    }
    else{
        $logger->log("Downloaded XML NWS data for $siteid",PEAR_LOG_DEBUG);
    }

    try{
        $siteData = new SimpleXMLElement($xmlstr);
    } catch (Exception $e){
        $logger->log("$siteid XML error: ".$e->getMessage(),PEAR_LOG_ERR);
    }

    if(!isset($siteData->observed->datum)){
        $ahps[$siteid] = array();
    }

    $ahps['name'] = $siteData['name'];
    $ahps[$siteid]['inService'] = true;
    if($siteData->message == 'No results found for this gage.'){
        $ahps[$siteid]['inService'] = false;
        return $ahps;
    }

    if($siteData->observed == 'Gauge is currently out of service.'){
        $ahps[$siteid]['inService'] = false;
        return $ahps;
    }


    $ahps['gageDatum'] = $siteData->zerodatum;

    foreach($siteData->observed->datum as $value){
        if($value->secondary['units'] == 'cfs'){
            $mult = 1;
        }
        else{
            $mult = 1000;
        }
        $discharge = floatval($value->secondary)*$mult;

        if(isset($value->secondary) && ($discharge != -999000)) $ahps[$siteid]['data'][strtotime($value->valid)]['QR']['val'] = $discharge;
        $ahps[$siteid]['data'][strtotime($value->valid)]['HG']['val'] = floatval($value->primary);
    }


    return $ahps;
}


/**
 * Get AHPS Report data.
 *
 *
 * @param obj $logger Event logging object.
 * @param string $url AHPS report url
 * @param int $age If > 0 use cache with this age, if 0 do not use cache
 * @return array Associative array of AHPS Report data for each site.
 * @access public
 */
function getAHPSreport($age = 86400){

    $url = URL_AHPSREPORT;
    $url .= "?type=csv";
    global $logger;  //Global pear logger class


    $id = 'AHPS_stage_flow';
    $options = array(
        'cacheDir' => CACHE_DIR,
        'lifeTime' => $age,
        'fileNameProtection' => false
    );

    $Cache_Lite = new Cache_Lite($options);
    $resultArray = array();
    $numOut = 0;
    $textdata = '';

    if( $cache_data = $Cache_Lite->get($id)){
        $resultArray = json_decode($cache_data,true);
        $timestamp = date('Y-m-d H:i',$Cache_Lite->lastModified($id));
        $logger->log("Using AHPS report table cached: $timestamp",PEAR_LOG_INFO);
        $timestamp = date('Y-m-d H:i',$Cache_Lite->lastModified($id));
    }
    else{
        $start = time();
        $textdata = file_get_contents($url);
        if ($textdata === false) {
           $logger->log("Failed to get AHPS report from $url",PEAR_LOG_ERR);
        }
        else{
            $resultArray = array();
            $downloadTime = time()-$start;
            $siteInfo = array_slice(explode("\n", trim($textdata)), 0);
            $parts = str_getcsv($siteInfo[0],",",'"');
            foreach($parts as $p){
            $resultArray['columns'][] = preg_replace("/[^A-Za-z0-9]/",'',$p);
        }

        array_shift($siteInfo);

        foreach($siteInfo as $site){
            $parts = str_getcsv($site,",",'"');
            $nws = strtoupper($parts[3]);
            $i=0;
            foreach($resultArray['columns'] as $col){
                $resultArray['sites'][$nws][$col] = $parts[$i];
                $i++;
            }
            $resultArray['sites'][$nws]['name'] = $parts[2]." ".$parts[1]." ".$parts[0];
        }

        $resultArray['columns'][] = 'name';
        $logger->log("AHPS report in $downloadTime seconds.",PEAR_LOG_INFO);
        $resultArray['outOfService'] = $numOut;
        $timestamp = date('Y-m-d H:i');
        $resultArray['cached'] = $timestamp;
        $Cache_Lite->save(json_encode($resultArray),$id);
        }
    }

    return $resultArray;
}



function parseTable($html){
  libxml_use_internal_errors(true);

  /* Remove <nobr> tags, these are not strict HTML */

  $html = str_replace('<nobr>','',$html);
  $html = str_replace('</nobr>','',$html);
  $html = preg_replace('/&(?!amp;)/', '&amp;', $html);
  $notes = array();                         //Array to keep note information.
  $dom = new domDocument;
  $dom->loadHTML($html);  //Required to account for rouge & chars in html links.
  $dom->preserveWhiteSpace = false;
  $tables = $dom->getElementsByTagName('table');
  $rows = $tables->item(0)->getElementsByTagName('tr');

  foreach($rows as $row){


        $cols = $row->getElementsByTagName('td');

        if ($cols->length == 0) continue;

    $nwslid = strtoupper($cols->item(0)->nodeValue);
    $notes['sites'][$nwslid]['note1']['text'] = $cols->item(2)->nodeValue;
    $notes['sites'][$nwslid]['note2']['text'] = $cols->item(3)->nodeValue;
    $div1 = $cols->item(2)->getElementsByTagName('div');
    $div2 = $cols->item(3)->getElementsByTagName('div');

    if($div1->item(0)->getAttribute('class') == "not_in_season"){
      $notes['sites'][$nwslid]['note1']['active'] = 0;
    }
    else{
      $notes['sites'][$nwslid]['note1']['active'] = 1;
    }

    if($div2->item(0)->getAttribute('class') == "not_in_season"){
      $notes['sites'][$nwslid]['note2']['active'] = 0;
    }
    else{
      $notes['sites'][$nwslid]['note2']['active'] = 1;
    }


    }
    return (array)$notes;
}


/**
 * Get AHPS notes for all sites
 *
 * @param obj $logger Event logging object.
 * @param string $url Url to AHPS notes csv file
 * @param int $age If > 0 use cache with this age, if 0 do not use cache
 * @return array Associative array of AHPS data for each site.
 * @access public
 */
function getAHPSNotes($age = 86400){

    $url = URL_AHPSNOTES;
    global $logger;  //Global pear logger class


    $id = 'hydro_notes';

    $options = array(
        'cacheDir' => CACHE_DIR,
        'lifeTime' => $age,
         'fileNameProtection' => false
        );

    $cache = new Cache_Lite($options);

    $resultArray = array();

    if($cache_data = $cache->get($id)){

        $resultArray = json_decode($cache_data,true);
        $timestamp = date('Y-m-d H:i',$cache->lastModified($id));
        $logger->log("Using AHPS notes table cached: $timestamp",PEAR_LOG_INFO);
        $resultArray['notes'] = "Using the cached AHPS <a href='".$url."'>Note Report </a> from $timestamp.";

    }
    else{
      $resultArray = array();
      $start = time();

      $ahpsNotes = '';
      $ahpsNotes = file_get_contents($url);
      if ($ahpsNotes === false) {
        $resultArray['notes']= "Failed to get hydro notes from <a href = '".$config->config->notes_url."'>".$config->config->notes_url."</a><br>";
        $logger->log("Failed to download AHPS Notes Table from: $url",PEAR_LOG_ERR);
      }
      else{
          $downloadTime = time()-$start;
          $logger->log("Downloaded AHPS Notes Table in $downloadTime seconds",PEAR_LOG_INFO);

          $resultArray = parseTable($ahpsNotes);
          $processTime = time()-$start;
          $resultArray['notes']  = "AHPS <a href='".$url."'>Note Report </a> downloaded in $downloadTime seconds.<br>";
          $timestamp = date('Y-m-d H:i');
          $resultArray['cached'] = $timestamp;
          if($cache->save(json_encode($resultArray))){
            $logger->log("Saved AHPS notes table to cache",PEAR_LOG_INFO);
          }else{
            $logger->log("Failed to save AHPS notes table to cache",PEAR_LOG_ERR);
        }

      }
    }


  return $resultArray;
}
?>