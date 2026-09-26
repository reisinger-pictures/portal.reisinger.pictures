#!/usr/bin/env python3
# ===========================================================================
# range_busy_ports.py — meldet Ports, die nicht bindbar sind.
#
# Aufruf: python3 range_busy_ports.py START ENDE
# Ausgabe: kommagetrennte Liste der blockierten Ports, sonst eine leere
#          Zeile. Exit 0 in beiden Fällen — die Aufruferin entscheidet, was
#          ein belegter Port bedeutet.
#
# Warum ein eigenes Skript und kein `python3 - <<EOF` in der Bash-Funktion:
# ein eingebettetes Heredoc laesst sich weder sinnvoll ausgeben noch in CI
# protokollieren, und beim Debuggen sieht man nicht, was tatsaechlich laeuft.
#
# SO_REUSEADDR wird gesetzt, weil Docker es beim Publizieren der Ports auch
# setzt. Ohne das wuerde TIME_WAIT als "belegt" gemeldet und der Stack
# wuerde an einem Zustand scheitern, der den Start nicht verhindert.
# ===========================================================================
import socket
import sys


def main() -> int:
    if len(sys.argv) != 3:
        print(f"usage: {sys.argv[0]} START ENDE", file=sys.stderr)
        return 2
    try:
        start, end = int(sys.argv[1]), int(sys.argv[2])
    except ValueError:
        print("START und ENDE muessen ganze Zahlen sein", file=sys.stderr)
        return 2
    if not 1 <= start <= end <= 65535:
        print(f"ungueltiger Bereich {start}-{end}", file=sys.stderr)
        return 2

    busy: list[int] = []
    for port in range(start, end + 1):
        sock = socket.socket()
        sock.setsockopt(socket.SOL_SOCKET, socket.SO_REUSEADDR, 1)
        try:
            sock.bind(("127.0.0.1", port))
        except OSError:
            busy.append(port)
        finally:
            sock.close()

    print(",".join(str(p) for p in busy))
    return 0


if __name__ == "__main__":
    sys.exit(main())
