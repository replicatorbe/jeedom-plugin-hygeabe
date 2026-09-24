# Changelog

## 0.6 — 24/09/2026

- Waste labels stay readable when the service provides no text colour: it is
  computed from the background, on the dashboard as in the **Calendar** tab,
  instead of defaulting to white.
- **Test the address** checks the same number of days as the calendar (the
  "Calendar horizon" setting) instead of a fixed sixty days, and says how many
  days it checked.
- A failing address, or one not filled in yet, no longer writes an error to the
  log every hour: while the same message is shown in the message centre, the
  repetition only goes to debug.

## 0.5 — 15/09/2026

**Reminders**

- A **Reminders** tab on every address: being warned a few hours before the
  collection no longer requires writing a scenario. A reminder says when, for
  which waste, and what it triggers — any Jeedom action command, notification,
  SMS, spoken message, lamp, or a core block.
- Several reminders per address, each with its own time and waste filter: "the
  day before at 7 pm for everything", "on the day at 6:30 am for bulky items".
- The title and the message accept tokens: `#dechets#`, `#collecte#`, `#jour#`,
  `#jours#`, `#adresse#`, `#equipement#`, `#intercommunale#`.
- A **Test** button plays the reminder right away on the next matching
  collection, and a **Next send** line under each reminder announces what it
  will send and when: a badly set reminder raises no error, it simply never
  fires, and this was the only way to notice before the day itself.
- Reminders are examined every five minutes, without ever querying the service:
  they work on the calendar already in memory. The one-network-read-a-day policy
  is unchanged.
- A missed reminder — box powered off — still fires up to two hours after the
  due time; beyond that it stays silent. Saving a reminder does not make it fire
  for a due time already gone.
- A failing action — deleted command, disabled device, notification plugin in
  error — is reported in the message centre and by the "Test" button, instead of
  vanishing into the core's silence.

## 0.4 — 13/09/2026

**Seasonal waste**

- Commands are now created for every waste type served at the address, and no
  longer only for those collected within the next two months. Christmas trees,
  bulky waste on demand and glass only showed up in the calendar once a year:
  their command appeared out of nowhere on the day, and the scenario could not
  be written in advance.
- Out of season the date stays empty and days left is `-1`.

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
