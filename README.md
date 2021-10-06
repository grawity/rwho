# RWho

This program is similar to the [`who`][who] command, but maintains a central
list of currently logged in accounts across multiple servers.

```
$ rwho
USER         HOST         LINE       FROM
grawity      ember        {45}       (tmux)
             land         pts/3      2a06:e881:108:2:64e6:9d86:856:b93c
             sky          pts/0      2a02:7b40:50d1:hjkl::1
             star         {13}       (tmux)
nobody       ember        pts/14     2001:778:e27f:0:9618:82ff:fe38:e480
             star         pts/9      star.nullroute.eu.org
             star         pts/1      78-59-7-25.static.zebra.lt
```

It was originally written in mid-2000s for a public-access Linux "[shell
account][]" network (similar to the _\~tilde clubs\~_ of nowadays), back when
you still had this sense of community around it... and when letting other
people know your IP address didn't matter so much.

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

  * There is support for showing the user's `~/.plan` file, either from the
    filesystem or even from LDAP.

[Finger]: https://en.wikipedia.org/wiki/Finger_protocol
[ex-http]: https://rwho.nullroute.lt/
[ex-finger]: https://nullroute.lt/finger/?q=%2Fw+grawity%40nullroute.lt

## Contents

  * `agent/` &ndash; new agent service for Linux (requires Python 3)
  * `agent-linux/` &ndash; old agent service for Linux and BSDs (requires Perl 5)
  * `agent-win32/` &ndash; old agent service for Windows XP/2003 (requires [pywin32][])
  * `server-php/` &ndash; API server for PHP
  * `ui-finger/` &ndash; a text interface for the [Finger][] protocol (inetd-style)
  * `ui-web/` &ndash; a slightly fancy HTML interface for Mozilla 1.7 and
    Internet Explorer 5 (requires PHP 7.4)

[pywin32]: https://sourceforge.net/projects/pywin32/files/pywin32
