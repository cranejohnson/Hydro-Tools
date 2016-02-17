<?
//Simple script to get ahps data and avoid cross site request
$site = $_GET['site'];
$ahps_xml = $homepage = file_get_contents("http://water.weather.gov/ahps2/hydrograph_to_xml.php?gage=$site&output=xml");
echo $ahps_xml;
?>
