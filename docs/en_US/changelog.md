# Changelog

## 0.3 — 13/09/2026

**Fixes**

- The Calendar tab stayed empty and "Last refresh" always showed a dash: the
  guard discarding the answer of a device left in the meantime compared an
  integer with a string, and therefore rejected every answer.
- "Address in use" was only recomputed when the device was loaded, so it stayed
  empty throughout the whole configuration — the one moment it is looked at. It
  now follows the locality, the street and the house number as they are entered.

## 0.2 — 13/09/2026

**Dashboard**

- Only one command is visible now: the "Next collection" tile. The others, meant
  for scenarios and graphs, were cluttering the device — up to twenty widgets
  piled into a few centimetres.
- The tile changes look as the collection approaches: orange date the day before
  with a "to take out tonight" reminder, red on the day itself.
- An optional `icons` parameter adds the waste pictogram inside each label.
- Labels wrap cleanly over several lines, follow the dark theme, and the text
  colour is computed when the service does not provide one: paper and cardboard
  no longer show as white on yellow.

**Refresh**

- The "Refresh" action command no longer forces a network read: a scenario
  calling it in a loop could emit thousands of calls a day to the service.
- After a failure, the plugin waits three hours before trying again, instead of
  hitting an already struggling service every hour.
- An answer with no collection at all no longer replaces the known calendar:
  that happens when the operator has not published next year yet.
- Saving a device only reads the calendar again if the address has changed.
- "Waste to take out tonight", empty six days out of seven, no longer wakes the
  scenarios listening to it every hour.

## 0.1 — 13/09/2026

First release.

**Calendar**

- A Belgian address (postal code, street, house number) is enough: the plugin
  fetches the collection calendar published by Recycle! and keeps it up to date
  on its own.
- Assisted search for the locality and the street, with a test button that
  checks the address and announces the next collection before saving.
- The operator serving the address is detected and displayed.

**Commands**

- Next collection, date, waste types, days left, collection today, collection
  tomorrow, waste to take out tonight.
- Optionally, a set of commands per waste type, for scenarios watching a single
  bin.
- A dedicated widget shows the date and one coloured label per waste type.
