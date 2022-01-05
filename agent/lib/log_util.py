import os
import socket
import sys
import syslog

def sd_notify(*msgs):
    if path := os.environ.get("NOTIFY_SOCKET"):
        if path[0] == "@":
            path = "\0" + path[1:]
        msg = "\n".join(msgs)
        sock = socket.socket(socket.AF_UNIX, socket.SOCK_DGRAM)
        sock.sendto(msg.encode("utf-8"), path)

def log_debug(msg, *args):
    if os.environ.get("DEBUG"):
        msg = msg % args
        if sys.stdout.isatty():
            msg = "\033[2m%s\033[m" % msg
        print(msg, file=sys.stdout, flush=True)
        syslog.syslog(syslog.LOG_DEBUG, msg)

def log_info(msg, *args):
    msg = msg % args
    print(msg, file=sys.stdout, flush=True)
    syslog.syslog(syslog.LOG_INFO, msg)

def log_err(msg, *args):
    msg = msg % args
    msg = "error: %s" % msg
    print(msg, file=sys.stdout, flush=True)
    syslog.syslog(syslog.LOG_ERR, msg)
