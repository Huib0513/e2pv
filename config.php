<?php
define('IDCOUNT', 4);
define('APIKEY', 'a2dfaff1fda45ac9ecd17f60737953cfdf5d26f1');
define('SYSTEMID', '36997');

define('LIFETIME', 1);       // see README.md
define('MODE', 'AGGREGATE'); // 'AGGREGATE' or 'SPLIT'
define('EXTENDED', 0);       // Send state data? Uses donation only feature
// AC is default 0. See README.md
define('AC', 0);             // Send DC data or AC (DC * Efficiency)

// If mode is SPLIT, define the Enecsys ID to PVOutput SystemID mapping for each
// inverter.
$systemid = array(
  110106912 => 36997,
  110103981 => 36997,
  110103907 => 36997,
  110133184 => 36997
);

// If mode is SPLIT, optionally define the Enecsys ID to APIKEY mappings
// If an id is not found, the default APIKEY from above is used.
//$apikey = array(
// NNNNNNNNN => 'hhhhhhhhhhhhhhhhhhhhhhhhhhhhhhhhhhhhhhhh',
// NNNNNNNNN => 'hhhhhhhhhhhhhhhhhhhhhhhhhhhhhhhhhhhhhhhh',
//);

// The following inverter ids are ignored (e.g. the neighbours' ones)
$ignored = array(
// NNNNNNNNN,
// ...
);


// Optional MySQL defs, uncomment to enable MySQL inserts, see README.md
define('MYSQLHOST', '192.168.143.111');
define('MYSQLUSER', 'datalogger');
define('MYSQLPASSWORD', 'Z0iKswDUoXZo9QqYoI0B');
define('MYSQLDB', 'HomeData');
define('MYSQLPORT', '3307');

// Optional OpenHab defs, uncomment to enable OpenHab updates
define('OPENHABHOST', '192.168.143.128');
define('OPENHABITEM', 'enecsys_raw_input');
define('OPENHABPORT', '8088');
?>
