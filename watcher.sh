#!/bin/bash


command -v inotifywait >/dev/null 2>&1 || {
	echo "Please install inotify-tools" >&2;
	exit 1;
}

while true
do
  php src/main.php &
  pid=$!
  echo "server is running, process id is: $pid"
  inotifywait -r -e close_write,moved_to,create src
  rm -rf cache
  kill -9 $pid
  # Oh yeah!
done
