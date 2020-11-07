import os
import sys
import syslog

def log_debug(msg, *args):
    if os.environ.get("DEBUG"):
        msg = msg % args
        print(msg, file=sys.stderr, flush=True)
        syslog.syslog(syslog.LOG_DEBUG, msg)

def log_info(msg, *args):
    msg = msg % args
    print(msg, file=sys.stderr, flush=True)
    syslog.syslog(syslog.LOG_INFO, msg)

def log_err(msg, *args):
    msg = msg % args
    print("error: %s" % msg, file=sys.stderr, flush=True)
    syslog.syslog(syslog.LOG_ERR, msg)
