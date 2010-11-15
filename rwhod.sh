#!/usr/bin/env bash
if (( $(id -u) > 0 )); then
	[[ $PIDFILE ]] ||
		export PIDFILE="$HOME/tmp/rwhod-$(hostname).pid"
	[[ $PERL5LIB ]] ||
		export PERL5LIB="$HOME/lib/perl5:$HOME/usr/lib/perl5"
else
	[[ $PIDFILE ]] ||
		export PIDFILE="/var/run/rwhod.pid"
fi

[[ $RWHOD_DIR ]] ||
	RWHOD_DIR="$HOME/code/useless/rwho"
[[ $RWHOD_OPTIONS ]] ||
	RWHOD_OPTIONS=()

ctl() {
	case $1 in
	start)
		"$RWHOD_DIR/rwhod.pl" "${RWHOD_OPTIONS[@]}" & pid=$!
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
			if kill -0 $pid 2>/dev/null; then
				echo "running: pid $pid"
				return 0
			else
				echo "unsure: pid $pid but can't signal"
				return 2
			fi
		else
			echo "not running or no pidfile at '$PIDFILE'"
			return 1
		fi
	esac
}

ctl "$@"
