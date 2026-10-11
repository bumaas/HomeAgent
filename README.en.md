# Home Agent

[Deutsche Fassung](README.md)

This module gives your home an agent: an AI assistant that speaks the Model Context Protocol (MCP)
operates Symcon with it, whether spoken, typed or from a workflow of its own. It can ask "Is any window
still open?", say "Turn off the lights in the living room" or close a blind halfway and gets a short,
unambiguous answer. It only sees and switches the devices you release.

> **Status:** early preview. Querying, switching, setting brightness, colour temperature, colour, position and heating setpoint,
> stopping blinds, consumption and power, the history of measured values starting scenes as well as playback and volume of media players work. The device list can be proposed from
> an Alexa instance. The Claude app can sign in with OAuth. So far the module has been tried on three systems.

## When do I need this?

- **You want to ask your home by voice, without Alexa or Google.** The module gives an AI assistant
  exactly the devices and states it needs for everyday questions.
- **Symcon's built-in MCP server is too powerful for this purpose.** It is built to administer a
  system and gives an assistant access to everything. Here the assistant only sees your release list.
- **An assistant miscounts or mixes up devices.** The module counts by itself and knows rooms,
  device types and your own names.

## What you need

- **Symcon version 9.0 or later.** You do not need Symcon's built-in MCP server. The module is an MCP
  server of its own and runs independently of it.
- **An assistant that speaks MCP.** Microphone, speech recognition and language model come with the
  assistant. The module gives it the devices and the tools to query and switch them.
- **A suitable client.** Any client can connect that speaks MCP over plain HTTP requests and sends a
  fixed token in the `Authorization` header. Claude Code, the MCP integration of Home
  Assistant and the Claude app have been tried. Home Assistant gets the token in the address, the Claude app
  signs in with [OAuth](#sign-in-with-oauth). Claude Desktop (through
  `mcp-remote`) and Open WebUI offer this as well according to their documentation. Clients that
  cannot connect are listed under [Limits](#limits).

## Getting started

1. **Create the instance.** Install the module and create a **Home Agent** instance. The form is divided
   into expandable sections that remember whether they are open or closed.
2. **Create an access.** In the section **Endpoint and accesses**, create an access with a name in the
   list **Accesses**. The button **Generate token** at the bottom of the form shows a random string of 64
   characters that you copy into the Token column. To start with, tick **Query only**: the assistant can
   then ask but not switch anything.
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
6. **Connect the assistant.** The address is shown in the section **Endpoint and accesses** as
   **Endpoint**.

   | Client | Setup |
   |---|---|
   | Claude Code | `claude mcp add --transport http symcon-homeagent http://<address>:3777/hook/homeagent/<InstanceID> --header "Authorization: Bearer <token>"` |
   | Claude app, also on the phone | Tick **Sign-in with OAuth** and add the connector as described under [Sign-in with OAuth](#sign-in-with-oauth). Without OAuth it also works with **Token also in the address**: then use the address `https://<Connect address>/hook/homeagent/<InstanceID>/<token>` and choose "No sign-in" as authentication. The app reaches your system from the internet; the Connect address is shown in the Connect Control instance. |
   | Home Assistant | Tick **Token also in the address** for the access. Add the integration **Model Context Protocol** with `http://<address>:3777/hook/homeagent/<InstanceID>/<token>` and select it for the conversation agent under "Control Home Assistant". Turn off "Prefer handling commands locally" in the voice pipeline, otherwise Home Assistant switches sentences it understands itself through its own devices. |
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
an instance see the same release list.

| Column | Meaning |
|---|---|
| Name | Free text, for example "Kitchen" or "Guests". It appears in the debug of the instance. |
| Token | The password of this access, at least 24 characters. Each access needs its own. |
| Default room | The room the assistant is in. "Turn on the light" without a room then means this room, and with equal device names the device there wins. For the whole house the assistant says `room=all`. |
| Query only | This access may ask but not switch anything. It only sees the query tool, and the assistant is told that the access can only read. |
| Token also in the address | For clients that cannot send a login. The token is then appended to the address. |

Without an access that has a token, the instance shows status 104 and rejects all requests.

## Sign-in with OAuth

The Claude app can sign in instead of carrying the token in the address. Symcon Connect has to be
active, because the app reaches your system from the internet.

1. Under **Endpoint and accesses**, tick **Sign-in with OAuth** and apply. The address for the
   connector is then shown below.
2. In the Claude app, add a custom connector with this address under "Customize" → "Connectors". Keep
   the preselection: sign in when connecting, and "Use Claude's published identity" as OAuth client.
3. In the list of accesses, select the access the assistant should use and click **Create pairing code
   for the selected access**. The code has six digits and is valid for ten minutes.
4. Click "Connect" in the app. A page of your system opens. Enter the code there and click **Allow**.

The assistant gets the rights and the default room of this access. The page names it together with the
address it comes from. If it shows anything other than claude.ai, click **Deny**.

The paired assistants are listed below. **Revoke selected assistant** disconnects one at once. If an
access gets a new token, all assistants paired through it are disconnected. A pairing that has not been
used for 90 days expires. The self-test shows the number of paired assistants and reports when the box
is ticked but Connect is not running.

## Rooms and floors

The room of a device is free text. In the list **Rooms** you can give a room further names and a
floor. After that "lounge" finds the living room, and "all lights upstairs" matches every room of
that floor. The list is optional. A room that is missing there is still found by its name.

Write the room of a device the same way as in the room list. The self-test of the instance names
rooms that appear with a device but not in the room list.

## The release list

Each row is a device with its capabilities. In the edit dialog you choose the type, and the dialog
then shows only the variables that belong to this type.

| Column | Meaning |
|---|---|
| Room | The way you call the room. Case and umlauts do not matter ("Küche" = "kueche"). |
| Name, aliases | The name and further names, separated by commas. A partial word is enough when searching. If a device is found through an alias, the answer shows it in brackets after the name. |
| Type | Light, switch, socket, appliance, blind, thermostat, media player, humidifier, window, door, presence, water leak, temperature, humidity, air quality or other measured value. |
| State or measured value | The variable for on/off, open/closed or detected, for measured values the value itself. For a thermostat the current temperature. |
| Setpoint | Only for a thermostat: the variable with the target temperature. It needs an action. |
| Colour temperature | Only for a light, optional: the variable with the colour temperature in Kelvin or mired. It needs an action. |
| Colour | Only for a light, optional: the variable with the colour as an integer (RGB, for example profile `~HexColor`). It needs an action. |
| Current humidity, target humidity, mode, fan speed | Only for a humidifier or dehumidifier: the measured humidity, the target humidity the assistant may set (range and step from presentation or profile), and two selection variables whose entries it sets by name. The state is called "On/off" there. |
| Playback, volume, mute, title, artist | Only for a media player. These are the variables Symcon combines in the "Media Player" presentation: playback with one of the profiles `~Playback`, `~PlaybackNoStop`, `~PlaybackPreviousNext`, `~PlaybackPreviousNextNoStop` or `~PlayerStatus`, the volume (announced in percent of its range), mute as a boolean, title and artist as text. For a media player the state is called "On/off". |
| Brightness/position | For a light the brightness. It is announced together with a light that is on and can be set in percent. For a blind the position, for a window the drive. |
| Moving state | Only for blinds and windows with a drive, and only if such a variable exists: it reports whether the blind is moving, for example `WORKING` on Homematic or the run status on the Velux KLF200. The module then answers "moving" at once, even if the position only arrives at the end. |
| Stop | Only for blinds and windows with a drive, optional: a variable that stops the travel, that is a boolean with an action or a selection with the entry "Stop" (such as "Aktion" in the HomeAssistant module). Homematic and Velux KLF200 devices are stopped by the module even without it. |
| Power | Only for lights, switches, sockets, appliances and humidifiers, optional: the current power, for example `POWER` of a Homematic metering channel. It follows the state in every answer, for example "on (26.1 W)". |
| Energy meter | Also optional: the energy counter, for example `ENERGY_COUNTER`. For consumption over periods it must be aggregated as a counter in the archive. If a device hangs on a metering socket, both rows may carry the same power and energy meter; consumption and power of a room or the house count each variable only once, and the self test points it out. |
| Inverted | Tick this if `true` means "closed" or "off". Many window contacts report `true` for closed. For blinds the module finds out by itself which value means "open"; the tick reverses that if open and closed are swapped. |
| Tilt window | Only for a window with a drive: tick this if the drive only tilts the window. The answer then says "tilted" and "50 % tilted" instead of "open" and "50 % open". |
| Query only | The assistant may announce the state but not switch or set the device. Meant for devices like the garage door or the heating in the children's room. |

Which variables a device needs depends on its type:

| Type | Variables |
|---|---|
| Light | Switching state, brightness or both. A dimmer without a separate switch only has the brightness. Optionally power, energy meter, colour temperature and colour. |
| Switch, socket | Switching state, optionally power and energy meter |
| Appliance | Switching state or power, optionally the energy meter. An appliance with power only, such as a heat pump, can be queried but not switched. |
| Blind | Position, optionally moving state and stop |
| Thermostat | Setpoint, optionally the current temperature |
| Media player | On/off, playback or volume, optionally mute, title and artist |
| Humidifier | On/off or target humidity, optionally current humidity, mode and fan speed |
| Window | Contact, drive or both, optionally moving state and stop for the drive |
| Door | Contact |
| Presence, water leak | Detector |
| Temperature, humidity, air quality, other measured value | Measured value |

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

A dehumidifier reports "on (51 %, setpoint 55 %, Smart, fan Niedrig)" or "off" and counts as active while it runs.

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
values. The first line gives
the total, for example "88.5 kWh consumed by 3 of 3 metered device(s)", followed by the consumption
per device. The values come from the aggregates of the archive; the current day, week and so on count
up to now. If an energy meter is not archived as a counter, its line says "no history archived" and
the self-test points this out. A counter in a unit other than Wh, kWh or MWh, such as a water or gas meter, is not an energy meter: its line says "not an energy meter (unit m³)" and it is not added. Without a unit, kWh is assumed. Devices without an energy meter are left out. The assistant only gets
the parameter if at least one released device has an energy meter or the energy balance is set up.

The module calculates the consumption of the whole house from the **energy balance**:
grid import + solar production + battery discharge − grid export − battery charge. You
enter the five counters in the "Energy balance" section of the form; each must be archived as a
counter. Without solar and battery the grid import is enough. If the assistant asks without room, floor,
type and name (with `room=all` for an access with a default room), the first line reads, for example, "House consumption 774.8 kWh (all devices,
period=last_month): grid import 122.7, solar 1356.8, battery discharge 285, grid export 658.7, battery
charge 330.9 kWh." Devices with their own energy meter are part of it and are not added: "Of this,
166.2 kWh by 9 of 10 metered device(s), 608.6 kWh not metered." If one of the counters has no history,
the answer says "House consumption unknown" and names the counter. Asked for a room or type, the
answer stays with the sum of the devices. With solar or a battery, a line with self-sufficiency
and self-consumption follows: "Self-sufficiency 84 %, self-consumed solar 51 %." For the whole house the
answer lists only the five largest consumers and sums up the rest ("5 more device(s) together
25.6 kWh."); asked for a room, all devices follow in the order of the list.

With `period=now`, **get_state** reports the power right now in W, built like the consumption: the
current value of each device with power, plus the total. For the whole house the module calculates the
house power from the five power fields of the energy balance, for example "House power 1548 W (all
devices, period=now): grid import 27, solar 0, battery discharge 1521, grid export 0, battery charge
0 W." and "Of this, 750 W by 11 of 11 device(s) with power measurement, 798 W not metered." W, kW and
MW are converted; without a unit, W is assumed. If you only have one signed grid sensor, enter it as
grid import (negative means export); a signed battery sensor goes into battery discharge (negative
means charging).

For measured values (temperature, humidity, air quality, other measurements and the current
temperature of a thermostat) the same periods return the **history**: minimum, average and maximum per
hour, day or month, for example "How warm was it in the guest room over the last 30 days?". The first
line then reads "History of 1 measured value(s) (room=Gast, type=temperature, from=2026-09-10,
to=2026-10-09), per day: minimum / average / maximum.", followed by a line per measured value with its
unit and a line per interval such as "2026-09-28 22.2 / 22.6 / 23.1". Today and yesterday come hourly,
weeks and months daily, years monthly; with `from` and `to` up to two days hourly, up to 100 days daily,
beyond that monthly. The history applies when a measured-value type is asked for or when none of the
matches has a meter; for the whole house it stays with consumption. At most five measured values at a
time, otherwise the answer asks for a room or name. If a thermostat reads the same sensor as a
temperature device, the history comes only once. The measured value must be logged in the archive,
otherwise the line says "no history archived".

The state query is also available for scripts:

```php
echo HAGT_QueryState($InstanceID, 'Küche', 'window', '', true);
```

### Switching and setting

**set_state** does everything that changes something. The assistant selects the devices as it does
when querying and adds a command or a percent value:

| Input | Effect |
|---|---|
| `on`, `off` | switches lights, switches, sockets, appliances, media players and dehumidifiers on or off |
| `open`, `close` | moves blinds and windows with a drive fully open or fully closed |
| `stop` | stops blinds and windows that are moving and ends playback of a media player |
| `play`, `pause` | resumes or pauses playback of a media player |
| `next`, `previous` | skips to the next or previous track of a media player |
| `volume` | sets the volume of a media player in percent |
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
variable. For the Sonos module it runs from 0 to 100, for the HomeAssistant module from 0 to 1.

The module checks a temperature against the range of the setpoint, which it reads from the presentation
or the profile of the variable. Outside of it nothing is sent, and the answer names the range. Odd values
go to the step size, with steps of 0.5 21.3 becomes 21.5, and the answer names the value sent. Entries
such as "On" at the edge of a Homematic profile do not count as part of the range. The button **Run
self-test** shows the range for each thermostat.

The assistant gives the colour temperature in Kelvin. If the variable counts in mired (range up to 1000,
for example Hue via Matter), the module converts it. A value outside the range of the lamp goes to the
nearest end, and the answer names the value sent. A light that is off is switched on. With the light on,
the query names the colour temperature, for example "on (100 %, 2700 K)".

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

## Limits

- Colour as text and the operating mode of thermostats (off, heat, auto) are not supported yet.
- A battery-powered thermostat often takes a new setpoint only at its next radio contact. The central
  unit still confirms it right away, so "set" then means "handed over to the central unit".
- For a blind that reports only on arrival and has no moving state, the module does not know after
  the command whether it is moving.
- The assistant does not see errors inside a scene script, it only learns that the script was started.
- Slats whose variable carries the "blind" presentation with rotation only get a percentage without
  "open" or "closed".
- The endpoint speaks MCP over plain HTTP requests and offers no event stream. Clients that require
  an event stream (SSE) cannot connect directly. With OAuth only clients with a Client ID Metadata
  Document can sign in; the Claude app has been tried. Neither way has been tried for ChatGPT.
- Tokens and pairing codes are the only lock. Through Symcon Connect the endpoint is reachable from
  the internet, so use generated tokens. Rejected logins appear in the message log (the first one of a
  minute and then their number); wrong pairing codes count as well. After 20 rejected logins within one
  minute the endpoint is blocked for
  60 seconds for everyone, including assistants with the right token.
- With the token in the address it can end up in logs, histories and settings files of the client.
  Set the tick only for accesses whose client cannot send the login in the header.
- The tokens are part of the instance configuration. Whoever may read objects of the system, for
  example an assistant using the built-in MCP server of Symcon, reads them as well.
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
- Supported protocol versions: 2025-11-25, 2025-06-18, 2025-03-26.
- Status 104: no access with a token, the endpoint rejects all requests. Status 201: the room list has
  errors, or there is no faultless access left; the log names every affected entry, and the endpoint
  stays closed until they are corrected. In both cases it answers with HTTP 503 ("not ready"), not
  with a login error. A faulty access (token too short or used twice, unknown default room), or a
  device or scene whose variable or script is missing, only disables itself; the rest keeps working,
  form and log name the entry. If a variable of the energy balance is missing, the whole balance is
  disabled, otherwise the house consumption would be wrong.
