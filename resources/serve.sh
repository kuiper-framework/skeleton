#!/bin/bash

dir=$(dirname $0)/..
php=php
conf_file="$dir/config.conf"
if [ -f $conf_file ]; then
  start_options=(--config $conf_file)
else
  start_options=()
fi

function restart() {
  echo "restart server"
  php $dir/src/index.php ${start_options[@]} reload
}

if type fswatch > /dev/null; then
  php $dir/src/index.php ${start_options[@]} start &

  fswatch --event Removed --event Renamed --event Updated --event Created -or -l 3 -0 $dir/src/ | while read -d "" event
  do
      restart
  done
else
  php $dir/src/index.php ${start_options[@]} start
fi