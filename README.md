# nwws-php-client

A simple client for the NWWS-2 OI ([NOAA Weather Wire Service](http://www.nws.noaa.gov/nwws/) version 2 Open Interface) written in PHP. The NOAA Weather Wire Service is a satellite data collection and dissemination system operated by the [National Weather Service](http://weather.gov), which was established in October 2000. Its purpose is to provide state and federal government, commercial users, media and private citizens with timely delivery of meteorological, hydrological, climatological and geophysical information. 

This client was largely based on the example muc_log_bot.php script found in the [JAXL XMPP PHP Library](https://github.com/jaxl/JAXL).

####How do I run it?
This script was developed and tested on [Ubuntu 14.04](http://ubuntu.com). After downloading the latest [release](https://github.com/jbuitt/nwws-perl-client), run the following command to install the php dependencies. You need at least PHP **5.6+** and a recent version of **Composer**

```
    $ composer install
```

Now create a config file (e.g. config.json) with the following format:

```
{
  "server": "nwws-oi.weather.gov",
  "port": 5223,
  "username": "[username]",
  "password": "[paswword]",
  "resource": "[resource]",
  "logfile": "/path/to/log/file",
  "archivedir": "/path/to/archive/dir"
}
```

Where [username] and [password] are your NWWS-2 credentials obtained by signing up [on the NOAA Weather Wire Service website](http://www.nws.noaa.gov/nwws/#NWWS_OI_Request). You may use whatever you would like for [resource]. 

Now run the script:

```
$ php ./nwws2.php /path/to/config.json
```

Provided that you're able to connect to the NWWS and your credentials are accepted, you will start to see products appear in the supplied archive directory in the following format:

```
[archive_dir]/
   [wfo]/
      [awips_id]-[product_id]-[product_datetime].txt
```

You can then type `Ctrl+Z` and then `bg` to send it to the background to continue downloading products. The script will automatically reconnect to NWWS if the connection is dropped.

####Author

+	[jim.buitt at gmail.com](mailto:jim.buitt@gmail.com)

## License

See [LICENSE](https://github.com/jbuitt/nwws-perl-client/blob/master/LICENSE) file.

