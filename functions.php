<?php

function checksum8($buffer) { // Simple 8 bit checksum
    $count=strlen($buffer);
    $byte_array=unpack('C*',$buffer);
    $sum=0;
    for($i=1;$i<=$count;$i++)
    {
        $sum=$sum+$byte_array[$i];
    }
    return($sum&0xff);
}
function checksum16($buffer) { // Simple 16 bit checksum
	$len=strlen($buffer);
    $sum=0;
    for($i=0;$i<$len;$i++)
    {
        $sum+=ord($buffer[$i]);
    }
    return($sum&0xffff);
}
function bcd2int($bcd) { // Convert bcd to integer
	return intval(bin2hex($bcd));
}
function hex2degC($hex) { // Convert BCD string(3) 10 * (deg C + 40)
	if (strlen($hex) != 3) return null;
	// Check for wind chill error
	if (0 == strcmp($hex, 'AAA')) return null;
	return round(intval($hex)/10 - 40, 1);
}
function C2F($C) { // Convert deg C to deg F
	return round($C * 1.8 + 32.0, 1);
}
function hex2degF($hex) { // Convert BCD string(3) in C to F
	$C = hex2degC($hex);
	if (!isset($C)) return null;
	return C2F($C);
}
function dewpoint($t, $rh) { // Approximate dewpoint from T and RH (in deg C)
	// Constants from wikipedia.org
	$b = 17.67;
	$c = 243.5;
	$gamma = log($rh/100) + ($b * $t)/($c + $t);
	return round(($c * $gamma)/($b - $gamma), 1);
}
function heatindex($t, $rh) { // T in deg F
	//Valid for T>80 F and RH>40%
	if ($t < 80 || $rh < 40) return $t;
	//{HI} = c_1 + c_2 T + c_3 R + c_4 T R 
	// + c_5 T^2 + c_6 R^2 + c_7 T^2R + c_8 T R^2 + c_9 T^2 R^2\ \, 
	//Constants from NOAA
	$c = [ 0, -42.379, 2.04901523, 10.14333127, -0.22475541, 
		-6.83783E-3, -5.481717E-2, 1.22874E-3, 8.5282E-4, -1.99E-6 ];
	$t2 = pow($t,2);
	$rh2 = pow($rh,2);
	$hi = $c[1] + $c[2]*$t + $c[3]*$rh + $c[4]*$t*$rh
		+ $c[5]*$t2 + $c[6]*$rh2 + $c[7]*$t2*$rh + $c[8]*$t*$rh2 + $c[9]*$t2*$rh2;
	return round($hi,1);
}
function updatelog($message, $level = 1) { // Write to wstation.log
	global $OF;
	if ($level > DBG_LVL || !($OF & OF_DBG)) return;
	file_put_contents('wstation.log', $message, FILE_APPEND);
}
function wdb() { // Create database connection
	global $wdb;
	$wdb = null; // Close any existing connection
	try {
		$wdb = new PDO('mysql:dbname=weather');
	} catch (PDOException $e) {
		updatelog("wdb connect error: ".$e->getMessage(), 0);
		return false;
	}
	return true;

}
function getstation($mac) {
	global $wdb, $station;
	if (!isset($wdb)) if (!wdb()) return false;
	try {
		$mac_query = "SELECT * FROM `stations` WHERE `mac` = '$mac'";
		$pds = $wdb->query($mac_query);
	} catch (PDOException $e) {
		updatelog("Failed to get station info ".$e->getMessage()."\n", 0);
		return false;
	}
	if (!$station = $pds->fetch(PDO::FETCH_ASSOC)) return false;
	$pds->closeCursor();
	return true;
}
function updatepkt($packettype) { // Saves packet data, returns packet id
	global $wdb, $id1, $id2, $postdata, $stationid;
	if (!isset($wdb)) if (!wdb()) return null;
	
	$http_id_short = $id1.':'.$id2;

	// INSERT INTO packets stationid, timestamp, packettype values(55,now(),2);
	$sql = 'INSERT INTO `packets` (`stationid`, `timestamp`, `packettype`)'
		." VALUES($stationid, NOW(), $packettype)";
	//updatelog($sql."\n", 2);
	try {
		$wdb->exec($sql);
	} catch (PDOException $e) {
		updatelog("Insert packet: ".$e->getMessage(), 0);
		return null;
	}
	$pid = $wdb->lastInsertId();
	if ($pid == 0) {
		updatelog("Bad PID\n", 0);
		return null;
	}	
	
	// Update the hourly table
	if ($packettype == 2) {
		$wdb->exec("INSERT INTO `hourly`
		SET stations_id = $stationid, 
		midnight = TO_DAYS(NOW()), 
		`hour` = HOUR(NOW()),
		packets_id = $pid");
	}
	
	// We already have the data for the history packets
	if ($packettype == 9) return $pid;
	
	$sql = 'INSERT INTO `packetdump` (pid, http_id, payload, packettype)'
		.' VALUES(?, ?, ?, ?)';
	try {
		$pds = $wdb->prepare($sql);
		$pds->bindParam(1, $pid, PDO::PARAM_INT);
		$pds->bindParam(2, $http_id_short);
		$pds->bindParam(3, $postdata);
		$pds->bindParam(4, $packettype, PDO::PARAM_INT);
		$pds->execute();
	} catch (PDOException $e) {
		// This isn't fatal
		updatelog("Insert packetdump: ".$e->getMessage(), 0);
	}
	// Leave connection open
	return $pid;
}
function fixuprain($pid) { // Fix up weekly and monthly rainfall to be running totals
	global $wdb, $sdp, $stationid;
	// Check to see if the last rain reset date was reset in this packet
	$sql = "SELECT MAX(`date`) as lrr FROM `v_packets_sensordatevalues` 
	WHERE did = 1 AND stationid = $stationid";
	try {
		$pds = $wdb->query($sql);
		$pds->bindColumn(1, $dts);  // sometimes $pds is null here.
		$pds->fetch(PDO::FETCH_BOUND);
		$dt_lrr = new DateTime($dts);
		updatelog("Last rain reset from db: ".$dt_lrr->format('Y-m-d H:i:s')."\n",2);
		$pds->closeCursor();
	} catch (PDOException $e) {
		updatelog("Select rain reset:".$e->getMessage(),0);
		$dt_lrr = $sdp['dt_last_rain_reset'];
	}
	if ($dt_lrr->getTimestamp() < $sdp['dt_last_rain_reset']->getTimestamp()) {
		updatelog("Rain total date has been reset\n", 1);
		$dt_lrr = $sdp['dt_last_rain_reset'];
		
		// Get prior value
		$prior_value = 0;
		$sql = "
		SELECT timestamp, value FROM v_packets_sensorvalues
		WHERE sid = 9 AND stationid = $stationid
		AND timestamp <'".$dt_lrr->format('YmdHis')."'
		ORDER by timestamp desc LIMIT 1
		";
		try {
			$pds = $wdb->query($sql);
			$pds->bindColumn(2, $prior_value);
			$pds->fetch(PDO::FETCH_BOUND);
			$pds->closeCursor();
		} catch (PDOException $e) {
			updatelog("Failed to retrieve prior rain total: ".$e->getMessage."\n", 0);
		}
		
		$sql = "INSERT INTO `sensordatevalues` (`pid`, `did`, `date`, `prior_value`)"
			." VALUES ($pid, 1, '".$dt_lrr->format('YmdHis')."', $prior_value";
		try {
			$wdb->exec($sql);
		} catch (PDOException $e) {
			updatelog("Insert last rain reset:".$e->getMessage()."\n", 0);
		}
	}
	$sdp['prev_week_rain'] = $sdp['cur_rain_reset'] - prev_days_rain(7);
	updatelog("prev_week_rain = ".$sdp['prev_week_rain']."\n", 2);
	$sdp['prev_mo_rain'] = $sdp['cur_rain_reset'] - prev_days_rain(30);
	updatelog("prev_mo_rain = ".$sdp['prev_mo_rain']."\n", 2);
}
function prev_days_rain($days) {
	global $wdb;
	// Get previous week's rain total
	$sql = "SELECT P.id, SV.value"
		." FROM packets P JOIN sensorvalues SV ON P.id = SV.id"
		." WHERE P.timestamp < DATE_SUB(NOW(), INTERVAL $days DAY)"
		." AND SV.sid = 9"
		." ORDER by P.id desc LIMIT 1";
	try {
		$pds = $wdb->query($sql);
		$pds->bindColumn(1, $prior_pid);
		$pds->bindColumn(2, $prior_value);
		$pds->fetch(PDO::FETCH_BOUND);
		$pds->closeCursor();
	} catch (PDOException $e) {
		updatelog("Failed to retrieve prior week's rain total: ".$e->getMessage()."\n", 0);
	}
	// Get rain totals from intervening resets, if any
	$sql = "SELECT SUM(`prior_value`) as inter_rain"
		." FROM sensordatevalues WHERE `pid` > $prior_pid"
		." AND did = 1";
	try {
		$pds = $wdb->query($sql);
		$pds->bindColumn(1, $inter_rain);
		$pds->fetch(PDO::FETCH_BOUND);
		if (!isset($inter_rain)) $inter_rain = 0;
	} catch (PDOException $e) {
		updatelog("Failed to retrieve prior rain totals: ".$e->getMessage()."\n", 0);
	}
	return $prior_value - $inter_rain;
}

function updatesdp() { // Update weather database with sensor data
	global $wdb, $sdp, $id1, $id2, $postdata;
	$pid = updatepkt(2); // This leaves the connection open
	if (!isset($pid)) {
		updatelog("Packet id not set!", 2);
		return;
	}

	updatelog("about to do fixuprain...", 2);
	// TODO: fix this, not sure why it dies in fixuprain
	// Seems it had to do with the rights on the views it uses. Sigh.
	// Probably need to change the .sql to use SQL SECURITY INVOKER.
	// Althought also seems to die at some point after last rain reset from db:... msg.
	//fixuprain($pid);

	$sql = 'INSERT INTO `sensorvalues` (id, sid, value)'
		.' VALUES (?, ?, ?)';
	updatelog("sql = $sql", 2);
	// Trigger will update the records table
	try {
		$pds = $wdb->prepare($sql);
		$pds->bindParam(1, $pid, PDO::PARAM_INT);
		$pds->bindParam(2, $sid, PDO::PARAM_INT);
		$pds->bindParam(3, $value);
		
		// Execute with:
		foreach ( [
			[ 2, $sdp['cur_in_temp']],
			[ 3, $sdp['cur_in_hum']],
			[ 4, $sdp['cur_out_temp']],
			[ 5, $sdp['cur_out_hum']],
			[ 6, $sdp['cur_pres_hg']],
			[ 7, hexdec(bin2hex($sdp['wind_dir_last6'])[0]) * 22.5],
			[ 8, $sdp['cur_wind_speed']],
			[ 9, $sdp['cur_rain_reset']],
			[ 10, $sdp['cur_rain_hour']],
			[ 11, $sdp['cur_rain_day']],
			[ 12, $sdp['prev_week_rain']],
			[ 13, $sdp['prev_mo_rain']],
			[ 14, $sdp['cur_wind_chill']],
			[ 15, $sdp['max_gust_cycle']],
			[ 16, $sdp['dewpoint']],
			] as list($sid, $value)) {
			updatelog("doing insert for pid $pid, $sid = $value...\n",2);
			$pds->execute();
			if ($pds->errorCode() > 0) {
				updatelog("Insert pid $pid sid $sid value $value\n", 0);
				updatelog(print_r($pds->errorInfo(), true)."\n", 0);
			}
		}
	} catch (PDOException $e) {
		updatelog("Insert sensorvalue: ".$e->getMessage(), 0);
	}
	$pds->closeCursor();
	
	$wdb = null;
}
function updatehis() { // Update weather database with history data
	global $wdb, $postdata, $OF, $stationid;
	if (!isset($wdb)) if (!wdb()) return;
	$pid = updatepkt(8);
	if (!isset($pid)) return;
	
	$spid = $pid; // Save the packetid
	updatelog(" Header: ".bin2hex(substr($postdata, 0, 8))."\n", 2);
	for ($i = 8; $i < strlen($postdata) - 4; $i+=18) {
		$histdata = bin2hex(substr($postdata, $i, 18));
		$timestamp = substr($histdata, 26, 10);
		// Check to see if we've seen this one before
		$sql = "SELECT * FROM packets WHERE packettype = 9 and timestamp = '$timestamp'";
		$pds2 = $wdb->query($sql);
		if ($pds2->fetch()) { 
			$pds2->closeCursor();
			updatelog("Skipping ".$timestamp."\n", 2);
			continue; 
		}
		$sql = 'INSERT INTO `packets` (`stationid`, `timestamp`, `packettype`)'
			." VALUES($stationid, '$timestamp', 9)";
		try {
			$pds3 = $wdb->query($sql);
			$spid = $wdb->lastInsertId();
		} catch (PDOException $e) {
			updatelog("Insert packet: ".$e->getMessage(), 0);
			continue;
		}
		$sql = 'INSERT INTO `sensorvalues` (id, sid, value)'
			.' VALUES (?, ?, ?)';
		try {
			$pds3 = $wdb->prepare($sql);
			$pds3->bindParam(1, $spid, PDO::PARAM_INT);
			$pds3->bindParam(2, $sid, PDO::PARAM_INT);
			$pds3->bindParam(3, $value);
			// Execute with:
			foreach ( [
				[ 17, intval(substr($histdata, 0, 3))/100], // Current rain hour (mm)
				[ 7, hexdec($histdata[4]) * 22.5], // Wind direction (degrees)
				[ 15, hexdec(substr($histdata, 5, 3))/100], // Wind gust speed (kph)
				[ 8, hexdec(substr($histdata, 8, 3))/100], // Wind speed (kph)
				[ 5, intval(substr($histdata, 11, 2))], // Outside RH (%)
				[ 3, intval(substr($histdata, 13, 2))], // Inside RH (%)
				//[ 6, intval(substr($histdata, 15, 5))], // Current pressure (mbar) I use inHg
				[ 4, C2F(intval(substr($histdata, 20, 3))/10 - 40)], // Outside temp
				[ 2, C2F(intval(substr($histdata, 23, 3))/10 - 40)],
				] as list($sid, $value)) {
				$pds3->execute();
			}
		} catch (PDOException $e) {
			updatelog("Insert sensorvalue: ".$e->getMessage(), 0);
		}
		updatelog("Added ".$histdata."\n", 2);
	}
	updatelog("Trailer: ".bin2hex(substr($postdata, strlen($postdata) - 4, 4))."\n", 2);
	if ($spid == $pid) { // We didn't add any new subpackets, drop the packet
		// The packetdump packet is deleted by the foreign key constraint
		$sql = "DELETE FROM packets WHERE id = $pid";
		try {
			$rows = $wdb->exec($sql);
		} catch (PDOException $e) {
			$sql.="\nFAILED ".$e->getMessage();
		}
		updatelog($sql." $rows rows deleted\n", 2);
	}
}
function set_last_hist_addr($last_hist_addr) { // Last memory location of history record
	global $wdb, $stationid;
	if (!isset($wdb)) if (!wdb()) return;
	try {
		$wdb->exec("UPDATE `stations` SET last_hist_addr = '$last_hist_addr'
		WHERE id = $stationid");
	} catch (PDOException $e) {
		updatelog("Failed to update last history address: ".$e->getMessage()."\n",0);
	}

}
function updatewug() { // Update weatherunderground.com
	global $sdp, $station;
	if (is_null($station['wug_id'])) return;
	
	$url = "http://weatherstation.wunderground.com";
	$url.= "/weatherstation/updateweatherstation.php?";
	$url.="ID=".$station['wug_id'];
	$url.="&PASSWORD=".$station['wug_sec'];
	$url.="&action=updateraw";
	$url.="&softwaretype=phpKennKong";
	// timestamp is local, WUG wants UTC
	$timestamp = $sdp['timestamp']->setTimeZone(new DateTimeZone('UTC'));
	$url.="&dateutc=".rawurlencode($timestamp->format("Y-m-d H:i:s"));
	$url.="&winddir=".hexdec($sdp['wind_dir_last6'][0]) * 22.5;
	$url.="&windspeedmph=".round($sdp['cur_wind_speed']/1.619, 1);
	$url.="&windgustmph=".round($sdp['max_gust_cycle']/1.619, 1);
	$url.="&tempf=".$sdp['cur_out_temp'];
	$url.="&humidity=".$sdp['cur_out_hum'];
	$url.="&dewptf=".$sdp['dewpoint'];
	$url.="&baromin=".$sdp['cur_pres_hg'];
	$url.="&rainin=".round($sdp['cur_rain_hour']/25.6, 2);
	$url.="&dailyrainin=".round($sdp['cur_rain_day']/25.6, 2);
	
	updatelog("WUG url: ".$url."\n", 2);
	if ($handle = fopen("$url", "r")) {
		$response = fread($handle, 1024);
		fclose($handle);
	} else {
		$response = "URL open failed\n";
	}
	updatelog("WUG response: ".$response, 1);
}
function updatelac() { // Relay data to Lacrosse server
	global $postdata, $http_id;
	$opts = array('http' =>
		array(
			'method'  => 'PUT',
			'protocol_version' => 1.1,
			'header'  => array(
				"Host: box.weatherdirect.com",
				"Connection: close",
				"HTTP_IDENTIFY: $http_id",
				"Content-Type: application/octet-stream",
				"Content-Length: ".strlen($postdata)
				),
			'content' => $postdata
		)
	);

	$context = stream_context_create($opts);

	$result = file_get_contents('http://box.weatherdirect.com/request.breq', false, $context);
	if ($result === FALSE) return array('NO FLAGS', '');
	foreach ($http_response_header as $i => $header) {
		if (strpos($header, 'HTTP_FLAGS:') !== false) break;
	}
	return array( isset($i) ? $http_response_header[$i] : 'NO FLAGS', $result);
}
if( !function_exists('apache_request_headers') ) {
	function apache_request_headers() {
	  $arh = array();
	  $rx_http = '/\AHTTP_/';
	  foreach($_SERVER as $key => $val) {
	    if( preg_match($rx_http, $key) ) {
	      $arh_key = preg_replace($rx_http, '', $key);
	      $rx_matches = array();
	      // do some nasty string manipulations to restore the original letter case
	      // this should work in most cases
	      $rx_matches = explode('_', $arh_key);
	      if( count($rx_matches) > 0 and strlen($arh_key) > 2 ) {
	        foreach($rx_matches as $ak_key => $ak_val) $rx_matches[$ak_key] = ucfirst($ak_val);
	        $arh_key = implode('-', $rx_matches);
	      }
	      $arh[$arh_key] = $val;
	    }
	  }
	  return( $arh );
	}
}

?>
