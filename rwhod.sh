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
		"$RWHOD_DIR/rwhod.pl" --daemon --pidfile "$PIDFILE" &
		;;
	foreground)
		exec "$RWHOD_DIR/rwhod.pl" --pidfile "$PIDFILE"
		;;
	stop)
		read -r pid < "$PIDFILE" && kill $pid && rm "$PIDFILE"
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
			echo "not running (no pidfile at '$PIDFILE')"
			return 3
		fi

		if ! read -r pid < "$PIDFILE"; then
			echo "error (cannot read pidfile)"
			return 1
		fi

		if kill -0 $pid 2>/dev/null; then
			echo "running (pid $pid)"
			[[ $RWHOD_DIR/rwhod.pl -nt $PIDFILE ]] &&
				echo "out of date"
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
	update)
		if [[ $RWHOD_DIR/rwhod.pl -nt $PIDFILE ]]; then
			ctl restart
		fi
		;;
	git-update)
		cd "$RWHOD_DIR" &&
		git pull --quiet --ff-only &&
		ctl update
		;;
	*)
		echo "usage: $0 <start|stop|restart|foreground|update>"
		;;
	esac
}

ctl "$@"
