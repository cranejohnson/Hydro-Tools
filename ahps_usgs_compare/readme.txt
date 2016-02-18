README

This script is a simple php script that compares data from the USGS NWIS system with
data posted by the NWS on AHPS. Sites within each state are determined by using the HADS NWS/USGS lookup tables.

To view results open "show_comparison.php" in a web browser.

Installation Requirements:
PHP 5.x or greater
PEAR
PEAR-Log
PEAR-Cache_Lite

Example command line usage:
//Compare all sites in Alaska
php ahps_usgs_compare.php -a AK
//Compare all sites in Alaska without graphs
php ahps_usgs_compare.php -a AK -g
//Compare one site in Alaska
php ahps_usgs_compare.php -a AK -s SIXA2
