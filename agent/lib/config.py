class ConfigSyntaxError(Exception):
    pass

class ConfigReader():
    def __init__(self, path):
        self.path = path
        self.data = {}
        self.reload()

    def reload(self):
        with open(self.path, "r") as fh:
            self.data = {}
            for i, line in enumerate(fh):
                line = line.rstrip("\r\n")
                if not line:
                    continue
                elif line.startswith("#"):
                    continue
                elif line.startswith(";"):
                    continue
                elif " = " not in line:
                    raise ConfigSyntaxError("%s:%d: no assignment in %r" % (self.path, i+1, line))
                else:
                    k, v = line.split(" = ", 1)
                    k = k.strip()
                    v = v.strip()
                    self.data[k] = v

    def get_str(self, key, default=None):
        return self.data.get(key, default)

    def get_bool(self, key, default=False):
        return bool(self.data.get(key, default) in {"true", "yes", True})

    def get_int(self, key, default=None):
        return int(self.data.get(key, default))

    def __contains__(self, key):
        return key in self.data
