<?php

chdir(dirname(__FILE__));

/* Include config file for paths etc..... */
require_once('../config.inc.php');

$mysqli->select_db("atlas14data");

date_default_timezone_set('UTC');

//Pear log package
require_once 'Log.php';

$conf = array(	'mode' => 0600,
	       	'timeFormat' => '%X %x');

$logger = Log::singleton('file',LOG_DIRECTORY.basename(__FILE__,'.php').'.log',__FILE__,$conf);




function dbInsert($mysqli,$table,$array,$logger){
	$values = '';
	$fields = '';
	$result = 0;

	foreach($array as $key => $value){
		$fields .= $key.",";
		$values .= "'".$value."',";
	}
	$fields = rtrim($fields,',');
	$values = rtrim($values,',');
	$insertquery = "INSERT INTO $table ($fields) VALUES ($values)";
	$result = $mysqli->query($insertquery);
#	echo $insertquery."\n";
	if($mysqli->error){
		$logger->log("dbinsert error:".$mysqli->error,PEAR_LOG_ERR);
	}
	return $result;
	}


function loadMeta($mysqli,$logger){

	foreach (glob("*.meta") as $filename){
		$parts = explode('.',$filename);
		$interval = $parts[0];
		$filestring = file_get_contents($filename);
		$allmeta = explode(PHP_EOL,$filestring);
		$num = 0;
		foreach ($allmeta as $sitemeta){
			if(strlen($sitemeta)<10) continue;
			$db = array();
			$db['intval'] = $interval;
			$db['name'] = trim(substr($sitemeta,9,25));
			$sitemeta = substr_replace($sitemeta,'',9,25);
			$fields = preg_split('/\s+/',$sitemeta);
			$db['state'] = $fields[2];
			$db['nwsid'] = $fields[3];
			$db['latitude'] = $fields[4];
			$db['longitude'] = $fields[5];
			$db['elevation'] = $fields[6];
			$temp = explode('/',$fields[7]);
			$db['begin'] = $temp[1]."/".$temp[0]."/0";
                        $temp = explode('/',$fields[9]);
			$db['end'] = $temp[1]."/".$temp[0]."/0";
			$db['type'] = $fields[10];
			$db['inventory'] = $fields[11];
		        if(dbInsert($mysqli,'siteinfo',$db,$logger)) $num++;  	
			
		}
		$logger->log("Meta data imported for: $num sites",PEAR_LOG_INFO);
	}
}

function ListIn($dir, $prefix = '') {
	$dir = rtrim($dir, '\\/');
	$result = array();
	foreach (scandir($dir) as $f) {
		if ($f !== '.' and $f !== '..') {
			if (is_dir("$dir/$f")) {
				$result = array_merge($result, ListIn("$dir/$f", "$prefix$f/"));
			} else {
				$result[] = $prefix.$f;
			}
		}
	}
	return $result;
}

function loadData($mysqli,$logger,$type){

	$files = ListIn('./');
	$num = 0;
	foreach($files as $file){
		echo "$file\n";
		$precip = -99;
		if(preg_match('/.hly$/',$file) & ($type == 'hourly')){
		$string = file_get_contents($file);
		$alldata = explode(PHP_EOL,$string);
		foreach($alldata as $data){
			$insert = array();
			if(strlen($data)<10) continue;
			$insert['nwsid'] = str_replace('-','',trim(substr($data,0,8)));
			$year = substr($data,8,4);
			$month = trim(substr($data,12,2));
			$day = trim(substr($data,14,2));
			for ($i=0;$i<24;$i++){
				$loc = ($i)*4+16;
				$precip = substr($data,$loc,4);
				$insert['pp'] = $precip;
				$insert['recordTime'] = "$year/$month/$day $i:00";
                                if(dbInsert($mysqli,'hourly',$insert,$logger)) $num++;
                                
			}
		}
		}
		if(preg_match('/.dly$/',$file) & ($type == 'daily')){
			$string = file_get_contents($file);
			$alldata = explode(PHP_EOL,$string);
			foreach($alldata as $data){
				$insert = array();
				if(strlen($data)<10) continue;
				$insert['nwsid'] = str_replace('-','',trim(substr($data,0,8)));
				$year = trim(substr($data,8,4));
				$month = trim(substr($data,12,2));
				for($i=1;$i<=31;$i++){
					$loc = 14+(($i-1)*4);
					$precip = substr($data,$loc,4);
					$insert['pp']= substr($data,$loc,4);
					$insert['recordTime']="$year/$month/$i";
					if(dbInsert($mysqli,'daily',$insert,$logger)) $num++;
				}

			}
		}
	}
}

#loadMeta($mysqli,$logger);
#loadData($mysqli,$logger);

$deleteFiles = 'keepem';
if(isset($argv[3]))$deleteFiles = $argv[3];
$startdate = strtotime($argv[1]);
$enddate = strtotime($argv[2]);

$dir = "/usr/local/apps/scripts/bcj/noaa_atlas14_data/outputFiles/";


$time = $startdate;

if($deleteFiles == 'load'){
      loadData($mysqli,$logger,'hourly');
      exit();
}


if($deleteFiles == 'deleteold') array_map('unlink', glob("$dir*"));

while($time <= $enddate){
	$mysqltime = date('Y-m-d H:i',$time);
	$filename = date('YmdH',$time);
	$file =  fopen($dir."noaa14Atlas".$filename,"w");
	$query = "Select siteinfo.latitude,siteinfo.longitude,hourly.pp,hourly.nwsid from hourly,siteinfo where hourly.nwsid = siteinfo.nwsid and hourly.recordTime = '$mysqltime'";
	$result = $mysqli->query($query) or die($mysqli->error);
	while($row= $result->fetch_assoc()){
		fwrite($file,$row['nwsid']." NOTUSED ".$row['latitude']." ".$row['longitude']." ".date('Y-m-d H\z')." ".($row['pp']/100)."\n");
	}
	fclose($file);
	if(date('H',$time) == 0){
		$filename = date('Ymd24',$time);
		$dailyfile = fopen($dir."noaa14Atlas".$filename,"w");
	 	$query = "Select siteinfo.latitude,siteinfo.longitude,daily.pp,daily.nwsid from daily,siteinfo where daily.nwsid = siteinfo.nwsid and daily.recordTime = '$mysqltime'";
 		$result = $mysqli->query($query) or die($mysqli->error);
 		while($row= $result->fetch_assoc()){
                	fwrite($dailyfile,$row['nwsid']." NOTUSED ".$row['latitude']." ".$row['longitude']." ".date('Y-m-d H\z')." ".($row['pp']/100)."\n");
		}
		fclose($dailyfile);
	}		

	$time = $time + 3600;
}	


?>

