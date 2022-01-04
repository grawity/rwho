# Copy of n.jsonrpc.client 2022-01-04
import itertools
import requests

#from n.jsonrpc.util import json_load, json_dump
# We don't need the binary-capable variants for now.
import json
json_load = json.loads
json_dump = json.dumps

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
        self.ua = requests.Session()
        if gss_service:
            import gssapi
            import requests_gssapi
            spnego = gssapi.Mechanism.from_sasl_name("SPNEGO")
            self.ua.auth = requests_gssapi.HTTPSPNEGOAuth(target_name=gss_service,
                                                          mech=spnego,
                                                          opportunistic_auth=True)
        self._callids = itertools.count()

    def call(self, method, params=None):
        if params is None:
            params = []
        callid = next(self._callids)
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
                    return self.call(name, kwargs)
                else:
                    return self.call(name, args)
            return wrapper
        else:
            raise AttributeError(name)
