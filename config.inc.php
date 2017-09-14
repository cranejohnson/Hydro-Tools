<?php
error_reporting(E_ALL);
ini_set('memory_limit', '512M');
set_time_limit(300);
date_default_timezone_set('UTC');

#This file defines global constants that are used for scripts to simplify global changes.
#Every script that uses this should 'require_once('pathto/config.inc.php');

//Options 'FILE'  - File Logging
//        'DB'    - Log to mysql database
//        'NULL'  - No logging

// if 'DB' you need mysql database with table test up according to
// CREATE TABLE log_table (
//    id          INT NOT NULL,
//    logtime     TIMESTAMP NOT NULL,
//    ident       CHAR(16) NOT NULL,
//    priority    INT NOT NULL,
//    message     VARCHAR(200),
//    PRIMARY KEY (id)
// );

define("SHEF_HEADER","SRAK58 PACR ".date('dHi')."\nACRRR3ACR \nWGET DATA REPORT \n\n");


//Constants for Paths
//Assume the config file is always in the project root
define("PROJECT_ROOT",dirname(__FILE__).'/');
define("LOG_DIRECTORY",PROJECT_ROOT."logs/");
define("TEMP_DIRECTORY",PROJECT_ROOT."tmp/");
define("CACHE_DIR",PROJECT_ROOT."cache/");
define("TO_LDAD",PROJECT_ROOT."TO_LDAD/");
define("SWEEP2WEB_DIRECTORY","/hd1apps/data/sweep2web/");
define("TOOLS_DIRECTORY",PROJECT_ROOT."tools/");
define("RESOURCES_DIRECTORY",PROJECT_ROOT."resources/");
define("WEB_DIRECTORY",PROJECT_ROOT."web/");


//Constants for web resources

//AHPS gage report
define("URL_AHPSREPORT","http://water.weather.gov/monitor/ahpsreport.php");
//AHPS notes report
define("URL_AHPSNOTES","http://water.weather.gov/monitor/hydronote_report.php");
//HADS USGS-NWSLID crosswalk table
define("URL_HADSIDLOOKUP","https://hads.ncep.noaa.gov/USGS/");
//Base URL for usgs instant value web service
define("URL_USGSINSTANTVAL","http://waterservices.usgs.gov/nwis/iv/");
//Base URL for AHPS xml  data
define("URL_AHPSXML","http://water.weather.gov/ahps2/hydrograph_to_xml.php");

//Credentials if required in a private directory
define("CREDENTIALS_FILE",PROJECT_ROOT."login.php");

include_once(CREDENTIALS_FILE);
#Credentials File contents:
#define("DB_HOST","localhost");
#define("DB_USER", "username");
#define("DB_PASSWORD","password");
#define("DB_DATABASE","database");

//Setup Output Directory
if (!file_exists(CACHE_DIR)) {
    mkdir(CACHE_DIR, 0777, true);
}

//Setup Output Directory
if (!file_exists(LOG_DIRECTORY)) {
    mkdir(LOG_DIRECTORY, 0777, true);
}





?>
