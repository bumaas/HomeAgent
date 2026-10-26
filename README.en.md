# Home Agent

[Deutsche Fassung](README.md)

This module gives your home an agent: an AI assistant that speaks the Model Context Protocol (MCP)
operates Symcon with it, whether spoken, typed or from a workflow of its own. It can ask "Is any window
still open?", say "Turn off the lights in the living room" or close a blind halfway and gets a short,
unambiguous answer. It only sees and switches the devices you release.

> **Status:** beta, tried on three systems and by the first testers. The assistant queries and switches. It sets
> brightness, colour, colour temperature, position, setpoints, operating mode and fan speed, stops blinds, starts
> scenes and operates media players. It knows consumption and power also behind meters and sub-meters, for the house
> also the costs, for heat pumps heat and performance factor. Histories of measured values and power and timelines of states come as text
> and, in the Claude app, as charts. The device list can be proposed from an Alexa instance.

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
  assistant. Claude Code, Home Assistant and the Claude app have been tried, Claude Desktop (through `mcp-remote`) and
  Open WebUI offer MCP as well according to their documentation. How they connect is step 7 of
  [Getting started](#getting-started), clients that cannot connect are listed under [Limits](#limits).

## Getting started

An AI assistant can also do the setup and propose rooms and devices for you to approve. How that works and what
to do after an update is described in [Setting up and updating](EINRICHTUNG.en.md).

1. **Create the instance.** Install the module and create a **Home Agent** instance.
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
5. **Energy balance and scenes (optional).** With meters for the grid, solar or a battery, enter the sources in
   the section **Energy balance**; the assistant then knows the consumption of the whole house (see
   [Energy balance and meters](#energy-balance-and-meters)). Scripts and flow scripts it may start go into the
   section **Scenes** (see [Scenes](#scenes)).
6. **Check.** **Run self-test** reports missing actions, the recognised direction of each blind and the
   range of each thermostat and says whether an energy balance is set up. **Show state of all released
   devices** shows what the assistant sees. Both appear in a window of their own.
7. **Connect the assistant.** Below the list of accesses, the section **Endpoint and accesses** says
   for each kind of assistant what to do, with the addresses of your system.

   | Client | Setup |
   |---|---|
   | Claude Code | `claude mcp add --transport http symcon-homeagent http://<address>:3777/hook/homeagent/<InstanceID> --header "Authorization: Bearer <token>"` |
   | Claude app, also on the phone | With Symcon Connect active, add the connector as described under [Sign-in with OAuth](#sign-in-with-oauth). Without sign-in it also works with **Token also in the address**: then use the address `https://<Connect address>/hook/homeagent/<InstanceID>/<token>` and choose "No sign-in" as authentication. The app reaches your system from the internet; the Connect address is shown in the Connect Control instance. |
   | Home Assistant | Tick **Token also in the address** for the access. Add the integration **Model Context Protocol** with the address that **Show address** returns for this access (`http://<address>:3777/hook/homeagent/<InstanceID>/<token>`), and select it for the conversation agent under "Control Home Assistant". Turn off "Prefer handling commands locally" in the voice pipeline, otherwise Home Assistant switches sentences it understands itself through its own devices. |
   | other clients | MCP over plain HTTP requests with `Authorization: Bearer <token>`; if the client cannot send a header, the token in the address. |

8. **First questions.** These sentences quickly show whether everything works:

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
with "Fenster" becomes a window with a drive. The module recognises the room from the name
and the path of the variable, first through the room list, otherwise through a category of the object tree;
categories such as "EG" or "OG" become the floor. If a room is wrong, change it in the window in the column "Room".
A new room is added to the room list on release. "Deckenlicht Bad" thus becomes
"Deckenlicht" in the room Bad, and the Alexa name stays as an alias. A temperature sensor becomes the
current temperature of the thermostat in the same room. Devices that are already released are not added
twice. Locks, televisions, colour lights, scenes and blinds that only know open, stop and close (profile
`~ShutterMoveStop` or `~ShutterMoveStep`) are not taken over; the message after the click counts them.

## Accesses

Each assistant gets an access of its own. You still maintain the devices only once, all accesses of
an instance see the same release list. The list has these columns:

| Column | Meaning |
|---|---|
| Name | Free text, best after whoever comes through the access (see below). It appears in the protocol, under "What did not work" and in the debug of the instance. |
| Query only | This access may ask but not switch anything. It only sees the query tool, and the assistant is told that the access can only read. |
| Default room | The room the assistant is in. "Turn on the light" without a room then means this room, and with equal device names the device there wins. For the whole house the assistant says `room=all`. If the room is neither in the room list nor the room of a device, it has no effect; the form names it under the accesses. |
| Connection | How the assistant signs in: "token in the header", "address with token" or, with sign-in by OAuth, "signed in:" with the paired assistants. |
| Tool list | Whether the assistant knows the current tool list: **current**, **outdated** or **not fetched yet**, with the time of the last fetch. The list changes with an update of the module and with your settings, for example a device of a new type, a new room or a new scene. If it says **outdated**, open a new chat in the Claude app; if that does not help, disconnect the connector and connect it again. |

The token is the password of the access. The module creates it on apply (64 characters), one set by script needs at
least 24. It only shows on request: **Show token** for Claude Code and similar clients, **Show address** for
Home Assistant. The dialog of a row also has the tick **Token also in the address** for clients that cannot send the
login in the header, and **New token**. It replaces the token on apply, all assistants of this access then need the
new one.

Without an access, the instance shows status 104 and rejects all requests.

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
form shows it. In the same way `HAGT_ExportConfiguration($InstanceID)` returns the whole configuration of the
instance for a backup, and `HAGT_AddAccess($InstanceID, 'Claude-App', false, false, '')` creates an access without
the script having to know the tokens of the others.

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

Charts are drawn by the tool `show_chart`. It appears in the connector settings under "Interactive tools", where it
can be set to "Always allow". Whether the app knows the new tools after an update of the module is shown by the
column **Tool list** of the [accesses](#accesses).

## Rooms and floors

You create rooms in the list **Rooms**, optionally with further names and a floor. After that
"lounge" finds the living room, and "all lights upstairs" matches every room of that floor.

In the dialog of a device you choose the room from this list, or "-" for a device without a room. A new
room goes into the room list first; after applying it appears in the selection. Rooms that are missing
in the room list (set by a script, for example) are still found by their name. The form names them
above the device list, and so does the self-test.

## The release list

Each row is a device with its capabilities. In the edit dialog you choose the type, and the dialog
then shows only the variables that belong to this type. The search field above the list filters by room,
name, alias, type and the names of the variables.

| Column | Meaning |
|---|---|
| Room | A room from the room list or "-". In requests case and umlauts do not matter ("Küche" = "kueche"). |
| Name, aliases | The name and further names, separated by commas. A partial word is enough when searching. If a device is found through an alias, the answer shows it in brackets after the name. |
| Type | Light, switch, socket, appliance, blind, thermostat, media player, humidifier, ventilation/fan, window, door, presence, water leak, temperature, humidity, air quality, other measured value or meter. |
| State or measured value | The variable for on/off, open/closed or detected, for measured values the value itself. For a thermostat the current temperature. |
| Setpoint | The variable with the target temperature. It needs an action. The module reads range and step size from the presentation or the profile; without a step size the spacing of the entries counts (for example 15.0, 15.5, 16.0). A value at the edge with a text of its own is not part of the range, whether the text comes from a profile association or from a presentation interval with a fixed value, for example "On" at 30.5. The self-test shows the range for each thermostat. |
| Valve position, 2, 3 | Optional: up to three valves of the radiators, side by side in the dialog. They count in percent of the range of the variable. On Homematic IP the position is in `LEVEL`, not in `VALVE_STATE` (that is the status of the valve adaption); an HM-CC-VD actuator is a device of its own next to the HM-CC-TC. |
| Colour temperature | Optional: the variable with the colour temperature in Kelvin or mired. It needs an action. |
| Colour | Optional: the variable with the colour as an integer (RGB, for example profile `~HexColor`). It needs an action. |
| Current humidity, target humidity, mode, fan speed | For a humidifier or dehumidifier: the measured humidity, the target humidity the assistant may set (range and step from presentation or profile), and two selection variables whose entries it sets by name. For ventilation only mode and fan speed. The state is called "On/off" there. |
| Playback, volume, mute, title, artist | The variables Symcon combines in the "Media Player" presentation: playback with one of the profiles `~Playback`, `~PlaybackNoStop`, `~PlaybackPreviousNext`, `~PlaybackPreviousNextNoStop` or `~PlayerStatus`, the volume (announced in percent of its range), mute as a boolean, title and artist as text. For a media player the state is called "On/off". |
| Brightness/position | For a light the brightness. It is announced together with a light that is on and can be set in percent. For a blind the position, for a window the drive. |
| Moving state | Optional: a variable that reports whether the blind is moving, for example `WORKING` on Homematic or the run status on the Velux KLF200. The module then answers "moving" at once, even if the position only arrives at the end. |
| Stop | Optional: a variable that stops the travel, that is a boolean with an action or a selection with the entry "Stop" (such as "Aktion" in the HomeAssistant module). Homematic and Velux KLF200 devices are stopped by the module even without it. |
| Power | Optional: the current power, for example `POWER` of a Homematic metering channel. It follows the state in every answer, for example "on (26.1 W)". |
| Energy meter | Also optional: the energy counter, for example `ENERGY_COUNTER`. For consumption over periods the archive must log it with the aggregation "counter" (see [What must be archived](#what-must-be-archived)). If a device hangs on a metering socket, both rows may carry the same power and energy meter; consumption and power of a room or the house count each variable only once, and the self test points it out. |
| Heat meter | Optional: the heat meter of a heat pump, logged in the archive with the aggregation "counter". It never counts in the electricity consumption. |
| Heat output | Optional: the current heat output, in W or kW. Together with the power of the appliance it gives the COP, and without a heat meter also the heat over periods; it must then be archived. |
| Measured by | For everything with power or an energy meter, optional: the meter the device or meter hangs on, for example the sub-distribution of the upper floor. The choice lists the meters of the saved list, so a new one appears after Apply. |
| Inverted | Tick this if `true` means "closed" or "off". Many window contacts report `true` for closed. For windows, doors and sensors with a number, anything above 0 otherwise counts as open or detected, with the tick only 0; this fits the Matter door state, for example (0 open, 1 closed). Further entries such as "ajar" appear in brackets in the answer. For blinds the module finds out by itself which value means "open": from the "blind" presentation, from the profile (a profile name containing ".Reversed" counts the other way round), for the HomeAssistant module 100 as open, like Home Assistant. The self-test shows it for every blind, for example "Blind Küche Rollladen: open at 1, closed at 0". If that is wrong, the tick, here "Reverse direction", turns it round. |
| Tilt window | Only for a window with a drive: tick this if the drive only tilts the window. The answer then says "tilted" and "50 % tilted" instead of "open" and "50 % open". |
| Query only | The assistant may announce the state but not switch or set the device. Meant for devices like the garage door or the heating in the children's room. |

Which variables a device needs depends on its type:

| Type | Variables |
|---|---|
| Light | Switching state, brightness or both. A dimmer without a separate switch only has the brightness. Optionally power, energy meter, colour temperature and colour. |
| Switch, socket | Switching state, optionally power and energy meter |
| Appliance | Switching state or power, optionally energy meter, heat meter and heat output. An appliance with power only, such as a heat pump, can be queried but not switched. |
| Blind | Position, optionally moving state and stop |
| Thermostat | Setpoint, optionally the current temperature, up to three valves, mode and fan speed (air conditioner), power and energy meter |
| Media player | On/off, playback or volume, optionally mute, title, artist, power and energy meter |
| Humidifier | On/off or target humidity, optionally current humidity, mode and fan speed |
| Ventilation/fan | On/off or fan speed, optionally mode |
| Window | Contact, drive or both, optionally moving state and stop for the drive |
| Door | Contact |
| Presence, water leak | Detector |
| Temperature, humidity, air quality, other measured value | Measured value |
| Meter | Energy meter, optionally power |

There is no type of its own for smoke detectors. Enter them as other measured value, the assistant cannot switch
a measured value anyway.

Some devices need more than the variable of their type:

- **Light with brightness only:** If the smallest level is above 0 (with Matter for example 1 to 254), the light
  cannot be switched off through the brightness. Enter the switch state as well.
- **Air conditioner:** Add it as a thermostat together with mode and fan speed. Both variables need an action and a
  selection in the presentation or the profile.
- **Media players of the HomeAssistant module:** There playback is only a command variable. Enter its status text
  (`playing`, `paused`, `idle`, `off` …) as on/off, and the module knows the state anyway. Without either only the
  title is shown, and the player is not counted among the active devices.

If a row lacks the variable of its type, or an entered variable is deleted, only this device is
disabled. The row turns red, form and log name it, all other devices stay reachable. Symcon also
names the instance as a reference when an entered variable is about to be deleted.

Only release what you really need by voice, about one temperature and one humidity sensor per room.
Every additional device makes the assistant's answers slower and less reliable.

## Energy balance and meters

With solar or a battery, no single meter measures what the house consumes. The module therefore calculates it from the
**energy balance**:

> House consumption = grid import + solar production + battery discharge − grid export − battery charge

The values come from the **sources**. A source is anything through which electricity enters or leaves the house: a
grid connection, a solar plant or a battery. Each source is a row of the list **Sources** in the "Energy balance"
section of the form. The dialog of a row shows the fields of its type:

| Type | Energy meter | Power (optional) |
|---|---|---|
| Grid | import and export | import and export, or one signed sensor |
| Solar plant | production | production |
| Battery | discharge and charge | discharge and charge, or one signed sensor |

Without solar and battery one grid row with the import is enough. Several solar plants, batteries or grid connections
each get a row of their own. The balance adds up the rows of a type, also backwards from the archive, and the answers
name them. Each variable may appear only once in the list. If a row has an error, such as a missing variable, the whole
balance is disabled, otherwise the house consumption would be wrong. If the balance used to be in single fields before
an update, you will find it as the rows "Grid", "Solar" and "Battery" in the list; you may rename them.

The meter at the grid connection belongs only here, not also as a device of type meter. It measures the grid import,
and with solar or a battery that is less than the house uses.

An instance has exactly one energy balance and one electricity price. For a second site with its own balance or
tariff, create a second instance.

### Sign of the power

If you only have one signed sensor for the grid, it goes into the field "Grid import power"; negative then means
export. A signed battery sensor likewise goes into "Battery discharge power", negative means charging. If a sensor
counts the other way round, for example a battery that reports positive while charging, tick "Reverse sign" in its row.
The self-test shows the current value of each source and how it counts, for example "Energy balance: battery discharge
power "Marstek" Battery Power reads 1112 W now and counts as battery charge 1112 W, reversed by the checkbox".

### Meters and "Measured by"

Enter a sub-distribution, a wallbox or a flat meter in the release list as a device of type **Meter**, with its energy
meter and optionally its power. A meter never counts in a device total, it has a line of its own. The production of a
solar plant belongs in the energy balance, not in a meter.

In the field **Measured by** you choose for devices and meters the meter above them, as deep as needed, for example the
terrarium on the children's room and the children's room on the sub-distribution of the upper floor. The answers then
name for each meter what is measured below it and what is not metered. For the whole house the top level counts, that
is everything without "Measured by". A reference to something that is not a meter of the list, and meters that measure
each other in a circle, disable the row. If more than 105 % of its own value is measured below a meter in the last 30
days, the self-test points it out.

### Electricity price

If you enter the **electricity price** in the energy balance, the answers about the house consumption also name the
costs. The price is an archived variable in ct/kWh or €/kWh, "ct" alone is enough as the suffix of the presentation
or profile. For a dynamic tariff this is for example the current price from the Tibber module. Next to it you enter
the feed-in tariff in ct/kWh. Each hour counts grid
import times the hourly mean price. These are working costs only, without base fee. Against the supplier's bill they
differ by a few percent, because your own meter measures slightly differently and the archive only knows hourly means.

### Heat pump

Enter a heat pump as type appliance, with its power or its energy meter and optionally the heat meter and the heat
output. The heat meter never counts in the electricity consumption. Without a heat meter the module calculates the heat
from the hourly means of the archived heat output.

The performance factor is only as good as the meters. If the energy meter only counts the compressor, like the
consumption values in the controller of some heat pumps, the circulation pumps and the heating element are missing and
it comes out too good. Some controllers only estimate the heat and book their meters at midnight. Leave the heat meter
empty then, the module calculates the heat from the heat output.

If heat output and power exist only once for heating and hot water, you have two options. One line for both gives the
performance factor over everything, that is the seasonal performance factor. For separate lines a small script on your
system splits both values by operating mode, for example by a "hot water mode" variable of the controller: in hot water
mode into "heat output hot water" and "power hot water", otherwise into the two variables for heating, the other ones
set to 0. All four must be archived.

### What must be archived

Answers about periods come from the Symcon archive. If something is missing there, the line says "no history
archived", and the self-test names the variable with its ID.

| For the assistant to … | the archive must … |
|---|---|
| give consumption, per device or for the house | log the energy meter with the aggregation "counter", as well as each meter of the sources and a heat meter |
| give costs | also log the electricity price |
| give the heat without a heat meter | log the heat output |
| give the power over a period | log the power |
| show a history of measured values | log the measured value |
| show a timeline | log the state |

An energy meter counts in mWh, Wh, kWh or MWh, without a unit kWh is assumed. A counter in another unit, such as a
water or gas meter, is not an energy meter: its line says "not an energy meter (unit m³)" and it is not added. Power in
mW, W, kW and MW is converted (Matter reports mW), without a unit W is assumed.

### Meter readings in the self-test

The self-test also checks whether the archive matches the meter reading. A single wrong value going up and back counts
as consumption in the archive. The self-test then names the meter with its ID and the months, for example "the archive
counts more than the meter reading rose (01/2024 +659.8 kWh)". Delete the wrong value in the archive and reaggregate
the variable. If a meter resets, for example after a power failure, the archive keeps counting correctly; that is not
reported.

## Scenes

A scene is a script or flow script of your system that the assistant may start on request, for example "movie night"
or "good night". In the list **Released scenes**, enter a name and the script or flow script for each scene, plus
aliases and a room if you like. The room is only needed if two scenes share a name. A flow script without an action
cannot be started.

The names are part of the description of the tool **run_scene**, so the assistant knows them from the start. The name
must match exactly one scene, otherwise nothing starts and the answer lists the scenes. The answer confirms the start;
what the scene did shows in a query afterwards. Without any scene entered there is no such tool. For scripts:
`HAGT_RunScene($InstanceID, '', 'Filmabend')`.

## What the assistant can do

The assistant gets three tools: one for asking (**get_state**), one for switching and setting (**set_state**) and one
for scenes (**run_scene**). The following sections start from questions as you would ask them in everyday life. What
the assistant sends to the tools for them is listed in the [technical appendix](#tool-arguments).

### "Is a window still open anywhere?"

The assistant asks for states by room, floor, type or name, if wanted only for what is active (on, open, presence
detected). The first line of the answer always gives the count, for example "1 of 4 checked devices active"; the
assistant does not have to count. Devices without on and off, such as blinds and measurements, are not counted, a
closing line gives their number and types. If nothing matches, the module lists the known rooms and types, and the
assistant can ask again. The last line gives date, time and weekday, for example "Now: 2026-10-11 12:19 (Sunday)". So
the assistant can also answer "What time is it?" and work out "yesterday". The answers follow the language of your
Symcon system (English or German).

Each device gets a line with its state, for example:

| Type | State in the answer |
|---|---|
| Light | "on (100 %, 2700 K)" |
| Appliance with power | "on (26.1 W)" |
| Window with drive | "tilted (with drive)" |
| Thermostat | "setpoint 21.5 °C (currently 20.4 °C, valve 97 %)" |
| Air conditioner | "setpoint 24 °C (currently 20.0 °C), Aus, fan Auto" |
| Dehumidifier | "on (51 %, setpoint 55 %, Smart, fan Niedrig)" |
| Ventilation | "on (Nacht, fan niedrig)" |
| Media player | "playing "Can't Get Enough" by Bad Company (volume 20 %)", "paused (volume 37 %, muted)" |

Some types need a few more words:

- **Thermostat:** If the assistant asks for temperatures, it also finds thermostats with a current temperature; you do
  not need a separate temperature entry on the same variable. A setpoint outside the range or at its edge with a text
  of its own is called a special setpoint; some Homematic thermostats use 100 for "valve fully open". A text then
  appears in the line, for example "special setpoint “On” (30.5 °C, outside 6 to 30 °C)".
- **Valves:** With valve positions the line names the most open valve, "valves up to 98 %" with several. For "Which
  radiators are heating?" a thermostat counts as active while a valve is above 0. On a special setpoint it never
  counts, because "valve fully open" keeps the valve open whether the room needs heat or not. The device card shows
  the position next to the setpoint.
- **Air conditioner:** The assistant sets mode and fan speed by name as with the dehumidifier. "Turn off the air
  conditioner" sets the mode to off; to turn it on, choose a mode such as cool or heat.
- **Dehumidifier, ventilation, media player:** They count as active while they run or play.

### "How much electricity did we use last month?"

Consumption is available per device, room or type and for the whole house: for today, yesterday, this and last week,
this and last month, this and last year or a free period, by the hour if wanted ("today between 1 and 3 pm"). Running
periods count up to now. A device needs an energy meter for this, the house the [energy
balance](#energy-balance-and-meters).

Asked for a room or type, the first line gives the total of the devices, for example "88.5 kWh consumed by 3 of 3
metered device(s)", followed by each device in the order of the list. Devices without an energy meter are left out.

For the whole house the consumption comes from the energy balance, with its parts:

> House consumption 774.8 kWh (all devices, period=last_month): grid import 122.7, solar 1356.8, battery discharge
> 285, grid export 658.7, battery charge 330.9 kWh.
>
> Of this, 166.2 kWh by 9 of 10 metered device(s), 608.6 kWh not metered.
>
> Self-sufficiency 84 %, self-consumed solar 51 %.

Devices with their own energy meter are part of the house consumption and are not added. For the house the answer
lists only the five largest consumers and sums up the rest ("5 more device(s) together 25.6 kWh."). Several plants of
one type are named, for example "solar … (of this Roof …, Garage …)". Self-sufficiency and self-consumption only exist
with solar or a battery. If a counter of the balance has no history, the answer says "House consumption unknown" and
names the counter.

With devices of type meter, each meter line names what is measured below it and the rest: "Haushalt 13.4 kWh, of this
2.1 kWh measured below it by 9 meter(s) or device(s), 11.3 kWh not metered". For the house the top level counts then:
"Of this, 13.4 kWh by 2 meter(s) and 0 device(s) at the top level, 0 kWh not metered."

For a heat pump the line also gives the heat and the performance factor, that is heat divided by electricity, for
example "Heizungsraum | Wärmepumpe | appliance | 1397 kWh, heat 5161 kWh, performance factor 3.7". If several lines
have a heat value, for example heating and hot water, the performance factor of all of them stands above. If the heat
comes from the heat output and the appliance has a power value, the module also takes the electricity of the
performance factor from it, for the same hours, and names both as a block of their own after the meter: "6 kWh
(meter); from power values: heat 93.7 kWh from 13.6 kWh electricity, performance factor 6.9". The consumption in front
and in the totals stays the value of the meter.

### "What did the electricity cost us?"

With the [electricity price](#electricity-price) entered, the house consumption also names the costs:

> Costs 38.21 € for 122.7 kWh grid import (paid on average 31.1 ct/kWh, average price 35.4 ct/kWh). Feed-in 60.47 €.
> Without solar and battery it would have been 259.20 €.

Costs exist only for the whole house. In the Claude app they also come as a chart, for example as a cumulative chart
projected to the end of the year (see [charts](#charts-device-cards-and-templates-in-the-claude-app)).

### "What is drawing power right now?"

Asked for the power right now, each device with power measurement gives its value, plus the total. For the whole house
the module calculates the house power from the powers of the sources:

> House power 1548 W (all devices, period=now): grid import 27, solar 0, battery discharge 1521, grid export 0, battery
> charge 0 W.
>
> Of this, 750 W by 11 of 11 device(s) with power measurement, 798 W not metered.

A meter without power of its own counts there with what is measured below it. For a heat pump with heat output the COP
of the moment is added ("500 W, heat 4188 W, COP 8.4").

The power over a period can be asked for as well, for example "When did the dryer draw the most power last month?". It
covers all matches with a power value, meters included. Per device a line gives the lowest and highest power with the
time from the raw values in the archive ("minimum 20.8 W (2026-10-08 22:40), maximum 336.9 W (2026-10-01 20:46)"); with
a single device a table per hour, day or month follows. These are instantaneous values and marked as such; no
15-minute mean can be above them.

For the whole house the answer gives the house power from the meters of the balance with the lowest and highest hourly
mean, plus grid import and grid export with their peaks in kW. Peaks of several sensors cannot be added up to the
second from the archive, so the house uses the hourly mean. If the balance gives a house consumption below 0 in an
hour, the meters do not match. Such hours are left out, and the answer gives their number and the days with most of
them.

### "How warm was it in the guest room last week?"

For measured values (temperature, humidity, air quality, other measurements and the current temperature of a
thermostat) the same periods return the **history**: minimum, average and maximum per hour, day or month. The first
line reads for example "History of 1 measured value(s) (room=Gast, type=temperature, from=2026-09-10, to=2026-10-09),
per day: minimum / average / maximum." Below it each measured value gets the lowest and highest value of the whole
period with its time ("minimum 20.6 °C (2026-09-30 05:40), maximum 23.4 °C (2026-10-02 16:15)") and a line per
interval such as "2026-09-28 22.2 / 22.6 / 23.1".

The history applies when a measured-value type is asked for or when none of the matches has a meter; for the whole
house it stays with consumption. If a thermostat reads the same sensor as a temperature device, the history comes only
once.

### "When was the front door open today?"

For devices with a state (on/off, open/closed, motion, a selection such as the operating mode of a battery) the same
periods return a **timeline**: how long each state lasted and, with few sections, when. The answer then reads for
example "Flur | Haustür | door | open 12 min, closed 15 h 36 min" followed by "open 08:32, 09:17-09:18, …". If a device
also has a meter, consumption and times share one line ("0.2 kWh, on 3 h 31 min, off 12 h 18 min"). A state without
archive is left out and counted. There is no timeline for the whole house.

### For scripts

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
| `temperature` | sets the setpoint of a thermostat in °C; `on` and `off` set a thermostat without mode to its special setpoint "On" or "Off" if it has one, but only with `type=thermostat` or its name |
| `humidity` | sets the target humidity of a humidifier or dehumidifier in percent |
| `mode`, `fan_speed` | sets mode or fan speed by name, for example "Continuous" or "High" |
| `color_temperature` | sets the colour temperature of a light in Kelvin, for example 2700 for warm white |
| `color` | sets the colour of a light: red, orange, yellow, green, turquoise, blue, purple, pink or white |

For lights 0 percent switches off, any other value switches on. A light that has only a brightness
entered and whose smallest level is above 0 (with Matter for example 1 to 254) cannot be switched off
this way; the answer then says so instead of sending the smallest level (see
[The release list](#the-release-list)). For blinds and windows the number says how far they are open: 0 is fully closed,
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

The module calculates the volume in percent of the range of the variable and sends it in its step size. If it counts
in another unit, the query names both, for example "volume 64 % = -17.5 dB".

The module checks a temperature against the [range of the setpoint](#the-release-list). Outside of it nothing is
sent, and the answer names the range and the special setpoints that `on` and `off` set. The module takes their value
from the entry with the text "On" or "Off", so 100 for an older Homematic thermostat and 30.5 for a wall thermostat.
Odd values go to the step size, with steps of 0.5 21.3 becomes 21.5, and the
answer names the value sent.

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

Blinds take longer to travel. The module answers "moving" as soon as the blind has visibly started: from its
position, from its moving state, or because it reported back after the command. If it does not report at all, the
answer reads "command sent, no position report yet". A query during the travel names the target: "moving to closed
(currently open)".

With `stop` the module halts a moving blind and reports where it stands: "stopped (68 % open)". It
stops through the stop variable you entered, otherwise through the module of the device (Homematic and
Velux KLF200); the self test names the way for each blind. `stop` is only offered if at least one blind
has such a way. If the moving state says the blind is already standing, nothing is sent and the answer
reads "was not moving". If the blind reports its position only later, it reads "stop sent, not confirmed yet".

For scripts, switching and setting are two functions:

```php
echo HAGT_SwitchDevices($InstanceID, 'Wohnzimmer', 'light', '', false);
echo HAGT_SetLevel($InstanceID, '', '', 'Deckenpanel', 30);
```

### Charts, device cards and templates in the Claude app

In the Claude app the module draws charts right in the chat on request. The values only go to the chart, the language
model gets key figures such as minimum and maximum. Each chart shows its period above it, for example "Monday,
12 October 2026" or "September 2026". Other assistants do not see the tool for it.

| Chart | Example | What you see |
|---|---|---|
| History | "Show me the temperature in the guest room over the last 30 days as a chart" | one chart per unit with a band from minimum to maximum and a line for the average; with several lines a checkbox shows the band. With `type=power` the power the same way, with several rooms all in one chart ("Compare the temperature in the guest room and outside"). |
| States | "Show me when the front door was open today" | bands along the time axis, coloured by state |
| Comparison | "house consumption today compared with yesterday", "temperature in the guest room this month against the same month last year" | a dashed line for a history, a mark on each bar for consumption; in a running period the answer gives the comparison up to the same time. Not for states. |
| Scatter | "heat pump power against outdoor temperature in September" | one point per day (per hour up to two days), the mean per temperature class and a trend line. The module finds a heating limit itself ("below about 16 °C about 0.06 kW more per degree colder, explains 84 %"). The y axis takes a measured value, a meter or a power, without a device the house consumption. |
| Heatmap | "house consumption in September as a heatmap" | one cell per hour, one row per day, beyond 62 days one row per weekday with the mean. The assistant gets the strongest and the weakest hour and the difference between weekdays and weekends. |
| House consumption | "Show me our electricity consumption this month as a chart" | one bar per section made of grid, solar used directly and battery, with the grid export below (battery charged from the grid counts as grid). Above it the rings self-sufficiency and self-consumption, with a comparison the share of the comparison period in the top right corner. Several solar plants appear in shades of the solar colour. |
| Power now | "Show me the power right now as a chart" | the same rings from the power values of the balance, plus a ring with the house power from solar, battery and grid |
| Consumption per device | "Which devices used the most in September? As a chart" | the five largest devices with a meter stacked, the rest as "other devices", for the house also "not metered". The chart of a meter shows what hangs below it. |
| Cumulative | "electricity consumption this year as a cumulative chart" | consumption or costs added up day by day, a running period projected along the course of the year before, without that year linearly: "So far 10239.5 kWh, projected 13079.9 kWh for the whole period." |
| Part of the balance | "How much feed-in revenue have I earned this year, and where will I end up by the end of the year?" | only grid import, grid export, solar production or battery, one series per source, as bars, cumulative chart, heatmap and with a comparison |

With an electricity price entered, house consumption, grid import and grid export also work in euros. The cumulative
chart then shows the feed-in revenue as a green line and names the balance. If a room has measured values besides
meters, the chart shows the measured values first; ask for the consumption explicitly then. A meter that only counts
once a day shows the whole day in one hour of an hourly chart.

With "Show me the lights in the living room" the Claude app shows the devices as cards, grouped by kind. Each card
shows the state and, in large print, the main value; what is on is coloured. Depending on the device there is a
switch, a bar for brightness or opening, buttons for open, stop and close, the setpoint with plus and minus, playback
and volume, operating mode and fan speed, and for lights colour temperature and colour swatches. A tap switches at
once, without a detour through the language model. The card then shows what the device reported back and follows a
moving blind until it stops. The assistant learns in the further conversation what was operated. With a **Query
only** access the cards only show the state.

Under "+" → "Connectors" → "Add from …" the Claude app offers ready templates: **Leaving home** and **Good
night** check what is still open or on, **Energy report** names consumption, self-sufficiency and the largest
consumers of yesterday. Which ones exist depends on the release list.

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
- The module does not show the history of a valve position yet, only the current position.
- A battery-powered thermostat often takes a new setpoint only at its next radio contact. The central
  unit still confirms it right away, so "set" then means "handed over to the central unit".
- For a blind that reports only on arrival and has no moving state, the module does not know after
  the command whether it is moving.
- The assistant does not see errors inside a scene script or flow script, it only learns that it was started.
- The meter check of the self-test only finds wrong values that disappear again. If a meter jumps up for good, for
  example when it is replaced by one with a higher reading, the archive counts the jump as consumption without the
  self-test noticing. It does not check small wrong values below a fifth of a usual month or months shortly after a reset.
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
- Tokens and pairing codes are the only lock, and through Symcon Connect the endpoint is reachable from the
  internet; so use generated tokens. Rejected logins and wrong pairing codes appear in the message log. After 20 of
  them within one minute the endpoint is blocked for this sender for 60 seconds (through Connect the sender on the
  internet counts, your assistants still get through), after 100 from everyone together for everyone.
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
- **Energy balance:** the calculation the module uses for the consumption of the whole house, from grid import, solar
  production, battery discharge, grid export and battery charge.
- **Source:** a row of the energy balance, that is a grid connection, a solar plant or a battery.
- **Energy meter:** the variable that adds up the energy of a device, for example `ENERGY_COUNTER`. In the archive it
  needs the aggregation "counter".
- **Meter (type):** a device of the release list that only measures, for example a sub-distribution, a wallbox or a flat
  meter. It never counts in a device total.
- **Measured by:** the meter a device or another meter hangs on.
- **Not metered:** the part of a consumption or a power that no device and no meter below measures.
- **Special setpoint:** a setpoint outside the range of a thermostat or at its edge with a text of its own, for example
  100 for "valve fully open" or 30.5 for "On".

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
  with a login error. A faulty access (name missing, token too short or used twice), or a
  device or scene whose variable or script is missing, only disables itself; the rest keeps working,
  form and log name the entry. If a variable of the energy balance is missing, the whole balance is
  disabled, otherwise the house consumption would be wrong.

### Tool arguments

The assistant sends these arguments to **get_state**; the values are part of the tool description.

| Argument | Meaning |
|---|---|
| `room`, `floor`, `type`, `name` | Selection of the devices. Without a room, an access with a default room uses that one, `room=all` means the whole house. |
| `active_only` | only what is on, open or detected |
| `period` | `today`, `yesterday`, `this_week`, `last_week`, `this_month`, `last_month`, `this_year`, `last_year` for consumption, history and timeline, `now` for the power right now. The assistant only gets the parameter if at least one released device has an energy meter or the energy balance is set up. |
| `from`, `to` | free period instead of `period`: first and last day as YYYY-MM-DD, both included, without `to` only that one day, for example "in January" with `from=2026-01-01` and `to=2026-01-31`. With a time (YYYY-MM-DD HH:MM) by the hour, for example `from=2026-10-12 13:00` and `to=2026-10-12 15:00`; then `to` is the end, without `to` it runs until now. The module counts whole hours, at most 48 at a time. |
| `type=power` | not a device type: with a period the power over the period, without a period the power right now |

Where the values come from and where the limits are:

- Consumption: whole years and months from the yearly and monthly values of the archive, hours from the hourly values,
  everything else from the daily values.
- History: today and yesterday hourly, weeks and months daily, years monthly; with `from` and `to` up to two days
  hourly, up to 100 days daily, beyond that monthly. At most five measured values at a time, otherwise the answer asks
  for a room or name.
- Power over a period: up to 20 devices at a time, for the house at most 400 days.
- Timeline: the times of each section with up to twelve sections. With more than five devices, more than twelve
  sections or more than two days only the totals with their count are given.

For charts (**show_chart**) there are also: `compare` (`previous` or `year_before`), `versus` (second device for the
scatter chart), `heatmap`, `cumulative` (cumulative chart), `costs` (in euros) and `part` (`grid`, `feed_in`, `pv`,
`battery_charge` or `battery_discharge`).
