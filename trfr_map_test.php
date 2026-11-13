<?php

include 'common.inc';

$tfr_url	= $argv[1];
echo "URL: $tfr_url\n";

$map_string	= experimental_build_map_string($conn, $tfr_url);

echo $map_string."\n";


?>