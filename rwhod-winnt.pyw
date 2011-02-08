from __future__ import print_function
import os, sys
import socket
import time
import ctypes	as c
import json
import servicemanager	as sm
from win32con import *
from win32gui import *
from win32ts import *
from win32security import *
import win32service
import win32serviceutil

# generic types
UINT				= c.c_uint
WORD				= c.c_uint16
DWORD				= c.c_uint32
WCHAR				= c.c_wchar
HWND				= DWORD

# window messages
WM_WTSSESSION_CHANGE		= 0x2B1

# WM_WTSSESSION_CHANGE events
WTS_CONSOLE_CONNECT		= 0x1
WTS_CONSOLE_DISCONNECT		= 0x2
WTS_REMOTE_CONNECT		= 0x3
WTS_REMOTE_DISCONNECT		= 0x4
WTS_SESSION_LOGON		= 0x5
WTS_SESSION_LOGOFF		= 0x6
WTS_SESSION_LOCK		= 0x7
WTS_SESSION_UNLOCK		= 0x8
WTS_SESSION_REMOTE_CONTROL	= 0x9

# http://msdn.microsoft.com/en-us/library/aa383860(v=vs.85).aspx
WTS_CONNECTSTATE_CLASS	= DWORD
WTSActive		= 0
WTSConnected		= 1
WTSConnectQuery		= 2
WTSShadow		= 3
WTSDisconnected		= 4
WTSIdle			= 5
WTSListen		= 6
WTSReset		= 7
WTSDown			= 8
WTSInit			= 9

# http://msdn.microsoft.com/en-us/library/aa383861(v=vs.85).aspx
WTS_INFO_CLASS		= DWORD
WTSSessionInfo		= 24

# include <winsta.h>
# http://msdn.microsoft.com/en-us/library/cc248871(PROT.10).aspx
WINSTATIONNAME_LENGTH		= 32
USERNAME_LENGTH			= 20
DOMAIN_LENGTH			= 17

class LARGE_INTEGER(c.Structure):
	_fields_ = (
		("HighPart",	DWORD),
		("LowPart",	DWORD),
	)
class FILETIME(c.Structure):
	_fields_ = (
		("dwHighDateTime",	DWORD),
		("dwLowDateTime",	DWORD),
	)
class WTSINFO(c.Structure):
	# http://msdn.microsoft.com/en-us/library/bb736370(v=vs.85).aspx
	_fields_ = (
		("State",			WTS_CONNECTSTATE_CLASS),
		("SessionId",			DWORD),
		("IncomingBytes",		DWORD),
		("OutgoingBytes",		DWORD),
		("IncomingFrames",		DWORD),
		("OutgoingFrames",		DWORD),
		("IncomingCompressedBytes",	DWORD),
		("OutgoingCompressedBytes",	DWORD),
		("WinStationName",		WCHAR * (WINSTATIONNAME_LENGTH+1)),
		("Domain",			WCHAR * (DOMAIN_LENGTH+1)),
		("UserName",			WCHAR * (USERNAME_LENGTH+1)),
		("ConnectTime",			FILETIME),
		("DisconnectTime",		FILETIME),
		("LastInputTime",		FILETIME),
		("LogonTime",			FILETIME),
		("CurrentTime",			FILETIME),
	)

#def displayStruct(structure):
#	for field, ftype in structure._fields_:
#		print "%25s: %r" % (field, structure.__getattribute__(field))

SERVER_URL = "http://equal.cluenet.org/rwho/server.php"

def _wtsapi_WTSQuerySessionInformation(hServer, sessionID, infoClass):
	ppBuffer = c.c_int32()
	pBytesReturned = c.c_int32()
	if c.windll.wtsapi32.WTSQuerySessionInformationW(
		c.c_int32(hServer), c.c_int32(sessionID), c.c_int32(infoClass),
		c.byref(ppBuffer), c.byref(pBytesReturned)):
		return (ppBuffer, pBytesReturned)
	else:
		return (0, 0)

def WTSQuerySessionInfo(hServer, sessionId):
	buf, bufsize = _wtsapi_WTSQuerySessionInformation(hServer, sessionId, WTSSessionInfo)
	if bufsize:
		return c.cast(buf.value, c.POINTER(WTSINFO)).contents
	else:
		return None

def SetTimer(hWnd, IDEvent, elapse):
	IDEvent = UINT(IDEvent)
	if c.windll.user32.SetTimer(HWND(hWnd), c.byref(IDEvent), UINT(elapse), None):
		return IDEvent.value
	else:
		return False

def UnixTimeFromFileTime(ftime):
	return (((ftime.dwHighDateTime << 32) | ftime.dwLowDateTime) - 116444736000000000) / 10000000.

class Monitor():
	className = "Monitor"
	wndName = "Monitor"
	def __init__(self):
		wc = WNDCLASS()
		wc.hInstance = hInst = GetModuleHandle(None)
		wc.lpszClassName = self.className
		wc.lpfnWndProc = self.WndProc
		self.classAtom = RegisterClass(wc)

		style = 0
		self.hWnd = CreateWindow(self.classAtom, self.wndName,
			style, 0, 0, CW_USEDEFAULT, CW_USEDEFAULT,
			0, 0, hInst, None)
		UpdateWindow(self.hWnd)

		WTSRegisterSessionNotification(self.hWnd, NOTIFY_FOR_ALL_SESSIONS)

	def WndProc(self, hWnd, message, wParam, lParam):
		if message == WM_CLOSE:
			DestroyWindow(hWnd)
		elif message == WM_DESTROY:
			PostQuitMessage(0)
		elif message == WM_QUERYENDSESSION:
			return True

class RWhoMonitor(Monitor):
	def __init__(self):
		Monitor.__init__(self)
		self.tid = 42
		self.periodic_timeout = 10*60
		self.OnTimer()
		PumpMessages()

	def WndProc(self, hWnd, message, wParam, lParam):
		if message == WM_POWERBROADCAST:
			if wParam == PBT_APMSUSPEND:
				self.OnSuspend()
			elif wParam == PBT_APMRESUMESUSPEND:
				self.OnResume()
		elif message == WM_TIMER:
			self.OnTimer()
		elif message == WM_WTSSESSION_CHANGE:
			self.OnSession(wParam, lParam)
		elif message == WM_CLOSE:
			DestroyWindow(hWnd)
		elif message == WM_ENDSESSION:
			self.OnShutdown()
		elif message == WM_DESTROY:
			self.OnShutdown()
			PostQuitMessage(0)
		elif message == WM_QUERYENDSESSION:
			return True

	def OnSuspend(self):
		cleanup()
	def OnResume(self):
		update()
	def OnShutdown(self):
		print("Shutdown")
		cleanup()
	def OnTimer(self):
		print("Timer")
		self.tid = SetTimer(self.hWnd, self.tid, self.periodic_timeout*1000)
		update()
	def OnSession(self, event, session):
		event = {
			WTS_CONSOLE_CONNECT		: "connected to console",
			WTS_CONSOLE_DISCONNECT		: "disconnected from console",
			WTS_REMOTE_CONNECT		: "connected remotely",
			WTS_REMOTE_DISCONNECT		: "disconnected remotely",
			WTS_SESSION_LOGON		: "logged on",
			WTS_SESSION_LOGOFF		: "logged off",
			WTS_SESSION_LOCK		: "locked",
			WTS_SESSION_UNLOCK		: "unlocked",
			WTS_SESSION_REMOTE_CONTROL	: "remote control"
		}.get(event, "unknown %d" % event)
		print("TSEvent: Session %d %s" % (session, event))
		update()

def collect_session_info():
	hServer = WTS_CURRENT_SERVER_HANDLE
	#hServer = WTSOpenServer("digit.cluenet.org")
	#hServer = WTSOpenServer("snow")
	for sess in WTSEnumerateSessions(hServer):
		print("Session:", sess)
		if sess["State"] != WTSActive:
			# skip inactive sessions (disconnected)
			continue
		sessionId = sess["SessionId"]
		user = WTSQuerySessionInformation(hServer, sessionId, WTSUserName)
		if not user:
			print("skipping (user)")
			continue
		entry = {}
		entry["user"] = user
		entry["line"] = sess["WinStationName"]
		entry["host"] = WTSQuerySessionInformation(hServer, sessionId, WTSClientName)
		sessionInfo = WTSQuerySessionInfo(hServer, sessionId)
		if sessionInfo:
			entry["time"] = int(UnixTimeFromFileTime(sessionInfo.LogonTime))
		else:
			entry["time"] = 0
		if hServer == WTS_CURRENT_SERVER_HANDLE:
			uSid, uDom, acctType = LookupAccountName(None, user)
			uSidAuthorities = [uSid.GetSubAuthority(i) for i in range(uSid.GetSubAuthorityCount())]
			entry["uid"] = uSidAuthorities[-1]
		else:
			entry["uid"] = 0
		yield entry

def update():
	upload(list(collect_session_info()))

def cleanup():
	upload([])

def upload(sdata):
	print("Uploading %d items" % len(sdata))
	data = {
		"host": socket.gethostname().lower(),
		"fqdn": socket.getfqdn().lower(),
		"action": "put",
		"utmp": json.dumps(sdata),
	}
	try:
		from urllib import urlencode
		from urllib2 import urlopen
	except ImportError:
		from urllib.parse import urlencode
		from urllib.request import urlopen
	resp = urlopen(SERVER_URL, urlencode(data))
	print(resp.read())

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
	if len(sys.argv) > 1:
		win32serviceutil.HandleCommandLine(RWhoService)
	else:
		m = RWhoMonitor()
