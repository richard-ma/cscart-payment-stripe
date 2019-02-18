#!/bin/sh

usage() {
    echo "Usage: $0 (ADDON_NAME)"
}

# expected ADDON_NAME
if [ $# -ne 1 ]; then
    usage
    exit
fi

# get ADDON_NAME
ADDON_NAME=$1

# check ADDON_NAME directory
if [ -d $ADDON_NAME ]
then
    if [ -f $ADDON_NAME.tar.gz ]
    then
        rm $ADDON_NAME.tar.gz # remove exists file
    fi
    cd $ADDON_NAME
    tar -zcvf $ADDON_NAME.tar.gz * # add all files to tarball
    mv $ADDON_NAME.tar.gz ..
    cd ..
else
    usage
fi
