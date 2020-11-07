import asyncio
import pyinotify

#class EventHandler(pyinotify.ProcessEvent):
#class EventHandler:
#    def __call__(self, event):
#        print("Got event %r" % event)
#        return None
#
#on_event = EventHandler()

'''
loop = asyncio.get_event_loop()

async def periodic():
    while True:
        print("yay")
        await asyncio.sleep(5)

ptask = loop.create_task(periodic())

def on_event(event):
    print("Got event %r" % event)
    return None

watchmgr = pyinotify.WatchManager()
watchmgr.add_watch(UTMP_PATH, pyinotify.IN_MODIFY)
notifier = pyinotify.AsyncioNotifier(watchmgr, loop,
                                     default_proc_fun=on_event)

try:
    loop.run_forever()
except Exception as e:
    print("[got %r, shutting down]" % e)
    loop.stop()
'''
