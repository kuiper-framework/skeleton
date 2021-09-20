#!/bin/bash

dir=$(dirname $0)/..
php=php
pid_file="$dir/logs/master.pid"
conf_file="$dir/config.conf"
if [ -f $conf_file ]; then
  start_options=(--config $conf_file)
else
  start_options=()
fi

function restart() {
    if [ -f $pid_file ]; then
        pid=`cat $pid_file`

        if kill -0 $pid 2>/dev/null; then
            kill -TERM $pid
            sleep 1
            while kill -0 $pid 2>/dev/null; do
                echo "wait previous server exit"
                sleep 1
            done
        fi
    fi

    echo "restart server"
    php $dir/src/index.php ${start_options[@]} &
}

if type fswatch > /dev/null; then
    restart
    
    fswatch --event Removed --event Renamed --event Updated --event Created -or -l 3 -0 $dir/src/ | while read -d "" event
    do
        restart
    done
else
    php $dir/src/index.php ${start_options[@]}
fi
