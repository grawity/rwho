#!/usr/bin/env bash

if (( $UID > 0 )); then
	PIDFILE=${PIDFILE:-$HOME/tmp/rwhod-$HOSTNAME.pid}
	PERL5LIB=$HOME/.local/lib/perl5
	export PERL5LIB
else
	PIDFILE=${PIDFILE:-/run/rwhod.pid}
fi

RWHOD_DIR=$(dirname "$0")

ctl() {
	case $1 in
	start)
		"$RWHOD_DIR/rwhod.pl" --daemon --pidfile="$PIDFILE" &
		;;
	stop)
		pid=$(< "$PIDFILE") && kill $pid && rm "$PIDFILE"
		;;
	restart)
		ctl stop
		ctl start
		;;
	reload)
		ctl restart
		;;
	force-reload)
		ctl restart
		;;
	status)
		if [[ ! -f $PIDFILE ]]; then
			echo "not running or no pidfile at '$PIDFILE'"
			return 3
		fi

		if ! pid=$(< "$PIDFILE"); then
			echo "cannot read pidfile"
			return 1
		fi

		if kill -0 $pid 2>/dev/null; then
			echo "running (pid $pid)"
			return 0
		else
			echo "unsure (pid $pid does not respond to signals)"
			return 1
		fi
		;;
	build-dep)
		perldeps=(
			JSON
			LWP::UserAgent
			Linux::Inotify2
			Socket::GetAddrInfo
			Sys::Utmp
		)
		${CPAN:-cpanm} "${perldeps[@]}"
		;;
	foreground)
		exec "$RWHOD_DIR/rwhod.pl" --pidfile="$PIDFILE"
		;;
	update)
		if [[ $RWHOD_DIR/rwhod.pl -nt $PIDFILE ]]; then
			ctl restart
		fi
		;;
	*)
		echo "usage: $0 <start|stop|restart|foreground|update>"
		;;
	esac
}

ctl "$@"
