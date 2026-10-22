# Home Agent

[Deutsche Fassung](README.md)

This module gives your home an agent: an AI assistant that speaks the Model Context Protocol (MCP)
operates Symcon with it, whether spoken, typed or from a workflow of its own. It can ask "Is any window
still open?", say "Turn off the lights in the living room" or close a blind halfway and gets a short,
unambiguous answer. It only sees and switches the devices you release.

> **Status:** early preview. Querying, switching, setting brightness, colour temperature, colour, position,
> heating setpoint and target humidity, operating mode and fan speed of dehumidifiers and ventilation units,
> stopping blinds, consumption and power also behind meters and sub-meters, heat and performance factor of heat pumps, the history of measured values and power, timelines of states, starting scenes as well as playback
> and volume of media players work. The device list can be proposed from an Alexa instance. The Claude app can
> sign in with OAuth. The form shows what did not work, a protocol for troubleshooting can be switched on. So far
> the module has been tried on three systems.

## When do I need this?

- **You want to ask your home by voice, without Alexa or Google.** The module gives an AI assistant
  exactly the devices and states it needs for everyday questions.
- **An assistant should not see the whole system.** Here it only sees your release list and a few
  simple tools, not the administration of the system.
- **An assistant miscounts or mixes up devices.** The module counts by itself and knows rooms,
  device types and your own names.

## What you need

- **Symcon version 9.0 or later.** The module is an MCP server of its own and needs nothing else.
- **An assistant that speaks MCP.** Microphone, speech recognition and language model come with the
  assistant. The module gives it the devices and the tools to query and switch them.
- **A suitable client.** Any client can connect that speaks MCP over plain HTTP requests and sends a
  fixed token in the `Authorization` header. Claude Code, the MCP integration of Home
  Assistant and the Claude app have been tried. Home Assistant gets the token in the address, the Claude app
  signs in with [OAuth](#sign-in-with-oauth). Claude Desktop (through
  `mcp-remote`) and Open WebUI offer this as well according to their documentation. Clients that
  cannot connect are listed under [Limits](#limits).

## Getting started

An AI assistant can also do the setup and propose rooms and devices for you to approve. How that works and what
to do after an update is described in [Setting up and updating](EINRICHTUNG.en.md).

1. **Create the instance.** Install the module and create a **Home Agent** instance. The form is divided
   into expandable sections that remember whether they are open or closed.
2. **Create an access.** In the section **Endpoint and accesses**, create an access with a name in the
   list **Accesses**. The module creates the token itself on apply. To start with, tick **Query only**: the
   assistant can then ask but not switch anything.
3. **Create rooms (optional).** In the section **Rooms**, enter the rooms with further names and a floor,
   for example "Gast" with the alias "Gästezimmer". This pays off before the next step, because the
   proposal from Alexa then also recognises these rooms by their other names and knows their floor.
4. **Release devices.** With an Alexa instance it is quickest: at the bottom of the form press **Propose
   devices from Alexa**, tick the devices you want in the window and press **Release selected** (see
   [Taking over devices from Alexa](#taking-over-devices-from-alexa)). Without Alexa or for further
   devices, expand the section **Devices** and enter room, name and type for each device in the list; the
   dialog then asks for the matching variables (see [The release list](#the-release-list)).
5. **Check.** **Run self-test** reports missing actions, the recognised direction of each blind and the
   range of each thermostat. **Show state of all released devices** shows what the assistant sees.
6. **Connect the assistant.** Below the list of accesses, the section **Endpoint and accesses** says
   for each kind of assistant what to do, with the addresses of your system.

   | Client | Setup |
   |---|---|
   | Claude Code | `claude mcp add --transport http symcon-homeagent http://<address>:3777/hook/homeagent/<InstanceID> --header "Authorization: Bearer <token>"` |
   | Claude app, also on the phone | With Symcon Connect active, add the connector as described under [Sign-in with OAuth](#sign-in-with-oauth). Without sign-in it also works with **Token also in the address**: then use the address `https://<Connect address>/hook/homeagent/<InstanceID>/<token>` and choose "No sign-in" as authentication. The app reaches your system from the internet; the Connect address is shown in the Connect Control instance. |
   | Home Assistant | Tick **Token also in the address** for the access. Add the integration **Model Context Protocol** with the address that **Show address** returns for this access (`http://<address>:3777/hook/homeagent/<InstanceID>/<token>`), and select it for the conversation agent under "Control Home Assistant". Turn off "Prefer handling commands locally" in the voice pipeline, otherwise Home Assistant switches sentences it understands itself through its own devices. |
   | other clients | MCP over plain HTTP requests with `Authorization: Bearer <token>`; if the client cannot send a header, the token in the address. |

7. **First questions.** These sentences quickly show whether everything works:

   | Question | What should come back |
   |---|---|
   | "Is any window open in the house?" | the number of open windows with their rooms |
   | "Is any light still on?" | the lights that are on, with room and brightness |
   | "How warm is it in the living room?" | the measured value, for a thermostat also the setpoint |
   | "How much power are we using right now?" | with an energy balance the house power, otherwise the devices with power measurement |
   | "Turn off the light in the kitchen." | with "Query only" a refusal, otherwise the result for each light |

   The module states the numbers itself in the first line of every answer; the assistant does not count.

## Taking over devices from Alexa

If your devices are already set up in Symcon's Alexa instance, you do not need to enter them again.
Choose the Alexa instance at the bottom of the form and press **Propose devices from Alexa**. A window
shows the proposals with room, name, type and alias, each with a tick. Untick everything the assistant
should not see and press **Release selected**. **Tick all** and **Tick none** above the list set all
ticks at once. The ticked devices go into the list and are saved right away; unsaved changes in the form
are lost.

Lights, switches, blinds, thermostats and temperature sensors are taken over. A blind whose name starts
with "Fenster" becomes a window with a drive. The module recognises the room from the name and from the
path of the variable, first through the room list. If the list does not know it, the module takes a
category from the object tree whose name also appears in the Alexa name ("Küche links" under
`…\EG\Küche\…` goes into the room Küche); categories such as "EG" or "OG" do not count as a room
but become the floor of the new room. If the devices of a room lie under different floors, one room per
floor is created ("Flur EG", "Flur OG"), and all of them get the alias "Flur". If a room is wrong or missing, change it in the window in the column "Room". A room the room list
does not know yet is added to it on release. "Deckenlicht Bad" thus becomes
"Deckenlicht" in the room Bad, and the Alexa name stays as an alias. A temperature sensor becomes the
current temperature of the thermostat in the same room. Devices that are already released are not added
twice. Locks, televisions, colour lights, scenes and blinds that only know open, stop and close (profile
`~ShutterMoveStop` or `~ShutterMoveStep`) are not taken over; the message after the click counts them.

## Accesses

Each assistant gets an access of its own. You still maintain the devices only once, all accesses of
an instance see the same release list. The list shows name, query only, default room and, in the column
**Connection**, how the assistant is connected. "Token also in the address" is in the edit dialog of the
row. You only see the token itself where you need it: **Show token** for Claude Code and similar clients,
**Show address** for Home Assistant. **New token** in the dialog replaces it on apply; all assistants of this
access then need the new one.

| Column | Meaning |
|---|---|
| Name | Free text, best after whoever comes through the access (see below). It appears in the protocol, under "What did not work" and in the debug of the instance. |
| Token | The password of this access. An access without a token gets one of 64 characters on apply; one set by script needs at least 24. |
| Default room | The room the assistant is in. "Turn on the light" without a room then means this room, and with equal device names the device there wins. For the whole house the assistant says `room=all`. |
| Query only | This access may ask but not switch anything. It only sees the query tool, and the assistant is told that the access can only read. |
| Token also in the address | For clients that cannot send a login. The token is then appended to the address. |
| Tool list | Whether the assistant knows the current tool list: **current**, **outdated** or **not fetched yet**, with the time of the last fetch. The list changes with an update of the module and with your settings, for example a device of a new type, a new room or a new scene. If it says **outdated**, open a new chat in the Claude app; if that does not help, disconnect the connector and connect it again. |

Without an access, the instance shows status 104 and rejects all requests. A new access gets its token on Apply.

### Which access for whom

Name an access after whoever comes through it: "Claude-App", "Home Assistant", "Claude Code". The rights do not
belong in the name, the column **Query only** shows them.

If several people use the Claude app, each gets an access of their own with their name, for example "Claude-App Anna".
Then each has their own default room and rights, and the protocol and the list of pairings show who asked. If two
accounts pair with the same access, the list shows two identical rows and you can no longer tell which belongs to whom.

For Home Assistant one access is enough for the whole house. Everybody talks to Home Assistant, and Home Assistant asks
Home Agent with its one access; who spoke does not arrive here. A second access is only needed if you set up a separate
voice assistant in Home Assistant with other rights, for example "Home Assistant Kids' room" with **Query only**. Home
Agent then gets a second entry in Home Assistant.

Whoever sets up by script or with an AI assistant gets the addresses of an access with
`HAGT_GetAddresses($InstanceID, 'Claude-App')` (empty name for all accesses): in the home network, through
Symcon Connect and whether sign-in with OAuth works. The token only appears as `<token>` there, only the
form shows it.

## Sign-in with OAuth

The Claude app can sign in instead of carrying the token in the address. Symcon Connect has to be
active, because the app reaches your system from the internet.

1. Under **Endpoint and accesses**, the address for the connector is shown below **Connect the Claude app**.
   Without an active Connect a note is shown there instead.
2. In the Claude app, add a custom connector with this address under "Connectors" → "Add connector". Keep
   the preselection: sign in when connecting, and "Use Claude's published identity" as OAuth client.
3. Choose the access the assistant should use and click **Create pairing code**. The code has six
   digits and is valid for ten minutes.
4. Click "Connect" in the app. A page of your system opens. Enter the code there and click **Allow**.

The assistant gets the rights and the default room of this access. The page names it together with the
address it comes from. If it shows anything other than claude.ai, click **Deny**.

The paired assistants are listed below. **revoke** in its row disconnects one at once. If an
access gets a new token, all assistants paired through it are disconnected. A pairing that has not been
used for 90 days expires. If a response to the assistant gets lost, for example because Connect is not
getting through, the pairing stays. The Claude app creates a new pairing each time the connector is added again and
leaves the old one; the column **Last used** shows which one is still in use, the most recently used comes first.
The self-test shows the number of paired assistants; without Connect it says
that sign-in is not available.

## Rooms and floors

You create rooms in the list **Rooms**, optionally with further names and a floor. After that
"lounge" finds the living room, and "all lights upstairs" matches every room of that floor.

In the dialog of a device you choose the room from this list, or "-" for a device without a room. A new
room goes into the room list first; after applying it appears in the selection. Rooms that are missing
in the room list (set by a script, for example) are still found by their name. The form names them
above the device list, and so does the self-test.

## The release list

Each row is a device with its capabilities. In the edit dialog you choose the type, and the dialog
then shows only the variables that belong to this type.

| Column | Meaning |
|---|---|
| Room | A room from the room list or "-". In requests case and umlauts do not matter ("Küche" = "kueche"). |
| Name, aliases | The name and further names, separated by commas. A partial word is enough when searching. If a device is found through an alias, the answer shows it in brackets after the name. |
| Type | Light, switch, socket, appliance, blind, thermostat, media player, humidifier, ventilation/fan, window, door, presence, water leak, temperature, humidity, air quality, other measured value or meter. |
| State or measured value | The variable for on/off, open/closed or detected, for measured values the value itself. For a thermostat the current temperature. |
| Setpoint | Only for a thermostat: the variable with the target temperature. It needs an action. |
| Colour temperature | Only for a light, optional: the variable with the colour temperature in Kelvin or mired. It needs an action. |
| Colour | Only for a light, optional: the variable with the colour as an integer (RGB, for example profile `~HexColor`). It needs an action. |
| Current humidity, target humidity, mode, fan speed | For a humidifier or dehumidifier: the measured humidity, the target humidity the assistant may set (range and step from presentation or profile), and two selection variables whose entries it sets by name. For ventilation only mode and fan speed. The state is called "On/off" there. |
| Playback, volume, mute, title, artist | Only for a media player. These are the variables Symcon combines in the "Media Player" presentation: playback with one of the profiles `~Playback`, `~PlaybackNoStop`, `~PlaybackPreviousNext`, `~PlaybackPreviousNextNoStop` or `~PlayerStatus`, the volume (announced in percent of its range), mute as a boolean, title and artist as text. For a media player the state is called "On/off". |
| Brightness/position | For a light the brightness. It is announced together with a light that is on and can be set in percent. For a blind the position, for a window the drive. |
| Moving state | Only for blinds and windows with a drive, and only if such a variable exists: it reports whether the blind is moving, for example `WORKING` on Homematic or the run status on the Velux KLF200. The module then answers "moving" at once, even if the position only arrives at the end. |
| Stop | Only for blinds and windows with a drive, optional: a variable that stops the travel, that is a boolean with an action or a selection with the entry "Stop" (such as "Aktion" in the HomeAssistant module). Homematic and Velux KLF200 devices are stopped by the module even without it. |
| Power | Only for lights, switches, sockets, appliances, humidifiers and ventilation, optional: the current power, for example `POWER` of a Homematic metering channel. It follows the state in every answer, for example "on (26.1 W)". |
| Energy meter | Also optional: the energy counter, for example `ENERGY_COUNTER`. For consumption over periods it must be aggregated as a counter in the archive. If a device hangs on a metering socket, both rows may carry the same power and energy meter; consumption and power of a room or the house count each variable only once, and the self test points it out. |
| Heat meter | Appliance only, optional: the heat meter of a heat pump, aggregated as a counter in the archive. It never counts in the electricity consumption. |
| Heat output | Appliance only, optional: the current heat output, in W or kW. Together with the power of the appliance it gives the COP, and without a heat meter also the heat over periods; it must then be archived. |
| Measured by | For everything with power or an energy meter, optional: the meter the device or meter hangs on, for example the sub-distribution of the upper floor. The choice lists the meters of the saved list, so a new one appears after Apply. |
| Inverted | Tick this if `true` means "closed" or "off". Many window contacts report `true` for closed. For windows, doors and sensors with a number, anything above 0 otherwise counts as open or detected, with the tick only 0; this fits the Matter door state, for example (0 open, 1 closed). Further entries such as "ajar" appear in brackets in the answer. For blinds the module finds out by itself which value means "open"; the tick reverses that if open and closed are swapped. |
| Tilt window | Only for a window with a drive: tick this if the drive only tilts the window. The answer then says "tilted" and "50 % tilted" instead of "open" and "50 % open". |
| Query only | The assistant may announce the state but not switch or set the device. Meant for devices like the garage door or the heating in the children's room. |

Which variables a device needs depends on its type:

| Type | Variables |
|---|---|
| Light | Switching state, brightness or both. A dimmer without a separate switch only has the brightness. Optionally power, energy meter, colour temperature and colour. |
| Switch, socket | Switching state, optionally power and energy meter |
| Appliance | Switching state or power, optionally energy meter, heat meter and heat output. An appliance with power only, such as a heat pump, can be queried but not switched. |
| Blind | Position, optionally moving state and stop |
| Thermostat | Setpoint, optionally the current temperature, mode and fan speed (air conditioner), power and energy meter |
| Media player | On/off, playback or volume, optionally mute, title, artist, power and energy meter |
| Humidifier | On/off or target humidity, optionally current humidity, mode and fan speed |
| Ventilation/fan | On/off or fan speed, optionally mode |
| Window | Contact, drive or both, optionally moving state and stop for the drive |
| Door | Contact |
| Presence, water leak | Detector |
| Temperature, humidity, air quality, other measured value | Measured value |
| Meter | Energy meter, optionally power |

If a row lacks the variable of its type, or an entered variable is deleted, only this device is
disabled. The row turns red, form and log name it, all other devices stay reachable. Symcon also
names the instance as a reference when an entered variable is about to be deleted.

Only release what you really need by voice, about one temperature and one humidity sensor per room.
Every additional device makes the assistant's answers slower and less reliable.

## Scenes

A scene is a script of your system that the assistant may start on request, for example "movie night"
or "good night". In the list **Released scenes**, enter a name and the script for each scene, plus
aliases and a room if you like. The room is only needed if two scenes share a name.

## What the assistant can do

The assistant gets three tools: one for asking, one for switching and setting and one for scenes.

### Querying

The tool **get_state** filters by room, floor, type and name and can be limited to active devices (on, open,
presence detected). The first line of the answer always gives the count, for example
"1 of 4 checked devices active". Devices without on and off, such as blinds and measurements, are not
counted; a closing line gives their number and types. If nothing matches, it lists the known rooms and types so the
assistant can ask again. The last line gives date, time and weekday, for example "Now: 2026-10-11 12:19
(Sunday)", so the assistant can also answer "What time is it?" and work out "yesterday". The answers follow the language of your Symcon system (English or German).

A thermostat reports target and current temperature, for example "setpoint 21 °C (currently 20.6 °C)".
If the assistant asks for `type=temperature`, it also finds thermostats with a current temperature; you
do not need a separate temperature entry on the same variable. A setpoint outside its range is called a
special setpoint: some Homematic thermostats use 100 for "valve fully open".

Add an air conditioner as a thermostat together with mode and fan speed. Its line then reads like
"setpoint 24 °C (currently 20.0 °C), Aus, fan Auto", and the assistant sets both by name as with the
dehumidifier. "Turn off the air conditioner" sets the mode to off; to turn it on, choose a mode such as cool or
heat. Both variables need an action and a selection in the
presentation or the profile.

A dehumidifier reports "on (51 %, setpoint 55 %, Smart, fan Niedrig)" or "off" and counts as active while it runs. Ventilation reports the same way without humidity: "on (Nacht, fan niedrig)".

A media player reports for example "playing "Can't Get Enough" by Bad Company (volume 20 %)",
"paused (volume 37 %, muted)" or "off". It counts as active while it plays. With the HomeAssistant
module playback is only a command variable; enter its status text (`playing`, `paused`, `idle`, `off` …)
as on/off, and the module knows the state anyway. Without either only the title is shown, and the player
is not counted among the active devices.

With `period`, **get_state** reports consumption instead of the state: `today`, `yesterday`,
`this_week`, `last_week`, `this_month`, `last_month`, `this_year` or `last_year`. Instead of `period`, a
free range works with `from` and `to` (first and last day as YYYY-MM-DD, both included; without `to`
only that one day), for example "in January" with `from=2026-01-01` and `to=2026-01-31`. Whole years and
whole months come from the yearly and monthly values of the archive, everything else from the daily
values. With a time (YYYY-MM-DD HH:MM) it works by the hour, for example "today between 1 and 3 pm" with
`from=2026-10-12 13:00` and `to=2026-10-12 15:00`; then `to` is the end, without `to` it runs until now. The
module counts whole hours from the hourly values, at most 48 hours at a time. The first line gives
the total, for example "88.5 kWh consumed by 3 of 3 metered device(s)", followed by the consumption
per device. The values come from the aggregates of the archive; the current day, week and so on count
up to now. If an energy meter is not archived as a counter, its line says "no history archived" and
the self-test points this out. It also checks whether the archive matches the meter reading: a single wrong value going up and back counts as consumption in the archive, and the self-test then names the meter and the months, for example "the archive counts more than the meter reading rose (01/2024 +659.8 kWh)". Delete the wrong value in the archive and reaggregate the variable. If a meter resets, for example after a power failure, the archive keeps counting correctly; that is not reported. A counter in a unit other than Wh, kWh or MWh, such as a water or gas meter, is not an energy meter: its line says "not an energy meter (unit m³)" and it is not added. Without a unit, kWh is assumed. Devices without an energy meter are left out. The assistant only gets
the parameter if at least one released device has an energy meter or the energy balance is set up.

The module calculates the consumption of the whole house from the **energy balance**:
grid import + solar production + battery discharge − grid export − battery charge. The "Energy
balance" section of the form holds the list **Sources** for this, one row per grid connection, solar plant
and battery. The dialog of a row shows the fields of its type: import and export for the grid, the production
for a solar plant, discharge and charge for a battery, each as an energy meter and optionally as a power. Each
energy meter must be archived as a counter. Each variable may appear only once in the list, otherwise the balance is locked. Without solar and battery one grid row with the import is enough. If the assistant asks without room, floor,
type and name (with `room=all` for an access with a default room), the first line reads, for example, "House consumption 774.8 kWh (all devices,
period=last_month): grid import 122.7, solar 1356.8, battery discharge 285, grid export 658.7, battery
charge 330.9 kWh." Devices with their own energy meter are part of it and are not added: "Of this,
166.2 kWh by 9 of 10 metered device(s), 608.6 kWh not metered." If one of the counters has no history,
the answer says "House consumption unknown" and names the counter. Asked for a room or type, the
answer stays with the sum of the devices. With solar or a battery, a line with self-sufficiency
and self-consumption follows: "Self-sufficiency 84 %, self-consumed solar 51 %." For the whole house the
answer lists only the five largest consumers and sums up the rest ("5 more device(s) together
25.6 kWh."); asked for a room, all devices follow in the order of the list.

Several solar plants, batteries or grid connections each get a row of their own. The balance adds up the rows of a
type, also backwards from the archive, and the answer then names them, for example "solar … (of this Roof …,
Garage …)". In the chart of the house consumption "solar (used directly)" then stands per plant in shades of the
solar colour, split by its production in the section; nobody measures which plant feeds in or charges the battery. A
part of the balance (`part`) shows each source as a series of its own. If a row has an error, the whole balance is disabled. If the balance used to be in single fields, you
will find it as the rows "Grid", "Solar" and "Battery" in the list after the update; you may rename them.

Enter a sub-distribution, a wallbox or a flat meter as type **Meter**, with its energy meter and optionally its
power. A meter never counts in a device total, it has a line of its own. In the field "Measured by" you choose for
devices and meters the meter above them, as deep as needed, for example the terrarium on the children's room and the
children's room on the sub-distribution of the upper floor. The line of a meter then names what is measured below
it and the rest: "Haushalt 13.4 kWh, of this 2.1 kWh measured below it by 9 meter(s) or device(s), 11.3 kWh not
metered". For the whole house the top level counts, that is everything without "Measured by": "Of this, 13.4 kWh by
2 meter(s) and 0 device(s) at the top level, 0 kWh not metered." The same goes for the power with `period=now`; a
meter without power of its own counts there with what is measured below it. A reference to something that is not a
meter of the list, and meters that measure each other in a circle, disable the row. If more than 105 % of its own
value is measured below a meter in the last 30 days, the self test points it out. The chart of a meter shows what
hangs below it and the rest as "not metered"; with meters the chart of the house per device shows the top level. The
production of a solar plant
belongs in the energy balance, not in a meter.

If you enter the **electricity price** in the energy balance (an archived variable in ct/kWh or €/kWh, for a dynamic
tariff for example the current price from the Tibber module) and the feed-in tariff, the house consumption also names
the costs: "Costs 38.21 € for 122.7 kWh grid import (paid on average 31.1 ct/kWh, average price 35.4 ct/kWh). Feed-in
60.47 €. Without solar and battery it would have been 259.20 €." Each hour counts grid import times the hourly mean
price. These are working costs only, without base fee; against the supplier's bill they differ by a few percent,
because your own meter measures slightly differently and the archive only knows hourly means. In charts `costs`
shows the house consumption in euros, as stacked bars, as heatmap and with a comparison. A cumulative chart
(`cumulative`) adds up consumption or costs day by day and projects a running period to its end, for example "So far
10239.5 kWh, projected 13079.9 kWh for the whole period. Comparison by the same time 9066.1 kWh (+13 %)." The
projection follows the course of the same period in the year before, so the expensive winter counts properly; without
data from the year before it is linear with the mean so far. In euros the feed-in revenue is a green line next to it,
and the text names the balance ("Feed-in 786.59 €, net 491.01 €.").

With `part` the chart shows a single meter of the energy balance instead of the house consumption: `grid` (grid
import), `feed_in` (grid export), `pv`, `battery_charge` or `battery_discharge`. This works as bars, cumulative chart,
heatmap and with a comparison, with `costs` in euros for `grid` and as feed-in revenue for `feed_in`. So the assistant
answers for example "How much feed-in revenue have I earned this year, and where will I end up by the end of the
year?" with a chart.

For a heat pump (type appliance) you also enter the heat meter and, optionally, the heat output. Like the energy meter,
the heat meter must be archived as a counter, and it never counts in the electricity consumption. The line then gives the
heat and the performance factor for the period, that is heat divided by electricity, for example "Heizungsraum |
Wärmepumpe | appliance | 1397 kWh, heat 5161 kWh, performance factor 3.7". If several lines have a heat value, from a heat meter or a heat output,
for example heating and hot water, the performance factor of all of them stands above. With `period=now` and a heat output
the COP of the moment is added ("500 W, heat 4188 W, COP 8.4"). The performance factor is only as good as the meters. If
the energy meter only counts the compressor, like the consumption values in the controller of some heat pumps, the
circulation pumps and the heating element are missing and it comes out too good.

Without a heat meter the module calculates the heat from the hourly means of the archived heat output, for example "heat
93.7 kWh from heat output". If the appliance also has a power value, the electricity of the performance factor comes from it
as well, so both values cover the same hours. The line names them as a block of their own after the meter, for example
"6 kWh (meter); from power values: heat 93.7 kWh from 13.6 kWh electricity, performance factor 6.9". The consumption
stays the value of the meter in front, also in the totals. Some controllers only
estimate the heat and book their meters at midnight. On a Stiebel Eltron the heat meter showed 1.5 to 2.5 times the heat
output calculated from flow rate and temperature difference at part load. Leave the heat meter empty in such a case.

If heat output and power exist only once for heating and hot water, you have two options. One line for both gives the
performance factor over everything, that is the seasonal performance factor. For separate lines a small script on your
system splits both values by operating mode, for example by a "hot water mode" variable of the controller: in hot
water mode into "heat output hot water" and "power hot water", otherwise into the two variables for heating, the other
ones set to 0. All four must be archived.

With `period=now`, **get_state** reports the power right now in W, built like the consumption: the
current value of each device with power, plus the total. For the whole house the module calculates the
house power from the powers of the sources, for example "House power 1548 W (all
devices, period=now): grid import 27, solar 0, battery discharge 1521, grid export 0, battery charge
0 W." and "Of this, 750 W by 11 of 11 device(s) with power measurement, 798 W not metered." W, kW and
MW are converted; without a unit, W is assumed. If you only have one signed grid sensor, enter it in the
field "Grid import power" (negative means export); a signed battery sensor goes into "Battery discharge
power" (negative means charging). If a sensor reports the other way round, for example a battery that counts
positive while charging, tick "Reverse sign" in its row. The self-test then shows the current value of each
source and how it counts ("Energy balance: battery discharge power "Marstek" Battery Power reads 1112 W now and
counts as battery charge 1112 W, reversed by the checkbox").

For measured values (temperature, humidity, air quality, other measurements and the current
temperature of a thermostat) the same periods return the **history**: minimum, average and maximum per
hour, day or month, for example "How warm was it in the guest room over the last 30 days?". The first
line then reads "History of 1 measured value(s) (room=Gast, type=temperature, from=2026-09-10,
to=2026-10-09), per day: minimum / average / maximum.", followed by a line per measured value with the
lowest and highest value of the whole period and when it occurred ("minimum 20.6 °C (2026-09-30 05:40), maximum 23.4 °C
(2026-10-02 16:15)") and a line per interval such as "2026-09-28 22.2 / 22.6 / 23.1". Today and yesterday come hourly,
weeks and months daily, years monthly; with `from` and `to` up to two days hourly, up to 100 days daily,
beyond that monthly. The history applies when a measured-value type is asked for or when none of the
matches has a meter; for the whole house it stays with consumption. At most five measured values at a
time, otherwise the answer asks for a room or name. If a thermostat reads the same sensor as a
temperature device, the history comes only once. The measured value must be logged in the archive,
otherwise the line says "no history archived".

The **power over a period** is asked for with `type=power` together with `period` or `from`/`to`, for example "When
did the dryer draw the most power last month?". It covers all matches with a power value, meters included. Per
device a line gives the lowest and highest power with the time from the raw values in the archive ("minimum 20.8 W
(2026-10-08 22:40), maximum 336.9 W (2026-10-01 20:46)"); with a single device the table per hour, day or month
follows. Up to 20 devices at a time. Without room and name, and with an energy balance, the line gives the house
power from the meters of the balance with the lowest and highest hourly mean, plus grid import and grid
export with their peaks in kW. Hours in which the balance gives a house consumption below 0 have meters that do not
match; they are left out and the answer gives their number and the days with most of them. Minimum and maximum of
devices, grid import and grid export are marked as instantaneous values; no 15-minute mean can be above them. Peaks of several sensors cannot be added up to the second from the archive, so the
house uses the hourly mean. Without a period `type=power` gives the current power. The power must be logged in the
archive.

For devices with a state (on/off, open/closed, motion, a selection such as the operating mode of a battery)
the same periods return a **timeline**: how long each state lasted and, with up to twelve sections, when.
"When was the front door open today?" is answered with something like
"Flur | Haustür | door | open 12 min, closed 15 h 36 min" followed by "open 08:32, 09:17-09:18, …". If a
device also has a meter, consumption and times share one line ("0.2 kWh, on 3 h 31 min, off 12 h 18 min").
With more than five devices, more than twelve sections or more than two days only the totals with their count
are given. The state must be logged in the archive; a state without archive is left out and counted. There is
no timeline for the whole house.

The state query is also available for scripts:

```php
echo HAGT_QueryState($InstanceID, 'Küche', 'window', '', true);
```

### Switching and setting

**set_state** does everything that changes something. The assistant selects the devices as it does
when querying and adds a command or a percent value:

| Input | Effect |
|---|---|
| `on`, `off` | switches lights, switches, sockets, appliances, media players, dehumidifiers and ventilation on or off; a media player without power switch (such as one from the Sonos module) pauses on `off` ("music off") and resumes on `on` only with `type=media`, so "conservatory on" does not start the music |
| `open`, `close` | moves blinds and windows with a drive fully open or fully closed |
| `stop` | stops blinds and windows that are moving and ends playback of a media player |
| `play`, `pause` | resumes or pauses playback of a media player |
| `next`, `previous` | skips to the next or previous track of a media player |
| `volume` | sets the volume of a media player in percent, also together with `play`, `pause` or `stop` ("quiet music on") |
| `volume_change` | makes a media player louder or quieter by that many percentage points, 10 without a number |
| `mute` | mutes a media player (`true`) or unmutes it (`false`) |
| `percent` | sets the brightness of a light or how far a blind or window is open |
| `temperature` | sets the setpoint of a thermostat in °C |
| `humidity` | sets the target humidity of a humidifier or dehumidifier in percent |
| `mode`, `fan_speed` | sets mode or fan speed by name, for example "Continuous" or "High" |
| `color_temperature` | sets the colour temperature of a light in Kelvin, for example 2700 for warm white |
| `color` | sets the colour of a light: red, orange, yellow, green, turquoise, blue, purple, pink or white |

For lights 0 percent switches off, any other value switches on. A light that has only a brightness
entered and whose smallest level is above 0 (with Matter for example 1 to 254) cannot be switched off
this way; the answer then says so instead of sending the smallest level. Enter the switch state for
such lights as well. For blinds and windows the number says how far they are open: 0 is fully closed,
100 fully open. The answer names the same number: "open", "closed" or "30 %
open", for a tilt window "tilted" and "30 % tilted".

The tool only offers what your released devices can do. Without a blind and without a window with a
drive there is no `open` and `close`, without a dimmable light and without both no percent value. A
window with only a contact can only be queried; for "close all windows" the answer says how many such
windows were left out. In a query, windows the tool can move carry the note "with drive", for example
"tilted (with drive)". If windows with and without a drive are listed, a closing line gives their number, for
example "6 of 21 windows have a drive".

For a media player, play, pause or stop count as done when the playback state matches, next and previous
as soon as title or playback state change. A player that is already paused gets no command, the line says
"already paused". Some players report the new title only after a few seconds; then the answer says "sent,
but the device has not confirmed yet". The module reads which values the playback variable understands
from its profile; with an unknown profile it leaves the player alone and says so.

The module takes mode and fan speed by name. It compares with the caption and the value of each entry of
the variable, ignoring case, so "Hoch" and "high" both match. Unknown names are not sent; the answer then
lists the entries of the device. The tool description names the known entries.

`play` without any room, floor or name resumes the media players that were paused through the tool in the
last hour. So "music off" and later "go on" work without repeating the room. Without such a player the
tool asks for a room as usual.

The module calculates the volume in percent of the range given by the presentation or the profile of the
variable. For the Sonos module it runs from 0 to 100, for the HomeAssistant module from 0 to 1, for a
Denon receiver from -80 to 18 dB. There 50 % is -31 dB, and the query names both ("volume 64 % = -17.5 dB").
Values are sent in the step size of the variable, half dB for the Denon.

The module checks a temperature against the range of the setpoint, which it reads from the presentation
or the profile of the variable. Outside of it nothing is sent, and the answer names the range. Odd values
go to the step size, with steps of 0.5 21.3 becomes 21.5, and the answer names the value sent. If the profile has no
step size, the spacing of its entries counts, for example 15.0, 15.5, 16.0. Entries
such as "On" at the edge of a Homematic profile do not count as part of the range. The button **Run
self-test** shows the range for each thermostat.

The assistant gives the colour temperature in Kelvin. If the variable counts in mired (range up to 1000,
for example Hue via Matter), the module converts it. A value outside the range of the lamp goes to the
nearest end, and the answer names the value sent. A light that is off is switched on. With the light on,
the query names the colour temperature, for example "on (100 %, 2700 K)".

If a variable with the colour mode sits next to the colour or colour temperature (as with Zigbee2MQTT, the
HomeAssistant module and Matter), query and card name only what currently applies: the colour temperature
for white light, the colour for coloured light. Without that variable both are shown.

The assistant picks a colour from a fixed list of nine names. The module writes it as RGB into the
variable and switches a light that is off on. With the light on, the query names the nearest colour of the
list. A light without its own switch, such as an RGBW strip, counts as on as long as its colour is not black,
and "off" also sets the colour to black. A colour variable with text (presentation colour with an encoding, for example with the
HomeAssistant module) is not set yet; the answer says so.

A command for a group only reaches the devices that understand it. "Living room off" switches lights
and sockets and leaves the blinds alone; the end of the answer says how many devices were left out.
If the assistant names a single device with a command it does not understand, nothing changes and
the answer says what the device can do.

You do not need to know how a blind counts internally. Some devices report 0 for open, others 0 for
closed. The module reads it from the "blind" presentation of the variable or from its profile (a
profile name containing ".Reversed" counts the other way round). Blinds from the HomeAssistant module
count 100 as open, like Home Assistant. The button **Run self-test** shows for
every blind what was detected, for example "Blind Küche Rollladen: open at 1, closed at 0". If that
is wrong, tick "Reverse direction" for the device.

Four rules keep the wrong things from being switched:

- Without a room, floor, type or name nothing happens. There is no "everything off" without further
  detail, not even for an access with a default room.
- A name must match exactly one device. If several match, the module switches nothing and lists the
  candidates with their state so the assistant can ask a focused question. Groups go through room and type ("all lights in the living
  room").
- Windows without a drive, doors, sensors and measured values can only be queried, even if their
  variable has an action. The same holds for every device with the tick "Query only".
- A percent value for a whole room needs the type as soon as the room has lights together with blinds
  or windows with a drive.
  "Living room to 50 %" would otherwise set both at once.

After switching, the module waits up to two seconds for the devices to confirm. The answer says for
each device what happened: "on", "already on", "sent, but the device has not confirmed yet" or
"failed" with the reason. A device that never reports its state back (because it has no power, for
example) therefore shows up as not confirmed, and the first line counts it separately. If a command
fails only after the answer, the reason is in the message log of the instance.

Blinds take longer to travel. The module answers "moving" as soon as the blind has visibly started:
from its position, from its moving state, or because it reported back after the command (Homematic
reports when it starts, even while the position is still the old one). If it does not report at all,
for example a Velux blind on the KLF200 without a moving state, the answer reads "command sent, no
position report yet". A query during the travel names the target: "moving to closed (currently open)".

With `stop` the module halts a moving blind and reports where it stands: "stopped (68 % open)". It
stops through the stop variable you entered, otherwise through the module of the device (Homematic and
Velux KLF200); the self test names the way for each blind. `stop` is only offered if at least one blind
has such a way. If the moving state says the blind is already standing, nothing is sent and the answer
reads "was not moving". A Velux reports its position only about 2.5 s after the stop; the answer then
reads "stop sent, not confirmed yet", and a query shows the position.

For scripts, switching and setting are two functions:

```php
echo HAGT_SwitchDevices($InstanceID, 'Wohnzimmer', 'light', '', false);
echo HAGT_SetLevel($InstanceID, '', '', 'Deckenpanel', 30);
```

### Charts, device cards and templates in the Claude app

Asking the Claude app for a chart, for example "Show me the temperature in the guest room over the last 30 days
as a chart", makes the module draw the history right in the chat: one chart per unit, a band from minimum to
maximum and a line for the average. With several lines in one chart the band starts hidden, a checkbox below the
chart shows and hides it. The values only go to the chart, the language model gets minimum and maximum. With
`type=power` it draws the power the same way.
Several rooms can be compared in one chart, for example "Compare the temperature in the guest room and outside
last week". States such as on/off, open/closed or a battery mode appear as bands along the time axis, coloured by
state, for example with "Show me when the front door was open today". Two periods can be compared, for example
"house consumption today compared with yesterday" or "temperature in the guest room this month against the same
month last year": a dashed line for a history, a mark on each bar for consumption. While the period is still
running, the answer also gives the consumption of the comparison period up to the same time. Timelines have no
comparison. A scatter chart shows how two values relate, for example "heat pump power against outdoor temperature in
September": one point per day (per hour up to two days), the mean per temperature class and a trend line. If
consumption only rises below a limit, the module finds this heating limit itself and the assistant gets the relation
in words ("below about 16 °C about 0.06 kW more per degree colder, explains 84 %"). The y axis takes a measured
value, a meter or a power, without a device the house consumption. A heatmap shows when in the day something
happens, for example "house consumption in September as a heatmap": one cell per hour, one row per day, beyond 62
days one row per weekday with the mean. The assistant gets the strongest and the weakest hour and the difference
between weekdays and weekends.
Each chart shows its period above it, for example "Monday, 12 October 2026" or "September 2026". The tool is called `show_chart` and appears in the connector settings under "Interactive tools", where it can be
set to "Always allow". Other assistants do not see it.

With the energy balance filled in, the module also draws the house consumption on request, for example "Show me
our electricity consumption this month as a chart": one bar per hour, day or month made of grid, solar used
directly and battery, with the grid export below. Each bar adds up to the house consumption of the balance; when
the battery charges from the grid, that counts as grid. Above it are two rings: self-sufficiency splits the house
consumption into solar used directly, battery and grid, self-consumption splits the solar production into used
directly, charged into the battery and exported. "Show me the power right now as a chart" draws the same rings from
the power values of the balance, plus a ring with the house power from solar, battery and grid. Without a battery in
the balance the rings leave it out. With a comparison, for example "compared with last year", each ring shows the
share of the comparison period in its top right corner, for a running period up to the same time.

Consumption per device works the same way, for example "Which devices used the most in September? As a chart":
the five largest devices with a meter stacked, the rest as "other devices", and for the whole house also "not
metered" from the energy balance. If a room has measured values besides meters, the chart shows the measured
values first; ask for the consumption explicitly then. A meter that only counts once a day shows the whole day in
one hour of an hourly chart.

With "Show me the lights in the living room" the Claude app shows the devices as cards, grouped by kind. Each card
shows the state and, in large print, the main value (brightness, opening, reading or power); what is on is coloured.
Depending on the device there is a switch, a bar for brightness or opening that can be dragged, buttons for open, stop and close, the setpoint with plus
and minus, playback with volume, a selection for operating mode and fan speed (a few entries as buttons), and for lights a slider for the colour temperature and colour swatches. While a blind is still moving, the card follows it until it stops. A tap on a card switches at once, without a detour through the language model, and the
card then shows the state the device reported back; a message only appears when something did not work. The assistant learns in the further conversation what was operated
on the cards. With a **Query only** access the cards only show the state.

Under "+" → "Connectors" → "Add from …" the Claude app offers ready templates: **Leaving home** and **Good
night** check what is still open or on, **Energy report** names consumption, self-sufficiency and the largest
consumers of yesterday. Which ones exist depends on the release list.

After an update it can take a while until the Claude app knows the new tools. It is faster to add the connector
again.

### Starting scenes

**run_scene** starts a scene. The names of your scenes are part of the tool description, so the
assistant knows them from the start. The name must match exactly one scene. If it matches several or
none, the module starts nothing and lists the scenes. Without any scene entered, the module does not
offer the tool.

The answer confirms that Symcon has started the script. What it did shows in a query afterwards.

For scripts:

```php
echo HAGT_RunScene($InstanceID, '', 'Filmabend');
```

## What did not work

The section **What did not work** shows the requests of the assistants that did not reach everything,
newest first. The heading counts the entries of the last 24 hours. Each row names time, access, the request,
the first line of the answer and a hint what you can do:

| Case | Example | Hint |
|---|---|---|
| Room unknown | "light in the bathroom on", the room is called "Bath" | enter the word as an alias of the room |
| Name unknown | "floor lamp on", the device is called "reading lamp" | enter the word as an alias of the device |
| Several devices fit | "ceiling light on" without a room matches three ceiling lights | give distinct names |
| Device cannot do this | "open the light" | nothing to do |
| Device does not confirm | an LED strip without power | check whether the device is reachable |
| Action failed | the variable has no action | check device and action |
| Sign-in rejected | a client with an old token | check token or address in the client |

A blind that is still moving is not listed. The last 50 entries are kept, also across a restart; **Clear
list** removes them. Rejected sign-ins from the same sender within ten minutes are one entry with a count.

For troubleshooting there is a **protocol** below, switched off at first. With **Keep a protocol** the module
writes every request as a line into a file in the log directory of Symcon (`homeagent_<InstanceID>.log`):
time, access, tool, arguments, duration and answer. After 24 hours or after **Lines per file** (5000 at first)
a new file starts, the previous one stays as `homeagent_<InstanceID>.old.log`. So one to two days are
available. **Show protocol** shows the last 200 lines, **Download protocol** downloads both files as one (at most the last 3 MB of each),
**Clear protocol** deletes them. Tokens and pairing codes are never written. Rejected requests without sign-in are written at most ten times in ten minutes per sender, then a note.
## Limits

- Colour as text is not supported yet. The operating mode of a thermostat only works through a variable with an
  action; on Homematic wall thermostats `CONTROL_MODE` has none.
- A battery-powered thermostat often takes a new setpoint only at its next radio contact. The central
  unit still confirms it right away, so "set" then means "handed over to the central unit".
- For a blind that reports only on arrival and has no moving state, the module does not know after
  the command whether it is moving.
- The assistant does not see errors inside a scene script, it only learns that the script was started.
- The meter check of the self-test only finds wrong values that disappear again. If a meter jumps up for good, for
  example when it is replaced by one with a higher reading, the archive counts the jump as consumption without the
  self-test noticing. It does not check small wrong values below a fifth of a usual month or months shortly after a reset.
- The house power with `type=power` covers at most 400 days.
- Heat, performance factor and COP are only given as text, not in charts and not with `type=power`. If the controller of
  a heat pump only counts on once a day, the whole previous day lands in the archive shortly after midnight; "yesterday"
  then means the day before.
- The peak of the house power is an hourly mean from the meters of the balance. Short peaks, such as a device starting up, only show in the grid power or the single device.
- Slats whose variable carries the "blind" presentation with rotation only get a percentage without
  "open" or "closed".
- The endpoint speaks MCP over plain HTTP requests and offers no event stream. Clients that require
  an event stream (SSE) cannot connect directly. With OAuth only clients with a Client ID Metadata
  Document can sign in; the Claude app has been tried. ChatGPT connects with the token, OAuth has not
  been tried with it.
- Requests from a browser with a foreign origin (`Origin`) are rejected, even with the right token. The system's own names are its IP addresses, `localhost`, its host name (also with `.local`, `.fritz.box`, `.lan`, `.home.arpa`, `.home` or `.internal`) and the Connect address.
- Tokens and pairing codes are the only lock. Through Symcon Connect the endpoint is reachable from
  the internet, so use generated tokens. Rejected logins appear in the message log (the first one of a
  minute and then their number); wrong pairing codes count as well. After 20 rejected logins within one
  minute the endpoint is blocked for this sender for 60 seconds, even with the right token. Through Symcon
  Connect the sender on the internet counts, so your own assistants still get through. After 100 rejected
  logins within one minute, from whoever, the endpoint is blocked for everyone for 60 seconds.
- With the token in the address it can end up in logs, histories and settings files of the client.
  Set the tick only for accesses whose client cannot send the login in the header.
- The tokens are part of the instance configuration. Whoever may read the configuration of the system,
  for example a script or another module, reads them as well.
- The protocol for troubleshooting contains room and device names and the answers to the assistants. Whoever
  can read the log directory of Symcon reads it as well. Tokens and pairing codes are not in it.
- The script functions know neither default room nor floor. They exist for state, switching, setting
  and scenes, not for stop, setpoint, colour temperature, colour, consumption, power and history.

## Terms

- **MCP:** Model Context Protocol, the interface through which AI assistants use tools.
- **Token:** the password the assistant logs in with.
- **Access:** a token together with its settings (default room, query only).

## Technical appendix

- Endpoint `/hook/homeagent/<InstanceID>`, POST only with JSON-RPC 2.0, response
  `application/json`. GET is rejected with 405, notifications are acknowledged with 202.
- Login with `Authorization: Bearer <token>`, for accesses with the tick set also through the token
  as the last part of the path. If a client sends both, the header counts.
- Supported protocol versions: 2026-07-28 (without `initialize`, with `server/discover`; clients may keep the
  tool list for 5 minutes), plus 2025-11-25, 2025-06-18 and 2025-03-26.
- Status 104: no access yet, the endpoint rejects all requests. Status 201: the room list has
  errors, or there is no faultless access left; the red rows in the form name the error, and the endpoint
  stays closed until they are corrected. In both cases it answers with HTTP 503 ("not ready"), not
  with a login error. A faulty access (token too short or used twice, unknown default room), or a
  device or scene whose variable or script is missing, only disables itself; the rest keeps working,
  form and log name the entry. If a variable of the energy balance is missing, the whole balance is
  disabled, otherwise the house consumption would be wrong.
