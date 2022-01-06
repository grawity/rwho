# Copy of n.jsonrpc.client 2022-01-05
import itertools
import requests

# We don't need the binary-capable variants for now.
#from n.jsonrpc.util import json_load, json_dump
from json import (dumps as json_dump,
                  loads as json_load)

class RemoteFault(RuntimeError):
    def __init__(self, arg):
        self.code = arg["code"]
        self.message = arg["message"]
        self.data = arg.get("data")

    def as_dict(self):
        return {"code": self.code, "message": self.message, "data": self.data}

class JsonRpcClient():
    def __init__(self, url, *,
                       gss_service=None):
        self.url = url
        self.ids = itertools.count()
        self.ua = requests.Session()
        if gss_service:
            self.rpc_set_auth_gssapi(gss_service)

    def rpc_set_auth_basic(self, username, password):
        import requests.auth
        self.ua.auth = requests.auth.HTTPBasicAuth(username.encode(),
                                                   password.encode())

    def rpc_set_auth_gssapi(self, service="HTTP"):
        import gssapi
        import requests_gssapi
        spnego = gssapi.Mechanism.from_sasl_name("SPNEGO")
        self.ua.auth = requests_gssapi.HTTPSPNEGOAuth(target_name=service,
                                                      mech=spnego,
                                                      opportunistic_auth=True)

    def rpc_call(self, method, params=None):
        if params is None:
            params = []
        callid = next(self.ids)
        data = {"jsonrpc": "2.0", "method": method, "params": params, "id": callid}
        resp = self.ua.post(self.url, data=json_dump(data))
        resp.raise_for_status()
        data = json_load(resp.content)
        if data.get("jsonrpc") != "2.0":
            raise RuntimeError("response JSON-RPC version mismatch")
        if data.get("id") != callid:
            raise RuntimeError("response ID %r did not match call ID %r" % (data.get("id"),
                                                                            callid))
        if err := data.get("error"):
            raise RemoteFault(err)
        return data["result"]

    def __getattr__(self, name):
        if name[0].isupper():
            def wrapper(*args, **kwargs):
                if args and kwargs:
                    raise RuntimeError("cannot send both positional and named args")
                elif kwargs:
                    return self.rpc_call(name, kwargs)
                else:
                    return self.rpc_call(name, args)
            return wrapper
        else:
            raise AttributeError(name)
