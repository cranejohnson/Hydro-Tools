<!DOCTYPE html>
<html>
<head>
<title>Gage Comparison</title>
<script src="//ajax.googleapis.com/ajax/libs/jquery/1.11.0/jquery.min.js"></script>
<script type="text/javascript" src="https://cdnjs.cloudflare.com/ajax/libs/jquery-scrollTo/2.1.2/jquery.scrollTo.min.js"></script>
<script>
function goTo($id){
    if($id.length == 0)return;
	$id = $id.toUpperCase();
	if ($('#'+$id).length){
		$('body').scrollTo('#'+$id);
		$('#'+$id+'_nav').focus();
	}
	else{
		alert($id+' Not Found');
	}
}
</script>

</head>
<body>
<?php

#Simple script to plot all the images in a directory to an html table.
#Author: Crane Johnson
#Date: 31Mar14
#
#This assumes files are all *.png with the format:
#      {nwsid}_{usgsid}_QR.png
#

#Edit this array below to eliminate highlighting for selected sites
$ignore_errors = array('BRRA2','BRNA2','SCSA2');


echo "<strong>Errors Ignored for: </strong>";
foreach($ignore_errors as $site){
   echo "<strong> $site </strong>";
}


$errorsJson = json_decode(file_get_contents('ahps_usgs_graphs/ahpsErrors.json'),true);


echo "<br><br>Latest Gage-Compare Log File Information<br><br>";

if(count($errorsJson) == 0){
    echo "<p>No Errors during the last run</p>";
}


foreach($errorsJson['sites'] as $site=>$errs){
    if(in_array($site,$ignore_errors)) continue;
    foreach($errs as $err){
        echo "<a href='#$site'>$site</a> $err<br>";
    }
}

?>

<br>Jump to a site: <input type="text" onBlur="goTo($(this).val());" ><i> Enter USGS or NWS ID</a>

<?


$fileList = glob("ahps_usgs_graphs/*.png");
echo '<table border = "1">';
foreach ($fileList as $file){
	$ids = explode('_',basename($file));
	$nwsid = $ids[0];
	$usgsid = $ids[1];

	echo "<tr>";
	echo "<td><a name='$nwsid' ></a>\n";
	echo "<div id='$usgsid'></div><a href='http://water.weather.gov/ahps2/hydrograph.php?gage=$nwsid' target = '_blank'>Link to AHPS $nwsid</a>&nbsp&nbsp\n";
	echo "<a href='http://waterdata.usgs.gov/nwis/uv/?site_no=$usgsid' target = '_blank'>Link to USGS $usgsid</a>\n";
    echo "&nbsp&nbsp&nbsp<a href='../ratViewer.php?USGS=$usgsid' target='_blank'>Rating Viewer</a>";
    echo "&nbsp&nbsp&nbsp<a href='http://amazon.nws.noaa.gov/cgi-bin/hads/interactiveDisplays/displayMetaData.pl?table=dcp&nwsli=$nwsid' target='_blank'>HADS Page</a>";
	echo "&nbsp&nbsp&nbsp&nbsp&nbsp&nbsp<a href='#'>Jump to Top</a></br>\n";
	echo "<img id='$nwsid' src='$file'>\n";
    echo '<br>Jump to a site: <input id=\''.$nwsid.'_nav\' type="text" onBlur="goTo($(this).val());" ><i> Enter USGS or NWS ID</a>';

	echo "</td>";
	echo "</tr>\n\n";
}
echo "</table>";


?>
</body>
</html>
