#!/usr/bin/env bash
RWHOD=(~/code/useless/rwho/rwhod.pl)
PIDFILE=~/tmp/rwhod-$(hostname).pid

ctl() {
	case $1 in
	start)
		"${RWHOD[@]}" & pid=$!
		echo $pid > "$PIDFILE"
		kill -0 $pid
		;;
	stop)
		pid=$(< "$PIDFILE") && kill $pid && rm "$PIDFILE"
		;;
	restart)
		ctl stop
		ctl start
		;;
	status)
		[[ -f $PIDFILE ]] && pid=$(< "$PIDFILE") && kill -0 $pid
	esac
}

ctl "$@"
