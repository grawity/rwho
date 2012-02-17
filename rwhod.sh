#!/usr/bin/env bash

RWHO_DIR="$(dirname "$0")"
RWHO_AGENT="$RWHO_DIR/agent-linux/rwhod"
RWHO_ARGS=""

if (( UID > 0 )); then
	RWHO_CONFIG="${XDG_CONFIG_HOME:-$HOME/.config}/rwho/rwhod.conf"
	RWHO_PIDFILE="${XDG_RUNTIME_DIR:-$HOME/.cache}/rwho/rwhod-$HOSTNAME.pid"
	OLD_PIDFILE="$HOME/tmp/rwhod-$HOSTNAME.pid"

	PERL5LIB="$HOME/.local/lib/perl5"
	export PERL5LIB
else
	RWHO_CONFIG="/etc/conf.d/rwhod"
	RWHO_PIDFILE="/run/rwhod.pid"
fi

ctl() {
	case $1 in
	start)
		mkdir -p "$(dirname "$RWHO_PIDFILE")"
		exec "$RWHO_AGENT" --pidfile="$RWHO_PIDFILE" --daemon $RWHO_ARGS
		;;
	foreground)
		exec "$RWHO_AGENT" --pidfile="$RWHO_PIDFILE" $RWHO_ARGS
		;;
	stop)
		if [ -f "$OLD_PIDFILE" ]; then
			read -r pid < "$OLD_PIDFILE" &&
			kill "$pid" &&
			rm "$OLD_PIDFILE"
		elif [ -f "$RWHO_PIDFILE" ]; then
			read -r pid < "$RWHO_PIDFILE" &&
			kill "$pid" &&
			rm "$RWHO_PIDFILE"
		else
			echo "not running (pidfile not found)"
		fi
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
		if [ ! -f "$RWHO_PIDFILE" ]; then
			echo "stopped (no pidfile)"
			return 3
		fi

		if ! read -r pid <"$RWHO_PIDFILE"; then
			echo "unknown (cannot read pidfile)"
			return 1
		fi

		if kill -0 "$pid" 2>/dev/null; then
			echo "running (pid $pid)"
			return 0
		else
			echo "unknown (pid $pid does not respond to signals)"
			return 1
		fi
		;;
	build-dep)
		perldeps='
		JSON
		LWP::UserAgent
		Linux::Inotify2
		Socket::GetAddrInfo
		Sys::Utmp
		'
		${CPAN:-cpanm} $perldeps
		;;
	update)
		if [ "$RWHO_AGENT" -nt "$RWHO_PIDFILE" ]; then
			ctl restart
		fi
		;;
	git-update)
		cd "$RWHO_PATH" &&
		git pull --quiet --ff-only &&
		ctl update
		;;
	*)
		echo "usage: $0 <start|stop|restart|foreground|status|update>"
		;;
	esac
}

ctl "$@"
