<?php

include 'vendor/autoload.php';

use Fabiang\Xmpp\Options;
use Fabiang\Xmpp\Client;
use Fabiang\Xmpp\Protocol\Roster;
use Fabiang\Xmpp\Protocol\Presence;
use Fabiang\Xmpp\Protocol\Message;

if($argc < 2) {
        echo "Usage: $argv[0] /path/to/config/file\n";
        exit;
}

// setup signal handlers
declare(ticks = 1);
pcntl_signal(SIGINT, "signalHandler");
pcntl_signal(SIGTERM, "signalHandler");

// write out PID
if (getenv('PIDFILE')) {
	if (is_writable(dirname(getenv('PIDFILE')))) {
		exec("echo " . getmypid() . " >" . getenv('PIDFILE'));
	} else {
		printToLog("Warning: PID file is not writable, defaulting to ./nwws2.pid");
		exec("echo " . getmypid() . " >./nwws2.pid");
	}
} else {
	exec("echo " . getmypid() . " >./nwws2.pid");
}

// parse config
$CONF = json_decode(file_get_contents($argv[1]), TRUE);

// Create a default product filter set if the configuration doesn't exist in the config file
if (!is_array($CONF['wmofilter'])) {
	printToLog("Applying default product filter");
	$wmoFilter = array (
		"/.{4,6}/" // allow all products
	);
} else {
	printToLog("Using product filter from configuration file");
	$wmoFilter = $CONF['wmofilter'];
}

// start connect loop
while(TRUE) {

	printToLog("Connecting to " . $CONF['server'] . " port " . $CONF['port']);

	// connect to NWWS-OI server
	$options = new Options('tcp://' . $CONF['server'] . ':' . $CONF['port']);
	$options->setUsername($CONF['username'])->setPassword($CONF['password']);
	$client = new Client($options);
	try {
		$client->connect();
	} catch (Fabiang\Xmpp\Exception $e) {
		continue;
		sleep(3);
	}

	printToLog("Connected.");

	// fetch roster list; users and their groups
	$client->send(new Roster);
	// set status to online
	$client->send(new Presence);

	// join nwws channel
	$channel = new Presence;
	$channel->setTo('nwws@conference.' . $CONF['server'] . '/' . $CONF['resource']);
	$client->send($channel);

	// start receiving products
	$xmlData = '';
	$scanFlag = 0;
	while(TRUE) {
		
		// check to make sure the socket is still open before doing anything else.
		// The NWWS-OI server likes to kill connections from time to time.
		if (!is_resource($client->getConnection()->getSocket()->getResource())) {
			printToLog("Socket is invalid, server probably disconnected us");
			continue 2;
		}
		
		try {
			$input = $client->getConnection()->receive();
		} catch (Fabiang\Xmpp\Exception $e) {
			continue;
		}
		if (preg_match('/^<message to/', $input)) {
			// beginning of message
			$xmlData .= $input;
			if (preg_match('/<\/message>$/', $input)) {
				// full product (not split up)
				writeProduct($xmlData);
				$xmlData = '';
			} else {
				$scanFlag = 1;
			}
		} elseif ($scanFlag && preg_match('/\<\/message\>$/', $input)) {
			// end of previous message
			$xmlData .= $input;
			writeProduct($xmlData);
			$xmlData = '';
			$scanFlag = 0;
		} elseif ($scanFlag) {
			// middle of long message
			$xmlData .= $input;
		}
	}

	printToLog("Disconnected.");
	sleep(3);
}


function writeProduct($xmlData)
{
        global $CONF;
		global $wmoFilter;
	// Prepend <messages> and append </messages> to satisfy XML parser
	$xmlData = '<messages>' . $xmlData . '</messages>';
	$xmlData = str_replace('x xmlns="nwws-oi"', 'x xmlns="http://nwws-oi"', $xmlData);
	try {
		$xmlObj = simplexml_load_string($xmlData, 'SimpleXMLElement', LIBXML_ERR_NONE);
	} catch (Exception $e) {
		return;
	}
	for($i=0; $i<count($xmlObj->message); $i++) {
		// skip NNWS warning message
		if (preg_match('/^\*\*WARNING/', $xmlObj->message[$i]->body)) {
			return;
		}
		// skip products with no type
		if (preg_match('/issues  valid/', $xmlObj->message[$i]->body)) {
			return;
		}
		// skip test messages
		if (preg_match('/issues TST valid/', $xmlObj->message[$i]->body)) {
			return;
		}
		
		// Apply WMO code filter
		$wmoFilterPass = false;
		foreach ($wmoFilter as $wmoMatch) {
			if (preg_match($wmoMatch, strtolower($xmlObj->message[$i]->x->attributes()->ttaaii))) {
				$wmoFilterPass = true;
			}
		}
		
		if (!$wmoFilterPass) {
			printToLog("Skipped WMO code " . strtolower($xmlObj->message[$i]->x->attributes()->ttaaii) . " from " . strtolower($xmlObj->message[$i]->x->attributes()->cccc));
			return;
		}
		
		printToLog("message stanza rcvd from nwws-oi saying... " . $xmlObj->message[$i]->body . ", timestamp ". gmdate("Y-m-dTH:i:sZ"));
		$awipsid  = '';
		$wfo      = '';
		$wmoCode  = '';
		$prodDate = '';
		$id       = '';
		if (isset($xmlObj->message[$i]->x->attributes()->awipsid)) {
			$awipsid = strtolower($xmlObj->message[$i]->x->attributes()->awipsid);
			//echo "**DEBUG** \$awipsid = $awipsid\n";
		}
		if (isset($xmlObj->message[$i]->x->attributes()->issue)) {
			$wfo = strtolower($xmlObj->message[$i]->x->attributes()->cccc);
			//echo "**DEBUG** \$wfo = $wfo\n";
		}
		if (isset($xmlObj->message[$i]->x->attributes()->ttaaii)) {
			$wmoCode = strtolower($xmlObj->message[$i]->x->attributes()->ttaaii);
			//echo "**DEBUG** \$wmoCode = $wmoCode\n";
		}
		if (isset($xmlObj->message[$i]->x->attributes()->issue)) {
			$prodDate = preg_replace('/[\-T\:]/', '', $xmlObj->message[$i]->x->attributes()->issue);
			$prodDate = preg_replace('/Z$/', '', $prodDate);
			//echo "**DEBUG** \$prodDate = $prodDate\n";
		}
		if (isset($xmlObj->message[$i]->x->attributes()->id)) {
			$id = $xmlObj->message[$i]->x->attributes()->id;
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
			$new_id = gmdate('yHi') . '_' . substr(time(), 0, 3) . substr($tmp_array[1], 0, 5);
			$file = $wfo . '_' . $wmoCode . '-' . $awipsid . '.' . $new_id . '.txt';
			$outfile = fopen($CONF['archivedir'] . '/' . $wfo . '/' . $file, "w");
			$prod_contents = preg_split("/\n\n/", $xmlObj->message[$i]->x);
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

function printToLog($logMsg)
{
        global $CONF;
        $logfile = fopen($CONF['logpath'] . '/' . $CONF['logprefix'] . '_' . date('Y-m-d') . '.log', 'a');
        fwrite($logfile, $logMsg . "\n");
        fclose($logfile);
}

function signalHandler($signo)
{
	switch ($signo) {
		case SIGINT:
			fwrite(STDERR, "Caught INT signal. Exiting.\n");
		case SIGTERM:
			// handle shutdown tasks
			if (getenv('PIDFILE')) {
				unlink(getenv('PIDFILE'));
			} else {
				unlink('./nwws2.pid');
			}
			exit;
			break;
		default:
			// handle all other signals
     }
}
