<?php

include 'vendor/autoload.php';

if($argc < 2) {
        echo "Usage: $argv[0] /path/to/config/file\n";
        exit;
}

//
// Parse config file
//
$CONF = json_decode(file_get_contents($argv[1]), TRUE);

//
// initialize JAXL object with initial config
//
$client = new JAXL(array(
	'jid'            => $CONF['username'] . '@' . $CONF['server'],
	'pass'           => $CONF['password'],
	'host'           => $CONF['server'],
	'port'           => $CONF['port'],
	'force_tls'      => TRUE,
	'resource'       => $CONF['resource'],
	'log_level'      => JAXL_INFO,
	'priv_dir'       => '.jaxl',
	'stream_context' => stream_context_create(array(
		'ssl' => array(
			'verify_peer' => false,		// This is required to connect to NWWS-2
			'cafile' => '/etc/ssl/certs/cacert.pem',
		)
	)),
));

$client->require_xep(array(
	'0045',	// MUC
	'0203',	// Delayed Delivery
	'0199'  // XMPP Ping
));

//
// add necessary event callbacks here
//

$_room_full_jid = "nwws@conference." . $CONF['server'] . "/" . $CONF['resource'];
$room_full_jid = new XMPPJid($_room_full_jid);

$client->add_cb('on_auth_success', function() {
	global $client, $room_full_jid;
	//_info("got on_auth_success cb, jid ".$client->full_jid->to_string());
	printToLog("got on_auth_success cb, jid ".$client->full_jid->to_string());

	// join muc room
	$client->xeps['0045']->join_room($room_full_jid);
});

$client->add_cb('on_auth_failure', function($reason) {
	global $client;
	$client->send_end_stream();
	//_info("got on_auth_failure cb with reason $reason");
	printToLog("got on_auth_failure cb with reason $reason");
});

$client->add_cb('on_groupchat_message', function($stanza) {
	global $client;
	global $CONF;
	
	if (preg_match('/^\*\*WARNING\*\*/', $stanza->body)) {
		return;
	}
	if (preg_match('/issues  valid/', $stanza->body)) {
		return;
	}
	if (preg_match('/issues TST valid/', $stanza->body)) {
		return;
	}

	$from = new XMPPJid($stanza->from);
	$delay = $stanza->exists('delay', NS_DELAYED_DELIVERY);
	
	if($from->resource) {
		printToLog("message stanza rcvd from ".$from->resource." saying... ".$stanza->body.($delay ? ", delay timestamp ".$delay->attrs['stamp'] : ", timestamp ".gmdate("Y-m-dTH:i:sZ")));
	}
	else {
		$subject = $stanza->exists('subject');
		if($subject) {
			printToLog("room subject: ".$subject->text.($delay ? ", delay timestamp ".$delay->attrs['stamp'] : ", timestamp ".gmdate("Y-m-dTH:i:sZ")));
		}
	}

	for($i=0; $i<count($stanza->childrens); $i++) {
		$child = new JAXLXml($stanza->childrens[$i]);
		if ($child->name->name === 'x') {
			//var_dump($child->name);
			$awipsid  = '';
			$wfo      = '';
			$wmoCode  = '';
			$prodDate = '';
			$id       = '';
			if (isset($child->name->attrs['awipsid'])) {
				$awipsid = strtolower($child->name->attrs['awipsid']);
				//echo "**DEBUG** \$awipsid = $awipsid\n";
			}
			if (isset($child->name->attrs['issue'])) {
				$wfo = strtolower($child->name->attrs['cccc']);
				//echo "**DEBUG** \$wfo = $wfo\n";
			}
			if (isset($child->name->attrs['ttaaii'])) {
				$wmoCode = strtolower($child->name->attrs['ttaaii']);
				//echo "**DEBUG** \$wmoCode = $wmoCode\n";
			}
			if (isset($child->name->attrs['issue'])) {
				$prodDate = preg_replace('/[\-T\:]/', '', $child->name->attrs['issue']);
				$prodDate = preg_replace('/Z$/', '', $prodDate);
				//echo "**DEBUG** \$prodDate = $prodDate\n";
			}
			if (isset($child->name->attrs['id'])) {
				$id = $child->name->attrs['id'];
				//echo "**DEBUG** \$id = $id\n";
			}
			// Write out file to archive directory
			if ($awipsid !== '' && $wfo !== '' && $prodDate !== '' && $id !== '') {
				if (!file_exists($CONF['archivedir'])) {
					mkdir($CONF['archivedir']);
				}
				if (!file_exists($CONF['archivedir'] . '/' . $wfo)) {
					mkdir($CONF['archivedir'] . '/' . $wfo);
				}
				$tmp_array = explode('.', $id);
				$new_id = gmdate('yHi') . '_' . substr(time(), 0, 3) . $tmp_array[1];
				if (strlen($new_id) === 7) {
					$new_id = '0' . $new_id;
				}
				$file = $wfo . '_' . $wmoCode . '-' . $awipsid . '.' . $new_id . '.txt';
				$outfile = fopen($CONF['archivedir'] . '/' . $wfo . '/' . $file, "w");
				$prod_contents = preg_split("/\n\n/", $child->name->text);
				for($j=0; $j<count($prod_contents); $j++) {
					fwrite($outfile, $prod_contents[$j] . "\n");
				}
				fclose($outfile);
				// Perform Product Arrival Notification (PAN) action, if it exists and is executable
				if (isset($CONF['pan_run'])) {
					if (is_executable($CONF['pan_run'])) {
						exec($CONF['pan_run'] . ' ' . getcwd() . '/' . $CONF['archivedir'] . '/' . $wfo . '/' . $file . ' 2>&1 &', $output, $retval);
						if ($retval !== 0) {
							printToLog("Error running PAN executable: " . implode(" ", $output));
						}
					}
				}
			}
		}
	}

});

$client->add_cb('on_presence_stanza', function($stanza) {
	/*
	global $client, $room_full_jid;
	
	$from = new XMPPJid($stanza->from);
	
	// self-stanza received, we now have complete room roster
	if(strtolower($from->to_string()) == strtolower($room_full_jid->to_string())) {
		if(($x = $stanza->exists('x', NS_MUC.'#user')) !== false) {
			if(($status = $x->exists('status', null, array('code'=>'110'))) !== false) {
				$item = $x->exists('item');
				_info("xmlns #user exists with x ".$x->ns." status ".$status->attrs['code'].", affiliation:".$item->attrs['affiliation'].", role:".$item->attrs['role']);
			}
			else {
				_info("xmlns #user have no x child element");
			}
		}
		else {
			_warning("=======> odd case 1");
		}
	}
	// stanza from other users received
	else if(strtolower($from->bare) == strtolower($room_full_jid->bare)) {
		if(($x = $stanza->exists('x', NS_MUC.'#user')) !== false) {
			$item = $x->exists('item');
			echo "presence stanza of type ".($stanza->type ? $stanza->type : "available")." received from ".$from->resource.", affiliation:".$item->attrs['affiliation'].", role:".$item->attrs['role'].PHP_EOL;
		}
		else {
			_warning("=======> odd case 2");
		}
	}
	else {
		_warning("=======> odd case 3");
	}
	*/
});

$client->add_cb('on_disconnect', function() {
	printToLog("got on_disconnect cb");
	//_info("got on_disconnect cb");
});

//
// finally start configured xmpp stream
//
while(1) {
	$client->start();
	sleep(1);
}


function printToLog($logMsg)
{
	global $CONF;
	$logfile = fopen($CONF['logpath'] . '/' . $CONF['logprefix'] . '_' . date('Y-m-d') . '.log', 'a');
	fwrite($logfile, $logMsg . "\n");
	fclose($logfile);
}

?>
