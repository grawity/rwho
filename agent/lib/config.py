import re

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
            section = ""
            for i, line in enumerate(fh):
                line = line.rstrip("\r\n")
                if not re.match(r"^[^;#]", line):
                    continue
                elif m := re.match(r"^\[(\S+)\]$", line):
                    section = m.group(1) + "."
                elif m := re.match(r"^(\S+)\s*=\s*(.*)$", line):
                    key, val = m.groups()
                    self.data[section + key] = val
                else:
                    raise ConfigSyntaxError("%s:%d: no assignment in %r" % (self.path, i+1, line))

    def merge(self, options):
        for line in options:
            if m := re.match(r"^(\S+)\s*=\s*(.*)$", line):
                key, val = m.groups()
                self.data[key] = val
            else:
                raise ConfigSyntaxError("argv: no assignment in %r" % (line,))

    def get_str(self, key, default=None):
        return self.data.get(key, default)

    def get_bool(self, key, default=False):
        return bool(self.data.get(key, default) in {"true", "yes", True})

    def get_int(self, key, default=None):
        return int(self.data.get(key, default))

    def __contains__(self, key):
        return key in self.data
