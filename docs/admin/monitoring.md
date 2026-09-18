# Monitoring

The **Monitor** group in the admin panel is the day-to-day "is it working, and
what happened" surface. It sits alongside [Observability](observability.md),
which covers metrics, dashboards, and alerting for the same platform.

## System Status

**Monitor → System Status** is the admin panel's home page. It is a board of
health cards, one per component, with a badge in the sidebar that goes amber on
any degraded check and red when something is down.

Checks cover the application tier (scheduler, queue workers, disk), the data
tier (PostgreSQL, Valkey, HAProxy), telephony (Asterisk AMI and SIP transports,
SIP trunk registrations, rtpengine, the LiveKit SIP bridge, the agent worker),
mail (Haraka and outbound), the observability stack (Prometheus, Grafana, Loki,
Promtail, Alertmanager), and supporting services (Icecast, local Whisper, and
the admin tools).

Three things make the board readable rather than a wall of red:

**"Not deployed" is not a failure.** A component you never installed shows grey
and rolls up as healthy. An install that uses managed PostgreSQL and no local
Icecast should see a clean board, not two permanent alarms.

**A check can be acknowledged.** Something known-broken and already being dealt
with can be marked as such, so it stops drowning the signal from whatever
breaks next.

**There is an invariant check, not just a liveness check.** Outbound trunk
configuration is validated for internal consistency, which catches the class of
problem where every individual component is up and the arrangement between them
is still wrong.

The same data is available on the command line:

```
php artisan orbital:status
```

## Call Logs

**Monitor → Call Logs** is the platform-wide call record — every call across
every client, which is the view your clients never get.

Rows carry the time, direction, from and to numbers, status, and duration, and
filter by direction and by client. Where a recording exists, a **play** action
opens it in an inline player.

Calls handled by an AI agent also carry a transcript, written back by the agent
worker as the call runs. For an AI call that went wrong, the transcript is
usually a faster answer than the audio.

This is the surface for "the client says nobody answered at 4pm" — you can see
whether the call arrived, which queue it hit, and how it ended, across accounts
and in one place.

## Failed Inbound Mail

**Monitor → Failed Inbound Mail** lists inbound email the router could not
place on any client.

A message lands here when the recipient address carried no account number, or
an account number that matches nothing. Wrong addresses, mail to a client you
have since removed, and spam aimed at the domain all collect here.

Open a message and you can:

- **Assign it to a client** — which re-runs that client's routing rules and
  drops the thread into the right queue
- **Forward it** somewhere else
- **Discard it**

**Check this page on a schedule.** Nothing in the operator inbox reveals that
mail is failing to route — the operator sees a quiet queue, which looks exactly
like a quiet day. Unrouted mail is also an alerting rule, so the better answer
is to let [alerting](observability.md) tell you.

Inbound **text** messages behave differently on purpose: a text to a number no
client owns is dropped with a log line rather than held. An SMS to a number you
do not serve is almost always a wrong number or a spam blast, and persisting it
means holding a stranger's content with no client to own it. See
[Messaging](messaging.md).

## See also

- [Observability](observability.md) — metrics, dashboards, and alert delivery
- [Email Routing](email-routing.md) — why a message would fail to route
- [High Availability](high-availability.md) — the Failover console
