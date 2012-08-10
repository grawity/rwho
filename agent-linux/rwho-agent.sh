#!/usr/bin/env bash

RWHO_DIR="$(dirname "$0")/.."
RWHO_AGENT="$RWHO_DIR/agent-linux/rwho-agent"
RWHO_ARGS=""

if [ "$(whoami)" = "root" ]; then
	RWHO_CONFIG="/etc/conf.d/rwho-agent"
	RWHO_PIDFILE="/run/rwho-agent.pid"
	CONFIG_FILE="/etc/rwho.conf"
else
	RWHO_CONFIG="${XDG_CONFIG_HOME:-$HOME/.config}/rwho/rwho-agent.conf"
	RWHO_PIDFILE="${XDG_CACHE_HOME:-$HOME/.cache}/rwho/rwho-agent.$HOSTNAME.pid"
	CONFIG_FILE="$RWHO_DIR/rwho.conf"

	PERL5LIB="$HOME/.local/lib/perl5"
	export PERL5LIB
fi

if [ -e "$RWHO_CONFIG" ]; then
	. "$RWHO_CONFIG"
fi

if [ "$CONFIG_FILE" && -s "$CONFIG_FILE" ]; then
	RWHO_ARGS="$RWHO_ARGS --config $CONFIG_FILE"
fi

ctl() {
	case $1 in
	start)
		mkdir -p "$(dirname "$RWHO_PIDFILE")"
		exec "$RWHO_AGENT" --pidfile="$RWHO_PIDFILE" --daemon $RWHO_ARGS
		;;
	foreground)
		mkdir -p "$(dirname "$RWHO_PIDFILE")"
		exec "$RWHO_AGENT" --pidfile="$RWHO_PIDFILE" --verbose $RWHO_ARGS
		;;
	stop)
		if [ -f "$RWHO_PIDFILE" ]; then
			read -r pid < "$RWHO_PIDFILE" &&
			kill "$pid" &&
			rm -f "$RWHO_PIDFILE"
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
	update)
		if [ "$RWHO_AGENT" -nt "$RWHO_PIDFILE" ]; then
			ctl restart
		fi
		;;
	*)
		echo "usage: $0 <start|stop|restart|foreground|status|update>"
		;;
	esac
}

ctl "$@"
