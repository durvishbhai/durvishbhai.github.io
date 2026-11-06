#!/usr/bin/env python3
"""Minimal single-message SMTP sink for tests: python3 fake_smtp.py <port> <outfile>. No TLS, no auth."""
import socket, sys

port, out = int(sys.argv[1]), sys.argv[2]
srv = socket.socket()
srv.setsockopt(socket.SOL_SOCKET, socket.SO_REUSEADDR, 1)
srv.bind(("127.0.0.1", port))
srv.listen(1)
print("ready", flush=True)
conn, _ = srv.accept()
f = conn.makefile("rwb")
def send(line): f.write((line + "\r\n").encode()); f.flush()
send("220 fake ESMTP")
data_mode, buf, rcpt = False, [], []
while True:
    raw = f.readline()
    if not raw:
        break
    line = raw.decode(errors="replace").rstrip("\r\n")
    if data_mode:
        if line == ".":
            data_mode = False
            open(out, "w").write("RCPT:" + ",".join(rcpt) + "\n" + "\n".join(buf))
            send("250 queued")
        else:
            buf.append(line[1:] if line.startswith("..") else line)
        continue
    cmd = line.upper()
    if cmd.startswith("EHLO") or cmd.startswith("HELO"):
        send("250 fake")
    elif cmd.startswith("MAIL FROM"):
        send("250 ok")
    elif cmd.startswith("RCPT TO"):
        rcpt.append(line.split(":", 1)[1].strip()); send("250 ok")
    elif cmd == "DATA":
        data_mode = True; send("354 go")
    elif cmd == "QUIT":
        send("221 bye"); break
    else:
        send("250 ok")
conn.close()
