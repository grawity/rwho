# rwho

A hack to centrally display all users logged in to a bunch of servers.

  * Runs over plain HTTP with no security
  * Stores the data in MySQL
  * Has a simple web UI, with HTML, JSON, and XML interfaces
  * Updates are sent instantly

## Contents

### agent-linux -- Linux agent

Uses `/run/utmp`, with inotify and/or periodic updates. Requires only read access to the `utmp` database; in other words, a standard account.

Dependencies:

  * Perl
      * JSON
      * LWP::UserAgent
      * Linux::Inotify2
      * Socket::GetAddrInfo
      * Sys::Utmp

### agent-windows -- Windows NT agent

Uses Terminal Services API, requires Administrator privileges to see sessions other than current.

Dependencies:

  * Python 2.x or 3.x
  * [PyWin32][pywin32]

[pywin32]: http://sourceforge.net/projects/pywin32/files/pywin32/

### lib-php -- PHP5 library

Used by current UIs, interfaces directly with the database.

### ui-cli -- command-line UI

Does nothing more but fork ui-finger and pass the arguments to stdin.

### ui-finger -- Finger UI

Inetd-style server for the Finger protocol. Accepts a single query in stdin, dumps results to stdout.

### ui-web -- HTML UI

Exactly what it says on the tin.
