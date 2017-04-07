<?php

require 'vendor/autoload.php';

if ($argc < 2) {
	echo "Usage: $argv[0] /path/to/config/file".PHP_EOL;
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
	'resource'       => $CONF['resource'],
	'log_level'      => JAXLLogger::DEBUG,
	'priv_dir'       => '.jaxl',
	'force_tls'      => true,
	'stream_context' => stream_context_create(
		array(
			'ssl' => array(
				'verify_peer' => false,         // This is required to connect to NWWS-2
				'cafile' => '/etc/ssl/certs/cacert.pem',
			)
		)
	)
));

error_reporting(E_ALL);

$client->require_xep(array(
	'0045',     // MUC
	'0203',     // Delayed Delivery
	'0199'      // XMPP Ping
));

//
// add necessary event callbacks here
//

$_room_full_jid = "nwws@conference." . $CONF['server'] . "/" . $CONF['resource'];
$room_full_jid = new XMPPJid($_room_full_jid);

function on_auth_success_callback()
{
    global $client, $room_full_jid;
    JAXLLogger::info("got on_auth_success cb, jid ".$client->full_jid->to_string());

    // join muc room
    $client->xeps['0045']->join_room($room_full_jid);
}
$client->add_cb('on_auth_success', 'on_auth_success_callback');

function on_auth_failure_callback($reason)
{
    global $client;
    $client->send_end_stream();
    JAXLLogger::info("got on_auth_failure cb with reason $reason");
}
$client->add_cb('on_auth_failure', 'on_auth_failure_callback');

$client->add_cb('on_groupchat_message', function($stanza) use ($client) {
	echo "Groupchat event received.\n";
});

/*
function on_presence_stanza_callback($stanza)
{
    global $client, $room_full_jid;

    $from = new XMPPJid($stanza->from);

    // self-stanza received, we now have complete room roster
    if (strtolower($from->to_string()) == strtolower($room_full_jid->to_string())) {
        if (($x = $stanza->exists('x', XEP0045::NS_MUC.'#user')) !== false) {
            if (($status = $x->exists('status', null, array('code' => '110'))) !== false) {
                $item = $x->exists('item');
                JAXLLogger::info("xmlns #user exists with x ".$x->ns." status ".$status->attrs['code'].
                    ", affiliation:".$item->attrs['affiliation'].", role:".$item->attrs['role']);
            } else {
                JAXLLogger::info("xmlns #user have no x child element");
            }
        } else {
            JAXLLogger::warning("=======> odd case 1");
        }
    } elseif (strtolower($from->bare) == strtolower($room_full_jid->bare)) {
        // stanza from other users received

        if (($x = $stanza->exists('x', XEP0045::NS_MUC.'#user')) !== false) {
            $item = $x->exists('item');
            echo "presence stanza of type ".($stanza->type ? $stanza->type : "available")." received from ".
                $from->resource.", affiliation:".$item->attrs['affiliation'].", role:".$item->attrs['role'].PHP_EOL;
        } else {
            JAXLLogger::warning("=======> odd case 2");
        }
    } else {
        JAXLLogger::warning("=======> odd case 3");
    }
}
$client->add_cb('on_presence_stanza', 'on_presence_stanza_callback');
*/

function on_disconnect_callback()
{
    JAXLLogger::info("got on_disconnect cb");
}
$client->add_cb('on_disconnect', 'on_disconnect_callback');

//
// finally start configured xmpp stream
//
//$client->start(array('--with-unix-sock' => TRUE, '--with-debug-shell' => TRUE));
$client->start();
echo "done".PHP_EOL;

