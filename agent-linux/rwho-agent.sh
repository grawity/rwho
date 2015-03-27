#!/usr/bin/env bash

RWHO_DIR="$(dirname "$0")/.."
AGENT="$RWHO_DIR/agent-linux/rwho-agent"
ARGS=""

if [ "$(whoami)" = "root" ]; then
	PIDFILE="/run/rwho-agent.pid"
	CONFIG="/etc/rwho.conf"
else
	PIDFILE="${XDG_CACHE_HOME:-$HOME/.cache}/rwho/rwho-agent.$HOSTNAME.pid"
	CONFIG="$RWHO_DIR/rwho.conf"

	PERL5LIB="$HOME/.local/lib/perl5"
	export PERL5LIB
fi

if [ -s "$CONFIG" ]; then
	ARGS+=" --config $CONFIG"
fi

ctl() {
	case $1 in
	start)
		mkdir -p "$(dirname "$PIDFILE")"
		exec "$AGENT" --pidfile "$PIDFILE" --daemon $ARGS
		;;
	foreground)
		mkdir -p "$(dirname "$PIDFILE")"
		exec "$AGENT" --pidfile "$PIDFILE" --verbose $ARGS
		;;
	stop)
		if [ -f "$PIDFILE" ]; then
			read -r pid < "$PIDFILE" &&
			kill "$pid" &&
			rm -f "$PIDFILE"
		else
			echo "not running (pidfile not found)"
		fi
		;;
	restart|reload|force-reload)
		ctl stop
		ctl start
		;;
	update)
		if [ "$AGENT" -nt "$PIDFILE" ]; then
			ctl restart
		fi
		;;
	git-update)
		cd "$RWHO_PATH" &&
		git pull --quiet --ff-only &&
		ctl update
		;;
	status)
		if [ ! -f "$PIDFILE" ]; then
			echo "stopped (no pidfile)"
			return 3
		fi

		if ! read -r pid < "$PIDFILE"; then
			echo "unknown (cannot read pidfile)"
			return 1
		fi

		if kill -0 "$pid" 2> /dev/null; then
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
		Socket::GetAddrInfo
		Sys::Utmp
		'
		case `uname` in
		FreeBSD)
			perldeps+='
			IO::KQueue
			';;
		Linux)
			perldeps+='
			Linux::Inotify2
			';;
		esac
		${CPAN:-echo} $perldeps
		;;
	*)
		echo "usage: $0 <start|stop|restart|foreground|status|update>"
		;;
	esac
}

ctl "$@"
