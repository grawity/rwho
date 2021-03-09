# RWho

This program is similar to the [`who`][who] command, but maintains a central
list of currently logged in accounts across multiple servers.

It was originally written in mid-2000s for a public-access Linux "[shell
account][]" network (similar to the _~tilde clubs~_ of nowadays), back when you
still had this sense of community around it... and when letting other people
know your IP address didn't matter so much.

Nowadays, of course, it really shouldn't be used without carefully considering
the privacy implications.

RWho was greatly inspired by the BSD Unix `rwho`, though has no direct
relationship to it (except for the name).

[who]: https://man.openbsd.org/who
[shell account]: https://en.wikipedia.org/wiki/Shell_account

## Features

Data is stored on a central server (currently &ndash; in a MySQL database),
with hosts sending live and periodic updates.

  * Hosts use HTTP Basic authentication over TLS (Kerberos is also planned).
    Originally, in the spirit of Unix rwho, no authentication was done at all.

  * "Agents" exist for Linux, BSDs, and Windows Server.

The information can be displayed through a fancy web interface or through the
traditional Finger protocol. See it in action via [HTTP][ex-http] or
[Finger][ex-finger].

[Finger]: https://en.wikipedia.org/wiki/Finger_protocol
[ex-http]: https://rwho.nullroute.eu.org/
[ex-finger]: https://nullroute.eu.org/finger/?q=%2Fw+grawity%40nullroute.eu.org

## Contents

  * `agent/` &ndash; new agent service for Linux (requires Python 3)
  * `agent-linux/` &ndash; old agent service for Linux and BSDs (requires Perl 5)
  * `agent-win32/` &ndash; old agent service for Windows XP/2003 (requires [pywin32][])
  * `server-php/` &ndash; API server for PHP
  * `ui-finger/` &ndash; a text interface for the [Finger][] protocol (inetd-style)
  * `ui-web/` &ndash; a slightly fancy HTML interface

[pywin32]: https://sourceforge.net/projects/pywin32/files/pywin32
