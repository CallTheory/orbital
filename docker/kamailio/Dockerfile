FROM kamailio/kamailio-ci:5.5.2-alpine

# Copy in the routing config + dispatcher backend list.
# The CI image ships every standard module pre-installed
# (dispatcher, dialog, nathelper, jsonrpcs, xhttp, tm, sl,
# rr, maxfwd, textops, siputils) so no compilation needed.
COPY kamailio.cfg /etc/kamailio/kamailio.cfg
COPY dispatcher.list /etc/kamailio/dispatcher.list

EXPOSE 5060/udp 5060/tcp 5061/tcp 8090/tcp
