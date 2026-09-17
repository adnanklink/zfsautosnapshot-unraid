#!/bin/sh
if [ -x /usr/local/emhttp/plugins/zfs.snapsync/scripts/post-install.sh ]; then
  /usr/local/emhttp/plugins/zfs.snapsync/scripts/post-install.sh || true
fi
