<?php

//----------------------------------------------------------------------------------------------------------
// Configuration, customize for your situation
//----------------------------------------------------------------------------------------------------------
date_default_timezone_set('America/Los_Angeles');   // We use this to set the correct time on your weather station
$station_serial=pack("H*" , "D6724855C1B79313");    // Set your station serial number here
$ping_interval = 300; //How often gateway reports (in seconds) (set in 00:70 reply)
$sensor_interval = chr(4); // Minutes between packets - 1
$history_interval = chr(0x03);
/* History interval sent in 14:01 reply
// Byte 0x1f seem to set the history save interval:
//   0x00 --  1 minute
//   0x01 --  5 minutes
//   0x02 -- 10 minutes
//   0x03 -- 15 minutes (weatherdirect.com default) 
//   0x04 -- 20 minutes
//   0x05 -- 30 minutes
//   0x06 -- 1 hour
//   0x07 -- 2 hours (changes every even hour, local time)
*/

// Output flags													
const OF_RTG = 0x01; // Reply to gateway
const OF_WDB = 0x02; // Write to database
const OF_DBG = 0x04; // Write to wstation.log file
const OF_WUG = 0x08; // Send to Weather Underground
const OF_LAC = 0x10; // Send to Lacrosse server
const OF_RES = 0x30; // Send Lacrosse response instead (forces OF_LAC)
const DBG_LVL = 2;
$OF = OF_RTG | OF_WDB | OF_DBG;
// | OF_WUG;
