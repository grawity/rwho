#!/usr/bin/env bash
RWHOD=(~/code/useless/rwho/rwhod.pl)
PIDFILE=~/tmp/rwhod-$(hostname).pid

if [[ -z $PERL5LIB ]]; then
	PERL5LIB=~/lib/perl5:~/usr/lib/perl5
fi
export PERL5LIB

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
				return 2
			fi
		else
			echo "not running or no pidfile"
			return 1
		fi
	esac
}

ctl "$@"
