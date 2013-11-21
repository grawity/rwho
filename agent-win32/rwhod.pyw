#!python
from __future__ import print_function
import os
import sys
import socket
import time
import ctypes as c
import json

if sys.platform != "win32":
    # simple sanity check
    raise RuntimeError("This script requires Windows NT with Terminal Services.")

import servicemanager as sm
import win32api as Api
from win32con import *
from win32gui import *
from win32ts import *
from win32security import *
import win32service
import win32serviceutil

SERVER_URL = "http://rwho.cluenet.org/server.php"

## Windows native types and constants

# generic types
UINT                = c.c_uint
WORD                = c.c_uint16
DWORD               = c.c_uint32
WCHAR               = c.c_wchar
HANDLE              = DWORD
HWND                = HANDLE

# window messages
WM_WTSSESSION_CHANGE        = 0x2B1

# WM_WTSSESSION_CHANGE events
WTS_CONSOLE_CONNECT         = 0x1
WTS_CONSOLE_DISCONNECT      = 0x2
WTS_REMOTE_CONNECT          = 0x3
WTS_REMOTE_DISCONNECT       = 0x4
WTS_SESSION_LOGON           = 0x5
WTS_SESSION_LOGOFF          = 0x6
WTS_SESSION_LOCK            = 0x7
WTS_SESSION_UNLOCK          = 0x8
WTS_SESSION_REMOTE_CONTROL  = 0x9

# http://msdn.microsoft.com/en-us/library/aa383860(v=vs.85).aspx
WTS_CONNECTSTATE_CLASS  = DWORD # enum
WTSActive               = 0
WTSConnected            = 1
WTSConnectQuery         = 2
WTSShadow               = 3
WTSDisconnected         = 4
WTSIdle                 = 5
WTSListen               = 6
WTSReset                = 7
WTSDown                 = 8
WTSInit                 = 9

# http://msdn.microsoft.com/en-us/library/aa383861(v=vs.85).aspx
WTS_INFO_CLASS          = DWORD # enum
WTSSessionInfo          = 24

# include <winsta.h>
# http://msdn.microsoft.com/en-us/library/cc248871(PROT.10).aspx
WINSTATIONNAME_LENGTH   = 32
USERNAME_LENGTH         = 20
DOMAIN_LENGTH           = 17

class LARGE_INTEGER(c.Structure):
    _fields_ = (
        ("HighPart",    DWORD),
        ("LowPart",     DWORD),
    )

class FILETIME(c.Structure):
    _fields_ = (
        ("dwHighDateTime",  DWORD),
        ("dwLowDateTime",   DWORD),
    )

class WTSINFO(c.Structure):
    # http://msdn.microsoft.com/en-us/library/bb736370(v=vs.85).aspx
    _fields_ = (
        ("State",               WTS_CONNECTSTATE_CLASS),
        ("SessionId",           DWORD),
        ("IncomingBytes",       DWORD),
        ("OutgoingBytes",       DWORD),
        ("IncomingFrames",      DWORD),
        ("OutgoingFrames",      DWORD),
        ("IncomingCompressedBytes", DWORD),
        ("OutgoingCompressedBytes", DWORD),
        ("WinStationName",      WCHAR * (WINSTATIONNAME_LENGTH+1)),
        ("Domain",              WCHAR * (DOMAIN_LENGTH+1)),
        ("UserName",            WCHAR * (USERNAME_LENGTH+1)),
        ("ConnectTime",         FILETIME),
        ("DisconnectTime",      FILETIME),
        ("LastInputTime",       FILETIME),
        ("LogonTime",           FILETIME),
        ("CurrentTime",         FILETIME),
    )

## Windows native functions

def _wtsapi_WTSQuerySessionInformation(hServer, sessionID, infoClass):
    ppBuffer = DWORD(0)
    pBytesReturned = DWORD(0)

    res = c.windll.wtsapi32.WTSQuerySessionInformationW(HANDLE(hServer),
                                                        DWORD(sessionID),
                                                        WTS_INFO_CLASS(infoClass),
                                                        c.byref(ppBuffer),
                                                        c.byref(pBytesReturned))

    if res:
        return (ppBuffer, pBytesReturned)
    else:
        print("WTSQuerySessionInformationW: error %r" % Api.GetLastError())
        return (0, 0)

def WTSQuerySessionInformation_SessionInfo(hServer, sessionId):
    # only supported starting with Server 2008 & Vista SP1
    buf, bufsize = _wtsapi_WTSQuerySessionInformation(hServer, sessionId, WTSSessionInfo)
    if bufsize:
        return buf, c.cast(buf.value, c.POINTER(WTSINFO)).contents
    else:
        return None, None

def SetTimer(hWnd, IDEvent, elapse):
    IDEvent = UINT(IDEvent)
    if c.windll.user32.SetTimer(HWND(hWnd), c.byref(IDEvent), UINT(elapse), None):
        return IDEvent.value
    else:
        return False

def UnixTimeFromFileTime(ftime):
    return (((ftime.dwHighDateTime << 32) | ftime.dwLowDateTime) - 116444736000000000) / 10000000.0

def collect_session_info():
    hServer = WTS_CURRENT_SERVER_HANDLE
    #hServer = WTSOpenServer("...")
    for sess in WTSEnumerateSessions(hServer):
        print("session", sess)

        if sess["State"] != WTSActive:
            # skip inactive (disconnected) sessions
            continue

        sessionId = sess["SessionId"]
        user = WTSQuerySessionInformation(hServer, sessionId, WTSUserName)
        if not user:
            print("skipping (null user)")
            continue

        entry = {}
        entry["user"] = user
        entry["line"] = sess["WinStationName"]
        entry["host"] = WTSQuerySessionInformation(hServer, sessionId, WTSClientName)

        ptr, sessionInfo = WTSQuerySessionInformation_SessionInfo(hServer, sessionId)
        # only supported starting with Server 2008 & Vista SP1
        if sessionInfo:
            print("logonTime", sessionInfo.LogonTime)
            entry["time"] = int(UnixTimeFromFileTime(sessionInfo.LogonTime))
            c.windll.wtsapi32.WTSFreeMemory(ptr)
        else:
            entry["time"] = 0

        if hServer == WTS_CURRENT_SERVER_HANDLE:
            uSid, uDom, acctType = LookupAccountName(None, user)
            uSidAuthorities = [uSid.GetSubAuthority(i)
                               for i in range(uSid.GetSubAuthorityCount())]
            entry["uid"] = uSidAuthorities[-1]
            entry["domain"] = uDom
        else:
            entry["uid"] = 0
            entry["domain"] = None

        yield entry

def update():
    upload("put", list(collect_session_info()))

def cleanup():
    upload("destroy", [])

def upload(action, sdata):
    try:
        from urllib import urlencode
        from urllib2 import urlopen
    except ImportError:
        from urllib.parse import urlencode
        from urllib.request import urlopen

    print("uploading %d items" % len(sdata))
    data = {
        "host": socket.gethostname().lower(),
        "fqdn": socket.getfqdn().lower(),
        "opsys": sys.platform,
        "action": action,
        "utmp": json.dumps(sdata),
    }
    data = urlencode(data).encode("utf-8")
    resp = urlopen(SERVER_URL, data)
    print("server returned: %r" % resp.readline().strip())

class WTSSessionEventMonitor():
    """Windows Terminal Services session event monitor"""

    className = "Monitor"
    wndName = "Monitor"

    def __init__(self):
        wc = WNDCLASS()
        wc.hInstance     = GetModuleHandle(None)
        wc.lpszClassName = self.className
        wc.lpfnWndProc   = self.WndProc

        self.classAtom = RegisterClass(wc)

        self.hWnd = CreateWindow(self.classAtom, self.wndName,
            # style, x, y, nWidth, nHeight,
            0, 0, 0, CW_USEDEFAULT, CW_USEDEFAULT,
            # hWndParent, hMenu, hInstance, lpParam
            0, 0, wc.hInstance, None)
        UpdateWindow(self.hWnd)

        WTSRegisterSessionNotification(self.hWnd, NOTIFY_FOR_ALL_SESSIONS)

    def WndProc(self, hWnd, message, wParam, lParam):
        if message == WM_CLOSE:
            DestroyWindow(hWnd)
        elif message == WM_DESTROY:
            PostQuitMessage(0)
        elif message == WM_QUERYENDSESSION:
            return True
        else:
            return DefWindowProc(hWnd, message, wParam, lParam)

class RWhoMonitor(WTSSessionEventMonitor):
    def __init__(self):
        WTSSessionEventMonitor.__init__(self)

        self.running = False
        self.timerId = 42
        self.periodic_timeout = 10*60

        try:
            Api.GetConsoleTitle()
        except:
            self.have_console = False
        else:
            self.have_console = True

    def run(self):
        print("starting monitor")
        try:
            if self.have_console:
                Api.SetConsoleCtrlHandler(self.OnConsoleCtrlEvent, True)

            self.running = True
            self.OnTimer()
            PumpMessages()
        except:
            self.running = False
            raise

    def stop(self):
        print("stopping monitor")
        try:
            self.running = False

            cleanup()
            SendMessage(self.hWnd, WM_DESTROY, 0, 0)
        except:
            self.running = True
            raise

    def WndProc(self, hWnd, message, wParam, lParam):
        DefWndProc = lambda: \
            WTSSessionEventMonitor.WndProc(self, hWnd, message, wParam, lParam)

        if message == WM_POWERBROADCAST:
            if wParam == PBT_APMSUSPEND:
                self.OnSuspend()
            elif wParam == PBT_APMRESUMESUSPEND:
                self.OnResume()
            return DefWndProc()
        elif message == WM_TIMER:
            self.OnTimer()
        elif message == WM_WTSSESSION_CHANGE:
            self.OnSession(wParam, lParam)
        elif message == WM_ENDSESSION:
            self.OnShutdown()
            return True
        elif message == WM_DESTROY:
            self.OnDestroy()
            return DefWndProc()
        else:
            return DefWndProc()

    def OnConsoleCtrlEvent(self, event):
        # http://msdn.microsoft.com/en-us/library/ms683242%28v=vs.85%29.aspx
        print("* console CtrlEvent(%d)" % event)
        if not self.running:
            return

        self.stop()

    def OnSuspend(self):
        print("* suspend")
        if not self.running:
            return

        cleanup()

    def OnResume(self):
        print("* resume")
        if not self.running:
            return

        update()

    def OnShutdown(self):
        print("* shutdown")
        if not self.running:
            return

        cleanup()

    def OnDestroy(self):
        print("* destroy")
        if not self.running:
            return

        cleanup()

    def OnTimer(self):
        print("* timer")
        if not self.running:
            return

        self.timerId = SetTimer(self.hWnd, self.timerId,
                    self.periodic_timeout*1000)
        update()

    def OnSession(self, event, session):
        if not self.running:
            return

        event_name = {
            WTS_CONSOLE_CONNECT:        "connected to console",
            WTS_CONSOLE_DISCONNECT:     "disconnected from console",
            WTS_REMOTE_CONNECT:         "connected remotely",
            WTS_REMOTE_DISCONNECT:      "disconnected remotely",
            WTS_SESSION_LOGON:          "logged on",
            WTS_SESSION_LOGOFF:         "logged off",
            WTS_SESSION_LOCK:           "locked",
            WTS_SESSION_UNLOCK:         "unlocked",
            WTS_SESSION_REMOTE_CONTROL: "remote control",
        }.get(event, "unknown %d" % event)

        print("* session %d %s" % (session, event_name))
        update()

class RWhoService(win32serviceutil.ServiceFramework):
    _svc_name_ = "rwhod"
    _svc_display_name_ = "rwho daemon"

    def __init__(self, args):
        win32serviceutil.ServiceFramework.__init__(self, args)

    def SvcStop(self):
        self.ReportServiceStatus(win32service.SERVICE_STOP_PENDING)
        cleanup()
        PostQuitMessage(0)
        self.ReportServiceStatus(win32service.SERVICE_STOPPED)

    def SvcDoRun(self):
        m = RWhoMonitor()

if __name__ == '__main__':
    try:
        if len(sys.argv) > 1:
            win32serviceutil.HandleCommandLine(RWhoService)
        else:
            mon = RWhoMonitor()
            try:
                mon.run()
            except KeyboardInterrupt:
                # keyboard interrupts will be handled by monitor itself
                pass
    except BaseException as e:
        path = os.path.expandvars("%TEMP%/rwho.log")
        with open(path, "a") as log:
            print(repr(e), file=log)
        raise
