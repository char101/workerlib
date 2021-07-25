#!/usr/bin/env bash

APP=main.php
NAME=pkkmb
PHP=/opt/php-cli/8.0/bin/php

start() {
  for dir in log /tmp/$NAME; do
    if [ ! -d $dir ]; then
      mkdir -p $dir
    fi
    sudo chown -R $WWW:$WWW $dir
  done

  sudo -Eu $WWW -- $PHP $APP start $*
}

stop() {
  sudo -Eu $WWW -- $PHP $APP stop $*
}

status() {
  sudo -Eu $WWW -- $PHP $APP status $*
}

reload() {
  sudo -Eu $WWW -- $PHP $APP reload $*
}

restart() {
  sudo -Eu $WWW -- $PHP $APP restart
}

if [ ! -z "$1" ]; then
  case $1 in
    d|development) ENV=development ;;
    p|production) ENV=production ;;
    t|testing) ENV=testing ;;
    u|upstrea) ENV=upstream ;;
    *) echo "Unknown environment: $1"; exit 1 ;;
  esac
  shift
  export APP_ENV=$ENV
  if [ "$ENV" = "development" ]; then
    WWW=char
  else
    WWW=www-data
  fi
else
  echo "Missing environment"
  exit 1
fi

CMD=start
if [ ! -z "$1" ]; then
  case $1 in
    start) CMD=start ;;
    stop) CMD=stop ;;
    reload) CMD=reload ;;
    restart) CMD=restart ;;
    status) CMD=status ;;
    *) echo "Unknown command: $1"; exit 1 ;;
  esac
  shift
fi

echo "ENV=$ENV WWW=$WWW CMD=$CMD \$*=$*"
case "$CMD" in
  start)
    start $*
    ;;
  stop)
    stop $*
    ;;
  status)
    status $*
    ;;
  reload)
    reload $*
    ;;
  restart)
    restart $*
    ;;
esac
