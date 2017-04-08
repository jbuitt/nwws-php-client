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

$CONF = json_decode(file_get_contents($argv[1]), TRUE);

// connect to NWWS-OI server
$options = new Options('tcp://' . $CONF['server'] . ':5222');
$options->setUsername($CONF['username'])->setPassword($CONF['password']);
$client = new Client($options);
$client->connect();

// fetch roster list; users and their groups
$client->send(new Roster);
// set status to online
$client->send(new Presence);

// join nwws channel
$channel = new Presence;
$channel->setTo('nwws@conference.' . $CONF['server'] . '/' . $CONF['resource'])->setNickName($CONF['username']);
$client->send($channel);

// start receiving products
$xmlData = '';
$scanFlag = 0;
while(TRUE) {
	$input = $client->getConnection()->receive();
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


function writeProduct($xmlData)
{
        global $CONF;
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

