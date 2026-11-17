#!/bin/bash

sleep 5 & echo "Rebuilding in 5 sec..." \
  && echo "Tearing it all down." \
  && docker compose down --remove-orphans \
  && docker compose up -d --force-recreate --remove-orphans --build

# inspect fpm
ps --no-headers -eo rss,comm | grep php | awk '{sum+=$1; count++} END {if (count > 0) print "[CHECK] Average Memory Usage (KB):", sum/count; else print "No PHP processes found."}'

echo "WP Container IPs: " && docker inspect frontend | grep "IPAddress"
echo "Proxy Container IP: " && docker inspect proxy | grep "IPAddress"
