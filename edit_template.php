<?php

include 'common.php';
session_stuff();

$incident_text	= mysqli_real_escape_string($conn,$_REQUEST['incident_text']);
$template_id	= $_REQUEST['template_id'];

$sqlstring	= "UPDATE call_templates SET incident_text = '$incident_text' WHERE id = '$template_id'";

echo $sqlstring;
?>