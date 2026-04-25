# NxtScript Element Reference

Comprehensive, source-of-truth reference for every `el<Name>` class in Amtelco's
NxtScript / Infinity Script element library. The data here is extracted directly
from the decompiled C# in:

- `amtelco/cs/NxtScript.Elements/NxtScript.Elements.decompiled.cs` (~42,900 lines)
- `amtelco/cs/NxtScript/NxtScript.decompiled.cs` (~27,900 lines, shared types)

Use this document while building a custom NxtScript editor: every property
listed below is a real C# field with a public setter that the IS Supervisor
editor or the runtime serializer reads/writes. Property names are verbatim,
matching the JSON / XML names you must emit when generating scripts.

---

## Table of Contents

1. [Conventions and Common Types](#conventions-and-common-types)
2. [Script Tree Shape](#script-tree-shape)
3. [ExprValue — the universal expression wrapper](#exprvalue--the-universal-expression-wrapper)
4. [Advanced Expression XML](#advanced-expression-xml)
5. [Common Enums](#common-enums)
6. [Element Reference by Category](#element-reference-by-category)
   - [Script Container & Screens](#script-container--screens)
   - [Display / Input Elements](#display--input-elements)
   - [Conditional / Flow Control](#conditional--flow-control)
   - [Rules](#rules)
   - [Field & Subject Actions](#field--subject-actions)
   - [Action Groups & Tables](#action-groups--tables)
   - [Messaging — direct delivery](#messaging--direct-delivery)
   - [Messaging — contact-aware delivery](#messaging--contact-aware-delivery)
   - [Contact Selection & Dispatch](#contact-selection--dispatch)
   - [Database & Web](#database--web)
   - [Contact / CRM (CMI)](#contact--crm-cmi)
   - [Appointments](#appointments)
   - [HL7 / Integration / MergeComm](#hl7--integration--mergecomm)
   - [System / Telephony](#system--telephony)
   - [History, Search, Special](#history-search-special)
   - [Class Registration](#class-registration)
   - [Misc Specialized](#misc-specialized)

---

## Conventions and Common Types

### Base classes (defined in `NxtScript.decompiled.cs`)

| Class | Role |
|-------|------|
| `Element` | Root of the script-element class hierarchy. Provides `Id` (Guid), `Name`, `AssociatePrevious`, `Nodes` (NodeList), `ChangeProperty(...)` plumbing, `Validate(Validator)` and `CreateSummary()`. |
| `ScriptElement : Element, ISupportStyle` | The root of one entire script — only `elScript` derives from this. Cannot be added to other elements. |
| `ScreenElement : Element, ISupportStyle` | Base for screen-level containers. Only `elScreen` and `elSummaryScreen`-style screens derive from this. Lives inside `elScript.Nodes["Screens"]`. |
| `DisplayElement : Element, ISupportStyle` | Base for things that render on a screen Display node. |
| `InputElement : DisplayElement, ILabelEdit` | Base for screen inputs. Adds `LabelExpr` and `DescriptionExpr`. All `el*box`, `elList`, `elCheckbox`, `elLabel`, `elImage`, `elButton`, `elDatePicker` derive from this. |
| `CalcElement : Element` | Base for "action" elements — anything that runs as part of a CalcNode (Click, Initialize, Start, Complete, action group, etc.). Most non-display elements derive from this. |
| `RuleElement : Element` | Base for rule elements (`elRule`, `elBranchRule`, `elListRule`). Lives inside `Nodes["Rules"]`. |
| `SharedActionsElement` | Base for `elActionGroup`, `elActionTable`. Lives only inside `Nodes["Shared"]` of `elScript` or `elScreen`. |
| `ActionTableStepElement` | Base for `elActionTableStep`. Children of `elActionTable`. |

### Marker interfaces

| Interface | Meaning |
|-----------|---------|
| `ISupportExecute` | Runtime executes this element (`Execute(IRuntime)`). Almost every CalcElement implements it. |
| `ISupportRuleRender` | Editor renders the element's body via `IRuleRender` (used for if/branch/case bodies). |
| `ISupportMessageSummary` | Element can contribute to the message summary on save. |
| `ISupportInputActions` | InputElement that runs sub-actions (TabKey/EnterKey/Click). |
| `IRuntimeDisplayElement` | Renderable on a screen at runtime. |
| `ISupportSummaryEdit` | Field value can be edited from the summary screen. |
| `ISupportAdditionalTypes` | Element exposes a sub-type system (`elTextbox` -> TextboxTypes; `elList` -> ListTypes). |
| `ISupportStyle` | Has visual style (font, color). |
| `IAcceptElementVisitor` | Visitor pattern for traversal. |
| `ScriptInfo.IField` | Element is a named field whose value is stored in the cache and emitted as `[FieldName]`. |
| `ScriptInfo.IList` | Element is a list source whose Values can be branched on by `elListCalc` / `elListRule`. |
| `ScriptInfo.IDbConnection` | Element registers a named database connection (only `elDbConnection`). |
| `ScriptInfo.ISharedActions` | Element can live in a `Shared` node (`elActionGroup`, `elActionTable`, `elDbConnection`). |
| `ScriptInfo.ISupportSharedActions` | Element exposes a `Shared` child node (`elScript`, `elScreen`). |
| `ScriptInfo.IDefineContact` | Element defines contacts (only `elScript`). |
| `IMessageScript` | Marker for the script root. |
| `ISupportCallFields` | Element exposes top-level CallField definitions (only `elScript`). |

### `[ElementProperty(group, label, description)]` attribute

Every editable property on an element is decorated with this attribute. It tells
the IS Supervisor property grid how to display the field:

- **group** — section name in the property grid ("General", "Summary", "Validation", "Input").
- **label** — the human-readable label shown in the editor.
- **description** — help text shown below the editor.

Some properties also carry `[ElementAllowEdit]`, which means the property is
editable inline (without opening a dialog), and `[DefaultValue(...)]` which is
respected by the JSON/XML serializer to omit default values.

Top-of-class `[ElementProperty(category, displayName, description, isVisible, payFors)]`
on the class itself is the **palette entry** — what shows up in the IS toolbox.
The fourth/fifth args (visibility flag and `PayFors`) gate the element behind a
purchased feature flag.

### `Nodes` collection

Every Element has a `Nodes` collection. `Nodes` may contain:

- `CalcNode` — list of CalcElements (action sequence). Examples: `Click`, `Initialize`, `True`, `False`, `Default`, `TabKey`, `EnterKey`.
- `DisplayNode` — list of DisplayElements (screen layout). Always named `Display`.
- `ScreenNode` — list of ScreenElements. Always named `Screens` and only on `elScript`.
- `SharedActionsNode` — list of SharedActionsElement instances. Always named `Shared`.
- `RuleNode` — list of RuleElement instances. Always named `Rules`.

Look up children with `element.Nodes.NodeByName("Click")` etc. Nodes are
*positional* — the order in `elScript.Nodes` is fixed (Shared, Initialize,
Start, Screens, Complete, MergeComm) and the editor relies on this.

### `ChangedFlags`

`ChangeProperty` calls pass one of:

- `ChangedFlags.ElementChange` — minor edit, doesn't trigger layout reflow.
- `ChangedFlags.ElementChange | ChangedFlags.ElementLayout` — also forces the editor to re-render the element.

You don't emit this in serialized form, but it tells you whether a property is
"layout-affecting" when the editor must re-paint after the change.

---

## Script Tree Shape

```
elScript                                              (root)
├── Nodes["Shared"]   : SharedActionsNode             (elActionGroup, elActionTable, elDbConnection)
├── Nodes["Initialize"] : CalcNode                    (CalcElements run once on first start)
├── Nodes["Start"]    : CalcNode                      (CalcElements run on every start/edit)
├── Nodes["Screens"]  : ScreenNode                    (elScreen instances)
│   └── elScreen
│       ├── Nodes["Shared"]     : SharedActionsNode   (screen-scoped action groups)
│       ├── Nodes["Initialize"] : CalcNode            (first time screen shown)
│       ├── Nodes["Load"]       : CalcNode            (every time screen shown)
│       ├── Nodes["Display"]    : DisplayNode         (DisplayElements: textboxes, buttons, lists, etc.)
│       │   └── elTextbox
│       │       ├── Nodes["TabKey"]   : CalcNode
│       │       ├── Nodes["EnterKey"] : CalcNode
│       │       └── Nodes["Rules"]    : RuleNode      (auto-added on demand)
│       ├── Nodes["Unload"]     : CalcNode            (when navigating away)
│       └── Nodes["Rules"]      : RuleNode            (screen-level rules)
├── Nodes["Complete"] : CalcNode                      (run when script saved/completed)
├── Nodes["MergeComm"] : CalcNode                     (run in MergeComm/automated mode; gated by PayFors.MergeComm)
└── Nodes["Rules"]    : RuleNode                      (script-level rules)
```

### Rules of containment

| Container | Accepts |
|-----------|---------|
| `elScript` Shared | `elActionGroup`, `elActionTable`, `elDbConnection` only. (Validators reject anything else.) |
| `elScript` Initialize / Start / Complete / MergeComm | Any `CalcElement` — actions, branches, sends, dispatches, etc. |
| `elScript` Screens | `elScreen` only. |
| `elScript` Rules | `elRule`, `elBranchRule`, `elListRule`. |
| `elScreen` Shared | `elActionGroup`, `elActionTable` (NOT `elDbConnection` — those belong on the script). |
| `elScreen` Initialize / Load / Unload | `CalcElement`. |
| `elScreen` Display | `InputElement` (textbox, list, button, label, image, checkbox, datepicker, viewSchedule, showSchedule). |
| `elScreen` Rules | rule elements scoped to `Screen` rule context. |
| Any input element's TabKey / EnterKey / Click | `CalcElement`. |
| Any input element's Rules | rule elements scoped to that input type. |
| `elIfCalc` True / False | `CalcElement`. |
| `elBranchCalc` Default / per-value nodes | `CalcElement`. |
| `elListCalc` per-value nodes | `CalcElement`. |
| `elActionGroup` | `CalcElement` chain. |
| `elActionTable` | `elActionTableStep` only. |
| `elActionTableStep` | column-value definitions and inner action calc. |
| `elContactDispatch` etc. | nested children documented per element below. |

### MergeComm node and PayFors

`elScript.Nodes["MergeComm"]` is a CalcNode tagged with
`FeatureFlags.NonInteractive` and `PayFors.MergeComm`. Only elements whose
`GetFeatureFlags()` returns `NonInteractive` (i.e., not a screen or interactive
input) may be added there, and the editor hides the node entirely unless the
client has the MergeComm pay-for. When generating scripts you don't need to
populate it for normal flows.

---

## ExprValue — the universal expression wrapper

Everywhere an expression appears in a script (label, default, summary prefix,
field value, dial number, email body, condition, etc.), it's an `ExprValue`.
Defined in `NxtScript.decompiled.cs:12153`.

```csharp
public class ExprValue {
    public enum Types {
        None, Text, Field, Advanced, Named, SystemField, ClientField,
        ACDField, ContactField, CallField, AgentField, MergeComm, NamedFormat
    }
    public Types Type;
    public string ExpressionText;   // serialized form, depends on Type
}
```

### When to use which Type

| Type | Source of value | What `ExpressionText` holds | Display | Notes |
|------|-----------------|----------------------------|---------|-------|
| **None** | nothing | empty | empty | Used for "no expression". `IsEmpty == true`. |
| **Text** | literal string | the literal text, verbatim | the text | Use this for static labels, hard-coded captions, fixed body text. |
| **Field** | message-field cache | the field name | `[FieldName]` | Resolves to `runtime.Cache[FieldName]`. The simplest field reference. |
| **Advanced** | XML expression | XML symbol stream (see next section) | formatted text | Use for any expression with concatenation, conditions, math, function calls, or mixed text + field substitution. **Do not** put `Hello [Name]` into a Text expression — put `Hello ` literal + `[Name]` field in an Advanced expression. |
| **Named** | named expression on the script/screen | the name | `Name: <name>` | Resolves to a saved named expression at runtime. Editor can resolve via `GetNamedExpression(name)`. |
| **SystemField** | IS system fields | field name | `System Field[name]` | Things like CallerID, AgentName, AccountName. Source id `2`. |
| **ClientField** | client database field | field name | `Client Field[name]` | Source id `3` — pulls from cltClients-style data. |
| **ContactField** | the active contact's field | field name | `Contact Field[name]` | Source id `4`. Used inside contact-iteration elements. |
| **CallField** | the script's CallFields | field name | `Call Field[name]` | Source id `5`. CallFields are defined on `elScript.CallFields`. |
| **AgentField** | the operator's profile | field name | `Agent Field[name]` | Source id `6`. |
| **MergeComm** | MergeComm runtime variables | field name | `MergeComm[name]` | Source id `7`. Only resolves when running in MergeComm mode. |
| **ACDField** | ACD call distribution data | field name | `ACD Field[name]` | Source id `1`. |
| **NamedFormat** | named format string | the name | `Named Format: <name>` | Used for display formatting (date format, number format) by name. |

When `Type != Text` and `Type != Advanced`, `ExpressionText` is the bare key
(field name, named-expression name). `GetAsAdvancedExpression(...)` re-serializes
any flavor into an Advanced XML, with `source` = the integer id from the table.

### Editor selector

Each property of type `ExprValue` is shown in the editor as a "..." button that
opens the expression editor; the user picks one of the above types. For a
custom editor you should let the user pick a Type from the enum and then
supply:

- For **Text** / **Field** / **Named** / **SystemField** / **ClientField** /
  **ContactField** / **CallField** / **AgentField** / **MergeComm** /
  **ACDField** / **NamedFormat** — a single text input.
- For **Advanced** — the XML symbol stream editor (see next section).

`ShouldSerializeXxxExpr()` methods return `false` when `IsEmpty`, so empty
expressions are typically omitted from JSON serialization.

---

## Advanced Expression XML

When `ExprValue.Type == Advanced`, `ExpressionText` is **NOT** plain text with
`{Field}` placeholders. It is an XML stream of `<symbol>` elements. There are
five symbol kinds, defined in `Symbols` (NxtScript.decompiled.cs:12337):

| Symbol Type | XML element | Use |
|-------------|-------------|-----|
| `Literals` (id 2) | `<symbol symbolTypeId="2"><literal>text</literal></symbol>` | A fragment of literal text. The exact characters appear in the output. |
| `Variables` (id 4) | `<symbol symbolTypeId="4"><variable source="N">Name</variable></symbol>` | A field reference. `source` = 0 (msg field), 1 (ACD), 2 (system), 3 (client), 4 (contact), 5 (call), 6 (agent), 7 (merge). |
| `Constants` (id 5) | `<symbol symbolTypeId="5"><constant constantId="0|1|2">…</constant></symbol>` | `Empty` (0), `NewLine` (1), `LineContinuation` (2). |
| `Functions` (id 0) | `<symbol symbolTypeId="0"><function functionId="N">FnName(<param name="p"><expression dataType="N">…</expression></param>…)</function></symbol>` | A built-in function call. |
| `Operands` (id 3) | `<symbol symbolTypeId="3"><operand operandId="N">+</operand></symbol>` | Math/logical/comparison operator. |
| `Enumerations` (id 1) | `<symbol symbolTypeId="1"><enumeration enumerationId="N" enumValueId="V">DateInterval.Day</enumeration></symbol>` | Used inside Function params. |

Symbols are serialized in sequence — there's no outer wrapper element, just
concatenated `<symbol>` blocks. The runtime evaluates them as a stream.

### Concrete example: `"Hello, " & [CallerName] & vbNewLine & "Account: " & {SystemField:AccountNumber}`

```xml
<symbol symbolTypeId="2"><literal>Hello, </literal></symbol>
<symbol symbolTypeId="3"><operand operandId="17">&amp;</operand></symbol>
<symbol symbolTypeId="4"><variable source="0">CallerName</variable></symbol>
<symbol symbolTypeId="3"><operand operandId="17">&amp;</operand></symbol>
<symbol symbolTypeId="5"><constant constantId="1">&#xD;&#xA;</constant></symbol>
<symbol symbolTypeId="3"><operand operandId="17">&amp;</operand></symbol>
<symbol symbolTypeId="2"><literal>Account: </literal></symbol>
<symbol symbolTypeId="3"><operand operandId="17">&amp;</operand></symbol>
<symbol symbolTypeId="4"><variable source="2">AccountNumber</variable></symbol>
```

### Function symbol IDs (Symbols.FunctionSymbols enum, NxtScript.decompiled.cs:12384)

```
0=Now            1=DateAdd         2=DateDiff       3=DatePart
4=Asc            5=Chr             6=UCase          7=LCase
8=Left           9=Right          10=Mid           11=InStr
12=Len          13=Replace        14=RTrim         15=LTrim
16=Trim         17=String         18=FormatNumber  19=FormatDate
20=Abs          21=Atn            22=Cos           23=Exp
24=Fix          25=Log            26=Round         27=Sgn
28=Sine         29=Sqr            30=Tan           31=IIF
32=CDbl         33=CDate          34=CStr          35=CBool
36=Guid         37=TimeRange      38=TimeSerial    39=DateSerial
40=GetAge       41=WeekDay        42=Month         43=FormatPhone
44=IsEmpty      45=FormatAge      46=Time          47=Date
48=CTime        49=CDateTime      50=Normalize     51=LabelField
52=ParseWithDelimiter             53=DateRange     54=EncodeXML
55=DecodeXML
```

### Operand symbol IDs (Symbols.OperandSymbols enum)

```
0=+    1=-    2=*    3=/    4=Mod
5=OR   6=AND  7=NOT  8=XOR
9==   10=<>  11=>   12=<   13=>=   14=<=
15=(  16=)   17=&  (string concat)
```

### Constant symbol IDs

```
0=Empty   1=NewLine ("\r\n")   2=LineContinuation ("_\r\n")
```

### Enumeration symbol IDs

`EnumSymbols` enum: `0=DateInterval`, `1=DateFormatStyles`, `2=NumberFormatStyles`.

`EnumDateInterval`: `0=Day, 1=DayOfYear, 2=Hour, 3=Minute, 4=Month, 5=Quarter, 6=Second, 7=WeekDay, 8=WeekOfYear, 9=Year`.

`EnumDateFormatStyles`: `0=General, 1=LongDate, 2=MediumDate, 3=ShortDate, 4=LongTime, 5=MediumTime, 6=ShortTime, 7=LongDateShortTime, 8=LongDateLongTime, 9=ShortDateShortTime, 10=Month, 11=GMT, 12=Sortable, 13=GMTSortable, 14=YearMonth`.

`EnumNumberFormatStyles`: `0=Currency, 1=Scientific, 2=Fixed, 3=General, 4=Number, 5=Percent, 6=Hexadecimal`.

### Variable `source` IDs (ScriptInfo.ExpressionFieldSources)

```
0 = MessageField          (msg cache, the default)
1 = ACDField
2 = SystemField
3 = ClientField
4 = ContactField
5 = CallField
6 = AgentField
7 = MergeCommField
```

### Persistence form (`XmlPersistance` property)

When ExprValue is round-tripped through XML (used by the editor's
copy-paste and the older XML serialization path), it wraps in:

```xml
<exprType type="Advanced">
  <expression>...the symbols above, or literal text for Type=Text...</expression>
</exprType>
```

JSON serialization uses the simpler form `{ "Type": 3, "ExpressionText": "..." }`
(Type as enum integer).

---

## Common Enums

### `NavTypes` (used by `elNav`)

```
None, Back, Forward, Cancel, Close, Screen
```

### `MethodTypes` (delivery methods, used by dispatch)

`MethodTypes` is per-element (e.g., `EmailDeliverOptions`, `PageDeliverOptions`,
`SmsDeliverOptions` etc.) — each enum is similar:

- `EmailDeliverOptions`: `Required, Reply, ReplyAll, Read, Forward` (flags).
- `EmailReplyActions`: `OneCall, Resend, Print, Reply, Forward, Voicemail` (flags).
- `PageDeliverOptions`: similar shape (Required, Reply, etc.).
- `SmsDeliverOptions`, `FaxDeliverOptions`, `VoceraDeliverOptions`,
  `CiscoDeliverOptions`, `SecureMessagingDeliverOptions` — each defines its own flags.
- `WCTPDeliverOptions` for WCTP paging.

### `DispatchPriorities` (priority of dispatch row, used by elContactDispatch and elSendToDispatcher)

```
None = 0, Low = 1, Normal = 2, High = 3
```

### `DispatchListColors`

(Defined in NxtScript.decompiled.cs alongside dispatch elements.)
Standard color palette: `Default, Red, Yellow, Green, Blue, Orange, Purple, Pink, Gray`.

### `ConnectionTypes` (DbLookup.ConnectionTypes, NxtScript.decompiled.cs:14801)

Database driver enumeration: `OleDb, Odbc, SqlClient, Oracle, MySQL, Access, ...`.

### `DbConnectionTypes` (NxtScript.decompiled.cs:19794)

Connection family used at runtime: `SqlServer, MySQL, Oracle, OleDb, Odbc, MSAccess, ...`.

### `ConstantSymbols`

```
Empty = 0, NewLine = 1, LineContinuation = 2
```

### `HotKey` (defined inside `elButton`)

```
None, Action1, Action2, Action3, Action4, Action5, Action6,
Action7, Action8, Action9, Action10, Action11, Action12
```

### `KeyActions` (NxtScript.decompiled.cs:16025)

Operator hotkey identifiers: `None, Action1..Action12, Tab, Enter, Escape, F1..F12`.

### `PromptType` (used by `elPrompt`)

```
YesNo, OkCancel, OkOnly, RetryCancel, AbortRetryIgnore, YesNoCancel
```

### `PromptResults`

```
None, OK, Cancel, Yes, No, Retry, Abort, Ignore
```

### `ParkToTypes` (used by `elPark`, `elPark2`)

```
None, ParkOrbit, OnHold, ConferenceBridge, AgentExtension, Inbound
```

### `DialType` (used by `elDial`, `elDialWithType`)

```
Voice, Fax, Pager, SMS, External, AgentExtension
```

### `ToggleValues` (used by `elToggleHold`, `elToggleVoice`, `elToggleRecording`, `elToggleOnOff`)

```
On, Off, Toggle
```

### `ChangeAccountResults`

```
None, Success, Failure, Cancelled, NotFound, NoChange
```

### `HistoryType` (used by `elGetHistory`, `elSaveHistory`)

```
None, Caller, Account, Field
```

### `RoleTypes` (CMI / CRM)

```
None, Primary, Secondary, Backup, Other
```

### `ContactMethods`

Bitmap of available contact methods on a single contact: `Phone, Email, Sms, Pager, Fax, Vocera, Cisco, SecureMessaging`, etc.

### `SecureMessagingPriority`

```
Low, Normal, High, Urgent
```

### `VoceraPriority`

```
Normal, Urgent
```

### `CiscoReplyActions`, `VoceraReplyActions`, `FaxReplyActions`, `PageReplyActions`, `SmsReplyActions`, `EmailReplyActions`, `SecureMessagingReplyActions`

Per-channel flags identifying how a reply (if any) is handled. See
`NxtScript.decompiled.cs` lines 17981–20235 for each enum's exact members.

### `ScriptEmailAccountTypes` (NxtScript.decompiled.cs:18233)

`Default, ClientSpecific, ScriptSpecific, AgentSpecific` — used by `elEmail` to
pick the From address.

### `ScriptTrackerTypes` (NxtScript.decompiled.cs:18068)

Call-tracker event categories used by `elSaveCallTrackerEvent`.

### `ViewScheduleViewTypes` (NxtScript.decompiled.cs:16414)

`Day, Week, Month, Custom` — passed to `elViewSchedule`.

### `Priorities` (auto-dispatch / handle dispatch)

`None, Low, Normal, High, Urgent`.

### `ShiftRecurrenceInterval`

Used by schedule/CMI activity scheduling. `None, Daily, Weekly, Monthly, Yearly`.

---

## Element Reference by Category

The categories below group elements by their role. Within each, individual
elements are listed alphabetically. The "Class palette name" line shows the
exact `[ElementProperty]` triplet that decorates the class itself, in the form
`[Group, DisplayName, Description, Visible, PayFors]`. The "Default ctor"
column lists what the parameterless constructor sets so you can default-init
a new instance with the right shape.

When a property is documented with a `(no [ElementProperty])` annotation, it is
public and serializable but not directly exposed by the property grid — those
properties are typically set via dialogs or sub-editors. Treat them as fields
your editor must persist in the JSON / XML form.

---

### Script Container & Screens

#### `elScript`

- **Display name:** `"Script"`
- **Class palette:** `[Script, Message Script Element, Each message script contains a script element to define script options.]`
- **Base:** `ScriptElement`
- **Implements:** `ISupportRuleRender, ScriptInfo.IDefineContact, ISupportMessageSummary, IMessageScript, ISupportCallFields, ScriptInfo.ISupportSharedActions`
- **Source:** lines 30758–31175.

This is the root of the entire script. Exactly one per script document.

| Property | Type | Default | Notes |
|----------|------|---------|-------|
| `ShowInitializeActions` | bool | false | If true, the editor renders the Initialize node body inline in the script tree. |
| `AllowSummaryEdit` | bool | true | Operator may edit the summary on the summary screen. |
| `ShowSummary` (`ShowScriptSummary`) | bool | true | Show the auto-generated summary at the end of the script. |
| `ConvertSummaryToUpper` | bool | false | Force the entire summary to upper case. |
| `RightAlignSummaryLabels` | bool | true | Right-justify the field labels in the summary. |
| `ExcludeEmptySummaryFields` | bool | true | Omit fields with empty values from the summary. |
| `UseNotebox` | bool | false | Show a free-text notebox in the operator's UI. |
| `ScreenModeChangeSelectsFirstInput` | bool | false | When the screen mode flips, focus the first input. |
| `UseISClientTime` | bool | false | Evaluate time expressions against the IS client clock instead of agent local. |
| `SummaryHeader` | ExprValue | empty | Text/expression printed above the auto summary. |
| `SummaryFooter` | ExprValue | empty | Text/expression printed below the auto summary. |
| `Contacts` | `List<ContactDefinition>` | empty list | The contact definitions the script knows about. Each entry has `Name`, `Subject` (an IS subject id), and method-channel filters. Contacts are referenced by name in dispatch and `elContactSend*` elements. |
| `CallFields` | `CallField[]` | empty | Top-level CallField definitions; available as ExprValue type=CallField. |

- **Nodes:** `Shared` (SharedActionsNode — only `elActionGroup` / `elActionTable` / `elDbConnection`), `Initialize` (CalcNode), `Start` (CalcNode), `Screens` (ScreenNode — only `elScreen`), `Complete` (CalcNode), `MergeComm` (CalcNode, `FeatureFlags.NonInteractive`, `PayFors.MergeComm`).
- **Validation:** must contain at least one screen. Contact names must be unique. CallField names must be unique (case-insensitive).
- **Editor gotcha:** the `elDbConnection` element is the *only* `CalcElement` allowed in `Shared` apart from the `SharedActionsElement` derivatives — IS treats it specially because it has `ScriptInfo.IDbConnection`.

#### `elScreen`

- **Display name:** "Screen" (no override; inherits from `ScreenElement`).
- **Base:** `ScreenElement`
- **Implements:** `ISupportStyle, IMessageScreen, ScriptInfo.IScreenWithModes, ISupportRuleRender, ISupportMessageSummary, ScriptInfo.ISupportSharedActions`
- **Source:** lines 30509–30756.

| Property | Type | Default | Notes |
|----------|------|---------|-------|
| `Modes` | `ScreenModes` | empty | Named visual modes for the screen. Each mode has its own list of "assigned" elements via `AssignElement(modeName, elementName)`. The default mode (when modes are non-empty) is `Modes[0]`. |
| `ShowInitializeActions` | bool | false | Render Initialize node body inline. |
| `ExcludeFromSummary` | bool | false | Omit this entire screen from the message summary. |
| `SummaryHeader` | ExprValue | empty | Text printed before this screen's fields in the summary. |
| `SummaryFooter` | ExprValue | empty | Text printed after. |

- **Nodes:** `Shared`, `Initialize`, `Load`, `Display` (DisplayNode — input elements only), `Unload`. Rules node added on demand.
- **Where added:** Only inside `elScript.Nodes["Screens"]`.
- **Editor gotcha:** Screens are positional. Operator navigation (Forward/Back) walks the `Screens` collection in order. Use unique screen names — they're referenced by `elNav.NavScreen`, `elPark.ResumeScreen`, etc.

#### `elSummaryScreen`

This is not a separate class — there is *no* `elSummaryScreen` in the decompiled
source. Summary behaviour is configured on the `elScript` itself
(`ShowSummary`, `AllowSummaryEdit`, `SummaryHeader`, `SummaryFooter`), and the
runtime auto-generates a summary screen at script end. To present a
custom-styled summary, use a normal `elScreen` populated with `elLabel`
elements that reference fields via `Expr` of type `Field`.

---

### Display / Input Elements

All concrete display elements derive from `InputElement` and inherit:

- `LabelExpr` (ExprValue) — `[ElementProperty("General","Label","Expression to use for input label")]`. Default `new ExprValue()`.
- `DescriptionExpr` (ExprValue) — `[ElementProperty("General","Description","Expression to use for input description")]`. Default empty.
- `Name` (string) — the element's identifier and (for `IField` implementers) its field name in the cache.
- `AssociatePrevious` (bool) — when true, the field is rendered on the same summary line as the previous field.

`InputElement` defines those two ExprValues on every input. They're omitted
from JSON when empty (`ShouldSerializeLabelExpr`).

#### `elTextbox`

- **Display name:** "Textbox" (default ctor).
- **Class palette:** `[Input, Textbox, A textbox used to get input for message.]` (set on parent base, defaults applied).
- **Base:** `InputElement`
- **Implements:** `ScriptInfo.IField, ISupportMessageSummary, ISupportInputActions, IRuntimeDisplayElement, ISupportRuleRender, ISupportSummaryEdit, IAcceptElementVisitor, ISupportAdditionalTypes`
- **Source:** lines 37719–38296.

| Property | Type | Default | Editor (group / label) | Notes |
|----------|------|---------|------------------------|-------|
| `Readonly` | bool | false | General / "Readonly" | Disables editing. |
| `ValidateImmediately` | bool | false | General / "Validate Immediately" | Validate before tab-out. |
| `InputType` | `TextboxType` | `TextboxTypeGeneral` | General / "InputType" | One of 13 sub-types (see "TextboxType variants"). |
| `DefaultExpr` | ExprValue | empty | General / "Default value" | Pre-fills the value at screen load. |
| `ForceValidation` | bool | false | General / "Force Validation" | Block downstream actions until value is valid. |
| `InputHint` | ExprValue | empty | General / "Input Hint" | Placeholder hint text. |
| `ExcludeFromSummary` | bool | false | Summary / "Exclude from Summary" | Omit this field from the auto summary. |
| `SummaryDialable` | bool | false | Summary / "Dialable" | Mark value as a dialable phone for the summary UI. |
| `SummaryPageable` | bool | false | Summary / "Include in paging text" | Include in pager body composition. |
| `SummaryExcludeLabel` | bool | false | Summary / "Exclude Label" | Drop the label, just show value. |
| `SummaryPrefix` | ExprValue | empty | Summary / "Prefix" | Text inserted before the value in the summary. |
| `SummarySuffix` | ExprValue | empty | Summary / "Suffix" | Text inserted after the value. |

- **Nodes:** `TabKey` (CalcNode), `EnterKey` (CalcNode); `Rules` added on demand.
- **Where added:** screen `Display` node only.
- **TextboxType (`InputType`) variants** (ListTypes drives a similar pattern for `elList`):
  - `TextboxTypeGeneral` — generic text. Properties: `CheckSpelling` (bool, default false), `SpellCheckDictionary` (SpellCheckDictionaries enum: `English|EnglishMedical|Dutch|French|Spanish`, default English), `CaseConversion` (CaseConversions enum: `None|Lower|Upper|Proper|Sentence`, default None), `ProperCaseOptions` (`ProperCaseParams` — flags `skipUpper, dotFirst, dotRest, dotPersonalParts, dotOtherParts, dotPHD`).
  - `TextboxTypeCreditCard` — types: `Visa|MasterCard|AmericanExpress|Discovery|DinersClub|CarteBlanche|EnRoute|Jcb` flags. Default `Visa | MasterCard`. Auto-formats with `-` separators every 4 digits and validates Luhn checksum.
  - `TextboxTypeEMail` — strict regex `[a-zA-Z0-9_.\-]+@…` validation. Always 1 row, never SaveFormatted.
  - `TextboxTypeDateTime` — properties: `DateFormat` (string, default `DateTimeFormatString.DefaultDate`), `TimeFormat` (string, default `DateTimeFormatString.DefaultTime`), `YearCutOff` (int, default 30), `LowType / LowDate / LowOp / LowUnit / LowUnitValue` and `HighType / HighDate / HighOp / HighUnit / HighUnitValue` for range constraints. `CompareTypes`: `None|Fixed|Current`. `CompareOperators`: `LessThan|LessThanEqual|GreaterThan|GreaterThanEqual`. `OffsetUnits`: `None|Second|Minute|Hour|Day|Week|Month|Year`.
  - `TextboxTypeDuration` — properties: `DurationType` (flags `Hour|Minute|Second`, default all), `Seperator` (char, default `:`).
  - `TextboxTypeMasked` — `Mask` (string, e.g. `999-99-9999` where `9`=digit, `x`=any, `z`=optional digit, `a`=optional letter), `Alert` (string explaining required format).
  - `TextboxTypeNumber` — `AllowDecimal` (bool, default false), `MinValue` (double, 0.0), `MaxValue` (double, 0.0; 0/0 means no limit), `DecimalPlaces` (int 0–8, default 2), `RoundType` (Rounding enum: `None|Normal|Up|Down`, default Normal).
  - `TextboxTypePhone`, `TextboxTypeCity`, `TextboxTypeState`, `TextboxTypeCountry`, `TextboxTypePostalCode`, `TextboxTypeSocialSecurity` — narrow validators. Mostly inherit base columns/rows.
- **Validation:** Empty `Name` is auto-named by IS, but you should always set Name explicitly so summary expressions can reference `[Name]`.
- **Editor gotcha:** When changing `InputType`, the editor must call `WireTypeEvents(false)` first, swap, then `WireTypeEvents(true)` to keep change-tracking sane. In serialized form just set `InputType` to a new sub-type instance.

#### `elCheckbox`

- **Class palette:** `[Input, Checkbox, A checkbox used to get input for message.]`
- **Base:** `InputElement`
- **Source:** lines 36451–36893.

| Property | Type | Default | Editor | Notes |
|----------|------|---------|--------|-------|
| `Readonly` | bool | false | Validation / "Readonly" | |
| `Required` | bool | false | Validation / "Required" | Must be checked. |
| `CheckedValue` | string | `"True"` | General / "Value - Checked" | Saved to cache when checked. |
| `UncheckedValue` | string | `"False"` | General / "Value - Unchecked" | Saved to cache when unchecked. |
| `DefaultExpr` | ExprValue | empty | General / "Default value" | Default state expression. |
| `ForceValidation` | bool | false | General / "Force Validation" | |
| `ExcludeFromSummary` | bool | false | Summary / "Exclude from Summary" | |
| `SummaryDialable` | bool | false | Summary / "Dialable" | |
| `SummaryPageable` | bool | false | Summary / "Include in paging text" | |
| `SummaryExcludeLabel` | bool | false | Summary / "Exclude Label" | |
| `SummaryPrefix` | ExprValue | empty | Summary / "Prefix" | |
| `SummarySuffix` | ExprValue | empty | Summary / "Suffix" | |

- **Nodes:** `TabKey`, `EnterKey`; `Rules` on demand.
- **Validation:** warns (severity Warning) if either `CheckedValue` or `UncheckedValue` is blank.

#### `elList`

- **Class palette:** `[Input, List, List used to display list of values]`
- **Base:** `InputElement`
- **Implements:** `ScriptInfo.IField, ScriptInfo.IList, …, ISupportAdditionalTypes`
- **Source:** lines 37144–37718.

| Property | Type | Default | Editor | Notes |
|----------|------|---------|--------|-------|
| `BlankFirst` | bool | false | General / "Blank first entry" | Don't auto-select first item. |
| `AllowSelectingBlank` | bool | false | General / "Allow selecting blank" | Adds an empty top item the user can pick. |
| `Readonly` | bool | false | General / "Readonly" | |
| `LimitToList` | bool | false | General / "Limit to List" | Reject values not in the list. |
| `NumRows` | int | 0 | General / "Num Rows" | Visible rows (0 = drop-down). |
| `Required` | bool | false | General / "Required" | |
| `Sorted` | bool | false | (no editor attr) | Sort items alphabetically. |
| `DefaultExpr` | ExprValue | empty | General / "Default Value" | |
| `ListType` | `ListType` | `ValueListType` | General / "List Type" | One of: ValueListType, DatabaseListType, DirectoryViewListType, ScheduleListType, JsonListType. |
| `ForceValidation` | bool | false | General / "Force Validation" | |
| `ExcludeFromSummary` / `SummaryDialable` / `SummaryPageable` / `SummaryExcludeLabel` / `SummaryPrefix` / `SummarySuffix` | identical to elTextbox | | Summary group | |

- **Nodes:** `TabKey`, `EnterKey`; `Rules` on demand.
- **`ListType` sub-types**:
  - `ValueListType` — `XmlValues : string[]` and `XmlChoices : string[]` (call `SetValues(choices, values)` to set both with proper change tracking).
  - `DatabaseListType` — `Connection` (string, name of an `elDbConnection`), `CmdType` (DbLookup.CmdTypes: `Table|StoredProc|OracleStoredProc|RawSQL`), `SqlType` (DbLookup.SQLTypes: `Normal|…`), `Table` (string), `OrderByFields` / `OrderByValues` (ArrayList), `LookupMsgFields` / `LookupDbFields` / `LookupTypes` (ArrayList), `DisplayField` (string — column shown to user), `ValueField` (string — column saved to cache), `MaxRowCount` (int 0–256, default 0).
  - `DirectoryViewListType` — `ContactDefinition` (string), `MaxListings` (int, 1–300, default 64), `ShowRoles` (bool, default true), `ShowListings` (bool, default true), `RolePrefix` / `ListingPrefix` / `SearchTerm` (ExprValue), `FilterRoles` (bool).
  - `ScheduleListType` — `ContactDefinition`, `MaxListings`, `SearchTerm`, `Roles` (`ExprValue[]`).
  - `JsonListType` — JSON-source list (separate sub-type; not detailed here, but follows same ListType base).

#### `elLabel`

- **Class palette:** `[Input, Label, A label used to display inputs, calculations or other values on screen]`
- **Base:** `InputElement`
- **Source:** lines 36895–37142.

| Property | Type | Default | Editor | Notes |
|----------|------|---------|--------|-------|
| `Expr` | ExprValue | empty | General / "Expression" | The text/value the label renders. |
| `ExcludeFromSummary` | bool | **true** | Summary / "Exclude from Summary" | Labels default to NOT appearing in summary. |
| `SummaryDialable` / `SummaryPageable` / `SummaryExcludeLabel` / `SummaryPrefix` / `SummarySuffix` | as elTextbox | empty | Summary | |

- **Nodes:** none of its own (no Rules either).
- **Editor gotcha:** Set `ExcludeFromSummary = false` to make a label-rendered value appear in the auto summary; otherwise the label is purely visual on the screen.

#### `elImage`

- **Base:** `InputElement`
- **Source:** lines 35912–36164.

| Property | Type | Default | Editor | Notes |
|----------|------|---------|--------|-------|
| `ImageId` | `IdItem` | `IdItem.InvalidItem` | General / "Image" | Reference into IS image library. |
| `MaxHeight` | int | 0 | General / "MaxHeight" | Pixels (0 = natural). |
| `ExcludeFromSummary` | bool | **true** | Summary | |
| `SummaryDialable` / `SummaryPageable` / `SummaryExcludeLabel` / `SummaryPrefix` / `SummarySuffix` | as elTextbox | | | |

- **Nodes:** none.
- **Where added:** screen Display node.

#### `elButton`

- **Class palette:** `[Input, Button, Button used to perform actions on a screen.]`
- **Base:** `InputElement`
- **Source:** lines 36166–36449.

| Property | Type | Default | Editor | Notes |
|----------|------|---------|--------|-------|
| `Caption` | string | (write-only proxy) | (none) | Setter creates `CaptionExpr = new ExprValue(Text, value)`. |
| `CaptionExpr` | ExprValue | `new ExprValue(Text, name)` (button name) | General / "Caption" | The button face text. |
| `HyperLink` | bool | false | General / "Hyperlink" | Render as inline link not raised button. |
| `ForceValidation` | bool | false | General / "Force Validation" | Validate inputs before running Click actions. |
| `AssignedHotKey` | `HotKey` enum | `None` | General / "HotKey" | One of `None|Action1..Action12`. Maps onto operator F-key. |

- **Nodes:** `Click` (CalcNode); Rules on demand.
- **Where added:** screen Display node.
- **Click handler:** populate `Nodes["Click"]` with CalcElements (e.g., `elNav`, `elSetField`, `elContactDispatch`). The click runs them in order.

#### `elDatePicker`

- **Source:** lines 3963–4173.
- **Base:** `CalcElement` (despite acting like an input it's a CalcElement that pops a dialog).
- **Implements:** `ScriptInfo.IField, ISupportMessageSummary, ISupportExecute`.

Properties (selection): `FieldName` (string — field to write the chosen date to), `Format` (DateTime format string), `MinDate` / `MaxDate` (ExprValue or constant), `Title` / `Instructions` (ExprValue), summary fields (`SummaryPrefix`, `SummarySuffix`, `ExcludeFromSummary`, `SummaryDialable`, `SummaryPageable`, `SummaryExcludeLabel`).

**Where added:** Triggered as an action (button click, action group, etc.). Not placed directly on Display.

---

### Conditional / Flow Control

#### `elIfCalc`

- **Class palette:** `[Conditional, If, Do actions based on conditions]` (inferred — class has `[ElementProperty]` on attributes only on properties).
- **Base:** `CalcElement`
- **Implements:** `ISupportRuleRender, ISupportExecute, ISupportMessageSummary`
- **Source:** lines 27604–27775.

| Property | Type | Default | Notes |
|----------|------|---------|-------|
| `Conditions` | `RuleConditions` | empty | A typed list of `RuleCondition`s. Each condition has a `RuleType` enum (see RuleConditionTypes table) and its own properties. |

- **Nodes:** `True` (CalcNode), `False` (CalcNode).
- **Display name:** `If(<conditions tostring>)`.
- **Execution:** ALL conditions must evaluate true (logical AND) to take the True branch.
- **`RuleConditionTypes`**: `AdvancedRule, DayOfWeek, FieldContains, FieldEquals, Holiday, IsEmpty, Month, NewMessage, TestDrive, TimeRange`. Each has its own subclass:
  - `AdvancedRuleCondition` — `Expression` (string, an Advanced expression XML).
  - `DayOfWeekRuleCondition` — `Days` (DayOfWeek flags).
  - `FieldContainsRuleCondition` — `FieldName` (string), `Value` (ExprValue), `CaseInsensitive` (bool).
  - `FieldEqualsRuleCondition` — `FieldName`, `Value`, `CaseInsensitive`.
  - `HolidayRuleCondition` — `HolidayName` or `IsAny` (bool).
  - `IsEmptyRuleCondition` — `FieldName`.
  - `MonthRuleCondition` — `Months` (Month flags).
  - `NewMessageRuleCondition` — no params (true if message is new).
  - `TestDriveRuleCondition` — true when running in test drive.
  - `TimeRangeRuleCondition` — `Start` / `End` (TimeSpan or ExprValue).

#### `elBranchCalc`

- **Class palette:** (no class-level attribute) — palette name "Case Branch".
- **Source:** lines 25083–25407.

| Property | Type | Default | Notes |
|----------|------|---------|-------|
| `Expr` | ExprValue | empty | The expression evaluated and matched against `Values`. |
| `Values` | string[] | empty | List of values to match. Each becomes a child node named with that value as `Tag`. |

- **Nodes:** `Default` (CalcNode, tag=`Default`) plus one CalcNode per value (the node's `Name` is the value, with disambiguation suffix; node's `Tag` holds the actual value used for matching).
- **Display name:** `Case Branch(<expr>)`.
- **Editor gotcha:** When you mutate `Values`, call `CreateNodes(values)` to ensure each value has a child CalcNode. Nodes whose tag no longer appears in `Values` and that are empty are automatically pruned on render.

#### `elListCalc`

- **Class palette:** `[Conditional, List Branch, Do actions based on value of a list input]`
- **Source:** lines 27904–28208.

| Property | Type | Default | Notes |
|----------|------|---------|-------|
| `ListElement` | string | empty | Name of the `elList` (or sibling list-bearing element) whose values drive the branch. Special value `.` means "the parent list" (when `elListCalc` is nested inside an `elList` Rules). |

- **Nodes:** one CalcNode per list value; the node's `Tag` matches the list value.
- **Display name:** `List Branch(<listElement>)`.
- **Editor gotcha:** If `ListElement` is "." but the parent isn't an `elList`, validation fails. Otherwise the editor must look up the named list element on the script and create one node per value.

#### `elNav`

- **Source:** lines 28394–28540.

| Property | Type | Default | Editor | Notes |
|----------|------|---------|--------|-------|
| `NavType` | `NavTypes` enum | `Close` | General / "Navigation Type" | `Back, Forward, Cancel, Close, Screen`. |
| `NavScreen` | string | empty | General / "Screen" | Required when `NavType=Screen`; the target screen's `Name`. |
| `RemoveCall` | bool | false | General / "Remove Call" | When closing/cancelling, also remove the call from the call list. |

- **Where added:** as an action (button click, action group, etc.).
- **Validation:** when NavType is `Screen`, `NavScreen` must reference an existing screen.

#### `elPrompt`

- **Source:** lines 20426–20577.
- **Base:** `CalcElement`, implements `ISupportExecute, ISupportRuleRender`.

Properties:
- `Title` (ExprValue) — dialog title.
- `Message` (ExprValue) — body text.
- `Type` (PromptType enum: `YesNo, OkCancel, OkOnly, RetryCancel, AbortRetryIgnore, YesNoCancel`).
- `DefaultButton` (PromptResults enum).

Nodes: one CalcNode per possible response (`Yes`, `No`, `OK`, `Cancel`, `Retry`, `Abort`, `Ignore`) — only those relevant to the chosen `Type` are present.

#### `elGroupSelect`

- **Source:** lines 8703–9565.
- **Base:** CalcElement, `ISupportExecute, ISupportRuleRender`.

Pops a dialog presenting the operator with a tree of options grouped by category. Use it to ask "which type of caller is this?" or "which dispatch path?". Each group has its own child CalcNode that runs when the operator picks a member of that group.

Properties (key fields): `Title`, `Instructions`, `Width` (int), `Groups` (List of `GroupOption` — each with `Id`, `Name`, `Expand` (bool), `SelectAll` (bool), `Values` (string[])).

Nodes: one CalcNode per group + an optional `Cancel` node.

---

### Rules

Rules are RuleElement-derived; they live in a `Rules` node attached to script,
screen, or input element. They re-shape the action flow at runtime based on
context (when fired), without inflating the action tree.

#### `elRule`

- **Source:** lines 32102+.
- **Base:** `RuleElement`
- Properties: `ApplyRule` (bool), `Context` (RuleContext), `Conditions` (RuleConditions), `Description` (string), and a single child CalcNode `Actions` of CalcElements that runs when the rule's conditions match in the right context.

#### `elBranchRule`

- **Source:** lines 31590+.
- A multi-value rule: like `elBranchCalc` but in rule form. Properties: `Expr` (ExprValue) and per-value child nodes.

#### `elListRule`

- **Source:** lines 31851+.
- Like `elListCalc` but as a rule. Properties: `ListElement` (string), per-value child nodes.

`RuleContextTypes`: `Script, Screen, Button, Textbox, Checkbox, List, MergeComm` etc., with sub-mask values for the specific lifecycle event (Initialize=1, Start/Load=2, Click=1, Tab=2, etc.).

---

### Field & Subject Actions

#### `elSetField`

- **Class palette:** `[Calculation, Set Field, Calculate a value for the specified field]`
- **Source:** lines 29095–29462.
- **Base:** `CalcElement`, `ISupportMessageSummary`, `ScriptInfo.IField`, `ISupportExecute`.

| Property | Type | Default | Editor | Notes |
|----------|------|---------|--------|-------|
| `FieldName` | string | empty | General / "Field Name" | Cache key to write to. |
| `Expr` | ExprValue | empty | General / "Expression" | Value expression. Result is written to `runtime.Cache[FieldName]`. |
| `OnlyWhenBlank` | bool | false | General / "OnlyWhenBlank" | Skip if cache already has a value. |
| `SplitExpression` | bool | false | Split Expression / "Split Expression" | Treat result as delimited and pick one piece. |
| `SplitDelimiter` | string | "" | Split Expression / "Split Delimiter" | Stored as XML CData under `<Text>`. |
| `ExtractFirstItem` | bool | true | Split Expression / "Extract First Item" | When false, advance index each execution. |
| `ExcludeFromSummary` | bool | (no default in ctor — false) | (no `[ElementProperty]`) | |
| `SummaryDialable` / `SummaryPageable` / `SummaryNewLine` / `SummaryLabel` / `SummaryPrefix` / `SummarySuffix` | mixed | defaults: `SummaryNewLine=true`, others empty/false | (no `[ElementProperty]`) | If not excluded, contributes to summary. |

- **Display name:** `<FieldName> = <expr> [, Set only when blank]`.

#### `elSetSubject`

- **Source:** lines 21714–21788.
- Updates the message subject. Properties: `Subject` (string — IS subject id or name), `SetSubjectExpr` (ExprValue if expression-based), `OnlyWhenBlank` (bool), `ContactDefinition` (string when scoped to a contact).

Use this to drive what shows up in the dispatcher's "Account" column. For
sub-account scripts (per the project memory), set the subject to
`<account#> <AMR_name>` here.

#### `elSetISField` / `elSetISField2`

- **Source:** 24274 / 22731.
- Like `elSetField` but writes to an Infinity Service field (System or Client field) rather than the message cache.
- Properties on `elSetISField2`: `FieldType` (FieldTypes enum: `System|Client`), `FieldName` (string), `Expr` (ExprValue).
- `elSetISField` is a thin shim adding `IsSystemField` (bool) for older scripts.

#### `elSummary`

- **Class palette:** `[Messaging, Summary, Create a message summary]`
- **Source:** lines 29464–29550.
- Sets `runtime.Message.Summary` to the evaluated `Expr`.

| Property | Type | Default | Editor | Notes |
|----------|------|---------|--------|-------|
| `Expr` | ExprValue | `new ExprValue(Advanced, "")` | General / "Expression" | The summary text expression. Almost always Advanced. |

#### `elSaveMessage`

- **Source:** lines 24016–24144.
- Saves the in-progress message (e.g., partial-save mid-script).

| Property | Type | Default | Notes |
|----------|------|---------|-------|
| `Expr` | ExprValue | empty | Message text. |
| `ClientNumber` (write-proxy) / `ClientNumberExpr` | ExprValue | empty | Account number; if blank, current. |
| `Urgent` | bool | false | Mark as urgent. |

#### `elSaveISMessage`

- **Source:** lines 12348–12513.
- Saves a message into Infinity Services (creates/updates an IS-tracked message).
- Properties: `Recipient` (string subject id or `ExprValue`), `Subject` (ExprValue), `Body` (ExprValue), `Priority` (Priorities enum), `MarkComplete` (bool).

#### `elSaveISSpecial` / `elSearchISSpecial`

- Source 6677 / 6295.
- Manage IS "special" flags / categories on a message.

#### `elSaveCallTrackerEvent`

- **Source:** lines 4174–4302.
- Logs an event into the call-tracker for reporting/analytics.
- Properties: `EventType` (`ScriptTrackerTypes` enum), `Description` (ExprValue).

#### `elSaveKeyword`

- **Source:** lines 14335–14443.
- Tags the call with a keyword for search.
- Properties: `KeywordExpr` (ExprValue).

#### `elSaveHistory` / `elGetHistory`

- Save: lines 24146; Get: lines 13387.
- `elSaveHistory`: `Expr` (ExprValue), `ShouldSaveHistoryForContact` (bool), `ContactDefinition` (string).
- `elGetHistory`: `HistoryType`, filter and target field properties.

---

### Action Groups & Tables

#### `elActionGroup`

- **Class palette:** `[Actions, Action Group, Groups a list of actions.]`
- **Base:** `SharedActionsElement`
- **Source:** lines 17900–17974.
- Defines a named, reusable sequence of CalcElement actions.

| Property | Type | Default | Notes |
|----------|------|---------|-------|
| `OnlyOnce` | bool | false | If true, the runtime executes the group at most once per script run. |

- **Nodes:** `Actions` (CalcNode) — the action sequence.
- **Where added:** `Shared` node of `elScript` or `elScreen`.
- Reference at run-time via `elDoActions` (which selects an action group by name).

#### `elActionTable`

- **Class palette:** `[Actions, ActionTable, Defines a multi-step action table]`
- **Source:** lines 17679–17898.

| Property | Type | Default | Notes |
|----------|------|---------|-------|
| `Conditions` | `Condition[]` | `[Condition("default")]` | Column headers — each step then has one CalcNode per condition. |

- **Nodes:** `Steps` (ActionTableStepNode) — contains `elActionTableStep` rows.
- Used for matrix logic ("for each step, when condition X, do Y").

#### `elActionTableStep`

- **Source:** lines 17548–17677.
- A row in an `elActionTable`. Synced to its parent's `Conditions` array — has one CalcNode per condition.
- **Where added:** `Steps` node of an `elActionTable`.
- **Editor gotcha:** When you add/rename/remove a Condition on the parent table, every step's nodes must be re-synced. The runtime's `SyncConditions(...)` does this automatically — the editor just sets `Conditions` on the table.

#### `elDoActions`

- **Source:** lines 13663–13767.
- Invokes a named action group.
- Properties: `ActionGroup` (string — the name of an `elActionGroup`).

#### `elDoTableActions`

- **Source:** lines 13144–13386.
- Invokes a step+condition pair on an `elActionTable`.
- Properties: `ActionTable` (string), `Step` (string), `Condition` (string or ExprValue depending on flow), `OnlyOnce` (bool).

#### `elSelectTableStep` / `elSkipTableStep`

- Source 12903 / 13046.
- Used inside `elActionTable` evaluation to pick or skip rows.

---

### Messaging — direct delivery

#### `elEmail`

- **Source:** lines 19781–20027.

| Property | Type | Default | Notes |
|----------|------|---------|-------|
| `Subject` | ExprValue | empty | |
| `Message` | ExprValue | empty | |
| `Recipient` | ExprValue | empty | The destination address. Required. |
| `SendInTestDrive` | bool | false | If true, even test-drive runs send the email. |
| `ServerConfiguration` | `EmailServerConfig` | new | Bundle of `AccountType` (`ScriptEmailAccountTypes`: `Default, ClientSpecific, ScriptSpecific, AgentSpecific, Smtp, Legacy`), `ServerName`, `Port`, `UseTls`, `Login`, `Password`, `FromAddress`, `FromDisplayName`, `SmtpAcc`. |
| `DeliverOption` | `EmailDeliverOptions` flags | None | `Required, Reply, ReplyAll, Read, Forward`. |
| `ReplyTextMatch` | ExprValue | empty | Regex/text pattern for matching replies. |
| `ReplyAction` | `EmailReplyActions` flags | None | What to do on reply: `OneCall, Resend, Print, Reply, Forward, Voicemail`. |
| `ReplyTimeout` | int | 180 | Seconds to wait for reply. |
| `SendVoice` | bool | false | Also dispatch a voice notification. |

- **Validation:** Recipient, Subject, Message all must be non-empty.
- **Per project memory:** for AMR-derived generic addresses (e.g., a clinic's main inbox) use plain `elEmail`; for individual people in a contact roster, use `elContactSendEmail`.

#### `elSms`

- **Source:** lines 12045–12261.
- Properties: `Recipient` (ExprValue, phone), `Message` (ExprValue), `DeliverOption` (`SmsDeliverOptions`), `ReplyAction` (`SmsReplyActions`), `ReplyTimeout`, `SendInTestDrive`.

#### `elSendPage`

- **Source:** lines 5046–5251.
- Properties: `Recipient`, `Message`, `DeliverOption` (`PageDeliverOptions`), `ReplyAction` (`PageReplyActions`), `ReplyTimeout`, `Priority`.

#### `elFax`

- **Source:** lines 10828–11052.
- Properties: `Recipient`, `Subject`, `Body` (ExprValue), `Cover` (bool), `DeliverOption` (`FaxDeliverOptions`), `ReplyAction` (`FaxReplyActions`).

#### `elWctpPage`

- **Source:** lines 5296–5485.
- WCTP (Wireless Communications Transfer Protocol) pager dispatch.

#### `elCiscoMessage` / `elVocera` / `elMsm`

- Cisco IP-phone, Vocera badge, MSM mobile pager messages. Each has the standard `Recipient`, `Subject`/`Message` (ExprValue), `DeliverOptions`, `ReplyActions`, `ReplyTimeout`. Cisco and Vocera also expose `Priority` (`CiscoReplyActions` / `VoceraPriority`).

#### `elSendHL7Message`

- **Source:** lines 8492–8702.
- Sends an HL7-formatted message to a configured HL7 endpoint.
- Properties: `Endpoint` (string — name of HL7 connection), `MessageType` (string), `Body` (ExprValue), `WaitForAck` (bool), `Timeout` (int).

#### `elSendMessages`

- **Source:** lines 14445–17547 (large element).
- Batch send: collects multiple destinations and channels and dispatches in one go. Holds a list of recipients with per-recipient overrides for method, message text, etc. Used for "send to entire on-call group right now" flows.

#### `elDeliverMessage`

- **Source:** lines 24324–24453.
- Marks the current in-progress message as Delivered (the IS "delivered" state).

| Property | Type | Default | Notes |
|----------|------|---------|-------|
| `DeliverTo` | ExprValue | `Text("")` | Free-text string showing who/what was delivered to (logged on the message). |
| `ShouldDeliverForContact` | bool | false | If true, mark delivery against a specific contact. |
| `ContactDefinition` | string | empty | Required when ShouldDeliverForContact. |

#### `elUndeliverMessage`

- **Source:** lines 9566–10004.
- Reverses delivery on a previously-delivered message.

#### `elMarkMessageSent`

- **Source:** lines 5252–5295.
- Marks the current message as "sent" (i.e., dispatch attempt completed).

---

### Messaging — contact-aware delivery

All `elContactSend*` elements derive from `elContactSendBase` and inherit:

- `ContactDefinition` (string) — name of an `elScript.Contacts` entry.
- `MethodName` (ExprValue) — name of the method to match.
- `SelectMethodUsing` (`SelectMethodBy` enum: `Prompt, MatchAnyPart, MatchEntireString, CurrentStatus, SpecifiedStatus`).
- `MethodTextExpression` (ExprValue) — body text of the message.
- `StatusNameExpression` (ExprValue) — used when `SelectMethodUsing=SpecifiedStatus`.
- `DispositionExpression` (ExprValue) — text saved as the message disposition.
- `StampDisposition` (bool, default true) — append the dispatch stamp to the disposition.
- `SendToAll` (bool, default false) — when multiple methods match, send to all.

Common nodes: `NoMethodFound` (CalcNode) — runs when contact has no method matching the criteria.

#### `elContactSendEmail` (line 18117)
Adds: `Subject` (ExprValue), `DeliverOption` (`EmailDeliverOptions`), `ReplyAction` (`EmailReplyActions`), `ReplyTimeout` (int, default 180), `ReplyTextMatch` (ExprValue), `SendVoice` (bool), `DeliverMessageOnSuccess` (bool? — when true, also marks message delivered after send).

#### `elContactSendSms` (line 18754)
Adds: `DeliverOption` (`SmsDeliverOptions`), `ReplyAction` (`SmsReplyActions`), `ReplyTimeout`, `ReplyTextMatch`.

#### `elContactSendPage` (line 18271)
Adds: `DeliverOption` (`PageDeliverOptions`), `ReplyAction` (`PageReplyActions`), `ReplyTimeout`, `Priority`, `ReplyTextMatch`.

#### `elContactSendFax` (line 11053)
Adds: `Subject` (ExprValue), `DeliverOption` (`FaxDeliverOptions`), `ReplyAction` (`FaxReplyActions`), `Cover` (bool).

#### `elContactSendVocera` (line 10005)
Adds: `Priority` (`VoceraPriority`), `DeliverOption` (`VoceraDeliverOptions`), `ReplyAction` (`VoceraReplyActions`), `ReplyTimeout`, `Type` (`VoceraTypes`: `Person, Group, Address Book`).

#### `elContactSendCisco` (line 7303)
Adds: `Priority`, `DeliverOption` (`CiscoDeliverOptions`), `ReplyAction` (`CiscoReplyActions`), `ReplyTimeout`.

#### `elContactSendSecureMessage` (line 17976)
Adds: `From` (ExprValue), `Subject` (ExprValue), `Priority` (`SecureMessagingPriority`), `DeliverOptions` (`SecureMessagingDeliverOptions`), `ReplyAction` (`SecureMessagingReplyActions`), `ReplyTimeout` (int, default 180).

**Per project memory:** `elContactSend*` family is the right choice when the
recipient is a person in the AMR roster with multiple methods (mobile, work
email, pager). Plain `elEmail` / `elSms` etc. are for static/generic destinations.

---

### Contact Selection & Dispatch

#### `elSelectContact`

- **Source:** lines 23563–24015.
- **Base:** `elSelectContactBase`
- Pops a picker for the operator to select one contact from a contact definition.

| Property | Type | Notes |
|----------|------|-------|
| `ContactDefinition` | string | Contact roster name. |
| `SearchTerm` | ExprValue | Initial search filter. |
| `MaxListings` | int | Max rows shown. |
| `Roles` | `ExprValue[]` | Filter to specific roles. |
| `AllowMulti` | bool | Allow multi-select. |
| `Required` | bool | Operator must pick. |

Nodes: `Selected` (CalcNode — runs when contact selected), `NoSelection` (CalcNode).

#### `elForEachContact`

- **Source:** lines 18879–18954.
- **Base:** `elSelectContactBase`
- Iterates over matching contacts running its body actions for each.
- Properties: same as `elSelectContact` plus `MaxIterations`.
- Nodes: `Body` (CalcNode — runs per contact), `Empty` (CalcNode — runs if zero contacts).

#### `elContactDispatch`

- **Source:** lines 33263–35911 (large class).
- **Base:** `CalcElement`, `ISupportExecute, ISupportRuleRender`.
- The workhorse for "page the on-call doctor with this message".

Properties (selection — full list spans 100+ lines):
- `ContactDefinition` (string) — required.
- `Subject` / `Message` (ExprValue) — text.
- `MethodFilter` (`ContactMethods` flags) — restrict to certain channels.
- `Priority` (`DispatchPriorities`: `None, Low, Normal, High`).
- `ColorCode` (`DispatchListColors` enum).
- `WaitForReply` (bool) plus reply-action and timeout fields per channel.
- `EscalateOnTimeout` (bool) and an escalation table.
- `DeliverMessageOnSuccess` (bool).
- `StampDisposition` (bool).
- `DispositionExpression` (ExprValue).

Nodes: `Success`, `Failure`, `NoMethodFound`, `Cancelled` (each a CalcNode that runs in the corresponding outcome).

#### `elSendToDispatcher`

- **Source:** lines 24455–25082.

| Property | Type | Default | Notes |
|----------|------|---------|-------|
| `DispatchGroup` | int | -1 (INVALID) | 0 = any group; 1–64 = specific dispatch group. |
| `Priority` | `DispatchPriorities` | Normal | |
| `Color` | `DispatchListColors` | Default | |
| `MessageBody` | ExprValue | empty | |
| `Title` | ExprValue | empty | |

Posts a row into the dispatcher queue (the dispatch console handles it from there).

#### `elSelectContact` related: `elContactMethods`, `elResolveContactOverride`, `elContactRoleUpdate`

- `elContactMethods` (line 11116) — fetches the list of methods for a contact and populates fields. Properties: `ContactDefinition`, `OutputFields` (mapping of method name → field).
- `elResolveContactOverride` (line 8354) — applies temporary contact overrides (vacation cover, etc.).
- `elContactRoleUpdate` (line 4303) — updates a contact's role assignments at runtime.

#### `elAutoDispatch` / `elHandleDispatch`

- `elAutoDispatch` (line 7460) — automatically dispatches based on rules without operator intervention.
- `elHandleDispatch` (line 5486) — accept/handle an incoming dispatch event.

---

### Database & Web

#### `elDbConnection`

- **Class palette:** `[Database, Connection, …]` (gated by PayFors.Database).
- **Source:** lines 25491–25832.

| Property | Type | Default | Editor | Notes |
|----------|------|---------|--------|-------|
| `ConnectionName` | string | empty | General / "Connection Name" | Name used to reference this connection from `elDbLookup`/`elDbSave` etc. |
| `Type` | `ConnectionTypes` | `SQL` | General / "Connection Type" | `SQL, Access, Oracle, AdvancedOLEDB`. |
| `DatabaseExpr` | ExprValue | (text) | General / "Database" | Name of database/file. |
| `ServerExpr` | ExprValue | (text) | General / "Server Name" | DB server hostname. |
| `LoginExpr` | ExprValue | (text) | | Username (Integrated Security if blank). |
| `PasswordExpr` | ExprValue | (text) | | Password. |
| `AdvancedExpr` | ExprValue | (text) | General / "Advanced" | Full OLEDB connection string when `Type=AdvancedOLEDB`. |

Note: there are duplicate string-typed properties (`Database`, `ServerName`, `Login`, `Password`, `AdvancedConnectionString`) that are write-only proxies for the ExprValue versions — older XML-serialized scripts populate these and the setters convert to ExprValue. New scripts should set the `*Expr` versions.

- **Where added:** ONLY `elScript.Nodes["Shared"]` (NOT screen Shared).
- **Display name:** `Db Connection(<name>)`.

#### `elDbLookupSingle`

- **Class palette:** `[Database, Lookup, Perform a database lookup, …]`
- **Source:** lines 26230–26305.
- Inherits `elDbLookup` (abstract) properties: `Connection` (string — name of `elDbConnection`), `CmdType` (`DbLookup.CmdTypes`: `Table, StoredProc, OracleStoredProc, RawSQL`), `SqlType`, `Table` (string), `Disabled` (bool), `OrderByFields/Values` (ArrayList), `LookupMsgFields/DbFields/Types` (filter parameter mapping ArrayLists), `LookupOracleTypes/Direction` (Oracle stored proc params), `MsgFields/DbFields` (output field mapping — DbField column → MsgField cache slot).
- Adds: `DefaultFields` (ArrayList of default values matching `MsgFields` length).
- Performs a single-row lookup and writes results into the cache.

#### `elDbPicklist`

- **Class palette:** `[Database, Picklist, Perform database lookup, showing results in picklist]`
- **Source:** lines 26307–26566.
- Inherits `elDbLookup` properties plus:

| Property | Type | Default | Notes |
|----------|------|---------|-------|
| `ListCaptions` | ArrayList of string | empty | Captions for each output column. **Validation requires at least one non-empty value.** |
| `SingleResultClosesList` | bool | false | If only one row matched, skip the picker and just populate. |
| `MaxDisplayRows` | int | 32 | 0–32 (hard ceiling). |
| `DisplayDetailField` | string | empty | Column shown as a detail panel. |
| `PicklistInstructions` | string | empty | Header text in the picker dialog. |

- **Validation gotchas:** `ListCaptions.Count` must equal `MsgFields.Count`; at least one caption must be non-empty. Otherwise validation fails. (This bites in script generators if you forget to populate ListCaptions in lockstep with the field mapping.)

#### `elDbActionlist`

- **Source:** lines 10423–10827.
- Like `elDbLookupSingle` but iterates: runs a child action node once per result row.
- Nodes: `Body` (CalcNode).

#### `elDbSave`

- **Class palette:** `[Database, Save, Save messages fields to database]`
- **Source:** lines 26568–26846.

| Property | Type | Default | Notes |
|----------|------|---------|-------|
| `Connection` | string | empty | Name of `elDbConnection`. |
| `TableName` | string | empty | |
| `MsgFieldsList` / `DbFieldsList` | ArrayList | empty | Parallel arrays mapping cache field → DB column. |
| `MsgLookupField` / `DbLookupField` | string | empty | When `UpdateDb=true`, used to find the row to update. |
| `UpdateDb` | bool | false | Update vs insert. |
| `Disabled` | bool | false | Skip execution. |
| `TextField` | bool | false | Treat the lookup field as text (string-quoted in SQL). |

#### `elWeb`

- **Class palette:** `[Links, Web, Open a URL]`
- **Source:** lines 29832–29990.

| Property | Type | Default | Editor | Notes |
|----------|------|---------|--------|-------|
| `URLExpression` | ExprValue | empty | General / "URL Expression" | Target URL. |
| `UseExternalBrowser` | bool | false | General / "UseExternalBrowser" | Open in OS default browser instead of embedded panel. |
| `WebPageFields` / `MessageFields` | ArrayList | empty | (no editor attr) | Parallel mapping for URL parameters. |

#### `elWebService`

- **Source:** lines 12262–12347.
- **Base:** `elWebServiceBase`.
- Calls a SOAP/REST web service. Properties (on base): `EndpointURL` (ExprValue), `Method` (string), `RequestBody` (ExprValue), `RequestType` (`SOAP|REST`), `ResultField` (string), `Disabled` (bool).

#### `elWebServicePicklist`

- **Source:** lines 13769–14334.
- Like `elWebService` but pops a picker on results.

#### `elParseJson` / `elParseXml` / `elTransformJson`

- `elParseJson` (line 6910), `elParseXml` (line 11869), `elTransformJson` (line 4455).
- Parse/transform JSON or XML payloads into message fields.
- Common properties: `SourceExpr` (ExprValue — the JSON/XML to parse), `FieldMappings` (list of `JsonPath`/`XPath` → cache field name), `OnError` (CalcNode).

---

### Contact / CRM (CMI)

CMI = Contact Management Integration — Amtelco's CRM bridge. All these
elements are gated by `PayFors.CMI`.

#### `elCMIConnection`

- **Source:** lines 30317–30508.
- Defines a CMI server endpoint. Properties: `ConnectionName`, `Server`, `Login`, `Password`, `Database`.
- Lives in `elScript.Nodes["Shared"]` like `elDbConnection`.

#### `elCMIGetUser`

- **Source:** lines 21789–21881.
- Looks up a user record. Properties: `Connection` (CMI conn name), `LookupBy` (enum), `LookupValue` (ExprValue), `OutputFields` (mapping).

#### `elCMISelectContact` / `elCMIAddContact` / `elCMIContactData`

- 30175 / 22556 / 22315.
- Pick, create, or read CMI contact data.
- Properties: `Connection`, `ContactId` (ExprValue), `Fields` (mapping), `OutputFields` (mapping).

#### `elCMISelectCompany` / `elCMIAddCompany` / `elCMICompanyData`

- 22173 / 22400 / 30090.
- Same shape as the contact equivalents but for companies.

#### `elCMIScheduleActivity` / `elCMIRecordActivity`

- 21882 / 22028.
- Schedule or log a CMI activity (call, meeting, follow-up).
- Properties: `Connection`, `ActivityType`, `Subject`, `DueDate` (ExprValue), `AssignedTo`, `Notes`.

#### `elCMIGetSessionSummary`

- **Source:** lines 20648–20734.
- Retrieves a CMI session summary text.

#### `elContactCreate` / `elContactUpdate`

- 20820 / 20735.
- **Base:** `elContactSave` (abstract, line 21325).
- Create or update a non-CMI Amtelco contact (in the IS Directory).
- Properties: `ContactDefinition`, `FieldMappings` (cache field → contact field), `MethodMappings` (set methods).

#### `elContactDetails`

- **Source:** lines 21540–21636.
- Shows a contact details panel to the operator.
- Properties: `ContactDefinition`.

---

### Appointments

All `elAppt*` elements live in `Calculation` palette and are gated on
appointment licensing. They follow a consistent shape: select an appointment
or schedule and either manipulate it or pull data from it.

#### `elApptCreate` (line 2531)
Properties: `Schedule` (ExprValue — schedule id), `StartDateTime`, `EndDateTime` (ExprValue), `Subject`, `Notes`, `ContactDefinition` (optional contact link), `OutputApptIdField` (string — cache field to write new id to).

#### `elApptSchedule` (line 3166)
Pops the appointment scheduler dialog for the operator. Properties: `Schedule`, `Title`, `Instructions`, `OnSuccess` / `OnCancel` nodes.

#### `elApptReschedule` (line 2150)
Properties: `Schedule`, `ApptId` (ExprValue), `NewStartDateTime`, `NewEndDateTime`.

#### `elApptCancel` (line 2409)
Properties: `Schedule`, `ApptId`, `Reason` (ExprValue).

#### `elApptDelete` (line 2332)
Properties: `Schedule`, `ApptId`. Hard delete.

#### `elApptLookup` (line 2848)
Searches for an appointment. Properties: `Schedule`, `SearchCriteria` (multiple ExprValue filters), `OutputFields` (mapping).

---

### HL7 / Integration / MergeComm

#### `elSendHL7Message` (line 8492)
See "Messaging — direct delivery". Sends an HL7 message to a registered endpoint.

#### `elMergeCommJob` (line 7886)
- Schedules a MergeComm (automated outbound) job. Properties: `JobType` (string), `Recipients` (ExprValue or list), `Body` (ExprValue), `When` (DateTime ExprValue or "now"), `Recurrence` (`ShiftRecurrenceInterval`).
- **Where added:** `Complete` or `MergeComm` nodes typically.

#### `elMergeCommBranch` (line 8143)
- Branches the script depending on whether it's running in interactive vs MergeComm context. Two CalcNode children: `Interactive`, `MergeComm`.

#### `elAutomatedCalc` (line 8270)
- Performs a calculation only in the MergeComm/automated path.

---

### System / Telephony

#### `elHangup` (line 27486)
Ends the call. No properties.

#### `elDial` (line 26848)
- `Expr` (ExprValue) — the number to dial. `StripFormatting` (bool, default true).

#### `elDialWithType` (line 23022)
- `Expr` (ExprValue), `DialType` (`DialType` enum: `Voice|Fax|Pager|SMS|External|AgentExtension`), `StripFormatting` (bool).

#### `elPark` (line 28722)
- `ParkToGroup` (bool, default true), `Group` (int, default -1=op-select), `Station` (int, default -1=op-select), `ResumeScreen` (string), `ResumeMode` (string).

#### `elPark2` (line 4811)
- Newer richer park element. Properties include: `ParkType` (`ParkToTypes`), `Target` (ExprValue), `ResumeScreen`, `ResumeMode`, `TerminateScript` (bool), `Disposition` (ExprValue).

#### `elParkOrbit` (line 21637)
- Parks on a specific orbit number; writes orbit id to a field. Properties: `OrbitField` (string), `Disposition` (ExprValue).

#### `elPause` (line 4654)
- `Seconds` (int) — pauses script execution.

#### `elPacify` (line 4697)
- Plays hold tone / pacify message. Properties: `Title` (string), `Instructions` (string).

#### `elShell` (line 20338)
- `Command` (ExprValue), `Arguments` (ExprValue), `WaitForExit` (bool), `Hidden` (bool).

#### `elToggleHold` / `elToggleVoice` / `elToggleRecording` / `elToggleOnOff`
- Each takes `ToggleValue` (`ToggleValues`: `On, Off, Toggle`) and forwards to the ACD.

#### `elRevertLive` / `elRevertNew`
- No properties; tell the ACD to revert all live or all new calls to the operator.

#### `elScreenMode` (line 28971)
- `Mode` (string) — change the current screen's mode.

#### `elChangeAccount` (line 25409)
- `Expr` (ExprValue, must evaluate to 1–10 digit account number) — switch active account mid-script.

#### `elTransferToVM` (line 20029)
- No properties; transfer the call to voicemail and close the script.

#### `elBringToForeground` (line 11548)
- No data-bearing properties; brings the IS Supervisor window to focus.

#### `elRemoveCall` (line 6871)
- Terminates the current call from the operator's call list.

#### `elHoldMessage` (line 27527)
- `MessageField` (string), `Duration` (int seconds) — places a "held message" reminder.

---

### History, Search, Special

#### `elGetHistory` (line 13387)
Fetches caller/account history into fields. Properties: `HistoryType` (`HistoryType`: `Caller|Account|Field`), `MaxResults`, `OutputField` mapping.

#### `elSaveHistory` (line 24146) — see Field & Subject Actions.

#### `elSearchISSpecial` (line 6295)
Search for IS special-flag messages.

#### `elSaveISSpecial` (line 6677)
Set an IS special flag on a message.

#### `elSaveISMessage` (line 12348)
Save a message into IS (creates a stored message).

#### `elDispatchTypes` (line 27305)
Returns dispatch types into fields.

#### `elDirectory` (line 26936)
Open the IS directory dialog. Properties: `SubjectId` (int), `ViewId` (int), `CloseOnSingle` (bool), `MultiSearch` (bool), `SearchDirectoryFields/MessageFields` (mapping for search inputs), `CopyDirectoryFields/MessageFields` (mapping for result outputs).

#### `elInfoPage` (line 27777)
Show a configured info page. Properties: `ClientNumber` (long), `InfoPage` (int — page id).

#### `elMapPoint` (line 28209)
Show a location on a map. Properties: `Address`/`Latitude`/`Longitude` (ExprValue).

#### `elOnCall` (line 28542)
Look up an on-call schedule. Properties: `SubSchedule` (string), `OnCallTimeField` (string — cache field with target time), `OnCallFields`/`MessageFields` (parallel mapping of schedule columns → cache fields), `Rank` (int, -1=all).

#### `elViewSchedule` (line 20882)
Show schedule view dialog. Properties: `ScheduleName` (string), `ViewType` (`ViewScheduleViewTypes`: `Day|Week|Month|Custom`), `Editable` (bool), `ContactDefinition` (optional).

#### `elShowSchedule` (line 22887)
Embed a schedule on screen Display. Properties: `ScheduleName`, `ViewType`, `Height`.

---

### Class Registration

#### `elClassRegRegister` (line 3423)
Register a caller for a class/event. Base: `elClassRegRegisterBase` (line 3344).
Properties: `RegSession` (string), `RegFields` (ArrayList — registration fields), `MsgFields` (ArrayList).

#### `elClassRegUnregister` (line 3288)
Remove a registration.

#### `elClassRegLookup` (line 3480)
Find existing registrations.

---

### Misc Specialized

#### `elCreditCard` (line 12514)
Pops the credit card capture dialog. Properties: `Title`, `Instructions`, `OutputFields` (PAN, exp, CVV, name, etc.). Heavily PCI-influenced; in test drive the values are scrubbed.

#### `elSandboxSearch` (line 20164)
Internal sandbox search element used by the editor for testing — typically not needed in production scripts.

#### `elScriptComment` (line 20068)
Documentation. `Comment` (string). No runtime effect.

#### `elSelectElement` (line 20579)
Pops a picker that lets the operator select a script element by name; writes the selected element's name to a field. Properties: `OutputField` (string), `ElementType` filter.

#### `elUrgent` (line 29753)
Marks the message as urgent. Optionally toggles via `Toggle` (bool).

---

## Implementation Notes for Editor Authors

### Property serialization conventions

- ExprValue: emitted only when not empty (because every element implements
  `ShouldSerializeXxxExpr` returning `!IsEmpty`). The JSON shape is
  `{ "Type": <enum int>, "ExpressionText": "..." }`.
- `ArrayList` properties (the legacy mapping-tables — DbFields/MsgFields etc.)
  serialize as JSON arrays of strings.
- `bool` defaults documented above are what the parameterless ctor sets — the
  editor should respect these as "the unsaved default" and never emit them
  to the file when they match.
- `Image`, `_logger`, `_img` private fields are not serialized; they are
  pure runtime helpers.

### Naming rules

- `Name` on every element is its identifier within its parent node. Must be
  unique within the node. Empty Name is allowed at parse time but auto-named
  by IS — emit explicit names to keep your generated scripts deterministic.
- For `IField` implementers (textbox, list, checkbox, datepicker, setField,
  parkOrbit), `Name` doubles as the cache field name and is referenced in
  ExprValues with `Type=Field`.
- Screen names matter for `elNav.NavScreen` and `elPark.ResumeScreen`.

### Node ordering

Within `Nodes`, order matters for two reasons:

1. The script tree displays nodes in their stored order.
2. Within an action node (CalcNode), elements execute in stored order.

Do not re-order Initialize/Start/Screens/Complete/MergeComm on `elScript` —
those positions are baked into a lot of editor logic. Within `Screens`, screen
order defines navigation order.

### Where the editor writes JSON vs XML

- Modern IS Supervisor and the runtime read JSON-form scripts (NewtonSoft).
  All `[JsonIgnore]` properties are excluded from JSON.
- The XML form is legacy but still parsed for older scripts. Properties tagged
  `[XmlIgnore]` are excluded from XML.
- ExprValue's `XmlPersistance` getter/setter handles round-tripping into the
  XML form when needed.

### Validation expectations

Every element has a `Validate(Validator)` method. The editor invokes this on
save and shows errors in a panel. Common patterns:

- `validator.AssertNonEmpty(x, "msg")` — error if blank.
- `validator.ValidateMessageField(name, "msg", allowBlank=false)` — error if
  not a known cache field name.
- `validator.ValidateConnection(connName)` — error if no `elDbConnection`
  with that name lives in `elScript.Shared`.
- `validator.AssertContactDefinition(name, "msg")` — error if not in
  `elScript.Contacts`.
- `validator.ValidateIsValidScreen(name)` — error if no `elScreen` with
  that name.
- `validator.AssertContactDefinition` returns bool — chains of validations
  short-circuit on failure (see `elContactSendBase.Validate`).

### Building a hub-and-spoke script (per project pattern)

Per the project memory, NxtScripts in this migration follow a hub-and-spoke
pattern: screen 1 is a call-type selector with one button per call type, and
each button click navigates to a dedicated per-call-type screen.

The minimal element graph for that:

```
elScript
├── Shared:
│   ├── elDbConnection (if doing DB lookups)
│   └── elActionGroup "deliverDailyLog" (shared dispatch logic)
├── Initialize: (system fields → cache; load contacts)
├── Start: (any per-call-type detection)
├── Screens:
│   ├── elScreen "selector"
│   │   └── Display:
│   │       ├── elLabel "Choose call type"
│   │       ├── elButton "general" → Click: [elNav target=screen "general"]
│   │       ├── elButton "afterHours" → Click: [elNav target=screen "afterHours"]
│   │       └── elButton "emergency" → Click: [elNav target=screen "emergency"]
│   ├── elScreen "general" (one screen per call type)
│   │   └── Display: [textbox "callerName", textbox "callbackPhone", memo "message", elButton "Save" → Click: [elSummary, elNav Forward]]
│   ├── elScreen "afterHours" (similar)
│   └── elScreen "emergency" (with elContactDispatch)
└── Complete: (no-op or final delivery)
```

Each per-call-type screen is independent — easier to reason about and modify
than a single mega-screen with conditional rules.

---

## Source File Index

- Script container, screen, rule classes: `NxtScript.Elements.decompiled.cs:30509–32485`.
- Input element classes: `NxtScript.Elements.decompiled.cs:35912–38322`.
- TextboxType variants: `NxtScript.Elements.decompiled.cs:39684–42305`.
- ListType variants: `NxtScript.Elements.decompiled.cs:38323–39683`.
- Action / messaging / dispatch elements: scattered through 2150–35911 (see line index).
- `ExprValue` / `Symbols` / `DbLookup`: `NxtScript.decompiled.cs:12153–14880`.
- All enums (NavTypes, DispatchPriorities, MethodTypes, etc.): `NxtScript.decompiled.cs` lines 14111+, 15879+, 16025+, 16321+, 17601+, 17981+, 18108+, 18233+, 19794+.

For any element whose decompiled body you need to inspect verbatim, the
class-line index from `grep -n "^\s*public class el"` on
`NxtScript.Elements.decompiled.cs` is the canonical lookup.
