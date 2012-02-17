#!/bin/sh
exec "$(dirname "$0")/agent-linux/rwho-agent.initd" "$@"
