# nwws-php-client

A simple client for the NWWS-2 OI ([NOAA Weather Wire Service](http://www.nws.noaa.gov/nwws/) version 2 Open Interface) written in PHP. The NOAA Weather Wire Service is a satellite data collection and dissemination system operated by the [National Weather Service](http://weather.gov), which was established in October 2000. Its purpose is to provide state and federal government, commercial users, media and private citizens with timely delivery of meteorological, hydrological, climatological and geophysical information. 

This client uses an updated fork by zorn-v of Fabian Grutschus' popular [XMPP PHP library](https://github.com/zorn-v/xmpp).

## How do I run it?

This script was developed and tested on [Ubuntu 16.04](http://ubuntu.com). After downloading the latest [release](https://github.com/jbuitt/nwws-php-client), run the following command to install the php dependencies. You need at least PHP **7.0+** with **pcntl** support and a recent version of **[Composer](http://getcomposer.org)**

```
    $ composer install
```

Now create a JSON config file with the following format:

```
{
  "server": "nwws-oi.weather.gov",
  "port": 5222,
  "username": "[username]",
  "password": "[paswword]",
  "resource": "[resource]",
  "logpath": "/path/to/log/dir",
  "logprefix": "logfile",
  "archivedir": "/path/to/archive/dir",
  "pan_run": "/path/to/executable_or_script"
}
```

Where [username] and [password] are your NWWS-2 credentials obtained by signing up [on the NOAA Weather Wire Service website](http://www.nws.noaa.gov/nwws/#NWWS_OI_Request). You may use whatever you would like for [resource]. The "pan_run" variable is an optional Product Arrival Notification (PAN) script that you'd like to run on product arrival.

Now run the script:

```
$ php ./nwws2.php /path/to/config/file
```

Provided that you're able to connect to the NWWS and your credentials are accepted, you will start to see products appear in the supplied archive directory in the following format:

```
[archive_dir]/
   [wfo]/
      [wfo]_[wmo_TTAAii]-[awips_id].[product_yearhour]_[product_id].txt
```

You can then type `Ctrl+Z` and then `bg` to send it to the background to continue downloading products. The client will automatically reconnect to NWWS if the connection is dropped. I have also provided a 'nwws2' bash script to take care of running the client using [nohup](https://en.wikipedia.org/wiki/Nohup). You'll need to copy the file 'contrib/etc/default/nwws2' to '/etc/default' before running the 'nwws2' script.

## Author

+	[jim.buitt at gmail.com](mailto:jim.buitt@gmail.com)

## License

See [LICENSE](https://github.com/jbuitt/nwws-php-client/blob/master/LICENSE) file.

