#!/bin/sh

# Download and install the Sabre\Vobject library for this plugin

wget 'https://github.com/fruux/sabre-vobject/archive/2.1.0.tar.gz' -O sabre-vobject-2.1.0.tar.gz
tar xf sabre-vobject-2.1.0.tar.gz

mv sabre-vobject-2.1.0/lib/* .
rm -rf sabre-vobject-2.1.0

