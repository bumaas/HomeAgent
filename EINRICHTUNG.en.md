# Setting up and updating Home Agent

[Deutsche Fassung](EINRICHTUNG.md)

This guide covers the first setup and every later update. What each field means is explained in the
[README](README.en.md).

## Installing

You need Symcon 9.0 or later. Install the module from the Module Store in the management console and then create
a **Home Agent** instance. It first shows status 104 because there is no access yet.

## Setting up

There are two ways to set up the instance. Both lead to the same result, and you can mix them.

### In the form

You enter rooms, devices and accesses yourself; with an Alexa instance most of it comes as a proposal. The steps
are in the README under [Getting started](README.en.md#getting-started).

### With an AI assistant

An AI assistant on your computer can do the work. It reads the hint for scripts and AI assistants in the form,
goes through all instances and variables of your system and proposes rooms, devices and the energy balance as a
table. Nothing is saved until you agree. Before saving it backs up the previous configuration, afterwards it runs
the self-test. All you check is the table.

If your assistant already has another way into the system, you do not need the following four points. In the
instructions further down you then replace the line with CREDENTIALS_FILE and the paragraph "Access" with a
sentence that names this way.

Otherwise the assistant talks to the JSON-RPC interface of Symcon. That takes four things:

1. **A remote access password.** Without a password the interface accepts calls from anyone in the local network
   who knows the address. How to set it is described in the Symcon documentation under
   [Remote access](https://www.symcon.de/en/service/documentation/components/remote-access/), on a SymBox for
   example in the web interface under Settings → Remote access. The user name is always the e-mail address of
   your license.
2. **An assistant that can run commands**, such as Claude Code, Codex or Gemini CLI, on a computer in the same
   network as the system. A chat in an app or a browser is not enough, it cannot run commands.
3. **curl.** Windows 10 and 11, macOS and Linux include it. In Windows PowerShell it is called `curl.exe`,
   because `curl` alone is a different command there.
4. **A file with the credentials.** curl can read its options from a file (switch `-K`). Create a text file
   for this on the computer the assistant runs on (not on the Symcon system), for example `symcon.curl` in your
   user folder, not in a folder you share through Git or a cloud.
   The name is up to you:

   ```
   url = "http://192.168.1.10:3777/api/"
   user = "license@example.com:YourRemoteAccessPassword"
   ```

   Because curl reads address and login from there, the password never appears in a command. The assistant
   shows its commands in the chat, and some clients keep them in their history. To try it, create a file
   `request.json` next to it:

   ```json
   {"jsonrpc": "2.0", "method": "IPS_GetKernelVersion", "params": [], "id": 1}
   ```

   and run in the same folder:

   ```
   curl.exe -s -K symcon.curl -H "Content-Type: application/json" -d @request.json
   ```

   | Part | Meaning |
   |---|---|
   | `curl.exe` | the program that sends the request |
   | `-s` | without progress display |
   | `-K symcon.curl` | reads address (`url`) and login (`user`) from the file with the credentials |
   | `-H "Content-Type: application/json"` | tells Symcon that JSON is coming |
   | `-d @request.json` | sends the content of `request.json`, the `@` means "from a file" |

   The answer is the version of your system, for example `{"id":1,"jsonrpc":"2.0","result":"9.1"}`. If it says
   "Invalid Username/Password for remote access!", user or password are wrong. After too many failed attempts
   Symcon checks passwords more slowly and then not at all.

**Before you start:** With these credentials the assistant may do anything you may do in the management console.
The instructions below forbid it to switch devices or change other instances, but it remains a language model.
Confirm its commands one by one instead of allowing them all, and make a backup of the system first.

Then give the assistant these instructions. If your file with the credentials is not `symcon.curl` in your user
folder, enter its path in the second line; otherwise the instructions stay as they are. The assistant asks you
itself which assistants you want to connect.
```
My input (check before sending):
CREDENTIALS_FILE = the file symcon.curl in my user folder

Set up the module "Home Agent" in Symcon. It is already installed from the Module Store.

Access: You reach Symcon through its JSON-RPC interface. Address and login are in the curl configuration
file CREDENTIALS_FILE. If you cannot find it, ask me instead of searching the computer. Never show its
content and never write it to another file. Every call is a POST with the body
  {"jsonrpc": "2.0", "method": "<function>", "params": [<parameters>], "id": 1}
best sent from a file:
  curl.exe -s -K CREDENTIALS_FILE -H "Content-Type: application/json" -d @request.json
(on Linux and macOS curl instead of curl.exe). Every Symcon function works this way, including those of
the module (HAGT_…).

The module: IPS_GetInstanceListByModuleID("{89E40AE0-BB2A-40B8-A16F-C9C5FFBFC09F}") returns its
instances, IPS_CreateInstance with the same ID creates a new one. You read the form of an instance with
IPS_GetConfigurationForm(<InstanceID>) and its configuration only with
HAGT_ExportConfiguration(<InstanceID>); every other function shows the tokens of the accesses in plain
text. You save with IPS_SetProperty(<InstanceID>, "<property>", <value>), a list as JSON text, followed
by IPS_ApplyChanges(<InstanceID>). Never write the property Accesses yourself; an access is created by
HAGT_AddAccess(<InstanceID>, "<name>", <ReadOnly>, <InPath>, "<default room>").

How to proceed:
0. Check whether there are instances of the module already and list them with name, location, status
   and number of devices. Then ask me whether to use one of them or create a new one. For a new one ask
   for the target category and the name and, if there is an instance already, whether to copy its
   rooms, devices and sources; copied entries then count as existing. Ask at the same time which
   assistants I want to connect to Home Agent (usually one, such as the Claude app). Do not create
   anything before I have answered.
1. Read the text "Hint for scripts and AI assistants" in the configuration form of the instance. It
   names all properties, the device types and the fields of each type. Stick to it and do not guess
   field names.
2. Rooms: Propose a room list with the names we use in everyday speech (e.g. Kitchen, Upstairs
   bathroom, Living room), with floor and aliases (e.g. "Kids' room" as an alias for "Leon"). No
   technical additions, each room name only once. New rooms that exist on several floors get one name
   per floor (e.g. "Kitchen upstairs" and "Kitchen downstairs") and both get the common name as an
   alias ("Kitchen"). Existing rooms keep their names, a renaming you only propose. What is already in
   the instance or taken over in step 0 comes first. For everything else use the device names and rooms
   of an Alexa instance as a template (module ID {CC759EB6-7821-4AA5-9267-EF08C6A6A5B3}), if there is
   none, the categories in the object tree.
3. Devices: Go through all device instances, including ModBus, MQTT and cloud devices, and propose a
   release list with speakable names, unique per room.
   Add:
   - Lights, sockets, blinds, windows and doors, thermostats, media players, heating and ventilation
     (heat pump, hot water, air conditioners, ventilation systems) and measurements such as
     temperature and humidity. For everything that should switch, only variables with an action
     (HasAction(<VariableID>) returns true); measurements without an action are fine.
   - Detectors for querying: water detectors as water_leak, smoke detectors as measurement.
   - For radiator thermostats also the valve positions, several actuators of a room on the same
     thermostat (up to three).
   - Power and energy meters where they exist. For a heat pump also a heat meter or heat output; two
     rows for heating and hot water only if both heat and electricity are measured separately,
     otherwise one.
   - Intermediate meters such as sub-distributions as type meter with MeteredBy, an electricity price
     as PriceVariableID, the energy balance as the list Sources: one row per grid connection, solar
     plant and battery with meter and power.
   - If a device is evidently connected more than once (e.g. through Matter and a bridge, with the
     same name and room), take the connection whose variables were updated last (VariableUpdated from
     IPS_GetVariable, not VariableChanged) and name the other one.
   Query only (ReadOnly): heating and ventilation, and sockets that power the network, servers or the
   home automation itself.
   Do not add, not even for querying: locks, alarm system, door openers, garage doors and gates. Do not
   add relays that power a smart lamp as a light; the lamp may get their power. If such a device is
   already in the instance, propose to remove it.
   As an open question instead of an entry: a device with an unclear room (do not add it with an empty
   room), a meter where it is unclear which meter measures above it (MeteredBy), a relay that may power a
   smart lamp, two connections that may be the same device, an energy meter that is not archived as a
   counter, a power or meter with a unit the hint does not name or that measures several channels,
   battery and solar meters with an unclear direction or boundary, the separate heat of a heat pump
   with only one row, a valve actuator where it is unclear which thermostat it belongs to.
   For devices that are already in the instance, ReadOnly, Invert and the names stay as they are; you
   change type or variables only if I agree in the table.
4. Show me all proposals as tables: rooms (name, floor, aliases), devices (room, name, type,
   variables), sources (type, name, meter, power) and the electricity price. Mark what is already in
   the instance and what is new or changes. With more than about 50 devices show only what is new or
   changed in the chat and write the whole tables to a file in the current working folder. Then wait
   for my OK. It applies to the tables, the open questions stay out; what I answer later you add in a
   second pass, again with a backup.
5. After my OK: First write the output of HAGT_ExportConfiguration(<InstanceID>) unchanged to a file in
   the current working folder with date and time in the name. Then enter rooms, devices, sources and
   electricity price and apply. Create an access with HAGT_AddAccess for each assistant from step 0
   that does not have one yet; whether it needs InPath is in the hint. Existing rooms, devices
   and accesses stay, unless I have agreed to a change in the table.
   Status and token arrive a few seconds after applying. Then check with HAGT_ExportConfiguration that
   no access has an empty token, and run HAGT_RunSelfTest. Show me the errors. Name every note about
   energy and heat meters and about the sign of a power as an open question to me. Only I know whether
   a blind, a window or a door counts the right way round: show me for each blind the direction from
   the self-test and its position according to Symcon, for each window and door its state according to
   Symcon, and ask me whether that is right. Set Invert only where I say it is swapped.

Rules:
- Do not switch anything and do not trigger any action on devices. Only read and configure the
  instance.
- Never print a token. Addresses only through HAGT_GetAddresses, which shows <token> as a placeholder.
- Do not change other instances.
- At the end a short summary: number of rooms and devices, open questions, result of the self-test.
```

The assistant first asks which instance to use and which assistants to connect, and later shows you the table.
Take your time with it: Are the names the ones you would say in everyday life? Is a device missing, or is there
one the assistant should not switch? Only your OK lets it save. Its open questions at the end are mostly about
meters that Symcon does not archive as counters, or about the sign of a battery power. The README explains both
under
[The release list](README.en.md#the-release-list).

### Afterwards

Connect your assistants as described in the README under [Getting started](README.en.md#getting-started), step 6, and
try the questions from step 7.

## Updating

1. **Install the update.** Install the update of Home Agent in the management console under Module Store. The
   instance keeps its configuration.
2. **Read the release notes.** They name new device types and fields and everything that changes in behaviour.
3. **Check the instance.** Open the form: the status should be active and there should be no messages at the
   top. Then **Run self-test**. Fields that an update replaces are taken over by the module itself.
4. **Use what is new.** Add new fields in the form. With many devices, run the instructions from above again:
   they keep what is already entered and only propose what is new or changed.
5. **Refresh the tool list.** The column **Tool list** in the list of accesses shows per assistant whether it
   already knows the new list. In the Claude app open a new chat; if that does not help, disconnect the
   connector and connect it again. Home Assistant fetches the list on its own only every 30 minutes; it is
   faster to reload the Model Context Protocol integration.

## When something does not work

| What you see | Cause | What to do |
|---|---|---|
| Status 104, assistants get HTTP 503 | There is no access with a token. | create an access and apply |
| Status 201, assistants get HTTP 503 | The room list has an error, or no access is free of errors. | check the rows marked red and the messages at the top of the form |
| Assistant gets HTTP 401 | Token, address or pairing are no longer valid, for example after **New token**. | renew token or address in the client, pair the Claude app again |
| A device is missing in the answers | Its row has an error and only blocks itself. | check the red row in the release list |
| A command does not arrive | The section **What did not work** shows it. | follow the hint in the row |

For a closer look, switch on the **Log** in the same section. It records every request with its answer; tokens
never appear in it.
