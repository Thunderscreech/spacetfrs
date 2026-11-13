<?php
//Take a TFR URL and render an airspace
include 'common.inc';

//https://tfr.faa.gov/save_pages/detail_3_5448.html

echo build_map_string($conn, $argv[1]);

?>