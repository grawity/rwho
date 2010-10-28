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
		if [[ -f $PIDFILE ]] && pid=$(< "$PIDFILE"); then
			if kill -0 $pid; then
				echo "running: pid $pid"
			else
				echo "unsure: pid $pid but can't signal"
			fi
		else
			echo "not running or no pidfile"
		fi
	esac
}

ctl "$@"
