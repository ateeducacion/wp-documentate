# Aggregated providers with nested conceptos (propuesta de gasto)

`fixtures/propuestagasto.odt` supports **repeatable providers**: a propuesta de
gasto can carry several servicios, suministros and expertos, and each provider
renders as its own table with its own conceptos rows.

## How the template is structured

Each kind is an **explicit TinyButStrong block** wrapped around its table, so
everything between the markers — provider data, conceptos rows **and the
totals** — visibly belongs to one provider:

```text
[servicios;block=begin;sub1=conceptos]
  ┌──────────────────────────────────────────────────────┐
  │ [servicios.proveedor]  [servicios.cif]               │
  │ [servicios.email]      [servicios.telefono]          │
  │ ──────────────────────────────────────────────────── │
  │ [servicios_sub1.concepto;block=tbs:row]              │  ← one row per concepto
  │ [servicios_sub1.cantidad] [servicios_sub1.unitario]  │
  │ [servicios_sub1.total]                               │
  │ ──────────────────────────────────────────────────── │
  │ Bruto [servicios.bruto]   IGIC [servicios.igic]      │  ← totals live INSIDE
  │ IRPF  [servicios.irpf]    Total [servicios.total]    │    the servicios block
  └──────────────────────────────────────────────────────┘
[servicios;block=end]
```

The same pattern applies to `suministros` and `expertos`.

Two building blocks, both native TinyButStrong (verified against the vendored
library — `sub1=` is resolved in `tbs_class.php`, see `AutoSub`):

- `[name;block=begin] … [name;block=end]` — an explicit block: the whole slice
  (one provider's table) repeats once per provider record. The begin/end
  markers make the grouping legible in the document itself, and the schema
  extractor models explicit blocks as repeaters.
- `sub1=conceptos` on the block definition — a TBS *automatic sub-block*: for
  every provider record, the sub-block named `servicios_sub1` is merged with
  that record's `conceptos` array. The concepto row keeps its ordinary
  `block=tbs:row` repeater.

Visibility wrappers key on the block itself — `[onshow;block=begin;
bloc=servicios] … [onshow;block=end]` hides a whole section when the document
carries no provider of that kind (an empty rows array counts as no data).

Two fields deliberately stay **document-level scalars** because they live
outside the tables (footnote paragraphs): `servicios_igic_exento` and
`suministros_igic_exento`.

## How the plugin handles it

- **Schema** (`SchemaExtractor`): an explicit block with `subN=<key>` produces
  ONE repeater whose fields include a nested entry of type `array` named after
  the data key (`conceptos`), carrying the sub-block's columns. The
  `<name>_subN.*` fields never surface as a separate top-level repeater.
  `SchemaConverter` maps that nested entry into the legacy `item_schema` as a
  `type: array` item with its own `item_schema`.
- **Editor**: each parent row renders its nested repeater as an inner
  add/remove list (`documentate-annexes.js`); inputs post as
  `tpl_fields[servicios][0][conceptos][1][concepto]`.
- **Storage**: rows are stored as JSON with the nested arrays embedded;
  sanitization recurses into the nested item schema.
- **Generation**: no special casing — `MergeBlock( 'servicios', $rows )` where
  each row embeds `'conceptos' => array( … )` and TBS fills the sub-block:

```php
$tbs->MergeBlock( 'servicios', array(
    array(
        'proveedor' => '…', 'cif' => '…', 'email' => '…', 'telefono' => '…',
        'bruto' => '…', 'igic' => '…', 'irpf' => '…', 'total' => '…',
        'conceptos' => array(
            array( 'concepto' => '…', 'cantidad' => '…', 'unitario' => '…', 'total' => '…' ),
        ),
    ),
    // … one entry per provider: one table per provider in the document.
) );
```

Nesting is one level deep by design (block → sub-block); TBS `sub2=`, `sub3=`…
would follow the same pattern if a template ever needs more than one sub-block
per parent.
