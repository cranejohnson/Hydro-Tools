<?php

//error_reporting(0);

require_once(RESOURCES_DIRECTORY.'rdb/rdb.php');

// Get the site number from the client
$my_site = htmlspecialchars($_GET['site']);
$type = htmlspecialchars($_GET['type']);

// Construct the URL
if($type == 'rating'){
    $my_url="http://waterdata.usgs.gov/nwisweb/get_ratings?file_type=exsa&site_no=".$my_site;
}
elseif($type == 'qmeas'){
    $my_url = "http://waterdata.usgs.gov/nwis/measurements?agency_cd=USGS&format=rdb&site_no=".$my_site;
}


$my_rdb = new rdb($my_url);			// Fetch this RDB file and load it into an object


// Finally, output as JSON to the client
echo $my_rdb->outputJSON(TRUE,TRUE,FALSE,TRUE,FALSE,TRUE,TRUE); 			// Output the data as JSON

?>
