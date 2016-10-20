#!/bin/bash
. /etc/default/nwws2
if [ "$1" = "" ]; then
	echo "Usage: $0 <days>"
	exit 1
fi
ARCHIVE_FILE=$(date +"%Y%m%d.tar.gz")
# Create tar.gz backup of products
/usr/bin/find $BASEDIR/archive/ -type f +mtime $1 -print0 | tar -czvf $BASEDIR/$ARCHIVE_FILE --null -T -
# Delete products
/usr/bin/find $BASEDIR/archive/ -type f +mtime $1 -delete
# Done
exit 0
