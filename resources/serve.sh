#!/bin/bash

dir=$(dirname $0)/..
php=php

function restart() {
    if [ -f "$dir/runtime/master.pid" ]; then
        pid=`cat $dir/runtime/master.pid`

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
    php $dir/src/index.php --config $dir/config.conf &
}

if type fswatch > /dev/null; then
    restart
    
    fswatch --event Removed --event Renamed --event Updated --event Created -or -l 3 -0 $dir/src/ | while read -d "" event
    do
        restart
    done
else
    php $dir/src/index.php --config $dir/config.conf
fi
