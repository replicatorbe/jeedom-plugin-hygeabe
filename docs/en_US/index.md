# Hygea plugin

This plugin fetches the waste collection calendar published by Recycle!
(recycleapp.be) and turns it into Jeedom commands. You enter an address once;
the plugin then knows when the truck comes and what has to be taken out.

It works for Hygea, but also for every other Belgian operator publishing on the
same service: the plugin reads the actual operator of the address and displays
it.

No dependency, no daemon, no account to create. The calendar is read once a day,
the commands are recomputed every hour.

## Installation

1. Plugins → Plugin management → Add → Github.
2. Fill in the repository (see the README), branch `master`.
3. Enable the plugin.

Nothing else has to be installed.

## Creating an address

Plugins → Organization → Hygea → **Add an address**.

| Field | Value |
|---|---|
| Name | anything you like, for example `Collections` |
| Postal code | type it, then click the magnifier |
| Locality | pick it from the list |
| Street | type the first letters, click the magnifier, pick it |
| Number | the house number, an integer |

Click **Test the address** before saving: the plugin checks that the street
belongs to the locality, shows which operator serves the address, and announces
the next collection.

### Why the house number matters

Some streets are collected in two rounds, odd and even side, or split into two
sections. The house number tells them apart.

> The service accepts any number without ever complaining, including a number
> that does not exist in the street. A wrong number therefore produces no error:
> it silently produces the neighbour's calendar. Check it.

### Options

| Option | Effect |
|---|---|
| Commands per waste type | creates, for each waste type found, its next collection date, the number of days left and a "tomorrow" boolean |
| Switchover hour | hour from which today's collection is considered done. `0` keeps it displayed all day; `9` moves the display to the next collection once the truck has gone |

## Available commands

| Command | Type | Description |
|---|---|---|
| Next collection | info / string | the date and the waste types, rendered by a dedicated widget |
| Summary | info / string | the same as plain text: `Thursday 17/09: Organic waste, PMD` |
| Next collection date | info / string | as `2026-09-17`, for computations |
| Next collection waste types | info / string | the waste types, comma separated |
| Days before next collection | info / numeric | `0` on the day itself, `1` the day before |
| Collection today | info / binary | |
| Collection tomorrow | info / binary | the command to watch to be reminded the evening before |
| Waste to take out tonight | info / string | tomorrow's waste types, empty if there is none |
| Operator | info / string | `HYGEA`, `TIBI`... the name returned by the service |
| Refresh | action | reads the calendar again right away |

With the "commands per waste type" option, each waste type adds:

| Command | Type |
|---|---|
| `<Waste> : date` | info / string |
| `<Waste> : days left` | info / numeric |
| `<Waste> : tomorrow` | info / binary |

## The widget

The "Next collection" command uses a widget shipped with the plugin: it shows
the date in large type and one label per waste type, using the official colours
of the service.

If you prefer the standard display, hide that command and make "Summary" visible
instead.

## Using it in a scenario

Being reminded the evening before, at 8 pm, only if there is something to take
out:

```
Trigger: schedule, 0 20 * * *
If: #[Home][Collections][Collection tomorrow]# == 1
Then: message::notification with
      "To take out tonight: " + #[Home][Collections][Waste to take out tonight]#
```

Announcing only the blue bag:

```
If: #[Home][Collections][PMD : tomorrow]# == 1
```

## Plugin configuration

| Setting | Role |
|---|---|
| Request timeout | seconds before giving up on a call. 10 by default |
| Calendar horizon | number of days requested on each read. 60 by default |
| Label language | language in which the service returns waste type names |

## Call frequency

The plugin reads the calendar **at most every 20 hours**, and recomputes its commands
every hour without touching the network. The terms of use of the service ask for
reasonable usage: avoid piling up manual refreshes or scenarios calling the
"Refresh" command.

When the service is unavailable, the last known calendar stays displayed and a
message appears in the message centre. Nothing is erased.

## Troubleshooting

Logs are under Analysis → Logs, log `hygeabe`.

| Symptom | Likely cause |
|---|---|
| "Incomplete address" | the locality or the street was not picked from the list: typing text is not enough, the service works with identifiers |
| "Address unknown to the service" | the chosen street does not belong to the chosen locality; search again after selecting the right locality |
| No collection although the address is valid | next year's calendar is not published yet, or the house number is wrong |
| The calendar stops at the end of December | normal: operators publish the following year at different dates |
| "The collection service is not answering" | outage or network cut; the plugin retries on the next cron |
