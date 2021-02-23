import ctypes
import enum
import ipaddress
import pwd
import struct

from lib.log_util import *

UTMP_PATH = "/run/utmp"

UT_LINESIZE = 32
UT_NAMESIZE = 32
UT_HOSTSIZE = 256

class UtType(enum.IntEnum):
    EMPTY           = 0
    RUN_LVL         = 1
    BOOT_TIME       = 2
    NEW_TIME        = 3
    OLD_TIME        = 4
    INIT_PROCESS    = 5
    LOGIN_PROCESS   = 6
    USER_PROCESS    = 7
    DEAD_PROCESS    = 8
    ACCOUNTING      = 9

def timeval_to_float(tv):
    return tv[0] + tv[1]/1e6

class struct_exit_status(ctypes.Structure):
    _fields_ = [
        ("e_termination",   ctypes.c_short),
        ("e_exit",          ctypes.c_short),
    ]

    def __repr__(self):
        return "<exit_status(termination=%r, exit=%r)>" % (self.e_termination, self.e_exit)

class struct_timeval(ctypes.Structure):
    _fields_ = [
        ("tv_sec",          ctypes.c_int32),
        ("tv_usec",         ctypes.c_int32),
    ]

    def __repr__(self):
        return "<timeval(sec=%r, usec=%r)>" % (self.tv_sec, self.tv_usec)

class struct_utent(ctypes.Structure):
    _fields_ = [
        ("ut_type",         ctypes.c_short),
        ("ut_pid",          ctypes.c_int32), # pid_t
        ("ut_line",         ctypes.c_char * UT_LINESIZE),
        ("ut_id",           ctypes.c_char * 4),
        ("ut_user",         ctypes.c_char * UT_NAMESIZE),
        ("ut_host",         ctypes.c_char * UT_HOSTSIZE),
        ("ut_exit",         struct_exit_status),
        ("ut_session",      ctypes.c_int32),
        ("ut_tv",           struct_timeval),
        ("ut_addr_v6",      ctypes.c_uint32 * 4),
        ("__reserved",      ctypes.c_char * 20),
    ]

    def __repr__(self):
        return "<utent(type=%r, pid=%r, line=%r, id=%r, user=%r, host=%r, exit=%r, session=%r, tv=%r, addr_v6=%r)>" % (self.ut_type, self.ut_pid, self.ut_line, self.ut_id, self.ut_user, self.ut_host, self.ut_exit, self.ut_session, self.ut_tv, [*self.ut_addr_v6])

def enum_utmp(path=None):
    sz = ctypes.sizeof(struct_utent)
    with open(path or UTMP_PATH, "rb") as fh:
        while buf := fh.read(sz):
            en = struct_utent.from_buffer_copy(buf)
            addr = en.ut_addr_v6
            if addr[0]:
                if addr[1] or addr[2] or addr[3]:
                    addr = ipaddress.ip_address(struct.pack("IIII", *addr))
                else:
                    addr = ipaddress.ip_address(struct.pack("I", addr[0]))
            else:
                addr = None
            yield {
                "type": UtType(en.ut_type),
                "pid": en.ut_pid,
                "line": en.ut_line.decode("ascii"),
                "id": en.ut_id.decode("ascii"),
                "user": en.ut_user.decode("utf-8", errors="replace"),
                "host": en.ut_host.decode("utf-8", errors="replace"),
                "exit": {
                    "termination": en.ut_exit.e_termination,
                    "exit": en.ut_exit.e_exit,
                },
                "session": en.ut_session,
                "tv": (en.ut_tv.tv_sec, en.ut_tv.tv_usec),
                "addr": addr,
            }

def enum_sessions(path=None):
    for en in enum_utmp(path):
        if en["type"] == UtType.USER_PROCESS:
            try:
                pwent = pwd.getpwnam(en["user"])
                uid = pwent.pw_uid
            except KeyError:
                log_info("Skipping utmp entry for nonexistent username %r" % en["user"])
                continue

            yield {
                "user": en["user"],
                "line": en["line"],
                "host": en["host"],
                "time": timeval_to_float(en["tv"]),
                "uid": pwent.pw_uid,
            }
