[Unit]
Description=Nullroute RWHO agent

[Service]
Type=forking
ExecStartPre=/bin/sh -c ". ~/lib/dotfiles/environ; perl -mLinux::Inotify2 -e1 || cpanm -f Linux::Inotify2"
ExecStartPre=/bin/sh -c ". ~/lib/dotfiles/environ; perl -mSys::Utmp -e1 || cpanm -f Sys::Utmp"
ExecStart=/home/grawity/lib/rwho/agent-linux/rwho-agent.sh start

[Install]
WantedBy=daemon.target
