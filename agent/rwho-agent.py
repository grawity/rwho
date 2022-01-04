#!/usr/bin/env python3
import argparse
import errno
import ipaddress
import os
from pprint import pprint
import pyinotify
import re
import select
import signal
import socket
import time

from lib.exceptions import *
from lib.utmp_linux import UTMP_PATH, enum_sessions
from lib.api_client import RwhoUploader
from lib.config import ConfigReader
from lib.log_util import *

# Exit status for permanent errors (RestartPreventExitStatus)
EX_LSB_NOTCONFIGURED = 6
EX_LSB_NOTRUNNING = 7
EX_NORESTART = EX_LSB_NOTRUNNING

class RwhoAgent():
    DEFAULT_SERVER = "https://rwho.nullroute.lt/server/"
    CONFIG_PATH = "/etc/rwho/agent.conf"
    KOD_PATH = "/etc/rwho/agent.kod"

    def __init__(self, config_path=None,
                       config_data=None):
        self.config = ConfigReader(config_path or self.CONFIG_PATH)
        self.config.merge(config_data or [])
        self.check_kod()
        self.server_url = self.config.get_str("agent.notify_url", self.DEFAULT_SERVER)
        self.ignored_users = {"root"}
        self.attempt_rdns = self.config.get_bool("agent.attempt_rdns", True)
        self.last_upload = -1
        self.wake_interval = 1*15
        self.update_interval = 1*60
        # TODO: Verify that update_interval >= wake_interval

        self.api = RwhoUploader(self.server_url)
        self.api.host_name = socket.gethostname().lower()
        self.api.host_fqdn = socket.getfqdn().lower()
        log_info("identifying as %r (aka %r)", self.api.host_fqdn, self.api.host_name)

        if self.config.get_bool("agent.auth_gssapi"):
            log_info("using GSSAPI authentication")
            self.api.auth_method = "gssapi"
        elif pwd := self.config.get_str("agent.auth_password"):
            log_info("using Basic authentication")
            self.api.auth_method = "basic"
            self.api.auth_pass = pwd
        elif os.environ.get("KRB5_CLIENT_KTNAME") \
          or os.environ.get("KRB5CCNAME") \
          or os.environ.get("GSS_USE_PROXY"):
            log_info("using GSSAPI authentication (detected from environment)")
            self.api.auth_method = "gssapi"
        else:
            log_info("using no authentication")

        if names := self.config.get_str("agent.exclude_users"):
            self.ignored_users |= {*names.split()}

    def check_kod(self):
        if os.path.exists(self.KOD_PATH):
            with open(self.KOD_PATH, "r") as fh:
                message = fh.readline()
            raise RwhoShutdownRequestedError(message)

    def store_kod(self, message):
        with open(self.KOD_PATH, "w") as fh:
            fh.write(message)

    def _try_rdns(self, addr):
        if addr.startswith(("tmux(")):
            return addr
        elif m := re.match(r"^(.+)( via mosh \[\d+\])$", addr):
            return self._try_rdns(m.group(1)) + m.group(2)
        else:
            try:
                _ = ipaddress.ip_address(addr)
            except ValueError:
                return addr
            else:
                try:
                    host, _ = socket.getnameinfo((addr, 9), 0)
                except Exception as e:
                    log_err("getnameinfo(%r) failed: %r", addr, e)
                    return addr
                else:
                    return host or addr

    def enum_sessions(self):
        sessions = [*enum_sessions()]
        if self.ignored_users:
            sessions = [s for s in sessions
                        if s["user"] not in self.ignored_users]
        if self.attempt_rdns:
            for s in sessions:
                s["host"] = self._try_rdns(s["host"])
        return sessions

    def refresh(self):
        sessions = self.enum_sessions()
        log_info("uploading %d sessions" % len(sessions))
        try:
            self.api.put_sessions(sessions)
            self.last_upload = time.time()
        except RwhoShutdownRequestedError as e:
            log_debug("shutdown requested, giving up")
            self.store_kod(e.args[0])
            raise

    def cleanup(self):
        log_info("removing all host data")
        try:
            self.api.remove_host()
        except RwhoShutdownRequestedError as e:
            log_debug("shutdown requested, giving up")
            self.store_kod(e.args[0])
            raise

def run_forever(agent):
    def on_periodic_upload():
        if agent.last_upload < time.time() - agent.update_interval:
            log_debug("periodic: uploading on timer")
            try:
                agent.refresh()
            except RwhoShutdownRequestedError:
                raise
            except Exception as e:
                log_err("periodic: upload failed: %r", e)
                raise
        else:
            log_debug("periodic: last upload too recent, skipping")

    def on_inotify_event(event):
        log_debug("inotify: uploading on event %r", event)
        try:
            agent.refresh()
        except RwhoShutdownRequestedError:
            raise
        except Exception as e:
            log_err("inotify: upload failed: %r", e)
            raise
        return False

    def on_signal(sig, frame):
        log_info("received signal %r, exiting", sig)
        raise KeyboardInterrupt

    watchmgr = pyinotify.WatchManager()
    watchmgr.add_watch(UTMP_PATH, pyinotify.IN_MODIFY)
    notifier = pyinotify.Notifier(watchmgr, default_proc_fun=on_inotify_event)

    poll = select.poll()
    poll.register(notifier._fd, select.POLLIN | select.POLLHUP)

    signal.signal(signal.SIGINT, on_signal)
    signal.signal(signal.SIGTERM, on_signal)
    signal.signal(signal.SIGQUIT, on_signal)

    try:
        log_debug("performing initial upload")
        on_periodic_upload()
        log_debug("entering main loop")
        sd_notify("READY=1")
        while True:
            r = poll.poll(agent.wake_interval * 1e3)
            # We only have one fd -- either r[0] is inotify,
            # or the poll() call timed out.
            if r:
                notifier.read_events()
                notifier.process_events()
            else:
                on_periodic_upload()
        log_debug("loop was stopped")
        sd_notify("STOPPING=1")
        agent.cleanup()
    except KeyboardInterrupt:
        log_debug("KeyboardInterrupt received")
        sd_notify("STOPPING=1")
        agent.cleanup()

if __name__ == "__main__":
    parser = argparse.ArgumentParser()
    parser.add_argument("-c", "--config",
                        help="specify path to configuration file")
    parser.add_argument("-o", "--option", default=[], action="append",
                        help="set a configuration parameter")
    args = parser.parse_args()

    try:
        agent = RwhoAgent(config_path=args.config,
                          config_data=args.option)
        run_forever(agent)
    except RwhoShutdownRequestedError as e:
        log_err("exiting on server shutdown request: %s", e.args[0])
        sd_notify("ERRNO=%d" % errno.ENOLINK)
        exit(EX_NORESTART)
    except RwhoPermanentError as e:
        log_err("exiting on permanent error: %s", e)
        exit(EX_NORESTART)
