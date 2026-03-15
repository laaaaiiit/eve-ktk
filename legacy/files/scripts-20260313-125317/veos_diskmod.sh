#!/bin/sh
cd $1
rmmod nbd
modprobe nbd nbds_max=128 max_part=16
for i in $(seq 0 127)
do  fuser -s  /dev/nbd${i} && continue
        /opt/qemu-5.2.0/bin/qemu-nbd -c /dev/nbd${i} *.qcow2
        mkdir disk
        mount /dev/nbd${i}p1 disk && ( sleep 1 && cp startup-config disk/startup-config )
        if [ $? -ne 0 ] ; then
                umount disk
                mount /dev/nbd${i}p2 disk
                sleep 1
                cp startup-config disk/startup-config
                echo "DISABLE=True" > disk/zerotouch-config
        fi
        umount disk
        /opt/qemu/bin/qemu-nbd -d /dev/nbd${i}
        rm -fr disk
        break
done
