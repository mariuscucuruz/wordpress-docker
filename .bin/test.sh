#!/bin/bash

if docker inspect frontend | grep -q 'IPAddress'; then
  echo "Tests passed!"

  echo "WP Container IPs:"
  docker inspect frontend | grep "IPAddress"

  echo "Proxy Container IP:"
  docker inspect proxy | grep "IPAddress"

  exit 0

else
  echo "Tests failed!"
  exit 1
fi
