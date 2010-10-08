#!/usr/bin/python
"""""""""""""""""""""""""""""""""""""""""""""""""""""""""""""""""""""""""""""
Half of this hasn't been implemented yet.
"""""""""""""""""""""""""""""""""""""""""""""""""""""""""""""""""""""""""""""

import ctypes	as c
import socket	as so
import win32api	as api
#import win32con	as con
import win32ts	as ts
import win32security	as sec
import json
from urllib import urlencode
import urllib2
from time import sleep

class WTS_INFO_CLASS():
	InitialProgram          = 0
	ApplicationName         = 1
	WorkingDirectory        = 2
	OEMId                   = 3
	SessionId               = 4
	UserName                = 5
	WinStationName          = 6
	DomainName              = 7
	ConnectState            = 8
	ClientBuildNumber       = 9
	ClientName              = 10
	ClientDirectory         = 11
	ClientProductId         = 12
	ClientHardwareId        = 13
	ClientAddress           = 14
	ClientDisplay           = 15
	ClientProtocolType      = 16
	IdleTime                = 17
	LogonTime               = 18
	IncomingBytes           = 19
	OutgoingBytes           = 20
	IncomingFrames          = 21
	OutgoingFrames          = 22
	ClientInfo              = 23
	SessionInfo             = 24
	SessionInfoEx           = 25
	ConfigInfo              = 26
	ValidationInfo          = 27
	SessionAddressV4        = 28
	IsRemoteSession         = 29

def _wtsapi_WTSQuerySessionInformation(hServer, sessionID, infoClass):
	ppBuffer = c.c_int32()
	pBytesReturned = c.c_int32()
	if c.windll.wtsapi32.WTSQuerySessionInformationW(
		c.c_int32(hServer), c.c_int32(sessionID), c.c_int32(infoClass),
		c.byref(ppBuffer), c.byref(pBytesReturned)):
		return (ppBuffer, pBytesReturned)

SERVER_URL = "http://equal.cluenet.org/~grawity/rwho/server.php"

def get_sessions():
	protocols = {
		ts.WTS_PROTOCOL_TYPE_CONSOLE: "console",
		ts.WTS_PROTOCOL_TYPE_ICA: "citrix",
		ts.WTS_PROTOCOL_TYPE_RDP: "rdp",
	}
	
	hServer = ts.WTS_CURRENT_SERVER_HANDLE
	#hServer = ts.WTSOpenServer("digit.cluenet.org")
	curSessId = ts.WTSGetActiveConsoleSessionId()
	
	for sess in ts.WTSEnumerateSessions(hServer):
		utent = {}
		
		id = sess["SessionId"]
		for key, const in {
			"User":		ts.WTSUserName,
			"Address":	ts.WTSClientAddress,
			"Client":	ts.WTSClientName,
			"Protocol":	ts.WTSClientProtocolType,
			#"XClient":	23, #ts.WTSClientInfo,
			#"XSession":	24, #ts.WTSSessionInfo,
		}.items():
			sess[key] = ts.WTSQuerySessionInformation(hServer, id, const)
		
		if not sess["User"]:
			# skip non-login sessions
			continue
		if sess["State"] != 0:
			continue

		userSid, userDomain, acctType = sec.LookupAccountName(None, sess["User"])
		userSidAuths = [userSid.GetSubAuthority(i) for i in range(userSid.GetSubAuthorityCount())]

		utent["user"] = sess["User"]
		utent["uid"] = userSidAuths[-1]
		utent["host"] = ""
		utent["line"] = "%s/%s" % (sess["WinStationName"].lower(), id)
		utent["time"] = 0
		#utent["proto"] = protocols.get(sess["Protocol"], "unknown")
		
		print "="*79
		for k, v in sess.items():
			print "%-10s: %s" % (k, repr(v))
		print
		for k, v in utent.items():
			print "%-10s: %s" % (k, repr(v))
		
		yield utent

def upload(utmp):
	data = {
		"host": so.gethostname().lower(),
		"fqdn": so.getfqdn().lower(),
		"action": "put",
		"utmp": json.dumps(utmp),
	}
	resp = urllib2.urlopen(SERVER_URL, urlencode(data))
	print resp.read()

utmp = list(get_sessions())
upload(utmp)
